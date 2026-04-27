/**
 * Background job runner for job nodes.
 */
(function () {
    var listEl = document.getElementById('running-jobs-list');
    if (!listEl || typeof jQuery === 'undefined') return;

    var jobs = {};
    var responseCache = {};
    var renderQueued = false;
    var graphStateQueued = false;
    var activePollRequests = {};
    var pollTimer = null;
    var POLL_INTERVAL_MS = 150;

    function looksLikeHtmlSnippet(code) {
        if (!code) return false;
        return /<\/?[a-z][\s\S]*>/i.test(code) || /<script[\s\S]*>/i.test(code) || /<canvas[\s\S]*>/i.test(code);
    }

    function looksLikeJavaScriptSnippet(code) {
        if (!code) return false;
        return /\b(const|let|var|function|document\.|window\.|new\s+[A-Z]|console\.|setTimeout|setInterval)\b/.test(code);
    }

    function inferCodeLanguage(text) {
        if (looksLikeHtmlSnippet(text)) return 'html';
        if (looksLikeJavaScriptSnippet(text)) return 'javascript';
        return '';
    }

    var LONG_JOB_AJAX_MS = 600000;

    function memoryGraphNodeIdForSubAgentStem(stem) {
        var s = (stem == null) ? '' : String(stem).replace(/\.md$/i, '');
        s = s.split(/[/\\]/).pop() || 'agent';
        var slug = s.toLowerCase().replace(/[^a-z0-9_]+/g, '_').replace(/^_+|_+$/g, '');
        return 'sub_agent_file_' + (slug || 'agent');
    }

    /**
     * Parse optional YAML front matter: ---\nassignee: sub_agent\nsubAgent: name\n---\n
     * @returns {{ runAssignee: string, subAgent: string|null, subAgentNodeId: string|null, body: string }}
     */
    function parseJobFileForRun(raw) {
        var out = {
            runAssignee: 'main',
            subAgent: null,
            subAgentNodeId: null,
            body: String(raw || '')
        };
        var t = String(raw || '').replace(/^\uFEFF/, '');
        t = t.replace(/^[ \t\x0B\r\n]+/, '');
        if (t.length < 4 || t.indexOf('---') !== 0) {
            return out;
        }
        var lines = t.split(/\r?\n/);
        if (lines.length < 2 || (lines[0] || '').replace(/\r$/, '') !== '---') {
            return out;
        }
        var meta = {};
        var i;
        for (i = 1; i < lines.length; i++) {
            var Lr = (lines[i] || '').replace(/\r$/, '');
            if (Lr === '---') {
                break;
            }
            var m = /^([a-zA-Z0-9_]+)\s*:\s*(.*)$/.exec(Lr);
            if (m) {
                var k = (m[1] || '').trim();
                var v = (m[2] || '').replace(/^['"]|['"]$/g, '').trim();
                if (k) {
                    meta[k] = v;
                }
            }
        }
        if (i >= lines.length || (lines[i] || '').replace(/\r$/, '') !== '---') {
            return out;
        }
        out.body = lines.slice(i + 1).join('\n').replace(/^\n+/, '');
        var assignee = String(meta.assignee != null ? meta.assignee : 'main').toLowerCase();
        if (assignee === 'sub_agent' || assignee === 'subagent' || assignee === 'sub' || assignee === 'sub-agent') {
            out.runAssignee = 'sub_agent';
        } else {
            out.runAssignee = 'main';
        }
        var subName = (meta.subAgent != null && String(meta.subAgent).trim() !== '') ? String(meta.subAgent) : (meta.sub_agent != null ? String(meta.sub_agent) : '');
        if (meta.subAgentName != null && String(meta.subAgentName).trim() !== '') {
            subName = String(meta.subAgentName);
        }
        subName = subName.split(/[/\\]/).pop() || '';
        subName = subName.replace(/\.md$/i, '').trim() || null;
        var fromNode = String(meta.subAgentNodeId != null ? meta.subAgentNodeId : (meta.sub_agent_node_id != null ? meta.sub_agent_node_id : '')).trim();
        if (out.runAssignee === 'sub_agent' && subName) {
            out.subAgent = subName;
            out.subAgentNodeId = memoryGraphNodeIdForSubAgentStem(subName);
        } else if (out.runAssignee === 'sub_agent' && fromNode.indexOf('sub_agent_file_') === 0) {
            out.subAgentNodeId = fromNode;
        }
        return out;
    }

    window.MemoryGraphParseJobFile = parseJobFileForRun;

    /**
     * Build full .md file text with optional front matter (Main / Sub-agent).
     * @param {string} assigneeType 'main' | 'sub_agent'
     * @param {string} subAgentStem  filename stem, no .md, or empty
     * @param {string} body  task list body only
     */
    window.MemoryGraphBuildJobFile = function (assigneeType, subAgentStem, body) {
        var b = (body == null) ? '' : String(body);
        if (assigneeType === 'sub_agent' && subAgentStem && String(subAgentStem).trim() !== '') {
            var st = String(subAgentStem).trim().replace(/\.md$/i, '');
            return '---\nassignee: sub_agent\nsubAgent: ' + st + '\n---\n\n' + b;
        }
        return b;
    };

    function parseJobSteps(content) {
        return String(content || '')
            .split(/\r?\n/)
            .map(function (line) { return line.trim(); })
            .filter(function (line) {
                return /^(-|\*|\d+\.)\s+/.test(line);
            })
            .map(function (line) {
                return line.replace(/^(-|\*|\d+\.)\s+/, '').trim();
            })
            .filter(function (line) { return line !== ''; });
    }

    function buildStepPrompt(name, stepText, stepIndex, totalSteps) {
        return [
            'You are executing one step from a larger job file.',
            'Complete only this step well.',
            'If the best output is a visual/chart/demo, return a fenced html code block that renders directly in-browser.',
            'If the output is text, return clean readable markdown/plain text without extra surrounding commentary.',
            'Do not return a table wrapper for the whole job.',
            'Job: ' + name,
            'Step ' + stepIndex + ' of ' + totalSteps + ': ' + stepText
        ].join('\n');
    }

    function formatStepResponse(step) {
        if (!step) return '';
        var text = String(step.response || '');
        var trimmed = text.trim();
        if (!trimmed) return '(No response returned.)';
        if (/```/.test(trimmed)) return trimmed;
        var language = inferCodeLanguage(trimmed);
        if (language) {
            return '```' + language + '\n' + trimmed + '\n```';
        }
        return trimmed;
    }

    function buildFinalJobResponse(name, steps) {
        var parts = [];
        parts.push('Job: ' + name);
        parts.push('');
        (steps || []).forEach(function (step, index) {
            parts.push('Step ' + (index + 1) + ': ' + step.task);
            parts.push('');
            parts.push(formatStepResponse(step));
            parts.push('');
        });
        return parts.join('\n');
    }

    function escapeHtml(value) {
        if (!value) return '';
        var div = document.createElement('div');
        div.textContent = value;
        return div.innerHTML;
    }

    function getRunningNodeIds() {
        return Object.keys(jobs).filter(function (name) {
            return jobs[name] && jobs[name].state === 'running' && (jobs[name].nodeId || jobs[name].subAgentNodeId);
        }).map(function (name) {
            return jobs[name].subAgentNodeId || jobs[name].nodeId;
        });
    }

    function uniq(list) {
        var out = [];
        (list || []).forEach(function (item) {
            if (out.indexOf(item) === -1) out.push(item);
        });
        return out;
    }

    function signalGraphActivity(sections, nodeIds, durationMs) {
        if (typeof window.MemoryGraphSignalActivity !== 'function') return;
        window.MemoryGraphSignalActivity({
            sections: Array.isArray(sections) ? sections : [],
            nodeIds: Array.isArray(nodeIds) ? nodeIds : [],
            durationMs: durationMs || 2600
        });
    }

    function syncGraphState() {
        if (typeof window.agentState === 'undefined') return;
        var runningIds = getRunningNodeIds();
        var aggregatedToolIds = [];
        var aggregatedMemoryIds = [];
        var aggregatedInstructionIds = [];
        var aggregatedMcpIds = [];
        var aggregatedSubAgentIds = [];
        var aggregatedExecutionDetails = {};
        var anyGettingTools = false;
        var anyCheckingMemory = false;
        var anyCheckingInstructions = false;
        var anyCheckingMcps = false;
        var anyCheckingJobs = runningIds.length > 0;

        Object.keys(jobs).forEach(function (name) {
            var job = jobs[name];
            var status = job && job.lastStatus ? job.lastStatus : null;
            if (!job || job.state !== 'running' || !status) return;
            var executionDetails = status.executionDetailsByNode && typeof status.executionDetailsByNode === 'object'
                ? status.executionDetailsByNode
                : {};
            anyGettingTools = anyGettingTools || !!status.gettingAvailTools;
            anyCheckingMemory = anyCheckingMemory || !!status.checkingMemory;
            anyCheckingInstructions = anyCheckingInstructions || !!status.checkingInstructions;
            anyCheckingMcps = anyCheckingMcps || !!status.checkingMcps || !!executionDetails.mcps;
            aggregatedToolIds = aggregatedToolIds.concat(status.activeToolIds || []);
            aggregatedMemoryIds = aggregatedMemoryIds.concat(status.activeMemoryIds || []);
            aggregatedInstructionIds = aggregatedInstructionIds.concat(status.activeInstructionIds || []);
            aggregatedMcpIds = aggregatedMcpIds.concat(status.activeMcpIds || []);
            aggregatedSubAgentIds = aggregatedSubAgentIds.concat(status.activeSubAgentIds || []);
            Object.keys(executionDetails).forEach(function (key) {
                aggregatedExecutionDetails[key] = executionDetails[key];
                if (key.indexOf('tool_') === 0) aggregatedToolIds.push(key);
                if (key.indexOf('memory_file_') === 0) aggregatedMemoryIds.push(key);
                if (key.indexOf('instruction_file_') === 0) aggregatedInstructionIds.push(key);
                if (key.indexOf('mcp_server_') === 0) aggregatedMcpIds.push(key);
                if (key.indexOf('sub_agent_file_') === 0) aggregatedSubAgentIds.push(key);
            });
        });

        if (typeof window.agentState.applyBackgroundJobState === 'function') {
            window.agentState.applyBackgroundJobState({
                checkingJobs: anyCheckingJobs,
                gettingAvailTools: anyGettingTools,
                checkingMemory: anyCheckingMemory,
                checkingInstructions: anyCheckingInstructions,
                checkingMcps: anyCheckingMcps,
                activeJobIds: runningIds,
                activeToolIds: uniq(aggregatedToolIds),
                activeMemoryIds: uniq(aggregatedMemoryIds),
                activeInstructionIds: uniq(aggregatedInstructionIds),
                activeMcpIds: uniq(aggregatedMcpIds),
                activeSubAgentIds: uniq(aggregatedSubAgentIds),
                executionDetailsByNode: aggregatedExecutionDetails,
                durationMs: anyCheckingJobs ? 3200 : 2400
            });
        } else {
            window.agentState.setBackgroundCheckingJobs(runningIds.length > 0);
            window.agentState.setBackgroundJobIds(runningIds);
            window.agentState.setBackgroundGettingAvailTools(anyGettingTools);
            window.agentState.setBackgroundCheckingMemory(anyCheckingMemory);
            window.agentState.setBackgroundCheckingInstructions(anyCheckingInstructions);
            window.agentState.setBackgroundCheckingMcps(anyCheckingMcps);
            window.agentState.setBackgroundActiveToolIds(uniq(aggregatedToolIds));
            window.agentState.setBackgroundActiveMemoryIds(uniq(aggregatedMemoryIds));
            window.agentState.setBackgroundActiveInstructionIds(uniq(aggregatedInstructionIds));
            window.agentState.setBackgroundActiveMcpIds(uniq(aggregatedMcpIds));
            window.agentState.setBackgroundExecutionDetailsByNode(aggregatedExecutionDetails);
            signalGraphActivity(
                [
                    runningIds.length ? 'agent' : '',
                    anyGettingTools ? 'tools' : '',
                    anyCheckingMemory ? 'memory' : '',
                    anyCheckingInstructions ? 'instructions' : '',
                    anyCheckingMcps ? 'mcps' : '',
                    anyCheckingJobs ? 'jobs' : '',
                    aggregatedSubAgentIds.length || aggregatedExecutionDetails.sub_agents ? 'sub_agents' : ''
                ].filter(Boolean),
                ['agent'].concat(uniq(aggregatedToolIds.concat(aggregatedMemoryIds, aggregatedInstructionIds, aggregatedMcpIds, aggregatedSubAgentIds, runningIds))),
                anyCheckingJobs ? 3200 : 2400
            );
        }
        if (typeof window.MemoryGraphUpdateExecutionPanel === 'function') window.MemoryGraphUpdateExecutionPanel();
    }

    function scheduleGraphStateSync() {
        if (graphStateQueued) return;
        graphStateQueued = true;
        requestAnimationFrame(function () {
            graphStateQueued = false;
            syncGraphState();
        });
    }

    function scheduleRenderJobs() {
        if (renderQueued) return;
        renderQueued = true;
        requestAnimationFrame(function () {
            renderQueued = false;
            renderJobs();
        });
    }

    function setJobState(name, patch) {
        jobs[name] = jobs[name] || {};
        Object.keys(patch || {}).forEach(function (key) {
            jobs[name][key] = patch[key];
        });
        scheduleGraphStateSync();
        scheduleRenderJobs();
    }

    function scheduleCompletedRemoval(name) {
        var job = jobs[name];
        if (!job) return;
        if (job.removeTimeout) {
            clearTimeout(job.removeTimeout);
        }
        job.expiresAt = Date.now() + 60000;
        job.removeTimeout = setTimeout(function () {
            if (jobs[name] && jobs[name].state === 'completed') {
                delete jobs[name];
                scheduleGraphStateSync();
                scheduleRenderJobs();
            }
        }, 60000);
    }

    function renderJobs() {
        var names = Object.keys(jobs);
        if (!names.length) {
            listEl.innerHTML = '<div class="running-job-empty">No jobs running.</div>';
            return;
        }
        listEl.innerHTML = '';
        names.forEach(function (name) {
            var job = jobs[name] || {};
            var item = document.createElement('div');
            item.className = 'running-job-item';

            var spinner = job.state === 'running'
                ? '<span class="running-job-spinner" aria-hidden="true"></span>'
                : '';
            var remainingText = '';
            if (job.state === 'completed' && job.expiresAt) {
                remainingText = '\nAuto hides in ' + Math.max(1, Math.ceil((job.expiresAt - Date.now()) / 1000)) + 's.';
            }
            var actionsHtml = '';
            if (job.state === 'running') {
                actionsHtml = '<button type="button" class="running-job-btn" data-action="stop">Stop</button>';
            } else if (job.state === 'completed') {
                actionsHtml = '<button type="button" class="running-job-btn" data-action="view">View response</button>' +
                    '<button type="button" class="running-job-btn" data-action="remove">Clear</button>';
            } else {
                actionsHtml = '<button type="button" class="running-job-btn" data-action="remove">Clear</button>';
            }

            item.innerHTML =
                '<div class="running-job-head">' +
                    '<div class="running-job-name">' + escapeHtml(name) + '</div>' +
                    spinner +
                '</div>' +
                '<div class="running-job-status">' + escapeHtml((job.statusText || 'Queued...') + remainingText) + '</div>' +
                '<div class="running-job-actions">' +
                    actionsHtml +
                '</div>';

            var buttons = item.querySelectorAll('.running-job-btn');
            Array.prototype.forEach.call(buttons, function (button) {
                button.addEventListener('click', function () {
                    var action = button.getAttribute('data-action');
                    if (action === 'stop') {
                        stopJobByName(name);
                    } else if (action === 'view') {
                        if (typeof window.MemoryGraphShowResponseModal === 'function') {
                            var promptForView = (jobs[name] && jobs[name].cronViewPrompt)
                                ? jobs[name].cronViewPrompt
                                : (jobs[name] && jobs[name].promptText ? jobs[name].promptText : ('Job: ' + name));
                            window.MemoryGraphShowResponseModal(promptForView, responseCache[name] || (jobs[name] && jobs[name].fullResponse) || '');
                        }
                    } else {
                        if (jobs[name] && jobs[name].removeTimeout) {
                            clearTimeout(jobs[name].removeTimeout);
                        }
                        delete jobs[name];
                        delete responseCache[name];
                        scheduleGraphStateSync();
                        scheduleRenderJobs();
                    }
                });
            });
            listEl.appendChild(item);
        });
    }

    function stopJobByName(name) {
        var job = jobs[name];
        if (!job) return;
        if (job.request && typeof job.request.abort === 'function') {
            job.request.abort();
        }
        if (job.removeTimeout) {
            clearTimeout(job.removeTimeout);
            job.removeTimeout = null;
        }
        job.lastStatus = null;
        setJobState(name, {
            state: 'stopped',
            statusText: 'Stopped.'
        });
    }

    function stopPollingJob(name) {
        delete activePollRequests[name];
        if (!Object.keys(activePollRequests).length && pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
    }

    function cronProgressSummary(status) {
        if (!status) return 'Scheduled run in progress…';
        if (!status.thinking) return 'Finishing up…';
        var parts = [];
        if (status.gettingAvailTools) parts.push('listing tools');
        if (status.checkingMemory || (status.memoryToolExecuting && !status.checkingMemory)) parts.push('memory');
        if (status.checkingInstructions) parts.push('instructions');
        if (status.checkingResearch) parts.push('research');
        if (status.checkingRules) parts.push('rules');
        if (status.checkingMcps || (status.executionDetailsByNode && status.executionDetailsByNode.mcps)) parts.push('MCP');
        if (status.checkingJobs) parts.push('jobs');
        var tools = status.activeToolIds && status.activeToolIds.length;
        if (tools) parts.push('tools (' + status.activeToolIds.slice(0, 2).join(', ') + (tools > 2 ? '…' : '') + ')');
        if (parts.length) return 'Cron: ' + parts.join(' · ');
        return 'Cron: agent thinking…';
    }

    function ensurePollTimer() {
        if (pollTimer) return;
        pollTimer = setInterval(function () {
            Object.keys(activePollRequests).forEach(function (name) {
                var requestId = activePollRequests[name];
                if (!requestId || !jobs[name] || jobs[name].requestId !== requestId) {
                    delete activePollRequests[name];
                    return;
                }
                jQuery.getJSON('api/chat_status.php', { request_id: requestId })
                    .done(function (status) {
                        if (!jobs[name] || jobs[name].requestId !== requestId || activePollRequests[name] !== requestId) return;
                        jobs[name].lastStatus = status || {};
                        scheduleGraphStateSync();
                        if (jobs[name].isCron) {
                            setJobState(name, { statusText: cronProgressSummary(status || {}) });
                        } else if (status && status.thinking && jobs[name].statusText !== 'Running in background...') {
                            setJobState(name, {
                                statusText: 'Running in background...'
                            });
                        }
                    })
                    .fail(function () {});
            });
            if (!Object.keys(activePollRequests).length && pollTimer) {
                clearInterval(pollTimer);
                pollTimer = null;
            }
        }, POLL_INTERVAL_MS);
    }

    function startJobPolling(name, requestId) {
        var job = jobs[name];
        if (!job) return;
        job.pollRequestId = requestId;
        activePollRequests[name] = requestId;
        ensurePollTimer();
    }

    function finishJob(name, state, statusText) {
        setJobState(name, {
            state: state,
            statusText: statusText
        });
        if (jobs[name]) jobs[name].lastStatus = null;
        stopPollingJob(name);
        scheduleGraphStateSync();
        if (state === 'completed') {
            scheduleCompletedRemoval(name);
        }
    }

    function runNextJobStep(name) {
        var job = jobs[name];
        if (!job || job.state !== 'running') return;

        if (job.currentStepIndex >= job.steps.length) {
            var finalResponse = buildFinalJobResponse(name, job.results || []);
            responseCache[name] = finalResponse;
            if (jobs[name]) jobs[name].fullResponse = finalResponse;
            finishJob(name, 'completed', 'Completed all ' + job.steps.length + ' steps.');
            return;
        }

        var settings = (typeof window.getAgentSettings === 'function' && window.getAgentSettings()) || {};
        var stepIndex = job.currentStepIndex;
        var stepText = job.steps[stepIndex];
        var isSub = job.runAssignee === 'sub_agent' && (job.subAgentName || job.subAgentNodeId);
        var mainRequestId = 'job_' + Date.now() + '_' + Math.floor(Math.random() * 100000);
        var subStatusId = (job.runToken != null) ? ('subjob_' + job.runToken + '_s' + stepIndex) : ('subjob_' + Date.now() + '_' + stepIndex);
        var requestId = isSub ? subStatusId : mainRequestId;
        var prompt = buildStepPrompt(name, stepText, stepIndex + 1, job.steps.length);
        var subNameForApi = (job.subAgentName != null && String(job.subAgentName).trim() !== '') ? String(job.subAgentName).trim() : '';
        if (isSub && !subNameForApi) {
            var resultsErr = (job.results || []).slice();
            resultsErr.push({
                task: stepText,
                response: 'Error: sub-agent name missing for this job (add subAgent: in the job header).'
            });
            setJobState(name, {
                results: resultsErr,
                currentStepIndex: stepIndex + 1,
                statusText: 'Step failed: no sub-agent name.'
            });
            runNextJobStep(name);
            return;
        }

        setJobState(name, {
            requestId: requestId,
            promptText: prompt,
            statusText: (isSub ? ('Sub-agent step ' + (stepIndex + 1) + ' of ' + job.steps.length) : ('Running step ' + (stepIndex + 1) + ' of ' + job.steps.length)) + ': ' + stepText
        });

        var request;
        if (isSub) {
            request = jQuery.ajax({
                url: 'api/sub_agent_run.php',
                method: 'POST',
                contentType: 'application/json',
                timeout: LONG_JOB_AJAX_MS,
                data: JSON.stringify({
                    name: subNameForApi,
                    prompt: prompt,
                    chatSessionId: job.subChatSessionId != null ? String(job.subChatSessionId) : '',
                    statusRequestId: subStatusId
                })
            });
        } else {
            request = jQuery.ajax({
                url: 'api/chat.php',
                method: 'POST',
                contentType: 'application/json',
                timeout: LONG_JOB_AJAX_MS,
                data: JSON.stringify({
                    requestId: mainRequestId,
                    provider: settings.provider || 'mercury',
                    model: settings.model || 'mercury-2',
                    systemPrompt: settings.systemPrompt || '',
                    temperature: settings.temperature != null ? settings.temperature : 0.7,
                    messages: [{ role: 'user', content: prompt }]
                })
            });
        }

        if (jobs[name]) jobs[name].request = request;
        startJobPolling(name, requestId);

        request.done(function (res) {
            if (res && res.reloadWebAppsList) {
                if (typeof window.MemoryGraphReloadAppsList === 'function') {
                    window.MemoryGraphReloadAppsList();
                }
                if (typeof window.MemoryGraphReloadSimpleAppsSection === 'function') {
                    window.MemoryGraphReloadSimpleAppsSection();
                }
            }
            var contentText = '';
            if (isSub) {
                if (res && res.error) {
                    contentText = 'Error: ' + String(res.error);
                } else if (res && res.response != null) {
                    contentText = String(res.response);
                }
            } else {
                if (typeof window.MemoryGraphExtractAssistantFromChatResponse === 'function') {
                    contentText = window.MemoryGraphExtractAssistantFromChatResponse(res);
                } else if (res && res.choices && res.choices[0] && res.choices[0].message) {
                    var mc = res.choices[0].message.content;
                    contentText = typeof mc === 'string' ? mc : '';
                }
            }
            var results = job.results || [];
            results.push({
                task: stepText,
                response: contentText
            });
            setJobState(name, {
                results: results,
                currentStepIndex: stepIndex + 1,
                statusText: 'Completed step ' + (stepIndex + 1) + ' of ' + job.steps.length + '.'
            });
            runNextJobStep(name);
        }).fail(function (xhr) {
            if (xhr && xhr.statusText === 'abort') {
                finishJob(name, 'stopped', 'Stopped.');
                return;
            }
            var msg = (xhr && xhr.responseJSON && xhr.responseJSON.error) || (xhr && xhr.statusText) || 'Step failed';
            if (msg && typeof msg === 'object') {
                msg = (msg.message !== undefined && typeof msg.message === 'string') ? msg.message : JSON.stringify(msg);
            }
            var displayMsg = (msg && String(msg).trim()) || 'Step failed';
            var results = (jobs[name] && jobs[name].results) ? jobs[name].results.slice() : [];
            results.push({
                task: stepText,
                response: 'Error: ' + displayMsg
            });
            setJobState(name, {
                results: results,
                currentStepIndex: stepIndex + 1
            });
            runNextJobStep(name);
        }).always(function () {
            if (jobs[name] && jobs[name].pollRequestId === requestId) {
                jobs[name].pollRequestId = null;
            }
            stopPollingJob(name);
            if (jobs[name] && jobs[name].requestId === requestId) {
                jobs[name].request = null;
            }
            scheduleGraphStateSync();
            scheduleRenderJobs();
        });
    }

    function runJob(name, content, options) {
        if (!name) return;
        var nodeId = options && options.nodeId ? options.nodeId : null;
        var parsed = parseJobFileForRun(String(content || ''));
        var bodyForSteps = parsed && parsed.body != null ? String(parsed.body) : String(content || '');
        var steps = parseJobSteps(bodyForSteps);

        if (jobs[name] && jobs[name].state === 'running') {
            stopJobByName(name);
        }
        delete responseCache[name];

        if (parsed.runAssignee === 'sub_agent' && !parsed.subAgent && !parsed.subAgentNodeId) {
            setJobState(name, {
                name: name,
                nodeId: nodeId,
                state: 'error',
                statusText: 'Job header says sub_agent but `subAgent` (or `subAgentNodeId`) is missing. Save the job with a sub-agent selected.'
            });
            return;
        }

        if (!steps.length) {
            setJobState(name, {
                name: name,
                nodeId: nodeId,
                state: 'error',
                statusText: 'No job steps found. Use markdown list items like "- Task" in the task body (below the header).'
            });
            return;
        }

        var runToken = Date.now() + '_' + Math.floor(Math.random() * 100000);
        var safeName = String(name).replace(/[^a-z0-9._-]+/gi, '_');
        var subChatSessionId = 'jcr_' + safeName + '_' + runToken;
        var subNode = null;
        if (parsed.runAssignee === 'sub_agent') {
            if (parsed.subAgentNodeId && String(parsed.subAgentNodeId).indexOf('sub_agent_file_') === 0) {
                subNode = parsed.subAgentNodeId;
            } else if (parsed.subAgent) {
                subNode = memoryGraphNodeIdForSubAgentStem(parsed.subAgent);
            }
        }

        setJobState(name, {
            name: name,
            nodeId: nodeId,
            subAgentNodeId: subNode,
            runAssignee: parsed.runAssignee,
            subAgentName: parsed.runAssignee === 'sub_agent' ? parsed.subAgent : null,
            subChatSessionId: subChatSessionId,
            runToken: runToken,
            state: 'running',
            statusText: 'Queued ' + steps.length + ' steps...',
            steps: steps,
            currentStepIndex: 0,
            results: [],
            promptText: String(content || ''),
            fullResponse: '',
            expiresAt: 0,
            removeTimeout: null
        });

        runNextJobStep(name);
    }

    window.MemoryGraphRunJob = runJob;
    window.MemoryGraphStopJobByName = stopJobByName;
    window.MemoryGraphIsJobRunning = function (name) {
        return !!(jobs[name] && jobs[name].state === 'running');
    };
    window.MemoryGraphResyncBackgroundGraphState = scheduleGraphStateSync;
    window.MemoryGraphSyncBackgroundGraphStateNow = function () {
        graphStateQueued = false;
        syncGraphState();
    };

    setInterval(function () {
        var hasCompleted = Object.keys(jobs).some(function (name) {
            return jobs[name] && jobs[name].state === 'completed' && jobs[name].expiresAt;
        });
        if (hasCompleted) scheduleRenderJobs();
    }, 1000);

    var prevCronActiveRequestIds = {};

    function buildCronCompletedViewText(jobRow, apiRes) {
        var promptLine = (jobRow && jobRow.promptText) ? String(jobRow.promptText) : '';
        if (apiRes && apiRes.ok && apiRes.result) {
            var r = apiRes.result;
            var promptShown = (r.cronPrompt && String(r.cronPrompt).trim() !== '')
                ? String(r.cronPrompt)
                : (promptLine || '(scheduled cron)');
            var parts = [];
            parts.push('Prompt');
            parts.push(promptShown);
            parts.push('');
            parts.push('---');
            parts.push('');
            if (r.ok === false || (r.error && String(r.error).trim() !== '')) {
                parts.push('Run status: failed or incomplete');
                parts.push(String(r.error || 'Unknown error'));
                parts.push('');
            } else {
                parts.push('Run status: success');
                parts.push('');
            }
            var body = (r.assistantContent && String(r.assistantContent).trim() !== '')
                ? String(r.assistantContent)
                : (r.summary && String(r.summary).trim() !== '' ? String(r.summary) : '');
            parts.push('Response');
            parts.push(body !== '' ? body : '(No assistant message body — the model may have only used tools. Check Research / Memory in the app.)');
            return parts.join('\n');
        }
        return [
            'Prompt',
            promptLine || '(scheduled cron)',
            '',
            '---',
            '',
            'Response',
            'Could not load saved cron output (missing or expired). Open chat history or research files for details.'
        ].join('\n');
    }

    function syncCronActiveRuns() {
        jQuery.getJSON('api/cron.php?action=list_active')
            .done(function (data) {
                var runs = (data && data.runs) ? data.runs : [];
                var nowMap = {};
                runs.forEach(function (r) {
                    if (!r || !r.requestId) return;
                    var rid = r.requestId;
                    nowMap[rid] = true;
                    var key = 'cron:' + rid;
                    if (!jobs[key]) {
                        setJobState(key, {
                            name: '[Cron] ' + (r.jobName || 'Scheduled job'),
                            nodeId: r.nodeId || null,
                            state: 'running',
                            statusText: 'Scheduled run starting…',
                            requestId: rid,
                            isCron: true,
                            cronJobName: r.jobName || '',
                            promptText: 'Scheduled cron job: ' + (r.jobName || r.jobId || rid)
                        });
                        startJobPolling(key, rid);
                        try {
                            window.alert('Cron job started: ' + (r.jobName || r.jobId || 'scheduled job') + '\n\nProgress is shown under Running Jobs (and on the graph).');
                        } catch (e1) {}
                    }
                });
                Object.keys(prevCronActiveRequestIds).forEach(function (rid) {
                    if (nowMap[rid]) return;
                    var key = 'cron:' + rid;
                    var j = jobs[key];
                    if (j && j.isCron && j.state === 'running') {
                        jQuery.getJSON('api/cron.php', { action: 'run_result', request_id: rid })
                            .done(function (apiRes) {
                                var r = (apiRes && apiRes.ok && apiRes.result) ? apiRes.result : null;
                                var assist = '';
                                var cronPrompt = '';
                                if (r) {
                                    cronPrompt = (r.cronPrompt && String(r.cronPrompt).trim() !== '')
                                        ? String(r.cronPrompt)
                                        : (j.promptText || '');
                                    assist = String(r.assistantContent || r.summary || '').trim();
                                    if (!assist && r.error && String(r.error).trim() !== '') {
                                        assist = '## Cron run failed\n\n' + String(r.error);
                                    }
                                }
                                if (!assist) {
                                    assist = '_No assistant text was saved (tools-only run or empty reply). Check **Research** and **Memory** in the app._';
                                }
                                responseCache[key] = assist;
                                if (jobs[key]) {
                                    jobs[key].fullResponse = assist;
                                    jobs[key].cronViewPrompt = cronPrompt || j.promptText || '';
                                }
                                try {
                                    var ac = assist.replace(/^#+\s*/gm, '').replace(/\*|_/g, '');
                                    var preview = ac.replace(/\s+/g, ' ').trim().slice(0, 280);
                                    window.alert('Cron job finished: ' + (j.cronJobName || j.name || rid) +
                                        (preview ? '\n\n' + preview + (ac.length > 280 ? '…' : '') : '\n\nOpen Running Jobs → View response for the formatted report.'));
                                } catch (e2) {}
                                finishJob(key, 'completed', 'Scheduled run completed.');
                            })
                            .fail(function () {
                                var fallback = '_Could not load saved cron output (missing or expired). Check chat history or research files._';
                                responseCache[key] = fallback;
                                if (jobs[key]) {
                                    jobs[key].fullResponse = fallback;
                                    jobs[key].cronViewPrompt = j.promptText || '';
                                }
                                try {
                                    window.alert('Cron job finished: ' + (j.cronJobName || j.name || rid) + '\n\nSaved output was not found on the server.');
                                } catch (e3) {}
                                finishJob(key, 'completed', 'Scheduled run completed.');
                            });
                    }
                });
                prevCronActiveRequestIds = nowMap;
            })
            .fail(function () {});
    }

    setInterval(syncCronActiveRuns, 450);
    setTimeout(syncCronActiveRuns, 300);

    renderJobs();
})();
