"""Socket.IO server (:8080) and HTTP push bridge (:8081) for realtime events."""
from __future__ import annotations

import asyncio
import hashlib
import hmac
import json
import os
import time
from pathlib import Path
from typing import Any

import socketio
from aiohttp import web

from apex_log import setup_logging

log = setup_logging()

API_KEY = os.environ.get("APEX_WS_KEY", "apex-ws-key-2025")
WS_SECRET = os.environ.get("WS_SECRET", "apex-ws-secret")
WS_HOST = "0.0.0.0"
WS_PORT = 8080
PUSH_HOST = "0.0.0.0"
PUSH_PORT = 8081
SESSION_TOKENS_FILE = Path(__file__).parent / "models" / "session_tokens.json"

sio = socketio.AsyncServer(
    async_mode="aiohttp",
    cors_allowed_origins="*",
    ping_interval=20,
    ping_timeout=20,
    max_http_buffer_size=2**20,
)
socket_app = web.Application()
sio.attach(socket_app)

user_sids: dict[int, set[str]] = {}
admin_sids: set[str] = set()
sid_meta: dict[str, dict[str, Any]] = {}
preview_last_at: dict[str, float] = {}
registry_lock = asyncio.Lock()

_EVENT_TYPE_MAP = {
    "Notification": "notification",
    "ModerationResult": "moderation_result",
    "QueueUpdate": "queue_update",
    "Banned": "banned",
}


def _load_user_admin_flags() -> dict[int, bool]:
    data: dict[str, Any] = {}
    env_raw = os.environ.get("APEX_SESSION_TOKENS", "").strip()
    if env_raw:
        try:
            loaded = json.loads(env_raw)
            if isinstance(loaded, dict):
                data = loaded
        except Exception:
            log.warning("Invalid APEX_SESSION_TOKENS JSON")
    elif SESSION_TOKENS_FILE.exists():
        try:
            loaded = json.loads(SESSION_TOKENS_FILE.read_text(encoding="utf-8"))
            if isinstance(loaded, dict):
                data = loaded
        except Exception as ex:
            log.warning("Could not read session token file: %s", ex)

    out: dict[int, bool] = {}
    for uid_raw, value in data.items():
        try:
            uid = int(uid_raw)
        except (TypeError, ValueError):
            continue
        if isinstance(value, bool):
            out[uid] = value
        elif isinstance(value, dict):
            out[uid] = bool(value.get("is_admin", False))
    return out


def _valid_join_token(user_id: int, token: str) -> bool:
    if not token:
        return False
    now_window = int(time.time() // 300)
    for window in (now_window, now_window - 1):
        payload = f"{user_id}:{window}".encode("utf-8")
        expected = hmac.new(WS_SECRET.encode("utf-8"), payload, hashlib.sha256).hexdigest()
        if hmac.compare_digest(expected, token):
            return True
    return False


async def _emit_message(sid: str, payload: dict) -> None:
    await sio.emit("apex", payload, to=sid)


async def _register(sid: str, user_id: int, is_admin: bool) -> None:
    async with registry_lock:
        user_sids.setdefault(user_id, set()).add(sid)
        if is_admin:
            admin_sids.add(sid)
        sid_meta[sid] = {"user_id": user_id, "is_admin": is_admin, "joined": True}


async def _unregister(sid: str) -> None:
    async with registry_lock:
        for uid in list(user_sids.keys()):
            conns = user_sids[uid]
            conns.discard(sid)
            if not conns:
                del user_sids[uid]
        admin_sids.discard(sid)
        sid_meta.pop(sid, None)
        preview_last_at.pop(sid, None)


async def _collect_target_sids(user_id: int | None, to_admins: bool) -> list[str]:
    async with registry_lock:
        targets: set[str] = set()
        if user_id is not None and user_id > 0:
            targets |= user_sids.get(user_id, set())
        if to_admins:
            targets |= admin_sids
        return list(targets)


async def fan_out_event(
    event: str,
    user_id: int | None,
    to_admins: bool,
    payload: dict,
) -> int:
    msg_type = _EVENT_TYPE_MAP.get(event, event.lower())
    message = {"type": msg_type, "payload": payload}
    targets = await _collect_target_sids(user_id, to_admins)
    sent = 0
    dead: list[str] = []

    for sid in targets:
        try:
            await _emit_message(sid, message)
            sent += 1
        except Exception as ex:
            log.warning("Fan-out failed sid=%s: %s", sid, ex)
            dead.append(sid)

    for sid in dead:
        await _unregister(sid)

    return sent


async def _handle_join(sid: str, data: dict, *, joined: bool) -> tuple[bool, bool]:
    try:
        user_id = int(data.get("user_id", 0))
    except (TypeError, ValueError):
        log.warning("Invalid join user_id sid=%s", sid)
        await _emit_message(sid, {"type": "error", "msg": "invalid_user"})
        return False, False

    if user_id <= 0:
        log.warning("Join rejected invalid user_id=%s sid=%s", user_id, sid)
        await _emit_message(sid, {"type": "error", "msg": "invalid_user"})
        return False, False

    token = str(data.get("token", "")).strip()
    if not _valid_join_token(user_id, token):
        log.warning("Join auth failed user_id=%s sid=%s", user_id, sid)
        await _emit_message(sid, {"type": "error", "msg": "auth_failed"})
        return False, False

    admin_flags = _load_user_admin_flags()
    is_admin = bool(admin_flags.get(user_id, False))

    if joined:
        await _unregister(sid)

    await _register(sid, user_id, is_admin)
    await _emit_message(sid, {"type": "joined", "user_id": user_id, "is_admin": is_admin})
    log.info("Client joined sid=%s user_id=%s is_admin=%s", sid, user_id, is_admin)
    return True, True


async def _handle_preview_moderation(sid: str, data: dict) -> None:
    text = str(data.get("text", "")).strip()
    if len(text) < 2:
        await _emit_message(sid, {
            "type": "live_moderation",
            "verdict": "ALLOWED",
            "harmful_prob": 0.0,
            "category": "safe",
            "method": "trivial",
            "reason": "",
            "offline": False,
        })
        return

    if len(text) > 8000:
        await _emit_message(sid, {"type": "error", "msg": "text_too_long"})
        return

    from api import analyze

    loop = asyncio.get_running_loop()
    try:
        result = await loop.run_in_executor(None, analyze, text)
    except Exception as ex:
        log.error("preview_moderation analyze failed: %s", ex)
        await _emit_message(sid, {"type": "error", "msg": "server_error"})
        return

    verdict = str(result.get("verdict", "ALLOWED"))
    await _emit_message(sid, {
        "type": "live_moderation",
        "verdict": verdict,
        "harmful_prob": float(result.get("harmful_prob", 0) or 0),
        "category": str(result.get("category", "safe")),
        "method": str(result.get("method", "sklearn")),
        "reason": str(result.get("reason", "")),
        "offline": verdict == "OFFLINE",
    })


async def _dispatch_client_message(sid: str, data: dict, *, joined: bool) -> tuple[bool, bool]:
    msg_type = str(data.get("type", "")).lower()

    try:
        if msg_type == "join":
            return await _handle_join(sid, data, joined=joined)
        if not joined:
            log.warning("Message before join sid=%s: %s", sid, msg_type)
            await _emit_message(sid, {"type": "error", "msg": "join_required"})
            return True, False
        if msg_type == "ping":
            await _emit_message(sid, {"type": "pong"})
            return True, False
        if msg_type == "preview_moderation":
            await _handle_preview_moderation(sid, data)
            return True, False

        log.warning("Unknown message type '%s' sid=%s", msg_type, sid)
        await _emit_message(sid, {"type": "error", "msg": "unknown_type"})
        return True, False
    except Exception as ex:
        log.error("Handler error (%s) sid=%s: %s", msg_type, sid, ex)
        try:
            await _emit_message(sid, {"type": "error", "msg": "server_error"})
        except Exception:
            pass
        return True, False


@sio.event
async def connect(sid, environ):
    sid_meta[sid] = {"joined": False, "user_id": 0, "is_admin": False}
    log.info("Socket.IO connect sid=%s", sid)


@sio.event
async def disconnect(sid):
    await _unregister(sid)
    log.info("Socket.IO disconnect sid=%s", sid)


@sio.on("apex")
async def on_apex(sid, data):
    if not isinstance(data, dict):
        await _emit_message(sid, {"type": "error", "msg": "invalid_message"})
        return

    joined = bool(sid_meta.get(sid, {}).get("joined"))

    if str(data.get("type", "")).lower() == "preview_moderation":
        now = time.monotonic()
        last = preview_last_at.get(sid, 0.0)
        if now - last < 1.2:
            await _emit_message(sid, {"type": "error", "msg": "rate_limited"})
            return
        preview_last_at[sid] = now

    keep_open, join_succeeded = await _dispatch_client_message(sid, data, joined=joined)
    if not keep_open:
        await sio.disconnect(sid)


async def _handle_push(request: web.Request) -> web.Response:
    if request.headers.get("X-Api-Key") != API_KEY:
        log.warning("Push auth failure from %s", request.remote)
        return web.json_response({"error": "Unauthorized"}, status=401)

    try:
        body = await request.json()
    except json.JSONDecodeError:
        log.warning("Push invalid JSON from %s", request.remote)
        return web.json_response({"error": "invalid_json"}, status=400)

    if not isinstance(body, dict):
        return web.json_response({"error": "invalid_body"}, status=400)

    event = str(body.get("event", "")).strip()
    if not event:
        return web.json_response({"error": "event required"}, status=400)

    user_id = body.get("user_id")
    uid: int | None = None
    if user_id is not None:
        try:
            uid = int(user_id)
        except (TypeError, ValueError):
            return web.json_response({"error": "invalid user_id"}, status=400)

    to_admins = bool(body.get("to_admins", False))
    payload = body.get("payload")
    if not isinstance(payload, dict):
        payload = {}

    count = await fan_out_event(event, uid, to_admins, payload)
    log.info(
        "Push event=%s user_id=%s to_admins=%s delivered=%s",
        event,
        uid,
        to_admins,
        count,
    )
    return web.json_response({"sent": True, "delivered": count})


async def _health(_request: web.Request) -> web.Response:
    return web.json_response({"ok": True, "service": "apex-push"})


async def _run_push_server() -> None:
    push_app = web.Application()
    push_app.router.add_post("/api/push", _handle_push)
    push_app.router.add_get("/health", _health)
    runner = web.AppRunner(push_app)
    await runner.setup()
    site = web.TCPSite(runner, PUSH_HOST, PUSH_PORT)
    await site.start()
    log.info("HTTP push server listening on http://%s:%s", PUSH_HOST, PUSH_PORT)


async def _run_socketio_server() -> None:
    runner = web.AppRunner(socket_app)
    await runner.setup()
    site = web.TCPSite(runner, WS_HOST, WS_PORT)
    await site.start()
    log.info("Socket.IO server listening on http://%s:%s", WS_HOST, WS_PORT)


async def main() -> None:
    log.info("Starting ApexSocial Socket.IO + push servers")
    await asyncio.gather(_run_socketio_server(), _run_push_server())
    await asyncio.Future()


if __name__ == "__main__":
    asyncio.run(main())
