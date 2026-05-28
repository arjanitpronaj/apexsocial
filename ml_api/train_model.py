import json
import pickle
import sys
from pathlib import Path

import pandas as pd
from sklearn.calibration import CalibratedClassifierCV
from sklearn.feature_extraction.text import TfidfVectorizer
from sklearn.linear_model import LogisticRegression
from sklearn.metrics import (
    accuracy_score,
    classification_report,
    precision_recall_fscore_support,
)
from sklearn.model_selection import train_test_split
from sklearn.pipeline import Pipeline
from sklearn.utils import resample

from apex_log import setup_logging
from text_utils import clean, keyword_match

log = setup_logging()

INCREMENTAL = "--incremental" in sys.argv

BASE = Path(__file__).parent
MODEL_DIR = BASE / "models"
DS_DIR = MODEL_DIR / "datasets"
INPUT_LOG = MODEL_DIR / "user_inputs.jsonl"
MODEL_DIR.mkdir(exist_ok=True)
DS_DIR.mkdir(exist_ok=True)

log.info("=" * 62)
log.info("  ApexSocial Trainer v6.0  (%s)", 'incremental' if INCREMENTAL else 'full')
log.info("=" * 62)


def load_keywords_from_config():
    cfg_path = MODEL_DIR / "config.json"
    if not cfg_path.exists():
        return [], []
    with open(cfg_path, encoding="utf-8") as f:
        cfg = json.load(f)
    hate = cfg.get("keywords", []) or []
    scam = cfg.get("scam_keywords", cfg.get("phishing_keywords", [])) or []
    return hate, scam


def _kw_hit_train(text: str, kw_list: list) -> bool:
    hit, _ = keyword_match(text, kw_list)
    return hit


def repair_user_label_noise(df: pd.DataFrame, hate_kw: list, scam_kw: list) -> pd.DataFrame:
    if df.empty or (not hate_kw and not scam_kw):
        return df
    df = df.copy()
    fixed = 0
    for i in df.index:
        if int(df.at[i, "label"]) != 0:
            continue
        tx = df.at[i, "text"]
        if _kw_hit_train(tx, hate_kw) or _kw_hit_train(tx, scam_kw):
            df.at[i, "label"] = 1
            fixed += 1
    if fixed:
        log.info(f"[Repair] Keyword-fixed {fixed:,} rows wrongly labeled safe -> harmful.")
    return df


def read_file(path: Path) -> pd.DataFrame:
    with open(path, "rb") as f:
        magic = f.read(4)
    if magic[:2] == b"PK":
        try:
            return pd.read_excel(path, engine="openpyxl")
        except Exception:
            return pd.read_excel(path)
    for enc in ("utf-8", "latin-1", "cp1252"):
        try:
            return pd.read_csv(path, encoding=enc)
        except Exception:
            continue
    return pd.read_csv(path, encoding="utf-8", errors="replace")


def find_file(directory: Path, patterns: list):
    if not directory.exists():
        return None
    for f in sorted(directory.iterdir()):
        if not f.is_file() or f.suffix.lower() not in {".csv", ".xlsx", ".xls", ""}:
            continue
        if any(p.lower() in f.stem.lower() for p in patterns):
            return f
    return None


def find_col(df: pd.DataFrame, keywords: list):
    for col in df.columns:
        if any(kw.lower() in col.lower() for kw in keywords):
            return col
    return None


def build_pipeline(n_samples: int) -> Pipeline:
    min_df = 2 if n_samples >= 8000 else 1
    # Drop terms in >92% of docs (e.g. repeated "user"/"url" after clean()) on large corpora.
    max_df = 0.92 if n_samples >= 2000 else 1.0

    if n_samples >= 5000:
        calib_cv = 5
    elif n_samples >= 800:
        calib_cv = 3
    else:
        calib_cv = 2

    # char_wb: Unicode-safe (CJK, Arabic, Latin, diacritics) — no strip_accents corruption
    base_lr = LogisticRegression(
        C=0.8,
        solver="saga",
        max_iter=4000,
        tol=1e-4,
        class_weight="balanced",
        random_state=42,
    )

    return Pipeline([
        ("tfidf", TfidfVectorizer(
            max_features=30000,
            analyzer="char_wb",
            ngram_range=(3, 5),
            sublinear_tf=True,
            min_df=min_df,
            max_df=max_df,
            lowercase=False,
        )),
        ("clf", CalibratedClassifierCV(
            estimator=base_lr,
            method="sigmoid",
            cv=calib_cv,
            n_jobs=-1,
        )),
    ])


def balance_dataset(df: pd.DataFrame, max_ratio: float = 3.0) -> pd.DataFrame:
    if df is None or df.empty:
        return df
    df = df.copy()
    n0 = int((df["label"] == 0).sum())
    n1 = int((df["label"] == 1).sum())
    log.info(f"[Balance] Before — safe:{n0:,} harmful:{n1:,} (total {len(df):,})")

    if n0 == 0 or n1 == 0:
        log.info("[Balance] Only one class present — skipping.")
        return df

    maj_label = 0 if n0 >= n1 else 1
    min_label = 1 - maj_label
    counts = {0: n0, 1: n1}
    maj_n = counts[maj_label]
    min_n = counts[min_label]
    ratio = maj_n / min_n

    if ratio <= max_ratio:
        log.info(f"[Balance] Ratio {ratio:.2f} <= {max_ratio} — unchanged.")
        return df

    target_maj = int(min_n * max_ratio)
    maj_df = df[df["label"] == maj_label]
    min_df = df[df["label"] == min_label]
    maj_down = resample(
        maj_df,
        replace=False,
        n_samples=target_maj,
        random_state=42,
    )
    balanced = pd.concat([min_df, maj_down], ignore_index=True)
    balanced = balanced.sample(frac=1, random_state=42).reset_index(drop=True)

    n0_after = int((balanced["label"] == 0).sum())
    n1_after = int((balanced["label"] == 1).sum())
    log.info(f"[Balance] After — safe:{n0_after:,} harmful:{n1_after:,} (total {len(balanced):,})")
    return balanced


def train_and_save(X, y, name: str) -> Pipeline:
    log.info(f"\n[Train] '{name}' — {len(X):,} examples")
    if len(X) < 10:
        log.info(f"[Train] Skipping '{name}' — not enough data.")
        return None

    pipe = build_pipeline(len(X))
    X_tr, X_te, y_tr, y_te = train_test_split(
        X, y, test_size=0.2, random_state=42, stratify=y
    )
    pipe.fit(X_tr, y_tr)
    y_pred = pipe.predict(X_te)
    acc = accuracy_score(y_te, y_pred)
    prec, rec, f1, _ = precision_recall_fscore_support(
        y_te, y_pred, average="binary", zero_division=0
    )
    log.info(f"[Train] Accuracy:{acc:.2%}  Precision:{prec:.3f}  Recall:{rec:.3f}  F1:{f1:.3f}")
    log.info(classification_report(y_te, y_pred, target_names=["safe", "harmful"], zero_division=0))

    out = MODEL_DIR / f"{name}.pkl"
    with open(out, "wb") as f:
        pickle.dump(pipe, f)
    log.info(f"[Train] Saved -> models/{name}.pkl")
    return pipe


def load_user_inputs() -> pd.DataFrame:
    if not INPUT_LOG.exists():
        return pd.DataFrame(columns=["text", "label"])
    rows = []
    with open(INPUT_LOG, encoding="utf-8") as fh:
        for line in fh:
            line = line.strip()
            if not line:
                continue
            try:
                rows.append(json.loads(line))
            except Exception:
                pass
    if not rows:
        return pd.DataFrame(columns=["text", "label"])
    raw = pd.DataFrame(rows)
    cols = ["text", "label"]
    if "category" in raw.columns:
        cols.append("category")
    df = raw[cols].dropna(subset=["text", "label"])
    df["text"] = df["text"].apply(clean)
    df = df[df["text"].str.len() > 3]
    df["label"] = df["label"].astype(int)
    log.info(f"[UserInputs] {len(df):,} records loaded from user_inputs.jsonl")
    return df


def gather_static_scam_frames() -> list:
    phish_rows = []

    scam_file = (
        find_file(DS_DIR, ["scam", "spam", "sms_spam"])
        or find_file(MODEL_DIR, ["scam", "spam"])
    )
    if scam_file:
        log.info(f"\n[Scam] {scam_file.name}")
        df = read_file(scam_file)
        lbl_col = find_col(df, ["class", "label", "type", "spam", "category"]) or df.columns[0]
        txt_col = (
            find_col(df, ["message", "text", "body", "sms", "content"])
            or (df.columns[1] if len(df.columns) > 1 else df.columns[0])
        )
        sv = str(df[txt_col].dropna().iloc[0]).lower().strip() if len(df) else ""
        if sv in ("ham", "spam", "0", "1") and txt_col != lbl_col:
            txt_col, lbl_col = lbl_col, txt_col
        df = df[[txt_col, lbl_col]].dropna()
        df["tc"] = df[txt_col].apply(clean)
        df["lb"] = df[lbl_col].apply(
            lambda x: 0
            if str(x).lower().strip() in ("ham", "safe", "0", "no", "normal", "legitimate")
            else 1
        )
        n0, n1 = (df.lb == 0).sum(), (df.lb == 1).sum()
        log.info(f"[Scam] {len(df):,} rows — safe:{n0:,} harmful:{n1:,}")
        phish_rows.append(df[["tc", "lb"]].rename(columns={"tc": "text", "lb": "label"}))
    else:
        log.info(f"[Scam] scam.csv not found -> place in {DS_DIR}/scam.csv")

    url_file = find_file(DS_DIR, ["malicious_phish", "maliciousphish", "malicious"])
    if url_file:
        log.info(f"\n[URL] {url_file.name}")
        df = read_file(url_file)
        uc = find_col(df, ["url", "link", "address", "domain"]) or df.columns[0]
        lc = (
            find_col(df, ["type", "label", "class", "category"])
            or (df.columns[1] if len(df.columns) > 1 else df.columns[0])
        )
        df = df[[uc, lc]].dropna()
        df["tc"] = df[uc].apply(clean)
        df["lb"] = df[lc].apply(
            lambda x: 0
            if str(x).lower().strip() in ("benign", "safe", "legitimate", "0", "no")
            else 1
        )
        lim = min(5000, (df.lb == 0).sum(), (df.lb == 1).sum())
        df_b = pd.concat([
            df[df.lb == 0].sample(lim, random_state=42),
            df[df.lb == 1].sample(lim, random_state=42),
        ])
        phish_rows.append(df_b[["tc", "lb"]].rename(columns={"tc": "text", "lb": "label"}))
        log.info(f"[URL] {len(df_b):,} balanced rows")

    phi_file = find_file(DS_DIR, ["phiusiil", "phiusl", "phishing_url"])
    if phi_file:
        log.info(f"\n[PhiUSIIL] {phi_file.name}")
        df = read_file(phi_file)
        uc = find_col(df, ["url", "link", "address"]) or (
            df.columns[1] if len(df.columns) > 1 else df.columns[0]
        )
        lc = find_col(df, ["label", "class", "type", "result", "target", "phishing", "status"])
        if not lc:
            for col in reversed(df.columns):
                if set(df[col].dropna().unique()).issubset({0, 1, "0", "1", 0.0, 1.0}):
                    lc = col
                    break
        if uc and lc:
            df = df[[uc, lc]].dropna()
            df["tc"] = df[uc].apply(clean)
            df["lb"] = df[lc].apply(
                lambda x: 0
                if str(x).lower().strip() in ("0", "benign", "safe", "no")
                else 1
            )
            lim = min(5000, (df.lb == 0).sum(), (df.lb == 1).sum())
            if lim > 0:
                df_b = pd.concat([
                    df[df.lb == 0].sample(lim, random_state=42),
                    df[df.lb == 1].sample(lim, random_state=42),
                ])
                phish_rows.append(df_b[["tc", "lb"]].rename(columns={"tc": "text", "lb": "label"}))
                log.info(f"[PhiUSIIL] {len(df_b):,} balanced rows")

    return phish_rows


def update_config():
    cfg_path = MODEL_DIR / "config.json"
    cfg = {}
    if cfg_path.exists():
        with open(cfg_path, encoding="utf-8") as f:
            cfg = json.load(f)
    changed = False
    if float(cfg.get("threshold", 0)) < 0.50:
        cfg["threshold"] = 0.52
        changed = True
    if changed:
        with open(cfg_path, "w", encoding="utf-8") as f:
            json.dump(cfg, f, indent=2, ensure_ascii=False)
        log.info("[Config] threshold updated to 0.52")
    else:
        log.info(f"[Config] threshold = {cfg.get('threshold')} (unchanged)")


if INCREMENTAL:
    log.info("\n[Mode] Incremental — extending models with user input data")
    update_config()
    hate_kw, scam_kw = load_keywords_from_config()
    user_df = load_user_inputs()
    if user_df.empty:
        log.info("[Incremental] No user input data. Exiting.")
        sys.exit(0)

    user_df = repair_user_label_noise(user_df, hate_kw, scam_kw)

    cat_series = (
        user_df["category"].astype(str).fillna("")
        if "category" in user_df.columns
        else pd.Series("", index=user_df.index)
    )
    is_scam_cat = cat_series.str.strip().eq("phishing_scam")
    user_safe = user_df[user_df.label == 0][["text", "label"]]
    user_scam_pos = user_df[(user_df.label == 1) & is_scam_cat][["text", "label"]]
    user_hate_pos = user_df[(user_df.label == 1) & ~is_scam_cat][["text", "label"]]

    base_csv = MODEL_DIR / "dataset.csv"
    hate_chunks = []
    if base_csv.exists():
        base_df = pd.read_csv(base_csv)
        if "text" in base_df.columns and "label" in base_df.columns:
            hate_chunks.append(base_df[["text", "label"]])
    hate_chunks.extend([user_safe, user_hate_pos])
    combined_hate = pd.concat(hate_chunks, ignore_index=True).dropna().drop_duplicates(subset=["text"])
    combined_hate = combined_hate[combined_hate["text"].str.len() > 3]
    n0h, n1h = (combined_hate.label == 0).sum(), (combined_hate.label == 1).sum()
    log.info(f"[Incremental] Hate blend: {len(combined_hate):,} rows — safe:{n0h:,} harmful:{n1h:,}")
    if n0h >= 5 and n1h >= 5:
        combined_hate = balance_dataset(combined_hate)
        train_and_save(combined_hate["text"], combined_hate["label"], "pipeline")
    else:
        log.info(f"[Incremental] Skip hate retrain (need ≥5 each class; got safe={n0h}, harmful={n1h}).")

    phish_static = gather_static_scam_frames()
    scam_chunks = list(phish_static) + [user_safe, user_scam_pos]
    df_scam = pd.concat(scam_chunks, ignore_index=True).dropna()
    df_scam = df_scam[df_scam["text"].str.len() > 3].drop_duplicates(subset=["text"])
    n0s, n1s = (df_scam.label == 0).sum(), (df_scam.label == 1).sum()
    log.info(f"[Incremental] Scam blend: {len(df_scam):,} rows — safe:{n0s:,} harmful:{n1s:,}")
    if phish_static and n0s >= 5 and n1s >= 5:
        df_scam = balance_dataset(df_scam)
        train_and_save(df_scam["text"], df_scam["label"], "scam_pipeline")
    elif not phish_static:
        log.info("[Incremental] No static scam/phishing CSVs — skipping scam_pipeline.")
    else:
        log.info(f"[Incremental] Skip scam retrain (need ≥5 each class; got safe={n0s}, harmful={n1s}).")

    with open(INPUT_LOG, encoding="utf-8") as f:
        all_lines = f.readlines()
    if len(all_lines) > 10000:
        with open(INPUT_LOG, "w", encoding="utf-8") as fh:
            fh.writelines(all_lines[-10000:])
        log.info("[Incremental] Trimmed user_inputs.jsonl to last 10,000 entries.")

    log.info("\n[Incremental] Done.")
    sys.exit(0)

update_config()

log.info("\n" + "=" * 62)
log.info("  MODEL 1: Hate  (pipeline.pkl)")
log.info("=" * 62)

hate_file = MODEL_DIR / "labeled_data.csv"
if not hate_file.exists():
    fallback = DS_DIR / "labeled_data.csv"
    if fallback.exists():
        hate_file = fallback
        log.info("[Hate] Using fallback dataset: %s", hate_file)
    else:
        log.error("[ERROR] labeled_data.csv not found in models/ or models/datasets/ — insert it and rerun.")
        sys.exit(1)
else:
    log.info("[Hate] Using dataset: %s", hate_file)

df_hate = read_file(hate_file)
log.info(f"[Hate] columns: {list(df_hate.columns)}")
df_hate["clean"] = df_hate["tweet"].apply(clean)
df_hate["label"] = df_hate["class"].apply(lambda x: 0 if int(x) == 2 else 1)

n0, n1 = (df_hate.label == 0).sum(), (df_hate.label == 1).sum()
log.info(f"[Hate] {len(df_hate):,} examples — safe:{n0:,}  harmful:{n1:,}")

user_df = load_user_inputs()
if not user_df.empty:
    hk, sk = load_keywords_from_config()
    user_df = repair_user_label_noise(user_df, hk, sk)
    cat_series = (
        user_df["category"].astype(str).fillna("")
        if "category" in user_df.columns
        else pd.Series("", index=user_df.index)
    )
    user_safe = user_df[user_df.label == 0][["text", "label"]]
    user_harm = user_df[user_df.label == 1]
    user_hate_pos = user_harm[
        ~cat_series[user_harm.index].str.strip().eq("phishing_scam")
    ][["text", "label"]]
    df_hate_ext = pd.concat(
        [
            df_hate[["clean", "label"]].rename(columns={"clean": "text"}),
            user_safe,
            user_hate_pos,
        ],
        ignore_index=True,
    ).drop_duplicates(subset=["text"]).dropna()
    df_hate_ext = balance_dataset(df_hate_ext)
    train_and_save(df_hate_ext["text"], df_hate_ext["label"], "pipeline")
else:
    df_hate_train = balance_dataset(
        df_hate[["clean", "label"]].rename(columns={"clean": "text"})
    )
    train_and_save(df_hate_train["text"], df_hate_train["label"], "pipeline")

sample = pd.concat([
    df_hate[df_hate.label == 0].sample(min(500, n0), random_state=42),
    df_hate[df_hate.label == 1].sample(min(500, n1), random_state=42),
]).sample(frac=1, random_state=42)[["clean", "label"]].rename(columns={"clean": "text"})
sample.to_csv(MODEL_DIR / "dataset.csv", index=False)
log.info("[Hate] Saved -> models/dataset.csv")

log.info("\n" + "=" * 62)
log.info("  MODEL 2: Scam  (scam_pipeline.pkl)")
log.info("=" * 62)

phish_rows = gather_static_scam_frames()

if not user_df.empty:
    cat_series = (
        user_df["category"].astype(str).fillna("")
        if "category" in user_df.columns
        else pd.Series("", index=user_df.index)
    )
    user_safe_scam = user_df[user_df.label == 0][["text", "label"]]
    user_scam_only = user_df[
        (user_df.label == 1) & cat_series.str.strip().eq("phishing_scam")
    ][["text", "label"]]
    phish_rows.append(user_safe_scam)
    if not user_scam_only.empty:
        phish_rows.append(user_scam_only)

if phish_rows:
    df_scam = pd.concat(phish_rows, ignore_index=True).dropna()
    df_scam = df_scam[df_scam["text"].str.len() > 3].drop_duplicates(subset=["text"])
    n0, n1 = (df_scam.label == 0).sum(), (df_scam.label == 1).sum()
    log.info(f"\n[Scam] Combined: {len(df_scam):,}  safe:{n0:,}  harmful:{n1:,}")
    df_scam = balance_dataset(df_scam)
    train_and_save(df_scam["text"], df_scam["label"], "scam_pipeline")
else:
    log.info("\n[Scam] No scam datasets found. Keyword-only detection active.")
    log.info(f"       Place datasets in: {DS_DIR}/")

log.info("\n" + "=" * 62)
log.info("  Training complete!")
log.info("  pipeline.pkl      <- hate model")
log.info("  scam_pipeline.pkl <- scam model")
log.info("  Next: python api.py")
log.info("=" * 62)
