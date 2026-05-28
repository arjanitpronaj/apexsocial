import json
import math
import os
import pickle
import subprocess
import sys
import threading
from datetime import datetime
from functools import wraps
from pathlib import Path

from flask import Flask, jsonify, request
from flask_cors import CORS

from apex_log import setup_logging
from context_scoring import apply_context_to_probs
from online_learning import INPUT_LOG as ONLINE_INPUT_LOG
from online_learning import log_training_sample, maybe_auto_retrain
from security import load_security_config, log_rejection, security_reject, validate_request
from semantic_scorer import semantic_harm_score
from text_integrity import integrity_to_dict, prepare_text
from text_utils import find_urls, keyword_match, ml_features, url_scam_boost

log = setup_logging()

BASE = Path(__file__).parent
MODEL_DIR = BASE / "models"
LOG_FILE = MODEL_DIR / "analysis_log.jsonl"
ANALYSIS_LOG_MAX = 5000
ANALYSIS_LOG_TRIM_EVERY = 500
_analysis_log_count = 0
INPUT_LOG = MODEL_DIR / "user_inputs.jsonl"
MODEL_DIR.mkdir(exist_ok=True)

with open(MODEL_DIR / "config.json", encoding="utf-8") as f:
    CFG = json.load(f)

SEC = load_security_config(CFG)
THRESH = float(CFG.get("threshold_low", CFG.get("threshold", 0.52)))
THRESH_HIGH = float(CFG.get("threshold_high", 0.78))
KW_HATE = CFG.get("keywords", [])
KW_SCAM = CFG.get("scam_keywords", CFG.get("phishing_keywords", []))
KW_BOOST_STRONG = float(CFG.get("keyword_boost_strong", 25))
KW_BOOST_WEAK = float(CFG.get("keyword_boost_weak", 16))
BYPASS_BOOST = float(CFG.get("bypass_boost", 18))
RETRAIN_MIN = int(CFG.get("retrain_min_samples", 10))
RETRAIN_EVERY_N = int(CFG.get("retrain_every_n", 8))
SEMANTIC_ON = bool(CFG.get("semantic_enabled", True))
THRESH_PCT = round(THRESH * 100, 1)
THRESH_HIGH_PCT = round(THRESH_HIGH * 100, 1)

_model_lock = threading.RLock()
hate_pipeline = None
scam_pipeline = None


def _load_models():
    global hate_pipeline, scam_pipeline
    with _model_lock:
        try:
            with open(MODEL_DIR / "pipeline.pkl", "rb") as f:
                hate_pipeline = pickle.load(f)
            log.info("Hate model loaded.")
        except Exception as ex:
            log.warning("pipeline.pkl missing: %s", ex)
            hate_pipeline = None
        try:
            with open(MODEL_DIR / "scam_pipeline.pkl", "rb") as f:
                scam_pipeline = pickle.load(f)
            log.info("Scam model loaded.")
        except Exception as ex:
            scam_pipeline = hate_pipeline
            if scam_pipeline:
                log.info("scam_pipeline.pkl missing — using hate model as fallback.")


_load_models()


def _cors_origins() -> list[str]:
    env = os.environ.get("APEX_CORS_ORIGINS", "")
    if env:
        return [o.strip() for o in env.split(",") if o.strip()]
    cfg_list = CFG.get("cors_origins")
    if isinstance(cfg_list, list) and cfg_list:
        return cfg_list
    return [
        "http://localhost",
        "http://127.0.0.1",
        "http://localhost:80",
        "http://127.0.0.1:80",
    ]


def _keyword_weight(matched_kw: str) -> float:
    if not matched_kw:
        return 0.0
    return KW_BOOST_STRONG if len(matched_kw.split()) > 1 else KW_BOOST_WEAK


def _keyword_signal(text: str, kw_list: list, category: str) -> dict:
    hit, kw = keyword_match(text, kw_list)
    weight = _keyword_weight(kw) if hit else 0.0
    return {
        "keyword_detected": hit,
        "keyword_type": category if hit else None,
        "keyword_weight": weight,
        "keyword": kw if hit else None,
    }


def _harmful_probability(text: str, pipeline) -> float:
    if pipeline is None:
        return 0.0
    try:
        row = pipeline.predict_proba([text])[0]
        clf = pipeline.named_steps["clf"]
        classes = list(getattr(clf, "classes_", []))
        if 1 in classes:
            idx = classes.index(1)
        elif True in classes:
            idx = classes.index(True)
        else:
            idx = len(row) - 1 if len(row) > 1 else 0
        return float(row[idx])
    except Exception as ex:
        log.warning("sklearn error: %s", ex)
        return 0.0


def _sigmoid_sharpen(p: float) -> float:
    p = max(0.0, min(1.0, float(p)))
    k = 16.0
    s = 1.0 / (1.0 + math.exp(-k * (p - THRESH)))
    if p < 0.3:
        s *= (p / 0.3) ** 1.5
    elif p > 0.7:
        t = (p - 0.7) / 0.3
        s = s + (1.0 - s) * (t ** 0.8)
    return max(0.0, min(1.0, s))


def _confidence(prob_0_1: float) -> str:
    p = max(0.0, min(1.0, float(prob_0_1)))
    if 0.35 <= p <= 0.65:
        return "low"
    if 0.25 <= p < 0.35 or 0.65 < p <= 0.75:
        return "medium"
    return "high"


def _display_harmful_prob(combined_score: float, method: str, raw_ml: float) -> float:
    if method == "sklearn":
        return round(_sigmoid_sharpen(raw_ml) * 100, 1)
    return round(combined_score, 1)


def analyze(text: str, integrity: dict | None = None) -> dict:
    raw = str(text).strip()
    if not raw or len(raw) < 2:
        return _allowed("empty", 0.0)

    prep = prepare_text(raw, strict=False)
    if not prep.ok:
        return _rejected_security(prep.errors)

    raw_for_kw = prep.normalized or raw
    hate_kw = _keyword_signal(raw_for_kw, KW_HATE, "hate_speech")
    scam_kw = _keyword_signal(raw_for_kw, KW_SCAM, "phishing_scam")

    ml_input = ml_features(raw)
    if len(ml_input) < 3:
        ml_input = prep.normalized or raw

    with _model_lock:
        hate_prob = _harmful_probability(ml_input, hate_pipeline)
        scam_prob = _harmful_probability(ml_input, scam_pipeline)

    hate_prob, scam_prob, context_notes = apply_context_to_probs(
        raw_for_kw,
        hate_prob,
        scam_prob,
        THRESH,
        THRESH_HIGH,
        language_hint=prep.language_hint,
        hate_keyword_matched=bool(hate_kw["keyword_detected"]),
    )

    sem_notes: list[str] = []
    if SEMANTIC_ON:
        sem_score, sem_notes, sem_method = semantic_harm_score(raw_for_kw)
        if sem_score is not None and sem_score > 0.65:
            hate_prob = max(hate_prob, sem_score * 0.85)
            scam_prob = max(scam_prob, sem_score * 0.4)
            context_notes.extend(sem_notes)
            if sem_method.startswith("transformer"):
                context_notes.append("semantic_transformer")

    hate_ml_pct = round(hate_prob * 100, 1)
    scam_ml_pct = round(scam_prob * 100, 1)

    url_boost, url_notes = url_scam_boost(raw)
    detected_urls = find_urls(raw)

    bypass_boost = prep.bypass_score
    if prep.flags:
        bypass_boost = max(bypass_boost, BYPASS_BOOST)

    hate_combined = min(100.0, hate_ml_pct + hate_kw["keyword_weight"] + bypass_boost * 0.35)
    scam_combined = min(
        100.0,
        scam_ml_pct + scam_kw["keyword_weight"] + url_boost + bypass_boost * 0.25,
    )
    if url_boost >= 28 and (
        "suspicious_tld" in url_notes
        or ("scam_context" in url_notes and "url_present" in url_notes)
    ):
        scam_combined = max(scam_combined, THRESH_HIGH_PCT)

    if hate_combined >= scam_combined:
        combined_score = hate_combined
        best_cat = "hate_speech"
        dominant_kw = hate_kw
        dominant_ml_pct = hate_ml_pct
        dominant_ml = hate_prob
    else:
        combined_score = scam_combined
        best_cat = "phishing_scam"
        dominant_kw = scam_kw
        dominant_ml_pct = scam_ml_pct
        dominant_ml = scam_prob

    keyword_detected = hate_kw["keyword_detected"] or scam_kw["keyword_detected"]
    method = "hybrid" if keyword_detected or prep.flags else "sklearn"
    harmful_prob = _display_harmful_prob(combined_score, method, dominant_ml)

    integ_meta = integrity or integrity_to_dict(prep)
    meta = {
        "keyword_detected": keyword_detected,
        "keyword_type": dominant_kw["keyword_type"] if dominant_kw["keyword_detected"] else None,
        "keyword_weight": dominant_kw["keyword_weight"] if dominant_kw["keyword_detected"] else 0.0,
        "ml_hate_pct": hate_ml_pct,
        "ml_scam_pct": scam_ml_pct,
        "combined_score": round(combined_score, 1),
        "context_adjusted": bool(context_notes),
        "context_notes": context_notes,
        "urls_detected": detected_urls,
        "url_boost": round(url_boost, 1),
        "url_notes": url_notes,
        "bypass_boost": round(bypass_boost, 1),
        "integrity": integ_meta,
        "language_hint": prep.language_hint,
    }

    if combined_score < THRESH_PCT:
        reason = (
            f"No combined risk above {THRESH_PCT:.0f}% threshold "
            f"(score {combined_score:.1f}%)."
        )
        return _allowed(method, harmful_prob, reason, category="safe", **meta)

    reason_map = {
        "hate_speech": "Content contains hate speech or harassment.",
        "phishing_scam": "Content contains scam or phishing material.",
    }
    reason = reason_map.get(best_cat, "Harmful content detected.")
    if dominant_kw["keyword_detected"]:
        reason = (
            f"{reason} Combined risk {combined_score:.1f}% "
            f"(ML {dominant_ml_pct:.1f}% + keyword +{dominant_kw['keyword_weight']:.0f}%)."
        )
        return _forbidden(best_cat, harmful_prob, method, reason, **meta)

    if prep.flags and combined_score >= THRESH_HIGH_PCT - 5:
        reason = f"{reason} Manipulation/bypass patterns detected."

    return _forbidden(best_cat, harmful_prob, method, reason, **meta)


def _rejected_security(errors: list) -> dict:
    return {
        "verdict": "REJECTED",
        "category": "invalid",
        "harmful_prob": 0.0,
        "method": "integrity",
        "reason": "Text failed integrity validation: " + ", ".join(errors),
        "confidence": "high",
        "integrity": {"ok": False, "errors": errors},
    }


def _allowed(method: str = "sklearn", prob: float = 0.0, reason: str = "",
             category: str = "safe", **extra) -> dict:
    return {
        "verdict": "ALLOWED",
        "category": category,
        "harmful_prob": prob,
        "method": method,
        "reason": reason,
        "confidence": _confidence(prob / 100.0),
        **extra,
    }


def _forbidden(category: str, prob: float, method: str, reason: str, **extra) -> dict:
    return {
        "verdict": "FORBIDDEN",
        "category": category,
        "harmful_prob": prob,
        "method": method,
        "reason": reason,
        "confidence": _confidence(prob / 100.0),
        **extra,
    }


_log_lock = threading.Lock()


def _trim_analysis_log():
    if not LOG_FILE.exists():
        return
    try:
        lines = LOG_FILE.read_text(encoding="utf-8").splitlines()
        if len(lines) > ANALYSIS_LOG_MAX:
            LOG_FILE.write_text(
                "\n".join(lines[-ANALYSIS_LOG_MAX:]) + "\n",
                encoding="utf-8",
            )
    except Exception as ex:
        log.warning("Trim analysis_log failed: %s", ex)


def _append_analysis_log(entry: dict):
    global _analysis_log_count
    with _log_lock:
        with open(LOG_FILE, "a", encoding="utf-8") as fh:
            fh.write(json.dumps(entry, ensure_ascii=False) + "\n")
        _analysis_log_count += 1
        if _analysis_log_count >= ANALYSIS_LOG_TRIM_EVERY:
            _analysis_log_count = 0
            _trim_analysis_log()


def _append_training_input(text: str, verdict: str, category: str, integrity: dict | None = None):
    if verdict != "FORBIDDEN":
        return
    cleaned_sample = ml_features(text)[:500]
    with _log_lock:
        record = {
            "ts": datetime.now().isoformat(),
            "text": cleaned_sample,
            "label": 1,
            "category": category,
        }
        if integrity and integrity.get("flags"):
            record["flags"] = integrity["flags"]
        with open(INPUT_LOG, "a", encoding="utf-8") as fh:
            fh.write(json.dumps(record, ensure_ascii=False) + "\n")


def _record_sample_and_maybe_retrain(
    text: str,
    verdict: str,
    category: str,
    content_type: str,
    user_id: int,
):
    log_training_sample(text, verdict, category, content_type, user_id)
    _append_training_input(text, verdict, category)
    maybe_auto_retrain(RETRAIN_EVERY_N, RETRAIN_MIN, _load_models)


def _append_feedback_sample(text: str, label: int, category: str) -> None:
    cleaned = ml_features(text)[:2000]
    if len(cleaned) < 2:
        return
    rec = {
        "ts": datetime.now().isoformat(),
        "text": cleaned,
        "raw_len": len(text),
        "label": 1 if int(label) == 1 else 0,
        "category": category or "safe",
        "verdict": "FORBIDDEN" if int(label) == 1 else "ALLOWED",
        "type": "post",
        "user_id": 0,
        "source": "admin_correction",
        "weight": 2,
    }
    with _log_lock:
        with open(ONLINE_INPUT_LOG, "a", encoding="utf-8") as fh:
            fh.write(json.dumps(rec, ensure_ascii=False) + "\n")


app = Flask(__name__)
CORS(
    app,
    origins=_cors_origins(),
    supports_credentials=True,
    allow_headers=["Content-Type", "X-Api-Key", "X-User-Id"],
    methods=["GET", "POST", "OPTIONS"],
)


def _secured(require_text: bool = False):
    def decorator(fn):
        @wraps(fn)
        def wrapper(*args, **kwargs):
            if request.method == "OPTIONS":
                return "", 204
            payload, err = validate_request(request, SEC, require_text=require_text)
            if err is not None:
                return err
            request.apex_payload = payload
            return fn(*args, **kwargs)
        return wrapper
    return decorator


@app.route("/analyze", methods=["POST", "OPTIONS"])
@_secured(require_text=True)
def route_analyze():
    d = getattr(request, "apex_payload", None) or request.get_json(silent=True) or {}
    text = str(d.get("text", "")).strip()
    prep = prepare_text(text)
    if not prep.ok:
        log_rejection(request, "integrity_failed", {"errors": prep.errors})
        return jsonify(_rejected_security(prep.errors)), 400

    ctype = str(d.get("type", "post"))
    uid = int(d.get("user_id", 0) or 0)
    result = analyze(text, integrity=integrity_to_dict(prep))
    _append_analysis_log({
        "ts": datetime.now().isoformat(),
        "uid": uid,
        "type": ctype,
        "text": text[:200],
        **result,
    })
    _record_sample_and_maybe_retrain(text, result["verdict"], result["category"], ctype, uid)
    return jsonify(result)


@app.route("/analyze_batch", methods=["POST", "OPTIONS"])
@_secured(require_text=False)
def route_batch():
    d = getattr(request, "apex_payload", None) or request.get_json(silent=True) or {}
    items = d.get("items", [])[:200]
    out = []
    for item in items:
        text = str(item.get("text", "")).strip()
        if not text:
            out.append({"id": item.get("id"), **_allowed("empty")})
            continue
        prep = prepare_text(text, strict=False)
        if not prep.ok:
            out.append({
                "id": item.get("id"),
                **_rejected_security(prep.errors),
            })
            continue
        r = analyze(text)
        _append_training_input(text, r["verdict"], r["category"], r.get("integrity"))
        out.append({"id": item.get("id"), "type": item.get("type", "post"), **r})
    return jsonify({"results": out, "count": len(out)})


@app.route("/feedback", methods=["POST", "OPTIONS"])
@_secured(require_text=True)
def route_feedback():
    d = getattr(request, "apex_payload", None) or request.get_json(silent=True) or {}
    text = str(d.get("text", "")).strip()
    action = str(d.get("admin_action", "")).strip().lower()
    category = str(d.get("category", "safe")).strip() or "safe"
    if action not in {"approve", "reject"}:
        return jsonify({"status": "error", "reason": "invalid_admin_action"}), 400
    label = 0 if action == "approve" else 1
    _append_feedback_sample(text, label, category)
    maybe_auto_retrain(RETRAIN_EVERY_N, RETRAIN_MIN, _load_models)
    return jsonify({"status": "ok", "label": label, "source": "admin_correction"})


@app.route("/integrity/check", methods=["POST", "OPTIONS"])
@_secured(require_text=True)
def route_integrity_check():
    d = getattr(request, "apex_payload", None) or {}
    text = str(d.get("text", "")).strip()
    prep = prepare_text(text)
    return jsonify(integrity_to_dict(prep))


@app.route("/health")
def health():
    with _model_lock:
        hate_ok = hate_pipeline is not None
        scam_ok = scam_pipeline is not None
    input_count = 0
    if INPUT_LOG.exists():
        try:
            input_count = sum(1 for _ in open(INPUT_LOG, encoding="utf-8"))
        except Exception:
            pass
    return jsonify({
        "status": "ok",
        "version": "6.1",
        "retrain_every_n": RETRAIN_EVERY_N,
        "semantic": SEMANTIC_ON,
        "hate_model": hate_ok,
        "scam_model": scam_ok,
        "threshold": THRESH,
        "hate_keywords": len(KW_HATE),
        "scam_keywords": len(KW_SCAM),
        "training_inputs": input_count,
        "retrain_min_samples": RETRAIN_MIN,
        "integrity": True,
        "security": True,
    })


@app.route("/stats")
def stats():
    if not LOG_FILE.exists():
        return jsonify({"total": 0, "forbidden": 0, "allowed": 0, "categories": {}})
    logs = []
    try:
        with open(LOG_FILE, encoding="utf-8") as fh:
            for line in fh:
                line = line.strip()
                if not line:
                    continue
                try:
                    logs.append(json.loads(line))
                except Exception:
                    pass
    except Exception:
        logs = []
    total = len(logs)
    forbidden = sum(1 for l in logs if l.get("verdict") == "FORBIDDEN")
    cats = {}
    for l in logs:
        if l.get("verdict") == "FORBIDDEN":
            c = l.get("category", "unknown")
            cats[c] = cats.get(c, 0) + 1
    return jsonify({
        "total": total,
        "forbidden": forbidden,
        "allowed": total - forbidden,
        "forbidden_pct": round(forbidden / total * 100, 1) if total else 0,
        "categories": cats,
    })


@app.route("/retrain", methods=["POST", "OPTIONS"])
@_secured(require_text=False)
def route_retrain():
    if not INPUT_LOG.exists():
        return jsonify({"status": "skipped", "reason": "No user input data yet."})
    try:
        line_count = sum(1 for _ in open(INPUT_LOG, encoding="utf-8"))
    except Exception:
        line_count = 0
    if line_count < RETRAIN_MIN:
        return jsonify({
            "status": "skipped",
            "reason": f"Only {line_count} inputs — need ≥{RETRAIN_MIN} to retrain.",
        })

    def _bg():
        try:
            r = subprocess.run(
                [sys.executable, str(BASE / "train_model.py"), "--incremental"],
                capture_output=True,
                text=True,
                timeout=300,
            )
            if r.returncode == 0:
                _load_models()
                log.info("Incremental retrain done — models hot-reloaded.")
            else:
                log.error("Retrain failed:\n%s", r.stderr)
        except Exception as ex:
            log.error("Retrain error: %s", ex)

    threading.Thread(target=_bg, daemon=True).start()
    return jsonify({
        "status": "started",
        "message": f"Retraining started with {line_count} inputs.",
    })


@app.route("/reload_models", methods=["POST", "OPTIONS"])
@_secured(require_text=False)
def route_reload():
    _load_models()
    with _model_lock:
        return jsonify({
            "status": "reloaded",
            "hate_model": hate_pipeline is not None,
            "scam_model": scam_pipeline is not None,
        })


@app.route("/test", methods=["POST", "OPTIONS"])
@_secured(require_text=False)
def route_test():
    d = getattr(request, "apex_payload", None) or request.get_json(silent=True) or {}
    return jsonify({
        "results": [
            {**analyze(str(t)), "input": str(t)[:100]}
            for t in d.get("texts", [])[:20]
        ]
    })


if __name__ == "__main__":
    log.info(
        "ApexSocial ML v6.1 | hate=%s scam=%s thresh=%.2f retrain_every=%d",
        "ok" if hate_pipeline else "MISSING",
        "ok" if scam_pipeline else "MISSING",
        THRESH,
        RETRAIN_EVERY_N,
    )
    try:
        from waitress import serve

        log.info("Starting Waitress on port 5000")
        serve(app, host="0.0.0.0", port=5000, threads=8)
    except ImportError:
        app.run(host="0.0.0.0", port=5000, debug=False)
