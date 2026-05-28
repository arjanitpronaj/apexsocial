"""Incremental / online learning — log samples and auto-retrain after N new rows."""
from __future__ import annotations

import json
import subprocess
import sys
import threading
from datetime import datetime
from pathlib import Path

from apex_log import setup_logging
from text_utils import ml_features

log = setup_logging()

BASE = Path(__file__).parent
MODEL_DIR = BASE / "models"
INPUT_LOG = MODEL_DIR / "user_inputs.jsonl"
STATE_FILE = MODEL_DIR / "online_state.json"
DRIFT_LOG = MODEL_DIR / "logs" / "drift_log.jsonl"
_retrain_lock = threading.Lock()
_retrain_running = False


def _load_state() -> dict:
    defaults = {"lines_at_last_retrain": 0, "last_retrain": None}
    if not STATE_FILE.exists():
        return dict(defaults)
    try:
        data = json.loads(STATE_FILE.read_text(encoding="utf-8"))
        if not isinstance(data, dict):
            return dict(defaults)
        return {**defaults, **data}
    except Exception:
        return dict(defaults)


def _save_state(state: dict) -> None:
    STATE_FILE.write_text(json.dumps(state, indent=2), encoding="utf-8")


def count_samples() -> int:
    if not INPUT_LOG.exists():
        return 0
    try:
        return sum(1 for line in open(INPUT_LOG, encoding="utf-8") if line.strip())
    except Exception:
        return 0


def log_training_sample(
    raw_text: str,
    verdict: str,
    category: str,
    content_type: str = "post",
    user_id: int = 0,
) -> None:
    """
    Append every moderated post/comment for incremental learning.
    label 1 = harmful (FORBIDDEN), label 0 = safe (ALLOWED).
    """
    v = (verdict or "").upper()
    if v in ("REJECTED", "OFFLINE", "EMPTY"):
        return
    label = 1 if v == "FORBIDDEN" else 0
    features = ml_features(raw_text)
    if len(features) < 2:
        return

    record = {
        "ts": datetime.now().isoformat(),
        "text": features[:2000],
        "raw_len": len(raw_text),
        "label": label,
        "category": category or "safe",
        "verdict": v,
        "type": content_type,
        "user_id": user_id,
    }
    MODEL_DIR.mkdir(parents=True, exist_ok=True)
    with open(INPUT_LOG, "a", encoding="utf-8") as fh:
        fh.write(json.dumps(record, ensure_ascii=False) + "\n")

    try:
        total = count_samples()
        if total > 0 and total % 100 == 0:
            _log_drift_metrics_and_maybe_retrain(total)
    except Exception as ex:
        log.warning("Drift monitor failed: %s", ex)

    # Trim file
    try:
        lines = INPUT_LOG.read_text(encoding="utf-8").splitlines()
        if len(lines) > 12000:
            INPUT_LOG.write_text("\n".join(lines[-10000:]) + "\n", encoding="utf-8")
    except Exception as ex:
        log.warning("Trim user_inputs failed: %s", ex)


def _log_drift_metrics_and_maybe_retrain(total_samples: int) -> None:
    recent: list[dict] = []
    try:
        lines = INPUT_LOG.read_text(encoding="utf-8").splitlines()
        for line in lines[-300:]:
            if not line.strip():
                continue
            try:
                obj = json.loads(line)
                if isinstance(obj, dict):
                    recent.append(obj)
            except Exception:
                continue
    except Exception as ex:
        log.warning("Unable to read recent inputs for drift: %s", ex)
        return

    if not recent:
        return

    forbidden_count = sum(1 for r in recent if int(r.get("label", 0) or 0) == 1)
    correction_count = sum(1 for r in recent if str(r.get("source", "")) == "admin_correction")
    forbidden_rate = forbidden_count / len(recent)
    correction_rate = correction_count / len(recent)

    DRIFT_LOG.parent.mkdir(parents=True, exist_ok=True)
    drift_entry = {
        "ts": datetime.now().isoformat(),
        "total_samples": total_samples,
        "window": len(recent),
        "forbidden_rate": round(forbidden_rate, 4),
        "correction_rate": round(correction_rate, 4),
    }
    with open(DRIFT_LOG, "a", encoding="utf-8") as fh:
        fh.write(json.dumps(drift_entry, ensure_ascii=False) + "\n")

    if forbidden_rate > 0.40:
        log.warning("Drift warning: forbidden_rate=%.3f (too aggressive?)", forbidden_rate)
    elif forbidden_rate < 0.02:
        log.warning("Drift warning: forbidden_rate=%.3f (too permissive?)", forbidden_rate)

    if correction_rate > 0.20:
        log.warning("Drift warning: correction_rate=%.3f, triggering background full retrain", correction_rate)
        try:
            subprocess.Popen(
                [sys.executable, str(BASE / "train_model.py")],
                stdout=subprocess.DEVNULL,
                stderr=subprocess.DEVNULL,
            )
        except Exception as ex:
            log.error("Failed to start background full retrain: %s", ex)


def maybe_auto_retrain(
    every_n: int,
    min_total: int,
    reload_callback,
) -> dict:
    """
    After each new sample, retrain in background when (total - last_retrain_count) >= every_n.
    Does not restart the Flask/Waitress process — hot-reloads pickles only.
    """
    global _retrain_running
    total = count_samples()
    state = _load_state()
    last = int(state.get("lines_at_last_retrain", 0))
    new_since = total - last

    if total < min_total or new_since < every_n:
        return {
            "triggered": False,
            "total": total,
            "new_since_retrain": new_since,
            "every_n": every_n,
        }

    with _retrain_lock:
        if _retrain_running:
            return {"triggered": False, "reason": "retrain_already_running", "total": total}
        _retrain_running = True

    def _bg():
        global _retrain_running
        try:
            log.info("Auto-retrain started (%d new samples, %d total)", new_since, total)
            r = subprocess.run(
                [sys.executable, str(BASE / "train_model.py"), "--incremental"],
                capture_output=True,
                text=True,
                timeout=600,
            )
            if r.returncode == 0:
                reload_callback()
                st = _load_state()
                st["lines_at_last_retrain"] = count_samples()
                st["last_retrain"] = datetime.now().isoformat()
                _save_state(st)
                log.info("Auto-retrain completed — models reloaded.")
            else:
                log.error("Auto-retrain failed: %s", (r.stderr or r.stdout)[-2000:])
        except Exception as ex:
            log.error("Auto-retrain error: %s", ex)
        finally:
            _retrain_running = False

    threading.Thread(target=_bg, daemon=True).start()
    return {"triggered": True, "total": total, "new_since_retrain": new_since}
