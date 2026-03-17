/**
 * 3D graph: one main Agent node + fixed child nodes + dynamic tool, memory, instruction, MCP, and job child nodes.
 */
(function () {
    var container = document.getElementById('graph-container');
    if (!container || typeof THREE === 'undefined') return;

    var scene = new THREE.Scene();
    scene.fog = new THREE.FogExp2(0x05070a, 0.01);
    var camera = new THREE.PerspectiveCamera(60, window.innerWidth / window.innerHeight, 0.1, 1000);
    camera.position.set(11, 7, 20);
    var renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true });
    renderer.setSize(window.innerWidth, window.innerHeight);
    renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
    container.appendChild(renderer.domElement);

    var controls = null;
    if (typeof THREE.OrbitControls !== 'undefined') {
        controls = new THREE.OrbitControls(camera, renderer.domElement);
        controls.enableDamping = true;
        controls.dampingFactor = 0.05;
        controls.minDistance = 8;
        controls.maxDistance = 60;
        controls.target.set(0.2, -0.4, 0.6);
    }

    var staticNodeData = {
        agent: { label: 'Agent', color: 0xd9e4ff, highlight: true, radius: 0.58, glowScale: 1.95 },
        memory: { label: 'Memory', color: 0x47d7c9, sub: true, radius: 0.42, glowScale: 1.82 },
        tools: { label: 'Tools', color: 0xffc857, sub: true, radius: 0.42, glowScale: 1.82 },
        instructions: { label: 'Instructions', color: 0x7cb8ff, sub: true, radius: 0.42, glowScale: 1.82 },
        research: { label: 'Research', color: 0xb8a9e8, sub: true, radius: 0.42, glowScale: 1.82 },
        rules: { label: 'Rules', color: 0xe8a9b8, sub: true, radius: 0.42, glowScale: 1.82 },
        mcps: { label: 'MCPs', color: 0x6be38e, sub: true, radius: 0.42, glowScale: 1.82 },
        jobs: { label: 'Jobs', color: 0xff8f70, sub: true, radius: 0.42, glowScale: 1.82 },
        categories: { label: 'Categories', color: 0xa0d4e8, sub: true, radius: 0.42, glowScale: 1.82 }
    };
    var staticEdges = [
        { from: 'agent', to: 'memory' },
        { from: 'agent', to: 'tools' },
        { from: 'agent', to: 'instructions' },
        { from: 'agent', to: 'research' },
        { from: 'agent', to: 'rules' },
        { from: 'agent', to: 'mcps' },
        { from: 'agent', to: 'jobs' },
        { from: 'agent', to: 'categories' }
    ];
    var staticPositions = {
        agent: [0, 0, 0],
        memory: [-7.4, 4.6, 4.1],
        tools: [7.2, 3.8, -3.6],
        instructions: [-1.1, -7.6, 5.3],
        research: [-5.2, -6.2, 3.8],
        rules: [5.2, -6.2, 3.8],
        mcps: [-8.1, -3.6, -4.8],
        jobs: [8.3, -2.9, 4.4],
        categories: [0, 7.2, -2.2]
    };

    var galaxyGroup = new THREE.Group();
    var nodeGroup = new THREE.Group();
    var edgeGroup = new THREE.Group();
    scene.add(galaxyGroup);
    scene.add(edgeGroup);
    scene.add(nodeGroup);

    var nodeGroups = {};
    var nodeMeshes = {};
    var nodeGlowById = {};
    var nodeMetaById = {};
    var nodeMeshesList = [];
    var runtimeActivityByNodeId = {};

    function createGalaxyBackground() {
        var starCount = 900;
        var positions = new Float32Array(starCount * 3);
        var colors = new Float32Array(starCount * 3);
        for (var i = 0; i < starCount; i++) {
            var radius = 32 + Math.random() * 90;
            var theta = Math.random() * Math.PI * 2;
            var phi = Math.acos((Math.random() * 2) - 1);
            var shade = 0.6 + Math.random() * 0.4;
            positions[i * 3] = radius * Math.sin(phi) * Math.cos(theta);
            positions[i * 3 + 1] = radius * Math.cos(phi) * 0.72;
            positions[i * 3 + 2] = radius * Math.sin(phi) * Math.sin(theta);
            colors[i * 3] = shade;
            colors[i * 3 + 1] = shade;
            colors[i * 3 + 2] = Math.min(1, shade + 0.08);
        }
        var geometry = new THREE.BufferGeometry();
        geometry.setAttribute('position', new THREE.BufferAttribute(positions, 3));
        geometry.setAttribute('color', new THREE.BufferAttribute(colors, 3));
        galaxyGroup.add(new THREE.Points(geometry, new THREE.PointsMaterial({
            size: 0.2,
            transparent: true,
            opacity: 0.9,
            depthWrite: false,
            vertexColors: true
        })));
    }

    function clearGraph() {
        while (nodeGroup.children.length) nodeGroup.remove(nodeGroup.children[0]);
        while (edgeGroup.children.length) edgeGroup.remove(edgeGroup.children[0]);
        nodeGroups = {};
        nodeMeshes = {};
        nodeGlowById = {};
        nodeMetaById = {};
        nodeMeshesList = [];
        if (window.agentState) {
            window.agentState.nodeGroups = {};
            window.agentState.parentAgentNode = null;
        }
    }

    function createNode(id, data, pos) {
        var radius = data.radius || 0.24;
        var group = new THREE.Group();
        group.position.set(pos[0], pos[1], pos[2]);
        group.userData.clickedUntil = 0;
        group.userData.disabled = data.active === false;

        var glow = new THREE.Mesh(
            new THREE.SphereGeometry(radius * (data.glowScale || 1.8), 24, 24),
            new THREE.MeshBasicMaterial({
                color: data.color,
                transparent: true,
                opacity: 0.2,
                blending: THREE.AdditiveBlending,
                depthWrite: false
            })
        );
        glow.renderOrder = -1;
        group.add(glow);

        var shell = new THREE.Mesh(
            new THREE.SphereGeometry(radius * 1.02, 24, 24),
            new THREE.MeshPhysicalMaterial({
                color: data.color,
                emissive: data.color,
                emissiveIntensity: 0.2,
                roughness: 0.22,
                metalness: 0.94,
                clearcoat: 1,
                clearcoatRoughness: 0.08,
                transparent: true,
                opacity: 0.82
            })
        );
        group.add(shell);

        var core = new THREE.Mesh(
            new THREE.SphereGeometry(radius * 0.78, 24, 24),
            new THREE.MeshPhongMaterial({
                color: 0x0c1016,
                emissive: data.color,
                emissiveIntensity: id === 'agent' ? 0.95 : 0.82,
                specular: 0xffffff,
                shininess: 110
            })
        );
        core.userData = { id: id, label: data.label };
        group.add(core);

        var inner = new THREE.Mesh(
            new THREE.SphereGeometry(radius * 0.3, 16, 16),
            new THREE.MeshBasicMaterial({
                color: data.color,
                transparent: true,
                opacity: 0.24
            })
        );
        group.add(inner);

        nodeGroup.add(group);
        nodeGroups[id] = group;
        nodeMeshes[id] = core;
        nodeGlowById[id] = glow;
        nodeMetaById[id] = {
            shellMesh: shell,
            innerMesh: inner,
            shellBaseColor: data.color,
            coreBaseColor: 0x0c1016,
            innerBaseColor: data.color,
            activeHighlightColor: id === 'agent' ? 0xffffff : 0xfff3b0
        };
        nodeMeshesList.push(core);

        if (window.agentState) {
            window.agentState.setNodeGroup(id, group);
            if (id === 'agent') window.agentState.setAgentNode(group);
        }
    }

    function createEdge(fromId, toId) {
        if (!nodeGroups[fromId] || !nodeGroups[toId]) return;
        var a = nodeGroups[fromId].position;
        var b = nodeGroups[toId].position;
        edgeGroup.add(new THREE.Line(
            new THREE.BufferGeometry().setFromPoints([new THREE.Vector3(a.x, a.y, a.z), new THREE.Vector3(b.x, b.y, b.z)]),
            new THREE.LineBasicMaterial({ color: 0xa9d4ff, transparent: true, opacity: 0.5 })
        ));
    }

    function layoutChildren(items, basePos, scaleX, scaleY, wave, idSelector, color) {
        var out = {};
        (items || []).forEach(function (item, i) {
            var count = Math.max(items.length, 1);
            var angle = (i / count) * Math.PI * 2;
            var dist = 3.8;
            var tilt = ((i % 3) - 1) * 0.8;
            var id = idSelector(item);
            out[id] = {
                node: {
                    label: item.title || item.name,
                    color: color,
                    sub: true,
                    active: item.active !== false,
                    radius: 0.24,
                    glowScale: 1.65
                },
                pos: [
                    basePos[0] + Math.cos(angle) * dist * scaleX,
                    basePos[1] + Math.sin(angle) * dist * scaleY + tilt,
                    basePos[2] + (wave === 'sin' ? Math.sin(angle * 1.5 + 0.4) : Math.cos(angle * 1.6 + 0.4)) * 2.15
                ]
            };
        });
        return out;
    }

    function buildGraph(tools, memories, instructions, research, rules, mcps, jobs, categories) {
        clearGraph();
        var nodeData = JSON.parse(JSON.stringify(staticNodeData));
        var positions = JSON.parse(JSON.stringify(staticPositions));
        var edges = staticEdges.slice();

        function mergeChildren(children, parentId) {
            Object.keys(children).forEach(function (id) {
                nodeData[id] = children[id].node;
                positions[id] = children[id].pos;
                edges.push({ from: parentId, to: id });
            });
        }

        mergeChildren(layoutChildren(tools || [], positions.tools, 0.95, 0.75, 'sin', function (tool) { return 'tool_' + tool.name; }, 0xffd36f), 'tools');
        mergeChildren(layoutChildren(memories || [], positions.memory, 0.9, 0.8, 'cos', function (memory) { return memory.nodeId; }, 0x59ead9), 'memory');
        mergeChildren(layoutChildren(instructions || [], positions.instructions, 0.9, 0.82, 'sin', function (instruction) { return instruction.nodeId; }, 0x8dc5ff), 'instructions');
        mergeChildren(layoutChildren(research || [], positions.research, 0.9, 0.82, 'cos', function (r) { return r.nodeId; }, 0xc4b5f0), 'research');
        mergeChildren(layoutChildren(rules || [], positions.rules, 0.9, 0.82, 'sin', function (r) { return r.nodeId; }, 0xf0b5c4), 'rules');
        mergeChildren(layoutChildren(mcps || [], positions.mcps, 0.9, 0.8, 'sin', function (mcp) { return mcp.nodeId; }, 0x85f2a8), 'mcps');
        mergeChildren(layoutChildren(jobs || [], positions.jobs, 0.92, 0.8, 'cos', function (job) { return job.nodeId; }, 0xff9f7f), 'jobs');
        mergeChildren(layoutChildren(categories || [], positions.categories, 0.9, 0.85, 'sin', function (c) { return c.nodeId; }, 0xb0e4f8), 'agent');

        Object.keys(nodeData).forEach(function (id) {
            createNode(id, nodeData[id], positions[id] || [0, 0, 0]);
        });
        edges.forEach(function (edge) { createEdge(edge.from, edge.to); });
    }

    function loadDynamicNodes() {
        return Promise.all([
            fetch('api_tools.php?action=list').then(function (res) { return res.json(); }),
            fetch('api_memory.php?action=list').then(function (res) { return res.json(); }),
            fetch('api_instructions.php?action=list').then(function (res) { return res.json(); }),
            fetch('api_research.php?action=list').then(function (res) { return res.json(); }),
            fetch('api_rules.php?action=list').then(function (res) { return res.json(); }),
            fetch('api_mcps.php?action=list').then(function (res) { return res.json(); }),
            fetch('api_jobs.php?action=list').then(function (res) { return res.json(); }),
            fetch('api_categories.php?action=list&_=' + Date.now()).then(function (res) { return res.json(); })
        ]).then(function (results) {
            window.toolsData = (results[0] || {}).tools || [];
            window.memoryFiles = (results[1] || {}).memories || [];
            window.instructionFiles = (results[2] || {}).instructions || [];
            window.researchFiles = (results[3] || {}).research || [];
            window.rulesFiles = (results[4] || {}).rules || [];
            window.mcpServers = (results[5] || {}).servers || [];
            window.jobFiles = (results[6] || {}).jobs || [];
            window.categoryNodes = (results[7] || {}).categories || [];
            buildGraph(window.toolsData, window.memoryFiles, window.instructionFiles, window.researchFiles, window.rulesFiles, window.mcpServers, window.jobFiles, window.categoryNodes);
        }).catch(function () {});
    }
    window.MemoryGraphRefresh = loadDynamicNodes;

    function markRuntimeActivity(ids, durationMs) {
        var until = performance.now() + Math.max(1600, parseInt(durationMs, 10) || 2400);
        (Array.isArray(ids) ? ids : []).forEach(function (id) {
            if (!id) return;
            runtimeActivityByNodeId[id] = until;
        });
    }

    function applyImmediateRuntimeAppearance(id) {
        var group = nodeGroups[id];
        var mesh = nodeMeshes[id];
        var glow = nodeGlowById[id];
        var meta = nodeMetaById[id] || null;
        if (!group || !mesh || !mesh.material) return;
        group.userData.clickedUntil = performance.now() + 2200;
        group.scale.set(1.46, 1.46, 1.46);
        mesh.material.emissiveIntensity = 2.4;
        if (glow && glow.material) glow.material.opacity = 1;
        if (meta && meta.shellMesh && meta.shellMesh.material) meta.shellMesh.material.emissiveIntensity = 1.05;
        if (meta && meta.innerMesh && meta.innerMesh.material) meta.innerMesh.material.opacity = 0.85;
    }

    var pendingActivityDetail = null;
    var activityRafId = null;
    function flushPendingActivity() {
        activityRafId = null;
        var detail = pendingActivityDetail;
        pendingActivityDetail = null;
        if (!detail) return;
        var sectionIds = Array.isArray(detail.sections) ? detail.sections : [];
        var nodeIds = Array.isArray(detail.nodeIds) ? detail.nodeIds : [];
        markRuntimeActivity(sectionIds, detail.durationMs);
        markRuntimeActivity(nodeIds, detail.durationMs);
        sectionIds.concat(nodeIds).forEach(applyImmediateRuntimeAppearance);
        renderer.render(scene, camera);
    }
    document.addEventListener('memoryGraphActivity', function (event) {
        var detail = event && event.detail ? event.detail : {};
        pendingActivityDetail = detail;
        if (activityRafId == null) {
            activityRafId = requestAnimationFrame(flushPendingActivity);
        }
    });
    window.MemoryGraphSignalActivity = function (detail) {
        document.dispatchEvent(new CustomEvent('memoryGraphActivity', {
            detail: detail && typeof detail === 'object' ? detail : {}
        }));
    };

    var raycaster = new THREE.Raycaster();
    var mouse = new THREE.Vector2();
    var lastTouchStart = null;
    function doPick(clientX, clientY) {
        var rect = renderer.domElement.getBoundingClientRect();
        mouse.x = ((clientX - rect.left) / rect.width) * 2 - 1;
        mouse.y = -((clientY - rect.top) / rect.height) * 2 + 1;
        scene.updateMatrixWorld(true);
        raycaster.setFromCamera(mouse, camera);
        var hits = raycaster.intersectObjects(nodeMeshesList, false);
        if (!hits.length) return false;
        var object = hits[0].object;
        if (!object.userData || !object.userData.id) return false;
        var id = object.userData.id;
        if (nodeGroups[id]) nodeGroups[id].userData.clickedUntil = performance.now() + 900;
        document.dispatchEvent(new CustomEvent('graphNodeClick', {
            detail: { id: id, label: object.userData.label },
            bubbles: true
        }));
        if (typeof window.MemoryGraphShowNodePanel === 'function') {
            window.MemoryGraphShowNodePanel(object.userData.label, id);
        }
        return true;
    }
    var el = renderer.domElement;
    el.addEventListener('mousedown', function (event) {
        if (doPick(event.clientX, event.clientY)) {
            event.preventDefault();
            event.stopPropagation();
        }
    }, true);
    el.addEventListener('click', function (event) {
        doPick(event.clientX, event.clientY);
    }, true);
    // Mobile: use touchstart position for pick (more reliable than touchend on some devices)
    el.addEventListener('touchstart', function (event) {
        if (event.touches && event.touches.length === 1) {
            lastTouchStart = { x: event.touches[0].clientX, y: event.touches[0].clientY };
        } else {
            lastTouchStart = null;
        }
    }, { passive: true, capture: true });
    el.addEventListener('touchend', function (event) {
        if (!event.changedTouches || !event.changedTouches.length) return;
        var x, y;
        if (lastTouchStart) {
            x = lastTouchStart.x;
            y = lastTouchStart.y;
            lastTouchStart = null;
        } else {
            var t = event.changedTouches[0];
            x = t.clientX;
            y = t.clientY;
        }
        if (doPick(x, y)) {
            event.preventDefault();
            event.stopPropagation();
        }
    }, { passive: false, capture: true });
    // Pointer events: fallback for devices that map touch to pointer
    el.addEventListener('pointerup', function (event) {
        if (event.pointerType === 'touch') return;
        doPick(event.clientX, event.clientY);
    }, true);

    scene.add(new THREE.AmbientLight(0xcfd6de, 0.62));
    var dir = new THREE.DirectionalLight(0xffffff, 0.28);
    dir.position.set(5, 8, 10);
    scene.add(dir);
    var point = new THREE.PointLight(0xbac4cf, 0.2, 46);
    point.position.set(-5, 5, 8);
    scene.add(point);
    createGalaxyBackground();

    function animateNode(id, enabled, t, speed, amp, base) {
        var group = nodeGroups[id];
        var mesh = nodeMeshes[id];
        var glow = nodeGlowById[id];
        var meta = nodeMetaById[id] || null;
        if (!group || !mesh || !mesh.material) return;
        var disabled = group.userData.disabled === true;
        if (disabled) {
            group.scale.set(1, 1, 1);
            var gray = 0x3a3a3a;
            var dimGlow = 0.25;
            if (meta && meta.shellMesh && meta.shellMesh.material) {
                meta.shellMesh.material.color.setHex(gray);
                if (meta.shellMesh.material.emissive) meta.shellMesh.material.emissive.setHex(gray);
                meta.shellMesh.material.emissiveIntensity = 0.05;
            }
            if (mesh.material) {
                mesh.material.color.setHex(0x2a2a2a);
                if (mesh.material.emissive) mesh.material.emissive.setHex(gray);
                mesh.material.emissiveIntensity = 0.08;
            }
            if (meta && meta.innerMesh && meta.innerMesh.material) {
                meta.innerMesh.material.color.setHex(gray);
                meta.innerMesh.material.opacity = 0.15;
            }
            if (glow && glow.material) glow.material.opacity = dimGlow;
            return;
        }
        var clicked = performance.now() < (group.userData.clickedUntil || 0);
        var runtimeActive = performance.now() < (runtimeActivityByNodeId[id] || 0);
        if (!runtimeActive && runtimeActivityByNodeId[id]) delete runtimeActivityByNodeId[id];
        if (enabled || clicked || runtimeActive) {
            var runtimeBoost = runtimeActive ? 0.18 : 0;
            var pulse = 1.04 + Math.sin(t * speed) * (amp + 0.08 + runtimeBoost) + (clicked ? 0.18 : 0) + (runtimeActive ? 0.12 : 0);
            group.scale.set(pulse, pulse, pulse);
            if (meta && meta.shellMesh && meta.shellMesh.material && meta.shellMesh.material.color) {
                meta.shellMesh.material.color.setHex(runtimeActive ? 0xffffff : meta.shellBaseColor);
                if (meta.shellMesh.material.emissive) meta.shellMesh.material.emissive.setHex(runtimeActive ? meta.activeHighlightColor : meta.shellBaseColor);
            }
            if (mesh.material && mesh.material.color) {
                mesh.material.color.setHex(runtimeActive ? meta.activeHighlightColor : meta.coreBaseColor);
                if (mesh.material.emissive) mesh.material.emissive.setHex(runtimeActive ? meta.activeHighlightColor : meta.shellBaseColor);
            }
            if (meta && meta.innerMesh && meta.innerMesh.material && meta.innerMesh.material.color) {
                meta.innerMesh.material.color.setHex(runtimeActive ? 0xffffff : meta.innerBaseColor);
            }
            mesh.material.emissiveIntensity = base + 0.35 + Math.sin(t * speed) * ((amp + 0.08 + runtimeBoost) * 4.2) + (clicked ? 0.5 : 0) + (runtimeActive ? 0.95 : 0);
            if (glow && glow.material) glow.material.opacity = Math.max(0.28, Math.min(1, 0.48 + Math.sin(t * speed) * 0.26 + (clicked ? 0.24 : 0) + (runtimeActive ? 0.28 : 0)));
            if (meta && meta.shellMesh && meta.shellMesh.material) meta.shellMesh.material.emissiveIntensity = clicked ? 0.72 : (runtimeActive ? 1.05 : 0.44);
            if (meta && meta.innerMesh && meta.innerMesh.material) meta.innerMesh.material.opacity = clicked ? 0.62 : (runtimeActive ? 0.82 : 0.38);
        } else if (id !== 'agent') {
            group.scale.set(1, 1, 1);
            if (meta && meta.shellMesh && meta.shellMesh.material && meta.shellMesh.material.color) {
                meta.shellMesh.material.color.setHex(meta.shellBaseColor);
                if (meta.shellMesh.material.emissive) meta.shellMesh.material.emissive.setHex(meta.shellBaseColor);
            }
            if (mesh.material && mesh.material.color) {
                mesh.material.color.setHex(meta.coreBaseColor);
                if (mesh.material.emissive) mesh.material.emissive.setHex(meta.shellBaseColor);
            }
            if (meta && meta.innerMesh && meta.innerMesh.material && meta.innerMesh.material.color) {
                meta.innerMesh.material.color.setHex(meta.innerBaseColor);
            }
            mesh.material.emissiveIntensity = 0.68;
            if (glow && glow.material) glow.material.opacity = 0.2;
            if (meta && meta.shellMesh && meta.shellMesh.material) meta.shellMesh.material.emissiveIntensity = 0.16;
            if (meta && meta.innerMesh && meta.innerMesh.material) meta.innerMesh.material.opacity = 0.18;
        }
    }

    var clock = new THREE.Clock();
    function animate() {
        requestAnimationFrame(animate);
        var t = clock.getElapsedTime();
        if (controls) controls.update();
        galaxyGroup.rotation.y += 0.0008;

        var state = window.agentState || null;
        var activeToolIds = state && Array.isArray(state.activeToolIds) ? state.activeToolIds.slice() : [];
        var activeMemoryIds = state && Array.isArray(state.activeMemoryIds) ? state.activeMemoryIds.slice() : [];
        var activeInstructionIds = state && Array.isArray(state.activeInstructionIds) ? state.activeInstructionIds.slice() : [];
        var activeResearchIds = state && Array.isArray(state.activeResearchIds) ? state.activeResearchIds.slice() : [];
        var activeRulesIds = state && Array.isArray(state.activeRulesIds) ? state.activeRulesIds.slice() : [];
        var activeMcpIds = state && Array.isArray(state.activeMcpIds) ? state.activeMcpIds.slice() : [];
        var activeJobIds = state && Array.isArray(state.activeJobIds) ? state.activeJobIds.slice() : [];
        var activeCategoryIds = state && Array.isArray(state.activeCategoryIds) ? state.activeCategoryIds.slice() : [];
        if (state) {
            activeToolIds = activeToolIds.concat(state.backgroundActiveToolIds || [], state.getRecentNodeIds ? state.getRecentNodeIds('tool_') : []);
            activeMemoryIds = activeMemoryIds.concat(state.backgroundActiveMemoryIds || [], state.getRecentNodeIds ? state.getRecentNodeIds('memory_file_') : []);
            activeInstructionIds = activeInstructionIds.concat(state.backgroundActiveInstructionIds || [], state.getRecentNodeIds ? state.getRecentNodeIds('instruction_file_') : []);
            activeResearchIds = activeResearchIds.concat(state.backgroundActiveResearchIds || [], state.getRecentNodeIds ? state.getRecentNodeIds('research_file_') : []);
            activeRulesIds = activeRulesIds.concat(state.backgroundActiveRulesIds || [], state.getRecentNodeIds ? state.getRecentNodeIds('rules_file_') : []);
            activeMcpIds = activeMcpIds.concat(state.backgroundActiveMcpIds || [], state.getRecentNodeIds ? state.getRecentNodeIds('mcp_server_') : []);
            activeJobIds = activeJobIds.concat(state.backgroundJobIds || [], state.getRecentNodeIds ? state.getRecentNodeIds('job_file_') : []);
            activeCategoryIds = activeCategoryIds.concat(state.backgroundActiveCategoryIds || [], state.getRecentNodeIds ? state.getRecentNodeIds('category_') : []);
        }

        var agentActive = !!(state && (state.isThinking || (state.isAgentRecentlyActive && state.isAgentRecentlyActive())));
        animateNode('agent', agentActive, t, 12, 0.22, 1.15);
        animateNode('tools', !!(state && (state.toolExecuting || state.gettingAvailTools || state.backgroundGettingAvailTools || (state.isSectionRecentlyActive && state.isSectionRecentlyActive('tools')) || activeToolIds.length)), t, 12, 0.2, 1.2);
        animateNode('memory', !!(state && (state.memoryToolExecuting || state.checkingMemory || state.backgroundCheckingMemory || (state.isSectionRecentlyActive && state.isSectionRecentlyActive('memory')) || activeMemoryIds.length)), t, 12, 0.2, 1.2);
        animateNode('instructions', !!(state && (state.instructionToolExecuting || state.checkingInstructions || state.backgroundCheckingInstructions || (state.isSectionRecentlyActive && state.isSectionRecentlyActive('instructions')) || activeInstructionIds.length)), t, 12, 0.2, 1.2);
        animateNode('research', !!(state && ((state.isSectionRecentlyActive && state.isSectionRecentlyActive('research')) || activeResearchIds.length)), t, 12, 0.2, 1.2);
        animateNode('rules', !!(state && ((state.isSectionRecentlyActive && state.isSectionRecentlyActive('rules')) || activeRulesIds.length)), t, 12, 0.2, 1.2);
        animateNode('mcps', !!(state && (state.mcpToolExecuting || state.checkingMcps || state.backgroundCheckingMcps || (state.isSectionRecentlyActive && state.isSectionRecentlyActive('mcps')) || activeMcpIds.length)), t, 12, 0.2, 1.2);
        animateNode('jobs', !!(state && (state.jobExecuting || state.checkingJobs || state.backgroundCheckingJobs || (state.isSectionRecentlyActive && state.isSectionRecentlyActive('jobs')) || activeJobIds.length)), t, 12, 0.2, 1.2);
        animateNode('categories', !!(state && ((state.isSectionRecentlyActive && state.isSectionRecentlyActive('categories')) || activeCategoryIds.length)), t, 12, 0.2, 1.2);

        Object.keys(nodeGroups).forEach(function (id) {
            if (id.indexOf('tool_') === 0) animateNode(id, activeToolIds.indexOf(id) !== -1, t, 13, 0.24, 1.2);
            if (id.indexOf('memory_file_') === 0) animateNode(id, activeMemoryIds.indexOf(id) !== -1, t, 13, 0.24, 1.2);
            if (id.indexOf('instruction_file_') === 0) animateNode(id, activeInstructionIds.indexOf(id) !== -1, t, 13, 0.24, 1.2);
            if (id.indexOf('research_file_') === 0) animateNode(id, activeResearchIds.indexOf(id) !== -1, t, 13, 0.24, 1.2);
            if (id.indexOf('rules_file_') === 0) animateNode(id, activeRulesIds.indexOf(id) !== -1, t, 13, 0.24, 1.2);
            if (id.indexOf('mcp_server_') === 0) animateNode(id, activeMcpIds.indexOf(id) !== -1, t, 13, 0.24, 1.2);
            if (id.indexOf('job_file_') === 0) animateNode(id, activeJobIds.indexOf(id) !== -1, t, 13, 0.24, 1.2);
            if (id.indexOf('category_') === 0) animateNode(id, activeCategoryIds.indexOf(id) !== -1, t, 13, 0.24, 1.2);
        });

        renderer.render(scene, camera);
    }

    animate();
    window.addEventListener('resize', function () {
        camera.aspect = window.innerWidth / window.innerHeight;
        camera.updateProjectionMatrix();
        renderer.setSize(window.innerWidth, window.innerHeight);
    });

    loadDynamicNodes();
})();
