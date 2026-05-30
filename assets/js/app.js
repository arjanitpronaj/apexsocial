const API = typeof window.APEX_API !== 'undefined' && window.APEX_API
    ? window.APEX_API
    : 'includes/ajax.php';

function apexLogError(context, error, extra) {
    console.error('[ApexSocial] ' + context, error, extra || '');
}

function apexLogWarn(context, extra) {
    console.warn('[ApexSocial] ' + context, extra || '');
}

if (!window.__apexGlobalErrorHandlersInstalled) {
    window.__apexGlobalErrorHandlersInstalled = true;
    window.onerror = function (message, source, lineno, colno, error) {
        apexLogError('Uncaught error', error || message, { source, lineno, colno });
    };
    window.onunhandledrejection = function (event) {
        apexLogError('Unhandled promise rejection', event?.reason, event);
    };
}

/**
 * Post composer: 10s idle countdown, then one ML check (WebSocket or HTTP fallback).
 */
(function () {
    const ta = document.getElementById('post-content');
    const btn = document.getElementById('post-btn');
    const cc = document.getElementById('cc');
    const mlAlert = document.getElementById('ml-alert');
    const mlTitle = document.getElementById('ml-title');
    const mlReason = document.getElementById('ml-reason');
    const form = document.getElementById('post-form');
    if (!ta || !btn || !form) return;

    const COUNTDOWN_SEC = 10;
    const INPUT_IDLE_DEBOUNCE_MS = 300;
    const ML_ANALYZE_URL = window.APEX_ML_ANALYZE_URL || 'http://127.0.0.1:5000/analyze';

    let countdownInterval = null;
    let countdownTimeout = null;
    let debounceTimer = null;
    let remaining = COUNTDOWN_SEC;
    let status = 'idle';
    let lastAnalyzedText = '';
    let countdownActive = false;
    let checkingMl = false;
    let pendingSubmitAfterCheck = false;
    let programmaticSubmit = false;

    // UI helpers
    function setIcon(type) {
        const icon = mlAlert.querySelector('.ml-alert-icon');
        if (!icon) return;
        if (type === 'analyzing') {
            icon.innerHTML = '<div class="ml-spinner"></div>';
        } else if (type === 'ALLOWED') {
            icon.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>';
        } else if (type === 'FORBIDDEN') {
            icon.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>';
        } else {
            icon.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>';
        }
    }

    function setML(type, title, reason) {
        status = type;
        mlAlert.className = 'ml-alert show ' + type.toLowerCase();
        setIcon(type);
        mlTitle.textContent  = title;
        mlReason.textContent = reason;
        btn.disabled = (type !== 'ALLOWED');
    }

    function syncVerdictPreview(text, stateClass) {
        const el = document.getElementById('rt-ml-preview-status');
        if (!el) return;
        if (!text) {
            el.textContent = '';
            el.style.display = 'none';
            return;
        }
        el.textContent = text;
        el.style.display = 'block';
        if (stateClass === 'forbidden') el.style.color = '#dc2626';
        else if (stateClass === 'allowed') el.style.color = '#16a34a';
        else el.style.color = '#64748b';
    }

    function getCountdownIndicator() {
        let el = document.getElementById('submit-countdown-indicator');
        if (el) return el;
        const container = btn.closest('.top-actions') || btn.parentNode;
        if (!container) return null;
        el = document.createElement('span');
        el.id = 'submit-countdown-indicator';
        el.style.cssText = 'margin-right:10px;font-size:12px;color:#64748b;display:none;';
        container.insertBefore(el, btn);
        return el;
    }

    function setCountdownIndicator(text, visible) {
        const el = getCountdownIndicator();
        if (!el) return;
        el.textContent = text || '';
        el.style.display = visible ? 'inline' : 'none';
    }

    // Countdown ticker
    function startCountdown() {
        clearCountdown();
        countdownActive = true;
        checkingMl = false;
        remaining = COUNTDOWN_SEC;
        setML('countdown',
              `Analysis in ${remaining}s…`,
              'AI check will run after 10 seconds of inactivity.');
        syncPreviewCountdown(remaining);
        setCountdownIndicator(`Submitting in ${remaining}...`, true);
        btn.disabled = true;

        countdownInterval = setInterval(() => {
            remaining--;
            if (remaining > 0) {
                mlTitle.textContent = `Analysis in ${remaining}s…`;
                syncPreviewCountdown(remaining);
                setCountdownIndicator(`Submitting in ${remaining}...`, true);
            } else {
                clearInterval(countdownInterval);
                countdownInterval = null;
                syncPreviewCountdown(0);
                setCountdownIndicator('Checking...', true);
            }
        }, 1000);
        countdownTimeout = setTimeout(runAnalysis, COUNTDOWN_SEC * 1000);
    }

    function syncPreviewCountdown(seconds) {
        const ta = document.getElementById('post-content');
        if (!ta) return;
        let el = document.getElementById('rt-ml-preview-status');
        if (!el) {
            el = document.createElement('div');
            el.id = 'rt-ml-preview-status';
            el.style.cssText = 'font-size:12px;color:#64748b;margin-top:6px;min-height:18px;';
            if (ta.parentNode) ta.parentNode.insertBefore(el, ta.nextSibling);
        }
        if (seconds > 0) {
            el.textContent = `Checking in ${seconds}s...`;
            el.style.display = 'block';
            el.style.color = '#64748b';
            return;
        }
        if (seconds === 0) {
            el.textContent = 'Checking...';
            el.style.display = 'block';
            el.style.color = '#64748b';
        }
    }

    function clearCountdown() {
        if (countdownInterval) { clearInterval(countdownInterval);  countdownInterval = null; }
        if (countdownTimeout)  { clearTimeout(countdownTimeout);      countdownTimeout = null; }
        countdownActive = false;
    }

    function cancelPendingTimersForInput() {
        if (debounceTimer) {
            clearTimeout(debounceTimer);
            debounceTimer = null;
        }
        clearCountdown();
        setCountdownIndicator('', false);
    }

    function applyVerdict(d) {
        const v = String(d.verdict || d.status || 'ALLOWED').toUpperCase();
        if (v === 'FORBIDDEN' || v === 'REVIEW') {
            const categoryLabel = d.category === 'hate_speech'
                ? 'Hate speech'
                : (d.category === 'phishing_scam' ? 'Scam/phishing' : 'Policy');
            const risk = Number.isFinite(Number(d.harmful_prob))
                ? ` (risk ${Number(d.harmful_prob).toFixed(1)}%)`
                : '';
            const reason = (d.reason || 'Content not allowed.') + risk;
            setML('FORBIDDEN', `Forbidden: ${categoryLabel}`, reason);
            syncVerdictPreview(`Blocked: ${categoryLabel}${risk}`, 'forbidden');
            return;
        }
        if (v === 'OFFLINE') {
            setML('OFFLINE', 'Detection system offline',
                'Detection system is currently inactive. Posting is temporarily unavailable. Please try again later.');
            syncVerdictPreview('Detection system offline', 'offline');
            return;
        }
        setML('ALLOWED', 'Allowed', 'Content passed the check — you can post.');
        syncVerdictPreview('Allowed — you can post', 'allowed');
    }

    async function runAnalysis() {
        const snapshot = ta.value.trim();
        if (!snapshot) { resetIdle(); return; }
        if (snapshot === lastAnalyzedText) return;

        countdownActive = false;
        checkingMl = true;
        setML('analyzing', 'Checking content…', 'Running AI moderation via WebSocket.');
        syncPreviewCountdown(0);
        btn.disabled = true;
        setCountdownIndicator('Checking...', true);

        try {
            const payload = {
                text: snapshot,
                user_id: (window.APEX_USER && window.APEX_USER.userId) ? Number(window.APEX_USER.userId) : 0,
                type: 'post',
            };
            const res = await fetch(ML_ANALYZE_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            });
            const result = await res.json();
            if (ta.value.trim() !== snapshot) return;

            if (!res.ok) {
                setML('OFFLINE', 'Detection system offline',
                    'Detection system is currently inactive. Posting is temporarily unavailable. Please try again later.');
                syncVerdictPreview('Detection system offline', 'offline');
                return;
            }

            lastAnalyzedText = snapshot;
            applyVerdict(result);
            if (status === 'ALLOWED' && pendingSubmitAfterCheck) {
                pendingSubmitAfterCheck = false;
                programmaticSubmit = true;
                form.submit();
            }
        } catch (e) {
            apexLogError('runAnalysis ML fetch failed', e);
            if (ta.value.trim() !== snapshot) return;
            setML('OFFLINE', 'Detection system offline',
                'Detection system is currently inactive. Posting is temporarily unavailable. Please try again later.');
            syncVerdictPreview('Detection system offline', 'offline');
        } finally {
            checkingMl = false;
            setCountdownIndicator('', false);
            if (status === 'ALLOWED' || status === 'FORBIDDEN') {
                btn.disabled = status !== 'ALLOWED';
            }
        }
    }

    function resetIdle() {
        cancelPendingTimersForInput();
        lastAnalyzedText = '';
        if (window.ApexRealtime && typeof ApexRealtime.cancelPreview === 'function') {
            ApexRealtime.cancelPreview();
        }
        pendingSubmitAfterCheck = false;
        checkingMl = false;
        syncVerdictPreview('', '');
        setML('idle', 'Start typing to enable Post', 'AI check runs 10s after you stop typing (via WebSocket).');
    }

    // Input listener
    ta.addEventListener('input', () => {
        cc.textContent = ta.value.length + ' / 1000';
        btn.disabled   = true;
        pendingSubmitAfterCheck = false;

        if (!ta.value.trim()) { resetIdle(); return; }
        lastAnalyzedText = '';
        cancelPendingTimersForInput();
        if (window.ApexRealtime && typeof ApexRealtime.cancelPreview === 'function') {
            ApexRealtime.cancelPreview();
        }
        debounceTimer = setTimeout(() => {
            debounceTimer = null;
            if (!ta.value.trim()) return;
            startCountdown();
        }, INPUT_IDLE_DEBOUNCE_MS);
    });

    // Form submit guard
    document.getElementById('post-form').addEventListener('submit', e => {
        if (programmaticSubmit) {
            programmaticSubmit = false;
            return;
        }
        if (status !== 'ALLOWED') {
            e.preventDefault();
            if (countdownActive || checkingMl || status === 'countdown' || status === 'analyzing') {
                pendingSubmitAfterCheck = true;
                setML(status === 'countdown' ? 'countdown' : 'analyzing',
                      status === 'countdown' ? mlTitle.textContent : 'Checking content…',
                      'Please wait… moderation check is still running.');
            } else if (status === 'OFFLINE') {
                setML('OFFLINE',
                      'Detection system offline',
                      'Detection system is currently inactive. Posting is temporarily unavailable. Please try again later.');
            } else {
                setML('FORBIDDEN', 'Content blocked',
                      'This content cannot be published. Edit your text and wait for a new check.');
            }
        }
    });
    resetIdle();
})();

function previewImg(input) {
    if (!input.files || !input.files[0]) return;
    const file = input.files[0];
    const wrap = document.getElementById('img-preview-wrap');
    const isPdf = file.name.toLowerCase().endsWith('.pdf');
    if (isPdf) {
        wrap.innerHTML = `<div class="pdf-preview-composer">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="13" y2="17"/></svg>
            <span>${file.name}</span>
            <button type="button" onclick="clearImg()" class="pdf-close-btn">✕</button>
        </div>`;
        wrap.style.display = 'block';
    } else {
        const r = new FileReader();
        r.onload = e => {
            wrap.innerHTML = `<img id="preview-img" src="${e.target.result}" style="width:100%;border-radius:12px;border:1px solid var(--border)"><button type="button" onclick="clearImg()" class="img-close-btn">✕</button>`;
            wrap.style.display = 'block';
        };
        r.readAsDataURL(file);
    }
}
function clearImg() {
    document.getElementById('img-input').value = '';
    const wrap = document.getElementById('img-preview-wrap');
    wrap.style.display = 'none';
    wrap.innerHTML = '';
}

async function toggleLike(pid, btn) {
    try {
        const fd = new FormData(); fd.append('action','toggle_like'); fd.append('post_id',pid);
        const r = await fetch(API, {method:'POST',body:fd}); const d = await r.json();
        if (d.count !== undefined) {
            btn.querySelector('.like-count').textContent = d.count;
            btn.classList.toggle('liked', d.liked);
            btn.querySelector('svg').setAttribute('fill', d.liked ? 'currentColor' : 'none');
        }
    } catch (e) {
        apexLogError('toggleLike failed', e, { postId: pid });
    }
}

async function toggleComments(pid) {
    const s = document.getElementById('comments-'+pid);
    if (s.classList.contains('open')) { s.classList.remove('open'); return; }
    s.classList.add('open');
    await loadComments(pid);
}

async function loadComments(pid) {
    let d;
    try {
        const r = await fetch(API+'?action=load_comments&post_id='+pid);
        d = await r.json();
    } catch (e) {
        apexLogError('loadComments failed', e, { postId: pid });
        return;
    }
    const list = document.getElementById('comments-list-'+pid);
    if (!d.comments || d.comments.length === 0) {
        list.innerHTML = '<div class="comment-empty">No comments yet</div>';
    } else {
        list.innerHTML = d.comments.map(c => `
            <div class="comment-item" id="comment-${c.id}">
                <div class="comment-av" style="background:${c.avatar_color||'#4f46e5'}">${c.initials}</div>
                <div class="comment-bubble">
                    <div class="comment-author">@${c.username}<span>${c.time}</span>
                        ${!c.is_mine ? `<button onclick="openReport('comment',${c.id})" class="comment-del" title="Report"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/></svg></button>` : ''}
                        ${c.is_mine ? `<button onclick="deleteComment(${c.id},${pid})" class="comment-del" title="Delete">✕</button>` : ''}
                    </div>
                    <div class="comment-text">${c.content}</div>
                </div>
            </div>
        `).join('');
    }
}

async function addComment(pid) {
    const input   = document.getElementById('comment-input-'+pid);
    const content = input.value.trim();
    if (!content) return;
    const fd = new FormData();
    fd.append('action','add_comment'); fd.append('post_id',pid); fd.append('content',content);
    let d;
    try {
        const r = await fetch(API, {method:'POST',body:fd});
        d = await r.json();
    } catch (e) {
        apexLogError('addComment request failed', e, { postId: pid });
        alert('Network error. Please try again.');
        return;
    }
    if (d.error === 'offline') {
        alert('Detection system is currently inactive. Posting is temporarily unavailable. Please try again later.');
        return;
    }
    if (d.error === 'forbidden') { alert('Comment blocked: '+(d.reason||'Content not allowed.')); return; }
    if (d.error) { alert(d.error); return; }
    if (d.success) { input.value = ''; await loadComments(pid); }
}

async function deleteComment(cid, pid) {
    if (!confirm('Delete this comment?')) return;
    const fd = new FormData(); fd.append('action','delete_comment'); fd.append('comment_id',cid);
    let d;
    try { const r = await fetch(API, {method:'POST',body:fd}); d = await r.json(); }
    catch (e) {
        apexLogError('deleteComment request failed', e, { commentId: cid, postId: pid });
        return;
    }
    if (d.success) {
        const el = document.getElementById('comment-'+cid);
        if (el) el.remove();
    } else { alert(d.error || 'Could not delete.'); }
}

function openRepost(pid) {
    const postEl   = document.getElementById('post-'+pid);
    const textEl   = postEl.querySelector('.post-text');
    const authorEl = postEl.querySelector('.post-aname');
    document.getElementById('repost-orig-preview').innerHTML =
        '<div class="ro-author">' + (authorEl ? authorEl.textContent : '') + '</div>' +
        (textEl ? textEl.textContent.substring(0, 200) : '');
    document.getElementById('repost-post-id').value    = pid;
    document.getElementById('repost-comment').value    = '';
    document.getElementById('repost-modal').classList.add('open');
}
function closeRepost() { document.getElementById('repost-modal').classList.remove('open'); }

async function submitRepost() {
    const pid     = document.getElementById('repost-post-id').value;
    const comment = document.getElementById('repost-comment').value.trim();
    const fd = new FormData();
    fd.append('action','repost'); fd.append('post_id',pid); fd.append('comment',comment);
    let d;
    try { const r = await fetch(API, {method:'POST',body:fd}); d = await r.json(); }
    catch (e) {
        apexLogError('submitRepost request failed', e, { postId: pid });
        closeRepost();
        return;
    }
    closeRepost();
    if (d.error === 'already_reposted') { alert('You already reposted this.'); return; }
    if (d.error === 'offline') {
        alert('Detection system is currently inactive. Posting is temporarily unavailable. Please try again later.');
        return;
    }
    if (d.error) { alert(d.error); return; }
    if (d.success) { alert('Repost published!'); location.reload(); }
}

function openReport(type, id) {
    document.getElementById('report-type').value = type;
    document.getElementById('report-id').value   = id;
    document.querySelectorAll('[name="rr"]').forEach(cb => cb.checked = false);
    document.getElementById('report-desc').value = '';
    document.getElementById('report-modal').classList.add('open');
}
function closeReport() { document.getElementById('report-modal').classList.remove('open'); }

async function submitReport() {
    const type    = document.getElementById('report-type').value;
    const id      = document.getElementById('report-id').value;
    const reasons = Array.from(document.querySelectorAll('[name="rr"]:checked')).map(cb => cb.value);
    const desc    = document.getElementById('report-desc').value.trim();
    if (reasons.length === 0) { alert('Please select at least one reason.'); return; }
    const fd = new FormData();
    fd.append('action','report_content'); fd.append('content_type',type); fd.append('content_id',id);
    fd.append('reasons',reasons.join(',')); fd.append('description',desc);
    let d;
    try { const r = await fetch(API, {method:'POST',body:fd}); d = await r.json(); }
    catch (e) {
        apexLogError('submitReport request failed', e, { type, id });
        closeReport();
        return;
    }
    closeReport();
    if (d.error === 'already_reported') { alert('Already reported.'); return; }
    if (d.error) { alert(d.error); return; }
    alert(d.message || 'Report submitted.');
}

async function deletePost(pid) {
    if (!confirm('Delete this post?')) return;
    try {
        const fd = new FormData(); fd.append('action','delete_post'); fd.append('post_id',pid);
        const r = await fetch(API, {method:'POST',body:fd}); const d = await r.json();
        if (d.success) document.querySelector('[data-post-id="'+pid+'"]').remove();
    } catch (e) {
        apexLogError('deletePost request failed', e, { postId: pid });
    }
}

async function addFriend(uid, btn) {
    try {
        const fd = new FormData(); fd.append('action','friend_request'); fd.append('target_id',uid);
        const r = await fetch(API, {method:'POST',body:fd}); const d = await r.json();
        if (d.success) {
            btn.textContent = d.new_status === 'sent' ? 'Sent' : 'Add';
            btn.classList.toggle('sent', d.new_status === 'sent');
        }
    } catch (e) {
        apexLogError('addFriend request failed', e, { userId: uid });
    }
}

(function () {
    const input   = document.getElementById('search-input');
    const results = document.getElementById('search-results');
    if (!input || !results) return;
    let t = null;
    input.addEventListener('input', () => {
        clearTimeout(t);
        if (!input.value.trim()) {
            results.style.display = 'none';
            if ('hidden' in results) results.hidden = true;
            return;
        }
        t = setTimeout(async () => {
            try {
                const fd = new FormData(); fd.append('action','search'); fd.append('q',input.value.trim());
                const r = await fetch(API, {method:'POST',body:fd}); const d = await r.json();
                if (!d.users || !d.users.length) {
                    results.innerHTML = '<div class="search-empty">No users found</div>';
                } else {
                    const base = typeof window.APEX_BASE !== 'undefined' ? window.APEX_BASE : '';
                    results.innerHTML = d.users.map(u =>
                        `<a href="${base}/pages/profile.php?user=${encodeURIComponent(u.username)}" class="search-row">
                            <div class="search-av" style="background:${u.avatar_color||'#4f46e5'}">${u.initials}</div>
                            <div><div class="search-name">${u.full_name||u.username}</div><div class="search-handle">@${u.username}</div></div>
                        </a>`
                    ).join('');
                }
                results.style.display = 'block';
                if ('hidden' in results) results.hidden = false;
            } catch (e) {
                apexLogError('search request failed', e, { query: input.value.trim() });
            }
        }, 250);
    });
    document.addEventListener('click', e => {
        if (!input.contains(e.target) && !results.contains(e.target)) {
            results.style.display = 'none';
            if ('hidden' in results) results.hidden = true;
        }
    });
})();

(function () {
    function setTheme(next) {
        document.documentElement.setAttribute('data-theme', next);
        try { localStorage.setItem('apex-theme', next); } catch (e) {}
    }
    try {
        const saved = localStorage.getItem('apex-theme');
        if (saved === 'dark' || saved === 'light') setTheme(saved);
    } catch (e) {}
    document.getElementById('theme-toggle')?.addEventListener('click', function () {
        const cur = document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
        setTheme(cur === 'dark' ? 'light' : 'dark');
    });

    const burger = document.getElementById('nav-burger');
    const overlay = document.getElementById('nav-drawer-overlay');
    const drawer = document.getElementById('nav-drawer');
    const closeBtn = document.getElementById('nav-drawer-close');
    function setOpen(open) {
        document.body.classList.toggle('nav-mobile-open', open);
        if (burger) burger.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (overlay) overlay.setAttribute('aria-hidden', open ? 'false' : 'true');
        if (drawer) drawer.setAttribute('aria-hidden', open ? 'false' : 'true');
    }
    burger?.addEventListener('click', function () {
        setOpen(!document.body.classList.contains('nav-mobile-open'));
    });
    overlay?.addEventListener('click', function () { setOpen(false); });
    closeBtn?.addEventListener('click', function () { setOpen(false); });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') setOpen(false);
    });
    window.addEventListener('resize', function () {
        if (window.innerWidth > 767) setOpen(false);
    });

    document.querySelectorAll('a.nav-top-link, a.nav-drawer-link[href]').forEach(function (a) {
        a.addEventListener('click', function () {
            document.body.classList.add('shell-exit');
            window.setTimeout(function () { document.body.classList.remove('shell-exit'); }, 220);
        });
    });
})();

// Real-time UI (toasts, badges) via ApexRealtime
(function () {
    'use strict';

    const user = window.APEX_USER;
    if (!user || !user.userId || !window.ApexRealtime) {
        return;
    }

    const base = (window.APEX_BASE || '').replace(/\/$/, '');
    const isAdmin = !!user.isAdmin;
    const TOAST_TTL = 4000;
    const MAX_TOASTS = 4;

    // Toast UI
    function injectToastStyles() {
        if (document.getElementById('apex-toast-styles')) {
            return;
        }
        const style = document.createElement('style');
        style.id = 'apex-toast-styles';
        style.textContent = `
#apex-toasts{position:fixed;bottom:20px;right:20px;z-index:10000;display:flex;flex-direction:column;gap:10px;max-width:min(360px,calc(100vw - 32px));pointer-events:none}
.apex-toast{pointer-events:auto;padding:12px 16px;border-radius:10px;font-size:13px;line-height:1.45;color:#fff;box-shadow:0 8px 24px rgba(0,0,0,.18);opacity:1;transform:translateX(0);transition:opacity .35s ease,transform .35s ease}
.apex-toast.apex-toast-out{opacity:0;transform:translateX(12px)}
.apex-toast a{color:inherit;text-decoration:underline;font-weight:600}
.apex-toast-success{background:#059669}
.apex-toast-error{background:#dc2626}
.apex-toast-info{background:#2563eb}
.apex-toast-warning{background:#d97706}
.signalr-status-dot{width:8px;height:8px;border-radius:50%;background:#ef4444;flex-shrink:0;margin-right:6px;display:inline-block}
.signalr-status-dot.connected{background:#10b981}
`;
        document.head.appendChild(style);
    }

    function getToastContainer() {
        let el = document.getElementById('apex-toasts');
        if (!el) {
            el = document.createElement('div');
            el.id = 'apex-toasts';
            el.setAttribute('aria-live', 'polite');
            document.body.appendChild(el);
        }
        return el;
    }

    function showToast(message, type = 'info', options = {}) {
        injectToastStyles();
        const container = getToastContainer();
        while (container.children.length >= MAX_TOASTS) {
            container.firstElementChild?.remove();
        }

        const toast = document.createElement('div');
        toast.className = `apex-toast apex-toast-${type}`;
        if (options.html) {
            toast.innerHTML = options.html;
        } else {
            toast.textContent = message;
        }
        container.appendChild(toast);

        window.setTimeout(() => {
            toast.classList.add('apex-toast-out');
            window.setTimeout(() => toast.remove(), 350);
        }, TOAST_TTL);
    }

    // --- Notification badge counters ---
    function bumpNotifBadge(delta = 1) {
        const badges = document.querySelectorAll('#notif-count, .notif-badge, .nav-top-badge');
        if (badges.length) {
            badges.forEach((el) => {
                const n = (parseInt(el.textContent, 10) || 0) + delta;
                el.textContent = String(n);
                el.style.display = '';
            });
            return;
        }
        document.querySelectorAll('a[href*="notifications"]').forEach((link) => {
            let badge = link.querySelector('.nav-top-badge, .notif-badge');
            if (!badge) {
                badge = document.createElement('span');
                badge.className = 'nav-top-badge notif-badge';
                badge.id = 'notif-count';
                link.appendChild(badge);
            }
            badge.textContent = String(delta);
        });
    }

    function bumpQueueBadge(delta = 1) {
        document.querySelectorAll('a[href*="queue.php"] .sb-badge').forEach((el) => {
            el.textContent = String((parseInt(el.textContent, 10) || 0) + delta);
        });
        const queueLink = document.querySelector('a[href*="queue.php"]');
        if (queueLink && !queueLink.querySelector('.sb-badge')) {
            const badge = document.createElement('span');
            badge.className = 'sb-badge';
            badge.textContent = String(delta);
            queueLink.appendChild(badge);
        }
    }

    // --- Feed refresh after post approval (no full reload) ---
    async function refreshFeedPosts() {
        const feed =
            document.getElementById('feed') ||
            document.querySelector('.feed') ||
            (document.getElementById('post-form') ? document.querySelector('.col-main') : null);
        if (!feed) {
            return;
        }

        try {
            const res = await fetch(`${base}/index.php`, { credentials: 'same-origin' });
            if (!res.ok) {
                return;
            }
            const html = await res.text();
            const doc = new DOMParser().parseFromString(html, 'text/html');
            const newMain = doc.querySelector('.col-main');
            if (!newMain) {
                return;
            }

            feed.querySelectorAll('.post-card, .empty').forEach((el) => el.remove());

            const anchor = feed.querySelector('.feed-filters') || feed.querySelector('.composer');
            if (!anchor) {
                return;
            }

            let insertAfter = anchor;
            newMain.querySelectorAll('.post-card, .empty').forEach((node) => {
                const clone = document.importNode(node, true);
                insertAfter.insertAdjacentElement('afterend', clone);
                insertAfter = clone;
            });
        } catch (e) {
            apexLogError('refreshFeedPosts failed', e);
        }
    }

    function handleModerationResult(payload) {
        const action = (payload.action || '').toLowerCase();
        const reason = payload.reason || '';
        const msg = payload.msg || '';

        if (action === 'approve') {
            showToast(msg || 'Your post was approved ✓', 'success');
            if (payload.type === 'comment' && payload.postId && typeof loadComments === 'function') {
                const section = document.getElementById(`comments-${payload.postId}`);
                if (section?.classList.contains('open')) {
                    loadComments(payload.postId);
                }
            } else if (payload.type === 'post') {
                refreshFeedPosts();
            }
        } else if (action === 'reject') {
            const text = msg || (reason ? `Your content was rejected ✗ ${reason}` : 'Your content was rejected ✗');
            showToast(text, 'error');
        } else {
            showToast(msg || 'Moderation update received', 'info');
        }
    }

    ApexRealtime.on('Notification', (payload) => {
        const text = payload?.msg || payload?.message || 'New notification';
        showToast(text, 'info');
        bumpNotifBadge(1);
    });

    if (isAdmin) {
        ApexRealtime.on('QueueUpdate', () => {
            document.querySelectorAll('a[href*="queue.php"] .sb-badge').forEach((el) => {
                const n = parseInt(el.textContent, 10) || 0;
                if (n > 0) {
                    el.textContent = String(Math.max(0, n - 1));
                }
            });
        });
    }

    ApexRealtime.on('ModerationResult', (payload) => {
        handleModerationResult(payload || {});
    });

    ApexRealtime.on('Banned', (payload) => {
        const reason = payload?.reason || 'banned';
        let encoded;
        try {
            encoded = encodeURIComponent(btoa(unescape(encodeURIComponent(reason))));
        } catch (e) {
        apexLogWarn('Failed to encode ban reason, using fallback', e);
            encoded = encodeURIComponent(btoa('banned'));
        }
        window.location.href = `${base}/pages/banned.php?r=${encoded}`;
    });
})();
