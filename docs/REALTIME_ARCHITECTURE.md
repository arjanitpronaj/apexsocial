# ApexSocial — Realtime Architecture (Native WebSocket)

## A. Qëllimi

Komunikim **në kohë reale** (jo request/response për çdo njoftim):

- preview ML gjatë shkrimit të postit
- njoftime (like, mesazhe, etj.)
- përditësim radhe admin
- rezultat moderimi për autorin
- ban live

**Moderimi zyrtar** i postit/komentit mbetet **HTTP REST** (`POST /analyze` në `:5000`) — PHP e thërret në submit.

---

## B. Arkitektura (3 kanale)

```
┌──────────── Browser (JavaScript) ────────────┐
│  realtime.js  →  ws://host:8080  (WebSocket) │  ← kanal i hapur, realtime
└──────────────────────────────────────────────┘
                    │
                    ▼
         ml_api/ws_server.py (:8080)
                    │
    ┌───────────────┴───────────────┐
    │  JSON mesazhe: join, ping,    │
    │  preview_moderation, push fan-out │
    └───────────────┬───────────────┘
                    │
         analyze() ←─┘ (vetëm për preview, në thread pool)

┌──────────── PHP (includes/realtime.php) ─────┐
│  curl POST http://host:8081/api/push         │  ← një kërkesë HTTP, pastaj serveri shtyn te WS
└──────────────────────────────────────────────┘

┌──────────── PHP (includes/config.php) ─────┐
│  curl POST http://127.0.0.1:5000/analyze     │  ← moderim në submit (request/response)
└──────────────────────────────────────────────┘
```

| Port | Protokoll | Roli |
|------|-----------|------|
| **8080** | WebSocket (`ws://` / `wss://`) | Lidhje e vazhdueshme browser ↔ Python |
| **8081** | HTTP POST `/api/push` | PHP → fan-out te klientët e lidhur |
| **5000** | HTTP REST Flask | ML klasifikim (ALLOWED / FORBIDDEN) |

---

## C. Gjuhët programuese

| Shtresë | Gjuha | Skedar |
|---------|-------|--------|
| Klient realtime | **JavaScript** | `assets/js/realtime.js` |
| Server realtime | **Python** | `ml_api/ws_server.py` |
| Push nga web app | **PHP** | `includes/realtime.php` |
| ML | **Python** | `ml_api/api.py` |

**Nuk përdoret:** Socket.IO, SignalR, C# Backend.

---

## D. Si startohet

```bash
# Terminal 1 — ML API
cd ml_api
pip install -r requirements.txt
python api.py                 # :5000

# Terminal 2 — WebSocket + push
cd ml_api
python ws_server.py           # :8080 (WS) + :8081 (HTTP push)

# Terminal 3 — XAMPP
# Apache + MySQL, hap http://localhost/apexsocial/
```

Kontroll push bridge: `GET http://127.0.0.1:8081/health` → `{"ok":true,"service":"apex-push"}`

---

## E. Autentifikimi (join)

1. PHP llogarit token HMAC: `apexWsJoinToken($userId)` në `includes/realtime.php`
2. Token injektohet në `window.APEX_USER.wsToken` (`navbar.php`, `admin/inc_sidebar.php`)
3. Pas `WebSocket` `onopen`, klienti dërgon:

```json
{"type":"join","user_id":5,"token":"<hex_hmac>"}
```

4. Serveri verifikon me `WS_SECRET` (env, default `apex-ws-secret`), dritar 5 min (+ dritari i mëparshëm për clock skew)
5. Përgjigje: `{"type":"joined","user_id":5,"is_admin":false}`

Pa join të suksesshëm, mesazhet e tjera kthejnë `join_required`.

---

## F. Protokolli WebSocket (JSON)

Të gjitha mesazhet janë **tekst JSON** një drejtim.

### Klient → server

| `type` | Fusha | Përshkrim |
|--------|-------|-----------|
| `join` | `user_id`, `token` | Autentifikim (herën e parë pas connect) |
| `ping` | — | Heartbeat aplikacioni |
| `preview_moderation` | `text` | Analizë ML live (max 8000 chars, rate ~1.2s) |

### Server → klient

| `type` | Përshkrim |
|--------|-----------|
| `joined` | Join OK |
| `pong` | Përgjigje ping |
| `live_moderation` | Rezultat preview (`verdict`, `harmful_prob`, `category`, …) |
| `notification` | + `payload` (nga PHP push) |
| `moderation_result` | + `payload` |
| `queue_update` | + `payload` |
| `banned` | + `payload` |
| `error` | `msg`: `auth_failed`, `rate_limited`, … |

### Emrat PHP → tip wire (fan-out)

| Event PHP (`apexRealtimePush`) | `type` në wire |
|-------------------------------|----------------|
| `Notification` | `notification` |
| `ModerationResult` | `moderation_result` |
| `QueueUpdate` | `queue_update` |
| `Banned` | `banned` |

---

## G. PHP push bridge (`:8081`)

```http
POST /api/push
X-Api-Key: apex-ws-key-2025   (ose APEX_WS_KEY në env)
Content-Type: application/json

{
  "event": "Notification",
  "user_id": 5,
  "to_admins": false,
  "payload": { "msg": "...", "type": "like" }
}
```

Përgjigje: `{"sent":true,"delivered":1}`

Funksione helper: `apexNotifyUser()`, `apexModerationResult()`, `apexQueueUpdate()`, `apexUserBanned()`.

---

## H. Klient JavaScript (`ApexRealtime`)

Ngarkohet nga `includes/navbar.php`:

- `window.APEX_WS_URL` — p.sh. `ws://localhost:8080`
- `window.APEX_USER` — `userId`, `wsToken`, `isAdmin`

API publike (përdoret nga `app.js`):

| Metodë | Përshkrim |
|--------|-----------|
| `ApexRealtime.on('Notification', fn)` | Listener eventesh |
| `ApexRealtime.previewModeration(text)` | Promise preview ML |
| `ApexRealtime.isConnected()` | `true` pas `joined` |
| `ApexRealtime.start()` | Rilidhje manuale |

Heartbeat: `ping` çdo 25s, timeout pong 31s, reconnect eksponencial.

---

## I. Composer / post flow (lidhja me realtime)

1. Përdoruesi shkruan → countdown 10s (`app.js`)
2. Opsional: `ApexRealtime.previewModeration()` përmes WebSocket
3. Submit → PHP `moderateContent()` → HTTP `:5000/analyze` (autoritativ)
4. Nëse `FORBIDDEN` → nuk insertohet në DB

Preview WS **nuk** zëvendëson kontrollin server në submit.

---

## J. Variabla mjedisi

| Variabël | Përdorim |
|----------|----------|
| `WS_SECRET` | HMAC token join |
| `APEX_WS_KEY` | Auth header push `/api/push` |
| `APEX_SESSION_TOKENS` | JSON `user_id → is_admin` për grup admin |

---

## K. Troubleshooting

| Problem | Zgjidhje |
|---------|----------|
| Pika e kuqe në navbar | `python ws_server.py` nuk punon ose port 8080 i zënë |
| `auth_failed` | `WS_SECRET` i ndryshëm PHP vs Python; rifresko faqen (token 5 min) |
| Preview nuk punon | ML `:5000` offline; WS jo i lidhur |
| Admin push nuk arrin | `APEX_WS_KEY` i njëjtë; admin duhet `joined` + `is_admin` në session tokens |
| CSP bllokon WS | `includes/config.php` `connect-src` përfshin `ws://127.0.0.1:8080` |

---

## L. Ndryshim nga Socket.IO / SignalR (historik)

| Teknologji | Status në repo |
|------------|----------------|
| C# SignalR `Backend/` | **Hequr** |
| Socket.IO | **Hequr** — zëvendësuar me WebSocket nativ |
| **WebSocket nativ** | **Aktual** — `websockets` + `new WebSocket()` |

---

*Përditësuar: arkitektura aktive ApexSocial — Python WebSocket :8080, push :8081, ML HTTP :5000.*
