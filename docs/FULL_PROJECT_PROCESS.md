# ApexSocial — Full Project Process Documentation

This document provides a complete technical overview of the ApexSocial system: architecture, components, technologies, libraries, data flow, moderation pipeline, realtime communication, admin operations, deployment, and limitations.

---

## 1) Project Purpose

ApexSocial is a social networking platform with integrated AI-based content moderation.

Primary goals:

- Allow standard social features (posts, comments, likes, profile, friends, notifications).
- Analyze user-generated text before publication.
- Block harmful/scam content with a binary decision model.
- Provide realtime UX for moderation preview and notifications.

Final moderation decision for publish flow is binary:

- `ALLOWED`
- `FORBIDDEN`

---

## 2) System Architecture (High Level)

Core runtime stack:

- **PHP web application** (XAMPP/Apache)
- **MySQL** database
- **Python ML API** (`ml_api/api.py`) on `:5000`
- **Python native WebSocket server** (`ml_api/ws_server.py`) on `:8080`
- **HTTP push bridge** (`ml_api/ws_server.py`) on `:8081`
- **JavaScript frontend** (`assets/js/app.js`, `assets/js/realtime.js`)

Logical flow:

1. Browser interacts with PHP pages and AJAX endpoints.
2. PHP calls ML API (`/analyze`) for authoritative moderation decisions.
3. Browser keeps a persistent WebSocket connection for live events and preview moderation.
4. PHP pushes events to the WS server over HTTP (`/api/push`), and WS server fans out to connected clients.

---

## 3) Ports, Protocols, and Responsibilities

| Service | Port | Protocol | Responsibility |
|---|---:|---|---|
| ML API (`api.py`) | 5000 | HTTP REST | Authoritative text moderation and ML endpoints |
| Realtime server (`ws_server.py`) | 8080 | WebSocket (`ws://`, `wss://`) | Persistent client communication, preview moderation, events |
| Push bridge (`ws_server.py`) | 8081 | HTTP POST `/api/push` | PHP-to-realtime event injection |

---

## 4) Complete Technology and Library Stack

## 4.1 Backend Web Layer (PHP)

- PHP 8.x
- PDO (MySQL access)
- cURL (server-to-server HTTP calls)
- XAMPP (Apache + PHP + MySQL local runtime)

Key PHP files:

- `includes/config.php`
- `includes/ajax.php`
- `includes/realtime.php`
- `includes/navbar.php`
- `admin/*.php`
- `pages/*.php`

## 4.2 Database

- MySQL 8.x (schema from `database.sql`)

## 4.3 ML and API Layer (Python)

Core libraries in `ml_api/requirements.txt`:

- `flask`
- `flask-cors`
- `scikit-learn`
- `pandas`
- `numpy`
- `openpyxl`
- `waitress`
- `apscheduler`
- `websockets`
- `aiohttp`

Optional semantic moderation libraries (commented/optional in requirements):

- `transformers`
- `torch`
- `sentencepiece`

Python modules:

- `ml_api/api.py` (main moderation API)
- `ml_api/train_model.py` (model training)
- `ml_api/context_scoring.py`
- `ml_api/text_utils.py`
- `ml_api/text_integrity.py`
- `ml_api/semantic_scorer.py`
- `ml_api/security.py`
- `ml_api/online_learning.py`
- `ml_api/scheduler.py`
- `ml_api/apex_log.py`

## 4.4 Realtime Layer

- Python `websockets` (native WebSocket server)
- Python `aiohttp` (HTTP push endpoint)
- Browser WebSocket API (`new WebSocket(...)`)

## 4.5 Frontend

- HTML5
- CSS3
- Vanilla JavaScript
- Google Fonts (`Inter`) in UI
- Chart.js in admin dashboard views where enabled

---

## 5) End-to-End Content Moderation Flow

## 5.1 Post creation flow

1. User types into composer on `index.php`.
2. `assets/js/app.js` starts an idle countdown (10 seconds).
3. During typing flow, `assets/js/realtime.js` may send `preview_moderation` over WebSocket for live UX hints.
4. On submit, PHP executes `moderateContent()` in `includes/config.php`.
5. `moderateContent()` calls ML API (`POST http://127.0.0.1:5000/analyze`).
6. API returns verdict and metadata.
7. If verdict is `FORBIDDEN`, publish is blocked.
8. If verdict is `ALLOWED`, content can proceed to normal application workflow.

Important:

- WebSocket preview is advisory UX.
- Server-side moderation on submit is authoritative.

## 5.2 Comment moderation flow

Same moderation path as posts:

- request enters PHP endpoint
- passes through `moderateContent()`
- final action based on binary verdict

---

## 6) ML Pipeline (Technical)

The moderation decision is computed in `ml_api/api.py` with supporting modules.

Pipeline stages:

1. **Input preparation/integrity checks**
   - normalization, control-char handling, suspicious pattern checks
2. **Keyword signals**
   - hate/scam keyword boost signals
3. **ML inference**
   - sklearn pipelines for harmful/scam probabilities
4. **Context adjustment**
   - sentence/context damping and safety-context reductions
5. **URL-based scam boosting**
   - URL extraction and scam heuristics
6. **Bypass/manipulation adjustments**
   - text obfuscation or bypass indicators
7. **Combined scoring + thresholding**
   - final category and binary verdict generation

Configuration source:

- `ml_api/models/config.json`

Relevant thresholds:

- `threshold_low` (default around `0.52`)
- `threshold_high` (used for scoring behaviors)

Decision model remains binary for publish logic.

---

## 7) Verdicts and Status Semantics

Operational moderation verdicts in publish flow:

- `ALLOWED`
- `FORBIDDEN`

Technical/system statuses that may appear in API/security paths:

- `OFFLINE`
- `REJECTED`

Business behavior remains fail-closed in critical paths (offline detection should not silently allow content).

---

## 8) Realtime Communication Protocol

## 8.1 Client -> server messages

- `join` (`user_id`, `token`)
- `ping`
- `preview_moderation` (`text`)

## 8.2 Server -> client messages

- `joined`
- `pong`
- `live_moderation`
- `notification`
- `moderation_result`
- `queue_update`
- `banned`
- `error`

## 8.3 PHP push event mapping

PHP helper sends canonical events:

- `Notification`
- `ModerationResult`
- `QueueUpdate`
- `Banned`

WS server maps these to lowercase wire message types and fans out to target sessions.

---

## 9) Authentication and Authorization (Realtime)

Join authentication uses HMAC token flow:

1. PHP generates WS token with `apexWsJoinToken($userId)` in `includes/realtime.php`.
2. Token is embedded into `window.APEX_USER.wsToken`.
3. Client sends token with `join`.
4. WS server verifies token using `WS_SECRET` (time-windowed validation).

Push endpoint authorization:

- `POST /api/push` requires header `X-Api-Key`
- checked against `APEX_WS_KEY`

Admin targeting:

- derived via session/admin metadata (`APEX_SESSION_TOKENS` mapping and server-side resolution)

---

## 10) Security Controls

Main controls currently present:

- Input validation and sanitization in PHP.
- ML API request validation and security checks.
- Rate limiting in API/realtime paths.
- HMAC verification for WS join.
- API key protection for push bridge.
- CSP restrictions including WS `connect-src`.
- Offline fail-closed behavior in moderation pipeline.

Recommended hardening:

- keep secrets only in environment variables
- rotate `WS_SECRET` and `APEX_WS_KEY`
- avoid default fallback secrets in production

---

## 11) Admin Domain and Operations

Admin panel capabilities include:

- dashboard and health views
- moderation queue/content review
- harmful content logs
- report moderation
- user ban/unban actions
- ML stats and dataset views

Realtime updates reduce refresh dependency for operational workflows.

---

## 12) Data Ownership and Persistence Responsibilities

- PHP layer is authoritative for app business transactions and DB writes.
- ML API provides analysis decisions and metadata.
- WS server handles transient realtime transport + fan-out.
- Logging and model artifacts live in `ml_api/models/`.

This separation keeps database business logic centralized in PHP.

---

## 13) Important Files and Directories

- `index.php` - main feed/composer
- `includes/config.php` - core app config + moderation helper functions
- `includes/ajax.php` - AJAX endpoints
- `includes/realtime.php` - push bridge + WS token helper
- `assets/js/app.js` - UI interactions and composer behavior
- `assets/js/realtime.js` - WebSocket client
- `ml_api/api.py` - moderation API
- `ml_api/ws_server.py` - realtime server + push bridge
- `ml_api/train_model.py` - training script
- `ml_api/models/config.json` - moderation settings/thresholds
- `docs/REALTIME_ARCHITECTURE.md` - realtime-specific architecture reference

---

## 14) Environment Variables

| Variable | Purpose |
|---|---|
| `WS_SECRET` | HMAC signing/verification for WebSocket join |
| `APEX_WS_KEY` | API key for HTTP push bridge `/api/push` |
| `APEX_SESSION_TOKENS` | Session/admin mapping for realtime fan-out |

---

## 15) Local Runbook (Windows + XAMPP)

1. Start Apache and MySQL in XAMPP.
2. Import `database.sql`.
3. Start ML API:

```bash
cd ml_api
pip install -r requirements.txt
python api.py
```

4. Start realtime server:

```bash
cd ml_api
python ws_server.py
```

5. Open app:

- `http://localhost/apexsocial/`

---

## 16) Functional Verification Checklist

- Composer preview returns `ALLOWED` for benign text.
- Harmful text returns `FORBIDDEN` in preview and submit flow.
- Submit path blocks forbidden content server-side.
- Notification events arrive in realtime without page refresh.
- Admin queue updates propagate in realtime.
- Push health endpoint responds on `:8081/health`.

---

## 17) Known Limitations

Current limitations include:

- Primarily text-focused moderation pipeline.
- No guarantee of zero false positives/false negatives.
- Not a distributed multi-node realtime architecture by default.
- CI/CD and production ops automation are not fully standardized in repo.

---

## 18) What the System Can and Cannot Do

## Can do

- Live moderation preview while typing.
- Enforce binary moderation at submit time.
- Realtime event delivery to users/admins.
- Fail safely when moderation backend is unavailable.

## Cannot fully guarantee

- Perfect semantic understanding for all languages/contexts.
- Complete elimination of moderation edge cases.
- Horizontal scaling behavior without additional infrastructure work.

---

## 19) Evolution Notes

Legacy architecture pieces (C# SignalR/older stacks) are not part of the active authoritative runtime path. Current path is:

- PHP web app
- Python ML API
- Python native WebSocket realtime layer

---

## 20) Thesis-Friendly Summary

ApexSocial implements a hybrid social platform architecture where:

- PHP manages web/business/database workflows,
- Python ML API performs moderation analysis,
- Python WebSocket server provides realtime communication,
- final publish moderation remains server-side and binary (`ALLOWED`/`FORBIDDEN`).

This yields a practical balance between moderation safety, realtime UX, and maintainable system boundaries.

# ApexSocial — Dokumentim i Plotë i Procesit (A–ZH)

Ky dokument përmbledh të gjithë procesin teknik të projektit `ApexSocial`: arkitekturën, rrjedhat e të dhënave, moderimin ML, komunikimin realtime, sigurinë, startimin lokal dhe operimin.

---

## A. Qëllimi i projektit

`ApexSocial` është platformë sociale me:

- publikim postimesh/komentesh,
- moderim automatik me Machine Learning,
- njoftime dhe preview në kohë reale,
- panel admin për menaxhim përmbajtjeje dhe përdoruesish.

Moderimi final është **binar**: `ALLOWED` ose `FORBIDDEN`.

---

## B. Arkitektura e përgjithshme

Komponentët kryesorë:

- **PHP (XAMPP)**: aplikacioni web dhe logjika e biznesit.
- **MySQL**: ruajtja e të dhënave.
- **Python Flask API (`ml_api/api.py`)**: analiza ML (`:5000`).
- **Python WebSocket server (`ml_api/ws_server.py`)**: realtime (`:8080`) + push bridge (`:8081`).
- **JavaScript frontend (`assets/js/app.js`, `assets/js/realtime.js`)**: UI + realtime client.

---

## C. Portet dhe protokollet

| Shërbimi | Port | Protokoll | Rol |
|---|---:|---|---|
| ML API | 5000 | HTTP REST | Analiza zyrtare e përmbajtjes |
| Realtime | 8080 | WebSocket (`ws://` / `wss://`) | Preview live + events |
| Push bridge | 8081 | HTTP POST `/api/push` | PHP -> fan-out te klientët WS |

---

## D. Rrjedha e postimit (end-to-end)

1. Përdoruesi shkruan tekst në composer.
2. `app.js` nis countdown 10 sekonda pa input.
3. `realtime.js` dërgon `preview_moderation` në WebSocket (opsionale për UX).
4. Në submit, PHP thërret `moderateContent()` (autoritativ).
5. `moderateContent()` bën `POST /analyze` te `ml_api/api.py`.
6. ML kthen verdict `ALLOWED` ose `FORBIDDEN`.
7. Nëse `ALLOWED`, përmbajtja ruhet; nëse `FORBIDDEN`, bllokohet.

**Shënim:** Preview WS nuk e zëvendëson kontrollin server në submit.

---

## E. Rrjedha e komenteve

Rrjedha është analoge me postimet:

- komentet kalojnë në `moderateContent()` para ruajtjes,
- verdicti final kontrollon nëse komenti aprovohet ose refuzohet.

---

## F. Pipeline e moderimit ML

Implementimi kryesor në `ml_api/api.py`.

Hapat:

1. normalizim dhe kontroll integriteti teksti,
2. sinjale me keyword matching,
3. inferencë me modele sklearn (hate + scam),
4. rregullime konteksti,
5. URL boost / bypass signal,
6. kombinim score dhe vendim final.

Pragjet vijnë nga `ml_api/models/config.json`:

- `threshold_low` (zakonisht 0.52),
- `threshold_high` përdoret për sjellje scoring, por verdict final mbetet binar.

---

## G. Verdictet e sistemit

Verdiktet operative:

- `ALLOWED`
- `FORBIDDEN`

Gjendje teknike ndihmëse mund të shfaqen në rrjedha të caktuara:

- `OFFLINE` (kur ML është i padisponueshëm dhe PHP fail-closed e trajton si bllokim),
- `REJECTED` (refuzim sigurie në API).

Për publikim përmbajtjeje, vendimi i biznesit mbetet binar.

---

## H. Realtime me WebSocket nativ

`ml_api/ws_server.py` mban lidhje të vazhdueshme me browser-in.

Mesazhe klient -> server:

- `join` (`user_id`, `token`)
- `ping`
- `preview_moderation` (`text`)

Mesazhe server -> klient:

- `joined`
- `pong`
- `live_moderation`
- `notification`
- `moderation_result`
- `queue_update`
- `banned`
- `error`

---

## I. Auth dhe autorizim në WebSocket

Join-i bëhet me token HMAC:

- PHP gjeneron token (`apexWsJoinToken()` në `includes/realtime.php`),
- klienti e dërgon te mesazhi `join`,
- serveri e verifikon me `WS_SECRET` dhe dritare kohe 5-min.

Admin fan-out bazohet në `APEX_SESSION_TOKENS` / metadata admin.

---

## J. Push bridge nga PHP

`includes/realtime.php` ka `apexRealtimePush()`:

- `POST /api/push` në `:8081`,
- header `X-Api-Key` me `APEX_WS_KEY`,
- payload me event + target (`user_id` ose `to_admins`) + data.

Ky mekanizëm përdoret për njoftime, rezultate moderimi, queue update, ban.

---

## K. Siguria

Shtresat kryesore:

- validim inputi dhe sanitizim në PHP,
- kontroll rate limit / request checks në ML API,
- HMAC auth për WS join,
- API key për push bridge,
- CSP me `connect-src` të përshtatur për WS,
- fail-closed kur ML është offline.

---

## L. Paneli admin

Funksione kryesore:

- dashboard statistika,
- menaxhim queue/përmbajtje të bllokuar,
- raportime,
- menaxhim përdoruesish (ban/unban),
- pamje statistikash ML.

Eventet realtime japin përditësime pa refresh.

---

## M. Struktura kryesore e skedarëve

- `index.php` — feed/composer.
- `includes/config.php` — DB, ML calls, helper funksione.
- `includes/ajax.php` — endpoint-et AJAX.
- `includes/realtime.php` — push bridge + WS token helper.
- `assets/js/app.js` — logjikë UI/composer/toasts.
- `assets/js/realtime.js` — WS client.
- `ml_api/api.py` — ML REST API.
- `ml_api/ws_server.py` — WS + push.
- `ml_api/train_model.py` — trajnimi.

---

## M2. Teknologjitë dhe libraritë (të plota)

### Backend Web App (PHP)

- PHP 8 (XAMPP)
- PDO (MySQL)
- cURL (HTTP drejt ML dhe push bridge)

### Realtime

- Python `websockets`
- Python `aiohttp` (push API `:8081`)
- Browser WebSocket API (`new WebSocket(...)`)

### ML API

- Flask
- flask-cors
- scikit-learn
- pandas, numpy
- openpyxl (dataset import)
- waitress (opsionale për deploy)
- apscheduler (scheduler/retrain)

### Frontend

- HTML/CSS
- Vanilla JavaScript
- Komponenti `ApexRealtime` për evente live

### Data

- MySQL (tabelat e aplikacionit social)
- skedarë modelesh/dataset-esh te `ml_api/models/`

---

## M3. Module të funksionaliteteve (çka mbulon projekti)

### Përdoruesi

- regjistrim/hyrje/dalje
- feed dhe publikim postimesh
- komente, likes, repost/interaction
- friends/following
- notifications
- profile/settings
- saved posts

### Admin

- dashboard
- queue/përmbajtje e bllokuar
- harmful logs
- reports moderation
- users management (ban/unban)
- ML stats
- dataset view/opsione operacionale

### Inteligjenca e moderimit

- analizë teksti para publikimit
- kategorizim hate/scam
- score i kombinuar me pragje
- vendim final binar
- feedback/retrain hooks

---

## N. Variabla mjedisi

| Variabël | Përdorim |
|---|---|
| `WS_SECRET` | Nënshkrimi HMAC për join WS |
| `APEX_WS_KEY` | Autentifikim i `POST /api/push` |
| `APEX_SESSION_TOKENS` | Mapping për role admin në realtime |

---

## N2. Çka mundet dhe çka s'mundet sistemi

### Çka mundet

- të bllokojë përmbajtje të dyshimtë para publikimit
- të japë preview live të risk-ut gjatë shkrimit
- të njoftojë përdorues/admin në kohë reale
- të punojë me flow fail-closed kur ML është offline
- të operojë me vendim të thjeshtë ALLOWED/FORBIDDEN

### Çka s'mundet (kufizime aktuale)

- nuk bën moderim media me model vizual (fokus kryesisht tekst)
- nuk garanton 100% zero false positives/negatives
- nuk është platformë distributed multi-node out-of-the-box
- nuk ka CI/CD enterprise pipeline të formalizuar në repo

---

## O. Si startohet projekti (lokal)

1. Nis `Apache` + `MySQL` në XAMPP.
2. Importo `database.sql`.
3. Nis ML API:

```bash
cd ml_api
pip install -r requirements.txt
python api.py
```

4. Nis realtime:

```bash
cd ml_api
python ws_server.py
```

5. Hape aplikacionin: `http://localhost/apexsocial/`.

---

## P. Teste funksionale bazë

1. Shkruaj tekst neutral -> preview `ALLOWED`.
2. Shkruaj tekst harmful -> preview `FORBIDDEN`.
3. Provo submit me harmful -> server bllokon.
4. Kryej një like/aksiom që gjeneron njoftim -> notif live shfaqet.
5. Kontrollo admin queue update pa refresh.

---

## Q. Troubleshooting i shpejtë

- **Nuk lidhet realtime:** kontrollo `python ws_server.py` dhe portin `8080`.
- **`auth_failed`:** `WS_SECRET` i ndryshëm mes PHP dhe Python.
- **Push nuk shpërndahet:** kontrollo `APEX_WS_KEY` dhe `:8081/api/push`.
- **Preview s’vjen:** kontrollo ML API në `:5000`.
- **CSP error:** verifiko `connect-src` në `includes/config.php`.

---

## R. Standardi i komunikimit në projekt

- Realtime = **WebSocket kanal i hapur**.
- Moderim final = **HTTP request/response**.

Pra sistemi kombinon:

- **event-driven** (realtime UX),
- **request-driven** (vendimi final server-side).

---

## S. Çfarë është hequr nga arkitektura

- C# SignalR backend (legacy),
- Socket.IO stack i mëparshëm.

Aktualisht rruga autoritative është Python WS + Python ML + PHP app.

---

## T. Përputhja me kërkesat e moderimit

- Nuk përdoret verdict i ndërmjetëm për publikim.
- Vendimi final përmbajtjeje është vetëm `ALLOWED/FORBIDDEN`.
- UI dhe backend janë sinkronizuar me këtë model binar.

---

## U. Performanca dhe stabiliteti

- Preview ka rate limit në ws server (mbrojtje nga flood).
- Heartbeat `ping/pong` mban lidhjen e shëndetshme.
- Reconnect në klient për rikthim automatik.

---

## V. Integrimi me databazë

- PHP mbetet shtresa që kryen insert/update në MySQL.
- ML API nuk menaxhon transaksione DB të aplikacionit social.
- Kjo e mban përgjegjësinë e biznesit në një pikë (PHP).

---

## X. Udhëzim për dokumentim teza

Përshkrimi i saktë:

- “Realtime implementohet me **native WebSocket** (`websockets` në Python, `WebSocket` API në JS).”
- “Moderimi final kryhet nga PHP përmes HTTP ndaj Flask API.”
- “Sistemi ka vendim binar ALLOWED/FORBIDDEN.”

---

## Y. Roadmap i shkurtër teknik

- Shto monitorim të health checks (5000/8080/8081),
- menaxho secrets vetëm nga env (pa default hardcoded),
- shto test automation për rrjedhat kryesore realtime + moderation.

---

## ZH. Përfundim

`ApexSocial` është sistem hibrid i strukturuar:

- **PHP** për biznes/logjikë aplikacioni,
- **Python ML** për klasifikim,
- **Python WebSocket** për komunikim në kohë reale.

Ky kombinim jep:

- moderim të sigurt server-side,
- UX realtime për përdoruesit/adminët,
- arkitekturë të qartë dhe të mirëmbajtshme.

