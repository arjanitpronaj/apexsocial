"""Rate limits, request size checks, and flood detection for the ML API."""
from __future__ import annotations

import json
import re
import threading
import time
from collections import defaultdict, deque
from datetime import datetime
from pathlib import Path
from typing import Any

from flask import Request, jsonify

_BASE = Path(__file__).parent
_REJECT_LOG = _BASE / "models" / "rejected_inputs.jsonl"
_log_lock = threading.Lock()

# Defaults (overridden from config.json security section)
DEFAULTS = {
    "rate_limit_per_minute": 120,
    "rate_limit_analyze": 60,
    "max_body_bytes": 512_000,
    "max_text_length": 8000,
    "spam_repeat_threshold": 8,
    "flood_window_seconds": 10,
    "flood_max_requests": 25,
}

_rate_buckets: dict[str, deque[float]] = defaultdict(deque)
_flood_buckets: dict[str, deque[float]] = defaultdict(deque)
_bucket_lock = threading.Lock()


def load_security_config(cfg: dict) -> dict:
    sec = dict(DEFAULTS)
    if isinstance(cfg.get("security"), dict):
        sec.update({k: v for k, v in cfg["security"].items() if k in DEFAULTS})
    return sec


def _client_key(req: Request) -> str:
    fwd = req.headers.get("X-Forwarded-For", "")
    if fwd:
        return fwd.split(",")[0].strip()
    return req.remote_addr or "unknown"


def _prune(bucket: deque[float], window: float, now: float) -> None:
    while bucket and now - bucket[0] > window:
        bucket.popleft()


def check_rate_limit(req: Request, sec: dict, endpoint: str = "default") -> tuple[bool, str]:
    key = f"{_client_key(req)}:{endpoint}"
    now = time.time()
    limit = int(sec.get("rate_limit_analyze" if endpoint == "analyze" else "rate_limit_per_minute", 60))
    window = 60.0

    with _bucket_lock:
        bucket = _rate_buckets[key]
        _prune(bucket, window, now)
        if len(bucket) >= limit:
            return False, f"Rate limit exceeded ({limit}/min)."
        bucket.append(now)

    # Short flood window
    flood_win = float(sec.get("flood_window_seconds", 10))
    flood_max = int(sec.get("flood_max_requests", 25))
    with _bucket_lock:
        fb = _flood_buckets[key]
        _prune(fb, flood_win, now)
        if len(fb) >= flood_max:
            return False, "Too many requests in a short period."
        fb.append(now)

    return True, ""


def sanitize_payload(data: Any) -> tuple[dict | None, list[str]]:
    """Validate JSON body shape; strip dangerous patterns from text fields."""
    issues: list[str] = []
    if not isinstance(data, dict):
        return None, ["invalid_json_shape"]

    out: dict = {}
    for k, v in data.items():
        if not isinstance(k, str) or len(k) > 64:
            issues.append("invalid_key")
            continue
        if re.search(r"[\x00-\x08\x0b\x0c\x0e-\x1f]", k):
            issues.append("dangerous_key")
            continue
        out[k] = v

    text = out.get("text")
    if text is not None:
        if not isinstance(text, str):
            issues.append("text_not_string")
            out["text"] = ""
        else:
            t = text
            if "<script" in t.lower() or "javascript:" in t.lower():
                issues.append("xss_pattern")
            if re.search(r"(\{\{|\}\}|\$\{|<\?php)", t):
                issues.append("template_injection")
            out["text"] = t

    return out, issues


def spam_score(text: str, sec: dict) -> float:
    if not text:
        return 0.0
    score = 0.0
    thr = int(sec.get("spam_repeat_threshold", 8))
    if re.search(rf"(.)\1{{{thr},}}", text):
        score += 25.0
    if text.count("\n") > 40:
        score += 15.0
    words = text.split()
    if len(words) > 5:
        unique_ratio = len(set(words)) / len(words)
        if unique_ratio < 0.35:
            score += 20.0
    if len(text) > 4000 and len(set(text)) < 30:
        score += 30.0
    return min(50.0, score)


def log_rejection(req: Request, reason: str, detail: dict | None = None) -> None:
    _REJECT_LOG.parent.mkdir(parents=True, exist_ok=True)
    entry = {
        "ts": datetime.now().isoformat(),
        "ip": _client_key(req),
        "path": req.path,
        "reason": reason,
        "detail": detail or {},
    }
    with _log_lock:
        with open(_REJECT_LOG, "a", encoding="utf-8") as fh:
            fh.write(json.dumps(entry, ensure_ascii=False) + "\n")


def security_reject(reason: str, code: int = 400, **extra):
    body = {"verdict": "REJECTED", "error": reason, "security": True, **extra}
    return jsonify(body), code


def validate_request(req: Request, sec: dict, *, require_text: bool = False) -> tuple[dict | None, Any]:
    """
    Returns (payload_dict, None) on success or (None, flask_response) on failure.
    """
    if req.content_length and req.content_length > int(sec.get("max_body_bytes", 512_000)):
        log_rejection(req, "body_too_large", {"size": req.content_length})
        return None, security_reject("Request body too large.", 413)

    ok, msg = check_rate_limit(req, sec, "analyze" if req.path == "/analyze" else "default")
    if not ok:
        log_rejection(req, "rate_limit", {"message": msg})
        return None, security_reject(msg, 429)

    raw = req.get_json(silent=True)
    if raw is None and req.method in ("POST", "PUT", "PATCH"):
        if req.data:
            log_rejection(req, "invalid_json")
            return None, security_reject("Invalid JSON payload.", 400)

    payload, issues = sanitize_payload(raw or {})
    if issues and "invalid_json_shape" in issues:
        return None, security_reject("Malformed request.", 400)

    if issues and ("xss_pattern" in issues or "template_injection" in issues):
        log_rejection(req, "xss_or_template_injection", {"issues": issues})
        return None, security_reject("Malicious content pattern detected.", 400, issues=issues)

    if require_text:
        text = str((payload or {}).get("text", "")).strip()
        if not text:
            return None, security_reject("No text provided.", 400)
        if len(text) > int(sec.get("max_text_length", 8000)):
            return None, security_reject("Text exceeds maximum length.", 400)
        sp = spam_score(text, sec)
        if sp >= 45.0:
            log_rejection(req, "spam_detected", {"spam_score": sp})
            return None, security_reject("Spam or flood pattern detected.", 400, spam_score=sp)

    if issues:
        log_rejection(req, "sanitization_flags", {"issues": issues})

    return payload or {}, None
