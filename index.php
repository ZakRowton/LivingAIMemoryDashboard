<?php
require_once __DIR__ . DIRECTORY_SEPARATOR . 'env.php';
memory_graph_load_env();
$mgCronBrowserTick = false;
$mgCronBt = memory_graph_env('MEMORYGRAPH_CRON_BROWSER_TICK', '');
if ($mgCronBt !== null && $mgCronBt !== '') {
    $mgCronBrowserTick = in_array(strtolower(trim($mgCronBt)), ['1', 'true', 'yes', 'on'], true);
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Open Jarvis Dashboard</title>
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
        var blockSES = ['cdn.tailwindcss.com', 'Removing intrinsics', 'lockdown-install', 'SES Removing', 'SES Removing unpermitted', 'getOrInsert', 'toTemporalInstant', 'intrinsics.%', 'unpermitted intrinsics', 'MapPrototype%', 'WeakMapPrototype%', 'DatePrototype%.toTemporalInstant'];
        if (typeof console !== 'undefined') {
            if (console.log) console.log = filter(console.log, blockSES);
            if (console.warn) console.warn = filter(console.warn, blockSES);
            if (console.error) console.error = filter(console.error, blockSES);
        }
    })();
    </script>
    <link href="vendor/bootstrap.min.css" rel="stylesheet">
    <link href="vendor/fonts.css" rel="stylesheet">
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
            background-image: radial-gradient(circle at top center, #11161d 0%, #040507 48%, #000000 100%);
            background-size: cover;
        }
        [data-theme="light"] body {
            background: #f5f0e6;
            background-image: radial-gradient(circle at top center, #ebe5d9 0%, #e8e0d2 100%);
        }

        /* —— Jarvis brand title (centered HUD) —— */
        .jarvis-brand-fixed {
            position: fixed;
            top: 14px;
            left: 0;
            width: 100%;
            z-index: 95;
            text-align: center;
            pointer-events: none;
        }
        .jarvis-brand {
            display: inline-flex;
            flex-direction: column;
            align-items: center;
            position: relative;
            padding: 12px 28px 14px;
            pointer-events: auto;
        }
        .jarvis-brand__halo {
            position: absolute;
            left: 50%;
            top: 50%;
            width: 140%;
            height: 200%;
            transform: translate(-50%, -50%);
            background: radial-gradient(ellipse at 50% 40%,
                rgba(212, 175, 55, 0.22) 0%,
                rgba(152, 192, 239, 0.12) 35%,
                transparent 70%);
            filter: blur(18px);
            opacity: 0.38;
            pointer-events: none;
            z-index: 0;
        }
        .jarvis-brand__title {
            position: relative;
            z-index: 1;
            margin: 0;
            padding: 0;
            border: none;
            max-width: min(640px, 94vw);
        }
        .jarvis-brand__title-main {
            display: block;
            font-family: 'Cinzel', serif;
            font-weight: 900;
            font-size: clamp(1.15rem, 3.2vw, 1.85rem);
            line-height: 1.15;
            letter-spacing: 0.12em;
            text-transform: none;
            opacity: 1;
            color: #eef4fa;
            -webkit-text-fill-color: #f2f6fb;
            text-shadow:
                0 0 10px rgba(212, 175, 55, 0.65),
                0 0 22px rgba(212, 175, 55, 0.35),
                0 0 36px rgba(152, 192, 239, 0.12),
                0 1px 0 rgba(0, 0, 0, 0.45);
            animation: jarvis-title-pulse 4.2s ease-in-out infinite;
        }
        .jarvis-brand__tagline {
            position: relative;
            z-index: 1;
            display: block;
            margin: 8px 0 0;
            padding: 0 6px;
            max-width: min(640px, 94vw);
            font-family: 'Playfair Display', serif;
            font-weight: 400;
            font-size: clamp(0.68rem, 1.65vw, 0.9rem);
            line-height: 1.4;
            letter-spacing: 0.04em;
            font-style: italic;
            color: rgba(138, 115, 38, 0.92);
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.55);
            opacity: 1;
        }
        @keyframes jarvis-title-pulse {
            0%, 100% {
                color: #e8eef4;
                -webkit-text-fill-color: #e8eef4;
                text-shadow:
                    0 0 8px rgba(212, 175, 55, 0.5),
                    0 0 20px rgba(212, 175, 55, 0.28),
                    0 0 32px rgba(152, 192, 239, 0.1),
                    0 1px 0 rgba(0, 0, 0, 0.45);
            }
            50% {
                color: #ffffff;
                -webkit-text-fill-color: #ffffff;
                text-shadow:
                    0 0 14px rgba(212, 175, 55, 0.85),
                    0 0 28px rgba(212, 175, 55, 0.45),
                    0 0 48px rgba(249, 241, 216, 0.2),
                    0 1px 0 rgba(0, 0, 0, 0.4);
            }
        }
        .jarvis-brand__rule {
            position: relative;
            z-index: 1;
            height: 2px;
            margin: 10px auto 0;
            width: 100%;
            max-width: min(520px, 88vw);
            border-radius: 2px;
            background: linear-gradient(90deg, transparent, rgba(212, 175, 55, 0.35), rgba(152, 192, 239, 0.45), rgba(212, 175, 55, 0.35), transparent);
            transform-origin: center center;
            overflow: hidden;
            transform: scaleX(0);
            opacity: 0;
        }
        .jarvis-brand__rule-scan {
            display: block;
            position: absolute;
            left: -40%;
            top: 0;
            width: 38%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(249, 241, 216, 0.95), transparent);
            animation: jarvis-scan 3.2s ease-in-out infinite;
        }
        @keyframes jarvis-scan {
            0% { transform: translateX(0); opacity: 0; }
            15% { opacity: 1; }
            85% { opacity: 1; }
            100% { transform: translateX(320%); opacity: 0; }
        }
        [data-theme="light"] .jarvis-brand__title-main {
            animation-name: jarvis-title-pulse-light;
            color: #3d1a20;
            -webkit-text-fill-color: #4a2329;
            text-shadow:
                0 0 10px rgba(184, 150, 46, 0.45),
                0 0 22px rgba(184, 150, 46, 0.2),
                0 1px 0 rgba(255, 255, 255, 0.35);
        }
        @keyframes jarvis-title-pulse-light {
            0%, 100% {
                color: #4a2329;
                -webkit-text-fill-color: #4a2329;
                text-shadow:
                    0 0 8px rgba(184, 150, 46, 0.35),
                    0 0 18px rgba(184, 150, 46, 0.15),
                    0 1px 0 rgba(255, 255, 255, 0.4);
            }
            50% {
                color: #5C2329;
                -webkit-text-fill-color: #5C2329;
                text-shadow:
                    0 0 14px rgba(184, 150, 46, 0.55),
                    0 0 26px rgba(184, 150, 46, 0.25),
                    0 1px 0 rgba(255, 255, 255, 0.5);
            }
        }
        [data-theme="light"] .jarvis-brand__tagline {
            color: rgba(58, 28, 34, 0.92);
            text-shadow: 0 1px 1px rgba(255, 255, 255, 0.35);
        }
        [data-theme="light"] .jarvis-brand__halo {
            background: radial-gradient(ellipse at 50% 40%,
                rgba(184, 150, 46, 0.2) 0%,
                rgba(114, 47, 55, 0.1) 45%,
                transparent 70%);
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
            top: 16px;
            left: 16px;
            z-index: 90;
            background: var(--panel-bg);
            backdrop-filter: blur(14px);
            border: 1px solid rgba(214, 219, 226, 0.22);
            border-radius: 10px;
            padding: 0;
            box-shadow: 0 0 14px rgba(214, 219, 226, 0.06), 0 6px 20px rgba(0,0,0,0.35);
            max-width: min(200px, calc(100vw - 32px));
        }
        .graph-legend-dropdown > summary {
            list-style: none;
            cursor: pointer;
            font-family: 'Cinzel', serif;
            font-size: 0.7rem;
            font-weight: 700;
            letter-spacing: 0.06em;
            color: var(--gold);
            padding: 6px 10px;
            user-select: none;
            text-shadow: 0 0 8px rgba(214, 219, 226, 0.12);
        }
        .graph-legend-dropdown > summary::-webkit-details-marker {
            display: none;
        }
        .graph-legend-dropdown > summary::after {
            content: ' \25BE';
            font-size: 0.65em;
            opacity: 0.75;
        }
        .graph-legend-dropdown[open] > summary::after {
            content: ' \25B4';
        }
        .graph-legend-dropdown > summary:hover {
            color: var(--gold-light);
        }
        .graph-legend-panel {
            border-top: 1px solid rgba(214, 219, 226, 0.12);
            padding: 4px 8px 8px;
            max-height: min(52vh, 280px);
            overflow-y: auto;
        }
        .graph-legend-list {
            list-style: none;
            margin: 0;
            padding: 0;
            font-size: 0.68rem;
            line-height: 1.25;
            color: var(--gold-light);
        }
        .graph-legend-list li {
            display: flex;
            align-items: center;
            gap: 5px;
            padding: 2px 0;
        }
        .graph-legend-swatch {
            display: inline-block;
            width: 7px;
            height: 7px;
            border-radius: 50%;
            flex-shrink: 0;
        }
        .graph-legend-categories-wrap {
            margin-top: 8px;
            padding-top: 8px;
            border-top: 1px solid rgba(214, 219, 226, 0.12);
        }
        .graph-legend-subtitle {
            font-size: 0.8rem;
            color: var(--gold-dim);
            margin-bottom: 4px;
        }
        /* Bottom dock: jobs (left) + chat (right) — avoids overlap */
        .bottom-dock {
            position: fixed;
            bottom: 14px;
            left: 14px;
            right: 14px;
            z-index: 110;
            display: grid;
            grid-template-columns: min(240px, min(34vw, calc(100vw - 100px))) 1fr;
            gap: 10px;
            align-items: end;
            pointer-events: none;
        }
        .bottom-dock > * {
            pointer-events: auto;
        }
        @media (max-width: 900px) {
            .bottom-dock {
                grid-template-columns: 1fr;
                gap: 12px;
                left: 12px;
                right: 12px;
                bottom: 16px;
            }
        }
        .chat-bar {
            position: relative;
            left: auto;
            bottom: auto;
            transform: none;
            justify-self: center;
            width: min(380px, 100%);
            max-width: 100%;
            background: var(--panel-bg);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(214, 219, 226, 0.18);
            border-radius: 10px;
            padding: 6px 9px;
            box-shadow: 0 6px 22px rgba(0,0,0,0.35);
        }
        @media (max-width: 900px) {
            .chat-bar {
                justify-self: stretch;
                width: 100%;
                order: 2;
            }
        }
        .chat-bar .input-wrap {
            display: flex;
            gap: 6px;
            align-items: center;
        }
        .chat-bar input {
            flex: 1;
            min-width: 0;
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(214, 219, 226, 0.16);
            border-radius: 7px;
            padding: 7px 10px;
            color: var(--gold-light);
            font-family: 'Playfair Display', serif;
            font-size: 0.8125rem;
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
            border-radius: 7px;
            padding: 7px 12px;
            font-family: 'Cinzel', serif;
            font-size: 0.68rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            cursor: pointer;
            flex-shrink: 0;
        }
        .chat-bar .btn-send:hover { filter: brightness(1.1); }
        .chat-bar .btn-send:disabled { opacity: 0.6; cursor: not-allowed; }
        .chat-queue-wrap {
            margin-bottom: 10px;
            padding-bottom: 10px;
            border-bottom: 1px solid rgba(214, 219, 226, 0.12);
        }
        .chat-queue-header {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            font-size: 0.9rem;
            color: var(--gold);
            font-family: 'Cinzel', serif;
        }
        .chat-queue-toggle {
            font-size: 0.75rem;
            transition: transform 0.2s;
        }
        .chat-queue-wrap.collapsed .chat-queue-toggle { transform: rotate(-90deg); }
        .chat-queue-wrap.collapsed .chat-queue-list { display: none; }
        .chat-queue-list {
            margin-top: 8px;
            max-height: 140px;
            overflow-y: auto;
        }
        .chat-queue-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 10px;
            margin-bottom: 4px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(214, 219, 226, 0.12);
            border-radius: 8px;
            font-size: 0.9rem;
        }
        .chat-queue-item-text {
            flex: 1;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .chat-queue-item-actions {
            display: flex;
            gap: 4px;
        }
        .chat-queue-item-actions button {
            background: transparent;
            border: none;
            color: var(--gold-dim);
            cursor: pointer;
            padding: 2px 6px;
            font-size: 0.85rem;
        }
        .chat-queue-item-actions button:hover { color: var(--gold); }
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
        /* Above bottom-dock (110) / notifications (105); backdrop stays Bootstrap default (~1050) so it stays BELOW this modal */
        #response-modal.modal {
            z-index: 10000;
        }
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
        /* Markdown-rendered AI response (modal + cron results) */
        .response-modal-md {
            font-family: 'Playfair Display', Georgia, serif;
            line-height: 1.75;
            color: var(--gold-light, #F9F1D8);
            margin-bottom: 18px;
            font-size: 1rem;
        }
        .response-modal-md h1, .response-modal-md h2, .response-modal-md h3, .response-modal-md h4 {
            font-family: 'Cinzel', serif;
            color: var(--gold, #D4AF37);
            margin: 1.15em 0 0.45em;
            font-weight: 700;
            line-height: 1.25;
        }
        .response-modal-md h1 { font-size: 1.35rem; }
        .response-modal-md h2 { font-size: 1.2rem; }
        .response-modal-md h3 { font-size: 1.08rem; }
        .response-modal-md p { margin: 0.65em 0; }
        .response-modal-md ul, .response-modal-md ol { margin: 0.65em 0; padding-left: 1.35em; }
        .response-modal-md li { margin: 0.35em 0; }
        .response-modal-md hr {
            border: none;
            border-top: 1px solid rgba(212, 175, 55, 0.22);
            margin: 1.25em 0;
        }
        .response-modal-md table {
            width: 100%;
            border-collapse: collapse;
            margin: 16px 0;
            font-size: 0.9rem;
            border: 1px solid rgba(212, 175, 55, 0.25);
            border-radius: 10px;
            overflow: hidden;
        }
        .response-modal-md th, .response-modal-md td {
            padding: 10px 12px;
            border-bottom: 1px solid rgba(212, 175, 55, 0.12);
            text-align: left;
            vertical-align: top;
        }
        .response-modal-md th {
            background: rgba(212, 175, 55, 0.1);
            color: var(--gold, #D4AF37);
            font-family: 'Cinzel', serif;
            font-weight: 600;
        }
        .response-modal-md tbody tr:nth-child(even) td { background: rgba(255,255,255,0.02); }
        .response-modal-md img {
            max-width: 100%;
            height: auto;
            border-radius: 10px;
            border: 1px solid rgba(212, 175, 55, 0.22);
            margin: 14px 0;
            display: block;
        }
        .response-modal-md code {
            font-family: "Courier New", monospace;
            background: rgba(0,0,0,0.45);
            padding: 0.12em 0.4em;
            border-radius: 4px;
            font-size: 0.88em;
        }
        .response-modal-md pre {
            background: rgba(0,0,0,0.5);
            border: 1px solid rgba(212, 175, 55, 0.14);
            border-radius: 10px;
            padding: 14px;
            overflow: auto;
            margin: 14px 0;
        }
        .response-modal-md pre code { background: transparent; padding: 0; }
        .response-modal-md blockquote {
            border-left: 3px solid var(--gold, #D4AF37);
            margin: 12px 0;
            padding: 6px 16px;
            color: var(--gold-light, #F9F1D8);
            opacity: 0.95;
        }
        .response-modal-md a { color: #98C0EF; text-decoration: underline; }
        .response-modal-md a:hover { color: #b8d4f5; }
        [data-theme="light"] .response-modal-md { color: #5C2329; }
        [data-theme="light"] .response-modal-md th { color: #6b5a2a; }
        [data-theme="light"] .response-modal-md blockquote { color: #4a4238; }
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
        .font-serif { font-family: 'Playfair Display', Georgia, serif; }
        /* Node widget – glowing panel */
        .node-widget {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 90;
            width: min(400px, calc(100vw - 40px));
            max-height: calc(100vh - 40px);
            display: flex;
            flex-direction: column;
            overflow: hidden;
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
            flex-shrink: 0;
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
        .node-widget-body {
            padding: 14px;
            color: var(--gold-light);
            font-size: 0.95rem;
            line-height: 1.5;
            flex: 1;
            min-height: 0;
            overflow-y: auto;
            overflow-x: hidden;
            -webkit-overflow-scrolling: touch;
        }
        .node-widget-label {
            color: var(--gold);
            font-weight: 600;
            text-shadow: 0 0 12px rgba(214, 219, 226, 0.14);
        }
        .node-widget-info { margin-top: 8px; }
        .sub-agent-chat-panel .sub-agent-run-hint {
            font-size: 0.8rem;
            color: var(--gold-dim);
            margin: 0 0 10px;
            line-height: 1.45;
        }
        .sub-agent-run-response {
            margin-top: 12px;
            padding: 10px 12px;
            border-radius: 8px;
            border: 1px solid rgba(214, 219, 226, 0.14);
            background: rgba(0, 0, 0, 0.35);
            font-size: 0.82rem;
            line-height: 1.45;
            color: #dce3ea;
            max-height: 240px;
            overflow-y: auto;
            white-space: pre-wrap;
            word-break: break-word;
            font-family: 'Playfair Display', Georgia, serif;
        }
        .sub-agent-run-response.is-error {
            border-color: rgba(248, 113, 113, 0.35);
            color: #fecaca;
        }
        .sub-agent-run-response:empty { display: none; }
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
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 10px;
        }
        .panel-action-btn-row .panel-action-btn {
            margin-top: 0;
            width: auto;
            flex: 1 1 80px;
            min-width: 80px;
        }
        .btn-stop {
            background: linear-gradient(180deg, #7a1515, #b91c1c);
            color: #f9f1d8;
        }
        .job-config-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 10px;
        }
        .job-config-actions .panel-action-btn {
            margin-top: 0;
            width: auto;
            flex: 1 1 80px;
            min-width: 80px;
        }
        .running-jobs-widget {
            position: relative;
            left: auto;
            bottom: auto;
            z-index: 1;
            width: 100%;
            max-width: 240px;
            min-width: 0;
            overflow-x: hidden;
            background: var(--panel-bg);
            backdrop-filter: blur(16px);
            border: 1px solid rgba(214, 219, 226, 0.24);
            border-radius: 11px;
            padding: 9px 10px;
            box-shadow: 0 8px 26px rgba(0, 0, 0, 0.42), 0 0 16px rgba(214, 219, 226, 0.06);
        }
        @media (max-width: 900px) {
            .running-jobs-widget {
                max-width: none;
                width: 100%;
                order: 1;
            }
        }
        .running-jobs-title {
            color: var(--gold);
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.06em;
            margin-bottom: 6px;
            text-shadow: 0 0 8px rgba(214, 219, 226, 0.14);
        }
        .running-jobs-list {
            display: flex;
            flex-direction: column;
            gap: 6px;
            max-height: 160px;
            min-width: 0;
            overflow-x: hidden;
            overflow-y: auto;
            scrollbar-width: thin;
            scrollbar-color: rgba(212, 175, 55, 0.45) rgba(0, 0, 0, 0.25);
        }
        .running-jobs-list::-webkit-scrollbar {
            width: 6px;
        }
        .running-jobs-list::-webkit-scrollbar-thumb {
            background: rgba(212, 175, 55, 0.5);
            border-radius: 4px;
        }
        .running-job-item {
            border: 1px solid rgba(214, 219, 226, 0.14);
            border-radius: 8px;
            padding: 8px 9px;
            background: rgba(255,255,255,0.03);
            min-width: 0;
            max-width: 100%;
        }
        .running-job-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 6px;
            margin-bottom: 5px;
            min-width: 0;
        }
        .running-job-name {
            color: var(--gold-light);
            font-size: 0.78rem;
            line-height: 1.25;
            min-width: 0;
            flex: 1 1 auto;
            overflow-wrap: anywhere;
            word-break: break-word;
        }
        .running-job-spinner {
            width: 13px;
            height: 13px;
            border: 2px solid rgba(214, 219, 226, 0.25);
            border-top-color: var(--gold);
            border-radius: 50%;
            animation: running-job-spin 0.8s linear infinite;
            flex-shrink: 0;
        }
        .running-job-status {
            font-size: 0.7rem;
            color: var(--gold-dim);
            margin-bottom: 5px;
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
            border-radius: 6px;
            padding: 5px 8px;
            background: rgba(255,255,255,0.05);
            color: var(--gold-light);
            font-family: 'Cinzel', serif;
            font-size: 0.65rem;
        }
        .running-job-empty {
            color: var(--gold-dim);
            font-size: 0.74rem;
            line-height: 1.35;
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

        /* Settings FAB + panel */
        .settings-fab {
            position: fixed;
            right: max(14px, env(safe-area-inset-right, 0px) + 8px);
            bottom: max(16px, env(safe-area-inset-bottom, 0px) + 10px);
            z-index: 125;
            width: 48px;
            height: 48px;
            border-radius: 50%;
            border: 1px solid rgba(214, 219, 226, 0.35);
            background: var(--panel-bg);
            backdrop-filter: blur(12px);
            color: var(--gold);
            font-size: 1.35rem;
            line-height: 1;
            cursor: pointer;
            box-shadow: 0 4px 22px rgba(0,0,0,0.4), 0 0 18px rgba(214, 219, 226, 0.08);
            transition: transform 0.2s ease, border-color 0.2s ease, color 0.2s ease;
        }
        .settings-fab:hover {
            transform: scale(1.06);
            border-color: rgba(214, 219, 226, 0.55);
            color: var(--gold-light);
        }
        /* Web apps FAB + left drawer + fullscreen viewer */
        .apps-fab {
            position: fixed;
            right: max(78px, env(safe-area-inset-right, 0px) + 70px);
            bottom: max(16px, env(safe-area-inset-bottom, 0px) + 10px);
            z-index: 125;
            min-width: 52px;
            height: 48px;
            padding: 0 12px;
            border-radius: 24px;
            border: 1px solid rgba(212, 175, 55, 0.38);
            background: var(--panel-bg);
            backdrop-filter: blur(12px);
            color: #d4af37;
            font-size: 0.72rem;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            cursor: pointer;
            box-shadow: 0 4px 22px rgba(0,0,0,0.4), 0 0 18px rgba(212, 175, 55, 0.12);
            transition: transform 0.2s ease, border-color 0.2s ease, color 0.2s ease;
        }
        .apps-fab:hover {
            transform: scale(1.05);
            border-color: rgba(212, 175, 55, 0.55);
            color: #f9f1d8;
        }
        @media (max-width: 900px) {
            .settings-fab {
                right: max(12px, env(safe-area-inset-right, 0px) + 8px);
                bottom: max(16px, env(safe-area-inset-bottom, 0px) + 10px);
            }
            .apps-fab {
                right: max(72px, env(safe-area-inset-right, 0px) + 64px);
                bottom: max(16px, env(safe-area-inset-bottom, 0px) + 10px);
            }
        }
        .apps-drawer-backdrop {
            position: fixed;
            inset: 0;
            z-index: 116;
            background: rgba(0, 0, 0, 0.45);
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.25s ease, visibility 0.25s ease;
        }
        .apps-drawer-backdrop.is-open {
            opacity: 1;
            visibility: visible;
        }
        .apps-drawer {
            position: fixed;
            top: 0;
            left: 0;
            width: min(320px, 92vw);
            height: 100%;
            z-index: 118;
            background: var(--panel-bg);
            backdrop-filter: blur(16px);
            border-right: 1px solid rgba(212, 175, 55, 0.18);
            box-shadow: 8px 0 40px rgba(0, 0, 0, 0.45);
            transform: translateX(-100%);
            transition: transform 0.28s cubic-bezier(0.2, 0.8, 0.2, 1);
            display: flex;
            flex-direction: column;
        }
        .apps-drawer.is-open {
            transform: translateX(0);
        }
        .apps-drawer-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 16px;
            border-bottom: 1px solid rgba(212, 175, 55, 0.15);
            font-size: 1.05rem;
            color: #d4af37;
        }
        .apps-drawer-close {
            background: none;
            border: none;
            color: var(--gold-dim);
            font-size: 1.5rem;
            line-height: 1;
            cursor: pointer;
            padding: 4px 8px;
        }
        .apps-drawer-body {
            padding: 12px 14px 18px;
            overflow-y: auto;
            flex: 1;
            min-height: 0;
        }
        .apps-drawer-hint {
            font-size: 0.78rem;
            color: var(--gold-dim);
            margin: 0 0 12px;
            line-height: 1.45;
        }
        .apps-drawer-hint code {
            font-family: "Courier New", monospace;
            font-size: 0.72rem;
        }
        .apps-drawer-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-bottom: 14px;
        }
        .apps-drawer-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            padding: 10px 12px;
            border-radius: 10px;
            border: 1px solid rgba(212, 175, 55, 0.15);
            background: rgba(0, 0, 0, 0.2);
        }
        [data-theme="light"] .apps-drawer-row {
            background: rgba(255, 255, 255, 0.35);
        }
        .apps-drawer-row-title {
            font-size: 0.88rem;
            color: var(--gold-light);
            flex: 1;
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .apps-drawer-open-btn {
            flex-shrink: 0;
            padding: 6px 12px;
            border-radius: 8px;
            border: 1px solid rgba(212, 175, 55, 0.4);
            background: rgba(212, 175, 55, 0.12);
            color: #f9f1d8;
            font-family: 'Cinzel', serif;
            font-size: 0.65rem;
            letter-spacing: 0.06em;
            cursor: pointer;
        }
        .apps-drawer-open-btn:hover {
            background: rgba(212, 175, 55, 0.22);
        }
        .apps-drawer-empty {
            font-size: 0.85rem;
            color: var(--gold-dim);
            padding: 8px 0;
        }
        /* Fullscreen app viewer above chat/dock; do NOT raise all .modal-backdrop globally — that traps #response-modal behind the dimmer */
        #web-app-modal.modal {
            z-index: 10050;
        }
        body.mg-web-app-modal-open .modal-backdrop {
            z-index: 10040;
        }
        .web-app-modal-content {
            background: #0a0a0a;
            border: none;
            border-radius: 0;
            height: 100vh;
            max-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        .web-app-modal-header {
            flex-shrink: 0;
            border-bottom: 1px solid rgba(212, 175, 55, 0.2);
            background: rgba(10, 10, 10, 0.95);
        }
        .web-app-modal-header .modal-title {
            color: #d4af37;
            font-size: 1rem;
        }
        .web-app-modal-header .btn-close {
            filter: invert(0.85) sepia(0.3);
        }
        .web-app-modal-body {
            flex: 1;
            min-height: 0;
            background: #000;
        }
        .web-app-modal-frame {
            width: 100%;
            height: 100%;
            min-height: 320px;
            border: 0;
            display: block;
        }
        .settings-backdrop {
            position: fixed;
            inset: 0;
            z-index: 117;
            background: rgba(0, 0, 0, 0.45);
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.25s ease, visibility 0.25s ease;
        }
        .settings-backdrop.is-open {
            opacity: 1;
            visibility: visible;
        }
        .settings-panel {
            position: fixed;
            top: 0;
            right: 0;
            width: min(380px, 100vw);
            height: 100%;
            z-index: 118;
            background: linear-gradient(165deg, rgba(14, 17, 22, 0.94) 0%, rgba(8, 10, 14, 0.92) 45%, rgba(6, 8, 12, 0.96) 100%);
            backdrop-filter: blur(18px);
            border-left: 1px solid rgba(212, 175, 55, 0.22);
            box-shadow:
                -12px 0 48px rgba(0, 0, 0, 0.55),
                inset 0 1px 0 rgba(214, 219, 226, 0.06);
            transform: translateX(100%);
            transition: transform 0.28s cubic-bezier(0.2, 0.8, 0.2, 1);
            display: flex;
            flex-direction: column;
        }
        [data-theme="light"] .settings-panel {
            background: linear-gradient(165deg, rgba(252, 250, 246, 0.98) 0%, rgba(245, 241, 234, 0.96) 100%);
            border-left-color: rgba(180, 150, 70, 0.28);
            box-shadow: -8px 0 36px rgba(0, 0, 0, 0.12);
        }
        .settings-panel.is-open {
            transform: translateX(0);
        }
        .settings-panel-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 20px 22px 18px;
            border-bottom: 1px solid rgba(212, 175, 55, 0.18);
            flex-shrink: 0;
        }
        .settings-panel-title {
            font-size: 0.72rem;
            letter-spacing: 0.2em;
            text-transform: uppercase;
            color: #d4af37;
            text-shadow: 0 0 20px rgba(212, 175, 55, 0.25);
        }
        [data-theme="light"] .settings-panel-title {
            color: #8a7228;
            text-shadow: none;
        }
        .settings-panel-close {
            background: rgba(0, 0, 0, 0.2);
            border: 1px solid rgba(212, 175, 55, 0.2);
            border-radius: 10px;
            color: var(--gold-dim);
            font-size: 1.25rem;
            line-height: 1;
            cursor: pointer;
            padding: 6px 12px;
            transition: color 0.2s ease, border-color 0.2s ease, background 0.2s ease;
        }
        [data-theme="light"] .settings-panel-close {
            background: rgba(255, 255, 255, 0.5);
        }
        .settings-panel-close:hover {
            color: var(--gold-light);
            border-color: rgba(212, 175, 55, 0.4);
            background: rgba(212, 175, 55, 0.08);
        }
        .settings-panel-body {
            padding: 22px 22px 20px;
            overflow-y: auto;
            overflow-x: hidden;
            flex: 1;
            min-width: 0;
            min-height: 0;
            box-sizing: border-box;
            display: flex;
            flex-direction: column;
        }
        .settings-section {
            flex-shrink: 0;
        }
        .settings-section-kicker {
            font-family: 'Cinzel', serif;
            font-size: 0.62rem;
            letter-spacing: 0.16em;
            text-transform: uppercase;
            color: var(--gold-dim);
            margin-bottom: 6px;
        }
        .settings-section-title {
            margin: 0 0 10px;
            font-size: 1.15rem;
            font-weight: 600;
            color: var(--gold-light);
            letter-spacing: 0.04em;
        }
        .settings-section-lead {
            margin: 0 0 18px;
            font-size: 0.84rem;
            line-height: 1.55;
            color: var(--gold-dim);
            max-width: 32em;
        }
        .settings-option-card {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            padding: 16px 18px;
            border-radius: 14px;
            border: 1px solid rgba(212, 175, 55, 0.2);
            background: rgba(0, 0, 0, 0.28);
            box-shadow: inset 0 1px 0 rgba(214, 219, 226, 0.05);
        }
        [data-theme="light"] .settings-option-card {
            background: rgba(255, 255, 255, 0.55);
            border-color: rgba(180, 150, 70, 0.22);
        }
        .settings-option-text {
            min-width: 0;
            flex: 1;
        }
        .settings-option-name {
            font-size: 0.78rem;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: #e8dcc8;
            margin-bottom: 4px;
        }
        [data-theme="light"] .settings-option-name {
            color: var(--gold-light);
        }
        .settings-option-desc {
            font-size: 0.8rem;
            line-height: 1.45;
            color: var(--gold-dim);
        }
        /* Gold-themed switch (no Bootstrap blue) */
        .settings-toggle {
            position: relative;
            display: inline-flex;
            align-items: center;
            flex-shrink: 0;
            cursor: pointer;
            margin: 0;
        }
        .settings-toggle-input {
            position: absolute;
            opacity: 0;
            width: 1px;
            height: 1px;
            margin: 0;
            pointer-events: none;
        }
        .settings-toggle-ui {
            position: relative;
            width: 52px;
            height: 30px;
            border-radius: 999px;
            background: rgba(0, 0, 0, 0.45);
            border: 1px solid rgba(212, 175, 55, 0.35);
            box-shadow: inset 0 2px 6px rgba(0, 0, 0, 0.35);
            transition: background 0.22s ease, border-color 0.22s ease, box-shadow 0.22s ease;
        }
        [data-theme="light"] .settings-toggle-ui {
            background: rgba(255, 255, 255, 0.65);
        }
        .settings-toggle-thumb {
            position: absolute;
            top: 3px;
            left: 4px;
            width: 22px;
            height: 22px;
            border-radius: 50%;
            background: linear-gradient(145deg, #f5f0e4 0%, #c9b896 45%, #a08c5c 100%);
            box-shadow:
                0 2px 6px rgba(0, 0, 0, 0.45),
                inset 0 1px 0 rgba(255, 255, 255, 0.35);
            transition: transform 0.22s cubic-bezier(0.2, 0.85, 0.2, 1);
        }
        .settings-toggle-input:checked + .settings-toggle-ui {
            background: rgba(212, 175, 55, 0.18);
            border-color: rgba(212, 175, 55, 0.55);
            box-shadow:
                inset 0 2px 8px rgba(0, 0, 0, 0.25),
                0 0 20px rgba(212, 175, 55, 0.12);
        }
        .settings-toggle-input:checked + .settings-toggle-ui .settings-toggle-thumb {
            transform: translateX(22px);
        }
        .settings-toggle-input:focus-visible + .settings-toggle-ui {
            outline: 2px solid rgba(212, 175, 55, 0.65);
            outline-offset: 3px;
        }
        .settings-panel-spacer {
            flex: 1;
            min-height: 32px;
        }
        .settings-panel-footer {
            flex-shrink: 0;
            margin-top: auto;
            padding-top: 18px;
            border-top: 1px solid rgba(212, 175, 55, 0.12);
        }
        .settings-footer-note {
            margin: 0;
            font-size: 0.76rem;
            line-height: 1.5;
            color: var(--gold-dim);
            opacity: 0.92;
        }
        .settings-footer-note strong {
            color: var(--gold-light);
            font-weight: 600;
        }
        .settings-provider-api-keys {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .settings-api-key-row {
            padding: 12px 14px;
            border-radius: 12px;
            border: 1px solid rgba(212, 175, 55, 0.18);
            background: rgba(0, 0, 0, 0.22);
        }
        [data-theme="light"] .settings-api-key-row {
            background: rgba(255, 255, 255, 0.45);
            border-color: rgba(180, 150, 70, 0.2);
        }
        .settings-api-key-row-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 8px;
        }
        .settings-api-key-label {
            font-size: 0.72rem;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: var(--gold-dim);
        }
        .settings-api-key-badge {
            font-size: 0.65rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            padding: 2px 8px;
            border-radius: 6px;
            border: 1px solid rgba(212, 175, 55, 0.25);
            color: var(--gold-dim);
        }
        .settings-api-key-badge.is-override {
            color: #e8dcc8;
            border-color: rgba(212, 175, 55, 0.45);
        }
        .settings-api-key-badge.is-env {
            color: var(--gold-dim);
        }
        .settings-api-key-badge.is-none {
            opacity: 0.75;
        }
        .settings-api-key-actions {
            display: flex;
            gap: 8px;
            margin-top: 8px;
            flex-wrap: wrap;
            align-items: center;
        }
        .settings-api-key-actions .settings-api-key-input {
            flex: 1;
            min-width: 0;
            font-size: 0.82rem;
            padding: 6px 10px;
            border-radius: 8px;
            border: 1px solid rgba(212, 175, 55, 0.22);
            background: rgba(0, 0, 0, 0.35);
            color: #e8e4dc;
        }
        [data-theme="light"] .settings-api-key-actions .settings-api-key-input {
            background: rgba(255, 255, 255, 0.8);
            color: #222;
        }
        .settings-api-key-actions .settings-api-key-btn {
            font-size: 0.72rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            padding: 6px 12px;
            border-radius: 8px;
            border: 1px solid rgba(212, 175, 55, 0.35);
            background: rgba(212, 175, 55, 0.12);
            color: var(--gold-light);
            cursor: pointer;
        }
        .settings-api-key-actions .settings-api-key-btn:hover {
            background: rgba(212, 175, 55, 0.2);
        }
        .settings-api-key-msg {
            font-size: 0.72rem;
            margin-top: 6px;
            color: var(--gold-dim);
        }
        .settings-api-key-msg.is-error {
            color: #e8a0a0;
        }

        /* Simple chat layout (replaces visible 3D graph) */
        html.mg-simple-ui #graph-container {
            display: none !important;
        }
        html.mg-simple-ui .graph-legend {
            display: none !important;
        }

        /* Simple mode: slimmer hero, less visual competition with the chat workspace */
        html.mg-simple-ui .jarvis-brand-fixed {
            top: 6px;
        }
        html.mg-simple-ui .jarvis-brand {
            flex-direction: row;
            flex-wrap: wrap;
            align-items: center;
            justify-content: center;
            gap: 6px 18px;
            padding: 8px 20px 10px;
        }
        html.mg-simple-ui .jarvis-brand__halo {
            opacity: 0.22;
            filter: blur(22px);
        }
        html.mg-simple-ui .jarvis-brand__rule {
            display: none;
        }
        html.mg-simple-ui .jarvis-brand__title {
            margin: 0;
        }
        html.mg-simple-ui .jarvis-brand__title-main {
            font-size: clamp(0.88rem, 2.1vw, 1.2rem);
            letter-spacing: 0.1em;
            animation: none;
        }
        html.mg-simple-ui .jarvis-brand__tagline {
            margin: 0;
            padding: 0 0 0 16px;
            max-width: min(380px, 42vw);
            font-size: clamp(0.62rem, 1.35vw, 0.78rem);
            border-left: 1px solid rgba(212, 175, 55, 0.22);
        }
        @media (max-width: 640px) {
            html.mg-simple-ui .jarvis-brand__tagline {
                border-left: none;
                padding-left: 0;
                max-width: 90vw;
            }
        }
        html[data-theme="light"].mg-simple-ui .jarvis-brand__tagline {
            border-left-color: rgba(184, 150, 46, 0.32);
        }

        .simple-ui-root {
            position: fixed;
            inset: 0;
            z-index: 45;
            display: none;
            flex-direction: row;
            align-items: stretch;
            gap: 0;
            padding-top: 52px;
            padding-bottom: 100px;
            box-sizing: border-box;
            background: var(--black);
            background-image: radial-gradient(circle at top center, #1a1510 0%, #040507 52%, #000000 100%);
        }
        html.mg-simple-ui .simple-ui-root {
            display: flex !important;
        }
        html.mg-simple-ui:not([data-theme="light"]) .simple-ui-root {
            color-scheme: dark;
        }
        html.mg-simple-ui[data-theme="light"] .simple-ui-root {
            color-scheme: light;
        }
        [data-theme="light"] .simple-ui-root {
            background: #f5f0e6;
            background-image: radial-gradient(circle at top center, #ebe5d9 0%, #e8e0d2 100%);
        }

        /* Simple UI: custom scrollbars (WebKit + Firefox) — gold thumb, dark track */
        html.mg-simple-ui .simple-chat-thread-wrap,
        html.mg-simple-ui .simple-side-nav,
        html.mg-simple-ui .simple-activity-log,
        html.mg-simple-ui .simple-list-col,
        html.mg-simple-ui .simple-detail-col,
        html.mg-simple-ui .simple-detail-pre,
        html.mg-simple-ui .simple-activity-log-mobile {
            scrollbar-width: thin;
            scrollbar-color: rgba(212, 175, 55, 0.55) rgba(3, 3, 3, 0.45);
        }
        html.mg-simple-ui[data-theme="light"] .simple-chat-thread-wrap,
        html.mg-simple-ui[data-theme="light"] .simple-side-nav,
        html.mg-simple-ui[data-theme="light"] .simple-activity-log,
        html.mg-simple-ui[data-theme="light"] .simple-list-col,
        html.mg-simple-ui[data-theme="light"] .simple-detail-col,
        html.mg-simple-ui[data-theme="light"] .simple-detail-pre,
        html.mg-simple-ui[data-theme="light"] .simple-activity-log-mobile {
            scrollbar-color: rgba(184, 150, 46, 0.75) rgba(240, 235, 224, 0.85);
        }
        html.mg-simple-ui .simple-chat-thread-wrap::-webkit-scrollbar,
        html.mg-simple-ui .simple-side-nav::-webkit-scrollbar,
        html.mg-simple-ui .simple-activity-log::-webkit-scrollbar,
        html.mg-simple-ui .simple-list-col::-webkit-scrollbar,
        html.mg-simple-ui .simple-detail-col::-webkit-scrollbar,
        html.mg-simple-ui .simple-detail-pre::-webkit-scrollbar,
        html.mg-simple-ui .simple-activity-log-mobile::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        html.mg-simple-ui .simple-chat-thread-wrap::-webkit-scrollbar-track,
        html.mg-simple-ui .simple-side-nav::-webkit-scrollbar-track,
        html.mg-simple-ui .simple-activity-log::-webkit-scrollbar-track,
        html.mg-simple-ui .simple-list-col::-webkit-scrollbar-track,
        html.mg-simple-ui .simple-detail-col::-webkit-scrollbar-track,
        html.mg-simple-ui .simple-detail-pre::-webkit-scrollbar-track,
        html.mg-simple-ui .simple-activity-log-mobile::-webkit-scrollbar-track {
            background: rgba(0, 0, 0, 0.35);
            border-radius: 8px;
            margin: 4px 0;
        }
        html.mg-simple-ui[data-theme="light"] .simple-chat-thread-wrap::-webkit-scrollbar-track,
        html.mg-simple-ui[data-theme="light"] .simple-side-nav::-webkit-scrollbar-track,
        html.mg-simple-ui[data-theme="light"] .simple-activity-log::-webkit-scrollbar-track,
        html.mg-simple-ui[data-theme="light"] .simple-list-col::-webkit-scrollbar-track,
        html.mg-simple-ui[data-theme="light"] .simple-detail-col::-webkit-scrollbar-track,
        html.mg-simple-ui[data-theme="light"] .simple-detail-pre::-webkit-scrollbar-track,
        html.mg-simple-ui[data-theme="light"] .simple-activity-log-mobile::-webkit-scrollbar-track {
            background: rgba(232, 224, 210, 0.9);
        }
        html.mg-simple-ui .simple-chat-thread-wrap::-webkit-scrollbar-thumb,
        html.mg-simple-ui .simple-side-nav::-webkit-scrollbar-thumb,
        html.mg-simple-ui .simple-activity-log::-webkit-scrollbar-thumb,
        html.mg-simple-ui .simple-list-col::-webkit-scrollbar-thumb,
        html.mg-simple-ui .simple-detail-col::-webkit-scrollbar-thumb,
        html.mg-simple-ui .simple-detail-pre::-webkit-scrollbar-thumb,
        html.mg-simple-ui .simple-activity-log-mobile::-webkit-scrollbar-thumb {
            background: linear-gradient(180deg, rgba(212, 175, 55, 0.55) 0%, rgba(138, 115, 38, 0.65) 100%);
            border-radius: 8px;
            border: 2px solid transparent;
            background-clip: padding-box;
            box-shadow: 0 0 12px rgba(212, 175, 55, 0.15);
        }
        html.mg-simple-ui .simple-chat-thread-wrap::-webkit-scrollbar-thumb:hover,
        html.mg-simple-ui .simple-side-nav::-webkit-scrollbar-thumb:hover,
        html.mg-simple-ui .simple-activity-log::-webkit-scrollbar-thumb:hover,
        html.mg-simple-ui .simple-list-col::-webkit-scrollbar-thumb:hover,
        html.mg-simple-ui .simple-detail-col::-webkit-scrollbar-thumb:hover,
        html.mg-simple-ui .simple-detail-pre::-webkit-scrollbar-thumb:hover,
        html.mg-simple-ui .simple-activity-log-mobile::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(180deg, #d4af37 0%, #8a7326 100%);
            box-shadow: 0 0 16px rgba(212, 175, 55, 0.35);
        }
        html.mg-simple-ui[data-theme="light"] .simple-chat-thread-wrap::-webkit-scrollbar-thumb,
        html.mg-simple-ui[data-theme="light"] .simple-side-nav::-webkit-scrollbar-thumb,
        html.mg-simple-ui[data-theme="light"] .simple-activity-log::-webkit-scrollbar-thumb,
        html.mg-simple-ui[data-theme="light"] .simple-list-col::-webkit-scrollbar-thumb,
        html.mg-simple-ui[data-theme="light"] .simple-detail-col::-webkit-scrollbar-thumb,
        html.mg-simple-ui[data-theme="light"] .simple-detail-pre::-webkit-scrollbar-thumb,
        html.mg-simple-ui[data-theme="light"] .simple-activity-log-mobile::-webkit-scrollbar-thumb {
            background: linear-gradient(180deg, rgba(184, 150, 46, 0.85) 0%, rgba(107, 90, 42, 0.75) 100%);
            box-shadow: none;
        }
        html.mg-simple-ui .simple-chat-thread-wrap::-webkit-scrollbar-corner,
        html.mg-simple-ui .simple-side-nav::-webkit-scrollbar-corner {
            background: transparent;
        }

        html.mg-simple-ui .simple-side-nav {
            width: min(228px, 32vw);
            flex-shrink: 0;
            margin: 10px 0 10px 12px;
            border-radius: 18px;
            border: 1px solid rgba(212, 175, 55, 0.14);
            border-right: 1px solid rgba(212, 175, 55, 0.14);
            background: rgba(10, 10, 10, 0.82);
            backdrop-filter: blur(16px);
            padding: 14px 0 18px;
            overflow-y: auto;
            box-shadow:
                0 0 0 1px rgba(0, 0, 0, 0.35),
                0 18px 40px rgba(0, 0, 0, 0.35),
                inset 0 1px 0 rgba(212, 175, 55, 0.07);
        }
        html[data-theme="light"].mg-simple-ui .simple-side-nav {
            background: rgba(252, 248, 240, 0.94);
            border-color: rgba(184, 150, 46, 0.26);
            box-shadow:
                0 0 0 1px rgba(255, 255, 255, 0.55),
                0 16px 36px rgba(107, 90, 42, 0.07),
                inset 0 1px 0 rgba(184, 150, 46, 0.14);
        }
        .simple-nav-label {
            padding: 6px 16px 10px;
            font-family: 'Cinzel', serif;
            font-size: 0.62rem;
            letter-spacing: 0.22em;
            text-transform: uppercase;
            color: rgba(212, 175, 55, 0.45);
        }
        [data-theme="light"] .simple-nav-label {
            color: rgba(107, 90, 42, 0.65);
        }
        .simple-nav-label--spaced {
            margin-top: 10px;
        }
        .simple-nav-btn {
            display: block;
            width: 100%;
            text-align: left;
            padding: 11px 16px;
            margin: 0 8px;
            width: calc(100% - 16px);
            border: none;
            border-radius: 10px;
            background: transparent;
            color: rgba(249, 241, 216, 0.55);
            font-family: 'Cinzel', serif;
            font-size: 0.74rem;
            font-weight: 600;
            letter-spacing: 0.06em;
            cursor: pointer;
            transition: background 0.2s ease, color 0.2s ease, box-shadow 0.2s ease;
        }
        [data-theme="light"] .simple-nav-btn {
            color: rgba(92, 35, 41, 0.72);
        }
        .simple-nav-btn:hover {
            background: rgba(212, 175, 55, 0.08);
            color: #f9f1d8;
        }
        [data-theme="light"] .simple-nav-btn:hover {
            background: rgba(184, 150, 46, 0.12);
            color: #5c2329;
        }
        .simple-nav-btn.is-active {
            color: #d4af37;
            background: rgba(212, 175, 55, 0.12);
            box-shadow: inset 3px 0 0 #d4af37, 0 0 20px rgba(212, 175, 55, 0.08);
        }
        [data-theme="light"] .simple-nav-btn.is-active {
            color: #6b5a2a;
            background: rgba(184, 150, 46, 0.15);
            box-shadow: inset 3px 0 0 #b8962e, 0 0 16px rgba(184, 150, 46, 0.12);
        }
        .simple-main {
            flex: 1;
            min-width: 0;
            display: flex;
            flex-direction: column;
            background: rgba(0, 0, 0, 0.15);
        }
        [data-theme="light"] .simple-main {
            background: rgba(255, 255, 255, 0.2);
        }
        html.mg-simple-ui .simple-main {
            margin: 10px 8px 10px 4px;
            border-radius: 22px;
            border: 1px solid rgba(212, 175, 55, 0.14);
            background: linear-gradient(168deg, rgba(14, 12, 10, 0.94) 0%, rgba(5, 5, 8, 0.92) 55%, rgba(8, 7, 12, 0.9) 100%);
            box-shadow:
                0 0 0 1px rgba(0, 0, 0, 0.5),
                0 28px 56px rgba(0, 0, 0, 0.42),
                inset 0 1px 0 rgba(212, 175, 55, 0.09);
            overflow: hidden;
        }
        html.mg-simple-ui[data-theme="light"] .simple-main {
            background: linear-gradient(168deg, rgba(255, 252, 246, 0.97) 0%, rgba(252, 248, 240, 0.95) 100%);
            border-color: rgba(184, 150, 46, 0.22);
            box-shadow:
                0 0 0 1px rgba(255, 255, 255, 0.7),
                0 20px 48px rgba(107, 90, 42, 0.1),
                inset 0 1px 0 rgba(184, 150, 46, 0.12);
        }
        .simple-toolbar {
            flex-shrink: 0;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 10px 16px;
            padding: 12px 22px 14px;
            border-bottom: 1px solid rgba(212, 175, 55, 0.14);
            background: linear-gradient(180deg, rgba(212, 175, 55, 0.07) 0%, transparent 100%);
        }
        [data-theme="light"] .simple-toolbar {
            border-bottom-color: rgba(184, 150, 46, 0.22);
            background: linear-gradient(180deg, rgba(184, 150, 46, 0.08) 0%, transparent 100%);
        }
        .simple-toolbar h2 {
            margin: 0;
            font-size: clamp(1.05rem, 2.5vw, 1.35rem);
            font-weight: 700;
            color: #d4af37;
            text-shadow: 0 0 24px rgba(212, 175, 55, 0.15);
            letter-spacing: 0.04em;
        }
        [data-theme="light"] .simple-toolbar h2 {
            color: #6b5a2a;
            text-shadow: none;
        }
        .simple-toolbar-pulses {
            display: none;
            flex-wrap: wrap;
            gap: 6px;
            margin-top: 10px;
        }
        @media (max-width: 768px) {
            .simple-toolbar-pulses {
                display: flex;
            }
        }
        .simple-activity-log-mobile {
            display: none;
            max-height: 72px;
            overflow-y: auto;
            margin-top: 8px;
            padding: 6px 8px;
            font-family: "Courier New", monospace;
            font-size: 0.62rem;
            line-height: 1.35;
            color: var(--gold-dim);
            white-space: pre-wrap;
            word-break: break-word;
            background: rgba(0, 0, 0, 0.25);
            border-radius: 8px;
            border: 1px solid rgba(214, 219, 226, 0.1);
        }
        @media (max-width: 768px) {
            .simple-activity-log-mobile {
                display: block;
            }
        }
        /* Simple UI: Chat thread (center) vs library split */
        .simple-chat-view {
            flex: 1;
            min-height: 0;
            display: flex;
            flex-direction: column;
            padding: 0 4px 12px;
            box-sizing: border-box;
        }
        .simple-chat-surface {
            flex: 1;
            min-height: 0;
            display: flex;
            flex-direction: column;
            margin: 0 10px 4px;
            border-radius: 18px;
            border: 1px solid rgba(212, 175, 55, 0.11);
            background: rgba(2, 2, 4, 0.42);
            box-shadow: inset 0 1px 0 rgba(212, 175, 55, 0.05);
            overflow: hidden;
        }
        html.mg-simple-ui[data-theme="light"] .simple-chat-surface {
            background: rgba(255, 255, 255, 0.28);
            border-color: rgba(184, 150, 46, 0.18);
            box-shadow: inset 0 1px 0 rgba(184, 150, 46, 0.1);
        }
        .simple-main-view-library .simple-chat-view {
            display: none !important;
        }
        .simple-main-view-chat .simple-library-view {
            display: none !important;
        }
        .simple-library-view {
            flex: 1;
            min-height: 0;
            display: flex;
            flex-direction: column;
        }
        .simple-chat-thread-wrap {
            flex: 1;
            min-height: 0;
            overflow-y: auto;
            overflow-x: hidden;
            padding: 22px 10px 32px 22px;
            box-sizing: border-box;
            -webkit-overflow-scrolling: touch;
            scrollbar-gutter: stable;
        }
        .simple-chat-thread {
            max-width: 48rem;
            margin: 0 auto;
            padding-right: 8px;
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        .simple-chat-empty {
            text-align: center;
            color: var(--gold-dim);
            font-size: 1rem;
            padding: 2.5rem 1rem 1rem;
            opacity: 0.85;
        }
        .simple-chat-row {
            display: flex;
            width: 100%;
        }
        .simple-chat-row--user {
            justify-content: flex-end;
        }
        .simple-chat-row--assistant {
            justify-content: flex-start;
        }
        .simple-chat-bubble {
            max-width: min(94%, 44rem);
            padding: 14px 18px;
            border-radius: 18px;
            line-height: 1.58;
            font-size: 1.02rem;
            box-sizing: border-box;
        }
        .simple-chat-row--user .simple-chat-bubble {
            background: rgba(212, 175, 55, 0.14);
            border: 1px solid rgba(212, 175, 55, 0.32);
            color: #f9f1d8;
            border-bottom-right-radius: 6px;
        }
        [data-theme="light"] .simple-chat-row--user .simple-chat-bubble {
            background: rgba(184, 150, 46, 0.12);
            border-color: rgba(107, 90, 42, 0.35);
            color: #4a4238;
        }
        .simple-chat-row--assistant .simple-chat-bubble {
            background: rgba(8, 8, 8, 0.5);
            border: 1px solid rgba(212, 175, 55, 0.14);
            color: #f0ebe0;
            border-bottom-left-radius: 6px;
            backdrop-filter: blur(10px);
        }
        [data-theme="light"] .simple-chat-row--assistant .simple-chat-bubble {
            background: rgba(255, 255, 255, 0.72);
            border-color: rgba(184, 150, 46, 0.22);
            color: #4a4238;
        }
        .simple-chat-bubble--error {
            border-color: rgba(185, 28, 28, 0.45) !important;
            box-shadow: 0 0 0 1px rgba(185, 28, 28, 0.12);
        }
        .simple-chat-text {
            white-space: pre-wrap;
            word-break: break-word;
        }
        .simple-chat-row--assistant .simple-chat-bubble .response-modal-code-block {
            margin-top: 10px;
        }
        html.mg-simple-ui .simple-chat-row--assistant .simple-chat-bubble .response-modal-code {
            background: rgba(0, 0, 0, 0.5);
            border: 1px solid rgba(212, 175, 55, 0.12);
            border-radius: 10px;
        }
        html[data-theme="light"].mg-simple-ui .simple-chat-row--assistant .simple-chat-bubble .response-modal-code {
            background: rgba(255, 255, 255, 0.75);
            border-color: rgba(184, 150, 46, 0.2);
        }
        @media (max-width: 768px) {
            html.mg-simple-ui .simple-side-nav {
                margin: 8px 0 8px 8px;
                border-radius: 14px;
            }
            html.mg-simple-ui .simple-main {
                margin: 8px 6px 8px 2px;
                border-radius: 16px;
            }
            html.mg-simple-ui .simple-activity-col {
                margin: 8px 8px 8px 0;
                border-radius: 14px;
            }
            html.mg-simple-ui .simple-chat-surface {
                margin: 0 6px 2px;
                border-radius: 14px;
            }
            html.mg-simple-ui .simple-chat-thread-wrap {
                padding: 16px 8px 24px 14px;
            }
        }
        .simple-split {
            flex: 1;
            display: grid;
            grid-template-columns: minmax(200px, 34%) 1fr;
            gap: 12px;
            min-height: 0;
            padding: 12px 14px 14px;
            box-sizing: border-box;
        }
        @media (max-width: 768px) {
            .simple-split {
                grid-template-columns: 1fr;
                grid-template-rows: minmax(120px, 32%) 1fr;
                padding: 10px;
                gap: 10px;
            }
        }
        .simple-list-col {
            border: 1px solid rgba(212, 175, 55, 0.14);
            border-radius: 14px;
            overflow-y: auto;
            padding: 12px 14px;
            background: rgba(10, 10, 10, 0.5);
            backdrop-filter: blur(12px);
            box-shadow: inset 0 1px 0 rgba(212, 175, 55, 0.06);
        }
        [data-theme="light"] .simple-list-col {
            background: rgba(255, 255, 255, 0.55);
            border-color: rgba(184, 150, 46, 0.25);
        }
        @media (max-width: 768px) {
            .simple-list-col {
                border-right: none;
                border-bottom: none;
            }
        }
        .simple-detail-col {
            overflow-y: auto;
            padding: 14px 18px;
            border: 1px solid rgba(212, 175, 55, 0.12);
            border-radius: 14px;
            background: rgba(8, 8, 8, 0.42);
            backdrop-filter: blur(12px);
            box-shadow: inset 0 1px 0 rgba(212, 175, 55, 0.05);
        }
        [data-theme="light"] .simple-detail-col {
            background: rgba(255, 255, 255, 0.65);
            border-color: rgba(184, 150, 46, 0.2);
        }
        .simple-item-list {
            list-style: none;
            margin: 0;
            padding: 0;
        }
        .simple-item-btn {
            width: 100%;
            text-align: left;
            padding: 10px 12px;
            margin-bottom: 6px;
            border-radius: 10px;
            border: 1px solid rgba(212, 175, 55, 0.16);
            background: rgba(0, 0, 0, 0.25);
            color: #f9f1d8;
            font-size: 0.9rem;
            cursor: pointer;
            transition: border-color 0.2s ease, background 0.2s ease, box-shadow 0.2s ease;
        }
        [data-theme="light"] .simple-item-btn {
            color: #4a4238;
            background: rgba(255, 255, 255, 0.5);
            border-color: rgba(184, 150, 46, 0.22);
        }
        .simple-item-btn:hover {
            border-color: rgba(212, 175, 55, 0.35);
            background: rgba(212, 175, 55, 0.07);
            box-shadow: 0 0 16px rgba(212, 175, 55, 0.06);
        }
        .simple-item-btn.is-selected {
            border-color: rgba(212, 175, 55, 0.45);
            background: rgba(212, 175, 55, 0.1);
            box-shadow: 0 0 20px rgba(212, 175, 55, 0.1);
        }
        .simple-item-off {
            font-size: 0.75rem;
            color: var(--gold-dim);
            margin-left: 6px;
        }
        .simple-detail-title {
            font-size: 1.05rem;
            color: var(--gold);
            margin: 0 0 10px;
        }
        .simple-detail-pre {
            font-family: "Courier New", monospace;
            font-size: 0.78rem;
            line-height: 1.45;
            white-space: pre-wrap;
            word-break: break-word;
            background: rgba(0, 0, 0, 0.35);
            border: 1px solid rgba(212, 175, 55, 0.14);
            border-radius: 10px;
            padding: 12px;
            max-height: min(52vh, 420px);
            overflow: auto;
            color: #dce3ea;
        }
        [data-theme="light"] .simple-detail-pre {
            background: rgba(255, 255, 255, 0.65);
            color: #1d2228;
            border-color: rgba(184, 150, 46, 0.22);
        }
        .simple-app-form-label {
            display: block;
            font-size: 0.68rem;
            letter-spacing: 0.04em;
            color: var(--gold-dim);
            margin: 12px 0 6px;
        }
        .simple-app-title-input {
            width: 100%;
            box-sizing: border-box;
            padding: 8px 12px;
            border-radius: 8px;
            border: 1px solid rgba(212, 175, 55, 0.22);
            background: rgba(255, 255, 255, 0.04);
            color: #f9f1d8;
            font-size: 0.9rem;
            outline: none;
        }
        .simple-app-title-input:focus {
            border-color: var(--accent, var(--gold));
            background: rgba(212, 175, 55, 0.06);
            box-shadow: 0 0 14px rgba(212, 175, 55, 0.12);
        }
        [data-theme="light"] .simple-app-title-input {
            background: rgba(255, 255, 255, 0.85);
            color: #5c2329;
            border-color: rgba(184, 150, 46, 0.35);
        }
        [data-theme="light"] .simple-app-title-input:focus {
            border-color: var(--light-gold, #b8962e);
            box-shadow: 0 0 12px rgba(184, 150, 46, 0.15);
        }
        .simple-web-app-editor {
            width: 100%;
            box-sizing: border-box;
            min-height: 200px;
            max-height: min(48vh, 400px);
            resize: vertical;
            font-family: "Courier New", monospace;
            font-size: 0.75rem;
            line-height: 1.45;
            padding: 12px;
            border-radius: 10px;
            border: 1px solid rgba(212, 175, 55, 0.14);
            background: rgba(0, 0, 0, 0.35);
            color: #dce3ea;
            outline: none;
        }
        .simple-web-app-editor:focus {
            border-color: rgba(212, 175, 55, 0.35);
            box-shadow: 0 0 16px rgba(212, 175, 55, 0.08);
        }
        [data-theme="light"] .simple-web-app-editor {
            background: rgba(255, 255, 255, 0.65);
            color: #1d2228;
            border-color: rgba(184, 150, 46, 0.22);
        }
        .simple-app-save-status {
            margin-top: 10px;
            font-size: 0.82rem;
        }
        html.mg-simple-ui .simple-web-app-editor {
            scrollbar-width: thin;
            scrollbar-color: rgba(212, 175, 55, 0.55) rgba(3, 3, 3, 0.45);
        }
        html.mg-simple-ui[data-theme="light"] .simple-web-app-editor {
            scrollbar-color: rgba(184, 150, 46, 0.75) rgba(240, 235, 224, 0.85);
        }
        html.mg-simple-ui .simple-web-app-editor::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        html.mg-simple-ui .simple-web-app-editor::-webkit-scrollbar-track {
            background: rgba(0, 0, 0, 0.35);
            border-radius: 8px;
            margin: 4px 0;
        }
        html.mg-simple-ui[data-theme="light"] .simple-web-app-editor::-webkit-scrollbar-track {
            background: rgba(232, 224, 210, 0.9);
        }
        html.mg-simple-ui .simple-web-app-editor::-webkit-scrollbar-thumb {
            background: linear-gradient(180deg, rgba(212, 175, 55, 0.55) 0%, rgba(138, 115, 38, 0.65) 100%);
            border-radius: 8px;
            border: 2px solid transparent;
            background-clip: padding-box;
            box-shadow: 0 0 12px rgba(212, 175, 55, 0.15);
        }
        html.mg-simple-ui .simple-web-app-editor::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(180deg, #d4af37 0%, #8a7326 100%);
            box-shadow: 0 0 16px rgba(212, 175, 55, 0.35);
        }
        html.mg-simple-ui[data-theme="light"] .simple-web-app-editor::-webkit-scrollbar-thumb {
            background: linear-gradient(180deg, rgba(184, 150, 46, 0.85) 0%, rgba(107, 90, 42, 0.75) 100%);
            box-shadow: none;
        }
        .simple-open-panel-btn {
            margin-top: 12px;
            padding: 8px 14px;
            border-radius: 8px;
            border: 1px solid rgba(214, 219, 226, 0.35);
            background: rgba(214, 219, 226, 0.12);
            color: var(--gold);
            font-family: 'Cinzel', serif;
            font-size: 0.72rem;
            cursor: pointer;
        }
        .simple-open-panel-btn:hover {
            background: rgba(214, 219, 226, 0.2);
        }
        .simple-loading, .simple-empty, .simple-warn {
            font-size: 0.9rem;
            color: var(--gold-dim);
        }
        .simple-warn { color: #c9a227; }
        .simple-activity-col {
            width: min(232px, 30vw);
            flex-shrink: 0;
            border-left: 1px solid rgba(212, 175, 55, 0.14);
            background: rgba(10, 10, 10, 0.55);
            backdrop-filter: blur(12px);
            display: flex;
            flex-direction: column;
            min-height: 0;
        }
        html.mg-simple-ui .simple-activity-col {
            margin: 10px 12px 10px 0;
            border-radius: 18px;
            border: 1px solid rgba(212, 175, 55, 0.14);
            border-left: 1px solid rgba(212, 175, 55, 0.14);
            background: rgba(8, 8, 10, 0.82);
            box-shadow:
                0 0 0 1px rgba(0, 0, 0, 0.35),
                0 18px 40px rgba(0, 0, 0, 0.32),
                inset 0 1px 0 rgba(212, 175, 55, 0.06);
        }
        [data-theme="light"] .simple-activity-col {
            background: rgba(252, 248, 240, 0.88);
            border-left-color: rgba(184, 150, 46, 0.22);
        }
        html.mg-simple-ui[data-theme="light"] .simple-activity-col {
            background: rgba(252, 248, 240, 0.94);
            border-color: rgba(184, 150, 46, 0.22);
            box-shadow:
                0 0 0 1px rgba(255, 255, 255, 0.55),
                0 16px 36px rgba(107, 90, 42, 0.06),
                inset 0 1px 0 rgba(184, 150, 46, 0.12);
        }
        @media (max-width: 768px) {
            .simple-activity-col {
                display: none;
            }
        }
        .simple-activity-title {
            font-size: 0.72rem;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: #d4af37;
            padding: 12px 14px;
            border-bottom: 1px solid rgba(212, 175, 55, 0.12);
        }
        [data-theme="light"] .simple-activity-title {
            color: #6b5a2a;
        }
        .simple-activity-tabs {
            display: flex;
            gap: 0;
            border-bottom: 1px solid rgba(212, 175, 55, 0.14);
            flex-shrink: 0;
        }
        .simple-activity-tab {
            flex: 1;
            margin: 0;
            padding: 10px 8px;
            border: none;
            border-bottom: 2px solid transparent;
            background: transparent;
            color: var(--gold-dim);
            font-family: 'Cinzel', serif;
            font-size: 0.58rem;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            cursor: pointer;
            transition: color 0.15s ease, border-color 0.15s ease, background 0.15s ease;
        }
        .simple-activity-tab:hover {
            color: #d4af37;
            background: rgba(212, 175, 55, 0.06);
        }
        .simple-activity-tab.is-active {
            color: #d4af37;
            border-bottom-color: rgba(212, 175, 55, 0.65);
            background: rgba(212, 175, 55, 0.05);
        }
        [data-theme="light"] .simple-activity-tab {
            color: #7a6a42;
        }
        [data-theme="light"] .simple-activity-tab.is-active {
            color: #5c4d24;
            border-bottom-color: rgba(184, 150, 46, 0.55);
            background: rgba(184, 150, 46, 0.08);
        }
        .simple-activity-body {
            flex: 1;
            min-height: 0;
            display: flex;
            flex-direction: column;
        }
        .simple-activity-panel {
            display: none;
            flex: 1;
            flex-direction: column;
            min-height: 0;
            overflow: hidden;
        }
        .simple-activity-panel.is-active {
            display: flex;
        }
        .simple-activity-panel > .simple-activity-log {
            min-height: 0;
        }
        .simple-chat-history-toolbar {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            padding: 8px 10px;
            border-bottom: 1px solid rgba(214, 219, 226, 0.08);
            flex-shrink: 0;
        }
        .simple-chat-history-toolbar .panel-action-btn {
            font-size: 0.62rem;
            padding: 5px 8px;
        }
        .simple-chat-history-list {
            flex: 1;
            min-height: 40px;
            overflow-y: auto;
            padding: 6px 8px 10px;
            font-size: 0.72rem;
            line-height: 1.35;
            color: var(--gold-dim);
        }
        .simple-chat-history-row {
            display: flex;
            align-items: flex-start;
            gap: 6px;
            padding: 8px 6px;
            margin-bottom: 4px;
            border-radius: 8px;
            border: 1px solid rgba(212, 175, 55, 0.1);
            background: rgba(0, 0, 0, 0.2);
        }
        [data-theme="light"] .simple-chat-history-row {
            background: rgba(255, 255, 255, 0.45);
            border-color: rgba(184, 150, 46, 0.15);
        }
        .simple-chat-history-row.is-current {
            border-color: rgba(212, 175, 55, 0.35);
            box-shadow: inset 0 0 0 1px rgba(212, 175, 55, 0.12);
        }
        .simple-chat-history-row-main {
            flex: 1;
            min-width: 0;
        }
        .simple-chat-history-meta {
            font-size: 0.62rem;
            opacity: 0.85;
            margin-top: 2px;
        }
        .simple-chat-history-actions {
            display: flex;
            flex-direction: column;
            gap: 4px;
            flex-shrink: 0;
        }
        .simple-chat-history-actions button {
            font-family: 'Cinzel', serif;
            font-size: 0.58rem;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            padding: 3px 6px;
            border-radius: 4px;
            border: 1px solid rgba(212, 175, 55, 0.25);
            background: rgba(212, 175, 55, 0.08);
            color: #d4af37;
            cursor: pointer;
        }
        .simple-chat-history-actions button:hover {
            background: rgba(212, 175, 55, 0.16);
        }
        .simple-chat-history-actions button.simple-chat-history-btn-danger {
            border-color: rgba(200, 90, 70, 0.45);
            color: #e8a090;
            background: rgba(120, 40, 30, 0.15);
        }
        [data-theme="light"] .simple-chat-history-actions button.simple-chat-history-btn-danger {
            color: #8b3a2a;
            border-color: rgba(180, 80, 60, 0.35);
            background: rgba(255, 230, 220, 0.5);
        }
        .simple-activity-pulses {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            padding: 10px 12px;
            border-bottom: 1px solid rgba(214, 219, 226, 0.08);
        }
        .simple-pulse-item {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 0.62rem;
            color: var(--gold-dim);
        }
        .simple-pulse-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            opacity: 0.35;
            transition: opacity 0.2s ease, transform 0.2s ease, box-shadow 0.2s ease;
        }
        .simple-pulse-dot.is-live {
            opacity: 1;
            transform: scale(1.15);
            animation: simplePulseGlow 1.1s ease-in-out infinite;
        }
        @keyframes simplePulseGlow {
            0%, 100% { box-shadow: 0 0 4px currentColor; }
            50% { box-shadow: 0 0 12px rgba(214, 219, 226, 0.85); }
        }
        .simple-activity-log {
            flex: 1;
            min-height: 80px;
            overflow-y: auto;
            padding: 8px 10px;
            font-family: "Courier New", monospace;
            font-size: 0.68rem;
            line-height: 1.4;
            color: var(--gold-dim);
            white-space: pre-wrap;
            word-break: break-word;
        }
    </style>
</head>
<body>
    <script>
    try {
        if (localStorage.getItem('memoryGraphInterfaceMode') === 'simple') {
            document.documentElement.classList.add('mg-simple-ui');
        }
    } catch (e) {}
    </script>
    <div id="graph-container"></div>

    <button type="button" id="apps-fab" class="apps-fab font-display" aria-label="Web apps" title="HTML / JS apps">Apps</button>
    <div id="apps-drawer-backdrop" class="apps-drawer-backdrop" hidden></div>
    <aside id="apps-drawer" class="apps-drawer font-display" aria-hidden="true">
        <div class="apps-drawer-header">
            <span>Web apps</span>
            <button type="button" class="apps-drawer-close" id="apps-drawer-close" aria-label="Close">&times;</button>
        </div>
        <div class="apps-drawer-body">
            <p class="apps-drawer-hint font-serif">Mini-apps in <code>apps/&lt;slug&gt;/index.html</code>. The AI can use <strong>display_web_app</strong>, <strong>create_web_app</strong>, etc.</p>
            <div id="apps-drawer-list" class="apps-drawer-list"></div>
            <button type="button" id="apps-drawer-refresh" class="panel-action-btn">Refresh list</button>
        </div>
    </aside>

    <button type="button" id="settings-fab" class="settings-fab font-display" aria-label="Open settings" title="Settings">&#9881;</button>
    <div id="settings-backdrop" class="settings-backdrop" hidden></div>
    <aside id="settings-panel" class="settings-panel" aria-hidden="true">
        <div class="settings-panel-header">
            <span class="settings-panel-title font-display">Settings</span>
            <button type="button" class="settings-panel-close font-display" id="settings-panel-close" aria-label="Close settings">&times;</button>
        </div>
        <div class="settings-panel-body">
            <div class="settings-section">
                <div class="settings-section-kicker font-display">Workspace</div>
                <h3 class="settings-section-title font-display">Interface</h3>
                <p class="settings-section-lead font-serif">Choose between the full <strong>3D memory graph</strong> and a <strong>focused chat workspace</strong> with sidebar navigation, library browser, and live activity hints.</p>
                <div class="settings-option-card">
                    <div class="settings-option-text">
                        <div class="settings-option-name font-display">Simple chat</div>
                        <div class="settings-option-desc font-serif">Hides the graph view; keeps agents, tools, and jobs one click away in the rail.</div>
                    </div>
                    <label class="settings-toggle" for="ui-mode-simple-switch">
                        <input type="checkbox" class="settings-toggle-input" id="ui-mode-simple-switch" role="switch" aria-label="Use simple chat layout">
                        <span class="settings-toggle-ui" aria-hidden="true"><span class="settings-toggle-thumb"></span></span>
                    </label>
                </div>
            </div>
            <div class="settings-section" id="settings-provider-api-section">
                <div class="settings-section-kicker font-display">Connections</div>
                <h3 class="settings-section-title font-display">AI provider API keys</h3>
                <p class="settings-section-lead font-serif">Optional overrides are saved in <strong>config/agent_config.json</strong> on the server and override <code>.env</code> for chat. Clear the field and use <strong>Clear override</strong> to use <code>.env</code> again.</p>
                <div id="settings-provider-api-keys-mount" class="settings-provider-api-keys font-serif" aria-live="polite"></div>
            </div>
            <div class="settings-panel-spacer" aria-hidden="true"></div>
            <footer class="settings-panel-footer">
                <p class="settings-footer-note font-serif">Layout preference is stored in <strong>this browser</strong> only (local storage). API key overrides live on the server. Switch anytime; the graph keeps working in the background for jobs and status.</p>
            </footer>
        </div>
    </aside>

    <div id="simple-ui-root" class="simple-ui-root">
        <nav class="simple-side-nav font-display" aria-label="Resource sections">
            <button type="button" class="simple-nav-btn is-active" data-section="chat">Chat</button>
            <div class="simple-nav-label">Library</div>
            <button type="button" class="simple-nav-btn" data-section="memory">Memory</button>
            <button type="button" class="simple-nav-btn" data-section="tools">Tools</button>
            <button type="button" class="simple-nav-btn" data-section="instructions">Instructions</button>
            <button type="button" class="simple-nav-btn" data-section="research">Research</button>
            <button type="button" class="simple-nav-btn" data-section="rules">Rules</button>
            <button type="button" class="simple-nav-btn" data-section="mcps">MCPs</button>
            <button type="button" class="simple-nav-btn" data-section="jobs">Jobs</button>
            <button type="button" class="simple-nav-btn" data-section="apps">Apps</button>
            <div class="simple-nav-label simple-nav-label--spaced">Automation</div>
            <button type="button" class="simple-nav-btn" data-section="scheduled">Scheduled</button>
        </nav>
        <main id="simple-main" class="simple-main simple-main-view-chat">
            <div class="simple-toolbar">
                <h2 id="simple-section-title" class="font-display">Chat</h2>
                <div id="simple-toolbar-pulses" class="simple-toolbar-pulses" aria-hidden="true"></div>
                <div id="simple-activity-log-mobile" class="simple-activity-log-mobile" aria-live="polite"></div>
            </div>
            <div id="simple-chat-view" class="simple-chat-view">
                <div class="simple-chat-surface">
                    <div class="simple-chat-thread-wrap">
                        <div id="simple-chat-thread" class="simple-chat-thread font-serif" role="log" aria-live="polite" aria-relevant="additions"></div>
                    </div>
                </div>
            </div>
            <div id="simple-library-view" class="simple-library-view">
                <div class="simple-split">
                    <div id="simple-list-col" class="simple-list-col font-serif"></div>
                    <div id="simple-detail-col" class="simple-detail-col font-serif"></div>
                </div>
            </div>
        </main>
        <aside class="simple-activity-col" aria-label="Chat history and activity">
            <div class="simple-activity-tabs font-display" role="tablist" aria-label="Side panel">
                <button type="button" class="simple-activity-tab" role="tab" aria-selected="false" data-tab="history" id="simple-activity-tab-history">Chat History</button>
                <button type="button" class="simple-activity-tab is-active" role="tab" aria-selected="true" data-tab="log" id="simple-activity-tab-log">Console Log</button>
            </div>
            <div id="simple-activity-pulses" class="simple-activity-pulses"></div>
            <div class="simple-activity-body">
                <div id="simple-activity-panel-history" class="simple-activity-panel" role="tabpanel" aria-labelledby="simple-activity-tab-history">
                    <div class="simple-chat-history-toolbar">
                        <button type="button" id="simple-chat-history-new" class="panel-action-btn">New session</button>
                        <button type="button" id="simple-chat-history-refresh" class="panel-action-btn">Refresh</button>
                    </div>
                    <div class="simple-chat-history-toolbar">
                        <button type="button" id="simple-chat-history-delete-selected" class="panel-action-btn btn-stop" disabled>Delete selected</button>
                        <button type="button" id="simple-chat-history-clear-legacy" class="panel-action-btn" title="Remove saved turns that have no session id">Clear legacy</button>
                    </div>
                    <div id="simple-chat-history-list" class="simple-chat-history-list font-serif" aria-live="polite"></div>
                </div>
                <div id="simple-activity-panel-log" class="simple-activity-panel is-active" role="tabpanel" aria-labelledby="simple-activity-tab-log">
                    <div id="simple-activity-log" class="simple-activity-log"></div>
                </div>
            </div>
        </aside>
    </div>

    <div class="jarvis-brand-fixed">
        <div class="jarvis-brand">
            <div class="jarvis-brand__halo" aria-hidden="true"></div>
            <h1 class="jarvis-brand__title">
                <span class="jarvis-brand__title-main font-display">Open Jarvis Dashboard</span>
            </h1>
            <div class="jarvis-brand__rule" aria-hidden="true"><span class="jarvis-brand__rule-scan"></span></div>
            <p class="jarvis-brand__tagline font-serif">An open-source Lightweight AI Framework For Managing MCPs, Skills, Memory, Research and More</p>
        </div>
    </div>

    <div class="graph-legend" id="graph-legend">
        <div class="graph-legend-title font-display">Nodes</div>
        <ul class="graph-legend-list" id="graph-legend-static">
            <li><span class="graph-legend-swatch" style="background:#d9e4ff; box-shadow:0 0 8px rgba(217,228,255,0.7);"></span> Agent</li>
            <li><span class="graph-legend-swatch" style="background:#47d7c9; box-shadow:0 0 8px rgba(71,215,201,0.6);"></span> Memory</li>
            <li><span class="graph-legend-swatch" style="background:#ffc857; box-shadow:0 0 8px rgba(255,200,87,0.6);"></span> Tools</li>
            <li><span class="graph-legend-swatch" style="background:#7cb8ff; box-shadow:0 0 8px rgba(124,184,255,0.65);"></span> Instructions</li>
            <li><span class="graph-legend-swatch" style="background:#b8a9e8; box-shadow:0 0 8px rgba(184,169,232,0.6);"></span> Research</li>
            <li><span class="graph-legend-swatch" style="background:#e8a9b8; box-shadow:0 0 8px rgba(232,169,184,0.6);"></span> Rules</li>
            <li><span class="graph-legend-swatch" style="background:#6be38e; box-shadow:0 0 8px rgba(107,227,142,0.55);"></span> MCPs</li>
            <li><span class="graph-legend-swatch" style="background:#ff8f70; box-shadow:0 0 8px rgba(255,143,112,0.58);"></span> Jobs</li>
            <li><span class="graph-legend-swatch" style="background:#a0d4e8; box-shadow:0 0 8px rgba(160,212,232,0.6);"></span> Categories</li>
        </ul>
        <div class="graph-legend-categories-wrap" id="graph-legend-categories-wrap" style="display:none;">
            <div class="graph-legend-subtitle">Category nodes</div>
            <ul class="graph-legend-list graph-legend-categories" id="graph-legend-categories"></ul>
        </div>
    </div>

    <div class="bottom-dock" id="bottom-dock">
        <aside id="running-jobs-widget" class="running-jobs-widget" aria-hidden="false">
            <div class="running-jobs-title font-display">Running Jobs</div>
            <div id="running-jobs-list" class="running-jobs-list">
                <div class="running-job-empty">No jobs running.</div>
            </div>
        </aside>
        <div class="chat-bar">
            <div id="chat-queue-wrap" class="chat-queue-wrap" style="display: none;">
                <div class="chat-queue-header" id="chat-queue-header">
                    <span class="chat-queue-toggle" id="chat-queue-toggle">&#9660;</span>
                    <span id="chat-queue-count">0 Queued</span>
                </div>
                <div class="chat-queue-list" id="chat-queue-list"></div>
            </div>
            <div class="input-wrap">
                <input type="text" id="chat-input" placeholder="Ask the AI..." autocomplete="off">
                <button type="button" class="btn-send" id="chat-send">Send</button>
                <button type="button" class="btn-send btn-stop" id="chat-stop">Stop</button>
            </div>
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
                <label class="provider-label" for="system-instruction-select">System instruction file</label>
                <select id="system-instruction-select" class="provider-select">
                    <option value="">None</option>
                </select>
                <div class="panel-action-btn-row" style="margin-top: 8px;">
                    <button type="button" id="system-instruction-create-btn" class="panel-action-btn">Create new instruction…</button>
                </div>
                <p class="text-muted font-serif" style="font-size: 0.75rem; margin-top: 8px;">The selected instructions/*.md file is loaded on the server and used as the system prompt for this provider and model. Edit files under Instructions on the graph.</p>
                <label class="provider-label">Temperature</label>
                <input type="number" id="temperature-input" class="provider-input" min="0" max="2" step="0.1" value="0.7">
            </div>
            <div id="tool-config-panel" style="display: none; margin-top: 15px;">
                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" id="tool-active-switch">
                    <label class="form-check-label provider-label" for="tool-active-switch" style="margin-top:0; cursor:pointer;">Enabled</label>
                </div>
                <label class="provider-label">Underlying Code</label>
                <textarea id="tool-code-display" class="provider-textarea" rows="12" placeholder="Tool PHP code..." style="font-family: 'Courier New', monospace; font-size: 0.8rem; min-height: 200px; max-height: 280px;"></textarea>
                <div class="panel-action-btn-row" style="margin-top: 10px;">
                    <button type="button" id="tool-save-btn" class="panel-action-btn">Save Code</button>
                    <button type="button" id="tool-delete-btn" class="panel-action-btn btn-stop">Delete Tool</button>
                </div>
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
                <div class="panel-action-btn-row">
                    <button type="button" id="memory-save-btn" class="panel-action-btn">Save Memory</button>
                    <button type="button" id="memory-delete-btn" class="panel-action-btn btn-stop">Delete Memory</button>
                </div>
            </div>
            <div id="instruction-config-panel" style="display: none; margin-top: 15px;">
                <label class="provider-label">Instruction Contents</label>
                <textarea id="instruction-content-input" class="provider-textarea" rows="10" placeholder="Instruction file contents..."></textarea>
                <div class="panel-action-btn-row">
                    <button type="button" id="instruction-save-btn" class="panel-action-btn">Save Instruction</button>
                    <button type="button" id="instruction-delete-btn" class="panel-action-btn btn-stop">Delete Instruction</button>
                </div>
            </div>
            <div id="research-parent-panel" style="display: none; margin-top: 15px;">
                <div id="research-list-panel" class="tool-list-panel"></div>
            </div>
            <div id="research-config-panel" style="display: none; margin-top: 15px;">
                <label class="provider-label">Research Contents</label>
                <textarea id="research-content-input" class="provider-textarea" rows="10" placeholder="Research file contents..."></textarea>
                <div class="panel-action-btn-row">
                    <button type="button" id="research-save-btn" class="panel-action-btn">Save Research</button>
                    <button type="button" id="research-delete-btn" class="panel-action-btn btn-stop">Delete Research</button>
                </div>
            </div>
            <div id="rules-parent-panel" style="display: none; margin-top: 15px;">
                <div id="rules-list-panel" class="tool-list-panel"></div>
            </div>
            <div id="rules-config-panel" style="display: none; margin-top: 15px;">
                <label class="provider-label">Rules Contents</label>
                <textarea id="rules-content-input" class="provider-textarea" rows="10" placeholder="Rules file contents..."></textarea>
                <div class="panel-action-btn-row">
                    <button type="button" id="rules-save-btn" class="panel-action-btn">Save Rules</button>
                    <button type="button" id="rules-delete-btn" class="panel-action-btn btn-stop">Delete Rules</button>
                </div>
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
                    <button type="button" id="job-delete-btn" class="panel-action-btn btn-stop">Delete Job</button>
                </div>
            </div>
            <div id="sub-agent-chat-panel" class="sub-agent-chat-panel" style="display: none; margin-top: 15px;">
                <p class="sub-agent-run-hint font-serif">Send a message to <strong id="sub-agent-chat-target-label">this sub-agent</strong> only. Uses the same tool loop as Jarvis when <code>MEMORYGRAPH_PUBLIC_BASE_URL</code> is configured.</p>
                <label class="provider-label" for="sub-agent-prompt-input">Prompt</label>
                <textarea id="sub-agent-prompt-input" class="provider-textarea" rows="5" placeholder="Your instructions for this sub-agent…"></textarea>
                <div class="panel-action-btn-row" style="margin-top: 10px;">
                    <button type="button" id="sub-agent-send-btn" class="panel-action-btn">Send to sub-agent</button>
                </div>
                <div id="sub-agent-run-response" class="sub-agent-run-response" role="status" aria-live="polite"></div>
            </div>
            <div id="cron-config-panel" style="display: none; margin-top: 15px;">
                <label class="provider-label">Schedule &amp; runs</label>
                <pre id="cron-detail-pre" class="provider-textarea" style="min-height:120px;max-height:220px;overflow:auto;font-family:'Courier New',monospace;font-size:0.78rem;"></pre>
                <label class="provider-label">Prompt preview</label>
                <p id="cron-message-preview" class="mb-2" style="font-size:0.85rem;color:var(--gold-dim);"></p>
                <div class="panel-action-btn-row" style="flex-wrap:wrap;gap:8px;">
                    <button type="button" id="cron-run-now-btn" class="panel-action-btn">Run now</button>
                    <button type="button" id="cron-toggle-enabled-btn" class="panel-action-btn">Enable / Disable</button>
                    <button type="button" id="cron-delete-btn" class="panel-action-btn btn-stop">Remove schedule</button>
                </div>
            </div>
        </div>
    </aside>

    <aside id="execution-widget" class="execution-widget" aria-hidden="true">
        <div class="execution-widget-title font-display">Execution Parameters</div>
        <pre id="execution-widget-body"></pre>
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

    <div class="modal fade" id="web-app-modal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-fullscreen">
            <div class="modal-content web-app-modal-content">
                <div class="modal-header web-app-modal-header">
                    <h5 class="modal-title font-display" id="web-app-modal-title">Web app</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body web-app-modal-body">
                    <iframe id="web-app-modal-frame" class="web-app-modal-frame" title="Web app" sandbox="allow-scripts allow-same-origin allow-forms allow-modals allow-popups allow-pointer-lock" allow="pointer-lock; fullscreen; autoplay; gamepad; accelerometer; gyroscope; microphone; camera; display-capture; xr-spatial-tracking"></iframe>
                </div>
            </div>
        </div>
    </div>

    <script src="vendor/jquery-3.7.1.min.js"></script>
    <script src="vendor/gsap.min.js"></script>
    <script>
    (function () {
        function bootJarvisTitle() {
            var mainEl = document.querySelector('.jarvis-brand__title-main');
            var taglineEl = document.querySelector('.jarvis-brand__tagline');
            if (!mainEl || !taglineEl) return;
            var rule = document.querySelector('.jarvis-brand__rule');
            var logoEls = [mainEl, taglineEl];
            if (typeof gsap === 'undefined') {
                mainEl.style.opacity = '1';
                taglineEl.style.opacity = '1';
                if (rule) { rule.style.opacity = '1'; rule.style.transform = 'scaleX(1)'; }
                return;
            }
            if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                gsap.set(logoEls, { opacity: 1 });
                if (rule) gsap.set(rule, { scaleX: 1, opacity: 1 });
                return;
            }
            gsap.fromTo(mainEl,
                { opacity: 0 },
                { opacity: 1, duration: 0.55, ease: 'power2.out', delay: 0.08 }
            );
            gsap.fromTo('.jarvis-brand__rule',
                { scaleX: 0, opacity: 0 },
                { scaleX: 1, opacity: 1, duration: 1.15, ease: 'power3.inOut', delay: 0.32 }
            );
            gsap.fromTo(taglineEl,
                { opacity: 0 },
                { opacity: 1, duration: 0.65, ease: 'power2.out', delay: 1.05 }
            );
            gsap.fromTo('.jarvis-brand__halo',
                { opacity: 0, scale: 0.6 },
                { opacity: 0.42, scale: 1, duration: 1.4, ease: 'power2.out', delay: 0.05 }
            );
            gsap.to('.jarvis-brand__halo', {
                opacity: 0.55,
                scale: 1.12,
                duration: 2.8,
                repeat: -1,
                yoyo: true,
                ease: 'sine.inOut',
                delay: 1.5
            });
            gsap.to('.jarvis-brand', {
                marginTop: -3,
                duration: 3.2,
                repeat: -1,
                yoyo: true,
                ease: 'sine.inOut',
                delay: 1.8
            });
        }
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', bootJarvisTitle);
        } else {
            bootJarvisTitle();
        }
    })();
    </script>
    <script src="vendor/bootstrap.bundle.min.js"></script>
    <script src="vendor/three.min.js"></script>
    <script src="vendor/OrbitControls.js"></script>
    <script src="AgentState.js"></script>
    <script>
    window.MemoryGraphUpdateLegend = function (categories) {
        var wrap = document.getElementById('graph-legend-categories-wrap');
        var el = document.getElementById('graph-legend-categories');
        if (!el || !wrap) return;
        el.innerHTML = '';
        var list = categories || [];
        wrap.style.display = list.length ? 'block' : 'none';
        list.forEach(function (cat) {
            var li = document.createElement('li');
            li.innerHTML = '<span class="graph-legend-swatch" style="background:#b0e4f8; box-shadow:0 0 8px rgba(176,228,248,0.6);"></span> ' + (cat.title || cat.name || '');
            el.appendChild(li);
        });
    };
    </script>
    <script src="js/graph.js"></script>
    <script>
    window.MEMORY_GRAPH_PROVIDERS = {
        mercury: { name: 'Mercury (Inception Labs)', models: ['mercury-2'] },
        featherless: { name: 'Featherless', models: ['glm47-flash'] },
        alibaba: { name: 'Alibaba Cloud', models: ['qwen-plus', 'glm-5'] },
        gemini: {
            name: 'Gemini (Google)',
            models: [
                'gemini-2.5-flash', 'gemini-2.5-pro',
                'gemini-3-flash-preview', 'gemini-3-pro-preview',
                'gemini-3-flash', 'gemini-3-pro',
                'gemini-3.1-flash-preview', 'gemini-3.1-pro-preview'
            ]
        },
        openrouter: { name: 'OpenRouter', models: ['google/gemma-4-31b-it:free'] },
        nvidia_nim: { name: 'NVIDIA NIM', models: [
            'deepseek-ai/deepseek-v4-flash',
            'deepseek-ai/deepseek-v4-pro',
            'nvidia/nemotron-voicechat',
            'z-ai/glm-4.7',
            'minimaxai/minimax-m2.7',
            'mistralai/devstral-2-123b-instruct-2512'
        ] }
    };
    (function () {
        var providerSelect = document.getElementById('provider-select');
        var modelSelect = document.getElementById('model-select');
        var systemInstructionSelect = document.getElementById('system-instruction-select');
        var systemInstructionCreateBtn = document.getElementById('system-instruction-create-btn');
        var temperatureInput = document.getElementById('temperature-input');
        window.MEMORY_GRAPH_SYSTEM_INSTRUCTION_FILES = {};
        window.MEMORY_GRAPH_INSTRUCTION_OPTIONS = [];
        var agentSelProvider = '';
        var agentSelModel = '';

        function agentPromptKey(pv, mv) {
            return (pv || '') + ':' + (mv || '');
        }

        function captureAgentSelection() {
            agentSelProvider = providerSelect ? providerSelect.value : '';
            agentSelModel = modelSelect ? modelSelect.value : '';
        }

        function persistAgentSelectionToServer(pv, mv) {
            if (!pv) return;
            fetch('api/agent_config.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'set_selection',
                    provider: pv,
                    model: mv || ''
                })
            }).catch(function () {});
        }

        function persistInstructionFileToServer(pv, mv, filename) {
            if (!pv || !mv) return;
            fetch('api/agent_config.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'set_system_instruction_file',
                    provider: pv,
                    model: mv,
                    instructionFile: filename || ''
                })
            }).then(function (r) { return r.ok ? r.json() : null; }).then(function (j) {
                if (j && j.ok && j.key) {
                    if (filename) {
                        window.MEMORY_GRAPH_SYSTEM_INSTRUCTION_FILES[j.key] = filename;
                    } else {
                        delete window.MEMORY_GRAPH_SYSTEM_INSTRUCTION_FILES[j.key];
                    }
                }
            }).catch(function () {});
        }

        function syncInstructionSelectToCurrentKey() {
            if (!systemInstructionSelect || !providerSelect || !modelSelect) return;
            var k = agentPromptKey(providerSelect.value, modelSelect.value);
            var fn = window.MEMORY_GRAPH_SYSTEM_INSTRUCTION_FILES[k] || '';
            systemInstructionSelect.value = fn;
            if (fn && !Array.prototype.some.call(systemInstructionSelect.options, function (o) { return o.value === fn; })) {
                var opt = document.createElement('option');
                opt.value = fn;
                opt.textContent = fn + ' (missing)';
                systemInstructionSelect.appendChild(opt);
                systemInstructionSelect.value = fn;
            }
        }

        function rebuildInstructionFileDropdown(names) {
            if (!systemInstructionSelect) return;
            var prev = systemInstructionSelect.value;
            systemInstructionSelect.innerHTML = '';
            var none = document.createElement('option');
            none.value = '';
            none.textContent = 'None';
            systemInstructionSelect.appendChild(none);
            (names || []).forEach(function (n) {
                if (!n) return;
                var opt = document.createElement('option');
                opt.value = n;
                opt.textContent = n;
                systemInstructionSelect.appendChild(opt);
            });
            if (prev && Array.prototype.some.call(systemInstructionSelect.options, function (o) { return o.value === prev; })) {
                systemInstructionSelect.value = prev;
            } else {
                syncInstructionSelectToCurrentKey();
            }
        }

        function loadInstructionFileList(done) {
            fetch('api_instructions.php?action=list')
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (data) {
                    var arr = (data && Array.isArray(data.instructions)) ? data.instructions : [];
                    window.MEMORY_GRAPH_INSTRUCTION_OPTIONS = arr.map(function (x) { return x && x.name ? x.name : ''; }).filter(Boolean);
                    rebuildInstructionFileDropdown(window.MEMORY_GRAPH_INSTRUCTION_OPTIONS);
                    if (typeof done === 'function') done();
                })
                .catch(function () { if (typeof done === 'function') done(); });
        }

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

        function applyAgentConfig(data) {
            if (!data || !data.providers) return;
            window.MEMORY_GRAPH_PROVIDERS = data.providers;
            window.MEMORY_GRAPH_SYSTEM_INSTRUCTION_FILES = (data.systemInstructionFilesByModel && typeof data.systemInstructionFilesByModel === 'object')
                ? JSON.parse(JSON.stringify(data.systemInstructionFilesByModel))
                : {};
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
            if (modelSelect && data.currentModel) {
                if (Array.prototype.some.call(modelSelect.options, function (o) { return o.value === data.currentModel; })) {
                    modelSelect.value = data.currentModel;
                }
            }
            captureAgentSelection();
        }
        window.applyAgentConfig = applyAgentConfig;

        if (systemInstructionSelect) {
            systemInstructionSelect.addEventListener('change', function () {
                if (!providerSelect || !modelSelect) return;
                persistInstructionFileToServer(providerSelect.value, modelSelect.value, systemInstructionSelect.value || '');
            });
        }
        if (systemInstructionCreateBtn) {
            systemInstructionCreateBtn.addEventListener('click', function () {
                var base = window.prompt('New instruction file base name (e.g. my-agent):', '');
                if (base == null || String(base).trim() === '') return;
                var safe = String(base).trim().replace(/[^\w\-]+/g, '-').replace(/^-+|-+$/g, '');
                if (!safe) return;
                var fname = safe.toLowerCase().slice(-3) === '.md' ? safe : (safe + '.md');
                var body = '# Agent system instruction\n\n';
                systemInstructionCreateBtn.disabled = true;
                fetch('api_instructions.php?action=save', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ name: fname, content: body })
                }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
                    .then(function (out) {
                        if (!out.ok || (out.j && out.j.error)) {
                            window.alert((out.j && out.j.error) ? out.j.error : 'Could not create file');
                            return;
                        }
                        var created = (out.j && out.j.name) ? out.j.name : fname;
                        loadInstructionFileList(function () {
                            if (systemInstructionSelect) {
                                systemInstructionSelect.value = created;
                                if (providerSelect && modelSelect) {
                                    persistInstructionFileToServer(providerSelect.value, modelSelect.value, created);
                                }
                            }
                        });
                        if (typeof window.MemoryGraphRefresh === 'function') window.MemoryGraphRefresh();
                    })
                    .catch(function () { window.alert('Network error'); })
                    .finally(function () { systemInstructionCreateBtn.disabled = false; });
            });
        }

        if (providerSelect) {
            providerSelect.addEventListener('change', function () {
                syncModelSelect();
                captureAgentSelection();
                persistAgentSelectionToServer(agentSelProvider, agentSelModel);
                syncInstructionSelectToCurrentKey();
            });
        }
        if (modelSelect) {
            modelSelect.addEventListener('change', function () {
                captureAgentSelection();
                persistAgentSelectionToServer(agentSelProvider, agentSelModel);
                syncInstructionSelectToCurrentKey();
            });
        }
        syncModelSelect();
        captureAgentSelection();
        fetch('api/agent_config.php')
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                if (data) applyAgentConfig(data);
                loadInstructionFileList(function () { syncInstructionSelectToCurrentKey(); });
            })
            .catch(function () {});

        window.getAgentSettings = function () {
            return {
                provider: (providerSelect && providerSelect.value) || 'mercury',
                providerName: (window.MEMORY_GRAPH_PROVIDERS[(providerSelect && providerSelect.value) || 'mercury'] || {}).name || 'Mercury',
                model: (modelSelect && modelSelect.value) || 'mercury-2',
                systemPrompt: '',
                temperature: (temperatureInput && temperatureInput.value) !== '' ? parseFloat(temperatureInput.value) : 0.7
            };
        };
    })();
    </script>
    <script src="vendor/marked.min.js"></script>
    <script src="vendor/purify.min.js"></script>
    <script src="js/chat.js"></script>
    <script src="js/apps_panel.js"></script>
    <script src="js/ui_settings_simple.js"></script>
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
        var subAgentChatPanel = document.getElementById('sub-agent-chat-panel');
        var subAgentPromptInput = document.getElementById('sub-agent-prompt-input');
        var subAgentSendBtn = document.getElementById('sub-agent-send-btn');
        var subAgentRunResponse = document.getElementById('sub-agent-run-response');
        var subAgentChatTargetLabel = document.getElementById('sub-agent-chat-target-label');
        var cronConfig = document.getElementById('cron-config-panel');
        var cronDetailPre = document.getElementById('cron-detail-pre');
        var cronMessagePreview = document.getElementById('cron-message-preview');
        var cronRunNowBtn = document.getElementById('cron-run-now-btn');
        var cronToggleEnabledBtn = document.getElementById('cron-toggle-enabled-btn');
        var cronDeleteBtn = document.getElementById('cron-delete-btn');
        var toolSwitchEl = document.getElementById('tool-active-switch');
        var toolsListPanel = document.getElementById('tools-list-panel');
        var toolsEnableAllBtn = document.getElementById('tools-enable-all-btn');
        var toolsDisableAllBtn = document.getElementById('tools-disable-all-btn');
        var memorySwitchEl = document.getElementById('memory-active-switch');
        var memoryContentInput = document.getElementById('memory-content-input');
        var memorySaveBtn = document.getElementById('memory-save-btn');
        var memoryDeleteBtn = document.getElementById('memory-delete-btn');
        var instructionDeleteBtn = document.getElementById('instruction-delete-btn');
        var toolSaveBtn = document.getElementById('tool-save-btn');
        var toolDeleteBtn = document.getElementById('tool-delete-btn');
        var jobDeleteBtn = document.getElementById('job-delete-btn');
        var researchParentPanel = document.getElementById('research-parent-panel');
        var researchConfig = document.getElementById('research-config-panel');
        var researchListPanel = document.getElementById('research-list-panel');
        var researchContentInput = document.getElementById('research-content-input');
        var researchSaveBtn = document.getElementById('research-save-btn');
        var researchDeleteBtn = document.getElementById('research-delete-btn');
        var rulesParentPanel = document.getElementById('rules-parent-panel');
        var rulesConfig = document.getElementById('rules-config-panel');
        var rulesListPanel = document.getElementById('rules-list-panel');
        var rulesContentInput = document.getElementById('rules-content-input');
        var rulesSaveBtn = document.getElementById('rules-save-btn');
        var rulesDeleteBtn = document.getElementById('rules-delete-btn');
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
        window.currentOpenedCron = null;
        window.currentOpenedNodeId = null;
        window.currentOpenedSubAgent = null;

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
            if (researchParentPanel) researchParentPanel.style.display = 'none';
            if (researchConfig) researchConfig.style.display = 'none';
            if (rulesParentPanel) rulesParentPanel.style.display = 'none';
            if (rulesConfig) rulesConfig.style.display = 'none';
            if (mcpsParentPanel) mcpsParentPanel.style.display = 'none';
            if (mcpConfig) mcpConfig.style.display = 'none';
            if (jobConfig) jobConfig.style.display = 'none';
            if (cronConfig) cronConfig.style.display = 'none';
            if (subAgentChatPanel) subAgentChatPanel.style.display = 'none';
            window.currentOpenedTool = null;
            window.currentOpenedMemory = null;
            window.currentOpenedInstruction = null;
            window.currentOpenedResearch = null;
            window.currentOpenedRules = null;
            window.currentOpenedMcp = null;
            window.currentOpenedJob = null;
            window.currentOpenedCron = null;
            window.currentOpenedSubAgent = null;
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

        function refreshResearchData() {
            return fetch('api_research.php?action=list')
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    window.researchFiles = data.research || [];
                    return window.researchFiles;
                });
        }

        function refreshRulesData() {
            return fetch('api_rules.php?action=list')
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    window.rulesFiles = data.rules || [];
                    return window.rulesFiles;
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
            if (mcpsParentPanel) {
                mcpsParentPanel.style.display = 'block';
                renderMcpList();
            }
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

        function renderResearchList() {
            if (!researchListPanel) return;
            var files = window.researchFiles || [];
            researchListPanel.innerHTML = '';
            files.forEach(function (r) {
                var row = document.createElement('div');
                row.className = 'tool-list-item';
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'tool-list-name';
                btn.style.background = 'none';
                btn.style.border = 'none';
                btn.style.padding = '0';
                btn.style.textAlign = 'left';
                btn.style.cursor = 'pointer';
                btn.style.width = '100%';
                btn.textContent = r.title || r.name;
                btn.addEventListener('click', function () {
                    openWidget(r.title || r.name, r.nodeId);
                });
                row.appendChild(btn);
                researchListPanel.appendChild(row);
            });
            if (!files.length) {
                researchListPanel.innerHTML = '<div class="running-job-empty">No research files.</div>';
            }
        }

        function renderRulesList() {
            if (!rulesListPanel) return;
            var files = window.rulesFiles || [];
            rulesListPanel.innerHTML = '';
            files.forEach(function (r) {
                var row = document.createElement('div');
                row.className = 'tool-list-item';
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'tool-list-name';
                btn.style.background = 'none';
                btn.style.border = 'none';
                btn.style.padding = '0';
                btn.style.textAlign = 'left';
                btn.style.cursor = 'pointer';
                btn.style.width = '100%';
                btn.textContent = r.title || r.name;
                btn.addEventListener('click', function () {
                    openWidget(r.title || r.name, r.nodeId);
                });
                row.appendChild(btn);
                rulesListPanel.appendChild(row);
            });
            if (!files.length) {
                rulesListPanel.innerHTML = '<div class="running-job-empty">No rules files.</div>';
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

        function loadResearchIntoPanel(name) {
            fetch('api_research.php?action=get&name=' + encodeURIComponent(name))
                .then(function (res) {
                    if (!res.ok) throw new Error('Research not found');
                    return res.json();
                })
                .then(function (research) {
                    if (!window.currentOpenedResearch || window.currentOpenedResearch.name !== research.name) return;
                    if (researchContentInput) researchContentInput.value = research.content || '';
                    infoEl.innerHTML = '<p class="mb-1"><strong>Research:</strong> ' + escapeHtml(research.name) + '</p>';
                })
                .catch(function () {
                    if (researchContentInput) researchContentInput.value = '';
                    if (window.currentOpenedResearch && window.currentOpenedResearch.name === name) {
                        infoEl.innerHTML = '<p class="mb-1"><strong>Research:</strong> ' + escapeHtml(name) + '</p><p class="mb-1 text-muted">Could not load contents.</p>';
                    }
                });
        }

        function loadRulesIntoPanel(name) {
            fetch('api_rules.php?action=get&name=' + encodeURIComponent(name))
                .then(function (res) {
                    if (!res.ok) throw new Error('Rules not found');
                    return res.json();
                })
                .then(function (rules) {
                    if (!window.currentOpenedRules || window.currentOpenedRules.name !== rules.name) return;
                    if (rulesContentInput) rulesContentInput.value = rules.content || '';
                    infoEl.innerHTML = '<p class="mb-1"><strong>Rules:</strong> ' + escapeHtml(rules.name) + '</p>';
                })
                .catch(function () {
                    if (rulesContentInput) rulesContentInput.value = '';
                    if (window.currentOpenedRules && window.currentOpenedRules.name === name) {
                        infoEl.innerHTML = '<p class="mb-1"><strong>Rules:</strong> ' + escapeHtml(name) + '</p><p class="mb-1 text-muted">Could not load contents.</p>';
                    }
                });
        }

        function loadMcpTools(name, nocache) {
            if (!mcpToolsDisplay) return;
            mcpToolsDisplay.textContent = 'Loading MCP tools...';
            var q = 'api_mcps.php?action=tools&name=' + encodeURIComponent(name) + (nocache ? '&nocache=1' : '');
            fetch(q)
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
                .then(function (res) {
                    return res.json().then(function (data) {
                        return { ok: res.ok, data: data };
                    });
                })
                .then(function (result) {
                    if (!window.currentOpenedMcp) return;
                    var server = result.data;
                    if (!result.ok || !server || server.error) {
                        var errMsg = (server && server.error) ? server.error : 'Could not load MCP server.';
                        infoEl.innerHTML = '<p class="mb-1"><strong>MCP Server:</strong> ' + escapeHtml(name) + '</p><p class="mb-1 text-muted">' + escapeHtml(errMsg) + '</p>';
                        openMcpConfigPanel(null);
                        if (mcpNameInput) mcpNameInput.value = name || '';
                        return;
                    }
                    var opened = window.currentOpenedMcp;
                    if (opened.originalName !== server.name && opened.name !== server.name) return;
                    infoEl.innerHTML = '<p class="mb-1"><strong>MCP Server:</strong> ' + escapeHtml(server.name) + '</p><p class="mb-1"><strong>Transport:</strong> ' + escapeHtml(server.transport || 'stdio') + '</p>';
                    openMcpConfigPanel(server);
                })
                .catch(function () {
                    if (!window.currentOpenedMcp) return;
                    infoEl.innerHTML = '<p class="mb-1"><strong>MCP Server:</strong> ' + escapeHtml(name) + '</p><p class="mb-1 text-muted">Could not load MCP server.</p>';
                    openMcpConfigPanel(null);
                    if (mcpNameInput) mcpNameInput.value = name || '';
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
                infoEl.innerHTML = '<p class="mb-1"><strong>Reference:</strong> Jarvis Settings</p>';
                if (agentConfig) agentConfig.style.display = 'block';
            } else if (id === 'sub_agents') {
                var subAgents = window.subAgentFiles || [];
                infoEl.innerHTML = '<p class="mb-1"><strong>Sub-Agents:</strong> ' + subAgents.length + ' configuration files</p>';
            } else if (id && id.indexOf('sub_agent_file_') === 0) {
                var subAgent = (window.subAgentFiles || []).find(function (s) { return s.nodeId === id; });
                var subName = subAgent ? subAgent.name : refName;
                var provider = subAgent ? (subAgent.provider || '') : '';
                var model = subAgent ? (subAgent.model || '') : '';
                var dashUrl = subAgent && subAgent.dashboard_url ? String(subAgent.dashboard_url).trim() : '';
                var linkHtml = '';
                if (dashUrl && /^https?:\/\//i.test(dashUrl)) {
                    var safeHref = dashUrl.replace(/"/g, '%22');
                    linkHtml = '<p class="mb-1"><a class="font-serif" href="' + safeHref + '" target="_blank" rel="noopener noreferrer">Sub-agent link</a></p>';
                }
                titleEl.textContent = 'Sub-Agent';
                infoEl.innerHTML = '<p class="mb-1"><strong>Sub-Agent:</strong> ' + escapeHtml(subName) + '</p>'
                    + '<p class="mb-1 text-muted" style="font-size:0.85rem">Provider: ' + escapeHtml(provider || '(unset)') + ' | Model: ' + escapeHtml(model || '(unset)') + '</p>'
                    + '<p class="mb-1 text-muted" style="font-size:0.82rem">Uses the same tools, memory, research, instructions, rules, and MCP as Jarvis when <code>MEMORYGRAPH_PUBLIC_BASE_URL</code> is set (see sub-agent config).</p>'
                    + linkHtml;
                if (subAgentChatPanel) {
                    subAgentChatPanel.style.display = 'block';
                    if (subAgentPromptInput) subAgentPromptInput.value = '';
                    if (subAgentRunResponse) {
                        subAgentRunResponse.textContent = '';
                        subAgentRunResponse.classList.remove('is-error');
                    }
                    if (subAgentChatTargetLabel) {
                        subAgentChatTargetLabel.textContent = subAgent && subAgent.title ? subAgent.title : (subName || 'this agent');
                    }
                    window.currentOpenedSubAgent = {
                        nodeId: id,
                        name: subName
                    };
                }
            } else if (id === 'tools') {
                var tools = window.toolsData || [];
                infoEl.innerHTML = '<p class="mb-1"><strong>Tools:</strong> ' + tools.length + ' available</p>';
                if (toolsParentPanel) {
                    toolsParentPanel.style.display = 'block';
                    renderToolsList();
                }
            } else if (id === 'research') {
                var research = window.researchFiles || [];
                infoEl.innerHTML = '<p class="mb-1"><strong>Research:</strong> ' + research.length + ' files</p>';
                if (researchParentPanel) {
                    researchParentPanel.style.display = 'block';
                    renderResearchList();
                }
            } else if (id === 'rules') {
                var rules = window.rulesFiles || [];
                infoEl.innerHTML = '<p class="mb-1"><strong>Rules:</strong> ' + rules.length + ' files</p>';
                if (rulesParentPanel) {
                    rulesParentPanel.style.display = 'block';
                    renderRulesList();
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
                    if (toolSaveBtn) toolSaveBtn.disabled = tool ? !!tool.builtin : true;
                    if (toolDeleteBtn) toolDeleteBtn.disabled = tool ? !!tool.builtin : true;
                    if (toolCodeEl) toolCodeEl.value = tool && tool.code ? tool.code : '// No PHP script found in tools/';
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
            } else if (id && id.indexOf('research_file_') === 0) {
                var research = (window.researchFiles || []).find(function (r) { return r.nodeId === id; });
                var researchName = research ? research.name : refName;
                infoEl.innerHTML = '<p class="mb-1"><strong>Research:</strong> ' + escapeHtml(researchName) + '</p>';
                if (researchConfig) {
                    researchConfig.style.display = 'block';
                    window.currentOpenedResearch = {
                        id: id,
                        name: researchName
                    };
                    if (researchContentInput) researchContentInput.value = '';
                    loadResearchIntoPanel(researchName);
                }
            } else if (id && id.indexOf('rules_file_') === 0) {
                var rules = (window.rulesFiles || []).find(function (r) { return r.nodeId === id; });
                var rulesName = rules ? rules.name : refName;
                infoEl.innerHTML = '<p class="mb-1"><strong>Rules:</strong> ' + escapeHtml(rulesName) + '</p>';
                if (rulesConfig) {
                    rulesConfig.style.display = 'block';
                    window.currentOpenedRules = {
                        id: id,
                        name: rulesName
                    };
                    if (rulesContentInput) rulesContentInput.value = '';
                    loadRulesIntoPanel(rulesName);
                }
            } else if (id && id.indexOf('mcp_server_') === 0) {
                var server = (window.mcpServers || []).find(function (item) { return item.nodeId === id; });
                var serverName = server ? server.name : refName;
                infoEl.innerHTML = '<p class="mb-1"><strong>MCP Server:</strong> ' + escapeHtml(serverName) + '</p>';
                if (mcpConfig) {
                    mcpConfig.style.display = 'block';
                    if (mcpsParentPanel) {
                        mcpsParentPanel.style.display = 'block';
                        renderMcpList();
                    }
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
            } else if (id && id.indexOf('job_cron_') === 0) {
                var cron = (window.cronJobs || []).find(function (c) { return c.nodeId === id; });
                function fillCronPanel(c) {
                    if (!c || !cronConfig) return;
                    infoEl.innerHTML = '<p class="mb-1"><strong>Scheduled job:</strong> ' + escapeHtml(c.name || refName) + '</p>';
                    cronConfig.style.display = 'block';
                    window.currentOpenedCron = { id: c.id, nodeId: c.nodeId, name: c.name };
                    if (cronDetailPre) {
                        cronDetailPre.textContent = JSON.stringify({
                            id: c.id,
                            schedule: c.schedule,
                            enabled: c.enabled,
                            createdAt: c.createdAt,
                            updatedAt: c.updatedAt,
                            runtime: c.runtime
                        }, null, 2);
                    }
                    if (cronMessagePreview) {
                        cronMessagePreview.textContent = c.messagePreview || '(no preview)';
                    }
                    var on = c.enabled !== false && c.active !== false;
                    if (cronToggleEnabledBtn) cronToggleEnabledBtn.textContent = on ? 'Disable' : 'Enable';
                }
                if (cron) {
                    fillCronPanel(cron);
                } else {
                    infoEl.innerHTML = '<p class="mb-1"><strong>Scheduled job</strong></p><p class="text-muted" style="font-size:0.85rem">Loading…</p>';
                    fetch('api/cron.php?action=list')
                        .then(function (res) { return res.json(); })
                        .then(function (data) {
                            window.cronJobs = data.jobs || [];
                            var c2 = (window.cronJobs || []).find(function (x) { return x.nodeId === id; });
                            if (c2 && window.currentOpenedNodeId === id) fillCronPanel(c2);
                            else infoEl.innerHTML = '<p class="mb-1">Scheduled job</p><p class="text-muted">Not found. Refresh the graph.</p>';
                        })
                        .catch(function () {
                            infoEl.innerHTML = '<p class="mb-1">Scheduled job</p><p class="text-muted">Could not load cron list.</p>';
                        });
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
            window.currentOpenedCron = null;
            window.currentOpenedSubAgent = null;
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
        if (toolSaveBtn) {
            toolSaveBtn.addEventListener('click', function () {
                if (!window.currentOpenedTool || !toolCodeEl) return;
                var tool = (window.toolsData || []).find(function (t) { return t.name === window.currentOpenedTool; });
                if (tool && tool.builtin) return;
                toolSaveBtn.disabled = true;
                fetch('api_tools.php?action=save_code', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ name: window.currentOpenedTool, code: toolCodeEl.value })
                }).then(function (res) { return res.json(); })
                .then(function (result) {
                    if (result && result.error) throw new Error(result.error);
                    if (typeof window.MemoryGraphRefresh === 'function') window.MemoryGraphRefresh();
                    return fetch('api_tools.php?action=list').then(function (r) { return r.json(); });
                }).then(function (data) {
                    window.toolsData = data.tools || [];
                    var refreshedTool = (window.toolsData || []).find(function (t) { return t.name === window.currentOpenedTool; });
                    if (refreshedTool && toolCodeEl) toolCodeEl.value = refreshedTool.code || '';
                    infoEl.innerHTML = '<p class="mb-1"><strong>Tool:</strong> ' + escapeHtml(window.currentOpenedTool) + '</p><p class="mb-1" style="color:#16a34a">Code saved.</p>';
                }).catch(function (err) {
                    infoEl.innerHTML = '<p class="mb-1"><strong>Tool:</strong> ' + escapeHtml(err && err.message ? err.message : 'Save failed') + '</p>';
                }).finally(function () {
                    toolSaveBtn.disabled = false;
                });
            });
        }
        if (toolDeleteBtn) {
            toolDeleteBtn.addEventListener('click', function () {
                if (!window.currentOpenedTool) return;
                var tool = (window.toolsData || []).find(function (t) { return t.name === window.currentOpenedTool; });
                if (tool && tool.builtin) {
                    infoEl.innerHTML = '<p class="mb-1"><strong>Tool:</strong> Built-in tools cannot be deleted.</p>';
                    return;
                }
                if (!confirm('Delete tool "' + window.currentOpenedTool + '"?')) return;
                toolDeleteBtn.disabled = true;
                fetch('api_tools.php?action=delete', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ name: window.currentOpenedTool })
                }).then(function (res) { return res.json(); })
                .then(function (result) {
                    if (result && result.error) throw new Error(result.error);
                    return refreshToolsData();
                }).then(function () {
                    refreshGraph();
                    closeWidget();
                }).catch(function (err) {
                    infoEl.innerHTML = '<p class="mb-1"><strong>Tool:</strong> ' + escapeHtml(err && err.message ? err.message : 'Delete failed') + '</p>';
                }).finally(function () {
                    toolDeleteBtn.disabled = false;
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
        if (memoryDeleteBtn) {
            memoryDeleteBtn.addEventListener('click', function () {
                if (!window.currentOpenedMemory) return;
                if (!confirm('Delete memory file "' + window.currentOpenedMemory.name + '"?')) return;
                memoryDeleteBtn.disabled = true;
                fetch('api_memory.php?action=delete', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ name: window.currentOpenedMemory.name })
                }).then(function (res) { return res.json(); })
                .then(function (result) {
                    if (result && result.error) throw new Error(result.error);
                    return refreshMemoryData();
                }).then(function () {
                    refreshGraph();
                    closeWidget();
                }).catch(function (err) {
                    infoEl.innerHTML = '<p class="mb-1"><strong>Memory:</strong> ' + escapeHtml(err && err.message ? err.message : 'Delete failed') + '</p>';
                }).finally(function () {
                    memoryDeleteBtn.disabled = false;
                });
            });
        }
        if (instructionDeleteBtn) {
            instructionDeleteBtn.addEventListener('click', function () {
                if (!window.currentOpenedInstruction) return;
                if (!confirm('Delete instruction file "' + window.currentOpenedInstruction.name + '"?')) return;
                instructionDeleteBtn.disabled = true;
                fetch('api_instructions.php?action=delete', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ name: window.currentOpenedInstruction.name })
                }).then(function (res) { return res.json(); })
                .then(function (result) {
                    if (result && result.error) throw new Error(result.error);
                    return fetch('api_instructions.php?action=list').then(function (r) { return r.json(); });
                }).then(function (d) {
                    window.instructionFiles = d.instructions || [];
                    refreshGraph();
                    closeWidget();
                }).catch(function (err) {
                    infoEl.innerHTML = '<p class="mb-1"><strong>Instruction:</strong> ' + escapeHtml(err && err.message ? err.message : 'Delete failed') + '</p>';
                }).finally(function () {
                    instructionDeleteBtn.disabled = false;
                });
            });
        }
        if (researchSaveBtn) {
            researchSaveBtn.addEventListener('click', function () {
                if (!window.currentOpenedResearch || !researchContentInput) return;
                researchSaveBtn.disabled = true;
                fetch('api_research.php?action=save', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        name: window.currentOpenedResearch.name,
                        content: researchContentInput.value
                    })
                }).then(function (res) { return res.json(); })
                .then(function (research) {
                    if (research && research.error) throw new Error(research.error);
                    return refreshResearchData();
                }).then(function () {
                    infoEl.innerHTML = '<p class="mb-1"><strong>Research:</strong> ' + escapeHtml(window.currentOpenedResearch.name) + '</p><p class="mb-1">Saved.</p>';
                    refreshGraph();
                }).catch(function (err) {
                    infoEl.innerHTML = '<p class="mb-1"><strong>Research:</strong> ' + escapeHtml(err && err.message ? err.message : 'Save failed') + '</p>';
                }).finally(function () {
                    researchSaveBtn.disabled = false;
                });
            });
        }
        if (researchDeleteBtn) {
            researchDeleteBtn.addEventListener('click', function () {
                if (!window.currentOpenedResearch) return;
                if (!confirm('Delete research file "' + window.currentOpenedResearch.name + '"?')) return;
                researchDeleteBtn.disabled = true;
                fetch('api_research.php?action=delete', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ name: window.currentOpenedResearch.name })
                }).then(function (res) { return res.json(); })
                .then(function (result) {
                    if (result && result.error) throw new Error(result.error);
                    return refreshResearchData();
                }).then(function () {
                    refreshGraph();
                    closeWidget();
                }).catch(function (err) {
                    infoEl.innerHTML = '<p class="mb-1"><strong>Research:</strong> ' + escapeHtml(err && err.message ? err.message : 'Delete failed') + '</p>';
                }).finally(function () {
                    researchDeleteBtn.disabled = false;
                });
            });
        }
        if (rulesSaveBtn) {
            rulesSaveBtn.addEventListener('click', function () {
                if (!window.currentOpenedRules || !rulesContentInput) return;
                rulesSaveBtn.disabled = true;
                fetch('api_rules.php?action=save', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        name: window.currentOpenedRules.name,
                        content: rulesContentInput.value
                    })
                }).then(function (res) { return res.json(); })
                .then(function (rules) {
                    if (rules && rules.error) throw new Error(rules.error);
                    return refreshRulesData();
                }).then(function () {
                    infoEl.innerHTML = '<p class="mb-1"><strong>Rules:</strong> ' + escapeHtml(window.currentOpenedRules.name) + '</p><p class="mb-1">Saved.</p>';
                    refreshGraph();
                }).catch(function (err) {
                    infoEl.innerHTML = '<p class="mb-1"><strong>Rules:</strong> ' + escapeHtml(err && err.message ? err.message : 'Save failed') + '</p>';
                }).finally(function () {
                    rulesSaveBtn.disabled = false;
                });
            });
        }
        if (rulesDeleteBtn) {
            rulesDeleteBtn.addEventListener('click', function () {
                if (!window.currentOpenedRules) return;
                if (!confirm('Delete rules file "' + window.currentOpenedRules.name + '"?')) return;
                rulesDeleteBtn.disabled = true;
                fetch('api_rules.php?action=delete', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ name: window.currentOpenedRules.name })
                }).then(function (res) { return res.json(); })
                .then(function (result) {
                    if (result && result.error) throw new Error(result.error);
                    return refreshRulesData();
                }).then(function () {
                    refreshGraph();
                    closeWidget();
                }).catch(function (err) {
                    infoEl.innerHTML = '<p class="mb-1"><strong>Rules:</strong> ' + escapeHtml(err && err.message ? err.message : 'Delete failed') + '</p>';
                }).finally(function () {
                    rulesDeleteBtn.disabled = false;
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
                loadMcpTools(window.currentOpenedMcp.originalName, true);
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
                        if (mcpsParentPanel) mcpsParentPanel.style.display = 'block';
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
        if (subAgentSendBtn) {
            subAgentSendBtn.addEventListener('click', function () {
                if (!window.currentOpenedSubAgent || !window.currentOpenedSubAgent.name) return;
                var prompt = (subAgentPromptInput && subAgentPromptInput.value || '').trim();
                if (!prompt) {
                    if (subAgentRunResponse) {
                        subAgentRunResponse.classList.add('is-error');
                        subAgentRunResponse.textContent = 'Enter a prompt first.';
                    }
                    return;
                }
                var sid = (typeof window.MemoryGraphGetChatSessionId === 'function') ? window.MemoryGraphGetChatSessionId() : '';
                var statusReqId = 'subagent_panel_' + Date.now() + '_' + Math.floor(Math.random() * 100000);
                if (typeof window.MemoryGraphStartAdhocStatusPoll === 'function') {
                    window.MemoryGraphStartAdhocStatusPoll(statusReqId);
                }
                subAgentSendBtn.disabled = true;
                if (subAgentRunResponse) {
                    subAgentRunResponse.classList.remove('is-error');
                    subAgentRunResponse.textContent = 'Running…';
                }
                fetch('api/sub_agent_run.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        name: window.currentOpenedSubAgent.name,
                        prompt: prompt,
                        chatSessionId: sid,
                        statusRequestId: statusReqId
                    })
                }).then(function (res) {
                    return res.text().then(function (t) {
                        var j = null;
                        try {
                            j = t ? JSON.parse(t) : null;
                        } catch (e) {}
                        return { ok: res.ok, status: res.status, body: j, raw: t };
                    });
                }).then(function (out) {
                    if (!subAgentRunResponse) return;
                    var j = out.body;
                    if (!out.ok || (j && j.error)) {
                        subAgentRunResponse.classList.add('is-error');
                        var msg = (j && j.error) ? String(j.error) : (out.raw || ('HTTP ' + out.status));
                        subAgentRunResponse.textContent = msg;
                        return;
                    }
                    if (j && typeof j.response === 'string' && j.response.trim()) {
                        subAgentRunResponse.textContent = j.response;
                        return;
                    }
                    subAgentRunResponse.textContent = JSON.stringify(j, null, 2);
                }).catch(function (err) {
                    if (subAgentRunResponse) {
                        subAgentRunResponse.classList.add('is-error');
                        subAgentRunResponse.textContent = (err && err.message) ? err.message : 'Request failed.';
                    }
                }).finally(function () {
                    if (typeof window.MemoryGraphStopAdhocStatusPoll === 'function') {
                        window.MemoryGraphStopAdhocStatusPoll();
                    }
                    subAgentSendBtn.disabled = false;
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
        if (jobDeleteBtn) {
            jobDeleteBtn.addEventListener('click', function () {
                if (!window.currentOpenedJob) return;
                if (!confirm('Delete job file "' + window.currentOpenedJob.name + '"?')) return;
                jobDeleteBtn.disabled = true;
                fetch('api_jobs.php?action=delete', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ name: window.currentOpenedJob.name })
                }).then(function (res) { return res.json(); })
                .then(function (result) {
                    if (result && result.error) throw new Error(result.error);
                    return refreshJobsData();
                }).then(function () {
                    refreshGraph();
                    closeWidget();
                }).catch(function (err) {
                    infoEl.innerHTML = '<p class="mb-1"><strong>Job:</strong> ' + escapeHtml(err && err.message ? err.message : 'Delete failed') + '</p>';
                }).finally(function () {
                    jobDeleteBtn.disabled = false;
                });
            });
        }
        function memoryGraphCronPost(body) {
            return fetch('api/cron.php', {
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
        if (cronRunNowBtn) {
            cronRunNowBtn.addEventListener('click', function () {
                if (!window.currentOpenedCron || !window.currentOpenedCron.id) return;
                cronRunNowBtn.disabled = true;
                memoryGraphCronPost({ action: 'run', job_id: window.currentOpenedCron.id }).then(function (res) {
                    var sum = res && res.ran && res.ran.summary ? String(res.ran.summary) : '';
                    var ok = res && res.ok;
                    var sub = ok && sum ? '<p class="mb-1 text-muted" style="font-size:0.85rem">' + escapeHtml(sum.slice(0, 600)) + '</p>' : '';
                    infoEl.innerHTML = '<p class="mb-1"><strong>Scheduled:</strong> ' + (ok ? 'Run finished (see chat / pending cron notes).' : escapeHtml(res && res.error ? res.error : 'Failed')) + '</p>' + sub;
                }).catch(function (err) {
                    infoEl.innerHTML = '<p class="mb-1"><strong>Scheduled:</strong> ' + escapeHtml(err && err.message ? err.message : 'Request failed.') + '</p>';
                }).finally(function () { cronRunNowBtn.disabled = false; });
            });
        }
        if (cronToggleEnabledBtn) {
            cronToggleEnabledBtn.addEventListener('click', function () {
                if (!window.currentOpenedCron || !window.currentOpenedCron.id) return;
                var c = (window.cronJobs || []).find(function (x) { return x.id === window.currentOpenedCron.id; });
                var on = c ? (c.enabled !== false && c.active !== false) : true;
                cronToggleEnabledBtn.disabled = true;
                memoryGraphCronPost({ action: 'set_enabled', job_id: window.currentOpenedCron.id, enabled: !on }).then(function (res) {
                    if (!res || !res.ok) throw new Error(res && res.error ? res.error : 'Failed');
                    return fetch('api/cron.php?action=list').then(function (r) { return r.json(); });
                }).then(function (data) {
                    window.cronJobs = (data && data.jobs) ? data.jobs : [];
                    refreshGraph();
                    var c2 = (window.cronJobs || []).find(function (x) { return x.id === window.currentOpenedCron.id; });
                    if (c2 && cronToggleEnabledBtn) {
                        cronToggleEnabledBtn.textContent = (c2.enabled !== false && c2.active !== false) ? 'Disable' : 'Enable';
                    }
                }).catch(function (err) {
                    infoEl.innerHTML = '<p class="mb-1">' + escapeHtml(err && err.message ? err.message : 'Toggle failed') + '</p>';
                }).finally(function () { cronToggleEnabledBtn.disabled = false; });
            });
        }
        if (cronDeleteBtn) {
            cronDeleteBtn.addEventListener('click', function () {
                if (!window.currentOpenedCron || !window.currentOpenedCron.id) return;
                if (!confirm('Remove this scheduled job?')) return;
                cronDeleteBtn.disabled = true;
                memoryGraphCronPost({ action: 'remove_job', job_id: window.currentOpenedCron.id }).then(function (res) {
                    if (!res || !res.ok) throw new Error(res && res.error ? res.error : 'Failed');
                    refreshGraph();
                    closeWidget();
                }).catch(function (err) {
                    infoEl.innerHTML = '<p class="mb-1">' + escapeHtml(err && err.message ? err.message : 'Delete failed') + '</p>';
                }).finally(function () { cronDeleteBtn.disabled = false; });
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
<?php if (!empty($mgCronBrowserTick)) { ?>
<script>
(function () {
    function tick() {
        fetch('api/cron.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'tick' }),
            credentials: 'same-origin'
        }).catch(function () {});
    }
    setInterval(tick, 45000);
    setTimeout(tick, 8000);
})();
</script>
<?php } ?>
</body>
</html>
