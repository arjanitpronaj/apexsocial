"""Context-aware scoring — negation, sentence structure, borderline thresholds."""
from __future__ import annotations

import re

from text_utils import (
    _has_safe_hate_context,
    _hate_token_negated,
    adjust_ml_probs,
)

# Quoted / reported speech — often discussing harm, not committing it
_QUOTED_RE = re.compile(r'(?i)["\u201c].{8,}["\u201d]')
_REPORTING_RE = re.compile(
    r"(?i)\b(?:reported|article|news|study|research|according to|he said|she said)\b"
)
_QUESTION_RE = re.compile(r"(?i)^(what|why|how|is|are|does|do|can)\b.+\?$")


def analyze_sentence_context(text: str) -> dict:
    """Structural signals that reduce false positives."""
    t = text.strip()
    notes: list[str] = []
    dampen = 1.0

    if _QUESTION_RE.search(t):
        dampen *= 0.75
        notes.append("question_form")
    if _QUOTED_RE.search(t) and _REPORTING_RE.search(t):
        dampen *= 0.55
        notes.append("reported_speech")
    elif _QUOTED_RE.search(t):
        dampen *= 0.7
        notes.append("quoted_text")

    sentences = re.split(r"(?<=[.!?])\s+", t)
    if len(sentences) >= 2:
        neg_in_any = any(_hate_token_negated(s) for s in sentences)
        if neg_in_any:
            dampen *= 0.65
            notes.append("multi_sentence_negation")

    if _has_safe_hate_context(t):
        dampen *= 0.5
        notes.append("safe_hate_context")

    return {"dampen": dampen, "notes": notes}


def apply_context_to_probs(
    text: str,
    hate_prob: float,
    scam_prob: float,
    thresh_low: float,
    thresh_high: float,
    language_hint: str | None = None,
    hate_keyword_matched: bool = False,
) -> tuple[float, float, list[str]]:
    """
    Returns adjusted probs and notes.
    """
    hp, sp, notes = adjust_ml_probs(text, hate_prob, scam_prob)
    ctx = analyze_sentence_context(text)
    hp *= ctx["dampen"]
    sp *= ctx["dampen"]
    notes.extend(ctx["notes"])

    # English-only keyword list + mostly English training data can over-score
    # non-Latin scripts for hate when no hate keyword signal exists.
    lang = (language_hint or "").strip().lower()
    if (not hate_keyword_matched) and lang in {"arabic", "cyrillic", "cjk", "mixed"}:
        hp *= 0.75
        notes.append("foreign_script_dampen")

    return hp, sp, notes
