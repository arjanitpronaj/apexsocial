"""Scheduled retraining jobs for ApexSocial ML."""
from __future__ import annotations

import json
import subprocess
import sys
import threading
from pathlib import Path
from urllib import request

from apscheduler.schedulers.blocking import BlockingScheduler

from apex_log import setup_logging
from online_learning import BASE, STATE_FILE, count_samples

log = setup_logging()
_job_lock = threading.Lock()
_job_running = False


def _post_reload_models() -> None:
    try:
        req = request.Request(
            "http://127.0.0.1:5000/reload_models",
            data=b"{}",
            headers={"Content-Type": "application/json"},
            method="POST",
        )
        with request.urlopen(req, timeout=10):
            pass
        log.info("Scheduler: /reload_models called successfully")
    except Exception as ex:
        log.error("Scheduler: /reload_models call failed: %s", ex)


def _state_lines_at_last_retrain() -> int:
    if not STATE_FILE.exists():
        return 0
    try:
        st = json.loads(STATE_FILE.read_text(encoding="utf-8"))
        if isinstance(st, dict):
            return int(st.get("lines_at_last_retrain", 0) or 0)
    except Exception:
        return 0
    return 0


def _run_subprocess(args: list[str], label: str) -> bool:
    try:
        log.info("Scheduler: %s started", label)
        proc = subprocess.run(args, capture_output=True, text=True, timeout=3600)
        if proc.returncode == 0:
            log.info("Scheduler: %s completed", label)
            return True
        log.error("Scheduler: %s failed: %s", label, (proc.stderr or proc.stdout)[-2000:])
    except Exception as ex:
        log.error("Scheduler: %s error: %s", label, ex)
    return False


def _with_lock(fn):
    def wrapper():
        global _job_running
        with _job_lock:
            if _job_running:
                log.warning("Scheduler: job already running, skipping")
                return
            _job_running = True
        try:
            fn()
        finally:
            _job_running = False

    return wrapper


@_with_lock
def nightly_incremental_job() -> None:
    total = count_samples()
    last = _state_lines_at_last_retrain()
    if total - last < 10:
        log.info("Scheduler nightly: skipped (growth=%d < 10)", total - last)
        return
    ok = _run_subprocess([sys.executable, str(BASE / "train_model.py"), "--incremental"], "nightly incremental retrain")
    if ok:
        _post_reload_models()


@_with_lock
def weekly_full_job() -> None:
    ok = _run_subprocess([sys.executable, str(BASE / "train_model.py")], "weekly full retrain")
    if ok:
        _post_reload_models()


def main() -> None:
    scheduler = BlockingScheduler(timezone="UTC")
    # 03:00 daily
    scheduler.add_job(nightly_incremental_job, "cron", hour=3, minute=0, id="nightly_incremental", replace_existing=True)
    # Sunday 04:00 weekly
    scheduler.add_job(weekly_full_job, "cron", day_of_week="sun", hour=4, minute=0, id="weekly_full", replace_existing=True)
    log.info("Scheduler started (nightly 03:00, weekly Sunday 04:00 UTC)")
    scheduler.start()


if __name__ == "__main__":
    main()
