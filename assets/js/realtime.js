// WebSocket client for ws_server.py (:8080)
(function (global) {
    'use strict';

    function apexLogError(context, error, extra) {
        console.error('[ApexSocial] ' + context, error, extra || '');
    }

    function apexLogWarn(context, extra) {
        console.warn('[ApexSocial] ' + context, extra || '');
    }

    const user = global.APEX_USER;
    if (!user || !user.userId) {
        global.ApexRealtime = { connected: false, isConnected: () => false };
        return;
    }

    function resolveWsUrl() {
        if (global.APEX_WS_URL) {
            return global.APEX_WS_URL;
        }
        const host = global.location.hostname || '127.0.0.1';
        const proto = global.location.protocol === 'https:' ? 'wss:' : 'ws:';
        return `${proto}//${host}:8080`;
    }

    const wsUrl = resolveWsUrl();
    const listeners = new Map();
    let socket = null;
    let heartbeatTimer = null;
    let reconnectAttempt = 0;
    let reconnectTimer = null;
    let joined = false;
    let intentionalClose = false;
    let lastPongAt = 0;
    let pongCheckTimer = null;

    let previewPendingText = '';
    let previewSeq = 0;
    let previewWaiters = [];

    function emit(event, payload) {
        const handlers = listeners.get(event);
        if (!handlers) return;
        handlers.forEach((fn) => {
            try { fn(payload); } catch (e) { apexLogError('Listener callback failed', e, { event }); }
        });
    }

    function on(event, handler) {
        if (!listeners.has(event)) listeners.set(event, new Set());
        listeners.get(event).add(handler);
        return () => listeners.get(event)?.delete(handler);
    }

    function setStatusDot(connected) {
        const dot = document.getElementById('signalr-status-dot');
        if (!dot) return;
        dot.classList.toggle('connected', connected);
        dot.title = connected ? 'Real-time connected' : 'Real-time disconnected';
    }

    function getPreviewStatusEl() {
        const ta = document.getElementById('post-content');
        if (!ta) return null;
        let el = document.getElementById('rt-ml-preview-status');
        if (!el) {
            el = document.createElement('div');
            el.id = 'rt-ml-preview-status';
            el.className = 'rt-ml-preview-status';
            el.setAttribute('aria-live', 'polite');
            el.style.cssText = 'font-size:12px;color:#64748b;margin-top:6px;min-height:18px;';
            if (ta.parentNode) {
                ta.parentNode.insertBefore(el, ta.nextSibling);
            }
        }
        return el;
    }

    function setPreviewStatus(text, stateClass) {
        const el = getPreviewStatusEl();
        if (!el) return;
        if (!text) {
            el.textContent = '';
            el.style.display = 'none';
            el.className = 'rt-ml-preview-status';
            return;
        }
        el.textContent = text;
        el.style.display = 'block';
        el.className = 'rt-ml-preview-status' + (stateClass ? ' rt-ml-' + stateClass : '');
        if (stateClass === 'forbidden') {
            el.style.color = '#dc2626';
        } else if (stateClass === 'allowed') {
            el.style.color = '#16a34a';
        } else {
            el.style.color = '#64748b';
        }
    }

    function clearPreviewStatus() {
        setPreviewStatus('', '');
    }

    function resolvePreviewWaiters(payload) {
        const waiters = previewWaiters.slice();
        previewWaiters = [];
        waiters.forEach((w) => {
            try { w.resolve(payload); } catch (e) { apexLogError('Preview waiter resolve failed', e); }
        });
    }

    function cancelPreview() {
        previewSeq += 1;
        resolvePreviewWaiters(null);
        clearPreviewStatus();
    }

    function showVerdict(payload) {
        if (!payload) return;
        const v = String(payload.verdict || 'ALLOWED').toUpperCase();
        const prob = payload.harmful_prob != null
            ? ` (${Number(payload.harmful_prob).toFixed(1)}%)`
            : '';
        if (v === 'FORBIDDEN' || v === 'REVIEW') {
            const cat = payload.category === 'hate_speech'
                ? 'Hate speech'
                : (payload.category === 'phishing_scam' ? 'Scam/phishing' : 'Policy');
            setPreviewStatus(`Forbidden: ${cat}${prob}`, 'forbidden');
            return;
        }
        if (v === 'OFFLINE') {
            setPreviewStatus('Detection system offline', 'offline');
            return;
        }
        setPreviewStatus('Allowed — content passed the check', 'allowed');
    }

    function sendPreviewModeration(text) {
        const trimmed = (text || '').trim();
        if (trimmed.length < 2) {
            cancelPreview();
            resolvePreviewWaiters({ verdict: 'ALLOWED', harmful_prob: 0, category: 'safe' });
            return;
        }

        previewPendingText = trimmed;
        previewSeq += 1;
        const seq = previewSeq;

        if (!socket || socket.readyState !== WebSocket.OPEN || !joined) {
            setPreviewStatus('Real-time preview unavailable', 'offline');
            resolvePreviewWaiters(null);
            return;
        }

        setPreviewStatus('Checking...', 'checking');

        let settled = false;
        const finish = (payload) => {
            if (settled || seq !== previewSeq) return;
            settled = true;
            off();
            if (payload) {
                showVerdict(payload);
            }
            resolvePreviewWaiters(payload);
        };

        const off = on('LiveModeration', finish);
        if (!sendJson({ type: 'preview_moderation', text: trimmed })) {
            off();
            setPreviewStatus('Real-time preview unavailable', 'offline');
            finish(null);
            return;
        }
        setTimeout(() => finish(null), 6000);
    }

    function scheduleReconnect() {
        if (reconnectTimer || intentionalClose) return;
        reconnectAttempt += 1;
        const delay = Math.min(30000, 1000 * Math.pow(2, reconnectAttempt));
        reconnectTimer = setTimeout(() => {
            reconnectTimer = null;
            start();
        }, delay);
    }

    function stopHeartbeat() {
        if (heartbeatTimer) {
            clearInterval(heartbeatTimer);
            heartbeatTimer = null;
        }
        if (pongCheckTimer) {
            clearTimeout(pongCheckTimer);
            pongCheckTimer = null;
        }
    }

    function sendJson(obj) {
        if (!socket || socket.readyState !== WebSocket.OPEN) return false;
        try {
            socket.send(JSON.stringify(obj));
            return true;
        } catch (e) {
            apexLogError('WebSocket send failed', e, obj);
            return false;
        }
    }

    function sendJoin() {
        sendJson({
            type: 'join',
            user_id: user.userId,
            token: user.wsToken || '',
        });
    }

    function startHeartbeat() {
        stopHeartbeat();
        heartbeatTimer = setInterval(() => {
            if (!socket || socket.readyState !== WebSocket.OPEN) return;
            sendJson({ type: 'ping' });
            if (pongCheckTimer) {
                clearTimeout(pongCheckTimer);
            }
            pongCheckTimer = setTimeout(() => {
                if (!socket || socket.readyState !== WebSocket.OPEN) return;
                if (Date.now() - lastPongAt > 31000) {
                    apexLogWarn('Pong timeout, reconnecting socket');
                    try {
                        socket.close();
                    } catch (e) {
                        apexLogError('Socket close after pong timeout failed', e);
                    }
                    scheduleReconnect();
                }
            }, 5000);
        }, 25000);
    }

    function handleMessage(msg) {
        const type = (msg.type || '').toLowerCase();

        switch (type) {
            case 'joined':
                joined = true;
                emit('joined', msg);
                break;
            case 'pong':
                lastPongAt = Date.now();
                emit('pong', msg);
                break;
            case 'notification':
                emit('Notification', msg.payload || msg);
                break;
            case 'moderation_result':
                emit('ModerationResult', msg.payload || msg);
                break;
            case 'queue_update':
                emit('QueueUpdate', msg.payload || msg);
                break;
            case 'banned':
                emit('Banned', msg.payload || msg);
                break;
            case 'live_moderation':
                emit('LiveModeration', msg);
                break;
            default:
                apexLogWarn('Unknown WebSocket message type', type);
                break;
        }
    }

    function connect() {
        return new Promise((resolve) => {
            if (socket && (socket.readyState === WebSocket.OPEN || socket.readyState === WebSocket.CONNECTING)) {
                resolve(socket.readyState === WebSocket.OPEN);
                return;
            }

            joined = false;
            intentionalClose = false;

            try {
                socket = new WebSocket(wsUrl);
            } catch (_) {
                setStatusDot(false);
                scheduleReconnect();
                resolve(false);
                return;
            }

            socket.onopen = () => {
                try {
                    lastPongAt = Date.now();
                    sendJoin();
                    reconnectAttempt = 0;
                    setStatusDot(true);
                    startHeartbeat();
                    emit('connected', {});
                    resolve(true);
                } catch (e) {
                    apexLogError('WebSocket onopen handler failed', e);
                    resolve(false);
                }
            };

            socket.onmessage = (ev) => {
                try {
                    const msg = JSON.parse(ev.data);
                    if (msg && typeof msg === 'object') {
                        handleMessage(msg);
                    }
                } catch (e) {
                    apexLogWarn('WebSocket message handling failed', { error: e, data: ev?.data });
                }
            };

            socket.onerror = () => {
                try {
                    setStatusDot(false);
                } catch (e) {
                    apexLogError('WebSocket onerror handler failed', e);
                }
            };

            socket.onclose = () => {
                try {
                    joined = false;
                    setStatusDot(false);
                    stopHeartbeat();
                    emit('disconnected', {});
                    if (!intentionalClose) {
                        scheduleReconnect();
                    }
                    resolve(false);
                } catch (e) {
                    apexLogError('WebSocket onclose handler failed', e);
                    resolve(false);
                }
            };
        });
    }

    async function start() {
        return connect();
    }

    function previewModeration(text) {
        return new Promise((resolve) => {
            const trimmed = (text || '').trim();
            if (trimmed.length < 2) {
                cancelPreview();
                resolve({ verdict: 'ALLOWED', harmful_prob: 0, category: 'safe' });
                return;
            }

            if (previewWaiters.length >= 1) {
                const prev = previewWaiters[previewWaiters.length - 1];
                if (prev && typeof prev.resolve === 'function') {
                    try { prev.resolve(null); } catch (e) { apexLogError('Preview waiter cleanup failed', e); }
                }
                previewWaiters = [];
            }
            previewWaiters.push({ resolve, text: trimmed });
            sendPreviewModeration(trimmed);
        });
    }

    function isConnected() {
        return !!(socket && socket.readyState === WebSocket.OPEN && joined);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }

    global.ApexRealtime = {
        on,
        emit,
        start,
        previewModeration,
        cancelPreview,
        isConnected,
        get state() {
            if (!socket) return 'Disconnected';
            if (socket.readyState === WebSocket.CONNECTING) return 'Connecting';
            if (socket.readyState === WebSocket.OPEN && joined) return 'Connected';
            if (socket.readyState === WebSocket.OPEN) return 'Open';
            return 'Disconnected';
        },
    };
})(window);
