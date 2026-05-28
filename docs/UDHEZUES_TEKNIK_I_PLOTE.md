# ApexSocial — Udhëzues teknik i plotë (ML, moderim, SignalR)

**Audienca:** lexues me bazë në informatikë / web / ML (dinë HTTP, JSON, klasifikim), por jo ekspert në çdo detaj.  
**Qëllimi:** një burim i vetëm me **gjuhët**, **teknologjitë**, **protokollet**, **algoritmet**, **skedarët** dhe **rrjedhat** — pa lënë boshllëqe.

Dokumenti përshkran gjendjen e kodit **v5.0** (dual threshold 52%/72%, REVIEW, URL boost, negacion keywords, `balance_dataset`).

---

## Spishti i përmbajtjes

1. [Inventari i gjuhëve dhe runtime-ve](#1-inventari-i-gjuhëve-dhe-runtime-ve)
2. [Dy subsisteme të ndara](#2-dy-subsisteme-të-ndara)
3. [Rrjeti: porte, protokolle, URL](#3-rrjeti-porte-protokolle-url)
4. [Moderimi: rruga byte-pas-byte](#4-moderimi-rruga-byte-pas-byte)
5. [API Python Flask — kontratat JSON](#5-api-python-flask--kontratat-json)
6. [Funksioni `analyze()` — specifikim i plotë](#6-funksioni-analyze--specifikim-i-plotë)
7. [`text_utils.py` — algoritme heuristike](#7-text_utilspy--algoritme-heuristike)
8. [Machine learning — teori dhe implementim](#8-machine-learning--teori-dhe-implementim)
9. [Trajnimi — `train_model.py`](#9-trajnimi--train_modelpy)
10. [Skedarët e modeleve dhe konfigurimi](#10-skedarët-e-modeleve-dhe-konfigurimi)
11. [PHP — integrimi dhe politika e sigurisë](#11-php--integrimi-dhe-politika-e-sigurisë)
12. [SignalR dhe WebSocket — real-time](#12-signalr-dhe-websocket--real-time)
13. [MySQL — çfarë ruhet pas moderimit](#13-mysql--çfarë-ruhet-pas-moderimit)
14. [Vendime: pemë vendimi dhe verdictet](#14-vendime-pemë-vendimi-dhe-verdictet)
15. [Shembull i plotë numerik](#15-shembull-i-plotë-numerik)
16. [Komanda operative dhe debug](#16-komanda-operative-dhe-debug)
17. [Fjalor](#17-fjalor)

---

## 1. Inventari i gjuhëve dhe runtime-ve

| Shtresa | Gjuha / format | Runtime / motor | Version i synuar (projekti) |
|---------|----------------|-----------------|-----------------------------|
| Shfletues | **JavaScript** (ES6+, IIFE, `async/await`) | V8 (Chrome/Edge/Firefox) | — |
| Markup / stil | **HTML5**, **CSS3** | Browser | — |
| Aplikacion web | **PHP** | Zend/engine në **Apache** (XAMPP) | PHP 8+ |
| Baza e të dhënave | **SQL** (MySQL dialect) | **MySQL** / MariaDB | utf8mb4 |
| Moderim AI | **Python 3** | CPython | 3.10+ rekomandohet |
| ML bibliotekë | Python (sklearn API) | **scikit-learn** ≥1.3 | TF-IDF + LR + calibration |
| Shërbim ML HTTP | **Python** | **Flask** ≥2.3 + **Waitress** (prod) | WSGI |
| Backend real-time | **C#** 11 | **.NET 8** (`net8.0`) | ASP.NET Core minimal hosting |
| Real-time klient | **JavaScript** | `@microsoft/signalr` **8.0.0** (CDN) | mbi WebSocket/long-poll |
| Konfigurim ML | **JSON** | — | `models/config.json` |
| Dataset trajnim | **CSV**, **JSONL** | pandas | UTF-8 |
| Modele të trajnuara | **pickle** (binary, Python-specific) | `pickle.load` në `api.py` | `.pkl` |
| Varësi Python | **text** | pip | `ml_api/requirements.txt` |
| Varësi .NET | **XML** (csproj) | NuGet | `Backend/ApexSocial.csproj` |

**Gjuha dominante për moderim të tekstit:** Python (`ml_api/`).  
**Gjuha dominante për UI dhe CRUD social:** PHP.  
**Gjuha për push notifications:** C# (SignalR hub).

---

## 2. Dy subsisteme të ndara

### 2.1 Subsistemi A — Moderim i përmbajtjes (detyrues për siguri)

| Aspekt | Vlerë |
|--------|-------|
| Qëllimi | Klasifikim teksti: safe vs harmful (hate / scam) |
| Protokolli | **HTTP/1.1** request–response |
| Serveri | Flask në `127.0.0.1:5000` |
| Klientët | PHP (`curl`), opsionalisht C# (`HttpClient`) |
| **NUK përdor** | SignalR, WebSocket, Socket.IO për analizë |

### 2.2 Subsistemi B — Real-time (opsional)

| Aspekt | Vlerë |
|--------|-------|
| Qëllimi | Njoftime, queue admin, ban push, refresh feed |
| Protokolli | **SignalR** (transport: **WebSocket** preferuar) |
| Serveri | Kestrel `0.0.0.0:8080`, hub `/hub` |
| Klienti | `assets/js/app.js` (bllo i dytë IIFE) |

**Konfuzion i zakonshëm:** nëse C# (:8080) është offline, **moderimi mund të funksionojë**; thjesht nuk ka toast/badge live.

---

## 3. Rrjeti: porte, protokolle, URL

| Shërbim | URL bazë | Metoda kryesore |
|---------|----------|-----------------|
| Faqja sociale | `http://localhost/apexsocial/` | GET/POST (PHP) |
| AJAX PHP | `.../includes/ajax.php` | POST (`action=...`) |
| ML API | `http://127.0.0.1:5000` | POST `/analyze`, GET `/health`, … |
| C# backend | `http://127.0.0.1:8080` | REST + SignalR `/hub` |

**Konstante PHP** (`includes/config.php`):

```php
define('ML_API_URL', 'http://127.0.0.1:5000');
define('BACKEND_URL', 'http://127.0.0.1:8080');
```

**Konstante JS** (`includes/navbar.php`):

```javascript
window.APEX_HUB_URL = 'http://127.0.0.1:8080/hub';
```

### 3.1 HTTP moderim (PHP → Python)

**Kërkesa:**

```http
POST /analyze HTTP/1.1
Host: 127.0.0.1:5000
Content-Type: application/json

{"text":"...", "user_id":5, "type":"post"}
```

**Përgjigja (sukses):** `200 OK`, body JSON (shiko seksionin 5).

**Timeout PHP:** `CURLOPT_TIMEOUT => 10`, `CONNECTTIMEOUT => 4`.

---

## 4. Moderimi: rruga byte-pas-byte

### 4.1 Faza paraprake në browser

| # | Ku | Gjuha | Çfarë ndodh |
|---|-----|--------|-------------|
| 1 | `index.php` | HTML/PHP | Render formën `#post-form`, `#post-content` |
| 2 | `navbar.php` | PHP + JS | Ngarkon `app.js`, `APEX_USER`, SignalR CDN |
| 3 | `app.js` IIFE #1 | JavaScript | `input` → `startCountdown()` → 10s → `runAnalysis()` |
| 4 | `runAnalysis` | JavaScript | `FormData`: `action=moderate_content`, `text` |
| 5 | `fetch(API)` | HTTP POST | Drejt `includes/ajax.php` (multipart/form) |
| 6 | UI | JavaScript | `setML('ALLOWED'|'FORBIDDEN', ...)`; `btn.disabled` |

**Parametër:** `COUNTDOWN_SEC = 10` — debounce, jo latencë ML.

### 4.2 Faza server PHP (parakontroll AJAX)

| # | Skedar | Funksion |
|---|--------|----------|
| 1 | `ajax.php` | Auth, ban check |
| 2 | `moderateContent()` | `config.php` |
| 3 | `mlAnalyze()` | `curl` → `:5000/analyze` |
| 4 | JSON përgjigje | `status` = verdict i uppercase |

### 4.3 Faza submit post (kontroll përfundimtar)

| # | Skedar | Shënim |
|---|--------|--------|
| 1 | `index.php` POST | **Ripërsërit** `moderateContent()` — nuk besohet vetëm preview JS |
| 2 | `mlVerdictBlocks()` | `FORBIDDEN` dhe `REVIEW` → redirect, nuk `INSERT` |
| 3 | `logAnalysis()` | Ruan snapshot në `content_analysis` |

---

## 5. API Python Flask — kontratat JSON

### 5.1 `POST /analyze`

**Request body:**

| Fushë | Tipi | Detyrues | Përshkrim |
|-------|------|---------|-----------|
| `text` | string | po | Teksti për analizë |
| `user_id` | int | jo | Për log (`analysis_log.json`) |
| `type` | string | jo | `post`, `comment`, … |

**Response 200 — fushat kryesore:**

| Fushë | Tipi | Shembull | Kuptimi |
|-------|------|----------|---------|
| `verdict` | string | `ALLOWED`, `REVIEW`, `FORBIDDEN` | Vendimi final |
| `category` | string | `safe`, `hate_speech`, `phishing_scam` | Klasa dominante |
| `harmful_prob` | float | `67.7` | Përqindje e shfaqur (0–100) |
| `method` | string | `sklearn`, `hybrid` | A u përdor keyword boost |
| `reason` | string | tekst | Arsye për UI |
| `confidence` | string | `low`, `medium`, `high` | Bazuar në `harmful_prob/100` |
| `combined_score` | float | `62.1` | Score i papërpunuar për vendim |
| `ml_hate_pct` | float | `56.6` | ML hate pas kontekstit |
| `ml_scam_pct` | float | `30.1` | ML scam |
| `keyword_detected` | bool | | |
| `keyword_weight` | float | `0`, `16`, `25` | |
| `urls_detected` | string[] | | URL të nxjerra nga teksti origjinal |
| `url_boost` | float | `32.0` | Shtesa scam % |
| `context_adjusted` | bool | | A u aplikua `adjust_ml_probs` |
| `context_notes` | string[] | `negated_hate` | |

**Anë:** çdo analizë shton rresht në `analysis_log.json`; vetëm `FORBIDDEN` në `user_inputs.jsonl`.

### 5.2 Endpoint-e të tjera

| Rrugë | Metoda | Qëllimi |
|-------|--------|---------|
| `/health` | GET | A janë ngarkuar `.pkl`, numër keywords, threshold |
| `/analyze_batch` | POST | Deri 200 tekste në një kërkesë |
| `/reload_models` | POST | Riload `pipeline.pkl` pa rinisje procesi |
| `/retrain` | POST | Nis `train_model.py --incremental` në thread |
| `/stats` | GET | Statistika nga `analysis_log.json` |
| `/test` | POST | Batch test për admin |

---

## 6. Funksioni `analyze()` — specifikim i plotë

**Skedari:** `ml_api/api.py`  
**Hyrja:** `text: str`  
**Dalja:** `dict` (përmes `_allowed`, `_forbidden`, `_review`)

### 6.1 Konstante nga `config.json` (ngarkohen në startup)

| Konstante Python | Burimi JSON | Vlera tipike |
|------------------|-------------|--------------|
| `THRESH` | `threshold_low` / `threshold` | `0.52` |
| `THRESH_HIGH` | `threshold_high` | `0.72` |
| `THRESH_PCT` | | `52.0` |
| `THRESH_HIGH_PCT` | | `72.0` |
| `KW_HATE` | `keywords` | listë ~75+ stringje |
| `KW_SCAM` | `scam_keywords` | listë ~100+ |
| `KW_BOOST_WEAK` | `keyword_boost_weak` | `16` |
| `KW_BOOST_STRONG` | `keyword_boost_strong` | `25` |

### 6.2 Renditja e saktë e hapave (si në kod)

```
1. text.strip(); nëse len<2 → ALLOWED (empty)

2. hate_kw = _keyword_signal(text, KW_HATE, "hate_speech")
   scam_kw = _keyword_signal(text, KW_SCAM, "phishing_scam")
   // lexon tekstin ORIGJINAL (para clean)

3. cleaned = clean(text)
   ml_input = cleaned if len(cleaned)>=3 else text.lower()

4. Nën _model_lock:
   hate_prob = _harmful_probability(ml_input, hate_pipeline)  // 0..1
   scam_prob = _harmful_probability(ml_input, scam_pipeline)

5. hate_prob, scam_prob, context_notes = adjust_ml_probs(text, hate_prob, scam_prob)
   // përsëri teksti ORIGJINAL për negacion / safe phrases

6. hate_ml_pct = round(hate_prob * 100, 1)
   scam_ml_pct = round(scam_prob * 100, 1)

7. url_boost, url_notes = url_scam_boost(text)
   detected_urls = find_urls(text)

8. hate_combined = min(100, hate_ml_pct + hate_kw.keyword_weight)
   scam_combined = min(100, scam_ml_pct + scam_kw.keyword_weight + url_boost)

9. Nëse url_boost>=28 dhe (suspicious_tld ose scam_context+url_present):
   scam_combined = max(scam_combined, THRESH_HIGH_PCT)

10. Zgjidh dominant: max(hate_combined, scam_combined) → best_cat, combined_score

11. method = "hybrid" nëse keyword_detected, përndryshe "sklearn"
    harmful_prob = _display_harmful_prob(...)  // sharpening vetëm për sklearn display

12. Vendimi:
    - combined < 52% → ALLOWED
    - combined >= 52% dhe keyword → FORBIDDEN
    - combined >= 72% → FORBIDDEN
    - përndryshe → REVIEW
```

### 6.3 `_harmful_probability`

- Thërret `pipeline.predict_proba([text])`
- Gjen indeksin e klasës `1` (harmful) në `clf.classes_`
- Kthen `float` në [0, 1]
- Nëse pipeline `None` → `0.0`

### 6.4 `_confidence(prob_0_1)`

| Interval prob (0–1) | confidence |
|---------------------|------------|
| [0.35, 0.65] | low |
| [0.25, 0.35) ose (0.65, 0.75] | medium |
| tjetër | high |

### 6.5 `_sigmoid_sharpen` (vetëm display kur `method==sklearn`)

- Qendër në `THRESH` (0.52), `k=16`
- Kompreson prob < 0.3, zgjeron > 0.7
- **Nuk ndryshon** `combined_score` për vendim — vetëm `harmful_prob` i raportuar

---

## 7. `text_utils.py` — algoritme heuristike

### 7.1 `clean(text) -> str`

**Gjuha:** Python, modul `re`.

| Hapi | Regex / veprim | Shembull |
|------|----------------|----------|
| lower | `.lower()` | |
| URL | `_URL_RE.sub(" url ", t)` | `www.a.xy/p` → `url` |
| mention | `@\w+` → ` user ` | |
| RT | `rt\s+` hequr | |
| karaktere | mbeten `[a-z0-9\s!?.,'\"£$€&@#/:-]` | |
| hapësira | `\s+` → një hapësirë | |

### 7.2 `normalize_keywords(text)`

- Zëvendësim leet: `@→a`, `0→o`, `3→e`, …
- `don't` → `dont` (para split)
- `[\W_]+` → hapësirë

### 7.3 `keyword_match(text, kw_list) -> (bool, str|None)`

- Dy kanale: `text.lower()` dhe `normalize_keywords(text)`
- Fjalë e vetme: `\bword\b` + kontroll negacioni 3 tokenë para
- Frazë: `str.find` + negacion në pozicionin e fillimit

**Negacion:** `not`, `no`, `never`, `don't`/`dont`, `doesn't`, `can't`, `won't`, `didn't`

### 7.4 `find_urls` / `url_scam_boost`

**Regex `_URL_RE` kap:**

- `https?://...`, `hxxps?://...`, `www....`
- domene `.xy`, `.tk`, `.xyz`, … (listë TLD në regex)
- `domain.tld/path` me path ≥3 karaktere

**`url_scam_boost`:**

| Kusht | Boost (max 35) |
|-------|----------------|
| URL ekziston | ≥20 |
| + regex konteksti scam | ≥28 |
| + TLD i listuar si suspicious | ≥32 |

**Kontekst scam (_SCAM_URL_CONTEXT_RE):** gift, register, claim, prize, verify, bitcoin, `$digits`, etj.

### 7.5 `adjust_ml_probs`

| Rregull | Multiplikator hate_prob |
|---------|-------------------------|
| token `hate` i neguar | ×0.22 |
| safe hate phrase (regex listë) | ×0.30 (shtesë) |

---

## 8. Machine learning — teori dhe implementim

### 8.1 Lloji i problemit

- **Klasifikim binar supervizuar**
- Klasat: `0` = safe, `1` = harmful
- Dy probleme të ndara: hate vs scam (dy modele)

### 8.2 Pipeline sklearn (i ruajtur në `.pkl`)

```text
Input: string (tekst i pastruar)
  → TfidfVectorizer
  → CalibratedClassifierCV(LogisticRegression)
Output: predict_proba[:,1] ≈ P(harmful | text)
```

### 8.3 TfidfVectorizer — parametrat (`train_model.py`)

| Parametër | Vlerë | Kuptimi teknik |
|-----------|-------|----------------|
| `max_features` | 25000 | Dimensioni maksimal i vektorit |
| `ngram_range` | (1, 3) | Unigram, bigram, trigram |
| `sublinear_tf` | True | TF ≈ 1 + log(tf) — ul peshën e përsëritjeve të shumta |
| `min_df` | 1 ose 2 | Fjalët që shfaqen në < min_df dokumente hidhen (n≥8000 → 2) |
| `max_df` | 1.0 ose 0.92 | Fjalët në >92% dokumenteve hidhen (korpus i madh) |
| `strip_accents` | unicode | Normalizim theksesh |
| `token_pattern` | `(?u)\b[a-z][a-z0-9'#]{1,}\b` | Tokenizim për social text |
| `lowercase` | True | |

**Formula TF-IDF (koncept):**

- \( \mathrm{tf}(t,d) \) — frekuenca e termit \(t\) në dokumentin \(d\)
- \( \mathrm{idf}(t) = \log \frac{N}{df(t)} \) — \(N\) = numri i dokumenteve, \(df\) = sa dokumente përmbajnë \(t\)
- \( \mathrm{tfidf}(t,d) = \mathrm{tf}(t,d) \times \mathrm{idf}(t) \)

### 8.4 LogisticRegression — parametrat

| Parametër | Vlerë |
|-----------|-------|
| `C` | 0.8 |
| `solver` | saga |
| `max_iter` | 4000 |
| `class_weight` | balanced |
| `random_state` | 42 |

**Decision function:** \( p = \sigma(\mathbf{w}^\top \mathbf{x} + b) \) ku \( \mathbf{x} \) është vektori TF-IDF sparse.

### 8.5 CalibratedClassifierCV

| Parametër | Vlerë |
|-----------|-------|
| `method` | sigmoid (Platt scaling) |
| `cv` | 2, 3, ose 5 fold (varësisht nga n_samples) |

**Qëllimi:** `predict_proba` të jetë më i kalibruar për pragu 0.52 / 0.72.

### 8.6 Pse jo deep learning?

| Kriter | TF-IDF + LR | Transformer (BERT) |
|--------|-------------|---------------------|
| CPU / RAM | I ulët | I lartë |
| Kohë trajnimi | Minuta | Orë + GPU |
| Interpretueshmëri | Pesha për terme | E vështirë |
| Sasia tipike e të dhënave | 10³–10⁵ rreshta | Më mirë 10⁶+ |

Për ApexSocial (XAMPP lokal, dataset mesatar), zgjedhja është **e arsyeshme inxhinierike**.

---

## 9. Trajnimi — `train_model.py`

### 9.1 Mënyrat

| Komanda | Flamuri | Përshkrim |
|---------|---------|-----------|
| `python train_model.py` | — | Trajnim i plotë hate + scam |
| `python train_model.py --incremental` | `INCREMENTAL=True` | Ripërdor `dataset.csv` + `user_inputs.jsonl` |

### 9.2 Burimet e të dhënave

**Hate:**

| Skedar | Kolona | Label |
|--------|--------|-------|
| `models/labeled_data.csv` | `tweet`, `class` | `class==2` → 0, else → 1 |

**Scam:**

| Skedar (në `models/` ose `models/datasets/`) | Detektim kolone |
|---------------------------------------------|-----------------|
| `scam.csv` | message/text + ham/spam |
| `malicious_phish.csv` | url + benign/phishing |
| `PhiUSIIL_*.csv` | url + label 0/1 |

### 9.3 `balance_dataset(df, max_ratio=3.0)`

- Nëse `maj_count / min_count > 3`, undersample shumicën me `sklearn.utils.resample(..., random_state=42)`
- Synimi: raport **saktësisht** 3:1
- **Arsye:** incremental me shumë raporte FORBIDDEN të bënte modelin të parashikonte gjithmonë harmful

### 9.4 `train_and_save(X, y, name)`

1. `build_pipeline(len(X))`
2. `train_test_split(..., test_size=0.2, stratify=y, random_state=42)`
3. `pipe.fit(X_train, y_train)`
4. Metrika: accuracy, precision, recall, F1, `classification_report`
5. `pickle.dump` → `models/{name}.pkl` (`pipeline` ose `scam_pipeline`)

### 9.5 Pas trajnimit full

- Krijon `models/dataset.csv` — 500 safe + 500 harmful (mostër) për incremental hate

---

## 10. Skedarët e modeleve dhe konfigurimi

### 10.1 `models/config.json`

| Çelës | Tipi | Roli |
|-------|------|------|
| `threshold` / `threshold_low` | float | Pragu i ulët (0.52) |
| `threshold_high` | float | Pragu i lartë (0.72) |
| `keywords` | string[] | Lista hate |
| `scam_keywords` | string[] | Lista scam |
| `keyword_boost_weak` | float | 16 |
| `keyword_boost_strong` | float | 25 |

### 10.2 Artefakte

| Skedar | Format | Përmbajtje |
|--------|--------|------------|
| `pipeline.pkl` | pickle | sklearn Pipeline hate |
| `scam_pipeline.pkl` | pickle | sklearn Pipeline scam |
| `analysis_log.json` | JSON array | Deri 5000 analiza të fundit |
| `user_inputs.jsonl` | JSON lines | Tekst + label për retrain |
| `dataset.csv` | CSV | `text`, `label` për incremental hate |

**Kujdes:** `.pkl` është i lidhur me versionin e sklearn — ri-trajno pas upgrade të madh të sklearn.

---

## 11. PHP — integrimi dhe politika e sigurisë

### 11.1 Funksionet (`includes/config.php`)

| Funksion | Roli |
|----------|------|
| `mlAnalyze($text, $userId, $type)` | HTTP POST `/analyze`, kthen array |
| `moderateContent(...)` | Wrapper; offline → FORBIDDEN |
| `mlVerdictBlocks($verdict)` | `FORBIDDEN` ose `REVIEW` → true |
| `analyzeContent(...)` | Për komente: `label`, `safe`, etj. |

### 11.2 Politika offline (fail-closed)

Nëse `curl` dështon ose nuk ka `verdict`:

```php
'verdict' => 'FORBIDDEN', 'offline' => true
```

**Arsye:** platformë sociale — më mirë të mos postohet pa motor moderimi.

### 11.3 Skedarët që thërrasin moderimin

| Skedar | Veprim |
|--------|--------|
| `ajax.php` | `moderate_content`, `add_comment`, repost |
| `index.php` | Krijim post |

### 11.4 JavaScript vs server

Preview JS **nuk** zëvendëson kontrollin server — `submit` bllokohet nëse `status !== 'ALLOWED'`, por serveri analizon përsëri.

---

## 12. SignalR dhe WebSocket — real-time

### 12.1 Stack

| Pjesa | Teknologji |
|-------|------------|
| Server hub | C# `ApexHub : Hub` |
| Host | ASP.NET Core 8 Kestrel |
| Klient | `@microsoft/signalr` 8.0.0 (CDN) |
| Transport | WebSocket (fallback: long polling) |

### 12.2 Metoda hub

```csharp
public async Task Join(int userId, bool isAdmin)
```

- Shton lidhjen në grup `user_{userId}`
- Nëse admin → edhe grupi `admins`
- Kthen event `Joined` te klienti

### 12.3 Evente server → klient

| Event | Marrësi tipik | Veprim JS |
|-------|---------------|-----------|
| `Notification` | `user_{id}` | Toast + badge |
| `NewPending` | admins | Link queue |
| `QueueUpdate` | admins | Ul badge |
| `ModerationResult` | user | `handleModerationResult` |
| `Banned` | user | Redirect `banned.php` |

### 12.4 Lidhja me ML

C# `CallML()` në `Program.cs` thërret të njëjtin `/analyze` për disa rrugë API — **rruga kryesore e posteve në PHP është direkt PHP→Python**, jo PHP→C#→Python.

**Headers C# (shembull):** `X-Api-Key`, `X-User-Id`, `X-Is-Admin` për rrugë të mbrojtura.

---

## 13. MySQL — çfarë ruhet pas moderimit

### 13.1 `posts` / `comments`

| Kolonë | Tipi | Kuptimi |
|--------|------|---------|
| `ml_label` | TINYINT | 0 safe, 1 blocked (FORBIDDEN/REVIEW në analizë) |
| `ml_prob` | FLOAT | `harmful_prob` nga API |
| `ml_category` | VARCHAR(30) | `hate_speech`, `phishing_scam`, `safe` |
| `ml_method` | VARCHAR(20) | `sklearn`, `hybrid`, … |

### 13.2 `content_analysis`

Audit log: `text_snapshot`, `label`, `harmful_prob`, `confidence`, `category`, `method`.

---

## 14. Vendime: pemë vendimi dhe verdictet

```text
                    ┌─────────────────┐
                    │  analyze(text)  │
                    └────────┬────────┘
                             │
              ┌──────────────┴──────────────┐
              │ combined_score < 52% ?      │
              └──────────────┬──────────────┘
                     po │           │ jo
                        ▼           ▼
                   ALLOWED    keyword hit?
                                  │
                          po ─────┴───── jo
                           ▼              ▼
                      FORBIDDEN    score >= 72% ?
                                        │
                                po ─────┴───── jo
                                 ▼              ▼
                            FORBIDDEN        REVIEW
```

| Verdict | PHP poston? | Trajnim jsonl? |
|---------|-------------|----------------|
| ALLOWED | po | jo (label 0 nuk shtohet si forbidden) |
| REVIEW | jo | jo |
| FORBIDDEN | jo | po (`label: 1`) |
| OFFLINE | jo | — |

---

## 15. Shembull i plotë numerik

**Input:**  
`i will gift 1000$ register www.evil.xy/steal`

| Hap | Rezultat |
|-----|----------|
| keyword hate/scam | miss (asnjë frazë e plotë nga lista) |
| find_urls | `['www.evil.xy/steal']` |
| url_boost | 32 (url + scam_context + suspicious_tld) |
| clean → ML | `... register url` → scam_ml ≈ 30%, hate_ml ≈ 10% |
| adjust_ml_probs | pa ndryshim të madh |
| hate_combined | 10 |
| scam_combined | max(30+32, 72) = **72** |
| keyword_detected | false |
| combined >= 72 | **FORBIDDEN**, category `phishing_scam` |

---

## 16. Komanda operative dhe debug

```bash
# Trajnim
cd ml_api
pip install -r requirements.txt
python train_model.py
python train_model.py --incremental

# API
python api.py
# GET http://127.0.0.1:5000/health

# C# real-time
cd Backend
dotnet run

# Test një tekst nga Python
python -c "from api import analyze; print(analyze('your text here'))"
```

**Logje:**

- Konsola `api.py` — ngarkim modele
- `models/analysis_log.json` — historiku vendimesh
- Browser DevTools → Network → `ajax.php` → response JSON

---

## 17. Fjalor

| Term | Definicion |
|------|------------|
| **Verdict** | Vendimi final API: ALLOWED / REVIEW / FORBIDDEN |
| **combined_score** | Përqindja e përdorur për pragu (ML + boost), 0–100 |
| **harmful_prob** | Përqindja e kthyer te klienti (mund të sharpen-ohet për display) |
| **hybrid** | Keyword u aktivizua (+ boost), ML gjithsesi u ekzekutua |
| **TF-IDF** | Vektorizim teksti në numra |
| **pickle** | Serializim binar Python për objekt sklearn |
| **SignalR** | Bibliotekë Microsoft për real-time mbi WebSocket |
| **fail-closed** | Nëse ML offline → blloko postimin |
| **incremental train** | Ritrajnim me të dhëna të reja përdoruesi |
| **stratify** | Train/test split me të njëjtën përqindje klasash |

---

## Dokumente të lidhura

| Skedar | Përmbajtje |
|--------|------------|
| `docs/MODERIMI_APEXSOCIAL.md` | Procedurë operative (shqip, më e shkurtër) |
| `README.md` | Setup i projektit |
| `ml_api/requirements.txt` | Varësi Python |
| `database.sql` | Skema MySQL |

---

*Përditësuar për ApexSocial v5.0 — dual threshold, REVIEW, URL detection, balance_dataset, context adjustment.*
