# ApexSocial — Content-Moderated Social Platform

A full-stack social media web app with **AI content moderation** (PHP + Python ML + native WebSocket realtime).

## Architecture

```
Browser (HTML/CSS/JS + realtime.js)
        ↓
   PHP (XAMPP)  ──HTTP──→  Python ML API (:5000)   … moderate posts/comments
        │
        ├── WebSocket (:8080)  ←→  ws_server.py   … live preview, notifications
        └── HTTP push (:8081)  →   ws_server.py   … PHP fan-out to connected clients
        ↓
     MySQL
```

## Stack

| Layer     | Technology                                  |
|-----------|---------------------------------------------|
| Frontend  | HTML5 / CSS3 / Vanilla JS                   |
| Web app   | PHP 8 / PDO / XAMPP                         |
| ML        | Python 3 / Flask / Scikit-learn — port 5000 |
| Realtime  | **Native WebSocket** — `ws_server.py` :8080, push :8081 |
| Database  | MySQL 8                                     |

## Setup (Windows + XAMPP)

### 1. Database
- Start MySQL in XAMPP control panel
- Open phpMyAdmin → Import `database.sql`
- Database `apexsocial` will be created with seed data

### 2. Deploy PHP files
- Copy the entire `apexsocial` folder to `C:\xampp\htdocs\`
- Open `http://localhost/apexsocial/` in browser

### 3. Start Python ML API
```bash
cd ml_api
pip install -r requirements.txt
python train_model.py    # first time only — trains sklearn pipelines
python api.py            # starts ML API on port 5000
```

### 4. Start WebSocket realtime server
```bash
cd ml_api
python ws_server.py        # WebSocket :8080 + HTTP push :8081
```

See `docs/REALTIME_ARCHITECTURE.md` for protocol, events, and troubleshooting.

### 5. Add Datasets
Place your training datasets (CSV/XLSX) in `ml_api/models/datasets/`:
- `PhiUSIIL_Phishing_URL_Dataset.csv`
- `scam.xlsx`
- `malicious_phish.csv`

Then retrain: `python train_model.py`

## Default Accounts

| Role      | Username     | Password     |
|-----------|--------------|--------------|
| Admin     | admin        | Admin@2024   |
| User      | alex_smith   | Alex@2024    |
| User      | sarah_jones  | Sarah@2024   |
| User      | mike_dev     | Mike@2024    |

Passwords are stored in **plain text** as required.

## Features

### User Side
- Light SaaS design, all English
- **Post composer with 20-second debounce**:
  - Button is disabled while typing
  - After 10s of no input, AI preview runs via WebSocket (or HTTP fallback)
  - Button enables ONLY if status = `safe`
  - If `forbidden`, a single alert shows the reason
- Like, comment, follow, friend requests
- Real-time notifications

### Admin Panel
- SaaS dashboard with top-colored stat cards
- Moderation queue (pending posts/comments)
- Harmful detected log
- Reports
- Users management (ban/unban)
- ML statistics
- Dataset viewer
- Activity log

## Content Moderation Flow

1. User types a post
2. After **10 seconds of inactivity**, JS runs ML preview (WebSocket `preview_moderation` or AJAX)
3. On submit, PHP calls Python ML `/analyze` via `moderateContent()`
4. Verdict: **ALLOWED** or **FORBIDDEN** (binary)
5. UI shows alert; Post button enables only when ALLOWED
6. Forbidden content is not published

## Only Two ML Statuses

By requirement, the system reports only:
- `safe` → user can post
- `forbidden` → blocked with reason

No intermediate states ("review", "warning", etc.) are shown to users.

## Security

- WebSocket join protected by HMAC token (`WS_SECRET`)
- Push bridge protected by API key (`APEX_WS_KEY`)
- Input validation on all endpoints
- Ban check on every request
- File upload validation in PHP
- Session invalidation on ban

## File Structure

```
apexsocial/
├── index.php              # Feed (home)
├── database.sql           # MySQL schema + seed
├── README.md
├── includes/
│   ├── config.php         # DB + ML client + moderation helpers
│   ├── realtime.php       # WebSocket push bridge (:8081)
│   └── ajax.php           # AJAX endpoints
├── pages/
│   ├── login.php          # User login (SaaS style)
│   ├── register.php
│   ├── logout.php
│   ├── profile.php
│   ├── friends.php
│   ├── notifications.php
│   └── banned.php
├── admin/
│   ├── login.php          # Admin login
│   ├── index.php          # Dashboard
│   ├── queue.php          # Moderation queue
│   ├── all_posts.php      # ✓ FIXED (no is_blocked error)
│   ├── activity.php       # ✓ FIXED (no p.is_blocked error)
│   ├── users.php
│   ├── harmful.php
│   ├── reports.php
│   ├── ml_stats.php
│   ├── dataset.php
│   ├── inc_sidebar.php    # Shared admin sidebar
│   └── logout.php
├── assets/
│   ├── css/
│   │   ├── style.css      # User-facing light SaaS theme
│   │   └── admin.css      # Admin panel theme
│   └── js/
│       ├── app.js         # Composer + toasts
│       └── realtime.js    # Native WebSocket client
├── uploads/
│   ├── posts/
│   └── avatars/
└── ml_api/                # Python ML + realtime
    ├── api.py             # Flask ML (:5000)
    ├── ws_server.py       # WebSocket (:8080) + push (:8081)
    ├── train_model.py
    ├── requirements.txt
    └── models/
        ├── config.json
        └── datasets/      # Place your datasets here
```

## Notes

- Dataset files are **NOT** included — add them manually to `ml_api/models/datasets/`
- Passwords in DB are **plain text** as required
- All content (posts, comments) goes to `pending` status and must be admin-approved
- Realtime docs: `docs/REALTIME_ARCHITECTURE.md`
