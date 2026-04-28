/**
 * Main chat: type \ to pick a sub-agent; shows green chip and routes the next send to that agent (targetSubAgent).
 */
(function () {
    var subagentList = null;
    var subagentStems = [];
    var mentionOpen = false;
    var mentionStart = -1;
    var selIndex = 0;
    var filtered = [];

    var $dd = null;
    var $input = null;
    var composer = null;

    function getStems() {
        return subagentStems;
    }

    function fetchList() {
        if (subagentList) return Promise.resolve(subagentList);
        return fetch('api_sub_agents.php?action=list', { method: 'GET' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                var raw = (d && d.subAgents) || [];
                subagentList = raw;
                subagentStems = raw
                    .map(function (a) {
                        if (!a || !a.name) return '';
                        var b = String(a.name).replace(/\.md$/i, '');
                        return b.length ? b : '';
                    })
                    .filter(function (s) { return s; });
                return subagentStems;
            })
            .catch(function () {
                subagentStems = [];
                return subagentStems;
            });
    }

    function closeMention() {
        mentionOpen = false;
        mentionStart = -1;
        if ($dd) {
            $dd.classList.remove('is-open');
            $dd.setAttribute('hidden', 'hidden');
        }
    }

    function buildFiltered(query) {
        var q = (query || '').toLowerCase();
        if (!q) {
            return subagentStems.slice();
        }
        return subagentStems.filter(function (s) {
            return s.toLowerCase().indexOf(q) !== -1;
        });
    }

    function renderList() {
        if (!$dd) return;
        $dd.innerHTML = '';
        if (!filtered.length) {
            $dd.appendChild((function () {
                var p = document.createElement('p');
                p.className = 'mg-sm-hint';
                p.style.padding = '10px 12px';
                p.textContent = subagentStems.length ? 'No matching sub-agent.' : 'No sub-agent files in sub-agents/.';
                return p;
            })());
            return;
        }
        var hint = document.createElement('div');
        hint.className = 'mg-sm-hint';
        hint.textContent = 'Sub-agents (Enter / Tab to insert)';
        $dd.appendChild(hint);
        var ul = document.createElement('ul');
        filtered.forEach(function (stem, i) {
            var li = document.createElement('li');
            li.setAttribute('role', 'option');
            li.setAttribute('data-idx', String(i));
            li.setAttribute('aria-selected', i === selIndex ? 'true' : 'false');
            li.textContent = stem;
            li.addEventListener('mousedown', function (e) {
                e.preventDefault();
                selectStem(stem);
            });
            ul.appendChild(li);
        });
        $dd.appendChild(ul);
    }

    function placeDropdown() {
        if (!$dd || !$input) return;
        if (!mentionOpen) return;
        var r = $input.getBoundingClientRect();
        var w = Math.min(300, window.innerWidth - 16);
        $dd.style.left = Math.max(8, Math.min(r.left, window.innerWidth - w - 8)) + 'px';
        $dd.style.width = w + 'px';
        $dd.style.top = (r.top - 6) + 'px';
        $dd.style.transform = 'translateY(-100%)';
    }

    function openMention(start) {
        mentionOpen = true;
        mentionStart = start;
        selIndex = 0;
        if (!$dd) return;
        $dd.classList.add('is-open');
        $dd.removeAttribute('hidden');
        var q = ($input.value || '').slice(start + 1);
        if (q.indexOf(' ') !== -1) {
            q = q.slice(0, q.indexOf(' '));
        }
        selIndex = 0;
        filtered = buildFiltered(q);
        if (selIndex >= filtered.length) {
            selIndex = 0;
        }
        renderList();
        requestAnimationFrame(function () { placeDropdown(); });
    }

    function onInput() {
        if (!mentionOpen || mentionStart < 0 || !$input) return;
        var v = $input.value || '';
        if (v.length <= mentionStart) {
            closeMention();
            return;
        }
        if (v[mentionStart] !== '\\') {
            closeMention();
            return;
        }
        var fromSlash = v.slice(mentionStart + 1);
        if (fromSlash.indexOf(' ') !== -1) {
            fromSlash = fromSlash.slice(0, fromSlash.indexOf(' '));
        }
        filtered = buildFiltered(fromSlash);
        selIndex = 0;
        if (selIndex >= filtered.length) {
            selIndex = 0;
        }
        renderList();
    }

    function selectStem(stem) {
        if (!stem || !$input) {
            closeMention();
            return;
        }
        var v = $input.value || '';
        var end = v.length;
        for (var i = mentionStart + 1; i < v.length; i++) {
            if (v[i] === ' ' || v[i] === '\n' || v[i] === '\t') {
                end = i;
                break;
            }
        }
        var newVal = (v.slice(0, mentionStart) + v.slice(end)).replace(/^\s+|\s+$/g, ' ');
        $input.value = newVal.replace(/^\s+/, '');

        var att = window.__mgMainChatAttachments;
        if (att && typeof att.setSubAgentRef === 'function') {
            att.setSubAgentRef({ stem: stem, label: stem });
        }
        closeMention();
        $input.focus();
    }

    function tryParseLeadingSlash() {
        var t = ($input && $input.value) ? $input.value : '';
        var m = t.match(/^\s*\\([a-zA-Z0-9_][a-zA-Z0-9_-]*)(\s+|$)([\s\S]*)$/);
        if (!m) {
            return null;
        }
        if (!subagentStems.length) {
            return null;
        }
        var slug = m[1];
        var ok = subagentStems.some(function (s) { return s.toLowerCase() === slug.toLowerCase(); });
        if (!ok) return null;
        if (!subagentStems.some(function (s) { return s === slug; })) {
            slug = subagentStems.filter(function (s) { return s.toLowerCase() === slug.toLowerCase(); })[0] || slug;
        }
        var rest = (m[3] != null) ? m[3] : '';
        return { stem: slug, text: rest.trim() };
    }

    function onKeydown(e) {
        if (e.isComposing) return;
        if (mentionOpen && $dd) {
            if (e.key === 'Escape') {
                e.preventDefault();
                closeMention();
                return;
            }
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                if (filtered.length) {
                    selIndex = (selIndex + 1) % filtered.length;
                }
                renderList();
                return;
            }
            if (e.key === 'ArrowUp') {
                e.preventDefault();
                if (filtered.length) {
                    selIndex = (selIndex - 1 + filtered.length) % filtered.length;
                }
                renderList();
                return;
            }
            if (e.key === 'Tab' && filtered.length) {
                e.preventDefault();
                selectStem(filtered[selIndex]);
                return;
            }
            if (e.key === 'Enter' && filtered.length) {
                e.preventDefault();
                selectStem(filtered[selIndex]);
                return;
            }
        }
    }

    function onInputCapture() {
        if (!mentionOpen && $input) {
            var v = $input.value || '';
            if (v.length < 1) return;
            var c = (typeof $input.selectionStart === 'number') ? $input.selectionStart : v.length;
            if (c > 0 && v[c - 1] === '\\') {
                fetchList().then(function (stems) {
                    if (!stems || !stems.length) {
                        return;
                    }
                    openMention(c - 1);
                });
            } else {
                onInput();
            }
        } else {
            onInput();
        }
    }

    function wire() {
        $dd = document.getElementById('mg-subagent-mention');
        $input = document.getElementById('chat-input');
        composer = document.getElementById('main-chat-composer');
        if (!$dd || !$input) return;
        $input.addEventListener('input', onInputCapture);
        $input.addEventListener('keydown', onKeydown, true);
        $input.addEventListener('click', onInput);
        $input.addEventListener('focus', function () { fetchList(); });
        $input.addEventListener('blur', function () {
            setTimeout(function () { closeMention(); }, 180);
        });
        window.addEventListener('resize', function () {
            if (mentionOpen) placeDropdown();
        });
        document.addEventListener('scroll', function () {
            if (mentionOpen) placeDropdown();
        }, true);
        window.MemoryGraphMentionListReady = function () { return fetchList().then(function () { return getStems().slice(); }); };
        window.MemoryGraphTryParseSubagentInInput = function () { return tryParseLeadingSlash(); };
    }

    window.MemoryGraphInitSubagentMention = function () {
        if (window.__mgSubagentMentionWired) {
            return;
        }
        window.__mgSubagentMentionWired = true;
        wire();
    };
})();
