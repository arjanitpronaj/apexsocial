"""WebSocket server (:8080) and HTTP push bridge (:8081) for realtime events."""
from __future__ import annotations

import asyncio
import hashlib
import hmac
import json
import os
import time
from pathlib import Path
from typing import Any

import websockets
from aiohttp import web
from websockets.server import WebSocketServerProtocol

from apex_log import setup_logging

log = setup_logging()

API_KEY = os.environ.get("APEX_WS_KEY", "apex-ws-key-2025")
WS_SECRET = os.environ.get("WS_SECRET", "apex-ws-secret")
WS_HOST = "0.0.0.0"
WS_PORT = 8080
PUSH_HOST = "0.0.0.0"
PUSH_PORT = 8081
SESSION_TOKENS_FILE = Path(__file__).parent / "models" / "session_tokens.json"

# user_id → open sockets for that user
user_connections: dict[int, set[WebSocketServerProtocol]] = {}
admin_connections: set[WebSocketServerProtocol] = set()
registry_lock = asyncio.Lock()

# PHP / legacy event name → outbound WS message type
_EVENT_TYPE_MAP = {
    "Notification": "notification",
    "ModerationResult": "moderation_result",
    "QueueUpdate": "queue_update",
    "Banned": "banned",
}


def _load_user_admin_flags() -> dict[int, bool]:
    """Admin flags from APEX_SESSION_TOKENS env or models/session_tokens.json."""
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
    # Accept current and previous 5-minute window for clock skew.
    now_window = int(time.time() // 300)
    for window in (now_window, now_window - 1):
        payload = f"{user_id}:{window}".encode("utf-8")
        expected = hmac.new(WS_SECRET.encode("utf-8"), payload, hashlib.sha256).hexdigest()
        if hmac.compare_digest(expected, token):
            return True
    return False


def _peer_ip(ws: WebSocketServerProtocol) -> str:
    addr = ws.remote_address
    if addr is None:
        return "unknown"
    return str(addr[0]) if isinstance(addr, tuple) else str(addr)


async def _send_json(ws: WebSocketServerProtocol, payload: dict) -> None:
    await ws.send(json.dumps(payload, ensure_ascii=False))


async def _register(ws: WebSocketServerProtocol, user_id: int, is_admin: bool) -> None:
    async with registry_lock:
        user_connections.setdefault(user_id, set()).add(ws)
        if is_admin:
            admin_connections.add(ws)


async def _unregister(ws: WebSocketServerProtocol) -> None:
    async with registry_lock:
        for uid in list(user_connections.keys()):
            conns = user_connections[uid]
            conns.discard(ws)
            if not conns:
                del user_connections[uid]
        admin_connections.discard(ws)


async def _collect_targets(user_id: int | None, to_admins: bool) -> list[WebSocketServerProtocol]:
    async with registry_lock:
        targets: set[WebSocketServerProtocol] = set()
        if user_id is not None and user_id > 0:
            targets |= user_connections.get(user_id, set())
        if to_admins:
            targets |= admin_connections
        return list(targets)


async def fan_out_event(
    event: str,
    user_id: int | None,
    to_admins: bool,
    payload: dict,
) -> int:
    """Push JSON event to matching sockets. Returns delivery count."""
    msg_type = _EVENT_TYPE_MAP.get(event, event.lower())
    if msg_type in (
        "notification",
        "moderation_result",
        "queue_update",
        "banned",
    ):
        message = {"type": msg_type, "payload": payload}
    else:
        message = {"type": msg_type, "payload": payload}

    targets = await _collect_targets(user_id, to_admins)
    sent = 0
    dead: list[WebSocketServerProtocol] = []

    for ws in targets:
        try:
            await _send_json(ws, message)
            sent += 1
        except Exception as ex:
            log.warning("Fan-out failed to %s: %s", _peer_ip(ws), ex)
            dead.append(ws)

    for ws in dead:
        await _unregister(ws)

    return sent


async def _handle_join(
    ws: WebSocketServerProtocol,
    data: dict,
    *,
    joined: bool,
) -> tuple[bool, bool]:
    """Returns (keep_open, join_succeeded)."""
    try:
        user_id = int(data.get("user_id", 0))
    except (TypeError, ValueError):
        log.warning("Invalid join user_id from %s", _peer_ip(ws))
        await _send_json(ws, {"type": "error", "msg": "invalid_user"})
        await ws.close()
        return False, False

    if user_id <= 0:
        log.warning("Join rejected invalid user_id=%s from %s", user_id, _peer_ip(ws))
        await _send_json(ws, {"type": "error", "msg": "invalid_user"})
        await ws.close()
        return False, False

    token = str(data.get("token", "")).strip()
    if not _valid_join_token(user_id, token):
        log.warning("Join auth failed user_id=%s from %s", user_id, _peer_ip(ws))
        await _send_json(ws, {"type": "error", "msg": "auth_failed"})
        await ws.close()
        return False, False

    admin_flags = _load_user_admin_flags()
    is_admin = bool(admin_flags.get(user_id, False))

    if joined:
        await _unregister(ws)

    await _register(ws, user_id, is_admin)
    await _send_json(ws, {"type": "joined", "user_id": user_id, "is_admin": is_admin})
    log.info(
        "Client joined ip=%s user_id=%s is_admin=%s",
        _peer_ip(ws),
        user_id,
        is_admin,
    )
    return True, True


async def _handle_preview_moderation(ws: WebSocketServerProtocol, data: dict) -> None:
    text = str(data.get("text", "")).strip()
    if len(text) < 2:
        await _send_json(ws, {
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
        await _send_json(ws, {"type": "error", "msg": "text_too_long"})
        return

    from api import analyze

    loop = asyncio.get_running_loop()
    try:
        result = await loop.run_in_executor(None, analyze, text)
    except Exception as ex:
        log.error("preview_moderation analyze failed: %s", ex)
        await _send_json(ws, {"type": "error", "msg": "server_error"})
        return

    verdict = str(result.get("verdict", "ALLOWED"))
    await _send_json(ws, {
        "type": "live_moderation",
        "verdict": verdict,
        "harmful_prob": float(result.get("harmful_prob", 0) or 0),
        "category": str(result.get("category", "safe")),
        "method": str(result.get("method", "sklearn")),
        "reason": str(result.get("reason", "")),
        "offline": verdict == "OFFLINE",
    })


async def _dispatch_message(ws: WebSocketServerProtocol, raw: str, *, joined: bool) -> tuple[bool, bool]:
    """Returns (keep_open, join_succeeded)."""
    try:
        data = json.loads(raw)
    except json.JSONDecodeError:
        log.warning("Invalid JSON from %s", _peer_ip(ws))
        await _send_json(ws, {"type": "error", "msg": "invalid_json"})
        return True, False

    if not isinstance(data, dict):
        log.warning("Non-object JSON from %s", _peer_ip(ws))
        await _send_json(ws, {"type": "error", "msg": "invalid_message"})
        return True, False

    msg_type = str(data.get("type", "")).lower()

    try:
        if msg_type == "join":
            return await _handle_join(ws, data, joined=joined)
        if not joined:
            log.warning("Message before join from %s: %s", _peer_ip(ws), msg_type)
            await _send_json(ws, {"type": "error", "msg": "join_required"})
            return True, False
        if msg_type == "ping":
            await _send_json(ws, {"type": "pong"})
            return True, False
        if msg_type == "preview_moderation":
            await _handle_preview_moderation(ws, data)
            return True, False

        log.warning("Unknown message type '%s' from %s", msg_type, _peer_ip(ws))
        await _send_json(ws, {"type": "error", "msg": "unknown_type"})
        return True, False
    except Exception as ex:
        log.error("Handler error (%s) from %s: %s", msg_type, _peer_ip(ws), ex)
        try:
            await _send_json(ws, {"type": "error", "msg": "server_error"})
        except Exception:
            pass
        return True, False


async def ws_client_handler(ws: WebSocketServerProtocol) -> None:
    ip = _peer_ip(ws)
    log.info("WebSocket connect ip=%s", ip)
    joined = False
    last_preview_at = 0.0
    try:
        async for message in ws:
            if isinstance(message, bytes):
                message = message.decode("utf-8", errors="replace")
            if not isinstance(message, str):
                continue
            # Per-connection preview_moderation rate-limit.
            try:
                obj = json.loads(message)
                if isinstance(obj, dict) and str(obj.get("type", "")).lower() == "preview_moderation":
                    now = time.monotonic()
                    if now - last_preview_at < 1.2:
                        await _send_json(ws, {"type": "error", "msg": "rate_limited"})
                        continue
                    last_preview_at = now
            except Exception:
                pass

            keep_open, join_succeeded = await _dispatch_message(ws, message, joined=joined)
            if not keep_open:
                break
            if join_succeeded and not joined:
                joined = True
    except websockets.ConnectionClosed:
        pass
    except Exception as ex:
        log.error("WebSocket session error ip=%s: %s", ip, ex)
    finally:
        await _unregister(ws)
        log.info("WebSocket disconnect ip=%s", ip)


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


async def _run_push_server() -> None:
    app = web.Application()
    app.router.add_post("/api/push", _handle_push)
    runner = web.AppRunner(app)
    await runner.setup()
    site = web.TCPSite(runner, PUSH_HOST, PUSH_PORT)
    await site.start()
    log.info("HTTP push server listening on http://%s:%s", PUSH_HOST, PUSH_PORT)


async def _run_ws_server() -> None:
    async with websockets.serve(
        ws_client_handler,
        WS_HOST,
        WS_PORT,
        ping_interval=20,
        ping_timeout=20,
        max_size=2**20,
    ):
        log.info("WebSocket server listening on ws://%s:%s", WS_HOST, WS_PORT)
        await asyncio.Future()


async def main() -> None:
    log.info("Starting ApexSocial WS + push servers")
    await asyncio.gather(_run_ws_server(), _run_push_server())


if __name__ == "__main__":
    asyncio.run(main())
