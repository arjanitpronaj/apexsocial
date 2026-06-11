# ApexSocial — Content-Moderated Social Platform*

A full-stack social media web app with **AI content moderation** via a **Singular C# Architecture**.

## Architecture (Required Flow)

```
Frontend (HTML/CSS/JS)
        ↓
   PHP (XAMPP)       ← API layer
        ↓
C# Singular Core     ← ONE C# service, central hub
        ↓
  Python ML API      ← Scikit-learn moderation
        ↓
    Response
```

**The C# Singular Core** (`Backend/Program.cs`) is the ONLY C# service. All moderation, business logic, and ML communication flows through it. PHP never calls Python directly.

## Stack

| Layer     | Technology                                  |
|-----------|---------------------------------------------|
| Frontend  | HTML5 / CSS3 / Vanilla JS (Light SaaS theme)|
| API layer | PHP 8 / PDO / XAMPP                         |
| Core      | **C# ASP.NET Core 8.0 (Singular)** — port 8080 |
| ML        | Python 3 / Flask / Scikit-learn — port 5000 |
| Database  | MySQL 8 (plain text passwords as required)  |
| Realtime  | SignalR (C#) + Socket.IO (Python)           |

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

### 4. Start C# Singular Core (MOST IMPORTANT)
```bash
cd Backend
dotnet restore
dotnet run               # starts C# Core on port 8080
```

**⚠️ If Windows Defender blocks the C# backend:**
1. Open Windows Security → Virus & Threat Protection → Manage Settings
2. Add an exclusion for the `Backend` folder
3. Or run PowerShell as Admin: `Add-MpPreference -ExclusionPath "C:\xampp\htdocs\apexsocial\Backend"`
4. Alternatively, allow `dotnet.exe` through Windows Defender Firewall

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
  - After 20s of no input, AI analysis runs via C# → ML
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
2. After **20 seconds of inactivity**, JS sends text to `includes/ajax.php`
3. PHP forwards to **C# Singular Core** at `/api/moderate`
4. C# calls Python ML `/analyze`
5. Python returns `label`, `harmful_prob`, `category`
6. C# normalizes to: `safe` or `forbidden`
7. UI shows single alert; Post button enables only if `safe`
8. On submit → post is saved as `pending` → admin approves in queue

## Only Two ML Statuses

By requirement, the system reports only:
- `safe` → user can post
- `forbidden` → blocked with reason

No intermediate states ("review", "warning", etc.) are shown to users.

## Security

- C# Singular Core protected by API key (`X-Api-Key` header)
- Input validation on all endpoints
- Ban check on every request
- File upload validation via C# backend
- Session invalidation on ban

## File Structure

```
apexsocial/
├── index.php              # Feed (home)
├── database.sql           # MySQL schema + seed
├── README.md
├── includes/
│   ├── config.php         # DB connection + C# client
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
│       └── app.js         # 20s debounce + ML logic
├── uploads/
│   ├── posts/
│   └── avatars/
├── Backend/               # C# Singular Architecture
│   ├── Program.cs         # ~775 lines, single file, all core logic
│   ├── ApexSocial.csproj
│   └── appsettings.json
└── ml_api/                # Python ML service
    ├── api.py             # Flask + Scikit-learn
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
- The C# Singular Core is the ONLY backend service — PHP never calls ML directly
