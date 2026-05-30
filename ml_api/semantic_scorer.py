"""Optional transformer-based semantic score (disabled when deps missing)."""
from __future__ import annotations

import json
import re
from functools import lru_cache
from pathlib import Path

from apex_log import setup_logging

log = setup_logging()

# Phrase buckets for threat / harassment / hate patterns
_THREAT_RE = re.compile(
    r"(?i)\b(?:kill|murder|shoot|stab|bomb|attack|hurt)\s+(?:you|them|him|her|us)\b"
    r"|\b(?:i will|i'll)\s+(?:kill|hurt|destroy|rape)\b"
    r"|\bdeath\s+threat\b"
)
_HARASS_RE = re.compile(
    r"(?i)\b(?:harass|stalk|bully|intimidate)\b"
    r"|\b(?:go\s+away|leave\s+me)\s+alone\b"
)
_HATE_RE = re.compile(
    r"(?i)\b(?:hate\s+(?:all|every)\s+\w+)\b"
    r"|\b(?:\w+\s+are\s+(?:animals|vermin|subhuman|trash))\b"
    r"|\b(?:deport|ethnic\s+cleansing|genocide)\b"
)

_pipeline = None
_load_failed = False
_MODEL_ID = "distilbert-base-uncased-finetuned-sst-2-english"


def _load_semantic_model_setting() -> str:
    cfg_path = Path(__file__).parent / "models" / "config.json"
    try:
        with open(cfg_path, encoding="utf-8") as fh:
            cfg = json.load(fh)
        return str(cfg.get("semantic_model", "")).strip().lower()
    except Exception:
        return ""


@lru_cache(maxsize=1)
def _get_sentiment_pipeline():
    # Default is SST-2 sentiment; set semantic_model in config.json for toxic-bert etc.
    global _pipeline, _load_failed, _MODEL_ID
    if _load_failed:
        return None
    if _pipeline is not None:
        return _pipeline

    semantic_model = _load_semantic_model_setting()
    if semantic_model == "toxic-bert":
        _MODEL_ID = "unitary/toxic-bert"
    else:
        _MODEL_ID = "distilbert-base-uncased-finetuned-sst-2-english"

    try:
        from transformers import pipeline

        _pipeline = pipeline(
            "text-classification",
            model=_MODEL_ID,
            truncation=True,
            max_length=512,
            device=-1,
        )
        log.info("Semantic model loaded: %s", _MODEL_ID)
        return _pipeline
    except Exception as ex:
        _load_failed = True
        log.warning("Semantic model unavailable (pip install transformers torch): %s", ex)
        return None


def semantic_harm_score(text: str) -> tuple[float | None, list[str], str]:
    """
    Returns (harm_probability 0-1 or None, notes, method).
    Combines transformer sentiment + policy regexes.
    """
    if not text or len(text.strip()) < 3:
        return None, [], "none"

    notes: list[str] = []
    policy_boost = 0.0
    if _THREAT_RE.search(text):
        policy_boost = max(policy_boost, 0.85)
        notes.append("policy_threat")
    if _HATE_RE.search(text):
        policy_boost = max(policy_boost, 0.75)
        notes.append("policy_hate")
    if _HARASS_RE.search(text):
        policy_boost = max(policy_boost, 0.65)
        notes.append("policy_harass")

    pipe = _get_sentiment_pipeline()
    if pipe is None:
        if policy_boost > 0:
            return policy_boost, notes, "policy_regex"
        return None, notes, "none"

    try:
        chunk = text[:1500]
        out = pipe(chunk)[0]
        label = (out.get("label") or "").upper()
        score = float(out.get("score", 0.5))

        if _MODEL_ID == "unitary/toxic-bert":
            toxic_prob = score if label == "TOXIC" else (1.0 - score) * 0.15
            combined = max(toxic_prob, policy_boost)
            if toxic_prob > 0.65:
                notes.append("semantic_toxic")
        else:
            neg_prob = score if label == "NEGATIVE" else (1.0 - score) * 0.35
            combined = max(neg_prob * 0.55, policy_boost)
            if neg_prob > 0.7:
                notes.append("semantic_negative")

        return min(1.0, combined), notes, "transformer+policy"
    except Exception as ex:
        log.warning("Semantic inference failed: %s", ex)
        if policy_boost > 0:
            return policy_boost, notes, "policy_regex"
        return None, notes, "none"
