# ApexSocial — Procedura e moderimit të përmbajtjes

Dokumentacion operativ (shkurt). Për **udhëzues teknik të plotë** (gjuhët, TF-IDF, SignalR, API, trajnimi):  
→ **[UDHEZUES_TEKNIK_I_PLOTE.md](./UDHEZUES_TEKNIK_I_PLOTE.md)**

---

## Përmbledhje (1 minutë)

| Pyetje | Përgjigje |
|--------|-----------|
| Sa rezultate finale ka? | **ALLOWED**, **REVIEW**, **FORBIDDEN** (REVIEW = nuk publikohet) |
| A ndalon keyword ML-in? | **Jo** — keyword shton pikë risku (+16 / +25), ML gjithmonë ekzekutohet |
| Sa kohë zgjasin 10 sekondat në browser? | Pritje që të pushosh së shkruari — **jo** kohë e modelit |
| Ku vendoset vendimi final? | Python `analyze()` — kombinim ML% + keyword, pragu **52%** |
| Çfarë duhet ndezur? | XAMPP (PHP+MySQL), `python api.py` (:5000); C# (:8080) vetëm për SignalR |

---

## Arkitektura

```text
Përdoruesi (browser)
        │
        ▼  JavaScript — app.js (countdown 10s, AJAX)
        │
        ▼  PHP — ajax.php / index.php / config.php
        │
        ▼  HTTP POST → http://127.0.0.1:5000/analyze
        │
        ▼  Python — api.py + text_utils.py
        │      • Keywords (heuristic)
        │      • clean(text)
        │      • sklearn (TF-IDF + Logistic Regression)
        │      • Kombinim + pragu 52%
        │
        ▼  JSON → PHP → UI / MySQL
```

| Shtresa | Teknologji | Skedarë kryesorë |
|---------|------------|------------------|
| UI | HTML, CSS, JavaScript | `assets/js/app.js`, `index.php` |
| Aplikacioni | PHP 8+ (XAMPP) | `includes/config.php`, `includes/ajax.php` |
| Baza e të dhënave | MySQL | `database.sql`, tabela `posts`, `content_analysis` |
| Moderimi AI | Python 3, Flask, sklearn | `ml_api/api.py`, `ml_api/text_utils.py` |
| Real-time (opsional) | C# ASP.NET Core 8, SignalR | `Backend/Program.cs` (:8080) |

---

## Procedura A — Parakontroll në browser (para Post)

**Skedari:** `assets/js/app.js`  
**Kur:** Vetëm në faqen me formular postimi (`#post-content`).

| Hapi | Funksioni / event | Çfarë ndodh |
|------|-------------------|-------------|
| 1 | `input` në textarea | Çdo shkronjë e re |
| 2 | — | Butoni **Post** çaktivizohet |
| 3 | `startCountdown()` | Fillon numërimi **10 sekonda** (`COUNTDOWN_SEC = 10`) |
| 4 | Nëse vazhdon të shkruash | Timer **reset** — fillon përsëri nga 10 |
| 5 | Pas 10s pa tastierë | `runAnalysis()` |
| 6 | `fetch` POST | `includes/ajax.php`, `action=moderate_content`, `text=...` |
| 7 | Përgjigja | `status`: ALLOWED / FORBIDDEN / OFFLINE |
| 8 | `setML(...)` | UI: kutia jeshile/kuqe; Post aktiv vetëm në ALLOWED |
| 9 | Submit `#post-form` | Bllokon nëse status ≠ ALLOWED |

> **Shënim:** Kjo është parashikim për përdoruesin. Vendimi përfundimtar për ruajtje bëhet përsëri në server kur klikon Post.

---

## Procedura B — PHP: AJAX → Python

### B.1 `includes/ajax.php`

| Hapi | Kodi |
|------|------|
| 1 | Kontroll login + ban |
| 2 | `if ($action === 'moderate_content')` |
| 3 | `moderateContent($text, $userId, 'post')` |
| 4 | Kthen JSON: `status`, `reason`, `harmful_prob`, `category` |

### B.2 `includes/config.php`

#### `moderateContent($text, $userId, $type)`

| Kushti | Rezultat |
|--------|----------|
| Tekst &lt; 3 karaktere (pas trim) | **ALLOWED** lokal — Python nuk thirret |
| Python offline | **FORBIDDEN** (fail-closed) + mesazh offline |
| Përndryshe | Kthen përgjigjen e Python siç është |

#### `mlAnalyze($text, ...)`

- **URL:** `ML_API_URL` + `/analyze` → `http://127.0.0.1:5000/analyze`
- **Metoda:** POST JSON `{ "text", "user_id", "type" }`
- **Timeout:** 10s (connect 4s)

#### `analyzeContent(...)`

- Përdoret për **komente** dhe **repost** në `ajax.php`
- Shton `label` (0/1), `safe`, `confidence` për MySQL

---

## Procedura C — Python: `analyze(text)` (zemra e sistemit)

**Skedari:** `ml_api/api.py`  
**Hyrje HTTP:** `route_analyze()` → `analyze(text)` → `jsonify(result)`

### C.0 — Ngarkimi në start

| Funksioni | Çfarë bën |
|-----------|-----------|
| `_load_models()` | Ngarkon `models/pipeline.pkl` dhe `models/scam_pipeline.pkl` në memorie |
| Lexon `models/config.json` | Keywords, threshold, boost 16/25 |

### C.1 — Tekst shumë i shkurtër

```text
Nëse len(text) < 2  →  ALLOWED (score 0), fund
```

### C.2 — Shtresa keyword (heuristic — NUK ndalon)

**Funksione:** `_keyword_signal()` → `keyword_match()` + `normalize_keywords()` në `text_utils.py`

| Lista | Burimi në config.json | Kategoria |
|-------|----------------------|-----------|
| Hate | `keywords` | `hate_speech` |
| Scam | `scam_keywords` | `phishing_scam` |

**Si kërkon:**

1. Tekst origjinal (lowercase)
2. Tekst i normalizuar (leetspeak: `0→o`, `!→i`, `@→a`, …)
3. Përputhje fjalë të vetme (`\bfjalë\b`) ose frazë

**Pesha (shtesë në score, jo vendim):**

| Lloji | `keyword_weight` |
|-------|------------------|
| 1 fjalë në listë | **+16** |
| Fraza 2+ fjalë | **+25** |
| Nuk u gjet | **+0** |

**Ruan (shembull):**

```json
{
  "keyword_detected": true,
  "keyword_type": "hate_speech",
  "keyword_weight": 25,
  "keyword": "kill yourself"
}
```

> Pas këtij hapi sistemi **vazhdon** — nuk kthehet FORBIDDEN këtu.

### C.3 — Preprocessing

**Funksioni:** `clean(text)` — `ml_api/text_utils.py`

| Veprim | Shembull |
|--------|----------|
| Lowercase | `Hello` → `hello` |
| URL → ` url ` | |
| @user → ` user ` | |
| Heq shenja të panevojshme | |
| Një hapësirë | |

```python
ml_input = cleaned  # nëse len(cleaned) >= 3, përndryshe text.lower()
```

### C.4 — Machine Learning (gjithmonë)

**Funksioni:** `_harmful_probability(text, pipeline)`

| Modeli | Skedar | Tema |
|--------|--------|------|
| Hate | `models/pipeline.pkl` | Urrejtje / ngacmim |
| Scam | `models/scam_pipeline.pkl` | Phishing / spam |

**Brenda çdo `.pkl` (sklearn Pipeline):**

```text
Teksti
   → TF-IDF (fjalët → numra / vektor)
   → Logistic Regression (P(harmful) nga 0.0 deri 1.0)
```

```python
hate_ml_pct = hate_prob * 100   # p.sh. 70.5
scam_ml_pct = scam_prob * 100   # p.sh. 19.6
```

### C.5 — Kombinim hibrid

```text
hate_combined  = min(100, hate_ml_pct  + hate_keyword_weight)
scam_combined  = min(100, scam_ml_pct  + scam_keyword_weight)

combined_score = max(hate_combined, scam_combined)
best_category    = hate_speech ose phishing_scam (kush ka score më të lartë)
method           = "hybrid" nëse pati keyword, përndryshe "sklearn"
```

### C.6 — Vendimi final

| Kushti | Rezultat |
|--------|----------|
| `combined_score >= 52` | **FORBIDDEN** (`_forbidden`) |
| `combined_score < 52` | **ALLOWED** (`_allowed`) |

Pragu **52%** vjen nga `threshold_low` / `threshold` në `config.json` (default 0.52).

### C.7 — Pas analizës (log)

| Funksioni | Skedar |
|-----------|--------|
| `_append_analysis_log` | `models/analysis_log.json` |
| `_append_training_input` | `models/user_inputs.jsonl` |

---

## Fushat e përgjigjes JSON (API)

| Fusha | Kuptimi |
|-------|---------|
| `verdict` | `ALLOWED` \| `FORBIDDEN` |
| `category` | `safe`, `hate_speech`, `phishing_scam` |
| `harmful_prob` | Score i kombinuar (0–100) |
| `method` | `hybrid`, `sklearn`, `empty` |
| `reason` | Tekst për përdoruesin |
| `keyword_detected` | true / false |
| `keyword_type` | Kategoria e keyword-it dominues |
| `keyword_weight` | 0, 16, ose 25 |
| `ml_hate_pct` | Vetëm ML hate % |
| `ml_scam_pct` | Vetëm ML scam % |
| `combined_score` | = harmful_prob |

---

## Shembuj numerikë

### Tekst i pastër

**Input:** `Hello everyone, have a nice day`

| Hate keyword | Scam keyword | hate_ml | scam_ml | combined | Rezultat |
|--------------|--------------|---------|---------|----------|----------|
| 0 | 0 | ~25% | ~12% | ~25% | **ALLOWED** |

### Fraza e rrezikshme

**Input:** `kill yourself`

| Hate keyword | hate_ml | Llogaritja | Rezultat |
|--------------|---------|------------|----------|
| +25 | ~71% | 71 + 25 = **96%** | **FORBIDDEN** |

### Keyword + ML i ulët

**Input:** (fjalë e vetme në listë, ML ~18%)

| Keyword | ML | Kombinuar | Rezultat |
|---------|-----|-----------|----------|
| +16 | 18% | **34%** | **ALLOWED** (&lt; 52%) |

---

## Procedura D — Postimi real (`index.php`)

| Hapi | Çfarë ndodh |
|------|------------|
| 1 | Përdoruesi klikon **Post** |
| 2 | `moderateContent($content, ...)` — **e njëjta pipeline** si AJAX |
| 3 | Nëse OFFLINE ose FORBIDDEN | Mesazh gabimi, **nuk ruhet** |
| 4 | Nëse ALLOWED | `INSERT INTO posts`, `logAnalysis()` në MySQL |

**Pse 2 herë?** Siguri: kontrolli në browser mund të anashkalohet; serveri vendos përfundimisht.

---

## Procedura E — Komente & repost

| Veprim | Skedar | Funksioni PHP |
|--------|--------|---------------|
| Koment | `ajax.php` → `add_comment` | `analyzeContent()` |
| Repost | `ajax.php` → `repost` | `analyzeContent()` |

E njëjta rrugë: PHP → Python `analyze()` → ALLOWED/FORBIDDEN.

---

## Procedura F — Trajnimi i modeleve (offline)

**Skedari:** `ml_api/train_model.py`  
**Komanda:**

```bash
cd ml_api
python train_model.py              # trajnim i plotë
python train_model.py --incremental  # përditësim me user_inputs.jsonl
```

| Hapi | Funksioni | Çfarë bën |
|------|-----------|-----------|
| 1 | `read_file()` | Lexon CSV nga `models/datasets/` |
| 2 | `clean()` | I njëjti preprocessing |
| 3 | `build_pipeline()` | TF-IDF + LogisticRegression |
| 4 | `train_and_save()` | Fit → ruan `.pkl` |
| 5 | `load_user_inputs()` | Të dhëna nga postimet reale |

**Pas trajnimit:** restart `python api.py` ose `POST /reload_models`.

> Trajnimi **nuk** ndodh kur përdoruesi poston — vetëm kur ti e ekzekuton skriptin.

---

## Endpoint-e Python

| Route | Metoda | Përshkrim |
|-------|--------|-----------|
| `/analyze` | POST | Analizë një teksti (kryesor) |
| `/analyze_batch` | POST | Shumë tekste (admin scan) |
| `/health` | GET | A është API online |
| `/stats` | GET | Statistika nga log |
| `/retrain` | POST | Nis trajnim incremental në background |
| `/reload_models` | POST | Ringarkon `.pkl` |
| `/test` | POST | Test i shpejtë |

---

## Diagram i rrjedhës (hybrid)

```text
                    TEKSTI
                      │
         ┌────────────┴────────────┐
         ▼                         ▼
   Keywords HATE              Keywords SCAM
   (sinjal +0/16/25)          (sinjal +0/16/25)
         │                         │
         └────────────┬────────────┘
                      ▼
                 clean(text)
                      │
         ┌────────────┴────────────┐
         ▼                         ▼
   hate_pipeline.pkl         scam_pipeline.pkl
   → hate_ml_pct             → scam_ml_pct
         │                         │
         └────────────┬────────────┘
                      ▼
         combined = ML% + keyword_weight
         (maks. 100 për kategori)
                      │
                      ▼
              combined_score >= 52% ?
                 /              \
           FORBIDDEN          ALLOWED
```

---

## Pyetje të shpeshta

**Nëse keyword detektohet, a shkon te ML?**  
→ **Po, gjithmonë.**

**Çfarë nëse Python nuk punon?**  
→ PHP: offline → postimi **bllokohet**.

**Ku ndryshoj 10 sekondat?**  
→ `assets/js/app.js` → `COUNTDOWN_SEC = 10`

**Ku ndryshoj pragun 52%?**  
→ `ml_api/models/config.json` → `threshold` ose `threshold_low`

**Ku ndryshoj +16 / +25 për keyword?**  
→ `config.json` → `keyword_boost_weak`, `keyword_boost_strong`

---

## Si ta nisësh sistemin

| Shërbim | Komanda | URL |
|---------|---------|-----|
| XAMPP | Apache + MySQL Start | `http://localhost/apexsocial` |
| ML API | `cd ml_api` → `python api.py` | `http://127.0.0.1:5000` |
| C# (opsional) | `cd Backend` → `dotnet run` | `http://127.0.0.1:8080` |

Kontroll shëndeti ML: `http://127.0.0.1:5000/health`

---

*Dokumenti përputhet me pipeline-in hibrid në `api.py` (keywords + ML, pa ndalim të menjëhershëm në keyword).*
