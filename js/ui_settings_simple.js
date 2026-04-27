/**
 * Settings FAB, settings drawer, 3D vs simple-chat layout, sidebar resource browser, activity pulses + log.
 */
(function () {
    var LS_KEY = 'memoryGraphInterfaceMode';
    var $fab;
    var $backdrop;
    var $panel;
    var $simpleRoot;
    var $switch;
    var $listCol;
    var $detailCol;
    var $log;
    var $logMobile;
    var $pulses;
    var $sectionTitle;
    var $simpleMain;
    var currentSection = 'chat';
    var listCache = {};
    var pendingSubAgentSelect = null;
    var logLines = [];
    var MAX_LOG = 450;
    var lastStatusSig = '';
    var lastActivityLogLen = 0;
    var lastAppendedActivityKey = '';

    function activityEntryKey(entry) {
        if (!entry || typeof entry !== 'object') {
            return '';
        }
        var t = entry.t != null ? String(entry.t) : '';
        var typ = entry.type != null ? String(entry.type) : '';
        var msg = entry.message != null ? String(entry.message) : '';
        return t + '\x1e' + typ + '\x1e' + msg.slice(0, 120);
    }

    function formatActivityLogLine(entry) {
        var typ = (entry && entry.type) ? String(entry.type) : 'event';
        var msg = (entry && entry.message) ? String(entry.message) : '';
        var lines = [typ.toUpperCase() + ': ' + msg];
        if (entry && entry.detail != null && entry.detail !== '') {
            var d = entry.detail;
            if (typeof d === 'object') {
                try {
                    d = JSON.stringify(d);
                } catch (e) {
                    d = String(d);
                }
            } else {
                d = String(d);
            }
            if (d.length > 14000) {
                d = d.slice(0, 13997) + '...';
            }
            lines.push('  ' + d.split('\n').join('\n  '));
        }
        return lines.join('\n');
    }

    var PULSE_SECTIONS = [
        { id: 'agent', label: 'Agent', color: '#d9e4ff' },
        { id: 'tools', label: 'Tools', color: '#ffc857' },
        { id: 'memory', label: 'Memory', color: '#47d7c9' },
        { id: 'instructions', label: 'Instr.', color: '#7cb8ff' },
        { id: 'research', label: 'Research', color: '#b8a9e8' },
        { id: 'rules', label: 'Rules', color: '#e8a9b8' },
        { id: 'mcps', label: 'MCPs', color: '#6be38e' },
        { id: 'jobs', label: 'Jobs', color: '#ff8f70' }
    ];

    var SECTION_DEFS = {
        memory: { label: 'Memory', listUrl: 'api_memory.php?action=list', listKey: 'memories', getUrl: function (name) { return 'api_memory.php?action=get&name=' + encodeURIComponent(name); } },
        tools: { label: 'Tools', listUrl: 'api_tools.php?action=list', listKey: 'tools', getUrl: null },
        instructions: { label: 'Instructions', listUrl: 'api_instructions.php?action=list', listKey: 'instructions', getUrl: function (name) { return 'api_instructions.php?action=get&name=' + encodeURIComponent(name); } },
        research: { label: 'Research', listUrl: 'api_research.php?action=list', listKey: 'research', getUrl: function (name) { return 'api_research.php?action=get&name=' + encodeURIComponent(name); } },
        rules: { label: 'Rules', listUrl: 'api_rules.php?action=list', listKey: 'rules', getUrl: function (name) { return 'api_rules.php?action=get&name=' + encodeURIComponent(name); } },
        mcps: { label: 'MCP servers', listUrl: 'api_mcps.php?action=list', listKey: 'servers', getUrl: function (name) { return 'api_mcps.php?action=get&name=' + encodeURIComponent(name); } },
        jobs: { label: 'Jobs', listUrl: 'api_jobs.php?action=list', listKey: 'jobs', getUrl: function (name) { return 'api_jobs.php?action=get&name=' + encodeURIComponent(name); } },
        sub_agents: {
            label: 'Sub-agents',
            listUrl: 'api_sub_agents.php?action=list',
            listKey: 'subAgents',
            getUrl: function (name) {
                return 'api_sub_agents.php?action=read&name=' + encodeURIComponent(name);
            }
        },
        apps: { label: 'Web apps', listUrl: 'api/web_apps.php?action=list', listKey: 'apps', getUrl: function (name) { return 'api/web_apps.php?action=get&name=' + encodeURIComponent(name); } },
        scheduled: { label: 'Scheduled', listUrl: 'api/cron.php?action=list', listKey: 'jobs', getUrl: null }
    };

    function postWebApp(action, body) {
        return fetch('api/web_apps.php?action=' + encodeURIComponent(action), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
            credentials: 'same-origin'
        }).then(function (r) {
            return r.text().then(function (t) {
                var res = null;
                try {
                    res = t ? JSON.parse(t) : null;
                } catch (e) {}
                if (!r.ok) {
                    throw new Error((res && res.error) ? res.error : (t || ('HTTP ' + r.status)));
                }
                return res || {};
            });
        });
    }

    function getMode() {
        try {
            return localStorage.getItem(LS_KEY) === 'simple' ? 'simple' : 'graph';
        } catch (e) {
            return 'graph';
        }
    }

    function setMode(mode) {
        var m = mode === 'simple' ? 'simple' : 'graph';
        try {
            localStorage.setItem(LS_KEY, m);
        } catch (e) {}
        applyMode(m);
    }

    function applyMode(mode) {
        var simple = mode === 'simple';
        document.documentElement.classList.toggle('mg-simple-ui', simple);
        document.body.classList.toggle('mg-simple-ui', simple);
        if ($switch && $switch.length) {
            $switch.prop('checked', simple);
        }
        if (typeof window.MemoryGraphSetRenderEnabled === 'function') {
            window.MemoryGraphSetRenderEnabled(!simple);
        }
        if (!simple) {
            try {
                window.dispatchEvent(new Event('resize'));
            } catch (e) {}
        }
        if (simple) {
            showSection(currentSection);
            if (typeof window.MemoryGraphFocusChatInput === 'function') {
                window.MemoryGraphFocusChatInput();
            }
        }
    }

    function showSection(section) {
        currentSection = section || 'chat';
        $('.simple-nav-btn').removeClass('is-active');
        $('.simple-nav-btn[data-section="' + currentSection + '"]').addClass('is-active');
        if (!$simpleMain || !$simpleMain.length) {
            return;
        }
        if (currentSection === 'chat') {
            $simpleMain.removeClass('simple-main-view-library').addClass('simple-main-view-chat');
            if ($sectionTitle && $sectionTitle.length) {
                $sectionTitle.text('Chat');
            }
            if (typeof window.MemoryGraphRefreshSimpleChatThread === 'function') {
                window.MemoryGraphRefreshSimpleChatThread();
            }
            return;
        }
        $simpleMain.removeClass('simple-main-view-chat').addClass('simple-main-view-library');
        loadSectionList(currentSection);
    }

    function sourceBadgeClass(src) {
        if (src === 'override') {
            return 'is-override';
        }
        if (src === 'env') {
            return 'is-env';
        }
        return 'is-none';
    }

    function badgeTextForSource(src) {
        if (src === 'override') {
            return 'Saved override';
        }
        if (src === 'env') {
            return '.env';
        }
        return 'Not set';
    }

    function setClearButtonState($btn, enabled) {
        $btn.prop('disabled', !enabled);
        $btn.css('opacity', enabled ? '' : '0.45');
    }

    function renderSettingsProviderApiKeys($mount, data) {
        $mount.empty();
        if (!data || !data.providers) {
            $mount.append($('<p class="settings-api-key-msg is-error font-serif">').text('Invalid response.'));
            return;
        }
        var status = data.providerApiKeyStatus && typeof data.providerApiKeyStatus === 'object'
            ? data.providerApiKeyStatus
            : {};
        var keys = Object.keys(data.providers).sort();
        keys.forEach(function (key) {
            var def = data.providers[key] || {};
            var displayName = def.name || key;
            var st = status[key] || { configured: false, source: 'none' };
            var row = $('<div class="settings-api-key-row">');
            var hdr = $('<div class="settings-api-key-row-header">');
            hdr.append($('<span class="settings-api-key-label font-display">').text(displayName));
            var badge = $('<span class="settings-api-key-badge font-display">')
                .text(badgeTextForSource(st.source))
                .addClass(sourceBadgeClass(st.source));
            hdr.append(badge);
            row.append(hdr);
            var act = $('<div class="settings-api-key-actions">');
            var inp = $('<input type="password" class="settings-api-key-input">')
                .attr('autocomplete', 'new-password')
                .attr('spellcheck', false)
                .attr('placeholder', st.source === 'override' ? 'Enter new key to replace…' : 'Paste API key')
                .attr('aria-label', 'API key for ' + displayName);
            var btnSave = $('<button type="button" class="settings-api-key-btn font-display">').text('Save');
            var btnClear = $('<button type="button" class="settings-api-key-btn font-display">').text('Clear override');
            var msg = $('<p class="settings-api-key-msg">').hide().text('');
            act.append(inp, btnSave, btnClear);
            row.append(act, msg);
            setClearButtonState(btnClear, st.source === 'override');
            function setBadge(st2) {
                var src = (st2 && st2.source) ? st2.source : 'none';
                badge.removeClass('is-override is-env is-none').addClass(sourceBadgeClass(src));
                badge.text(badgeTextForSource(src));
                setClearButtonState(btnClear, src === 'override');
            }
            function postKey(val) {
                msg.removeClass('is-error').text('Saving…').show();
                fetch('api/agent_config.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify({ action: 'set_provider_api_key', provider: key, apiKey: val })
                })
                    .then(function (r) {
                        return r.json().then(function (j) {
                            return { ok: r.ok, j: j };
                        });
                    })
                    .then(function (x) {
                        if (!x.j || x.j.error) {
                            msg.addClass('is-error').text((x.j && x.j.error) ? x.j.error : 'Save failed');
                            return;
                        }
                        inp.val('');
                        if (x.j.apiKeyStatus) {
                            setBadge(x.j.apiKeyStatus);
                        }
                        msg.removeClass('is-error').text('Saved.');
                        setTimeout(function () {
                            msg.fadeOut(200);
                        }, 1200);
                    })
                    .catch(function () {
                        msg.addClass('is-error').text('Network error');
                    });
            }
            btnSave.on('click', function () {
                var v = (inp.val() || '').trim();
                if (!v) {
                    msg.removeClass('is-error').show().text('Enter a key to save, or use Clear override.');
                    return;
                }
                postKey(v);
            });
            btnClear.on('click', function () {
                inp.val('');
                postKey('');
            });
            $mount.append(row);
        });
    }

    function refreshSettingsProviderApiKeys() {
        var $mount = $('#settings-provider-api-keys-mount');
        if (!$mount.length) {
            return;
        }
        $mount.html('<p class="settings-api-key-msg font-serif">Loading…</p>');
        fetch('api/agent_config.php', { credentials: 'same-origin' })
            .then(function (r) {
                return r.ok ? r.json() : Promise.reject(new Error('HTTP ' + r.status));
            })
            .then(function (data) {
                renderSettingsProviderApiKeys($mount, data);
            })
            .catch(function () {
                $mount.html('<p class="settings-api-key-msg is-error font-serif">Could not load provider list.</p>');
            });
    }

    function openSettings() {
        if (!$panel.length) return;
        $backdrop.removeAttr('hidden').addClass('is-open');
        $panel.addClass('is-open').attr('aria-hidden', 'false');
        refreshSettingsProviderApiKeys();
    }

    function closeSettings() {
        $backdrop.removeClass('is-open').attr('hidden', 'hidden');
        $panel.removeClass('is-open').attr('aria-hidden', 'true');
    }

    function appendLog(text) {
        if (!$log || !$log.length) return;
        var t = new Date();
        var ts = ('0' + t.getHours()).slice(-2) + ':' + ('0' + t.getMinutes()).slice(-2) + ':' + ('0' + t.getSeconds()).slice(-2);
        var line = '[' + ts + '] ' + text;
        logLines.push(line);
        if (logLines.length > MAX_LOG) {
            logLines.shift();
        }
        var joined = logLines.join('\n');
        $log.text(joined);
        if ($log[0]) {
            $log.scrollTop($log[0].scrollHeight);
        }
        if ($logMobile && $logMobile.length) {
            $logMobile.text(joined);
            $logMobile.scrollTop($logMobile[0].scrollHeight);
        }
    }

    function inferActivityFromStatus(status) {
        var ex = status.executionDetailsByNode && typeof status.executionDetailsByNode === 'object' ? status.executionDetailsByNode : {};
        var toolIds = Array.isArray(status.activeToolIds) ? status.activeToolIds.slice() : [];
        var memIds = Array.isArray(status.activeMemoryIds) ? status.activeMemoryIds.slice() : [];
        var insIds = Array.isArray(status.activeInstructionIds) ? status.activeInstructionIds.slice() : [];
        var resIds = Array.isArray(status.activeResearchIds) ? status.activeResearchIds.slice() : [];
        var rulIds = Array.isArray(status.activeRulesIds) ? status.activeRulesIds.slice() : [];
        var mcpIds = Array.isArray(status.activeMcpIds) ? status.activeMcpIds.slice() : [];
        var jobIds = Array.isArray(status.activeJobIds) ? status.activeJobIds.slice() : [];

        Object.keys(ex).forEach(function (key) {
            if (key.indexOf('tool_') === 0 && toolIds.indexOf(key) === -1) toolIds.push(key);
            if (key.indexOf('memory_file_') === 0 && memIds.indexOf(key) === -1) memIds.push(key);
            if (key.indexOf('instruction_file_') === 0 && insIds.indexOf(key) === -1) insIds.push(key);
            if (key.indexOf('research_file_') === 0 && resIds.indexOf(key) === -1) resIds.push(key);
            if (key.indexOf('rules_file_') === 0 && rulIds.indexOf(key) === -1) rulIds.push(key);
            if (key.indexOf('mcp_server_') === 0 && mcpIds.indexOf(key) === -1) mcpIds.push(key);
            if (key.indexOf('job_file_') === 0 && jobIds.indexOf(key) === -1) jobIds.push(key);
            if (key.indexOf('job_cron_') === 0 && jobIds.indexOf(key) === -1) jobIds.push(key);
        });

        var active = {
            agent: !!status.thinking,
            tools: !!(status.gettingAvailTools || toolIds.length || ex.tools),
            memory: !!(status.checkingMemory || status.memoryToolExecuting || memIds.length || ex.memory),
            instructions: !!(status.checkingInstructions || insIds.length || ex.instructions),
            research: !!(status.checkingResearch || resIds.length || ex.research),
            rules: !!(status.checkingRules || rulIds.length || ex.rules),
            mcps: !!(status.checkingMcps || mcpIds.length || ex.mcps),
            jobs: !!(status.checkingJobs || jobIds.length || ex.jobs)
        };
        return { active: active, toolIds: toolIds, memIds: memIds, mcpIds: mcpIds };
    }

    window.MemoryGraphResetSimpleActivityLog = function (opts) {
        lastActivityLogLen = 0;
        lastStatusSig = '';
        lastAppendedActivityKey = '';
        if (opts && opts.clear) {
            logLines = [];
            if ($log && $log.length) {
                $log.text('');
            }
            if ($logMobile && $logMobile.length) {
                $logMobile.text('');
            }
        }
    };

    window.SimpleUiLogFromStatus = function (status) {
        if (!document.documentElement.classList.contains('mg-simple-ui')) return;
        var inf = inferActivityFromStatus(status);
        var active = inf.active;

        PULSE_SECTIONS.forEach(function (s) {
            var el = document.querySelector('.simple-pulse-dot[data-pulse="' + s.id + '"]');
            if (!el) return;
            el.classList.toggle('is-live', !!active[s.id]);
        });

        var al = Array.isArray(status.activityLog) ? status.activityLog : [];
        if (al.length < lastActivityLogLen) {
            if (lastAppendedActivityKey) {
                var syncFrom = 0;
                for (var s = al.length - 1; s >= 0; s--) {
                    if (activityEntryKey(al[s]) === lastAppendedActivityKey) {
                        syncFrom = s + 1;
                        break;
                    }
                }
                lastActivityLogLen = syncFrom;
            } else {
                lastActivityLogLen = 0;
            }
        }
        var i;
        for (i = lastActivityLogLen; i < al.length; i++) {
            appendLog(formatActivityLogLine(al[i]));
            lastAppendedActivityKey = activityEntryKey(al[i]);
        }
        lastActivityLogLen = al.length;

        var sig = JSON.stringify({
            t: !!status.thinking,
            g: !!status.gettingAvailTools,
            cm: !!status.checkingMemory,
            ci: !!status.checkingInstructions,
            cr: !!status.checkingResearch,
            crl: !!status.checkingRules,
            cmc: !!status.checkingMcps,
            cj: !!status.checkingJobs,
            ti: inf.toolIds.length,
            mi: inf.memIds.length,
            mcpi: inf.mcpIds.length
        });
        if (sig !== lastStatusSig) {
            lastStatusSig = sig;
            var parts = [];
            if (status.thinking) parts.push('thinking');
            if (status.gettingAvailTools) parts.push('listing tools');
            if (status.checkingMemory) parts.push('memory');
            if (status.checkingInstructions) parts.push('instructions');
            if (status.checkingResearch) parts.push('research');
            if (status.checkingRules) parts.push('rules');
            if (status.checkingMcps) parts.push('MCP');
            if (status.checkingJobs) parts.push('jobs');
            if (inf.toolIds.length) parts.push('tools: ' + inf.toolIds.slice(0, 3).join(', ') + (inf.toolIds.length > 3 ? '…' : ''));
            if (inf.memIds.length) parts.push('memory nodes: ' + inf.memIds.length);
            if (inf.mcpIds.length) parts.push('MCP: ' + inf.mcpIds.slice(0, 2).join(', '));
            if (parts.length) {
                appendLog(parts.join(' · '));
            }
        }

        if (typeof window.MemoryGraphSignalActivity === 'function') {
            var nodeIds = inf.toolIds.concat(inf.memIds, inf.mcpIds);
            var sections = [];
            if (active.tools) sections.push('tools');
            if (active.memory) sections.push('memory');
            if (active.instructions) sections.push('instructions');
            if (active.research) sections.push('research');
            if (active.rules) sections.push('rules');
            if (active.mcps) sections.push('mcps');
            if (active.jobs) sections.push('jobs');
            window.MemoryGraphSignalActivity({
                sections: sections,
                nodeIds: nodeIds,
                durationMs: status.thinking ? 2600 : 2200
            });
        }
    };

    function appendSubAgentsNewButton() {
        var $tb = $('<div class="panel-action-btn-row" style="margin-bottom:10px;">');
        $tb.append($('<button type="button" class="panel-action-btn">').text('New sub-agent').on('click', function () {
            var raw = window.prompt('Sub-agent file name (e.g. my_helper or my_helper.md, saved under sub-agents/):', '');
            if (raw == null) return;
            raw = String(raw).trim();
            if (raw === '') return;
            var base = raw.replace(/^[\\/]+/, '').split(/[\\/]/).pop() || raw;
            if (base.toLowerCase().indexOf('.md') === -1) {
                base += '.md';
            }
            $listCol.find('.simple-item-btn').removeClass('is-selected');
            showDetail('sub_agents', { name: base, title: base.replace(/\.md$/i, ''), _isNew: true });
        }));
        $listCol.append($tb);
    }

    function renderList(items, section) {
        if (!$listCol || !$listCol.length) return;
        $listCol.empty();
        if (section === 'sub_agents') {
            appendSubAgentsNewButton();
        }
        if (!items || !items.length) {
            $listCol.append($('<p class="simple-empty font-serif">').text(section === 'sub_agents' ? 'No sub-agent configs yet. Create one or add .md files under sub-agents/.' : 'Nothing here yet.'));
            return;
        }
        var ul = $('<ul class="simple-item-list font-serif">');
        items.forEach(function (item) {
            var name = item.title || item.name || '(unnamed)';
            var active = item.active !== false;
            var li = $('<li class="simple-item-row">');
            var btn = $('<button type="button" class="simple-item-btn">').text(name);
            if (item.name) {
                btn.attr('data-item-name', item.name);
            }
            if (!active) {
                btn.append($('<span class="simple-item-off">').text(' off'));
            }
            btn.on('click', function () {
                $listCol.find('.simple-item-btn').removeClass('is-selected');
                btn.addClass('is-selected');
                showDetail(section, item);
            });
            li.append(btn);
            ul.append(li);
        });
        $listCol.append(ul);
    }

    function showDetail(section, item) {
        if (!$detailCol || !$detailCol.length) return;
        $detailCol.empty();
        var def = SECTION_DEFS[section];
        if (!def) return;

        var title = $('<h3 class="simple-detail-title font-display">').text(item.name || item.title || 'Detail');
        $detailCol.append(title);

        var meta = $('<div class="simple-detail-meta font-serif">');
        if (item.description) {
            meta.append($('<p>').text(item.description));
        }
        if (item.active === false) {
            meta.append($('<p class="simple-warn">').text('Disabled'));
        }
        $detailCol.append(meta);

        var openPanel = null;
        if (typeof window.MemoryGraphShowNodePanel === 'function') {
            openPanel = function (label, id) {
                closeSettings();
                window.MemoryGraphShowNodePanel(label, id);
            };
        }

        if (section === 'tools') {
            var pre = $('<pre class="simple-detail-pre">').text(item.code || item.description || JSON.stringify(item, null, 2));
            $detailCol.append(pre);
            if (openPanel && item.name) {
                $('<button type="button" class="simple-open-panel-btn">').text('Open in graph panel').on('click', function () {
                    openPanel(item.name, 'tool_' + item.name);
                }).appendTo($detailCol);
            }
            return;
        }

        if (section === 'sub_agents') {
            var fileName = item.name || '';
            if (!fileName && item.title) {
                fileName = String(item.title).replace(/\s+/g, '_') + '.md';
            }
            var isNew = !!item._isNew;
            if (!isNew && (item.provider || item.model)) {
                meta.append($('<p>').text([item.provider, item.model].filter(Boolean).join(' · ')));
            }
            var newTemplate = '---\n' +
                'provider: \n' +
                'model: \n' +
                'api_key: \n' +
                'endpoint: \n' +
                'chat_type: openai\n' +
                'temperature: 0.7\n' +
                'dashboard_url: \n' +
                'system_prompt: \n' +
                '---\n\n' +
                'You are a helpful sub-agent. Stay concise.\n';
            var $st = $('<p class="simple-app-save-status font-serif" role="status" style="margin-top:4px;">').hide();

            var $ta = $('<textarea class="simple-web-app-editor font-serif" spellcheck="false" wrap="off" style="min-height: 260px;" aria-label="Sub-agent markdown">');
            $detailCol.append(
                $('<label class="simple-app-form-label font-display">').text('Config (.md)'),
                $ta,
                $st
            );
            var row = $('<div class="panel-action-btn-row" style="margin-top:10px;flex-wrap:wrap;gap:8px;">');
            var $save = $('<button type="button" class="panel-action-btn">').text(isNew ? 'Create' : 'Save');
            var $del = $('<button type="button" class="panel-action-btn btn-stop">').text('Delete');
            if (isNew) {
                $del.hide();
            }
            row.append($save, $del);
            if (openPanel) {
                row.append($('<button type="button" class="simple-open-panel-btn">').text('Open in graph panel').prop('disabled', isNew).on('click', function () {
                    if (item.nodeId) {
                        openPanel(item.title || fileName, item.nodeId);
                    }
                }));
            }
            $detailCol.append(row);

            function setStatus(msg, isErr) {
                if (!msg) {
                    $st.hide().text('');
                    return;
                }
                $st.text(msg).show().css('color', isErr ? '#f87171' : 'var(--gold-dim)');
            }

            if (isNew) {
                $ta.val(newTemplate);
                setStatus('New file — edit front-matter and system prompt, then Create.', false);
            } else {
                $ta.prop('disabled', true).val('Loading…');
                $.getJSON('api_sub_agents.php?action=read&name=' + encodeURIComponent(fileName))
                    .done(function (data) {
                        if (data && data.error) {
                            setStatus(data.error, true);
                            $ta.val('').attr('placeholder', '');
                            return;
                        }
                        var c = data && data.content;
                        $ta.val(typeof c === 'string' ? c : '').prop('disabled', false);
                        if (data && data.nodeId) {
                            item.nodeId = data.nodeId;
                        }
                    })
                    .fail(function () {
                        setStatus('Could not load file.', true);
                        $ta.val('').prop('disabled', false);
                    });
            }

            $save.on('click', function () {
                var text = $ta.val();
                if (text == null || String(text).trim() === '') {
                    setStatus('Content cannot be empty.', true);
                    return;
                }
                $save.prop('disabled', true);
                setStatus(isNew ? 'Creating…' : 'Saving…', false);
                var payload = { action: isNew ? 'create' : 'update', name: fileName, content: String(text) };
                $.ajax({
                    url: 'api_sub_agents.php',
                    method: 'POST',
                    contentType: 'application/json',
                    data: JSON.stringify(payload),
                    credentials: 'same-origin'
                })
                    .done(function (res) {
                        if (res && res.error) {
                            setStatus(res.error, true);
                            return;
                        }
                        setStatus(isNew ? 'Created.' : 'Saved.', false);
                        delete listCache.sub_agents;
                        var savedName = (res && res.name) ? res.name : fileName;
                        pendingSubAgentSelect = savedName;
                        loadSectionList('sub_agents');
                        if (typeof window.MemoryGraphRefresh === 'function') {
                            window.MemoryGraphRefresh();
                        }
                    })
                    .fail(function (xhr) {
                        pendingSubAgentSelect = null;
                        var msg = (xhr.responseJSON && xhr.responseJSON.error) || xhr.statusText || 'Request failed';
                        setStatus(String(msg), true);
                    })
                    .always(function () {
                        $save.prop('disabled', false);
                    });
            });

            $del.on('click', function () {
                if (isNew || !window.confirm('Delete sub-agent "' + fileName + '"? This cannot be undone.')) {
                    return;
                }
                $del.prop('disabled', true);
                $save.prop('disabled', true);
                $.ajax({
                    url: 'api_sub_agents.php',
                    method: 'POST',
                    contentType: 'application/json',
                    data: JSON.stringify({ action: 'delete', name: fileName }),
                    credentials: 'same-origin'
                })
                    .done(function (res) {
                        if (res && res.error) {
                            setStatus(res.error, true);
                            return;
                        }
                        $detailCol.empty();
                        delete listCache.sub_agents;
                        loadSectionList('sub_agents');
                        if (typeof window.MemoryGraphRefresh === 'function') {
                            window.MemoryGraphRefresh();
                        }
                    })
                    .fail(function (xhr) {
                        setStatus((xhr.responseJSON && xhr.responseJSON.error) || xhr.statusText || 'Delete failed', true);
                    })
                    .always(function () {
                        $del.prop('disabled', false);
                        $save.prop('disabled', false);
                    });
            });
            return;
        }

        if (section === 'scheduled') {
            $detailCol.append($('<pre class="simple-detail-pre">').text(JSON.stringify(item, null, 2)));
            if (item.messagePreview) {
                $detailCol.append($('<p class="simple-detail-meta font-serif">').text('Prompt preview: ' + item.messagePreview));
            }
            var row = $('<div class="panel-action-btn-row" style="margin-top:10px;flex-wrap:wrap;gap:8px;">');
            if (openPanel && item.nodeId) {
                row.append($('<button type="button" class="simple-open-panel-btn">').text('Open in graph panel').on('click', function () {
                    openPanel(item.name || item.title || 'Scheduled', item.nodeId);
                }));
            }
            row.append($('<button type="button" class="panel-action-btn">').text('Run now').on('click', function () {
                if (!item.id) {
                    alert('No job id — refresh the list.');
                    return;
                }
                var btn = $(this);
                btn.prop('disabled', true);
                fetch('api/cron.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'run', job_id: item.id }),
                    credentials: 'same-origin'
                }).then(function (r) {
                    return r.text().then(function (t) {
                        var res = null;
                        try {
                            res = t ? JSON.parse(t) : null;
                        } catch (e) {}
                        if (!r.ok) {
                            throw new Error((res && res.error) ? res.error : (t || ('HTTP ' + r.status)));
                        }
                        return res || {};
                    });
                }).then(function (res) {
                    var ok = res && res.ok;
                    var ran = res && res.ran;
                    var sum = ran && (ran.summary || ran.error);
                    alert(ok ? ('Run finished.' + (sum ? '\n\n' + String(sum).slice(0, 800) : '')) : (res && res.error ? res.error : 'Run failed'));
                    delete listCache.scheduled;
                    loadSectionList('scheduled');
                }).catch(function (err) {
                    alert(err && err.message ? err.message : 'Run request failed');
                }).finally(function () { btn.prop('disabled', false); });
            }));
            var cronIsOn = item.enabled !== false && item.active !== false;
            row.append($('<button type="button" class="panel-action-btn">').text(cronIsOn ? 'Disable' : 'Enable').on('click', function () {
                if (!item.id) {
                    alert('No job id — refresh the list.');
                    return;
                }
                var btn = $(this);
                btn.prop('disabled', true);
                fetch('api/cron.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'set_enabled', job_id: item.id, enabled: !cronIsOn }),
                    credentials: 'same-origin'
                }).then(function (r) {
                    return r.text().then(function (t) {
                        var res = null;
                        try {
                            res = t ? JSON.parse(t) : null;
                        } catch (e) {}
                        if (!r.ok) {
                            throw new Error((res && res.error) ? res.error : (t || ('HTTP ' + r.status)));
                        }
                        return res || {};
                    });
                }).then(function (res) {
                    if (res && res.ok) {
                        delete listCache.scheduled;
                        loadSectionList('scheduled');
                    } else {
                        alert(res && res.error ? res.error : 'Update failed');
                    }
                }).catch(function (err) {
                    alert(err && err.message ? err.message : 'Request failed');
                }).finally(function () { btn.prop('disabled', false); });
            }));
            row.append($('<button type="button" class="panel-action-btn btn-stop">').text('Remove').on('click', function () {
                if (!item.id || !confirm('Remove this scheduled job?')) return;
                var btn = $(this);
                btn.prop('disabled', true);
                fetch('api/cron.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'remove_job', job_id: item.id }),
                    credentials: 'same-origin'
                }).then(function (r) {
                    return r.text().then(function (t) {
                        var res = null;
                        try {
                            res = t ? JSON.parse(t) : null;
                        } catch (e) {}
                        if (!r.ok) {
                            throw new Error((res && res.error) ? res.error : (t || ('HTTP ' + r.status)));
                        }
                        return res || {};
                    });
                }).then(function (res) {
                    if (res && res.ok) {
                        delete listCache.scheduled;
                        loadSectionList('scheduled');
                        if (typeof window.MemoryGraphRefresh === 'function') window.MemoryGraphRefresh();
                    } else {
                        alert(res && res.error ? res.error : 'Remove failed');
                    }
                }).catch(function (err) {
                    alert(err && err.message ? err.message : 'Request failed');
                }).finally(function () { btn.prop('disabled', false); });
            }));
            $detailCol.append(row);
            return;
        }

        if (section === 'apps') {
            var slug = item.name;
            var appTitle = item.title || slug || 'App';
            title.text(appTitle);
            var parts = [];
            if (slug) parts.push('Slug: ' + slug);
            if (item.size != null && item.size >= 0) {
                parts.push(Math.round(item.size / 1024 * 10) / 10 + ' KB');
            }
            if (item.updated) {
                try {
                    parts.push('Updated ' + new Date(item.updated * 1000).toLocaleString());
                } catch (e) {}
            }
            if (parts.length) {
                meta.append($('<p>').text(parts.join(' · ')));
            }

            var $titleLabel = $('<label class="simple-app-form-label font-display">').attr('for', 'simple-app-title-' + slug).text('Display title');
            var $titleInput = $('<input type="text" id="simple-app-title-' + slug + '" class="simple-app-title-input font-serif">').attr({ spellcheck: false, autocomplete: 'off' }).val(appTitle);
            var $htmlLabel = $('<label class="simple-app-form-label font-display">').attr('for', 'simple-app-html-' + slug).text('index.html (edit and save)');
            var $ta = $('<textarea id="simple-app-html-' + slug + '" class="simple-web-app-editor font-serif" spellcheck="false" wrap="off">');
            $ta.attr('placeholder', 'Loading…');

            var openUrl = 'api/serve_app.php?app=' + encodeURIComponent(slug || '');
            var rowOpen = $('<div class="panel-action-btn-row" style="margin-top:12px;flex-wrap:wrap;gap:8px;">');
            rowOpen.append($('<button type="button" class="panel-action-btn">').text('Open fullscreen').on('click', function () {
                var t = ($titleInput.val() || '').trim() || appTitle;
                if (typeof window.MemoryGraphOpenWebApp === 'function') {
                    window.MemoryGraphOpenWebApp({ name: slug, title: t, url: openUrl });
                } else {
                    window.open(openUrl, '_blank', 'noopener,noreferrer');
                }
            }));
            $detailCol.append(rowOpen);

            var $status = $('<p class="simple-app-save-status font-serif" role="status">').hide();
            $detailCol.append($status);

            function setAppStatus(msg, isErr) {
                if (!msg) {
                    $status.hide().text('');
                    return;
                }
                $status.text(msg).show().css('color', isErr ? '#f87171' : 'var(--gold-dim)');
            }

            $detailCol.append($titleLabel, $titleInput, $htmlLabel, $ta);

            var rowEdit = $('<div class="panel-action-btn-row" style="margin-top:12px;flex-wrap:wrap;gap:8px;">');
            var $btnSave = $('<button type="button" class="panel-action-btn">').text('Save changes');
            var $btnDel = $('<button type="button" class="panel-action-btn btn-stop">').text('Delete app');
            rowEdit.append($btnSave, $btnDel);
            $detailCol.append(rowEdit);

            if (!slug) {
                setAppStatus('Invalid app slug.', true);
                $ta.prop('disabled', true);
                $btnSave.prop('disabled', true);
                $btnDel.prop('disabled', true);
                return;
            }

            $btnSave.on('click', function () {
                var html = $ta.val();
                if (!html || !String(html).trim()) {
                    setAppStatus('HTML cannot be empty.', true);
                    return;
                }
                var tit = ($titleInput.val() || '').trim();
                var payload = { name: slug, html: html };
                if (tit) {
                    payload.title = tit;
                }
                $btnSave.prop('disabled', true);
                setAppStatus('Saving…', false);
                postWebApp('update', payload)
                    .then(function (res) {
                        if (res && res.ok) {
                            setAppStatus('Saved.', false);
                            delete listCache.apps;
                            loadSectionList('apps');
                            if (typeof window.MemoryGraphReloadAppsList === 'function') {
                                window.MemoryGraphReloadAppsList();
                            }
                            if (typeof window.MemoryGraphRefresh === 'function') {
                                window.MemoryGraphRefresh();
                            }
                        } else {
                            setAppStatus((res && res.error) ? res.error : 'Save failed', true);
                        }
                    })
                    .catch(function (err) {
                        setAppStatus(err && err.message ? err.message : 'Save failed', true);
                    })
                    .finally(function () {
                        $btnSave.prop('disabled', false);
                    });
            });

            $btnDel.on('click', function () {
                if (!confirm('Delete app "' + slug + '"? This removes the folder under apps/ and cannot be undone.')) {
                    return;
                }
                $btnDel.prop('disabled', true);
                $btnSave.prop('disabled', true);
                postWebApp('delete', { name: slug })
                    .then(function (res) {
                        if (res && res.ok) {
                            delete listCache.apps;
                            $detailCol.empty();
                            loadSectionList('apps');
                            if (typeof window.MemoryGraphReloadAppsList === 'function') {
                                window.MemoryGraphReloadAppsList();
                            }
                            if (typeof window.MemoryGraphRefresh === 'function') {
                                window.MemoryGraphRefresh();
                            }
                        } else {
                            setAppStatus((res && res.error) ? res.error : 'Delete failed', true);
                        }
                    })
                    .catch(function (err) {
                        setAppStatus(err && err.message ? err.message : 'Delete failed', true);
                    })
                    .finally(function () {
                        $btnDel.prop('disabled', false);
                        $btnSave.prop('disabled', false);
                    });
            });

            $.getJSON('api/web_apps.php?action=get&name=' + encodeURIComponent(slug))
                .done(function (data) {
                    if (data && data.error) {
                        setAppStatus(data.error, true);
                        $ta.prop('disabled', true).attr('placeholder', '');
                        return;
                    }
                    var c = data && data.content;
                    $ta.val(typeof c === 'string' ? c : '').attr('placeholder', '');
                    if (data && data.title) {
                        $titleInput.val(data.title);
                    }
                })
                .fail(function () {
                    setAppStatus('Could not load source.', true);
                    $ta.prop('disabled', true).attr('placeholder', '');
                });
            return;
        }

        var name = item.name;
        if (!name || !def.getUrl) {
            $detailCol.append($('<pre class="simple-detail-pre">').text(JSON.stringify(item, null, 2)));
            return;
        }

        $.getJSON(def.getUrl(name))
            .done(function (data) {
                var content = data.content;
                if (typeof content === 'string' && content.length) {
                    $detailCol.append($('<pre class="simple-detail-pre">').text(content));
                } else {
                    $detailCol.append($('<pre class="simple-detail-pre">').text(JSON.stringify(data, null, 2)));
                }
                var nid = data.nodeId;
                if (openPanel && nid) {
                    $('<button type="button" class="simple-open-panel-btn">').text('Open in graph panel').on('click', function () {
                        openPanel(data.title || data.name || name, nid);
                    }).appendTo($detailCol);
                }
            })
            .fail(function () {
                $detailCol.append($('<p class="simple-warn">').text('Could not load item.'));
            });
    }

    function loadSectionList(section) {
        var def = SECTION_DEFS[section];
        if (!def || !$sectionTitle || !$sectionTitle.length) return;
        $sectionTitle.text(def.label);

        if (section !== 'scheduled' && listCache[section]) {
            renderList(listCache[section], section);
            if (section === 'sub_agents' && pendingSubAgentSelect) {
                var want = pendingSubAgentSelect;
                pendingSubAgentSelect = null;
                setTimeout(function () {
                    $listCol.find('.simple-item-btn').each(function () {
                        if ($(this).attr('data-item-name') === want) {
                            $(this).trigger('click');
                            return false;
                        }
                    });
                }, 0);
            }
            return;
        }

        $listCol.html('<p class="simple-loading font-serif">Loading…</p>');
        $.getJSON(def.listUrl)
            .done(function (data) {
                var arr = data[def.listKey];
                arr = Array.isArray(arr) ? arr : [];
                if (section !== 'scheduled') {
                    listCache[section] = arr;
                }
                renderList(arr, section);
                if (section === 'sub_agents' && pendingSubAgentSelect) {
                    var want2 = pendingSubAgentSelect;
                    pendingSubAgentSelect = null;
                    setTimeout(function () {
                        $listCol.find('.simple-item-btn').each(function () {
                            if ($(this).attr('data-item-name') === want2) {
                                $(this).trigger('click');
                                return false;
                            }
                        });
                    }, 0);
                }
            })
            .fail(function () {
                $listCol.html('<p class="simple-warn">Failed to load list.</p>');
            });
    }

    $(function () {
        $fab = $('#settings-fab');
        $backdrop = $('#settings-backdrop');
        $panel = $('#settings-panel');
        $simpleRoot = $('#simple-ui-root');
        $switch = $('#ui-mode-simple-switch');
        $listCol = $('#simple-list-col');
        $detailCol = $('#simple-detail-col');
        $log = $('#simple-activity-log');
        $pulses = $('#simple-activity-pulses');
        $sectionTitle = $('#simple-section-title');
        $simpleMain = $('#simple-main');
        var $toolbarPulses = $('#simple-toolbar-pulses');

        function buildPulseStrip($container, compact) {
            if (!$container || !$container.length) return;
            $container.empty();
            PULSE_SECTIONS.forEach(function (s) {
                var wrap = $('<div class="simple-pulse-item" title="' + s.label + '">');
                wrap.append($('<span class="simple-pulse-dot" data-pulse="' + s.id + '">').css('background', s.color));
                if (!compact) {
                    wrap.append($('<span class="simple-pulse-label font-serif">').text(s.label));
                }
                $container.append(wrap);
            });
        }
        buildPulseStrip($pulses, false);
        buildPulseStrip($toolbarPulses, true);

        function updateSimpleChatHistoryDeleteSelectedBtn() {
            var n = $('.simple-chat-history-cb:checked').length;
            $('#simple-chat-history-delete-selected').prop('disabled', n === 0);
        }

        function refreshSimpleChatHistoryList() {
            var $list = $('#simple-chat-history-list');
            if (!$list.length) {
                return;
            }
            if (!document.documentElement.classList.contains('mg-simple-ui')) {
                return;
            }
            $list.html('<p class="simple-loading font-serif">Loading…</p>');
            $.getJSON('api/chat_sessions.php', { action: 'list_sessions', limit: 80 })
                .done(function (data) {
                    if (!data || !data.ok) {
                        $list.html('<p class="simple-warn font-serif">Could not load sessions.</p>');
                        return;
                    }
                    var sessions = Array.isArray(data.sessions) ? data.sessions : [];
                    var current = (typeof window.MemoryGraphPeekChatSessionId === 'function' && window.MemoryGraphPeekChatSessionId()) || '';
                    $list.empty();
                    if (!sessions.length) {
                        $list.append($('<p class="simple-empty font-serif">').text('No saved chat sessions yet. Completed replies are stored here by browser session id.'));
                        updateSimpleChatHistoryDeleteSelectedBtn();
                        return;
                    }
                    sessions.forEach(function (s) {
                        var sid = s.sessionId != null ? String(s.sessionId) : '';
                        var count = parseInt(s.exchangeCount, 10) || 0;
                        var lastTs = parseInt(s.lastTs, 10) || 0;
                        var when = lastTs ? new Date(lastTs).toLocaleString() : '—';
                        var title = sid === '' ? 'Legacy (no session id)' : sid;
                        var preview = (s.lastUserPreview && String(s.lastUserPreview)) || '—';
                        var $row = $('<div class="simple-chat-history-row font-serif">').attr('data-session-id', sid);
                        if (sid !== '' && current && current === sid) {
                            $row.addClass('is-current');
                        }
                        var $cb = $('<input type="checkbox" class="simple-chat-history-cb" aria-label="Select session">');
                        var $main = $('<div class="simple-chat-history-row-main">');
                        $main.append($('<div>').text(title).css({ wordBreak: 'break-all', fontSize: '0.68rem', color: 'var(--gold)' }));
                        $main.append($('<div class="simple-chat-history-meta">').text(count + ' saved turn(s) · ' + when));
                        if (preview !== '—') {
                            $main.append($('<div class="simple-chat-history-meta">').text(preview));
                        }
                        var $act = $('<div class="simple-chat-history-actions">');
                        var $btnLoad = $('<button type="button">').text('Load');
                        if (sid === '') {
                            $btnLoad.prop('disabled', true).attr('title', 'Cannot load legacy rows into the thread');
                        } else {
                            $btnLoad.addClass('simple-chat-history-load');
                        }
                        var $btnDel = $('<button type="button" class="simple-chat-history-btn-danger simple-chat-history-delete-one">').text('Delete');
                        $act.append($btnLoad, $btnDel);
                        $row.append($cb, $main, $act);
                        $list.append($row);
                    });
                    updateSimpleChatHistoryDeleteSelectedBtn();
                })
                .fail(function () {
                    $list.html('<p class="simple-warn font-serif">Could not load sessions.</p>');
                });
        }
        window.MemoryGraphRefreshSimpleChatHistoryList = refreshSimpleChatHistoryList;

        $('.simple-activity-tab').on('click', function () {
            var tab = $(this).data('tab');
            if (!tab) return;
            $('.simple-activity-tab').removeClass('is-active').attr('aria-selected', 'false');
            $(this).addClass('is-active').attr('aria-selected', 'true');
            $('.simple-activity-panel').removeClass('is-active');
            if (tab === 'history') {
                $('#simple-activity-panel-history').addClass('is-active');
                $('#simple-activity-panel-log').removeClass('is-active');
                refreshSimpleChatHistoryList();
            } else {
                $('#simple-activity-panel-log').addClass('is-active');
                $('#simple-activity-panel-history').removeClass('is-active');
            }
        });

        $('#simple-chat-history-list').on('change', '.simple-chat-history-cb', updateSimpleChatHistoryDeleteSelectedBtn);

        $('#simple-chat-history-new').on('click', function () {
            if (typeof window.MemoryGraphStartNewChatSession !== 'function') return;
            window.MemoryGraphStartNewChatSession();
        });
        $('#simple-chat-history-refresh').on('click', function () {
            refreshSimpleChatHistoryList();
        });
        $('#simple-chat-history-delete-selected').on('click', function () {
            var ids = [];
            $('.simple-chat-history-cb:checked').each(function () {
                var $r = $(this).closest('.simple-chat-history-row');
                var sid = $r.attr('data-session-id');
                if (sid === undefined || sid === null) sid = '';
                ids.push(String(sid));
            });
            if (!ids.length) return;
            var uniq = [];
            var seenLegacy = false;
            ids.forEach(function (id) {
                if (id === '') {
                    seenLegacy = true;
                } else if (uniq.indexOf(id) === -1) {
                    uniq.push(id);
                }
            });
            var payloadIds = uniq.slice();
            if (seenLegacy) {
                payloadIds.push('');
            }
            if (!window.confirm('Delete ' + payloadIds.length + ' session(s) from saved server history? Your current thread is not removed unless it matches.')) {
                return;
            }
            $.ajax({
                url: 'api/chat_sessions.php',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({ action: 'delete_sessions', sessionIds: payloadIds }),
                credentials: 'same-origin'
            })
                .done(function (res) {
                    if (res && res.error) {
                        window.alert(res.error);
                        return;
                    }
                    refreshSimpleChatHistoryList();
                })
                .fail(function (xhr) {
                    var msg = (xhr.responseJSON && xhr.responseJSON.error) || xhr.statusText || 'Request failed';
                    window.alert(msg);
                });
        });
        $('#simple-chat-history-clear-legacy').on('click', function () {
            if (!window.confirm('Remove all saved chat turns that have no session id?')) return;
            $.ajax({
                url: 'api/chat_sessions.php',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({ action: 'delete_session', sessionId: '' }),
                credentials: 'same-origin'
            })
                .done(function () {
                    refreshSimpleChatHistoryList();
                })
                .fail(function (xhr) {
                    var msg = (xhr.responseJSON && xhr.responseJSON.error) || xhr.statusText || 'Request failed';
                    window.alert(msg);
                });
        });

        $('#simple-chat-history-list').on('click', '.simple-chat-history-load', function () {
            var $row = $(this).closest('.simple-chat-history-row');
            var sid = $row.attr('data-session-id');
            if (!sid) return;
            $.getJSON('api/chat_sessions.php', { action: 'session_turns', session_id: sid })
                .done(function (data) {
                    if (!data || !data.ok) {
                        window.alert((data && data.error) || 'Could not load session.');
                        return;
                    }
                    var turns = Array.isArray(data.turns) ? data.turns : [];
                    if (typeof window.MemoryGraphSetChatSessionId === 'function') {
                        window.MemoryGraphSetChatSessionId(sid);
                    }
                    if (typeof window.MemoryGraphReplaceSimpleChatTurns === 'function') {
                        window.MemoryGraphReplaceSimpleChatTurns(turns);
                    }
                    refreshSimpleChatHistoryList();
                })
                .fail(function () {
                    window.alert('Could not load session.');
                });
        });

        $('#simple-chat-history-list').on('click', '.simple-chat-history-delete-one', function () {
            var $row = $(this).closest('.simple-chat-history-row');
            var sid = $row.attr('data-session-id');
            if (sid === undefined || sid === null) sid = '';
            var label = sid === '' ? 'legacy chats (no session id)' : sid;
            if (!window.confirm('Delete saved server history for:\n' + label + '?')) return;
            $.ajax({
                url: 'api/chat_sessions.php',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({ action: 'delete_session', sessionId: sid }),
                credentials: 'same-origin'
            })
                .done(function () {
                    refreshSimpleChatHistoryList();
                })
                .fail(function (xhr) {
                    var msg = (xhr.responseJSON && xhr.responseJSON.error) || xhr.statusText || 'Request failed';
                    window.alert(msg);
                });
        });

        $fab.on('click', function () {
            openSettings();
        });
        $('#settings-panel-close').on('click', closeSettings);
        $backdrop.on('click', closeSettings);

        $switch.on('change', function () {
            setMode($switch.prop('checked') ? 'simple' : 'graph');
        });

        $('.simple-nav-btn').on('click', function () {
            var sec = $(this).data('section');
            if (sec) {
                $detailCol.empty();
                showSection(sec);
            }
        });

        $(document).on('keydown', function (e) {
            if (e.key === 'Escape' && $panel.hasClass('is-open')) {
                closeSettings();
            }
        });

        var initial = getMode();
        $switch.prop('checked', initial === 'simple');
        applyMode(initial);

        window.MemoryGraphReloadSimpleAppsSection = function () {
            if (!document.documentElement.classList.contains('mg-simple-ui')) {
                return;
            }
            delete listCache.apps;
            if (currentSection === 'apps' && $simpleMain && $simpleMain.length && $simpleMain.hasClass('simple-main-view-library')) {
                loadSectionList('apps');
            }
        };

        var origRefresh = window.MemoryGraphRefresh;
        if (typeof origRefresh === 'function') {
            window.MemoryGraphRefresh = function () {
                listCache = {};
                origRefresh.apply(this, arguments);
                if (document.documentElement.classList.contains('mg-simple-ui')) {
                    if (currentSection === 'chat') {
                        if (typeof window.MemoryGraphRefreshSimpleChatThread === 'function') {
                            window.MemoryGraphRefreshSimpleChatThread();
                        }
                    } else {
                        loadSectionList(currentSection);
                    }
                }
            };
        }
    });
})();
