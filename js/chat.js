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
            }
            $row.append($bubble);
            $thread.append($row);
        });
        var el = $thread[0];
        if (el && el.parentElement) {
            el.parentElement.scrollTop = el.parentElement.scrollHeight;
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
            $('<span class="chat-queue-item-text">').text(qlabel).appendTo($item);
            var $actions = $('<div class="chat-queue-item-actions">');
            $('<button type="button" title="Edit">&#9998;</button>').on('click', function () {
                $input.val(item.text).focus();
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

    function addToQueue(text, parts) {
        var p = Array.isArray(parts) ? parts.slice() : [];
        promptQueue.push({ text: text != null ? text : '', parts: p, id: Date.now() + '_' + Math.random().toString(36).slice(2) });
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
            setTimeout(function () { sendMessageInternal(nt, np); }, 100);
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

    function userTurnHistoryLine(text, partsSummary) {
        var t = (text || '').trim();
        if (partsSummary) {
            return t ? (t + '\n\n[Attached: ' + partsSummary + ']') : ('[Attached: ' + partsSummary + ']');
        }
        return t;
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
        var text = ($input.val() || '').trim();
        var att = window.__mgMainChatAttachments;
        var rawParts = (att && typeof att.getParts === 'function') ? att.getParts() : [];
        var parts = Array.isArray(rawParts) ? rawParts.slice() : [];
        if (!text && !parts.length) return;
        $input.val('');
        if (currentRequest) {
            addToQueue(text, parts);
            if (att && typeof att.clear === 'function') {
                att.clear();
            }
            return;
        }
        sendMessageInternal(text, parts);
    }

    function sendMessageInternal(text, attachmentParts) {
        wasStopped = false;
        attachmentParts = attachmentParts || [];
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
        var historyLine = userTurnHistoryLine(text, summarizePartsForHistory(attachmentParts));
        messages.push({ role: 'user', content: userPayload });
        var requestId = 'chat_' + Date.now() + '_' + Math.floor(Math.random() * 100000);
        lastGraphRefreshToken = '';
        inFlightMainRequestId = requestId;
        inFlightMainSessionId = getActiveSessionId();
        lastActivityTailTBySession[inFlightMainSessionId] = lastActivityTailTBySession[inFlightMainSessionId] || 0;

        if (typeof window.agentState !== 'undefined') window.agentState.setThinking(true);
        startStatusPolling(requestId);

        currentRequest = $.ajax({
            url: 'api/chat.php',
            method: 'POST',
            timeout: LONG_CHAT_AJAX_MS,
            contentType: 'application/json',
            data: JSON.stringify({
                requestId: requestId,
                chatSessionId: chatSessionId,
                provider: settings.provider || 'mercury',
                model: settings.model || 'mercury-2',
                systemPrompt: (settings.systemPrompt != null && settings.systemPrompt !== '') ? settings.systemPrompt : '',
                temperature: settings.temperature != null ? settings.temperature : 0.7,
                messages: messages
            })
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
                getTurns().push({ role: 'user', content: historyLine });
                getTurns().push({ role: 'assistant', content: content });
                saveSessionBundle();
                if (res && res.jobToRun && typeof window.MemoryGraphRunJob === 'function') {
                    var jobs = Array.isArray(res.jobToRun) ? res.jobToRun : [res.jobToRun];
                    jobs.forEach(function (job) {
                        if (job && job.name && job.content) {
                            window.MemoryGraphRunJob(job.name, job.content, { nodeId: job.nodeId || null });
                        }
                    });
                }
                if (typeof window.applyAgentConfig === 'function') {
                    $.getJSON('api/agent_config.php').done(function (data) { if (data) window.applyAgentConfig(data); }).fail(function () {});
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
                showNotification(displayMsg, text, errBody);
                getTurns().push({ role: 'user', content: text });
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
                } else if (!stopPollingTimeout) {
                    stopPollingTimeout = setTimeout(function () {
                        stopStatusPolling();
                    }, RECENT_ACTIVITY_HOLD_MS);
                }
                if (typeof window.MemoryGraphRefresh === 'function') window.MemoryGraphRefresh();
                processNextInQueue();
                focusChatInputSoon();
                var mgAtt = window.__mgMainChatAttachments;
                if (mgAtt && typeof mgAtt.clear === 'function') {
                    mgAtt.clear();
                }
            });
    }

    $(function () {
        if (typeof window.MemoryGraphInitChatAttachments === 'function') {
            window.MemoryGraphInitChatAttachments();
        }
        renderSimpleChatThread();
        focusChatInputSoon();
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
})();
