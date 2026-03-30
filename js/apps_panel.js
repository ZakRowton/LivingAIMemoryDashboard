/**
 * Web apps drawer (apps/) + fullscreen Bootstrap modal viewer.
 */
(function () {
    var webAppModalInstance = null;

    function resolveAppUrl(url) {
        if (!url || typeof url !== 'string') return '';
        try {
            return new URL(url, window.location.href).href;
        } catch (e) {
            return url;
        }
    }

    window.MemoryGraphOpenWebApp = function (payload) {
        if (!payload || !payload.url) return;
        var title = payload.title || payload.name || 'Web app';
        var $frame = $('#web-app-modal-frame');
        var $title = $('#web-app-modal-title');
        if ($title.length) $title.text(title);
        if ($frame.length) $frame.attr('src', resolveAppUrl(payload.url));
        var el = document.getElementById('web-app-modal');
        if (!el || typeof bootstrap === 'undefined' || !bootstrap.Modal) return;
        if (!webAppModalInstance) webAppModalInstance = new bootstrap.Modal(el);
        webAppModalInstance.show();
    };

    function renderAppsList(apps) {
        var $list = $('#apps-drawer-list');
        if (!$list.length) return;
        $list.empty();
        if (!apps || !apps.length) {
            $list.append($('<div class="apps-drawer-empty font-serif">No apps yet. Ask the AI to run <code>create_web_app</code> or use the bundled <strong>demo-counter</strong>.</div>'));
            return;
        }
        apps.forEach(function (a) {
            if (!a || !a.name) return;
            var title = a.title || a.name;
            var $row = $('<div class="apps-drawer-row">');
            $row.append($('<div class="apps-drawer-row-title font-serif">').attr('title', title).text(title));
            var $btn = $('<button type="button" class="apps-drawer-open-btn">Open</button>');
            $btn.on('click', function () {
                window.MemoryGraphOpenWebApp({
                    name: a.name,
                    title: title,
                    url: 'api/serve_app.php?app=' + encodeURIComponent(a.name)
                });
            });
            $row.append($btn);
            $list.append($row);
        });
    }

    function loadAppsList() {
        return $.getJSON('api/web_apps.php?action=list')
            .done(function (data) {
                var apps = (data && data.apps) ? data.apps : [];
                window.webAppsList = apps;
                renderAppsList(apps);
            })
            .fail(function () {
                var $list = $('#apps-drawer-list');
                if ($list.length) {
                    $list.empty().append($('<div class="apps-drawer-empty">Could not load apps.</div>'));
                }
            });
    }

    window.MemoryGraphReloadAppsList = loadAppsList;

    function openDrawer() {
        var $d = $('#apps-drawer');
        var $b = $('#apps-drawer-backdrop');
        if (!$d.length) return;
        $b.prop('hidden', false);
        requestAnimationFrame(function () {
            $b.addClass('is-open');
            $d.addClass('is-open').attr('aria-hidden', 'false');
        });
        loadAppsList();
    }

    function closeDrawer() {
        var $d = $('#apps-drawer');
        var $b = $('#apps-drawer-backdrop');
        $d.removeClass('is-open').attr('aria-hidden', 'true');
        $b.removeClass('is-open');
        setTimeout(function () {
            $b.prop('hidden', true);
        }, 280);
    }

    $(function () {
        $('#apps-fab').on('click', function () {
            openDrawer();
        });
        $('#apps-drawer-close, #apps-drawer-backdrop').on('click', function () {
            closeDrawer();
        });
        $('#apps-drawer-refresh').on('click', function () {
            loadAppsList();
        });
        $(document).on('keydown', function (e) {
            if (e.key === 'Escape' && $('#apps-drawer').hasClass('is-open')) closeDrawer();
        });

        var prevRefresh = window.MemoryGraphRefresh;
        window.MemoryGraphRefresh = function () {
            var out;
            try {
                if (typeof prevRefresh === 'function') out = prevRefresh.apply(this, arguments);
            } finally {
                loadAppsList();
            }
            return out;
        };

        loadAppsList();

        function notifyWebAppFrameResize() {
            var f = document.getElementById('web-app-modal-frame');
            if (!f || !f.contentWindow) return;
            try {
                f.contentWindow.dispatchEvent(new Event('resize'));
            } catch (e) {}
        }

        $('#web-app-modal').on('show.bs.modal', function () {
            document.body.classList.add('mg-web-app-modal-open');
        });
        $('#web-app-modal').on('shown.bs.modal', function () {
            notifyWebAppFrameResize();
            setTimeout(notifyWebAppFrameResize, 80);
            setTimeout(notifyWebAppFrameResize, 350);
        });
        $('#web-app-modal-frame').on('load', function () {
            notifyWebAppFrameResize();
            setTimeout(notifyWebAppFrameResize, 50);
            setTimeout(notifyWebAppFrameResize, 250);
        });
        $('#web-app-modal').on('hidden.bs.modal', function () {
            document.body.classList.remove('mg-web-app-modal-open');
            var f = document.getElementById('web-app-modal-frame');
            if (f) f.removeAttribute('src');
        });
    });
})();
