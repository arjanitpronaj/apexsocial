# ApexSocial — Realtime Architecture v6

## Target flow (production)

```
Browser
  ├─ AJAX → PHP (includes/ajax.php)     … CRUD, moderation authority, MySQL
  ├─ SignalR → C# :8080/hub             … push only (notifications, queue, live preview)
  └─ (server) PHP → curl → ML :5000     … classification

PHP after DB write → POST /api/realtime/push (X-Api-Key) → SignalR groups
```

## Layers

| Layer | Path | Responsibility |
|-------|------|----------------|
| Frontend hub client | `assets/js/realtime.js` | One connection, Ping/Pong, reconnect, `previewModeration()` |
| Frontend UI | `assets/js/app.js` | Toasts, badges, composer (realtime 400ms or AJAX 10s fallback) |
| PHP bridge | `includes/realtime.php` | `apexRealtimePush()`, helpers |
| Realtime host | `Backend/Program.cs` | Hub `/hub`, `POST /api/realtime/push`, `GET /health` |
| ML | `ml_api/api.py` | `/analyze` only (no Socket.IO) |

## SignalR events

| Event | Emitter | Audience |
|-------|---------|----------|
| `Notification` | PHP (likes, queue, etc.) | `user_{id}` |
| `NewPending` | PHP (REVIEW post/comment) | `admins` |
| `QueueUpdate` | PHP (admin queue action) | `admins` |
| `ModerationResult` | PHP (approve/reject) | author |
| `Banned` | PHP (admin ban) | `user_{id}` |
| `LiveModeration` | Hub `PreviewModeration` | caller (composer) |
| `Pong` | Hub `Ping` | caller (heartbeat) |

## CORS

- No `AllowAnyOrigin` with credentials.
- Origins: `localhost` / `127.0.0.1` (any port) via `SetIsOriginAllowed`.
- `BACKEND_URL` derived from `HTTP_HOST` → `http://{host}:8080` (fixes localhost vs 127.0.0.1 mismatch).

## Removed (cleanup)

| Item | Reason |
|------|--------|
| `index.html` | Unused static mock |
| C# REST API (~500 lines in old `Program.cs`) | Duplicated PHP; never called |
| `ajax.php` `ping` | Dead action |
| Duplicate ML CSVs in `ml_api/models/` | Copies exist under `models/datasets/` |
| Inline SignalR in `app.js` | Replaced by `realtime.js` |

## Optional further cleanup

| Item | Notes |
|------|-------|
| `admin/scan.php` | Orphan batch tool; keep if admins use it |
| `admin/posts.php` | Redirect only → link directly to `all_posts.php` |
| `mlIsOnline()` in config.php | Wire to composer or remove |
| `Backend/bin`, `Backend/obj` | Add to `.gitignore`; do not commit |

## Run

```bash
# Terminal 1 — ML
cd ml_api && python api.py

# Terminal 2 — Realtime hub
cd Backend && dotnet run
```

Open site as `http://localhost/apexsocial` (not `127.0.0.1`) so hub URL matches CORS.
