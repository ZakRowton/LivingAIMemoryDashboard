<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Memory Graph</title>
    <script>
    (function () {
        var filter = function (orig, blocklist) {
            return function () {
                var s = '';
                if (arguments.length && arguments[0] != null) {
                    if (typeof arguments[0] === 'string') s = arguments[0];
                    else if (arguments[0] && typeof arguments[0].message === 'string') s = arguments[0].message;
                }
                for (var i = 0; i < blocklist.length; i++) {
                    if (s.indexOf(blocklist[i]) !== -1) return;
                }
                orig.apply(console, arguments);
            };
        };
        var blockSES = ['cdn.tailwindcss.com', 'Removing intrinsics', 'lockdown-install', 'SES Removing', 'getOrInsert', 'toTemporalInstant', 'intrinsics.%', 'unpermitted intrinsics'];
        if (typeof console !== 'undefined') {
            if (console.log) console.log = filter(console.log, blockSES);
            if (console.warn) console.warn = filter(console.warn, blockSES);
            if (console.error) console.error = filter(console.error, blockSES);
        }
    })();
    </script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@400;700;900&family=Playfair+Display:ital,wght@0,400;0,600;1,400&display=swap" rel="stylesheet">
    <style>
        :root {
            --gold: #d8dde4;
            --gold-light: #f4f7fa;
            --gold-dim: #98a2ad;
            --black: #05070a;
            --panel-bg: rgba(12, 15, 19, 0.82);
        }
        [data-theme="light"] {
            --gold: #d8dde4;
            --gold-light: #1d2228;
            --gold-dim: #5a6672;
            --black: #edf1f4;
            --panel-bg: rgba(248, 250, 252, 0.96);
        }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; overflow: hidden; height: 100%; }
        body {
            font-family: 'Playfair Display', serif;
            background: var(--black);
            color: var(--gold-light);
            background-image:
                linear-gradient(27deg, rgba(255,255,255,0.018) 0%, rgba(255,255,255,0.018) 25%, transparent 25%, transparent 50%, rgba(255,255,255,0.018) 50%, rgba(255,255,255,0.018) 75%, transparent 75%, transparent 100%),
                linear-gradient(153deg, rgba(255,255,255,0.012) 0%, rgba(255,255,255,0.012) 25%, transparent 25%, transparent 50%, rgba(255,255,255,0.012) 50%, rgba(255,255,255,0.012) 75%, transparent 75%, transparent 100%),
                radial-gradient(circle at top center, #11161d 0%, #040507 48%, #000000 100%);
            background-size: 18px 18px, 18px 18px, cover;
        }
        [data-theme="light"] body {
            background: #f5f0e6;
            background-image: radial-gradient(circle at top center, #ebe5d9 0%, #e8e0d2 100%);
        }
        [data-theme="light"] .provider-select {
            color-scheme: light;
            background-color: #f0ebe0;
        }
        [data-theme="light"] .provider-select option {
            background: #f0ebe0;
            color: #1d2228;
        }
        #graph-container {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 0;
        }
        #graph-container canvas {
            display: block;
            width: 100%;
            height: 100%;
        }
        .graph-legend {
            position: fixed;
            top: 20px;
            left: 20px;
            z-index: 90;
            background: var(--panel-bg);
            backdrop-filter: blur(14px);
            border: 1px solid rgba(214, 219, 226, 0.22);
            border-radius: 12px;
            padding: 12px 14px;
            box-shadow: 0 0 18px rgba(214, 219, 226, 0.07), 0 8px 32px rgba(0,0,0,0.4);
        }
        .graph-legend-title {
            color: var(--gold);
            font-size: 0.95rem;
            margin-bottom: 8px;
            text-shadow: 0 0 12px rgba(214, 219, 226, 0.18);
        }
        .graph-legend-list {
            list-style: none;
            margin: 0;
            padding: 0;
            font-size: 0.88rem;
            color: var(--gold-light);
        }
        .graph-legend-list li {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 3px 0;
        }
        .graph-legend-swatch {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            flex-shrink: 0;
        }
        .chat-bar {
            position: fixed;
            bottom: 24px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 110;
            width: min(560px, calc(100vw - 32px));
            background: var(--panel-bg);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(214, 219, 226, 0.18);
            border-radius: 12px;
            padding: 10px 14px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.4);
        }
        .chat-bar .input-wrap {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        .chat-bar input {
            flex: 1;
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(214, 219, 226, 0.16);
            border-radius: 8px;
            padding: 12px 14px;
            color: var(--gold-light);
            font-family: 'Playfair Display', serif;
            font-size: 1rem;
        }
        .chat-bar input::placeholder { color: rgba(249, 241, 216, 0.5); }
        .chat-bar input:focus {
            outline: none;
            border-color: var(--gold);
            box-shadow: 0 0 12px rgba(214, 219, 226, 0.09);
        }
        .chat-bar .btn-send {
            background: linear-gradient(180deg, #eef2f6, #9ca7b2);
            color: #07090c;
            border: none;
            border-radius: 8px;
            padding: 12px 20px;
            font-family: 'Cinzel', serif;
            font-weight: 700;
            cursor: pointer;
        }
        .chat-bar .btn-send:hover { filter: brightness(1.1); }
        .chat-bar .btn-send:disabled { opacity: 0.6; cursor: not-allowed; }
        #notifications {
            position: fixed;
            bottom: 100px;
            right: 20px;
            z-index: 105;
            display: flex;
            flex-direction: column;
            gap: 10px;
            max-width: 320px;
        }
        .notification {
            background: var(--panel-bg);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(214, 219, 226, 0.18);
            border-radius: 10px;
            padding: 12px 14px;
            cursor: pointer;
            font-size: 0.9rem;
            line-height: 1.4;
            box-shadow: 0 0 14px rgba(214, 219, 226, 0.05), 0 4px 20px rgba(0,0,0,0.35);
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .notification:hover {
            transform: translateY(-2px);
            box-shadow: 0 0 20px rgba(214, 219, 226, 0.12), 0 6px 24px rgba(0,0,0,0.4);
        }
        .notification .preview { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
        /* Response modal – glowing panel */
        #response-modal .modal-content {
            background: var(--panel-bg);
            border: 1px solid rgba(214, 219, 226, 0.22);
            border-radius: 14px;
            color: var(--gold-light);
            box-shadow:
                0 0 20px rgba(214, 219, 226, 0.08),
                0 0 40px rgba(214, 219, 226, 0.05),
                0 20px 60px rgba(0, 0, 0, 0.5),
                inset 0 1px 0 rgba(214, 219, 226, 0.08);
        }
        #response-modal .modal-header {
            border-bottom: 1px solid rgba(214, 219, 226, 0.16);
            padding: 14px 18px;
        }
        #response-modal .modal-title {
            font-family: 'Cinzel', serif;
            color: var(--gold);
            text-shadow: 0 0 20px rgba(214, 219, 226, 0.18);
        }
        #response-modal .modal-body {
            white-space: normal;
            max-height: 70vh;
            overflow-y: auto;
            padding: 18px;
        }
        #response-modal .btn-close { filter: invert(1); opacity: 0.8; }
        .response-modal-section-title {
            margin: 0 0 10px;
            color: var(--gold);
            font-family: 'Cinzel', serif;
            font-size: 1rem;
            text-shadow: 0 0 16px rgba(214, 219, 226, 0.16);
        }
        .response-modal-text {
            white-space: pre-wrap;
            word-break: break-word;
            line-height: 1.65;
            margin-bottom: 16px;
        }
        .response-modal-prompt {
            padding: 12px 14px;
            border: 1px solid rgba(214, 219, 226, 0.14);
            border-radius: 10px;
            background: rgba(255,255,255,0.03);
        }
        .response-modal-code-block {
            margin-bottom: 18px;
        }
        .response-modal-code-label,
        .response-modal-preview-label {
            margin-bottom: 8px;
            color: var(--gold-dim);
            font-size: 0.82rem;
            letter-spacing: 0.06em;
            text-transform: uppercase;
        }
        .response-modal-code {
            margin: 0 0 14px;
            padding: 14px;
            border-radius: 10px;
            background: rgba(0, 0, 0, 0.5);
            border: 1px solid rgba(214, 219, 226, 0.14);
            color: #d6e8ff;
            font-family: "Courier New", monospace;
            font-size: 0.86rem;
            line-height: 1.55;
            white-space: pre-wrap;
            word-break: break-word;
            overflow: auto;
        }
        .response-modal-preview-frame {
            width: 100%;
            min-height: 320px;
            height: 320px;
            border: 1px solid rgba(214, 219, 226, 0.18);
            border-radius: 12px;
            background: #0a0a0a;
            box-shadow: inset 0 0 0 1px rgba(214, 219, 226, 0.05);
            overflow: hidden;
            display: block;
        }
        @media (max-width: 768px) {
            #graph-container {
                pointer-events: auto;
            }
            #graph-container canvas {
                touch-action: none;
                pointer-events: auto;
            }
            #notifications {
                bottom: 130px;
                left: 12px;
                right: 12px;
                max-width: none;
                z-index: 111;
            }
            .notification {
                min-height: 44px;
                -webkit-tap-highlight-color: transparent;
            }
            #response-modal.modal {
                z-index: 9999;
            }
            #response-modal .modal-dialog {
                max-width: calc(100vw - 24px);
                margin: 12px auto;
            }
            #response-modal .modal-body {
                max-height: 65vh;
                -webkit-overflow-scrolling: touch;
            }
            #response-modal .modal-content {
                max-height: 85vh;
            }
            body .modal-backdrop {
                z-index: 9998;
            }
        }
        .font-display { font-family: 'Cinzel', serif; }
        /* Node widget – glowing panel */
        .node-widget {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 90;
            width: min(320px, calc(100vw - 40px));
            background: var(--panel-bg);
            backdrop-filter: blur(14px);
            border: 1px solid rgba(214, 219, 226, 0.2);
            border-radius: 14px;
            padding: 0;
            box-shadow:
                0 0 18px rgba(214, 219, 226, 0.08),
                0 0 36px rgba(214, 219, 226, 0.04),
                0 12px 40px rgba(0, 0, 0, 0.45),
                inset 0 1px 0 rgba(214, 219, 226, 0.06);
            opacity: 0;
            visibility: hidden;
            transform: translateX(10px);
            transition: opacity 0.25s ease, visibility 0.25s ease, transform 0.25s ease, box-shadow 0.3s ease;
        }
        .node-widget.is-open {
            z-index: 110;
            opacity: 1;
            visibility: visible;
            transform: translateX(0);
            box-shadow:
                0 0 24px rgba(214, 219, 226, 0.12),
                0 0 48px rgba(214, 219, 226, 0.05),
                0 16px 48px rgba(0, 0, 0, 0.5),
                inset 0 1px 0 rgba(214, 219, 226, 0.08);
        }
        .node-widget-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 14px;
            border-bottom: 1px solid rgba(214, 219, 226, 0.16);
        }
        .node-widget-title {
            color: var(--gold);
            font-size: 1rem;
            text-shadow: 0 0 16px rgba(214, 219, 226, 0.18);
        }
        .node-widget-close {
            background: none;
            border: none;
            color: var(--gold-light);
            font-size: 1.4rem;
            line-height: 1;
            cursor: pointer;
            opacity: 0.8;
        }
        .node-widget-close:hover { opacity: 1; color: var(--gold); }
        .node-widget-body { padding: 14px; color: var(--gold-light); font-size: 0.95rem; line-height: 1.5; }
        .node-widget-label {
            color: var(--gold);
            font-weight: 600;
            text-shadow: 0 0 12px rgba(214, 219, 226, 0.14);
        }
        .node-widget-info { margin-top: 8px; }
        /* Agent Config styles (moved to node widget) */
        .provider-label {
            display: block;
            margin-top: 10px;
            margin-bottom: 4px;
            font-size: 0.85rem;
            color: var(--gold-dim, #98a2ad);
        }
        .provider-select, .provider-input, .provider-textarea {
            width: 100%;
            padding: 8px 10px;
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(214, 219, 226, 0.16);
            border-radius: 6px;
            color: var(--gold-light);
            font-family: 'Playfair Display', serif;
            font-size: 0.9rem;
        }
        .provider-select {
            color-scheme: dark;
            background-color: #0f0f0f;
        }
        .provider-select option {
            background: #0f0f0f;
            color: #f9f1d8;
        }
        .provider-textarea { resize: vertical; min-height: 60px; }
        .provider-select:focus, .provider-input:focus, .provider-textarea:focus {
            outline: none;
            border-color: var(--gold);
            box-shadow: 0 0 10px rgba(214, 219, 226, 0.08);
        }
        .panel-action-btn {
            margin-top: 10px;
            width: 100%;
            background: linear-gradient(180deg, #eef2f6, #9ca7b2);
            color: #07090c;
            border: none;
            border-radius: 8px;
            padding: 10px 14px;
            font-family: 'Cinzel', serif;
            font-weight: 700;
            cursor: pointer;
        }
        .panel-action-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        .panel-action-btn-row {
            display: flex;
            gap: 8px;
            margin-top: 10px;
        }
        .panel-action-btn-row .panel-action-btn {
            margin-top: 0;
        }
        .btn-stop {
            background: linear-gradient(180deg, #7a1515, #b91c1c);
            color: #f9f1d8;
        }
        .job-config-actions {
            display: flex;
            gap: 8px;
            margin-top: 10px;
        }
        .job-config-actions .panel-action-btn {
            margin-top: 0;
        }
        .running-jobs-widget {
            position: fixed;
            left: 20px;
            bottom: 20px;
            z-index: 108;
            width: min(360px, calc(100vw - 40px));
            background: var(--panel-bg);
            backdrop-filter: blur(16px);
            border: 1px solid rgba(214, 219, 226, 0.24);
            border-radius: 16px;
            padding: 14px;
            box-shadow: 0 14px 40px rgba(0, 0, 0, 0.48), 0 0 24px rgba(214, 219, 226, 0.08);
        }
        @media (max-width: 768px) {
            .running-jobs-widget {
                left: 50%;
                transform: translateX(-50%);
                bottom: 100px;
                width: min(360px, calc(100vw - 24px));
            }
        }
        .running-jobs-title {
            color: var(--gold);
            font-size: 0.95rem;
            margin-bottom: 10px;
            text-shadow: 0 0 10px rgba(214, 219, 226, 0.18);
        }
        .running-jobs-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
            max-height: 280px;
            overflow-y: auto;
        }
        .running-job-item {
            border: 1px solid rgba(214, 219, 226, 0.14);
            border-radius: 12px;
            padding: 12px;
            background: rgba(255,255,255,0.03);
        }
        .running-job-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 8px;
        }
        .running-job-name {
            color: var(--gold-light);
            font-size: 0.95rem;
        }
        .running-job-spinner {
            width: 16px;
            height: 16px;
            border: 2px solid rgba(214, 219, 226, 0.25);
            border-top-color: var(--gold);
            border-radius: 50%;
            animation: running-job-spin 0.8s linear infinite;
            flex-shrink: 0;
        }
        .running-job-status {
            font-size: 0.82rem;
            color: var(--gold-dim);
            margin-bottom: 8px;
            white-space: pre-wrap;
            word-break: break-word;
        }
        .running-job-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .running-job-btn {
            border: 1px solid rgba(214, 219, 226, 0.16);
            border-radius: 8px;
            padding: 7px 10px;
            background: rgba(255,255,255,0.05);
            color: var(--gold-light);
            font-family: 'Cinzel', serif;
            font-size: 0.75rem;
        }
        .running-job-empty {
            color: var(--gold-dim);
            font-size: 0.88rem;
        }
        @keyframes running-job-spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        .tool-list-panel {
            margin-top: 12px;
            display: flex;
            flex-direction: column;
            gap: 8px;
            max-height: 260px;
            overflow-y: auto;
        }
        .tool-list-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 10px 12px;
            border: 1px solid rgba(214, 219, 226, 0.14);
            border-radius: 8px;
            background: rgba(255,255,255,0.04);
        }
        .tool-list-name {
            font-size: 0.92rem;
            color: var(--gold-light);
            line-height: 1.3;
            word-break: break-word;
        }
        .execution-widget {
            position: fixed;
            right: 20px;
            z-index: 109;
            width: min(320px, calc(100vw - 40px));
            background: var(--panel-bg);
            backdrop-filter: blur(14px);
            border: 1px solid rgba(214, 219, 226, 0.18);
            border-radius: 14px;
            padding: 14px;
            box-shadow:
                0 0 18px rgba(214, 219, 226, 0.07),
                0 12px 40px rgba(0, 0, 0, 0.45);
            opacity: 0;
            visibility: hidden;
            transform: translateX(10px);
            transition: opacity 0.2s ease, visibility 0.2s ease, transform 0.2s ease;
        }
        .execution-widget.is-open {
            opacity: 1;
            visibility: visible;
            transform: translateX(0);
        }
        .execution-widget-title {
            color: var(--gold);
            font-size: 0.95rem;
            margin-bottom: 8px;
            text-shadow: 0 0 12px rgba(214, 219, 226, 0.14);
        }
        .execution-widget pre {
            margin: 0;
            background: rgba(0,0,0,0.45);
            border: 1px solid rgba(214,219,226,0.14);
            border-radius: 8px;
            padding: 10px;
            color: #d6e8ff;
            white-space: pre-wrap;
            word-break: break-word;
            max-height: 220px;
            overflow: auto;
            font-size: 0.82rem;
            font-family: "Courier New", monospace;
        }
        .mb-1 { margin-bottom: 0.25rem; }
        .mb-2 { margin-bottom: 0.5rem; }
    </style>
</head>
<body>
    <div id="graph-container"></div>

    <div style="position:fixed;top:20px;left:0;width:100%;z-index:95;text-align:center;pointer-events:none;">
        <div style="display:inline-block;pointer-events:auto;color:var(--gold);font-family:'Cinzel',serif;text-shadow:0 0 16px rgba(214,219,226,0.26);">
            <h1>Neus Agent Dashboard</h1>
        </div>
    </div>

    <div class="graph-legend" id="graph-legend">
        <div class="graph-legend-title font-display">Nodes</div>
        <ul class="graph-legend-list">
            <li><span class="graph-legend-swatch" style="background:#d9e4ff; box-shadow:0 0 8px rgba(217,228,255,0.7);"></span> Agent</li>
            <li><span class="graph-legend-swatch" style="background:#47d7c9; box-shadow:0 0 8px rgba(71,215,201,0.6);"></span> Memory</li>
            <li><span class="graph-legend-swatch" style="background:#ffc857; box-shadow:0 0 8px rgba(255,200,87,0.6);"></span> Tools</li>
            <li><span class="graph-legend-swatch" style="background:#7cb8ff; box-shadow:0 0 8px rgba(124,184,255,0.65);"></span> Instructions</li>
            <li><span class="graph-legend-swatch" style="background:#6be38e; box-shadow:0 0 8px rgba(107,227,142,0.55);"></span> MCPs</li>
            <li><span class="graph-legend-swatch" style="background:#ff8f70; box-shadow:0 0 8px rgba(255,143,112,0.58);"></span> Jobs</li>
        </ul>
    </div>



    <div class="chat-bar">
        <div class="input-wrap">
            <input type="text" id="chat-input" placeholder="Ask the AI..." autocomplete="off">
            <button type="button" class="btn-send" id="chat-send">Send</button>
            <button type="button" class="btn-send btn-stop" id="chat-stop">Stop</button>
        </div>
    </div>

    <aside id="node-widget" class="node-widget" aria-hidden="true">
        <div class="node-widget-header">
            <span class="node-widget-title font-display">Node</span>
            <button type="button" class="node-widget-close" id="node-widget-close" aria-label="Close">&times;</button>
        </div>
        <div class="node-widget-body">
            <p class="node-widget-label mb-2"></p>
            <div class="node-widget-info"></div>
            <div id="agent-config-panel" style="display: none; margin-top: 15px;">
                <label class="provider-label">Provider</label>
                <select id="provider-select" class="provider-select">
                    <option value="mercury">Mercury (Inception Labs)</option>
                    <option value="featherless">Featherless</option>
                    <option value="alibaba">Alibaba Cloud</option>
                    <option value="gemini">Gemini (Google)</option>
                </select>
                <label class="provider-label">Model</label>
                <select id="model-select" class="provider-select"></select>
                <label class="provider-label">System prompt</label>
                <textarea id="system-prompt-input" class="provider-textarea" rows="3" placeholder="Optional system instruction..."></textarea>
                <label class="provider-label">Temperature</label>
                <input type="number" id="temperature-input" class="provider-input" min="0" max="2" step="0.1" value="0.7">
            </div>
            <div id="tool-config-panel" style="display: none; margin-top: 15px;">
                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" id="tool-active-switch">
                    <label class="form-check-label provider-label" for="tool-active-switch" style="margin-top:0; cursor:pointer;">Enabled</label>
                </div>
                <label class="provider-label">Underlying Code</label>
                <pre id="tool-code-display" style="background: rgba(0,0,0,0.5); padding: 10px; border-radius: 6px; font-size: 0.8rem; overflow-x: auto; color: #dce3ea; max-height: 250px; white-space: pre-wrap; font-family: monospace; border: 1px solid rgba(214,219,226,0.2);"></pre>
            </div>
            <div id="tools-parent-panel" style="display: none; margin-top: 15px;">
                <div class="panel-action-btn-row">
                    <button type="button" id="tools-enable-all-btn" class="panel-action-btn">Enable All</button>
                    <button type="button" id="tools-disable-all-btn" class="panel-action-btn">Disable All</button>
                </div>
                <div id="tools-list-panel" class="tool-list-panel"></div>
            </div>
            <div id="memory-config-panel" style="display: none; margin-top: 15px;">
                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" id="memory-active-switch">
                    <label class="form-check-label provider-label" for="memory-active-switch" style="margin-top:0; cursor:pointer;">Enabled</label>
                </div>
                <label class="provider-label">Memory Contents</label>
                <textarea id="memory-content-input" class="provider-textarea" rows="10" placeholder="Memory file contents..."></textarea>
                <button type="button" id="memory-save-btn" class="panel-action-btn">Save Memory</button>
            </div>
            <div id="instruction-config-panel" style="display: none; margin-top: 15px;">
                <label class="provider-label">Instruction Contents</label>
                <textarea id="instruction-content-input" class="provider-textarea" rows="10" placeholder="Instruction file contents..."></textarea>
                <button type="button" id="instruction-save-btn" class="panel-action-btn">Save Instruction</button>
            </div>
            <div id="mcps-parent-panel" style="display: none; margin-top: 15px;">
                <div class="panel-action-btn-row">
                    <button type="button" id="mcp-new-btn" class="panel-action-btn">New MCP</button>
                    <button type="button" id="mcps-enable-all-btn" class="panel-action-btn">Enable All</button>
                    <button type="button" id="mcps-disable-all-btn" class="panel-action-btn">Disable All</button>
                </div>
                <div id="mcps-list-panel" class="tool-list-panel"></div>
            </div>
            <div id="mcp-config-panel" style="display: none; margin-top: 15px;">
                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" id="mcp-active-switch">
                    <label class="form-check-label provider-label" for="mcp-active-switch" style="margin-top:0; cursor:pointer;">Enabled</label>
                </div>
                <label class="provider-label">Server Name</label>
                <input type="text" id="mcp-name-input" class="provider-input" placeholder="my-mcp-server">
                <label class="provider-label">Description</label>
                <textarea id="mcp-description-input" class="provider-textarea" rows="2" placeholder="Optional description..."></textarea>
                <label class="provider-label">Transport</label>
                <select id="mcp-transport-input" class="provider-select">
                    <option value="stdio">stdio</option>
                    <option value="streamablehttp">streamablehttp</option>
                </select>
                <label class="provider-label">Command</label>
                <input type="text" id="mcp-command-input" class="provider-input" placeholder="npx">
                <label class="provider-label">Args (JSON array)</label>
                <textarea id="mcp-args-input" class="provider-textarea" rows="3" placeholder='["-y","@modelcontextprotocol/server-filesystem","C:\\path"]'></textarea>
                <label class="provider-label">Env (JSON object)</label>
                <textarea id="mcp-env-input" class="provider-textarea" rows="3" placeholder='{"API_KEY":"value"}'></textarea>
                <label class="provider-label">Working Directory</label>
                <input type="text" id="mcp-cwd-input" class="provider-input" placeholder="Optional working directory">
                <label class="provider-label">URL</label>
                <input type="text" id="mcp-url-input" class="provider-input" placeholder="Optional URL for non-stdio transports">
                <label class="provider-label">Headers (JSON object)</label>
                <textarea id="mcp-headers-input" class="provider-textarea" rows="3" placeholder='{"Authorization":"Bearer token"}'></textarea>
                <label class="provider-label">Available Tools</label>
                <pre id="mcp-tools-display" style="background: rgba(0,0,0,0.5); padding: 10px; border-radius: 6px; font-size: 0.8rem; overflow-x: auto; color: #dce3ea; max-height: 220px; white-space: pre-wrap; font-family: monospace; border: 1px solid rgba(214,219,226,0.2);"></pre>
                <div class="panel-action-btn-row">
                    <button type="button" id="mcp-save-btn" class="panel-action-btn">Save MCP</button>
                    <button type="button" id="mcp-refresh-tools-btn" class="panel-action-btn">Refresh Tools</button>
                </div>
                <button type="button" id="mcp-delete-btn" class="panel-action-btn btn-stop">Delete MCP</button>
            </div>
            <div id="job-config-panel" style="display: none; margin-top: 15px;">
                <label class="provider-label">Job Contents</label>
                <textarea id="job-content-input" class="provider-textarea" rows="10" placeholder="Job tasks..."></textarea>
                <div class="job-config-actions">
                    <button type="button" id="job-save-btn" class="panel-action-btn">Save Job</button>
                    <button type="button" id="job-execute-btn" class="panel-action-btn">Execute Job</button>
                    <button type="button" id="job-stop-btn" class="panel-action-btn btn-stop">Stop Job</button>
                </div>
            </div>
        </div>
    </aside>

    <aside id="execution-widget" class="execution-widget" aria-hidden="true">
        <div class="execution-widget-title font-display">Execution Parameters</div>
        <pre id="execution-widget-body"></pre>
    </aside>

    <aside id="running-jobs-widget" class="running-jobs-widget" aria-hidden="false">
        <div class="running-jobs-title font-display">Running Jobs</div>
        <div id="running-jobs-list" class="running-jobs-list">
            <div class="running-job-empty">No jobs running.</div>
        </div>
    </aside>

    <div id="notifications"></div>

    <div class="modal fade" id="response-modal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">AI Response</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="response-modal-body"></div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/controls/OrbitControls.js"></script>
    <script src="AgentState.js"></script>
    <script src="js/graph.js"></script>
    <script>
    window.MEMORY_GRAPH_PROVIDERS = {
        mercury: { name: 'Mercury (Inception Labs)', models: ['mercury-2'] },
        featherless: { name: 'Featherless', models: ['glm47-flash'] },
        alibaba: { name: 'Alibaba Cloud', models: ['qwen-plus'] },
        gemini: {
            name: 'Gemini (Google)',
            models: [
                'gemini-2.5-flash', 'gemini-2.5-pro',
                'gemini-3-flash-preview', 'gemini-3-pro-preview',
                'gemini-3-flash', 'gemini-3-pro',
                'gemini-3.1-flash-preview', 'gemini-3.1-pro-preview'
            ]
        }
    };
    (function () {
        var providerSelect = document.getElementById('provider-select');
        var modelSelect = document.getElementById('model-select');
        var systemPromptInput = document.getElementById('system-prompt-input');
        var temperatureInput = document.getElementById('temperature-input');

        function syncModelSelect() {
            var p = (providerSelect && providerSelect.value) || 'mercury';
            var list = (window.MEMORY_GRAPH_PROVIDERS[p] && window.MEMORY_GRAPH_PROVIDERS[p].models) || [];
            if (!modelSelect) return;
            modelSelect.innerHTML = '';
            list.forEach(function (m) {
                var opt = document.createElement('option');
                opt.value = m;
                opt.textContent = m;
                modelSelect.appendChild(opt);
            });
        }
        if (providerSelect) providerSelect.addEventListener('change', syncModelSelect);
        syncModelSelect();

        function applyAgentConfig(data) {
            if (!data || !data.providers) return;
            window.MEMORY_GRAPH_PROVIDERS = data.providers;
            if (providerSelect) {
                providerSelect.innerHTML = '';
                Object.keys(data.providers).forEach(function (key) {
                    var opt = document.createElement('option');
                    opt.value = key;
                    opt.textContent = (data.providers[key] && data.providers[key].name) ? data.providers[key].name : key;
                    providerSelect.appendChild(opt);
                });
                if (data.currentProvider) providerSelect.value = data.currentProvider;
                syncModelSelect();
            }
            if (modelSelect && data.currentModel) modelSelect.value = data.currentModel;
        }
        window.applyAgentConfig = applyAgentConfig;
        fetch('api/agent_config.php')
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) { if (data) applyAgentConfig(data); })
            .catch(function () {});

        window.getAgentSettings = function () {
            return {
                provider: (providerSelect && providerSelect.value) || 'mercury',
                providerName: (window.MEMORY_GRAPH_PROVIDERS[(providerSelect && providerSelect.value) || 'mercury'] || {}).name || 'Mercury',
                model: (modelSelect && modelSelect.value) || 'mercury-2',
                systemPrompt: (systemPromptInput && systemPromptInput.value) || '',
                temperature: (temperatureInput && temperatureInput.value) !== '' ? parseFloat(temperatureInput.value) : 0.7
            };
        };
    })();
    </script>
    <script src="js/chat.js"></script>
    <script src="js/jobs.js"></script>
    <script>
    (function () {
        var widget = document.getElementById('node-widget');
        var executionWidget = document.getElementById('execution-widget');
        var executionWidgetBody = document.getElementById('execution-widget-body');
        var titleEl = widget && widget.querySelector('.node-widget-title');
        var labelEl = widget && widget.querySelector('.node-widget-label');
        var infoEl = widget && widget.querySelector('.node-widget-info');
        var closeBtn = document.getElementById('node-widget-close');
        var agentConfig = document.getElementById('agent-config-panel');
        var toolConfig = document.getElementById('tool-config-panel');
        var toolsParentPanel = document.getElementById('tools-parent-panel');
        var memoryConfig = document.getElementById('memory-config-panel');
        var instructionConfig = document.getElementById('instruction-config-panel');
        var instructionContentInput = document.getElementById('instruction-content-input');
        var instructionSaveBtn = document.getElementById('instruction-save-btn');
        var mcpsParentPanel = document.getElementById('mcps-parent-panel');
        var mcpConfig = document.getElementById('mcp-config-panel');
        var jobConfig = document.getElementById('job-config-panel');
        var toolSwitchEl = document.getElementById('tool-active-switch');
        var toolsListPanel = document.getElementById('tools-list-panel');
        var toolsEnableAllBtn = document.getElementById('tools-enable-all-btn');
        var toolsDisableAllBtn = document.getElementById('tools-disable-all-btn');
        var memorySwitchEl = document.getElementById('memory-active-switch');
        var memoryContentInput = document.getElementById('memory-content-input');
        var memorySaveBtn = document.getElementById('memory-save-btn');
        var mcpNewBtn = document.getElementById('mcp-new-btn');
        var mcpsEnableAllBtn = document.getElementById('mcps-enable-all-btn');
        var mcpsDisableAllBtn = document.getElementById('mcps-disable-all-btn');
        var mcpsListPanel = document.getElementById('mcps-list-panel');
        var mcpActiveSwitchEl = document.getElementById('mcp-active-switch');
        var mcpNameInput = document.getElementById('mcp-name-input');
        var mcpDescriptionInput = document.getElementById('mcp-description-input');
        var mcpTransportInput = document.getElementById('mcp-transport-input');
        var mcpCommandInput = document.getElementById('mcp-command-input');
        var mcpArgsInput = document.getElementById('mcp-args-input');
        var mcpEnvInput = document.getElementById('mcp-env-input');
        var mcpCwdInput = document.getElementById('mcp-cwd-input');
        var mcpUrlInput = document.getElementById('mcp-url-input');
        var mcpHeadersInput = document.getElementById('mcp-headers-input');
        var mcpToolsDisplay = document.getElementById('mcp-tools-display');
        var mcpSaveBtn = document.getElementById('mcp-save-btn');
        var mcpRefreshToolsBtn = document.getElementById('mcp-refresh-tools-btn');
        var mcpDeleteBtn = document.getElementById('mcp-delete-btn');
        var jobContentInput = document.getElementById('job-content-input');
        var jobSaveBtn = document.getElementById('job-save-btn');
        var jobExecuteBtn = document.getElementById('job-execute-btn');
        var jobStopBtn = document.getElementById('job-stop-btn');
        var toolCodeEl = document.getElementById('tool-code-display');

        window.currentOpenedTool = null;
        window.currentOpenedMemory = null;
        window.currentOpenedMcp = null;
        window.currentOpenedJob = null;
        window.currentOpenedNodeId = null;

        function escapeHtml(s) {
            if (!s) return '';
            var div = document.createElement('div');
            div.textContent = s;
            return div.innerHTML;
        }

        function hideAllPanels() {
            if (agentConfig) agentConfig.style.display = 'none';
            if (toolConfig) toolConfig.style.display = 'none';
            if (toolsParentPanel) toolsParentPanel.style.display = 'none';
            if (memoryConfig) memoryConfig.style.display = 'none';
            if (instructionConfig) instructionConfig.style.display = 'none';
            if (mcpsParentPanel) mcpsParentPanel.style.display = 'none';
            if (mcpConfig) mcpConfig.style.display = 'none';
            if (jobConfig) jobConfig.style.display = 'none';
            window.currentOpenedTool = null;
            window.currentOpenedMemory = null;
            window.currentOpenedInstruction = null;
            window.currentOpenedMcp = null;
            window.currentOpenedJob = null;
        }

        function hideExecutionWidget() {
            if (!executionWidget) return;
            executionWidget.classList.remove('is-open');
            executionWidget.setAttribute('aria-hidden', 'true');
        }

        function updateExecutionWidgetPosition() {
            if (!widget || !executionWidget) return;
            var rect = widget.getBoundingClientRect();
            executionWidget.style.top = (rect.bottom + 12) + 'px';
        }

        function renderExecutionWidget(nodeId) {
            if (!executionWidget || !executionWidgetBody) return;
            var state = window.agentState || null;
            var detailsMap = {};
            if (state && state.executionDetailsByNode) {
                Object.keys(state.executionDetailsByNode).forEach(function (key) {
                    detailsMap[key] = state.executionDetailsByNode[key];
                });
            }
            if (state && state.backgroundExecutionDetailsByNode) {
                Object.keys(state.backgroundExecutionDetailsByNode).forEach(function (key) {
                    detailsMap[key] = state.backgroundExecutionDetailsByNode[key];
                });
            }
            var detail = nodeId ? detailsMap[nodeId] : null;
            if (!detail) {
                hideExecutionWidget();
                return;
            }
            var payload = {
                tool: detail.toolName || '',
                arguments: detail.arguments || {}
            };
            executionWidgetBody.textContent = JSON.stringify(payload, null, 2);
            updateExecutionWidgetPosition();
            executionWidget.classList.add('is-open');
            executionWidget.setAttribute('aria-hidden', 'false');
        }

        function refreshGraph() {
            if (typeof window.MemoryGraphRefresh === 'function') {
                window.MemoryGraphRefresh();
            }
        }

        function refreshToolsData() {
            return fetch('api_tools.php?action=list')
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    window.toolsData = data.tools || [];
                    return window.toolsData;
                });
        }

        function refreshMemoryData() {
            return fetch('api_memory.php?action=list')
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    window.memoryFiles = data.memories || [];
                    return window.memoryFiles;
                });
        }

        function refreshJobsData() {
            return fetch('api_jobs.php?action=list')
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    window.jobFiles = data.jobs || [];
                    return window.jobFiles;
                });
        }

        function refreshMcpData() {
            return fetch('api_mcps.php?action=list')
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    window.mcpServers = data.servers || [];
                    return window.mcpServers;
                });
        }

        function renderToolsList() {
            if (!toolsListPanel) return;
            var tools = window.toolsData || [];
            toolsListPanel.innerHTML = '';
            tools.forEach(function (tool) {
                var row = document.createElement('div');
                row.className = 'tool-list-item';

                var name = document.createElement('div');
                name.className = 'tool-list-name';
                name.textContent = tool.name;

                var wrap = document.createElement('div');
                wrap.className = 'form-check form-switch';

                var input = document.createElement('input');
                input.className = 'form-check-input';
                input.type = 'checkbox';
                input.checked = !!tool.active;
                input.disabled = !!tool.builtin;
                input.addEventListener('change', function () {
                    if (tool.builtin) return;
                    tool.active = input.checked;
                    fetch('api_tools.php?action=toggle', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({ name: tool.name, active: input.checked })
                    })
                    .then(function (res) {
                        if (!res.ok) throw new Error('Failed to toggle tool');
                        return res.json();
                    })
                    .then(function () {
                        return refreshToolsData();
                    })
                    .then(function () {
                        renderToolsList();
                        refreshGraph();
                    })
                    .catch(function () {
                        input.checked = !input.checked;
                    });
                });

                wrap.appendChild(input);
                row.appendChild(name);
                row.appendChild(wrap);
                toolsListPanel.appendChild(row);
            });
        }

        function toggleAllTools(active) {
            fetch('api_tools.php?action=toggle_all', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ active: active })
            }).then(function (res) {
                if (!res.ok) throw new Error('Failed to toggle all tools');
                return res.json();
            }).then(function () {
                return refreshToolsData();
            }).then(function () {
                renderToolsList();
                refreshGraph();
            });
        }

        function safeParseJson(text, fallback) {
            if (!text || !String(text).trim()) return fallback;
            try {
                return JSON.parse(text);
            } catch (err) {
                return null;
            }
        }

        function openMcpConfigPanel(server) {
            if (!mcpConfig) return;
            mcpConfig.style.display = 'block';
            window.currentOpenedMcp = server ? {
                id: server.nodeId || null,
                name: server.name || '',
                originalName: server.name || ''
            } : {
                id: null,
                name: '',
                originalName: ''
            };
            if (mcpActiveSwitchEl) mcpActiveSwitchEl.checked = server ? !!server.active : true;
            if (mcpNameInput) mcpNameInput.value = server ? (server.name || '') : '';
            if (mcpDescriptionInput) mcpDescriptionInput.value = server ? (server.description || '') : '';
            if (mcpTransportInput) {
                var transportValue = server ? (server.transport || 'stdio') : 'stdio';
                var hasTransportOption = Array.prototype.some.call(mcpTransportInput.options || [], function (option) {
                    return option.value === transportValue;
                });
                if (!hasTransportOption && transportValue) {
                    var opt = document.createElement('option');
                    opt.value = transportValue;
                    opt.textContent = transportValue;
                    mcpTransportInput.appendChild(opt);
                }
                mcpTransportInput.value = transportValue;
            }
            if (mcpCommandInput) mcpCommandInput.value = server ? (server.command || '') : '';
            if (mcpArgsInput) mcpArgsInput.value = JSON.stringify(server && server.args ? server.args : [], null, 2);
            if (mcpEnvInput) mcpEnvInput.value = JSON.stringify(server && server.env ? server.env : {}, null, 2);
            if (mcpCwdInput) mcpCwdInput.value = server ? (server.cwd || '') : '';
            if (mcpUrlInput) mcpUrlInput.value = server ? (server.url || '') : '';
            if (mcpHeadersInput) mcpHeadersInput.value = JSON.stringify(server && server.headers ? server.headers : {}, null, 2);
            if (mcpToolsDisplay) mcpToolsDisplay.textContent = server ? 'Loading MCP tools...' : 'Save the MCP server to load its tools.';
            if (mcpDeleteBtn) mcpDeleteBtn.disabled = !server;
            if (mcpRefreshToolsBtn) mcpRefreshToolsBtn.disabled = !server;
            if (server) loadMcpTools(server.name);
        }

        function renderMcpList() {
            if (!mcpsListPanel) return;
            var servers = window.mcpServers || [];
            mcpsListPanel.innerHTML = '';
            servers.forEach(function (server) {
                var row = document.createElement('div');
                row.className = 'tool-list-item';

                var left = document.createElement('button');
                left.type = 'button';
                left.className = 'tool-list-name';
                left.style.background = 'none';
                left.style.border = 'none';
                left.style.padding = '0';
                left.style.textAlign = 'left';
                left.style.cursor = 'pointer';
                left.textContent = server.title || server.name;
                left.addEventListener('click', function () {
                    openWidget(server.title || server.name, server.nodeId);
                });

                var wrap = document.createElement('div');
                wrap.className = 'form-check form-switch';

                var input = document.createElement('input');
                input.className = 'form-check-input';
                input.type = 'checkbox';
                input.checked = !!server.active;
                input.addEventListener('change', function () {
                    fetch('api_mcps.php?action=toggle', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({ name: server.name, active: input.checked })
                    })
                    .then(function (res) {
                        if (!res.ok) throw new Error('Failed to toggle MCP server');
                        return res.json();
                    })
                    .then(function () {
                        return refreshMcpData();
                    })
                    .then(function () {
                        renderMcpList();
                        refreshGraph();
                    })
                    .catch(function () {
                        input.checked = !input.checked;
                    });
                });

                wrap.appendChild(input);
                row.appendChild(left);
                row.appendChild(wrap);
                mcpsListPanel.appendChild(row);
            });
            if (!servers.length) {
                mcpsListPanel.innerHTML = '<div class="running-job-empty">No MCP servers configured.</div>';
            }
        }

        function toggleAllMcps(active) {
            fetch('api_mcps.php?action=toggle_all', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ active: active })
            }).then(function (res) {
                if (!res.ok) throw new Error('Failed to toggle MCP servers');
                return res.json();
            }).then(function () {
                return refreshMcpData();
            }).then(function () {
                renderMcpList();
                refreshGraph();
            });
        }

        function loadMemoryIntoPanel(name) {
            fetch('api_memory.php?action=get&name=' + encodeURIComponent(name))
                .then(function (res) { return res.json(); })
                .then(function (memory) {
                    if (!window.currentOpenedMemory || window.currentOpenedMemory.name !== memory.name) return;
                    if (memorySwitchEl) memorySwitchEl.checked = !!memory.active;
                    if (memoryContentInput) memoryContentInput.value = memory.content || '';
                    infoEl.innerHTML = '<p class="mb-1"><strong>Memory:</strong> ' + escapeHtml(memory.name) + '</p>';
                });
        }

        function loadInstructionIntoPanel(name) {
            fetch('api_instructions.php?action=get&name=' + encodeURIComponent(name))
                .then(function (res) {
                    if (!res.ok) throw new Error('Instruction not found');
                    return res.json();
                })
                .then(function (instruction) {
                    if (!window.currentOpenedInstruction || window.currentOpenedInstruction.name !== instruction.name) return;
                    if (instructionContentInput) instructionContentInput.value = instruction.content || '';
                    infoEl.innerHTML = '<p class="mb-1"><strong>Instruction:</strong> ' + escapeHtml(instruction.name) + '</p>';
                })
                .catch(function () {
                    if (instructionContentInput) instructionContentInput.value = '';
                    if (window.currentOpenedInstruction && window.currentOpenedInstruction.name === name) {
                        infoEl.innerHTML = '<p class="mb-1"><strong>Instruction:</strong> ' + escapeHtml(name) + '</p><p class="mb-1 text-muted">Could not load contents.</p>';
                    }
                });
        }

        function loadMcpTools(name) {
            if (!mcpToolsDisplay) return;
            mcpToolsDisplay.textContent = 'Loading MCP tools...';
            fetch('api_mcps.php?action=tools&name=' + encodeURIComponent(name))
                .then(function (res) { return res.json(); })
                .then(function (payload) {
                    if (!window.currentOpenedMcp || window.currentOpenedMcp.name !== name) return;
                    if (payload && payload.error) {
                        mcpToolsDisplay.textContent = 'Error: ' + payload.error + (payload.details ? '\n' + JSON.stringify(payload.details, null, 2) : '');
                        return;
                    }
                    var tools = payload && Array.isArray(payload.tools) ? payload.tools : [];
                    if (!tools.length) {
                        mcpToolsDisplay.textContent = 'No tools reported by this MCP server.';
                        return;
                    }
                    mcpToolsDisplay.textContent = tools.map(function (tool) {
                        return '- ' + (tool.name || 'unknown') + (tool.description ? ': ' + tool.description : '');
                    }).join('\n');
                })
                .catch(function (err) {
                    mcpToolsDisplay.textContent = 'Error loading MCP tools.';
                });
        }

        function loadMcpIntoPanel(name) {
            fetch('api_mcps.php?action=get&name=' + encodeURIComponent(name))
                .then(function (res) { return res.json(); })
                .then(function (server) {
                    if (!window.currentOpenedMcp || window.currentOpenedMcp.originalName !== server.name) return;
                    infoEl.innerHTML = '<p class="mb-1"><strong>MCP Server:</strong> ' + escapeHtml(server.name) + '</p><p class="mb-1"><strong>Transport:</strong> ' + escapeHtml(server.transport || 'stdio') + '</p>';
                    openMcpConfigPanel(server);
                });
        }

        function loadJobIntoPanel(name) {
            fetch('api_jobs.php?action=get&name=' + encodeURIComponent(name))
                .then(function (res) { return res.json(); })
                .then(function (job) {
                    if (!window.currentOpenedJob || window.currentOpenedJob.name !== job.name) return;
                    if (jobContentInput) jobContentInput.value = job.content || '';
                    infoEl.innerHTML = '<p class="mb-1"><strong>Job:</strong> ' + escapeHtml(job.name) + '</p>';
                    if (jobStopBtn && typeof window.MemoryGraphIsJobRunning === 'function') {
                        jobStopBtn.disabled = !window.MemoryGraphIsJobRunning(job.name);
                    }
                });
        }

        function openWidget(label, id) {
            if (!widget || !labelEl || !infoEl) return;
            var refName = label || id || 'Node';
            window.currentOpenedNodeId = id;
            labelEl.textContent = refName;
            titleEl.textContent = 'Node';
            hideAllPanels();

            if (id === 'agent') {
                infoEl.innerHTML = '<p class="mb-1"><strong>Reference:</strong> Agent Settings</p>';
                if (agentConfig) agentConfig.style.display = 'block';
            } else if (id === 'tools') {
                var tools = window.toolsData || [];
                infoEl.innerHTML = '<p class="mb-1"><strong>Tools:</strong> ' + tools.length + ' available</p>';
                if (toolsParentPanel) {
                    toolsParentPanel.style.display = 'block';
                    renderToolsList();
                }
            } else if (id === 'mcps') {
                var servers = window.mcpServers || [];
                infoEl.innerHTML = '<p class="mb-1"><strong>MCP Servers:</strong> ' + servers.length + ' configured</p>';
                if (mcpsParentPanel) {
                    mcpsParentPanel.style.display = 'block';
                    renderMcpList();
                }
            } else if (id && id.indexOf('tool_') === 0) {
                var toolName = id.replace('tool_', '');
                var tool = (window.toolsData || []).find(function(t) { return t.name === toolName; });
                infoEl.innerHTML = '<p class="mb-1"><strong>Tool:</strong> ' + escapeHtml(toolName) + '</p>' + (tool && tool.description ? '<p class="mb-1 text-muted" style="font-size:0.85rem">' + escapeHtml(tool.description) + '</p>' : '');
                if (toolConfig) {
                    toolConfig.style.display = 'block';
                    window.currentOpenedTool = toolName;
                    if (toolSwitchEl) {
                        toolSwitchEl.checked = tool ? !!tool.active : false;
                        toolSwitchEl.disabled = tool ? !!tool.builtin : true;
                    }
                    if (toolCodeEl) toolCodeEl.textContent = tool && tool.code ? tool.code : '// No PHP script found in tools/';
                }
            } else if (id && id.indexOf('memory_file_') === 0) {
                var memory = (window.memoryFiles || []).find(function (m) { return m.nodeId === id; });
                var memoryName = memory ? memory.name : refName;
                infoEl.innerHTML = '<p class="mb-1"><strong>Memory:</strong> ' + escapeHtml(memoryName) + '</p>';
                if (memoryConfig) {
                    memoryConfig.style.display = 'block';
                    window.currentOpenedMemory = {
                        id: id,
                        name: memoryName
                    };
                    if (memorySwitchEl) memorySwitchEl.checked = memory ? !!memory.active : true;
                    if (memoryContentInput) memoryContentInput.value = '';
                    loadMemoryIntoPanel(memoryName);
                }
            } else if (id && id.indexOf('instruction_file_') === 0) {
                var instruction = (window.instructionFiles || []).find(function (i) { return i.nodeId === id; });
                var instructionName = instruction ? instruction.name : refName;
                infoEl.innerHTML = '<p class="mb-1"><strong>Instruction:</strong> ' + escapeHtml(instructionName) + '</p>';
                if (instructionConfig) {
                    instructionConfig.style.display = 'block';
                    window.currentOpenedInstruction = {
                        id: id,
                        name: instructionName
                    };
                    if (instructionContentInput) instructionContentInput.value = '';
                    loadInstructionIntoPanel(instructionName);
                }
            } else if (id && id.indexOf('mcp_server_') === 0) {
                var server = (window.mcpServers || []).find(function (item) { return item.nodeId === id; });
                var serverName = server ? server.name : refName;
                infoEl.innerHTML = '<p class="mb-1"><strong>MCP Server:</strong> ' + escapeHtml(serverName) + '</p>';
                if (mcpConfig) {
                    mcpConfig.style.display = 'block';
                    window.currentOpenedMcp = {
                        id: id,
                        name: serverName,
                        originalName: serverName
                    };
                    if (server) loadMcpIntoPanel(serverName);
                    else openMcpConfigPanel(null);
                }
            } else if (id && id.indexOf('job_file_') === 0) {
                var job = (window.jobFiles || []).find(function (j) { return j.nodeId === id; });
                var jobName = job ? job.name : refName;
                infoEl.innerHTML = '<p class="mb-1"><strong>Job:</strong> ' + escapeHtml(jobName) + '</p>';
                if (jobConfig) {
                    jobConfig.style.display = 'block';
                    window.currentOpenedJob = {
                        id: id,
                        name: jobName
                    };
                    if (jobContentInput) jobContentInput.value = '';
                    loadJobIntoPanel(jobName);
                }
            } else {
                infoEl.innerHTML = '<p class="mb-1">' + escapeHtml(refName) + '</p>';
            }
            widget.classList.add('is-open');
            widget.setAttribute('aria-hidden', 'false');
            renderExecutionWidget(id);
        }

        function closeWidget() {
            if (!widget) return;
            widget.classList.remove('is-open');
            widget.setAttribute('aria-hidden', 'true');
            window.currentOpenedNodeId = null;
            hideExecutionWidget();
        }

        document.addEventListener('graphNodeClick', function (e) {
            if (e.detail && e.detail.id != null) openWidget(e.detail.label, e.detail.id);
        });
        if (closeBtn) closeBtn.addEventListener('click', closeWidget);
        window.addEventListener('resize', function () {
            if (executionWidget && executionWidget.classList.contains('is-open')) {
                updateExecutionWidgetPosition();
            }
        });

        if (toolsEnableAllBtn) {
            toolsEnableAllBtn.addEventListener('click', function () {
                toggleAllTools(true);
            });
        }
        if (toolsDisableAllBtn) {
            toolsDisableAllBtn.addEventListener('click', function () {
                toggleAllTools(false);
            });
        }

        if (toolSwitchEl) {
            toolSwitchEl.addEventListener('change', function(e) {
                if(!window.currentOpenedTool) return;
                var isActive = e.target.checked;
                var tool = (window.toolsData || []).find(function(t) { return t.name === window.currentOpenedTool; });
                if (tool && tool.builtin) {
                    e.target.checked = !!tool.active;
                    return;
                }
                if (tool) tool.active = isActive;
                fetch('api_tools.php?action=toggle', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ name: window.currentOpenedTool, active: isActive })
                })
                .then(function (res) {
                    if (!res.ok) throw new Error('Failed to toggle tool');
                    return res.json();
                })
                .then(function () {
                    return refreshToolsData();
                })
                .then(function () {
                    var refreshedTool = (window.toolsData || []).find(function(t) { return t.name === window.currentOpenedTool; });
                    if (toolSwitchEl) {
                        toolSwitchEl.checked = refreshedTool ? !!refreshedTool.active : false;
                        toolSwitchEl.disabled = refreshedTool ? !!refreshedTool.builtin : true;
                    }
                    renderToolsList();
                    refreshGraph();
                })
                .catch(function () {
                    if (toolSwitchEl) toolSwitchEl.checked = !isActive;
                });
            });
        }

        if (memorySwitchEl) {
            memorySwitchEl.addEventListener('change', function (e) {
                if (!window.currentOpenedMemory) return;
                var isActive = e.target.checked;
                fetch('api_memory.php?action=toggle', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        name: window.currentOpenedMemory.name,
                        active: isActive
                    })
                })
                .then(function (res) {
                    if (!res.ok) throw new Error('Failed to toggle memory');
                    return res.json();
                })
                .then(function () {
                    return refreshMemoryData();
                })
                .then(function () {
                    loadMemoryIntoPanel(window.currentOpenedMemory.name);
                    refreshGraph();
                })
                .catch(function () {
                    memorySwitchEl.checked = !isActive;
                });
            });
        }

        if (memorySaveBtn) {
            memorySaveBtn.addEventListener('click', function () {
                if (!window.currentOpenedMemory || !memoryContentInput) return;
                memorySaveBtn.disabled = true;
                fetch('api_memory.php?action=save', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        name: window.currentOpenedMemory.name,
                        content: memoryContentInput.value
                    })
                }).then(function (res) {
                    return res.json();
                }).then(function (memory) {
                    if (window.memoryFiles) {
                        var found = false;
                        window.memoryFiles.forEach(function (item) {
                            if (item.name === memory.name) {
                                item.active = memory.active;
                                item.title = memory.title;
                                found = true;
                            }
                        });
                        if (!found) window.memoryFiles.push(memory);
                    }
                    infoEl.innerHTML = '<p class="mb-1"><strong>Memory:</strong> ' + escapeHtml(memory.name) + '</p><p class="mb-1">Saved.</p>';
                    refreshGraph();
                }).finally(function () {
                    memorySaveBtn.disabled = false;
                });
            });
        }
        if (instructionSaveBtn) {
            instructionSaveBtn.addEventListener('click', function () {
                if (!window.currentOpenedInstruction || !instructionContentInput) return;
                instructionSaveBtn.disabled = true;
                fetch('api_instructions.php?action=save', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        name: window.currentOpenedInstruction.name,
                        content: instructionContentInput.value
                    })
                }).then(function (res) { return res.json(); })
                .then(function (instruction) {
                    if (instruction && instruction.error) throw new Error(instruction.error);
                    if (window.instructionFiles) {
                        var found = false;
                        window.instructionFiles.forEach(function (item) {
                            if (item.name === instruction.name) {
                                item.title = instruction.title;
                                item.nodeId = instruction.nodeId;
                                found = true;
                            }
                        });
                        if (!found) window.instructionFiles.push(instruction);
                    }
                    infoEl.innerHTML = '<p class="mb-1"><strong>Instruction:</strong> ' + escapeHtml(instruction.name) + '</p><p class="mb-1">Saved.</p>';
                    refreshGraph();
                }).catch(function (err) {
                    infoEl.innerHTML = '<p class="mb-1"><strong>Instruction:</strong> ' + escapeHtml(window.currentOpenedInstruction.name) + '</p><p class="mb-1 text-danger">' + escapeHtml(err && err.message ? err.message : 'Save failed') + '</p>';
                }).finally(function () {
                    instructionSaveBtn.disabled = false;
                });
            });
        }
        if (mcpNewBtn) {
            mcpNewBtn.addEventListener('click', function () {
                infoEl.innerHTML = '<p class="mb-1"><strong>MCP Server:</strong> New MCP server</p>';
                openMcpConfigPanel(null);
            });
        }
        if (mcpsEnableAllBtn) {
            mcpsEnableAllBtn.addEventListener('click', function () {
                toggleAllMcps(true);
            });
        }
        if (mcpsDisableAllBtn) {
            mcpsDisableAllBtn.addEventListener('click', function () {
                toggleAllMcps(false);
            });
        }
        if (mcpActiveSwitchEl) {
            mcpActiveSwitchEl.addEventListener('change', function (e) {
                if (!window.currentOpenedMcp || !window.currentOpenedMcp.originalName) return;
                var isActive = e.target.checked;
                fetch('api_mcps.php?action=toggle', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        name: window.currentOpenedMcp.originalName,
                        active: isActive
                    })
                })
                .then(function (res) {
                    if (!res.ok) throw new Error('Failed to toggle MCP server');
                    return res.json();
                })
                .then(function () {
                    return refreshMcpData();
                })
                .then(function () {
                    renderMcpList();
                    if (window.currentOpenedMcp && window.currentOpenedMcp.originalName) {
                        loadMcpIntoPanel(window.currentOpenedMcp.originalName);
                    }
                    refreshGraph();
                })
                .catch(function () {
                    mcpActiveSwitchEl.checked = !isActive;
                });
            });
        }
        if (mcpSaveBtn) {
            mcpSaveBtn.addEventListener('click', function () {
                var name = mcpNameInput ? mcpNameInput.value.trim() : '';
                var args = safeParseJson(mcpArgsInput ? mcpArgsInput.value : '', []);
                var env = safeParseJson(mcpEnvInput ? mcpEnvInput.value : '', {});
                var headers = safeParseJson(mcpHeadersInput ? mcpHeadersInput.value : '', {});
                if (!name) {
                    infoEl.innerHTML = '<p class="mb-1"><strong>MCP Server:</strong> Name is required.</p>';
                    return;
                }
                if (args === null || !Array.isArray(args)) {
                    infoEl.innerHTML = '<p class="mb-1"><strong>MCP Server:</strong> Args must be a JSON array.</p>';
                    return;
                }
                if (env === null || Array.isArray(env) || typeof env !== 'object') {
                    infoEl.innerHTML = '<p class="mb-1"><strong>MCP Server:</strong> Env must be a JSON object.</p>';
                    return;
                }
                if (headers === null || Array.isArray(headers) || typeof headers !== 'object') {
                    infoEl.innerHTML = '<p class="mb-1"><strong>MCP Server:</strong> Headers must be a JSON object.</p>';
                    return;
                }
                mcpSaveBtn.disabled = true;
                fetch('api_mcps.php?action=save', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        originalName: window.currentOpenedMcp ? window.currentOpenedMcp.originalName : '',
                        name: name,
                        description: mcpDescriptionInput ? mcpDescriptionInput.value : '',
                        transport: mcpTransportInput ? (mcpTransportInput.value || 'stdio') : 'stdio',
                        command: mcpCommandInput ? mcpCommandInput.value : '',
                        args: args,
                        env: env,
                        cwd: mcpCwdInput ? mcpCwdInput.value : '',
                        url: mcpUrlInput ? mcpUrlInput.value : '',
                        headers: headers,
                        active: mcpActiveSwitchEl ? mcpActiveSwitchEl.checked : true
                    })
                }).then(function (res) {
                    return res.json();
                }).then(function (server) {
                    if (server.error) throw new Error(server.error);
                    return refreshMcpData().then(function () {
                        window.currentOpenedMcp = {
                            id: server.nodeId,
                            name: server.name,
                            originalName: server.name
                        };
                        infoEl.innerHTML = '<p class="mb-1"><strong>MCP Server:</strong> ' + escapeHtml(server.name) + '</p><p class="mb-1">Saved.</p>';
                        renderMcpList();
                        openWidget(server.title || server.name, server.nodeId);
                        refreshGraph();
                    });
                }).catch(function (err) {
                    infoEl.innerHTML = '<p class="mb-1"><strong>MCP Server:</strong> ' + escapeHtml(err && err.message ? err.message : 'Failed to save MCP server.') + '</p>';
                }).finally(function () {
                    mcpSaveBtn.disabled = false;
                });
            });
        }
        if (mcpRefreshToolsBtn) {
            mcpRefreshToolsBtn.addEventListener('click', function () {
                if (!window.currentOpenedMcp || !window.currentOpenedMcp.originalName) return;
                loadMcpTools(window.currentOpenedMcp.originalName);
            });
        }
        if (mcpDeleteBtn) {
            mcpDeleteBtn.addEventListener('click', function () {
                if (!window.currentOpenedMcp || !window.currentOpenedMcp.originalName) return;
                mcpDeleteBtn.disabled = true;
                fetch('api_mcps.php?action=delete', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ name: window.currentOpenedMcp.originalName })
                }).then(function (res) {
                    return res.json();
                }).then(function (payload) {
                    if (payload.error) throw new Error(payload.error);
                    return refreshMcpData().then(function () {
                        window.currentOpenedMcp = null;
                        infoEl.innerHTML = '<p class="mb-1"><strong>MCP Server:</strong> Deleted.</p>';
                        if (mcpConfig) mcpConfig.style.display = 'none';
                        renderMcpList();
                        refreshGraph();
                    });
                }).catch(function (err) {
                    infoEl.innerHTML = '<p class="mb-1"><strong>MCP Server:</strong> ' + escapeHtml(err && err.message ? err.message : 'Failed to delete MCP server.') + '</p>';
                }).finally(function () {
                    mcpDeleteBtn.disabled = false;
                });
            });
        }
        if (jobSaveBtn) {
            jobSaveBtn.addEventListener('click', function () {
                if (!window.currentOpenedJob || !jobContentInput) return;
                jobSaveBtn.disabled = true;
                fetch('api_jobs.php?action=save', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        name: window.currentOpenedJob.name,
                        content: jobContentInput.value
                    })
                }).then(function (res) {
                    return res.json();
                }).then(function (job) {
                    if (window.jobFiles) {
                        var found = false;
                        window.jobFiles.forEach(function (item) {
                            if (item.name === job.name) {
                                item.title = job.title;
                                found = true;
                            }
                        });
                        if (!found) window.jobFiles.push(job);
                    }
                    infoEl.innerHTML = '<p class="mb-1"><strong>Job:</strong> ' + escapeHtml(job.name) + '</p><p class="mb-1">Saved.</p>';
                    refreshGraph();
                }).finally(function () {
                    jobSaveBtn.disabled = false;
                });
            });
        }
        if (jobExecuteBtn) {
            jobExecuteBtn.addEventListener('click', function () {
                if (!window.currentOpenedJob || !jobContentInput || typeof window.MemoryGraphRunJob !== 'function') return;
                window.MemoryGraphRunJob(window.currentOpenedJob.name, jobContentInput.value, {
                    nodeId: window.currentOpenedJob.id
                });
                if (jobStopBtn) jobStopBtn.disabled = false;
            });
        }
        if (jobStopBtn) {
            jobStopBtn.addEventListener('click', function () {
                if (!window.currentOpenedJob || typeof window.MemoryGraphStopJobByName !== 'function') return;
                window.MemoryGraphStopJobByName(window.currentOpenedJob.name);
                jobStopBtn.disabled = true;
            });
        }
        window.MemoryGraphShowNodePanel = function (label, id) {
            openWidget(label, id);
        };
        window.MemoryGraphUpdateExecutionPanel = function () {
            renderExecutionWidget(window.currentOpenedNodeId);
        };
    })();
    </script>
</body>
</html>
