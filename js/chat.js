/**
 * Sticky chat: POST to Mercury API via api/chat.php, show response as notification; click opens modal.
 * Supports a queue: multiple prompts can be sent; if one is executing, new ones are queued.
 */
(function () {
    var $input = $('#chat-input');
    var $send = $('#chat-send');
    var $stop = $('#chat-stop');
    var $notifications = $('#notifications');
    var $modalBody = $('#response-modal-body');
    var $queueWrap = $('#chat-queue-wrap');
    var $queueHeader = $('#chat-queue-header');
    var $queueToggle = $('#chat-queue-toggle');
    var $queueCount = $('#chat-queue-count');
    var $queueList = $('#chat-queue-list');
    if (!$input.length || !$send.length) return;
    var RECENT_ACTIVITY_HOLD_MS = 1800;
    var fullResponses = {};
    var modalInstance = null;
    var statusPollHandle = null;
    var adhocStatusPollHandle = null;
    var stopPollingTimeout = null;
    var currentRequest = null;
    var wasStopped = false;
    var lastGraphRefreshToken = '';
    var lastAdhocGraphRefreshToken = '';
    var promptQueue = [];
    var BUNDLE_KEY = 'memoryGraphSessionsBundleV1';
    var LEGACY_TURNS_KEY = 'memoryGraphChatTurnsV1';
    var CHAT_SESSION_KEY = 'memoryGraphChatSessionIdV1';
    var MAX_STORED_TURNS = 40;
    var sessionBundle = null;
    var inFlightMainRequestId = '';
    var inFlightMainSessionId = '';
    var adhocStatusTargetSessionId = '';
    var lastActivityTailTBySession = {};
    var LONG_CHAT_AJAX_MS = 600000;
    var fishAudioState = {
        loaded: false,
        enabled: true,
        muted: true,
        autoSpeak: true,
        settings: null
    };
    var fishAudioElement = null;
    var fishAudioListeners = [];

    function notifyFishAudioState() {
        fishAudioListeners.forEach(function (cb) {
            try { cb(window.MemoryGraphFishAudioGetState()); } catch (e) {}
        });
    }

    function stopFishAudioPlayback() {
        if (!fishAudioElement) return;
        try {
            fishAudioElement.pause();
            fishAudioElement.currentTime = 0;
        } catch (e) {}
    }

    function loadFishAudioSettings() {
        return fetch('api/fish_audio_settings.php', { credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : Promise.reject(new Error('HTTP ' + r.status)); })
            .then(function (data) {
                var s = data && data.settings ? data.settings : {};
                fishAudioState.loaded = true;
                fishAudioState.enabled = s.enabled !== false;
                fishAudioState.muted = !!s.muted;
                fishAudioState.autoSpeak = s.autoSpeak !== false;
                fishAudioState.settings = s;
                notifyFishAudioState();
                return s;
            });
    }

    function saveFishAudioSettings(partial) {
        var body = partial && typeof partial === 'object' ? partial : {};
        return fetch('api/fish_audio_settings.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        })
            .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
            .then(function (x) {
                if (!x.ok || !x.j || x.j.error) {
                    throw new Error((x.j && x.j.error) ? x.j.error : 'Save failed');
                }
                var s = x.j.settings || {};
                fishAudioState.loaded = true;
                fishAudioState.enabled = s.enabled !== false;
                fishAudioState.muted = !!s.muted;
                fishAudioState.autoSpeak = s.autoSpeak !== false;
                fishAudioState.settings = s;
                if (fishAudioState.muted) {
                    stopFishAudioPlayback();
                }
                notifyFishAudioState();
                return s;
            });
    }

    function fishAudioSpeakText(text, opts) {
        var spoken = String(text || '').trim();
        if (!spoken) return Promise.reject(new Error('No text to speak'));
        if (!fishAudioState.loaded) {
            return loadFishAudioSettings().then(function () { return fishAudioSpeakText(spoken, opts); });
        }
        if (!fishAudioState.enabled) return Promise.reject(new Error('Fish Audio is disabled'));
        if (fishAudioState.muted && !(opts && opts.ignoreMute)) return Promise.reject(new Error('Audio is muted'));
        return fetch('api/fish_tts.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ text: spoken })
        })
            .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
            .then(function (x) {
                if (!x.ok || !x.j || x.j.error || !x.j.audioBase64) {
                    throw new Error((x.j && x.j.error) ? x.j.error : 'TTS failed');
                }
                var mime = x.j.mimeType || 'audio/mpeg';
                var raw = atob(String(x.j.audioBase64));
                var bytes = new Uint8Array(raw.length);
                for (var i = 0; i < raw.length; i++) bytes[i] = raw.charCodeAt(i);
                var blob = new Blob([bytes], { type: mime });
                var url = URL.createObjectURL(blob);
                stopFishAudioPlayback();
                fishAudioElement = new Audio(url);
                fishAudioElement.onended = function () {
                    URL.revokeObjectURL(url);
                };
                fishAudioElement.onerror = function () {
                    URL.revokeObjectURL(url);
                };
                return fishAudioElement.play();
            });
    }

    window.MemoryGraphFishAudioGetState = function () {
        return {
            loaded: !!fishAudioState.loaded,
            enabled: !!fishAudioState.enabled,
            muted: !!fishAudioState.muted,
            autoSpeak: !!fishAudioState.autoSpeak,
            settings: fishAudioState.settings ? JSON.parse(JSON.stringify(fishAudioState.settings)) : null
        };
    };
    window.MemoryGraphFishAudioOnState = function (cb) {
        if (typeof cb !== 'function') return function () {};
        fishAudioListeners.push(cb);
        try { cb(window.MemoryGraphFishAudioGetState()); } catch (e) {}
        return function () {
            fishAudioListeners = fishAudioListeners.filter(function (fn) { return fn !== cb; });
        };
    };
    window.MemoryGraphFishAudioSaveSettings = saveFishAudioSettings;
    window.MemoryGraphFishAudioLoadSettings = loadFishAudioSettings;
    window.MemoryGraphFishAudioSpeakText = function (text) { return fishAudioSpeakText(text); };
    window.MemoryGraphFishAudioToggleMute = function () {
        var nextMuted = !fishAudioState.muted;
        return saveFishAudioSettings({ muted: nextMuted }).then(function () { return nextMuted; });
    };

    function newSessionId() {
        return 'cs_' + Date.now() + '_' + Math.random().toString(36).slice(2, 14);
    }

    function normalizeBundle(raw) {
        if (!raw || !raw.sessions || typeof raw.sessions !== 'object' || !raw.activeId) return null;
        if (!raw.sessions[raw.activeId]) {
            var ids = Object.keys(raw.sessions);
            if (ids.length) raw.activeId = ids[0];
            else return null;
        }
        if (!Array.isArray(raw.order) || !raw.order.length) {
            raw.order = Object.keys(raw.sessions);
        }
        return raw;
    }

    function loadSessionBundle() {
        try {
            var s = sessionStorage.getItem(BUNDLE_KEY);
            if (s) {
                var b = JSON.parse(s);
                b = normalizeBundle(b);
                if (b) return b;
            }
        } catch (e) {}
        var legacyTurns = [];
        try {
            var lr = sessionStorage.getItem(LEGACY_TURNS_KEY);
            if (lr) {
                var a = JSON.parse(lr);
                if (Array.isArray(a)) legacyTurns = a;
            }
        } catch (e2) {}
        var sid = '';
        try {
            sid = (sessionStorage.getItem(CHAT_SESSION_KEY) || '').trim();
        } catch (e3) {}
        if (!sid) sid = newSessionId();
        return {
            activeId: sid,
            order: [sid],
            sessions: (function () {
                var o = {};
                o[sid] = { label: 'Main', isSub: false, turns: filterPersistTurns(legacyTurns) };
                return o;
            })()
        };
    }

    function filterPersistTurns(arr) {
        var out = [];
        (Array.isArray(arr) ? arr : []).forEach(function (t) {
            if (!t || !t.role) return;
            if (t.role === 'user' || t.role === 'assistant') {
                if (typeof t.content === 'string') out.push({ role: t.role, content: t.content });
            } else if (t.role === 'activity' && t.actKind && typeof t.label === 'string') {
                out.push({ role: 'activity', actKind: t.actKind, label: t.label });
            }
        });
        if (out.length > MAX_STORED_TURNS) out = out.slice(-MAX_STORED_TURNS);
        return out;
    }

    function saveSessionBundle() {
        try {
            Object.keys(sessionBundle.sessions).forEach(function (k) {
                var sess = sessionBundle.sessions[k];
                if (!sess || !Array.isArray(sess.turns)) return;
                if (sess.turns.length > MAX_STORED_TURNS) {
                    sess.turns = sess.turns.slice(-MAX_STORED_TURNS);
                }
            });
            sessionStorage.setItem(BUNDLE_KEY, JSON.stringify(sessionBundle));
            try {
                sessionStorage.setItem(CHAT_SESSION_KEY, sessionBundle.activeId);
            } catch (e) {}
        } catch (e2) {}
    }

    sessionBundle = loadSessionBundle();

    function getTurns() {
        return sessionBundle.sessions[sessionBundle.activeId].turns;
    }

    function getActiveSessionId() {
        return sessionBundle.activeId;
    }

    function emitOpenSessionsChanged() {
        try {
            document.dispatchEvent(new CustomEvent('memoryGraphOpenSessionsChanged'));
        } catch (e) {}
        if (typeof window.MemoryGraphRefreshOpenSessionTabs === 'function') {
            window.MemoryGraphRefreshOpenSessionTabs();
        }
    }

    function ensureSessionEntry(sessionId, label, isSub) {
        sessionId = String(sessionId || '').trim();
        if (!sessionId) return;
        if (!sessionBundle.sessions[sessionId]) {
            sessionBundle.sessions[sessionId] = {
                label: label != null && String(label).trim() ? String(label).trim() : sessionId,
                isSub: !!isSub,
                turns: []
            };
            if (sessionBundle.order.indexOf(sessionId) === -1) {
                sessionBundle.order.push(sessionId);
            }
        }
    }

    function getOrCreateChatSessionId() {
        if (!getActiveSessionId() || !sessionBundle.sessions[sessionBundle.activeId]) {
            var id = newSessionId();
            sessionBundle.activeId = id;
            ensureSessionEntry(id, 'Main', false);
        }
        saveSessionBundle();
        return sessionBundle.activeId;
    }
    window.MemoryGraphGetChatSessionId = getOrCreateChatSessionId;

    function focusChatInputSoon() {
        setTimeout(function () {
            var el = document.getElementById('chat-input');
            if (!el || typeof el.focus !== 'function') return;
            try {
                el.focus({ preventScroll: true });
            } catch (e) {
                el.focus();
            }
        }, 180);
    }

    function toolDetailLooksFailed(d) {
        if (d == null) return false;
        if (typeof d !== 'object') return false;
        if (d.error) return true;
        if (d.ok === false) return true;
        return false;
    }

    function firstLineToolNameFromMessage(msg) {
        if (!msg || typeof msg !== 'string') return '';
        var s = msg.split('→')[0] || msg;
        s = s.split('\n')[0];
        return s.replace(/^\s+|\s+$/g, '').slice(0, 120);
    }

    function appendActivityBubblesToSession(targetSessionId, status) {
        if (!targetSessionId || !status || !document.documentElement.classList.contains('mg-simple-ui')) return;
        var al = Array.isArray(status.activityLog) ? status.activityLog : [];
        if (!al.length) return;
        var lastT = lastActivityTailTBySession[targetSessionId] || 0;
        var maxT = lastT;
        var changed = false;
        al.forEach(function (entry) {
            if (!entry) return;
            var ts = entry.t != null ? parseInt(entry.t, 10) : 0;
            if (ts <= lastT) return;
            if (ts > maxT) maxT = ts;
            var typ = String(entry.type || '');
            var detail = entry.detail;
            if (typ === 'tool_result') {
                var ok = !toolDetailLooksFailed(detail);
                getTurnsForSession(targetSessionId).push({
                    role: 'activity',
                    actKind: ok ? 'toolOk' : 'toolErr',
                    label: firstLineToolNameFromMessage(entry.message)
                });
                changed = true;
            } else if (typ === 'tool_call' && detail && typeof detail === 'object' && detail.mcp_parallel) {
                getTurnsForSession(targetSessionId).push({ role: 'activity', actKind: 'mcp', label: String(entry.message || 'MCP tool').split('\n')[0].slice(0, 120) });
                changed = true;
            }
        });
        if (maxT > lastT) {
            lastActivityTailTBySession[targetSessionId] = maxT;
        }
        if (changed) {
            saveSessionBundle();
            if (getActiveSessionId() === targetSessionId) {
                renderSimpleChatThread();
            }
        }
    }

    function getTurnsForSession(sid) {
        ensureSessionEntry(sid, sid, false);
        return sessionBundle.sessions[sid].turns;
    }

    function renderSimpleChatThread() {
        var $thread = $('#simple-chat-thread');
        if (!$thread.length) return;
        $thread.empty();
        var chatTurns = getTurns();
        if (!chatTurns.length) {
            $thread.append($('<p class="simple-chat-empty font-serif">').text('Message the assistant below. Your conversation is kept for this browser session.'));
            if (typeof window.MemoryGraphFeatherlessScheduleTokenize === 'function') {
                window.MemoryGraphFeatherlessScheduleTokenize();
            }
            return;
        }
        chatTurns.forEach(function (t) {
            if (!t) return;
            if (t.role === 'activity' && t.actKind && t.label) {
                var $rowA = $('<div class="simple-chat-row simple-chat-row--activity">');
                var $wrap = $('<div class="simple-activity-bubble-wrap">');
                var $b = $('<span class="simple-activity-bubble" role="status">');
                if (t.actKind === 'mcp') {
                    $b.addClass('simple-activity-bubble--mcp');
                    $b.append($('<span class="simple-activity-bubble-mcp-pill">').text(t.label));
                } else {
                    if (t.actKind === 'toolErr') {
                        $b.addClass('simple-activity-bubble--fail');
                    } else {
                        $b.addClass('simple-activity-bubble--ok');
                    }
                    var $icon = $('<span class="simple-activity-bubble-ico" aria-hidden="true">');
                    if (t.actKind === 'toolErr') {
                        $icon.addClass('simple-activity-bubble-ico--fail');
                        $icon.text('✕');
                    } else {
                        $icon.addClass('simple-activity-bubble-ico--ok');
                        $icon.text('✓');
                    }
                    $b.append($icon, $('<span class="simple-activity-bubble-lbl">').text(t.label));
                }
                $rowA.append($wrap.append($b));
                $thread.append($rowA);
                return;
            }
            if (t.role !== 'user' && t.role !== 'assistant') return;
            if (typeof t.content !== 'string') return;
            var $row = $('<div class="simple-chat-row simple-chat-row--' + t.role + '">');
            var $bubble = $('<div class="simple-chat-bubble">');
            if (t.role === 'assistant' && /^error:\s/i.test(t.content.trim())) {
                $bubble.addClass('simple-chat-bubble--error');
            }
            if (t.role === 'user') {
                $('<div class="simple-chat-text font-serif">').text(t.content).appendTo($bubble);
            } else {
                renderResponseContent($bubble, t.content);
                var $actions = $('<div class="simple-chat-actions">');
                var $speak = $('<button type="button" class="simple-chat-audio-btn" title="Speak response" aria-label="Speak response">🔊</button>');
                $speak.on('click', function () {
                    $speak.prop('disabled', true);
                    fishAudioSpeakText(t.content, { ignoreMute: true })
                        .catch(function () {})
                        .finally(function () { $speak.prop('disabled', false); });
                });
                $actions.append($speak);
                $bubble.append($actions);
            }
            $row.append($bubble);
            $thread.append($row);
        });
        var el = $thread[0];
        if (el && el.parentElement) {
            el.parentElement.scrollTop = el.parentElement.scrollHeight;
        }
        if (typeof window.MemoryGraphFeatherlessScheduleTokenize === 'function') {
            window.MemoryGraphFeatherlessScheduleTokenize();
        }
    }

    window.MemoryGraphRefreshSimpleChatThread = renderSimpleChatThread;
    window.MemoryGraphFocusChatInput = focusChatInputSoon;

    function setRequestUi(active) {
        $send.prop('disabled', active);
        if ($stop.length) $stop.prop('disabled', !active).toggle(active);
        $input.attr('placeholder', (active || promptQueue.length > 0) ? 'Add a follow-up' : 'Ask the AI...');
    }

    function renderQueue() {
        if (!promptQueue.length) {
            $queueWrap.hide();
            return;
        }
        $queueWrap.show();
        $queueCount.text(promptQueue.length + ' Queued');
        $queueList.empty();
        promptQueue.forEach(function (item, idx) {
            var $item = $('<div class="chat-queue-item" data-idx="' + idx + '">');
            var qlabel = (item.text && String(item.text).trim()) ? item.text : '(no text)';
            if (item.parts && item.parts.length) {
                qlabel += ' [+' + item.parts.length + ' attachment(s)]';
            }
            if (item.subAgent && item.subAgent.stem) {
                qlabel = '[→ ' + item.subAgent.stem + '] ' + qlabel;
            }
            $('<span class="chat-queue-item-text">').text(qlabel).appendTo($item);
            var $actions = $('<div class="chat-queue-item-actions">');
            $('<button type="button" title="Edit">&#9998;</button>').on('click', function () {
                $input.val(item.text).focus();
                if (window.__mgMainChatAttachments) {
                    if (typeof window.__mgMainChatAttachments.setParts === 'function') {
                        window.__mgMainChatAttachments.setParts(item.parts && item.parts.length ? item.parts : []);
                    } else if (typeof window.__mgMainChatAttachments.clear === 'function' && (!item.parts || !item.parts.length)) {
                        window.__mgMainChatAttachments.clear();
                    }
                    if (typeof window.__mgMainChatAttachments.setSubAgentRef === 'function') {
                        window.__mgMainChatAttachments.setSubAgentRef(item.subAgent || null);
                    }
                }
                removeFromQueue(idx);
                renderQueue();
            }).appendTo($actions);
            $('<button type="button" title="Move up">&#9650;</button>').on('click', function () {
                if (idx > 0) {
                    var t = promptQueue[idx];
                    promptQueue[idx] = promptQueue[idx - 1];
                    promptQueue[idx - 1] = t;
                    renderQueue();
                }
            }).appendTo($actions);
            $('<button type="button" title="Remove">&#128465;</button>').on('click', function () {
                removeFromQueue(idx);
                renderQueue();
            }).appendTo($actions);
            $item.append($actions);
            $queueList.append($item);
        });
    }

    function addToQueue(text, parts, subRef) {
        var p = Array.isArray(parts) ? parts.slice() : [];
        var s = (subRef && subRef.stem) ? { stem: subRef.stem, label: subRef.label || subRef.stem } : null;
        promptQueue.push({ text: text != null ? text : '', parts: p, subAgent: s, id: Date.now() + '_' + Math.random().toString(36).slice(2) });
        renderQueue();
        setRequestUi(!!currentRequest);
    }

    function removeFromQueue(idx) {
        promptQueue.splice(idx, 1);
        renderQueue();
        setRequestUi(!!currentRequest);
    }

    function shiftNextFromQueue() {
        if (!promptQueue.length) return null;
        return promptQueue.shift();
    }

    function processNextInQueue() {
        var next = shiftNextFromQueue();
        renderQueue();
        setRequestUi(!!currentRequest);
        if (next) {
            var nt = next.text != null ? next.text : '';
            var np = next.parts || [];
            var sref = next.subAgent && next.subAgent.stem ? next.subAgent : null;
            setTimeout(function () { sendMessageInternal(nt, np, sref); }, 100);
        }
    }

    function signalGraphActivity(sections, nodeIds, durationMs) {
        if (typeof window.MemoryGraphSignalActivity !== 'function') return;
        window.MemoryGraphSignalActivity({
            sections: Array.isArray(sections) ? sections : [],
            nodeIds: Array.isArray(nodeIds) ? nodeIds : [],
            durationMs: durationMs || 2400
        });
    }

    function stopStatusPolling() {
        if (stopPollingTimeout) {
            clearTimeout(stopPollingTimeout);
            stopPollingTimeout = null;
        }
        if (statusPollHandle) {
            clearInterval(statusPollHandle);
            statusPollHandle = null;
        }
        if (typeof window.agentState !== 'undefined') {
            window.agentState.setThinking(false);
            window.agentState.setGettingAvailTools(false);
            window.agentState.setCheckingMemory(false);
            window.agentState.setCheckingInstructions(false);
            window.agentState.setCheckingMcps(false);
            window.agentState.setCheckingJobs(false);
            window.agentState.setActiveToolIds([]);
            window.agentState.setActiveMemoryIds([]);
            window.agentState.setActiveInstructionIds([]);
            window.agentState.setActiveMcpIds([]);
            window.agentState.setActiveJobIds([]);
            window.agentState.setActiveResearchIds([]);
            window.agentState.setActiveRulesIds([]);
            if (typeof window.agentState.setActiveSubAgentIds === 'function') window.agentState.setActiveSubAgentIds([]);
            window.agentState.setExecutionDetailsByNode({});
            if (typeof window.agentState.setMemoryToolExecuting === 'function') window.agentState.setMemoryToolExecuting(false);
            if (typeof window.agentState.setToolExecuting === 'function') window.agentState.setToolExecuting(false);
            if (typeof window.agentState.setInstructionToolExecuting === 'function') window.agentState.setInstructionToolExecuting(false);
            if (typeof window.agentState.setAccessingMemoryFile === 'function') window.agentState.setAccessingMemoryFile(false);
        }
        if (typeof window.MemoryGraphUpdateExecutionPanel === 'function') window.MemoryGraphUpdateExecutionPanel();
    }

    function inferStatusGraphState(status) {
        var st = status || {};
        if (st.fileExists === false) {
            return null;
        }
        var executionDetails = st.executionDetailsByNode ? st.executionDetailsByNode : {};
        var inferredToolIds = Array.isArray(st.activeToolIds) ? st.activeToolIds.slice() : [];
        var inferredMemoryIds = Array.isArray(st.activeMemoryIds) ? st.activeMemoryIds.slice() : [];
        var inferredInstructionIds = Array.isArray(st.activeInstructionIds) ? st.activeInstructionIds.slice() : [];
        var inferredResearchIds = Array.isArray(st.activeResearchIds) ? st.activeResearchIds.slice() : [];
        var inferredRulesIds = Array.isArray(st.activeRulesIds) ? st.activeRulesIds.slice() : [];
        var inferredMcpIds = Array.isArray(st.activeMcpIds) ? st.activeMcpIds.slice() : [];
        var inferredJobIds = Array.isArray(st.activeJobIds) ? st.activeJobIds.slice() : [];
        var inferredSubAgentIds = Array.isArray(st.activeSubAgentIds) ? st.activeSubAgentIds.slice() : [];
        Object.keys(executionDetails).forEach(function (key) {
            if (key.indexOf('tool_') === 0 && inferredToolIds.indexOf(key) === -1) inferredToolIds.push(key);
            if (key.indexOf('memory_file_') === 0 && inferredMemoryIds.indexOf(key) === -1) inferredMemoryIds.push(key);
            if (key.indexOf('instruction_file_') === 0 && inferredInstructionIds.indexOf(key) === -1) inferredInstructionIds.push(key);
            if (key.indexOf('research_file_') === 0 && inferredResearchIds.indexOf(key) === -1) inferredResearchIds.push(key);
            if (key.indexOf('rules_file_') === 0 && inferredRulesIds.indexOf(key) === -1) inferredRulesIds.push(key);
            if (key.indexOf('mcp_server_') === 0 && inferredMcpIds.indexOf(key) === -1) inferredMcpIds.push(key);
            if ((key.indexOf('job_file_') === 0 || key.indexOf('job_cron_') === 0) && inferredJobIds.indexOf(key) === -1) inferredJobIds.push(key);
            if (key.indexOf('sub_agent_file_') === 0 && inferredSubAgentIds.indexOf(key) === -1) inferredSubAgentIds.push(key);
        });
        var inferredCheckingMcps = !!(st.checkingMcps || inferredMcpIds.length || executionDetails.mcps);
        var inferredMemoryExecution = !!(st.checkingMemory || inferredMemoryIds.length || executionDetails.memory);
        var inferredInstructionExecution = !!(st.checkingInstructions || inferredInstructionIds.length || executionDetails.instructions);
        var inferredResearchExecution = !!(st.checkingResearch || inferredResearchIds.length || executionDetails.research);
        var inferredRulesExecution = !!(st.checkingRules || inferredRulesIds.length || executionDetails.rules);
        var inferredSubAgentExecution = !!(inferredSubAgentIds.length || executionDetails.sub_agents);
        var memoryActive = !!(st.checkingMemory || inferredMemoryIds.length > 0 || st.memoryToolExecuting || st.isAccessingMemoryFile);
        var durationMs = (memoryActive && inferredMemoryIds.length > 0) || (inferredSubAgentExecution && inferredSubAgentIds.length > 0) ? 4500 : (st.thinking ? 2600 : 2200);
        var snapshot = {
            thinking: !!st.thinking,
            gettingAvailTools: !!st.gettingAvailTools,
            checkingMemory: !!st.checkingMemory,
            checkingInstructions: !!st.checkingInstructions,
            checkingResearch: inferredResearchExecution,
            checkingRules: inferredRulesExecution,
            checkingMcps: inferredCheckingMcps,
            checkingJobs: !!st.checkingJobs,
            activeToolIds: inferredToolIds,
            activeMemoryIds: inferredMemoryIds,
            activeInstructionIds: inferredInstructionIds,
            activeResearchIds: inferredResearchIds,
            activeRulesIds: inferredRulesIds,
            activeMcpIds: inferredMcpIds,
            activeJobIds: inferredJobIds,
            activeSubAgentIds: inferredSubAgentIds,
            executionDetailsByNode: executionDetails,
            isAccessingMemoryFile: !!(st.isAccessingMemoryFile || memoryActive),
            durationMs: durationMs
        };
        var background = {
            checkingJobs: snapshot.checkingJobs,
            gettingAvailTools: snapshot.gettingAvailTools,
            checkingMemory: snapshot.checkingMemory,
            checkingInstructions: snapshot.checkingInstructions,
            checkingMcps: snapshot.checkingMcps,
            activeToolIds: snapshot.activeToolIds,
            activeMemoryIds: snapshot.activeMemoryIds,
            activeInstructionIds: snapshot.activeInstructionIds,
            activeMcpIds: snapshot.activeMcpIds,
            activeSubAgentIds: snapshot.activeSubAgentIds,
            activeJobIds: snapshot.activeJobIds,
            activeResearchIds: snapshot.activeResearchIds,
            activeRulesIds: snapshot.activeRulesIds,
            executionDetailsByNode: executionDetails,
            durationMs: durationMs
        };
        return { snapshot: snapshot, background: background, durationMs: durationMs };
    }

    function applyStatusSnapshot(status) {
        var st = status || {};
        if (st.fileExists === false) return;
        var infl = inferStatusGraphState(st);
        if (!infl) return;
        if (inFlightMainSessionId) {
            appendActivityBubblesToSession(inFlightMainSessionId, st);
        }
        if (typeof window.agentState !== 'undefined' && typeof window.agentState.applySnapshotFromStatus === 'function') {
            window.agentState.applySnapshotFromStatus(infl.snapshot);
        }
        if (st.graphRefreshToken && st.graphRefreshToken !== lastGraphRefreshToken) {
            lastGraphRefreshToken = st.graphRefreshToken;
            if (typeof window.MemoryGraphRefresh === 'function') window.MemoryGraphRefresh();
        }
        if (typeof window.MemoryGraphUpdateExecutionPanel === 'function') window.MemoryGraphUpdateExecutionPanel();
        if (typeof window.SimpleUiLogFromStatus === 'function') {
            window.SimpleUiLogFromStatus(st);
        }
        if (!st.thinking && !stopPollingTimeout) {
            stopPollingTimeout = setTimeout(function () {
                stopStatusPolling();
            }, RECENT_ACTIVITY_HOLD_MS);
        }
    }

    function applyAdhocStatusSnapshot(status) {
        var st = status || {};
        if (st.fileExists === false) return;
        var infl = inferStatusGraphState(st);
        if (!infl) return;
        if (adhocStatusTargetSessionId) {
            appendActivityBubblesToSession(adhocStatusTargetSessionId, st);
        }
        if (typeof window.agentState !== 'undefined' && window.agentState.applyBackgroundJobState) {
            window.agentState.applyBackgroundJobState(infl.background);
        }
        if (st.graphRefreshToken && st.graphRefreshToken !== lastAdhocGraphRefreshToken) {
            lastAdhocGraphRefreshToken = st.graphRefreshToken;
            if (typeof window.MemoryGraphRefresh === 'function') window.MemoryGraphRefresh();
        }
        if (typeof window.MemoryGraphUpdateExecutionPanel === 'function') window.MemoryGraphUpdateExecutionPanel();
        if (typeof window.MemoryGraphUpdateSimplePulsesFromStatus === 'function') {
            var merged = {};
            for (var k in st) {
                if (Object.prototype.hasOwnProperty.call(st, k)) {
                    merged[k] = st[k];
                }
            }
            merged.thinking = false;
            window.MemoryGraphUpdateSimplePulsesFromStatus(merged);
        }
    }

    function pollStatusSnapshot(requestId, ignoreFailure) {
        if (!requestId) return;
        $.getJSON('api/chat_status.php', { request_id: requestId })
            .done(function (status) {
                applyStatusSnapshot(status || {});
            })
            .fail(function () {
                if (!ignoreFailure) {
                    stopStatusPolling();
                }
            });
    }

    function pollAdhocStatusSnapshot(requestId) {
        if (!requestId) return;
        $.getJSON('api/chat_status.php', { request_id: requestId })
            .done(function (status) {
                applyAdhocStatusSnapshot(status || {});
            })
            .fail(function () {});
    }

    function startStatusPolling(requestId) {
        if (typeof window.MemoryGraphStopAdhocStatusPoll === 'function') {
            window.MemoryGraphStopAdhocStatusPoll();
        }
        stopStatusPolling();
        if (!requestId) return;
        if (typeof window.MemoryGraphResetSimpleActivityLog === 'function') {
            window.MemoryGraphResetSimpleActivityLog({ clear: true });
        }
        pollStatusSnapshot(requestId, true);
        statusPollHandle = setInterval(function () {
            pollStatusSnapshot(requestId, false);
        }, 100);
    }

    /** Poll graph execution status for sub-agent panel runs: updates background graph state only (no main isThinking / snapshot fight). */
    window.MemoryGraphStartAdhocStatusPoll = function (requestId, subSessionId) {
        if (!requestId) return;
        if (typeof subSessionId === 'string' && subSessionId.trim() !== '') {
            adhocStatusTargetSessionId = subSessionId.trim();
            lastActivityTailTBySession[adhocStatusTargetSessionId] = lastActivityTailTBySession[adhocStatusTargetSessionId] || 0;
        } else {
            adhocStatusTargetSessionId = '';
        }
        if (adhocStatusPollHandle) {
            clearInterval(adhocStatusPollHandle);
            adhocStatusPollHandle = null;
        }
        pollAdhocStatusSnapshot(requestId);
        adhocStatusPollHandle = setInterval(function () {
            pollAdhocStatusSnapshot(requestId);
        }, 200);
    };

    window.MemoryGraphStopAdhocStatusPoll = function () {
        if (adhocStatusPollHandle) {
            clearInterval(adhocStatusPollHandle);
            adhocStatusPollHandle = null;
        }
        adhocStatusTargetSessionId = '';
        if (typeof window.agentState !== 'undefined' && typeof window.agentState.detachSubAgentPanelFromMainGraph === 'function') {
            window.agentState.detachSubAgentPanelFromMainGraph();
        }
        if (typeof window.MemoryGraphClearSubAgentRuntimeGlow === 'function') {
            window.MemoryGraphClearSubAgentRuntimeGlow();
        }
        if (typeof window.MemoryGraphSyncBackgroundGraphStateNow === 'function') {
            window.MemoryGraphSyncBackgroundGraphStateNow();
        } else if (typeof window.MemoryGraphResyncBackgroundGraphState === 'function') {
            window.MemoryGraphResyncBackgroundGraphState();
        }
    };

    function buildModalText(promptText, responseText) {
        var parts = [];
        parts.push('Prompt:');
        parts.push(promptText || '');
        parts.push('');
        parts.push('Response:');
        parts.push(responseText || '');
        return parts.join('\n');
    }

    function looksLikeHtmlSnippet(code) {
        if (!code) return false;
        return /<\/?[a-z][\s\S]*>/i.test(code) || /<script[\s\S]*>/i.test(code) || /<canvas[\s\S]*>/i.test(code);
    }

    function looksLikeJavaScriptSnippet(code) {
        if (!code) return false;
        return /\b(const|let|var|function|document\.|window\.|new\s+[A-Z]|console\.|setTimeout|setInterval)\b/.test(code);
    }

    function isPreviewableCode(language, code) {
        var lang = (language || '').toLowerCase();
        return lang === 'html' || lang === 'htm' || lang === 'javascript' || lang === 'js' || (!lang && (looksLikeHtmlSnippet(code) || looksLikeJavaScriptSnippet(code)));
    }

    function buildPreviewResizeScript(previewId) {
        return [
            '<script>',
            '(function(){',
            'var previewId = ' + JSON.stringify(previewId) + ';',
            'function getHeight(){',
            'var body = document.body;',
            'var html = document.documentElement;',
            'return Math.max(',
            'body ? body.scrollHeight : 0,',
            'body ? body.offsetHeight : 0,',
            'html ? html.scrollHeight : 0,',
            'html ? html.offsetHeight : 0,',
            '320',
            ');',
            '}',
            'function notify(){',
            'try {',
            'parent.postMessage({ type: "memory-graph-preview-height", previewId: previewId, height: getHeight() }, "*");',
            '} catch (e) {}',
            '}',
            'window.addEventListener("load", notify);',
            'window.addEventListener("resize", notify);',
            'if (typeof ResizeObserver !== "undefined") {',
            'try { new ResizeObserver(notify).observe(document.documentElement); } catch (e) {}',
            '}',
            'setTimeout(notify, 50);',
            'setTimeout(notify, 200);',
            'setTimeout(notify, 600);',
            'setTimeout(notify, 1200);',
            '})();',
            '<\/script>'
        ].join('');
    }

    function injectPreviewResizeScript(doc, previewId) {
        var script = buildPreviewResizeScript(previewId);
        if (/<\/body>/i.test(doc)) {
            return doc.replace(/<\/body>/i, script + '</body>');
        }
        return doc + script;
    }

    function buildPreviewDocument(language, code, previewId) {
        var lang = (language || '').toLowerCase();
        var body = code || '';
        if (lang === 'javascript' || lang === 'js' || (!looksLikeHtmlSnippet(body) && looksLikeJavaScriptSnippet(body))) {
            body = [
                '<!DOCTYPE html>',
                '<html lang="en">',
                '<head>',
                '<meta charset="UTF-8">',
                '<meta name="viewport" content="width=device-width, initial-scale=1.0">',
                '<title>Preview</title>',
                '<style>',
                'html, body { margin: 0; padding: 0; overflow: hidden; }',
                'body { padding: 16px; background: #0a0a0a; color: #f9f1d8; font-family: Georgia, serif; }',
                'canvas { max-width: 100%; height: auto !important; }',
                '</style>',
                '</head>',
                '<body>',
                '<div id="app"></div>',
                '<script>',
                body,
                '<\/script>',
                '</body>',
                '</html>'
            ].join('');
        } else if (!/<!DOCTYPE html/i.test(body) && !/<html[\s>]/i.test(body)) {
            body = [
                '<!DOCTYPE html>',
                '<html lang="en">',
                '<head>',
                '<meta charset="UTF-8">',
                '<meta name="viewport" content="width=device-width, initial-scale=1.0">',
                '<title>Preview</title>',
                '<style>',
                'html, body { margin: 0; padding: 0; overflow: hidden; }',
                'body { padding: 16px; background: #0a0a0a; color: #f9f1d8; font-family: Georgia, serif; }',
                'canvas { max-width: 100%; height: auto !important; }',
                '</style>',
                '</head>',
                '<body>',
                body,
                '</body>',
                '</html>'
            ].join('');
        }
        return injectPreviewResizeScript(body, previewId);
    }

    function ensurePreviewResizeListener() {
        if (window.__memoryGraphPreviewResizeBound) return;
        window.__memoryGraphPreviewResizeBound = true;
        window.addEventListener('message', function (event) {
            var data = event && event.data;
            if (!data || data.type !== 'memory-graph-preview-height' || !data.previewId) return;
            var frame = document.querySelector('iframe[data-preview-id="' + data.previewId + '"]');
            if (!frame) return;
            var height = Math.max(320, parseInt(data.height, 10) || 320);
            frame.style.height = height + 'px';
        });
    }

    function getMarkedParseFn() {
        var m = typeof marked !== 'undefined' ? marked : null;
        if (!m) return null;
        if (typeof m.parse === 'function') {
            try {
                m.setOptions({ gfm: true, breaks: true });
            } catch (e1) {}
            return function (src) {
                return m.parse(src);
            };
        }
        if (typeof m === 'function') {
            return m;
        }
        return null;
    }

    function memoryGraphMarkdownToHtml(src) {
        if (!src || typeof src !== 'string') return null;
        var parseMd = getMarkedParseFn();
        var purify = typeof DOMPurify !== 'undefined' ? DOMPurify : null;
        if (!parseMd || !purify) return null;
        try {
            var raw = parseMd(src);
            if (!raw || typeof raw !== 'string') return null;
            return purify.sanitize(raw, { USE_PROFILES: { html: true } });
        } catch (e2) {
            return null;
        }
    }

    function appendTextBlock($container, text) {
        if (!text) return;
        var cleaned = text.replace(/^\s+|\s+$/g, '');
        if (!cleaned) return;
        $('<div class="response-modal-text">').text(cleaned).appendTo($container);
    }

    function appendMarkdownOrText($container, text) {
        if (!text) return;
        var cleaned = text.replace(/^\s+|\s+$/g, '');
        if (!cleaned) return;
        var html = memoryGraphMarkdownToHtml(cleaned);
        if (html) {
            $('<div class="response-modal-md font-serif">').html(html).appendTo($container);
            return;
        }
        appendTextBlock($container, cleaned);
    }

    function appendCodeBlock($container, language, code) {
        var label = language ? language.toUpperCase() : 'CODE';
        var $block = $('<div class="response-modal-code-block">');
        $('<div class="response-modal-code-label">').text(label).appendTo($block);
        $('<pre class="response-modal-code"><code></code></pre>')
            .find('code')
            .text(code || '')
            .end()
            .appendTo($block);

        if (isPreviewableCode(language, code)) {
            var previewId = 'preview-' + Date.now() + '-' + Math.floor(Math.random() * 100000);
            ensurePreviewResizeListener();
            $('<div class="response-modal-preview-label">').text('Preview').appendTo($block);
            $('<iframe class="response-modal-preview-frame" sandbox="allow-scripts allow-same-origin allow-modals allow-pointer-lock" allow="pointer-lock; fullscreen; autoplay; gamepad"></iframe>')
                .attr('data-preview-id', previewId)
                .attr('scrolling', 'no')
                .attr('srcdoc', buildPreviewDocument(language, code, previewId))
                .appendTo($block);
        }

        $container.append($block);
    }

    function renderResponseContent($container, responseText) {
        var text = responseText || '';
        var codeBlockRegex = /```([a-zA-Z0-9_-]+)?\r?\n([\s\S]*?)```/g;
        var lastIndex = 0;
        var hasMatches = false;
        var match;

        while ((match = codeBlockRegex.exec(text)) !== null) {
            hasMatches = true;
            appendMarkdownOrText($container, text.slice(lastIndex, match.index));
            appendCodeBlock($container, match[1] || '', match[2] || '');
            lastIndex = codeBlockRegex.lastIndex;
        }

        if (hasMatches) {
            appendMarkdownOrText($container, text.slice(lastIndex));
            return;
        }

        if (looksLikeHtmlSnippet(text)) {
            appendCodeBlock($container, 'html', text);
            return;
        }

        appendMarkdownOrText($container, text);
    }

    function renderModalContent(promptText, responseText) {
        if (!$modalBody.length) return;
        $modalBody.empty();

        $('<div class="response-modal-section-title">').text('Prompt').appendTo($modalBody);
        $('<div class="response-modal-text response-modal-prompt">').text(promptText || '').appendTo($modalBody);
        $('<div class="response-modal-section-title">').text('Response').appendTo($modalBody);
        renderResponseContent($modalBody, responseText || '');
    }

    function openResponseModal(promptText, responseText) {
        renderModalContent(promptText, responseText);
        if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
            if (modalInstance) modalInstance.show();
            else {
                var modalEl = document.getElementById('response-modal');
                if (modalEl) {
                    modalInstance = new bootstrap.Modal(modalEl);
                    modalInstance.show();
                }
            }
        }
    }

    function showNotification(preview, promptText, responseText) {
        var id = 'notif-' + Date.now();
        fullResponses[id] = {
            prompt: promptText || '',
            response: responseText || ''
        };
        var $el = $('<div class="notification" data-id="' + id + '">')
            .html('<div class="preview">' + escapeHtml(preview) + '</div>');
        $notifications.append($el);
        $el.on('click', function () {
            var tid = $(this).attr('data-id');
            var payload = fullResponses[tid] !== undefined ? fullResponses[tid] : { prompt: '', response: '' };
            openResponseModal(payload.prompt, payload.response);
        });
        setTimeout(function () {
            $el.fadeOut(300, function () { delete fullResponses[id]; $(this).remove(); });
        }, 12000);
    }

    function escapeHtml(s) {
        if (!s) return '';
        var div = document.createElement('div');
        div.textContent = s;
        return div.innerHTML;
    }

    /**
     * OpenAI-compatible APIs sometimes return message.content as an array of parts, not a string.
     * jQuery used (content || '') which kept non-strings and broke previews/modal (blank "Response").
     */
    function extractAssistantTextFromChatResponse(res) {
        var mg = res && res.memory_graph;
        if (mg && typeof mg.assistant_body === 'string' && mg.assistant_body.trim() !== '') {
            return mg.assistant_body.trim();
        }
        if (!res || !res.choices || !res.choices[0] || !res.choices[0].message) {
            if (res && typeof res.response === 'string' && res.response.trim()) {
                return res.response.trim();
            }
            if (mg && typeof mg.hint === 'string' && mg.hint.trim()) {
                return mg.hint.trim();
            }
            return '';
        }
        var msg = res.choices[0].message;
        var c = msg.content;
        if (typeof c === 'string') {
            var st = c.trim();
            if (st !== '') return c;
            if (typeof msg.reasoning_content === 'string' && msg.reasoning_content.trim()) return msg.reasoning_content.trim();
            if (typeof msg.thinking === 'string' && msg.thinking.trim()) return msg.thinking.trim();
            if (mg && typeof mg.hint === 'string' && mg.hint.trim()) return mg.hint.trim();
            return '';
        }
        if (c === null || c === undefined) {
            if (typeof msg.reasoning_content === 'string' && msg.reasoning_content.trim()) return msg.reasoning_content.trim();
            if (typeof msg.thinking === 'string' && msg.thinking.trim()) return msg.thinking.trim();
            if (mg && typeof mg.hint === 'string' && mg.hint.trim()) {
                return mg.hint.trim();
            }
            return '';
        }
        if (typeof c === 'number' || typeof c === 'boolean') {
            return String(c);
        }
        if (Array.isArray(c)) {
            var parts = [];
            for (var i = 0; i < c.length; i++) {
                var p = c[i];
                if (p == null || typeof p !== 'object') continue;
                if (typeof p.text === 'string') parts.push(p.text);
                else if (p.text && typeof p.text === 'object' && typeof p.text.value === 'string') parts.push(p.text.value);
                else if (typeof p.content === 'string') parts.push(p.content);
            }
            var joined = parts.join('');
            if (joined) return joined;
        }
        if (res.memory_graph && typeof res.memory_graph.hint === 'string' && res.memory_graph.hint.trim()) {
            return res.memory_graph.hint.trim();
        }
        try {
            return JSON.stringify(c);
        } catch (e) {
            return '';
        }
    }

    window.MemoryGraphShowResponseModal = openResponseModal;

    function summarizePartsForHistory(parts) {
        if (!parts || !parts.length) return '';
        return parts.map(function (p) {
            if (!p || typeof p !== 'object') return 'part';
            if (p.type === 'image_url') return 'image';
            if (p.type === 'input_audio') return 'audio';
            if (p.type === 'input_video') return 'video';
            if (p.type === 'text') return 'text';
            return String(p.type || 'file');
        }).join(', ');
    }

    function userTurnHistoryLine(text, partsSummary, subAgentStem) {
        var t = (text || '').trim();
        var out = t;
        if (partsSummary) {
            out = t ? (t + '\n\n[Attached: ' + partsSummary + ']') : ('[Attached: ' + partsSummary + ']');
        }
        if (subAgentStem) {
            out = '[→ sub-agent: ' + subAgentStem + (out ? ']\n' + out : ']\n');
        }
        return out || t;
    }

    function buildUserMessageForApi(text, parts) {
        parts = parts || [];
        if (!parts.length) {
            return text || '';
        }
        var body = [];
        var t = (text || '').trim();
        if (t) {
            body.push({ type: 'text', text: t });
        }
        parts.forEach(function (p) {
            if (p) body.push(p);
        });
        if (!body.some(function (b) { return b && b.type === 'text'; })) {
            body.unshift({ type: 'text', text: '(User message: see attached files.)' });
        }
        if (body.length === 1 && body[0].type === 'text') {
            return body[0].text;
        }
        return body;
    }

    function sendMessage() {
        var att = window.__mgMainChatAttachments;
        var doSend = function () {
            var text = ($input.val() || '').trim();
            if (att && typeof att.getSubAgentRef === 'function' && !att.getSubAgentRef() && window.MemoryGraphTryParseSubagentInInput) {
                var pr = window.MemoryGraphTryParseSubagentInInput();
                if (pr && pr.stem) {
                    if (att.setSubAgentRef) {
                        att.setSubAgentRef({ stem: pr.stem, label: pr.stem });
                    }
                    text = pr.text;
                    $input.val(text);
                } else {
                    text = ($input.val() || '').trim();
                }
            }
            var rawParts = (att && typeof att.getParts === 'function') ? att.getParts() : [];
            var parts = Array.isArray(rawParts) ? rawParts.slice() : [];
            var sref = (att && att.getSubAgentRef) ? att.getSubAgentRef() : null;
            if (!text && !parts.length) return;
            $input.val('');
            if (currentRequest) {
                addToQueue(text, parts, sref);
                if (att && typeof att.clear === 'function') {
                    att.clear();
                }
                return;
            }
            sendMessageInternal(text, parts, sref);
        };
        if (window.MemoryGraphMentionListReady) {
            window.MemoryGraphMentionListReady().then(function () { doSend(); }).catch(function () { doSend(); });
        } else {
            doSend();
        }
    }

    function sendMessageInternal(text, attachmentParts, subAgentFromQueue) {
        wasStopped = false;
        attachmentParts = attachmentParts || [];
        if (subAgentFromQueue && subAgentFromQueue.stem && window.__mgMainChatAttachments && typeof window.__mgMainChatAttachments.setSubAgentRef === 'function') {
            window.__mgMainChatAttachments.setSubAgentRef({ stem: subAgentFromQueue.stem, label: subAgentFromQueue.label || subAgentFromQueue.stem });
        }
        setRequestUi(true);

        var settings = (typeof window.getAgentSettings === 'function' && window.getAgentSettings()) || {};
        var chatSessionId = getOrCreateChatSessionId();
        var messages = [];
        getTurns().forEach(function (t) {
            if (t && (t.role === 'user' || t.role === 'assistant')) {
                if (typeof t.content === 'string') {
                    messages.push({ role: t.role, content: t.content });
                } else if (Array.isArray(t.content)) {
                    messages.push({ role: t.role, content: t.content });
                }
            }
        });
        var userPayload = buildUserMessageForApi(text, attachmentParts);
        var att0 = window.__mgMainChatAttachments;
        var subForHist = (att0 && att0.getSubAgentRef) ? att0.getSubAgentRef() : null;
        var subLabel = (subForHist && subForHist.stem) ? subForHist.stem : (subAgentFromQueue && subAgentFromQueue.stem ? subAgentFromQueue.stem : null);
        var historyLine = userTurnHistoryLine(text, summarizePartsForHistory(attachmentParts), subLabel);
        messages.push({ role: 'user', content: userPayload });
        getTurns().push({ role: 'user', content: historyLine });
        saveSessionBundle();
        renderSimpleChatThread();
        var requestId = 'chat_' + Date.now() + '_' + Math.floor(Math.random() * 100000);
        lastGraphRefreshToken = '';
        inFlightMainRequestId = requestId;
        inFlightMainSessionId = getActiveSessionId();
        lastActivityTailTBySession[inFlightMainSessionId] = lastActivityTailTBySession[inFlightMainSessionId] || 0;

        if (typeof window.agentState !== 'undefined') window.agentState.setThinking(true);
        startStatusPolling(requestId);

        var gSub = (att0 && att0.getSubAgentRef) ? att0.getSubAgentRef() : null;
        var saStem = (gSub && gSub.stem) ? gSub.stem : (subAgentFromQueue && subAgentFromQueue.stem) ? subAgentFromQueue.stem : '';
        var postBody = {
            requestId: requestId,
            chatSessionId: chatSessionId,
            provider: settings.provider || 'mercury',
            model: settings.model || 'mercury-2',
            systemPrompt: (settings.systemPrompt != null && settings.systemPrompt !== '') ? settings.systemPrompt : '',
            temperature: settings.temperature != null ? settings.temperature : 0.7,
            messages: messages
        };
        if (saStem) {
            postBody.targetSubAgent = saStem;
        }
        currentRequest = $.ajax({
            url: 'api/chat.php',
            method: 'POST',
            timeout: LONG_CHAT_AJAX_MS,
            contentType: 'application/json',
            data: JSON.stringify(postBody)
        })
            .done(function (res) {
                if (wasStopped) return;
                if (typeof res === 'string') {
                    try {
                        res = JSON.parse(res);
                    } catch (e) {
                        res = {};
                    }
                }
                if (res && res.graphRefreshNeeded && typeof window.MemoryGraphRefresh === 'function') {
                    window.MemoryGraphRefresh();
                }
                if (res && res.reloadWebAppsList) {
                    if (typeof window.MemoryGraphReloadAppsList === 'function') {
                        window.MemoryGraphReloadAppsList();
                    }
                    if (typeof window.MemoryGraphReloadSimpleAppsSection === 'function') {
                        window.MemoryGraphReloadSimpleAppsSection();
                    }
                }
                if (res && res.web_app && typeof window.MemoryGraphOpenWebApp === 'function') {
                    window.MemoryGraphOpenWebApp(res.web_app);
                }
                if (res && res.memory_graph && Array.isArray(res.memory_graph.memory_file_node_ids) && res.memory_graph.memory_file_node_ids.length) {
                    if (typeof window.agentState !== 'undefined' && typeof window.agentState.markMemoryFileNodesActive === 'function') {
                        window.agentState.markMemoryFileNodesActive(res.memory_graph.memory_file_node_ids);
                    }
                }
                if (typeof window.MemoryGraphGroqQuotaApplyResponse === 'function') {
                    window.MemoryGraphGroqQuotaApplyResponse(res);
                }
                if (typeof window.MemoryGraphGeminiQuotaApplyResponse === 'function') {
                    window.MemoryGraphGeminiQuotaApplyResponse(res);
                }
                var content = extractAssistantTextFromChatResponse(res);
                if (!content && typeof res === 'string') content = res;
                if (!content && res && res.choices && res.choices[0] && res.choices[0].message) {
                    try {
                        content = JSON.stringify(res.choices[0].message);
                        if (content.length > 8000) content = content.slice(0, 8000) + '…';
                    } catch (e2) {}
                }
                if (!content) content = 'No text in response.';
                var preview = content.length > 120 ? content.slice(0, 120) + '…' : content;
                showNotification(preview, historyLine, content);
                getTurns().push({ role: 'assistant', content: content });
                saveSessionBundle();
                renderSimpleChatThread();
                if (fishAudioState.loaded && fishAudioState.enabled && !fishAudioState.muted && fishAudioState.autoSpeak) {
                    fishAudioSpeakText(content).catch(function () {});
                }
                if (res && res.jobToRun && typeof window.MemoryGraphRunJob === 'function') {
                    var jobs = Array.isArray(res.jobToRun) ? res.jobToRun : [res.jobToRun];
                    jobs.forEach(function (job) {
                        if (job && job.name && job.content) {
                            window.MemoryGraphRunJob(job.name, job.content, { nodeId: job.nodeId || null });
                        }
                    });
                }
                if (typeof window.applyAgentConfig === 'function') {
                    $.ajax({ url: 'api/agent_config.php', dataType: 'json', cache: false })
                        .done(function (data) { if (data) window.applyAgentConfig(data); });
                }
            })
            .fail(function (xhr) {
                if (wasStopped || (xhr && xhr.statusText === 'abort')) return;
                var msg = (xhr.responseJSON && xhr.responseJSON.error) || xhr.statusText || 'Request failed';
                if ((!msg || msg === 'OK') && xhr && xhr.responseText) {
                    try {
                        var parsed = JSON.parse(xhr.responseText);
                        msg = parsed.error || xhr.responseText;
                    } catch (e) {
                        msg = xhr.responseText;
                    }
                }
                if (msg && typeof msg === 'object') {
                    msg = (msg.message !== undefined && typeof msg.message === 'string') ? msg.message : JSON.stringify(msg);
                }
                var displayMsg = (msg && String(msg).trim()) || 'Request failed';
                var errBody = 'Error: ' + displayMsg;
                showNotification(displayMsg, historyLine, errBody);
                getTurns().push({ role: 'assistant', content: errBody });
                saveSessionBundle();
                renderSimpleChatThread();
            })
            .always(function () {
                currentRequest = null;
                inFlightMainRequestId = '';
                inFlightMainSessionId = '';
                if (typeof window.agentState !== 'undefined') window.agentState.setThinking(false);
                if (typeof window.MemoryGraphRefreshSimpleChatHistoryList === 'function') {
                    window.MemoryGraphRefreshSimpleChatHistoryList();
                }
                pollStatusSnapshot(requestId, true);
                if (wasStopped) {
                    stopStatusPolling();
                    if ($input.length) {
                        $input.val(text);
                    }
                } else if (!stopPollingTimeout) {
                    stopPollingTimeout = setTimeout(function () {
                        stopStatusPolling();
                    }, RECENT_ACTIVITY_HOLD_MS);
                }
                if (typeof window.MemoryGraphRefresh === 'function') window.MemoryGraphRefresh();
                processNextInQueue();
                focusChatInputSoon();
                if (!wasStopped) {
                    var mgAtt = window.__mgMainChatAttachments;
                    if (mgAtt && typeof mgAtt.clear === 'function') {
                        mgAtt.clear();
                    }
                }
                if (typeof window.MemoryGraphFeatherlessRefreshMeters === 'function') {
                    window.MemoryGraphFeatherlessRefreshMeters();
                }
            });
    }

    $(function () {
        if (typeof window.MemoryGraphInitChatAttachments === 'function') {
            window.MemoryGraphInitChatAttachments();
        }
        if (typeof window.MemoryGraphInitSubagentMention === 'function') {
            window.MemoryGraphInitSubagentMention();
        }
        if (window.MemoryGraphMentionListReady) {
            window.MemoryGraphMentionListReady().catch(function () {});
        }
        renderSimpleChatThread();
        focusChatInputSoon();
        if (typeof window.MemoryGraphGroqQuotaSync === 'function') {
            window.MemoryGraphGroqQuotaSync();
        }
        if (typeof window.MemoryGraphGeminiQuotaSync === 'function') {
            window.MemoryGraphGeminiQuotaSync();
        }
        if (typeof window.MemoryGraphFeatherlessMeterSync === 'function') {
            window.MemoryGraphFeatherlessMeterSync();
        }
        if ($input.length) {
            $input.on('input', function () {
                if (typeof window.MemoryGraphFeatherlessScheduleTokenize === 'function') {
                    window.MemoryGraphFeatherlessScheduleTokenize();
                }
            });
        }
    });

    $send.on('click', sendMessage);
    if ($queueHeader.length) {
        $queueHeader.on('click', function () {
            $queueWrap.toggleClass('collapsed');
        });
    }
    if ($stop.length) {
        $stop.on('click', function () {
            if (!currentRequest) return;
            wasStopped = true;
            currentRequest.abort();
            currentRequest = null;
            inFlightMainRequestId = '';
            inFlightMainSessionId = '';
            if (typeof window.agentState !== 'undefined') window.agentState.setThinking(false);
            stopStatusPolling();
            setRequestUi(false);
            $input.focus();
        });
        $stop.hide().prop('disabled', true);
    }
    $input.on('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage();
        }
    });
    (function initFishAudioUi() {
        var $audioFab = $('#audio-fab');
        function syncFab(state) {
            if (!$audioFab.length) return;
            var s = state || window.MemoryGraphFishAudioGetState();
            var muted = !s || s.muted;
            $audioFab.toggleClass('is-muted', muted);
            $audioFab.text(muted ? '🔇' : '🔊');
            $audioFab.attr('title', muted ? 'Response speech muted' : 'Response speech enabled');
            $audioFab.attr('aria-label', muted ? 'Unmute response speech' : 'Mute response speech');
        }
        if ($audioFab.length) {
            $audioFab.on('click', function () {
                window.MemoryGraphFishAudioToggleMute().catch(function () {});
            });
        }
        window.MemoryGraphFishAudioOnState(syncFab);
        loadFishAudioSettings().catch(function () {
            syncFab({ muted: true });
        });
    })();

    window.MemoryGraphExtractAssistantFromChatResponse = extractAssistantTextFromChatResponse;

    function peekChatSessionId() {
        return getActiveSessionId() || '';
    }
    window.MemoryGraphPeekChatSessionId = peekChatSessionId;

    window.MemoryGraphStartNewChatSession = function () {
        var id = newSessionId();
        ensureSessionEntry(id, 'Main', false);
        sessionBundle.sessions[id].turns = [];
        sessionBundle.activeId = id;
        if (sessionBundle.order.indexOf(id) === -1) {
            sessionBundle.order.unshift(id);
        }
        saveSessionBundle();
        renderSimpleChatThread();
        emitOpenSessionsChanged();
        if (typeof window.MemoryGraphRefreshSimpleChatHistoryList === 'function') {
            window.MemoryGraphRefreshSimpleChatHistoryList();
        }
        return id;
    };

    window.MemoryGraphSetChatSessionId = function (sessionId) {
        sessionId = String(sessionId || '').trim();
        if (!sessionId) return;
        ensureSessionEntry(sessionId, sessionId.slice(0, 20), false);
        sessionBundle.activeId = sessionId;
        if (sessionBundle.order.indexOf(sessionId) === -1) {
            sessionBundle.order.push(sessionId);
        }
        saveSessionBundle();
        emitOpenSessionsChanged();
    };

    window.MemoryGraphSwitchSession = function (sessionId) {
        sessionId = String(sessionId || '').trim();
        if (!sessionId || !sessionBundle.sessions[sessionId]) return;
        sessionBundle.activeId = sessionId;
        saveSessionBundle();
        renderSimpleChatThread();
        emitOpenSessionsChanged();
    };

    window.MemoryGraphGetOpenSessions = function () {
        return sessionBundle.order.map(function (id) {
            var s = sessionBundle.sessions[id] || { label: id, isSub: false, turns: [] };
            return {
                id: id,
                label: s.label || id,
                isSub: !!s.isSub,
                active: id === sessionBundle.activeId
            };
        });
    };

    window.MemoryGraphCreateSubAgentSession = function (subAgentName) {
        var id = 'cs_s_' + Date.now() + '_' + Math.random().toString(36).slice(2, 12);
        var label = 'Sub' + (subAgentName ? ': ' + String(subAgentName).slice(0, 32) : '');
        ensureSessionEntry(id, label, true);
        sessionBundle.sessions[id].turns = [];
        sessionBundle.activeId = id;
        if (sessionBundle.order.indexOf(id) === -1) {
            sessionBundle.order.push(id);
        }
        lastActivityTailTBySession[id] = 0;
        saveSessionBundle();
        try {
            sessionStorage.setItem(CHAT_SESSION_KEY, id);
        } catch (e) {}
        renderSimpleChatThread();
        emitOpenSessionsChanged();
        return id;
    };

    window.MemoryGraphAppendSubAgentTranscript = function (sessionId, userText, assistantText, opts) {
        var sid = String(sessionId || '').trim();
        if (!sid) return;
        ensureSessionEntry(sid, 'Sub', true);
        var t = getTurnsForSession(sid);
        if (userText) t.push({ role: 'user', content: String(userText) });
        if (assistantText && String(assistantText).trim() !== '') {
            t.push({ role: 'assistant', content: String(assistantText) });
        }
        saveSessionBundle();
        if (getActiveSessionId() === sid) {
            renderSimpleChatThread();
        }
        if (opts && opts.showNotification) {
            var c = String(assistantText || '').trim() || 'Done.';
            var pre = c.length > 100 ? c.slice(0, 100) + '…' : c;
            showNotification(pre, String(userText || ''), c);
        }
        if (assistantText && fishAudioState.loaded && fishAudioState.enabled && !fishAudioState.muted && fishAudioState.autoSpeak) {
            fishAudioSpeakText(String(assistantText)).catch(function () {});
        }
        if (typeof window.MemoryGraphRefreshSimpleChatHistoryList === 'function') {
            window.MemoryGraphRefreshSimpleChatHistoryList();
        }
    };

    window.MemoryGraphReplaceSimpleChatTurns = function (turns) {
        var raw = Array.isArray(turns) ? turns : [];
        var out = filterPersistTurns(raw);
        var active = getActiveSessionId();
        if (!sessionBundle.sessions[active]) {
            ensureSessionEntry(active, 'Main', false);
        }
        sessionBundle.sessions[active].turns = out;
        saveSessionBundle();
        renderSimpleChatThread();
    };

    var GROQ_TPD_LS_PREFIX = 'mg_groq_tpd_v1_';

    function mgGroqTpdKey(model) {
        var d = new Date();
        return GROQ_TPD_LS_PREFIX + String(model || '') + '_' + d.getFullYear() + '-' + (d.getMonth() + 1) + '-' + d.getDate();
    }

    function mgGroqBumpTpdUsed(model, usage) {
        try {
            var lim = (window.MEMORY_GRAPH_GROQ_MODEL_LIMITS || {})[model];
            if (!lim || lim.tpd == null) return;
            var total = usage && usage.total_tokens != null ? Number(usage.total_tokens) : 0;
            if (!total || total < 0) return;
            var k = mgGroqTpdKey(model);
            var prev = parseInt(localStorage.getItem(k) || '0', 10) || 0;
            localStorage.setItem(k, String(prev + total));
        } catch (e) { /* ignore quota storage */ }
    }

    function mgGroqDialPctRemaining(used, limit) {
        if (limit == null || limit <= 0) return null;
        var u = Math.max(0, Number(used) || 0);
        if (u > limit) u = limit;
        return (limit - u) / limit;
    }

    function mgGroqDialSvg(pct) {
        var r = 16;
        var c = 2 * Math.PI * r;
        var p = pct == null ? 0 : Math.max(0, Math.min(1, pct));
        var off = c * (1 - p);
        var cls = 'mg-groq-dial-fill';
        if (p < 0.15) cls += ' mg-groq-dial-fill--rose';
        else if (p < 0.35) cls += ' mg-groq-dial-fill--amber';
        return '<svg viewBox="0 0 44 44" aria-hidden="true"><circle class="mg-groq-dial-track" cx="22" cy="22" r="' + r + '"/>'
            + '<circle class="' + cls + '" cx="22" cy="22" r="' + r + '" stroke-dasharray="' + c + '" stroke-dashoffset="' + off + '"/>'
            + '</svg>';
    }

    function mgGroqFormatNum(n) {
        if (n == null || n !== n) return '—';
        var x = Number(n);
        if (x >= 1000000) return (x / 1000000).toFixed(1) + 'M';
        if (x >= 10000) return Math.round(x / 1000) + 'k';
        if (x >= 1000) return (x / 1000).toFixed(x % 1000 === 0 ? 0 : 1) + 'k';
        return String(Math.round(x));
    }

    function renderGroqQuotaBar(opts) {
        var row = document.getElementById('mg-groq-quota-row');
        if (!row) return;
        var settings = (typeof window.getAgentSettings === 'function' && window.getAgentSettings()) || {};
        if (settings.provider !== 'groq') {
            row.setAttribute('hidden', '');
            row.innerHTML = '';
            return;
        }
        row.removeAttribute('hidden');
        var model = settings.model || '';
        var caps = (window.MEMORY_GRAPH_GROQ_MODEL_LIMITS || {})[model] || {};
        var rl = (opts && opts.rateLimits) || window.__mgLastGroqRateLimits || {};
        window.__mgLastGroqRateLimits = rl;

        var lr = rl.limit_requests;
        var rr = rl.remaining_requests;
        var reqPct = (lr != null && lr > 0 && rr != null) ? rr / lr : null;

        var lt = rl.limit_tokens;
        var rt = rl.remaining_tokens;
        var tokPct = (lt != null && lt > 0 && rt != null) ? rt / lt : null;

        var tpd = caps.tpd != null ? caps.tpd : null;
        var usedTpd = 0;
        try {
            if (tpd != null) usedTpd = parseInt(localStorage.getItem(mgGroqTpdKey(model)) || '0', 10) || 0;
        } catch (e2) { usedTpd = 0; }
        var tpdRemainPct = tpd != null && tpd > 0 ? mgGroqDialPctRemaining(usedTpd, tpd) : null;

        var html = '<div class="mg-groq-quota-title">Groq quota · ' + escapeHtml(model) + '</div>';
        html += '<div class="mg-groq-dial" title="Remaining requests today vs limit (Groq x-ratelimit-*-requests = RPD window).">' + mgGroqDialSvg(reqPct)
            + '<span class="mg-groq-dial-cap">' + (rr != null ? mgGroqFormatNum(rr) : '—') + ' / ' + (lr != null ? mgGroqFormatNum(lr) : '—') + '</span>'
            + '<span class="mg-groq-dial-sub">Requests (day)</span></div>';
        html += '<div class="mg-groq-dial" title="Remaining tokens in the current minute vs TPM limit (Groq headers).">' + mgGroqDialSvg(tokPct)
            + '<span class="mg-groq-dial-cap">' + (rt != null ? mgGroqFormatNum(rt) : '—') + ' / ' + (lt != null ? mgGroqFormatNum(lt) : '—') + '</span>'
            + '<span class="mg-groq-dial-sub">Tokens (minute)</span></div>';
        if (tpd != null) {
            var rem = Math.max(0, tpd - usedTpd);
            html += '<div class="mg-groq-dial" title="Browser-local estimate: sums usage.total_tokens per chat response today vs docs TPD for this model.">' + mgGroqDialSvg(tpdRemainPct)
                + '<span class="mg-groq-dial-cap">' + mgGroqFormatNum(rem) + ' / ' + mgGroqFormatNum(tpd) + '</span>'
                + '<span class="mg-groq-dial-sub">Daily tokens (est.)</span></div>';
        } else if (caps.ash != null) {
            html += '<div class="mg-groq-dial"><span class="mg-groq-dial-cap">ASH ' + mgGroqFormatNum(caps.ash) + '</span><span class="mg-groq-dial-sub">Audio sec / hr</span></div>';
            html += '<div class="mg-groq-dial"><span class="mg-groq-dial-cap">ASD ' + mgGroqFormatNum(caps.asd) + '</span><span class="mg-groq-dial-sub">Audio sec / day</span></div>';
        }

        if (rl.reset_requests || rl.reset_tokens) {
            html += '<div class="mg-groq-dial-sub" style="width:100%;margin-top:2px;">Resets'
                + (rl.reset_requests ? ' · req ' + escapeHtml(String(rl.reset_requests)) : '')
                + (rl.reset_tokens ? ' · tok ' + escapeHtml(String(rl.reset_tokens)) : '')
                + '</div>';
        }
        row.innerHTML = html;
    }

    window.MemoryGraphGroqQuotaSync = function () {
        renderGroqQuotaBar({});
    };

    window.MemoryGraphGroqQuotaApplyResponse = function (res) {
        if (!res) return;
        var settings = (typeof window.getAgentSettings === 'function' && window.getAgentSettings()) || {};
        if (settings.provider !== 'groq') return;
        if (res.groq_model_limits && typeof res.groq_model_limits === 'object' && settings.model) {
            if (!window.MEMORY_GRAPH_GROQ_MODEL_LIMITS) window.MEMORY_GRAPH_GROQ_MODEL_LIMITS = {};
            window.MEMORY_GRAPH_GROQ_MODEL_LIMITS[settings.model] = res.groq_model_limits;
        }
        if (res.groq_rate_limits && typeof res.groq_rate_limits === 'object') {
            if (res.usage) mgGroqBumpTpdUsed(settings.model, res.usage);
            renderGroqQuotaBar({ rateLimits: res.groq_rate_limits });
        }
    };

    function mgGeminiDaySuffix() {
        var d = new Date();
        return d.getFullYear() + '-' + (d.getMonth() + 1) + '-' + d.getDate();
    }

    function mgGeminiMinuteSuffix() {
        var d = new Date();
        var p = function (n) { return (n < 10 ? '0' : '') + n; };
        return mgGeminiDaySuffix() + '_' + p(d.getHours()) + ':' + p(d.getMinutes());
    }

    function mgGeminiRpdCap(caps) {
        if (!caps || typeof caps !== 'object') return null;
        if (caps.rpd_unlimited) return null;
        if (caps.rpd_free > 0) return caps.rpd_free;
        if (caps.rpd_paid != null && caps.rpd_paid > 0) return caps.rpd_paid;
        return null;
    }

    function mgGeminiTpmCap(caps) {
        if (!caps || typeof caps !== 'object') return null;
        if (caps.tpm_unlimited) return null;
        if (caps.tpm_free > 0) return caps.tpm_free;
        if (caps.tpm_paid != null && caps.tpm_paid > 0) return caps.tpm_paid;
        return null;
    }

    function mgGeminiBumpLocal(model, usageMeta) {
        try {
            var day = mgGeminiDaySuffix();
            var rk = 'mg_gemini_req_day_v1_' + String(model || '') + '_' + day;
            var prevR = parseInt(localStorage.getItem(rk) || '0', 10) || 0;
            localStorage.setItem(rk, String(prevR + 1));

            var tok = usageMeta && usageMeta.totalTokenCount != null ? Number(usageMeta.totalTokenCount) : 0;
            if (tok > 0) {
                var mk = 'mg_gemini_tpm_v1_' + String(model || '') + '_' + mgGeminiMinuteSuffix();
                var prevT = parseInt(localStorage.getItem(mk) || '0', 10) || 0;
                localStorage.setItem(mk, String(prevT + tok));

                var dk = 'mg_gemini_tok_day_v1_' + String(model || '') + '_' + day;
                var prevD = parseInt(localStorage.getItem(dk) || '0', 10) || 0;
                localStorage.setItem(dk, String(prevD + tok));
            }
        } catch (e) { /* ignore */ }
    }

    function renderGeminiQuotaBar(opts) {
        var row = document.getElementById('mg-gemini-quota-row');
        if (!row) return;
        var settings = (typeof window.getAgentSettings === 'function' && window.getAgentSettings()) || {};
        if (settings.provider !== 'gemini') {
            row.setAttribute('hidden', '');
            row.innerHTML = '';
            return;
        }
        var model = settings.model || '';
        var caps = (window.MEMORY_GRAPH_GEMINI_MODEL_LIMITS || {})[model] || null;
        if (!caps) {
            row.setAttribute('hidden', '');
            row.innerHTML = '';
            return;
        }
        row.removeAttribute('hidden');

        var qh = (opts && opts.quotaHeaders) || window.__mgLastGeminiQuotaHeaders || {};
        if (opts && opts.quotaHeaders && typeof opts.quotaHeaders === 'object') {
            window.__mgLastGeminiQuotaHeaders = opts.quotaHeaders;
        }

        var rpdCap = mgGeminiRpdCap(caps);
        var rpdUnlimited = !!caps.rpd_unlimited;
        var usedReq = 0;
        try {
            usedReq = parseInt(localStorage.getItem('mg_gemini_req_day_v1_' + String(model) + '_' + mgGeminiDaySuffix()) || '0', 10) || 0;
        } catch (e1) { usedReq = 0; }

        var reqPct = null;
        var remReqStr = '—';
        var limReqStr = '—';
        if (rpdUnlimited) {
            remReqStr = '—';
            limReqStr = '∞';
        } else if (rpdCap != null && rpdCap > 0) {
            var ur = Math.min(usedReq, rpdCap);
            reqPct = (rpdCap - ur) / rpdCap;
            remReqStr = mgGroqFormatNum(Math.max(0, rpdCap - usedReq));
            limReqStr = mgGroqFormatNum(rpdCap);
        }

        var tpmCap = mgGeminiTpmCap(caps);
        var tpmUnlimited = !!caps.tpm_unlimited;
        var usedMinTok = 0;
        try {
            usedMinTok = parseInt(localStorage.getItem('mg_gemini_tpm_v1_' + String(model) + '_' + mgGeminiMinuteSuffix()) || '0', 10) || 0;
        } catch (e2) { usedMinTok = 0; }

        var tokPct = null;
        var remTokStr = '—';
        var limTokStr = '—';
        if (tpmUnlimited) {
            remTokStr = '—';
            limTokStr = '∞';
        } else if (tpmCap != null && tpmCap > 0) {
            var ut = Math.min(usedMinTok, tpmCap);
            tokPct = (tpmCap - ut) / tpmCap;
            remTokStr = mgGroqFormatNum(Math.max(0, tpmCap - usedMinTok));
            limTokStr = mgGroqFormatNum(tpmCap);
        }

        var dayTok = 0;
        try {
            dayTok = parseInt(localStorage.getItem('mg_gemini_tok_day_v1_' + String(model) + '_' + mgGeminiDaySuffix()) || '0', 10) || 0;
        } catch (e3) { dayTok = 0; }

        var html = '<div class="mg-groq-quota-title">Gemini quota (local est.) · ' + escapeHtml(model) + '</div>';
        html += '<div class="mg-groq-dial" title="Successful requests counted in this browser today vs published RPD (free tier if available, else paid).">' + mgGroqDialSvg(reqPct)
            + '<span class="mg-groq-dial-cap">' + remReqStr + ' / ' + limReqStr + '</span>'
            + '<span class="mg-groq-dial-sub">Requests (day)</span></div>';
        html += '<div class="mg-groq-dial" title="Tokens from usageMetadata summed in the current clock minute vs published TPM cap (same tier rule as requests).">' + mgGroqDialSvg(tokPct)
            + '<span class="mg-groq-dial-cap">' + remTokStr + ' / ' + limTokStr + '</span>'
            + '<span class="mg-groq-dial-sub">Tokens (minute)</span></div>';
        html += '<div class="mg-groq-dial-sub" style="width:100%;margin-top:2px;">Tokens logged today (sum): ' + mgGroqFormatNum(dayTok) + '</div>';
        html += '<div class="mg-groq-dial-sub" style="width:100%;opacity:0.85;">Not Google live quota — counts persist in this browser only.</div>';

        var qhKeys = qh && typeof qh === 'object' ? Object.keys(qh) : [];
        if (qhKeys.length) {
            var blob = '';
            try {
                blob = JSON.stringify(qh);
            } catch (e4) {
                blob = String(qh);
            }
            if (blob.length > 220) blob = blob.slice(0, 220) + '…';
            html += '<div class="mg-groq-dial-sub" style="width:100%;margin-top:2px;word-break:break-all;">Response headers: ' + escapeHtml(blob) + '</div>';
        }
        row.innerHTML = html;
    }

    window.MemoryGraphGeminiQuotaSync = function () {
        renderGeminiQuotaBar({});
    };

    window.MemoryGraphGeminiQuotaApplyResponse = function (res) {
        if (!res) return;
        if (res.error) return;
        var settings = (typeof window.getAgentSettings === 'function' && window.getAgentSettings()) || {};
        if (settings.provider !== 'gemini') return;
        if (res.gemini_model_limits && typeof res.gemini_model_limits === 'object' && settings.model) {
            if (!window.MEMORY_GRAPH_GEMINI_MODEL_LIMITS) window.MEMORY_GRAPH_GEMINI_MODEL_LIMITS = {};
            window.MEMORY_GRAPH_GEMINI_MODEL_LIMITS[settings.model] = res.gemini_model_limits;
        }
        if (res.choices && res.choices[0] && res.choices[0].message) {
            mgGeminiBumpLocal(settings.model, res.gemini_usage_metadata);
            renderGeminiQuotaBar({ quotaHeaders: res.gemini_quota_headers });
        }
    };

    var featherlessConcurrencyTimer = null;
    var featherlessTokenizeTimer = null;
    var featherlessLastConc = null;
    var featherlessLastToken = null;
    var featherlessLastTokenErr = null;

    function featherlessIsActive() {
        var s = (typeof window.getAgentSettings === 'function' && window.getAgentSettings()) || {};
        return s.provider === 'featherless';
    }

    function featherlessFlattenContent(c) {
        if (typeof c === 'string') return c;
        if (!Array.isArray(c)) return '';
        var parts = [];
        c.forEach(function (p) {
            if (!p || typeof p !== 'object') return;
            if (p.type === 'text' && p.text) parts.push(String(p.text));
        });
        return parts.join(' ');
    }

    function buildFeatherlessTokenizeText() {
        var chunks = [];
        getTurns().forEach(function (t) {
            if (!t || (t.role !== 'user' && t.role !== 'assistant')) return;
            var body = featherlessFlattenContent(t.content);
            if (body) chunks.push(String(t.role).toUpperCase() + ': ' + body);
        });
        if ($input.length) {
            var draft = String($input.val() || '').trim();
            if (draft) chunks.push('USER (draft): ' + draft);
        }
        return chunks.join('\n\n');
    }

    function stopFeatherlessMeter() {
        if (featherlessConcurrencyTimer) {
            clearInterval(featherlessConcurrencyTimer);
            featherlessConcurrencyTimer = null;
        }
        if (featherlessTokenizeTimer) {
            clearTimeout(featherlessTokenizeTimer);
            featherlessTokenizeTimer = null;
        }
    }

    function renderFeatherlessMeterRow() {
        var row = document.getElementById('mg-featherless-meter-row');
        if (!row) return;
        if (!featherlessIsActive()) {
            row.setAttribute('hidden', '');
            row.innerHTML = '';
            return;
        }
        row.removeAttribute('hidden');
        var s = (typeof window.getAgentSettings === 'function' && window.getAgentSettings()) || {};
        var model = s.model || 'glm47-flash';
        var html = '<div class="mg-groq-quota-title">Featherless · ' + escapeHtml(model) + '</div>';
        if (featherlessLastTokenErr) {
            html += '<div class="mg-featherless-line mg-featherless-line--err">' + escapeHtml(featherlessLastTokenErr) + '</div>';
        } else if (featherlessLastToken != null) {
            html += '<div class="mg-featherless-line">Prompt tokens (model tokenizer): <strong>' + featherlessLastToken + '</strong></div>';
            html += '<div class="mg-featherless-line mg-featherless-line--muted">Counts thread + current draft via /v1/tokenize.</div>';
        } else {
            html += '<div class="mg-featherless-line mg-featherless-line--muted">Prompt tokens: …</div>';
        }
        if (featherlessLastConc && featherlessLastConc.error) {
            html += '<div class="mg-featherless-line mg-featherless-line--err">' + escapeHtml(featherlessLastConc.error) + '</div>';
        } else if (featherlessLastConc) {
            var lim = featherlessLastConc.limit;
            var used = featherlessLastConc.used_cost != null ? featherlessLastConc.used_cost : 0;
            var rc = featherlessLastConc.request_count != null ? featherlessLastConc.request_count : 0;
            var limStr = lim == null ? '∞' : String(lim);
            html += '<div class="mg-featherless-line">Concurrency cost: <strong>' + used + '</strong> / <strong>' + limStr + '</strong> · In flight: <strong>' + rc + '</strong></div>';
            var reqs = featherlessLastConc.requests;
            if (Array.isArray(reqs) && reqs.length) {
                var bits = reqs.slice(0, 3).map(function (r) {
                    if (!r || typeof r !== 'object') return '';
                    var m = r.model ? String(r.model) : '?';
                    var c = r.cost != null ? r.cost : '';
                    var d = r.duration_ms != null ? r.duration_ms : '';
                    return escapeHtml(m) + (c !== '' ? ' (cost ' + c + ')' : '') + (d !== '' ? ' · ' + d + ' ms' : '');
                }).filter(Boolean);
                if (bits.length) {
                    html += '<div class="mg-featherless-line mg-featherless-line--muted">' + bits.join(' · ') + '</div>';
                }
            }
        } else {
            html += '<div class="mg-featherless-line mg-featherless-line--muted">Concurrency: …</div>';
        }
        row.innerHTML = html;
    }

    function fetchFeatherlessConcurrency() {
        if (!featherlessIsActive()) return;
        $.ajax({
            url: 'api/featherless_meter.php?action=concurrency',
            method: 'GET',
            dataType: 'json',
            cache: false
        }).done(function (data) {
            if (!data || !data.ok) {
                featherlessLastConc = { error: (data && data.error) ? String(data.error) : 'Concurrency unavailable' };
            } else {
                featherlessLastConc = {
                    limit: data.limit,
                    used_cost: data.used_cost,
                    request_count: data.request_count,
                    requests: data.requests || []
                };
            }
            renderFeatherlessMeterRow();
        }).fail(function (xhr) {
            var m = (xhr.responseJSON && xhr.responseJSON.error) ? String(xhr.responseJSON.error) : (xhr.statusText || 'Concurrency request failed');
            featherlessLastConc = { error: m };
            renderFeatherlessMeterRow();
        });
    }

    function runFeatherlessTokenize() {
        if (!featherlessIsActive()) return;
        var s = (typeof window.getAgentSettings === 'function' && window.getAgentSettings()) || {};
        var model = s.model || 'glm47-flash';
        var text = buildFeatherlessTokenizeText();
        featherlessLastTokenErr = null;
        featherlessLastToken = null;
        renderFeatherlessMeterRow();
        $.ajax({
            url: 'api/featherless_meter.php',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({ action: 'tokenize', model: model, text: text }),
            dataType: 'json',
            cache: false
        }).done(function (data) {
            if (data && data.ok && data.token_count != null) {
                featherlessLastToken = data.token_count;
            } else {
                featherlessLastTokenErr = (data && data.error) ? String(data.error) : 'Tokenize failed';
            }
            renderFeatherlessMeterRow();
        }).fail(function (xhr) {
            var m = (xhr.responseJSON && xhr.responseJSON.error) ? String(xhr.responseJSON.error) : (xhr.statusText || 'Tokenize failed');
            featherlessLastTokenErr = m;
            renderFeatherlessMeterRow();
        });
    }

    window.MemoryGraphFeatherlessScheduleTokenize = function () {
        if (!featherlessIsActive()) return;
        if (featherlessTokenizeTimer) clearTimeout(featherlessTokenizeTimer);
        featherlessTokenizeTimer = setTimeout(function () {
            featherlessTokenizeTimer = null;
            runFeatherlessTokenize();
        }, 450);
    };

    window.MemoryGraphFeatherlessMeterSync = function () {
        stopFeatherlessMeter();
        featherlessLastConc = null;
        featherlessLastToken = null;
        featherlessLastTokenErr = null;
        if (!featherlessIsActive()) {
            renderFeatherlessMeterRow();
            return;
        }
        renderFeatherlessMeterRow();
        fetchFeatherlessConcurrency();
        featherlessConcurrencyTimer = setInterval(fetchFeatherlessConcurrency, 2000);
        window.MemoryGraphFeatherlessScheduleTokenize();
    };

    window.MemoryGraphFeatherlessRefreshMeters = function () {
        if (!featherlessIsActive()) return;
        fetchFeatherlessConcurrency();
        window.MemoryGraphFeatherlessScheduleTokenize();
    };
})();
