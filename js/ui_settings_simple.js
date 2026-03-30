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
    var logLines = [];
    var MAX_LOG = 100;
    var lastStatusSig = '';

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

    function openSettings() {
        if (!$panel.length) return;
        $backdrop.removeAttr('hidden').addClass('is-open');
        $panel.addClass('is-open').attr('aria-hidden', 'false');
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

    window.SimpleUiLogFromStatus = function (status) {
        if (!document.documentElement.classList.contains('mg-simple-ui')) return;
        var inf = inferActivityFromStatus(status);
        var active = inf.active;

        PULSE_SECTIONS.forEach(function (s) {
            var el = document.querySelector('.simple-pulse-dot[data-pulse="' + s.id + '"]');
            if (!el) return;
            el.classList.toggle('is-live', !!active[s.id]);
        });

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
        if (sig === lastStatusSig) return;
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

    function renderList(items, section) {
        if (!$listCol || !$listCol.length) return;
        $listCol.empty();
        if (!items || !items.length) {
            $listCol.append($('<p class="simple-empty font-serif">').text('Nothing here yet.'));
            return;
        }
        var ul = $('<ul class="simple-item-list font-serif">');
        items.forEach(function (item) {
            var name = item.title || item.name || '(unnamed)';
            var active = item.active !== false;
            var li = $('<li class="simple-item-row">');
            var btn = $('<button type="button" class="simple-item-btn">').text(name);
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
