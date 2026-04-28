/**
 * Multimodal attachments for main chat and sub-agent panel (OpenAI-style content parts).
 * Sets window.__mgAttachmentParts (array) consumed by chat.js / sub-agent send; themed strip + drag/drop.
 */
(function () {
    var MAX_FILES = 8;
    var MAX_BYTES = 7 * 1024 * 1024;
    var MAX_IMG_SIDE = 2048;
    var MAX_TEXT_INLINE = 120000;

    function uid() {
        return 'att_' + Date.now() + '_' + Math.random().toString(36).slice(2, 9);
    }

    function extOf(name) {
        var i = name.lastIndexOf('.');
        return i >= 0 ? name.slice(i + 1).toLowerCase() : '';
    }

    function downscaleDataUrl(dataUrl, mime, cb) {
        try {
            var img = new Image();
            img.onload = function () {
                var w = img.width;
                var h = img.height;
                if (!w || !h) {
                    cb(dataUrl);
                    return;
                }
                var scale = Math.min(1, MAX_IMG_SIDE / w, MAX_IMG_SIDE / h);
                if (scale >= 1) {
                    cb(dataUrl);
                    return;
                }
                var cw = Math.max(1, Math.round(w * scale));
                var ch = Math.max(1, Math.round(h * scale));
                var c = document.createElement('canvas');
                c.width = cw;
                c.height = ch;
                var ctx = c.getContext('2d');
                if (!ctx) {
                    cb(dataUrl);
                    return;
                }
                ctx.drawImage(img, 0, 0, cw, ch);
                var outMime = mime === 'image/png' ? 'image/png' : 'image/jpeg';
                var q = outMime === 'image/jpeg' ? 0.82 : undefined;
                try {
                    cb(c.toDataURL(outMime, q));
                } catch (e) {
                    cb(dataUrl);
                }
            };
            img.onerror = function () {
                cb(dataUrl);
            };
            img.src = dataUrl;
        } catch (e2) {
            cb(dataUrl);
        }
    }

    function fileToPart(file, cb) {
        if (file.size > MAX_BYTES) {
            cb({ error: file.name + ' is too large (max ' + Math.round(MAX_BYTES / 1048576) + ' MB).' });
            return;
        }
        var mime = file.type || 'application/octet-stream';
        var name = file.name || 'attachment';

        if (mime.indexOf('image/') === 0) {
            var r = new FileReader();
            r.onload = function () {
                var url = typeof r.result === 'string' ? r.result : '';
                downscaleDataUrl(url, mime, function (finalUrl) {
                    cb({
                        part: { type: 'image_url', image_url: { url: finalUrl } },
                        label: name,
                        kind: 'image'
                    });
                });
            };
            r.onerror = function () {
                cb({ error: 'Could not read ' + name });
            };
            r.readAsDataURL(file);
            return;
        }

        if (mime.indexOf('audio/') === 0) {
            var r2 = new FileReader();
            r2.onload = function () {
                var dataUrl = typeof r2.result === 'string' ? r2.result : '';
                var m = /^data:([^;]+);base64,(.+)$/.exec(dataUrl);
                if (!m) {
                    cb({ error: 'Could not encode audio for ' + name });
                    return;
                }
                var fmt = extOf(name);
                if (fmt === 'mp3' || mime.indexOf('mpeg') >= 0) fmt = 'mp3';
                else if (fmt === 'wav') fmt = 'wav';
                else if (fmt === 'webm' || mime.indexOf('webm') >= 0) fmt = 'webm';
                else if (fmt === 'ogg' || mime.indexOf('ogg') >= 0) fmt = 'ogg';
                else fmt = 'wav';
                cb({
                    part: { type: 'input_audio', input_audio: { data: m[2], format: fmt } },
                    label: name,
                    kind: 'audio'
                });
            };
            r2.onerror = function () {
                cb({ error: 'Could not read ' + name });
            };
            r2.readAsDataURL(file);
            return;
        }

        if (mime.indexOf('video/') === 0 && file.size <= 1536 * 1024) {
            var r3 = new FileReader();
            r3.onload = function () {
                var dataUrl = typeof r3.result === 'string' ? r3.result : '';
                var m3 = /^data:([^;]+);base64,(.+)$/.exec(dataUrl);
                if (!m3) {
                    cb({ error: 'Could not encode video for ' + name });
                    return;
                }
                var vf = extOf(name) || 'mp4';
                cb({
                    part: { type: 'input_video', input_video: { data: m3[2], format: vf, mime_type: mime } },
                    label: name,
                    kind: 'video'
                });
            };
            r3.onerror = function () {
                cb({ error: 'Could not read ' + name });
            };
            r3.readAsDataURL(file);
            return;
        }

        if (mime.indexOf('text/') === 0 || /\.(txt|md|csv|json|log|xml|html?)$/i.test(name)) {
            var r4 = new FileReader();
            r4.onload = function () {
                var t = typeof r4.result === 'string' ? r4.result : '';
                if (t.length > MAX_TEXT_INLINE) {
                    t = t.slice(0, MAX_TEXT_INLINE) + '\n\n[Truncated]';
                }
                cb({
                    part: { type: 'text', text: 'Attached file `' + name + '`:\n```\n' + t + '\n```' },
                    label: name,
                    kind: 'text'
                });
            };
            r4.onerror = function () {
                cb({ error: 'Could not read ' + name });
            };
            r4.readAsText(file);
            return;
        }

        var r5 = new FileReader();
        r5.onload = function () {
            var dataUrl = typeof r5.result === 'string' ? r5.result : '';
            var m5 = /^data:([^;]+);base64,(.+)$/.exec(dataUrl);
            if (!m5 || m5[2].length > 400000) {
                cb({
                    part: {
                        type: 'text',
                        text: 'User attached binary file `' + name + '` (' + mime + ', ' + file.size + ' bytes). It was not inlined; describe what you need or ask the user to export text.'
                    },
                    label: name,
                    kind: 'file'
                });
                return;
            }
            cb({
                part: {
                    type: 'text',
                    text: 'Attached file `' + name + '` (base64, ' + mime + '):\n' + m5[2].slice(0, 48000) + (m5[2].length > 48000 ? '\n...[base64 truncated]' : '')
                },
                label: name,
                kind: 'file'
            });
        };
        r5.onerror = function () {
            cb({ error: 'Could not read ' + name });
        };
        r5.readAsDataURL(file);
    }

    function createController(rootEl, stripEl, fileInput, pickBtn, overlayEl) {
        var items = [];
        var subAgentRef = null;

        function render() {
            if (!stripEl) return;
            stripEl.innerHTML = '';
            if (subAgentRef && subAgentRef.stem) {
                var subChip = document.createElement('span');
                subChip.className = 'mg-attach-chip mg-attach-chip--subagent';
                subChip.setAttribute('data-subagent', subAgentRef.stem);
                subChip.appendChild(document.createTextNode('Sub-agent: ' + (subAgentRef.label || subAgentRef.stem)));
                var srm = document.createElement('button');
                srm.type = 'button';
                srm.className = 'mg-attach-chip-remove';
                srm.setAttribute('aria-label', 'Remove sub-agent target');
                srm.textContent = '×';
                srm.addEventListener('click', function () {
                    subAgentRef = null;
                    render();
                });
                subChip.appendChild(srm);
                stripEl.appendChild(subChip);
            }
            items.forEach(function (it) {
                var chip = document.createElement('span');
                chip.className = 'mg-attach-chip';
                chip.textContent = it.kind + ': ' + (it.label || 'file');
                var rm = document.createElement('button');
                rm.type = 'button';
                rm.className = 'mg-attach-chip-remove';
                rm.setAttribute('aria-label', 'Remove attachment');
                rm.textContent = '×';
                rm.addEventListener('click', function () {
                    items = items.filter(function (y) {
                        return y.id !== it.id;
                    });
                    render();
                });
                chip.appendChild(rm);
                stripEl.appendChild(chip);
            });
        }

        function addFileList(fileList) {
            var arr = Array.prototype.slice.call(fileList || [], 0);
            if (!arr.length) return Promise.resolve();
            if (items.length + arr.length > MAX_FILES) {
                return Promise.reject(new Error('Maximum ' + MAX_FILES + ' attachments.'));
            }
            var chain = Promise.resolve();
            arr.forEach(function (file) {
                chain = chain.then(function () {
                    return new Promise(function (resolve, reject) {
                        fileToPart(file, function (res) {
                            if (res.error) {
                                reject(new Error(res.error));
                                return;
                            }
                            items.push({ id: uid(), part: res.part, label: res.label, kind: res.kind });
                            render();
                            resolve();
                        });
                    });
                });
            });
            return chain;
        }

        function clear() {
            items = [];
            subAgentRef = null;
            render();
        }

        /**
         * Restore the attachment strip from prior API content parts (e.g. re-editing a queued item).
         */
        function setPartsFromApi(parts) {
            clear();
            if (!Array.isArray(parts) || !parts.length) {
                return;
            }
            parts.forEach(function (p) {
                if (!p || !p.type) {
                    return;
                }
                var kind = 'file';
                var label = 'Attachment';
                if (p.type === 'image_url') {
                    kind = 'image';
                    label = 'Image';
                } else if (p.type === 'input_audio') {
                    kind = 'audio';
                    label = (p.input_audio && p.input_audio.format) ? 'audio' : 'audio';
                } else if (p.type === 'input_video') {
                    kind = 'video';
                    label = 'Video';
                } else if (p.type === 'text') {
                    kind = 'text';
                    var t = String(p.text || '');
                    label = t.length > 36 ? t.slice(0, 33) + '…' : t;
                } else {
                    kind = 'file';
                    label = p.type;
                }
                items.push({ id: uid(), part: p, label: label, kind: kind });
            });
            render();
        }

        function getParts() {
            return items.map(function (x) {
                return x.part;
            });
        }

        function summaryLine() {
            if (!items.length) return '';
            return items.map(function (x) {
                return x.kind + ':' + (x.label || '');
            }).join(', ');
        }

        function setOverlay(on) {
            if (!overlayEl) return;
            overlayEl.style.display = on ? 'flex' : 'none';
            if (rootEl) rootEl.classList.toggle('mg-chat-composer--drag', !!on);
        }

        if (pickBtn && fileInput) {
            pickBtn.addEventListener('click', function () {
                fileInput.click();
            });
        }
        if (fileInput) {
            fileInput.addEventListener('change', function () {
                addFileList(fileInput.files).catch(function (e) {
                    alert(e && e.message ? e.message : String(e));
                });
                fileInput.value = '';
            });
        }

        if (rootEl) {
            ['dragenter', 'dragover'].forEach(function (ev) {
                rootEl.addEventListener(ev, function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    setOverlay(true);
                });
            });
            rootEl.addEventListener('dragleave', function (e) {
                if (!rootEl.contains(e.relatedTarget)) setOverlay(false);
            });
            rootEl.addEventListener('drop', function (e) {
                e.preventDefault();
                e.stopPropagation();
                setOverlay(false);
                var dt = e.dataTransfer;
                if (!dt || !dt.files) return;
                addFileList(dt.files).catch(function (err) {
                    alert(err && err.message ? err.message : String(err));
                });
            });
        }

        function setSubAgentRef(ref) {
            if (ref && ref.stem) {
                subAgentRef = { stem: String(ref.stem).replace(/\.md$/i, ''), label: ref.label || ref.stem };
            } else {
                subAgentRef = null;
            }
            render();
        }

        function getSubAgentRef() {
            return subAgentRef;
        }

        render();

        return {
            addFileList: addFileList,
            clear: clear,
            setParts: setPartsFromApi,
            render: render,
            getParts: getParts,
            summaryLine: summaryLine,
            setSubAgentRef: setSubAgentRef,
            getSubAgentRef: getSubAgentRef
        };
    }

    window.__mgMainChatAttachments = null;
    window.__mgSubAgentAttachments = null;

    function bindSharedPickerModal(mainCtrl, subCtrl) {
        var modal = document.getElementById('mg-shared-attach-modal');
        var drop = document.getElementById('mg-shared-attach-drop');
        var browse = document.getElementById('mg-shared-attach-browse');
        var closeBtn = document.getElementById('mg-shared-attach-close');
        var file = document.getElementById('mg-shared-attach-file');
        if (!modal || !drop || !browse || !closeBtn || !file) return;

        var active = null;

        function open(ctrl) {
            active = ctrl;
            modal.style.display = 'flex';
            modal.setAttribute('aria-hidden', 'false');
        }

        function shut() {
            active = null;
            modal.style.display = 'none';
            modal.setAttribute('aria-hidden', 'true');
            file.value = '';
        }

        function addToActive(list) {
            if (!active || !active.addFileList) return Promise.resolve();
            return active.addFileList(list);
        }

        browse.addEventListener('click', function () {
            file.click();
        });
        closeBtn.addEventListener('click', shut);
        modal.addEventListener('click', function (e) {
            if (e.target === modal) shut();
        });
        file.addEventListener('change', function () {
            addToActive(file.files).catch(function (e) {
                alert(e && e.message ? e.message : String(e));
            });
        });
        ['dragenter', 'dragover'].forEach(function (ev) {
            drop.addEventListener(ev, function (e) {
                e.preventDefault();
                drop.classList.add('mg-attach-modal-drop--active');
            });
        });
        drop.addEventListener('dragleave', function (e) {
            if (!drop.contains(e.relatedTarget)) drop.classList.remove('mg-attach-modal-drop--active');
        });
        drop.addEventListener('drop', function (e) {
            e.preventDefault();
            drop.classList.remove('mg-attach-modal-drop--active');
            var dt = e.dataTransfer;
            if (!dt || !dt.files) return;
            addToActive(dt.files).catch(function (err) {
                alert(err && err.message ? err.message : String(err));
            });
        });

        function wireOpen(btn, ctrl) {
            if (!btn || !ctrl) return;
            btn.addEventListener('click', function () {
                open(ctrl);
            });
        }

        wireOpen(document.getElementById('chat-attach-btn'), mainCtrl);
        wireOpen(document.getElementById('sub-agent-attach-btn'), subCtrl);
    }

    window.MemoryGraphInitChatAttachments = function () {
        if (window.__mgChatAttachmentsInited) {
            return;
        }
        window.__mgChatAttachmentsInited = true;
        var mainRoot = document.getElementById('main-chat-composer');
        var mainStrip = document.getElementById('chat-attachment-strip');
        var mainOverlay = document.getElementById('chat-drop-overlay');
        var mainPick = document.getElementById('chat-attach-btn');
        if (mainRoot && mainStrip && mainPick) {
            window.__mgMainChatAttachments = createController(mainRoot, mainStrip, null, null, mainOverlay);
        }

        var subRoot = document.getElementById('sub-agent-chat-composer');
        var subStrip = document.getElementById('sub-agent-attachment-strip');
        var subOverlay = document.getElementById('sub-agent-drop-overlay');
        var subPick = document.getElementById('sub-agent-attach-btn');
        if (subRoot && subStrip && subPick) {
            window.__mgSubAgentAttachments = createController(subRoot, subStrip, null, null, subOverlay);
        }

        bindSharedPickerModal(window.__mgMainChatAttachments, window.__mgSubAgentAttachments);
    };
})();
