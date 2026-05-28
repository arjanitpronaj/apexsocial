import re



from text_integrity import prepare_text



NEGATION_WORDS = frozenset({

    "not",

    "no",

    "never",

    "dont",

    "don't",

    "doesnt",

    "doesn't",

    "didnt",

    "didn't",

    "cant",

    "can't",

    "wont",

    "won't",

})



NEGATION_LOOKBACK = 3

_TOKEN_RE = re.compile(r"(?u)\b[\w']+\b")



# Control chars only (preserve \n \t); remove 0x00-0x1F except tab/newline/cr

_CONTROL_RE = re.compile(r"[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]")



_URL_RE = re.compile(

    r"(?i)"

    r"(?:https?://|hxxps?://|www\.)[^\s<>\"']+"

    r"|"

    r"\b[a-z0-9][-a-z0-9]{0,62}\."

    r"(?:xy|tk|top|click|xyz|lol|bond|icu|sbs|cam|gq|cf|ml|ga|pw|su|rest|buzz|zip|mov|cfd|sbs|lat|monster|quest|hair|shop|link|site|online|website|space|cfd|boo|deals|coupons|store)"

    r"(?:/[^\s]*)?\b"

    r"|"

    r"\b[a-z0-9][-a-z0-9]{0,62}\.[a-z]{2,12}/[^\s]{3,}\b"

)



_SCAM_URL_CONTEXT_RE = re.compile(

    r"(?i)\b(?:gift|gifts|register|signup|sign\s*up|claim|prize|won|winner|free|"

    r"click|verify|login|log\s*in|password|bitcoin|crypto|send\s+money|cash|"

    r"\$\d|urgent|limited\s+time)\b"

)





def strip_control_chars(text: str) -> str:

    """Remove dangerous control bytes; keep Unicode letters and user whitespace."""

    if not text:

        return ""

    return _CONTROL_RE.sub("", str(text))





def find_urls(text: str) -> list[str]:

    if not text:

        return []

    seen: set[str] = set()

    out: list[str] = []

    for m in _URL_RE.finditer(text):

        u = m.group(0).strip().rstrip(".,;:!?)\"']")

        key = u.lower()

        if key not in seen:

            seen.add(key)

            out.append(u)

    return out





def url_scam_boost(text: str) -> tuple[float, list[str]]:

    urls = find_urls(text)

    if not urls:

        return 0.0, []



    notes: list[str] = []

    boost = 20.0

    notes.append("url_present")



    t = text.lower()

    if _SCAM_URL_CONTEXT_RE.search(t):

        boost = max(boost, 28.0)

        notes.append("scam_context")



    for u in urls:

        ul = u.lower()

        if re.search(

            r"\.(?:xy|tk|top|click|xyz|lol|bond|icu|sbs|cam|gq|cf|ml|ga|pw|su)\b",

            ul,

        ):

            boost = max(boost, 32.0)

            notes.append("suspicious_tld")

            break

        if re.search(r"https?://|hxxps?://|www\.", ul):

            boost = max(boost, 24.0)

            notes.append("http_link")



    return min(35.0, boost), notes





def ml_features(text: str) -> str:

    """

    Feature string for ML / training only — may normalize internal spaces.

    Preserves all valid Unicode (CJK, Arabic, Albanian ë/ç, etc.).

    """

    raw = strip_control_chars(str(text))

    res = prepare_text(raw, strict=False)

    t = res.normalized if res.ok else raw

    t = _URL_RE.sub(" url ", t)

    t = re.sub(r"@\w+", " user ", t, flags=re.UNICODE)

    t = re.sub(r"(?i)\brt\s+", "", t)

    # Collapse only runs of 2+ spaces/tabs (never strip edges of original message storage)

    t = re.sub(r"[ \t]{2,}", " ", t)

    t = re.sub(r"\n{3,}", "\n\n", t)

    return t.strip()





def clean(text: str) -> str:

    """Alias for training pipeline — same as ml_features."""

    return ml_features(text)





def normalize_keywords(text: str) -> str:

    t = ml_features(text).lower()

    t = t.replace("@", "a").replace("$", "s").replace("€", "e").replace("£", "l")

    t = t.replace("!", "i").replace("|", "i")

    t = t.replace("0", "o").replace("1", "i").replace("3", "e").replace("4", "a")

    t = t.replace("5", "s").replace("7", "t")

    t = re.sub(r"(?u)(\w)'(\w)", r"\1\2", t)

    t = re.sub(r"[\W_]+", " ", t, flags=re.UNICODE)

    return re.sub(r"\s+", " ", t).strip()





def _token_variants(token: str) -> set[str]:

    t = token.lower().strip()

    variants = {t, t.replace("'", "")}

    if "'" in t:

        variants.add(t.replace("'", ""))

    return {v for v in variants if v}





def _is_negation_token(token: str) -> bool:

    return bool(_token_variants(token) & NEGATION_WORDS)





def _tokens_before(text: str, char_index: int, lookback: int = NEGATION_LOOKBACK) -> list[str]:

    if char_index <= 0:

        return []

    return _TOKEN_RE.findall(text[:char_index])[-lookback:]





def _has_negation_before(text: str, char_index: int) -> bool:

    return any(_is_negation_token(tok) for tok in _tokens_before(text, char_index))





def _find_valid_single_word(text: str, word: str) -> int | None:

    if not text or not word:

        return None

    pattern = re.compile(r"\b" + re.escape(word) + r"\b", re.UNICODE)

    for match in pattern.finditer(text):

        if not _has_negation_before(text, match.start()):

            return match.start()

    return None





def _find_valid_phrase(text: str, phrase: str) -> int | None:

    if not text or not phrase:

        return None

    start = 0

    while start <= len(text) - len(phrase):

        idx = text.find(phrase, start)

        if idx == -1:

            return None

        if not _has_negation_before(text, idx):

            return idx

        start = idx + 1

    return None





def _match_keyword_in_text(text: str, keyword: str) -> bool:

    words = keyword.split()

    if len(words) == 1:

        return _find_valid_single_word(text, keyword) is not None

    return _find_valid_phrase(text, keyword) is not None





def keyword_match(text: str, kw_list: list):

    tl = text.lower()

    tn = normalize_keywords(text)

    for kw in kw_list:

        kl = kw.lower().strip()

        if not kl:

            continue

        if _match_keyword_in_text(tl, kl) or _match_keyword_in_text(tn, kl):

            return True, kw

    return False, None





_SAFE_HATE_PHRASES = (

    re.compile(r"(?i)\b(?:against|oppose|condemn|fight(?:ing)?|end(?:ing)?|stop(?:ping)?|eradicate)\s+hate\b"),

    re.compile(r"(?i)\banti[- ]?hate\b"),

    re.compile(r"(?i)\bno\s+place\s+for\s+hate\b"),

    re.compile(r"(?i)\bstand\s+against\s+hate\b"),

    re.compile(r"(?i)\bzero\s+tolerance\s+for\s+hate\b"),

    re.compile(

        r"(?i)\bhate\s+(?:speech|crime|bullying|violence|racism|sexism|"

        r"discrimination|injustice|war|poverty|corruption)\b"

    ),

)

_HATE_TOKEN_RE = re.compile(r"(?u)\bhate\b")





def _hate_token_negated(text: str) -> bool:

    for src in (text.lower(), normalize_keywords(text)):

        for match in _HATE_TOKEN_RE.finditer(src):

            if _has_negation_before(src, match.start()):

                return True

    return False





def _has_safe_hate_context(text: str) -> bool:

    return any(pat.search(text) for pat in _SAFE_HATE_PHRASES)





def adjust_ml_probs(text: str, hate_prob: float, scam_prob: float) -> tuple[float, float, list[str]]:

    notes: list[str] = []

    hp = max(0.0, min(1.0, float(hate_prob)))

    sp = max(0.0, min(1.0, float(scam_prob)))



    if _hate_token_negated(text):

        hp *= 0.18

        notes.append("negated_hate")

    if _has_safe_hate_context(text):

        hp *= 0.25

        notes.append("safe_hate_context")



    return hp, sp, notes


