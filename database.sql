-- ============================================================
-- ApexSocial — Database Schema
-- Plain text passwords as requested
-- Run this in phpMyAdmin (http://localhost/phpmyadmin)
-- ============================================================

CREATE DATABASE IF NOT EXISTS apexsocial CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE apexsocial;

-- ── USERS ────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,           -- plain text as required
    full_name VARCHAR(100) DEFAULT '',
    bio TEXT DEFAULT NULL,
    location VARCHAR(100) DEFAULT '',
    avatar VARCHAR(255) DEFAULT NULL,
    avatar_color VARCHAR(7) DEFAULT '#4f46e5',
    is_blocked TINYINT(1) DEFAULT 0,
    is_admin TINYINT(1) DEFAULT 0,
    ban_reason TEXT DEFAULT NULL,
    banned_at DATETIME DEFAULT NULL,
    session_invalidated_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ── POSTS ────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS posts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    content TEXT NOT NULL,
    image VARCHAR(255) DEFAULT NULL,
    pdf VARCHAR(255) DEFAULT NULL,
    repost_of INT DEFAULT NULL,
    status ENUM('pending','approved','rejected') DEFAULT 'pending',
    ml_label TINYINT(1) DEFAULT NULL,
    ml_prob FLOAT DEFAULT NULL,
    ml_category VARCHAR(30) DEFAULT NULL,
    ml_method VARCHAR(20) DEFAULT NULL,
    reviewed_by INT DEFAULT NULL,
    reviewed_at DATETIME DEFAULT NULL,
    reject_reason TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (repost_of) REFERENCES posts(id) ON DELETE SET NULL,
    FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_status (status),
    INDEX idx_user_id (user_id)
);

-- ── COMMENTS ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS comments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    post_id INT NOT NULL,
    user_id INT NOT NULL,
    content TEXT NOT NULL,
    status ENUM('pending','approved','rejected') DEFAULT 'pending',
    ml_label TINYINT(1) DEFAULT NULL,
    ml_prob FLOAT DEFAULT NULL,
    ml_category VARCHAR(30) DEFAULT NULL,
    ml_method VARCHAR(20) DEFAULT NULL,
    reviewed_by INT DEFAULT NULL,
    reviewed_at DATETIME DEFAULT NULL,
    reject_reason TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_post_status (post_id, status)
);

-- ── LIKES ────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS likes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    post_id INT NOT NULL,
    user_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_like (post_id, user_id),
    FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- ── FRIENDSHIPS ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS friendships (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sender_id INT NOT NULL,
    receiver_id INT NOT NULL,
    status ENUM('pending','accepted','rejected') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_friendship (sender_id, receiver_id),
    FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (receiver_id) REFERENCES users(id) ON DELETE CASCADE
);

-- ── NOTIFICATIONS ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    from_user_id INT NOT NULL,
    type ENUM('friend_request','friend_accepted','like','comment',
              'post_approved','post_rejected','comment_approved','comment_rejected') NOT NULL,
    reference_id INT DEFAULT NULL,
    message TEXT DEFAULT NULL,
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (from_user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_unread (user_id, is_read)
);

-- ── CONTENT ANALYSIS LOG ─────────────────────────────────────
CREATE TABLE IF NOT EXISTS content_analysis (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    content_type ENUM('post','comment') NOT NULL,
    content_id INT NOT NULL,
    text_snapshot TEXT NOT NULL,
    label TINYINT(1) NOT NULL,
    harmful_prob FLOAT NOT NULL,
    confidence FLOAT NOT NULL,
    category VARCHAR(30) DEFAULT 'safe',
    method VARCHAR(20) DEFAULT 'sklearn',
    reviewed_by_admin TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_label (label),
    INDEX idx_category (category)
);

-- ── REPORTS ──────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reporter_id INT NOT NULL,
    content_type ENUM('post','comment','profile') NOT NULL,
    content_id INT NOT NULL,
    reason VARCHAR(200) NOT NULL DEFAULT 'other',
    description TEXT DEFAULT NULL,
    status ENUM('pending','reviewed_ok','reviewed_removed') DEFAULT 'pending',
    reviewed_by INT DEFAULT NULL,
    reviewed_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_report (reporter_id, content_type, content_id),
    FOREIGN KEY (reporter_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
);

-- ════════════════════════════════════════════════════════════
-- SEED DATA — plain text passwords
-- ════════════════════════════════════════════════════════════
INSERT INTO users (username,email,password,full_name,bio,location,avatar_color,is_admin) VALUES
('admin',      'admin@apexsocial.com','Admin@2024', 'Administrator',  'Platform administrator','HQ',      '#ef4444',1),
('alex_smith', 'alex@demo.com',       'Alex@2024',  'Alex Smith',     'Full-stack developer',  'New York','#4f46e5',0),
('sarah_jones','sarah@demo.com',      'Sarah@2024', 'Sarah Jones',    'UI/UX Designer',        'London',  '#ec4899',0),
('mike_dev',   'mike@demo.com',       'Mike@2024',  'Mike Developer', 'Backend engineer',      'Berlin',  '#10b981',0);

INSERT INTO posts (user_id,content,status,ml_label,ml_prob,ml_category,ml_method,reviewed_by,reviewed_at) VALUES
(2,'Just launched my new portfolio website! Built with React and Node.js.','approved',0,2.1,'safe','sklearn',1,NOW()),
(3,'Design tip: White space is a powerful design tool that guides the eye.','approved',0,1.5,'safe','sklearn',1,NOW()),
(4,'Spent the weekend contributing to open source projects!','approved',0,0.8,'safe','sklearn',1,NOW()),
(2,'Learning WebSockets today — real-time communication is fascinating!','approved',0,1.2,'safe','sklearn',1,NOW()),
(3,'Color psychology in UI design is underrated.','approved',0,0.9,'safe','sklearn',1,NOW());

INSERT INTO friendships (sender_id,receiver_id,status) VALUES (2,3,'accepted'),(2,4,'accepted');

INSERT INTO likes (post_id,user_id) VALUES (1,3),(1,4),(2,2),(2,4),(3,2),(3,3),(4,3),(5,2),(5,4);

INSERT INTO comments (post_id,user_id,content,status,ml_label,ml_prob,ml_category,ml_method,reviewed_by,reviewed_at) VALUES
(1,3,'Looks amazing! Love the clean design.','approved',0,1.2,'safe','sklearn',1,NOW()),
(1,4,'Great work! Performance is impressive.','approved',0,0.8,'safe','sklearn',1,NOW()),
(2,2,'Negative space makes a huge difference.','approved',0,1.0,'safe','sklearn',1,NOW()),
(3,3,'Open source is the backbone of tech.','approved',0,0.7,'safe','sklearn',1,NOW());

INSERT INTO content_analysis (user_id,content_type,content_id,text_snapshot,label,harmful_prob,confidence,category,method) VALUES
(2,'post',1,'Just launched my new portfolio website!',0,2.1,97.9,'safe','sklearn'),
(3,'post',2,'Design tip: White space is a powerful design tool',0,1.5,98.5,'safe','sklearn');
