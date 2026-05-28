"""
Text integrity and multilingual normalization (Steps 1–2).
Runs before ML classification and training.
"""
from __future__ import annotations

import re
import unicodedata
from dataclasses import dataclass, field

# --- Homoglyph / confusable maps (Unicode bypass) ---
_HOMOGLYPH_MAP: dict[int, str] = {}

def _add_map(chars: str, target: str) -> None:
    for ch in chars:
        _HOMOGLYPH_MAP[ord(ch)] = target


# Cyrillic / Greek → Latin lookalikes (per-character)
for _cyr, _lat in (
    ("а", "a"), ("е", "e"), ("о", "o"), ("р", "p"), ("с", "c"), ("у", "y"), ("х", "x"),
    ("і", "i"), ("ё", "e"), ("ᴀ", "a"),
    ("α", "a"), ("β", "b"), ("ε", "e"), ("ο", "o"), ("ρ", "p"), ("υ", "u"), ("χ", "x"),
):
    _HOMOGLYPH_MAP[ord(_cyr)] = _lat
# Fullwidth Latin
for i in range(0xFF01, 0xFF5F):
    _HOMOGLYPH_MAP[i] = chr(i - 0xFEE0) if 0x21 <= i - 0xFEE0 <= 0x7E else " "
# Arabic-Indic / Eastern Arabic digits → ASCII
for i, d in enumerate("0123456789"):
    _HOMOGLYPH_MAP[0x0660 + i] = d
    _HOMOGLYPH_MAP[0x06F0 + i] = d
# Common leet / symbols
_SYMBOL_MAP = {
    "@": "a", "$": "s", "€": "e", "£": "l", "!": "i", "|": "i",
    "0": "o", "1": "i", "3": "e", "4": "a", "5": "s", "7": "t",
    "©": "c", "®": "r",
}

# Zero-width and invisible characters
_INVISIBLE_RE = re.compile(
    r"[\u200b-\u200f\u202a-\u202e\u2060-\u2064\ufeff\u00ad\u034f\u061c]"
)

# Masked words: h@te, sh!t, f*ck, n1gga with separators
_MASKED_WORD_RE = re.compile(
    r"(?i)\b([a-z]{1,3})[\W_]{0,2}([a-z@$!0-9]{1,3})[\W_]{0,2}([a-z@$!0-9]{2,})\b"
)

# Repeated character spam (aaaaaa)
_REPEAT_SPAM_RE = re.compile(r"(.)\1{6,}")

# Mixed-script ratio (non-Latin in Latin context)
_NON_LATIN_RE = re.compile(r"[^\x00-\x7F\u00a3\u20ac]")

_MAX_TEXT_LEN = 8000
_MIN_TEXT_LEN = 2


@dataclass
class IntegrityResult:
    ok: bool
    text: str = ""
    normalized: str = ""
    ml_text: str = ""
    errors: list[str] = field(default_factory=list)
    warnings: list[str] = field(default_factory=list)
    flags: list[str] = field(default_factory=list)
    bypass_score: float = 0.0
    scripts: dict[str, float] = field(default_factory=dict)
    language_hint: str = "unknown"


def _fold_homoglyphs(s: str) -> str:
    out: list[str] = []
    for ch in s:
        o = ord(ch)
        if o in _HOMOGLYPH_MAP:
            out.append(_HOMOGLYPH_MAP[o])
        else:
            out.append(ch)
    return "".join(out)


def _apply_symbol_map(s: str) -> str:
    for sym, rep in _SYMBOL_MAP.items():
        s = s.replace(sym, rep)
    return s


def _detect_scripts(text: str) -> dict[str, float]:
    if not text:
        return {}
    counts: dict[str, int] = {}
    for ch in text:
        if ch.isspace() or not ch.isprintable():
            continue
        name = unicodedata.name(ch, "")
        if "LATIN" in name:
            key = "latin"
        elif "CYRILLIC" in name:
            key = "cyrillic"
        elif "ARABIC" in name:
            key = "arabic"
        elif "CJK" in name or "HIRAGANA" in name or "KATAKANA" in name:
            key = "cjk"
        elif "GREEK" in name:
            key = "greek"
        else:
            key = "other"
        counts[key] = counts.get(key, 0) + 1
    total = sum(counts.values()) or 1
    return {k: round(v / total, 3) for k, v in counts.items()}


def _language_hint(scripts: dict[str, float]) -> str:
    if not scripts:
        return "unknown"
    dominant = max(scripts, key=scripts.get)
    if dominant == "latin" and scripts.get("latin", 0) > 0.85:
        return "latin"
    if scripts.get("cyrillic", 0) > 0.2:
        return "cyrillic"
    if scripts.get("arabic", 0) > 0.2:
        return "arabic"
    if scripts.get("cjk", 0) > 0.15:
        return "cjk"
    if scripts.get("greek", 0) > 0.15:
        return "greek"
    if len(scripts) > 1:
        return "mixed"
    return dominant


def _bypass_score(text: str, flags: list[str]) -> float:
    score = 0.0
    if "homoglyph" in flags:
        score += 22.0
    if "mixed_script" in flags:
        score += 18.0
    if "masked_word" in flags:
        score += 25.0
    if "invisible_chars" in flags:
        score += 30.0
    if "repeat_spam" in flags:
        score += 12.0
    if _MASKED_WORD_RE.search(text):
        score = max(score, 20.0)
        if "masked_word" not in flags:
            flags.append("masked_word")
    return min(40.0, score)


def normalize_unicode(text: str, form: str = "NFKC") -> str:
    """Unicode NFKC/NFKD normalization + homoglyph folding."""
    if not text:
        return ""
    t = unicodedata.normalize(form, text)
    t = _INVISIBLE_RE.sub("", t)
    raw = t
    t = _fold_homoglyphs(t)
    if t != raw:
        pass  # flag set by caller
    t = _apply_symbol_map(t)
    return t


def validate_structure(text: str) -> list[str]:
    """Return validation error codes (empty = OK)."""
    errors: list[str] = []
    if not text or not str(text).strip():
        errors.append("empty")
        return errors
    if len(text) > _MAX_TEXT_LEN:
        errors.append("too_long")
    if len(text.strip()) < _MIN_TEXT_LEN:
        errors.append("too_short")
    # Null bytes / control chars (except common whitespace)
    if re.search(r"[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]", text):
        errors.append("control_chars")
    # Excessive non-printable ratio
    non_print = sum(1 for c in text if not c.isprintable() and c not in "\n\r\t")
    if len(text) > 20 and non_print / len(text) > 0.15:
        errors.append("corrupted")
    return errors


def prepare_text(raw: str, *, strict: bool = True) -> IntegrityResult:
    """
    Full integrity pipeline: validate → normalize → flags → ML-ready string.
    """
    raw = str(raw or "")
    errors = validate_structure(raw)
    if errors and strict:
        return IntegrityResult(
            ok=False,
            text=raw[:500],
            errors=errors,
        )

    flags: list[str] = []
    warnings: list[str] = []

    if _INVISIBLE_RE.search(raw):
        flags.append("invisible_chars")
    if _REPEAT_SPAM_RE.search(raw):
        flags.append("repeat_spam")

    nfkc_base = unicodedata.normalize("NFKC", _INVISIBLE_RE.sub("", raw))
    normalized = normalize_unicode(raw)
    folded = _fold_homoglyphs(nfkc_base)
    if folded != nfkc_base.lower() or _apply_symbol_map(nfkc_base) != nfkc_base:
        flags.append("homoglyph")

    scripts = _detect_scripts(normalized)
    lang = _language_hint(scripts)
    if len(scripts) > 1 and scripts.get("latin", 0) > 0.4:
        flags.append("mixed_script")
        warnings.append("mixed_alphabet")

    # Lowercase for ML (preserve original in .text)
    lowered = normalized.lower()

    # Collapse masked separators: h.a.t.e → hate
    def _collapse_masked(m: re.Match) -> str:
        return "".join(g for g in m.groups() if g)

    lowered = _MASKED_WORD_RE.sub(_collapse_masked, lowered)

    bypass = _bypass_score(lowered, flags)

    return IntegrityResult(
        ok=True,
        text=raw.strip()[:_MAX_TEXT_LEN],
        normalized=normalized.strip()[:_MAX_TEXT_LEN],
        ml_text=lowered,
        errors=errors,
        warnings=warnings,
        flags=flags,
        bypass_score=bypass,
        scripts=scripts,
        language_hint=lang,
    )


def integrity_to_dict(res: IntegrityResult) -> dict:
    return {
        "ok": res.ok,
        "errors": res.errors,
        "warnings": res.warnings,
        "flags": res.flags,
        "bypass_score": round(res.bypass_score, 1),
        "language_hint": res.language_hint,
        "scripts": res.scripts,
        "normalized_preview": (res.normalized or "")[:120],
    }
