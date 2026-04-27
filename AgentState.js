class AgentState {
    constructor() {
        this.parentAgentNode = null;
        this.nodeGroups = {};
        this.isThinking = false;
        this.gettingAvailTools = false;
        this.checkingMemory = false;
        this.checkingInstructions = false;
        this.checkingMcps = false;
        this.checkingJobs = false;
        this.activeToolIds = [];
        this.activeMemoryIds = [];
        this.activeInstructionIds = [];
        this.activeResearchIds = [];
        this.activeRulesIds = [];
        this.activeCategoryIds = [];
        this.activeMcpIds = [];
        this.activeJobIds = [];
        this.activeSubAgentIds = [];
        this.subAgentsToolExecuting = false;
        this.backgroundGettingAvailTools = false;
        this.backgroundCheckingMemory = false;
        this.backgroundCheckingInstructions = false;
        this.backgroundCheckingMcps = false;
        this.backgroundCheckingJobs = false;
        this.backgroundActiveToolIds = [];
        this.backgroundActiveMemoryIds = [];
        this.backgroundActiveInstructionIds = [];
        this.backgroundActiveResearchIds = [];
        this.backgroundActiveRulesIds = [];
        this.backgroundActiveCategoryIds = [];
        this.backgroundActiveMcpIds = [];
        this.backgroundJobIds = [];
        this.backgroundActiveSubAgentIds = [];
        this.backgroundExecutionDetailsByNode = {};
        this.executionDetailsByNode = {};
        this.toolExecuting = false;
        this.memoryToolExecuting = false;
        this.instructionToolExecuting = false;
        this.mcpToolExecuting = false;
        this.jobExecuting = false;
        this.isAccessingMemoryFile = false;
        this.memoryFileAccessHoldMs = 4500;
        this.activityHoldMs = 2200;
        this.recentAgentActivityUntil = 0;
        this.recentSectionActivityUntil = { tools: 0, memory: 0, instructions: 0, research: 0, rules: 0, categories: 0, mcps: 0, jobs: 0, sub_agents: 0 };
        this.recentNodeActivityUntil = {};
    }

    nowMs() { return Date.now ? Date.now() : new Date().getTime(); }
    holdUntil(durationMs) { return this.nowMs() + Math.max(0, parseInt(durationMs, 10) || this.activityHoldMs); }

    cleanupRecentNodeActivity() {
        var now = this.nowMs();
        Object.keys(this.recentNodeActivityUntil).forEach(function (nodeId) {
            if ((this.recentNodeActivityUntil[nodeId] || 0) <= now) delete this.recentNodeActivityUntil[nodeId];
        }, this);
    }

    markSectionActivity(section, durationMs) {
        if (!section || !Object.prototype.hasOwnProperty.call(this.recentSectionActivityUntil, section)) return;
        this.recentSectionActivityUntil[section] = this.holdUntil(durationMs);
    }

    markNodeActivity(nodeIds, durationMs) {
        var until = this.holdUntil(durationMs);
        (Array.isArray(nodeIds) ? nodeIds : []).forEach(function (nodeId) {
            if (!nodeId) return;
            this.recentNodeActivityUntil[nodeId] = until;
        }, this);
    }

    markMemoryFileNodesActive(memoryFileNodeIds) {
        var durationMs = this.memoryFileAccessHoldMs;
        var until = this.nowMs() + Math.max(0, parseInt(durationMs, 10) || 3200);
        (Array.isArray(memoryFileNodeIds) ? memoryFileNodeIds : []).forEach(function (nodeId) {
            if (!nodeId || nodeId.indexOf('memory_file_') !== 0) return;
            this.recentNodeActivityUntil[nodeId] = until;
        }, this);
        this.isAccessingMemoryFile = (Array.isArray(memoryFileNodeIds) ? memoryFileNodeIds : []).length > 0;
        this.markSectionActivity('memory', durationMs);
        this.dispatchGraphActivity();
    }

    isSectionRecentlyActive(section) {
        if (!section || !Object.prototype.hasOwnProperty.call(this.recentSectionActivityUntil, section)) return false;
        return (this.recentSectionActivityUntil[section] || 0) > this.nowMs();
    }

    isAgentRecentlyActive() { return this.recentAgentActivityUntil > this.nowMs(); }

    getRecentNodeIds(prefix) {
        this.cleanupRecentNodeActivity();
        var out = [];
        Object.keys(this.recentNodeActivityUntil).forEach(function (nodeId) {
            if ((this.recentNodeActivityUntil[nodeId] || 0) <= this.nowMs()) return;
            if (prefix && nodeId.indexOf(prefix) !== 0) return;
            out.push(nodeId);
        }, this);
        return out;
    }

    dispatchGraphActivity(durationMs) {
        var sections = [];
        var nodeIds = [];
        if (this.isThinking || this.isAgentRecentlyActive()) nodeIds.push('agent');
        if (this.toolExecuting || this.gettingAvailTools || this.backgroundGettingAvailTools || this.isSectionRecentlyActive('tools')) sections.push('tools');
        if (this.memoryToolExecuting || this.checkingMemory || this.backgroundCheckingMemory || this.isSectionRecentlyActive('memory')) sections.push('memory');
        if (this.instructionToolExecuting || this.checkingInstructions || this.backgroundCheckingInstructions || this.isSectionRecentlyActive('instructions')) sections.push('instructions');
        if (this.isSectionRecentlyActive('research')) sections.push('research');
        if (this.isSectionRecentlyActive('rules')) sections.push('rules');
        if (this.isSectionRecentlyActive('categories')) sections.push('categories');
        if (this.mcpToolExecuting || this.checkingMcps || this.backgroundCheckingMcps || this.isSectionRecentlyActive('mcps')) sections.push('mcps');
        if (this.jobExecuting || this.checkingJobs || this.backgroundCheckingJobs || this.isSectionRecentlyActive('jobs')) sections.push('jobs');
        if (this.subAgentsToolExecuting || this.backgroundActiveSubAgentIds.length || this.isSectionRecentlyActive('sub_agents')) sections.push('sub_agents');
        if (this.isAccessingMemoryFile) nodeIds = nodeIds.concat(this.activeMemoryIds);
        nodeIds = nodeIds
            .concat(this.activeToolIds, this.activeMemoryIds, this.activeInstructionIds, this.activeResearchIds, this.activeRulesIds, this.activeCategoryIds, this.activeMcpIds, this.activeJobIds, this.activeSubAgentIds)
            .concat(this.backgroundActiveToolIds, this.backgroundActiveMemoryIds, this.backgroundActiveInstructionIds, this.backgroundActiveResearchIds, this.backgroundActiveRulesIds, this.backgroundActiveCategoryIds, this.backgroundActiveMcpIds, this.backgroundJobIds, this.backgroundActiveSubAgentIds)
            .concat(this.getRecentNodeIds('tool_'))
            .concat(this.getRecentNodeIds('memory_file_'))
            .concat(this.getRecentNodeIds('instruction_file_'))
            .concat(this.getRecentNodeIds('research_file_'))
            .concat(this.getRecentNodeIds('rules_file_'))
            .concat(this.getRecentNodeIds('category_'))
            .concat(this.getRecentNodeIds('mcp_server_'))
            .concat(this.getRecentNodeIds('job_file_'))
            .concat(this.getRecentNodeIds('job_cron_'))
            .concat(this.getRecentNodeIds('sub_agent_file_'));
        var dur = Math.max(this.activityHoldMs, parseInt(durationMs, 10) || 0) || this.activityHoldMs;
        document.dispatchEvent(new CustomEvent('memoryGraphActivity', { detail: {
            sections: sections.filter(function (value, index, arr) { return value && arr.indexOf(value) === index; }),
            nodeIds: nodeIds.filter(function (value, index, arr) { return value && arr.indexOf(value) === index; }),
            durationMs: dur
        }}));
    }

    markExecutionActivity(snapshot) {
        var data = snapshot && typeof snapshot === 'object' ? snapshot : {};
        var details = data.executionDetailsByNode && typeof data.executionDetailsByNode === 'object' ? data.executionDetailsByNode : {};
        var toolIds = Array.isArray(data.activeToolIds) ? data.activeToolIds.slice() : [];
        var memoryIds = Array.isArray(data.activeMemoryIds) ? data.activeMemoryIds.slice() : [];
        var instructionIds = Array.isArray(data.activeInstructionIds) ? data.activeInstructionIds.slice() : [];
        var researchIds = Array.isArray(data.activeResearchIds) ? data.activeResearchIds.slice() : [];
        var rulesIds = Array.isArray(data.activeRulesIds) ? data.activeRulesIds.slice() : [];
        var categoryIds = Array.isArray(data.activeCategoryIds) ? data.activeCategoryIds.slice() : [];
        var mcpIds = Array.isArray(data.activeMcpIds) ? data.activeMcpIds.slice() : [];
        var jobIds = Array.isArray(data.activeJobIds) ? data.activeJobIds.slice() : [];
        var subAgentIds = Array.isArray(data.activeSubAgentIds) ? data.activeSubAgentIds.slice() : [];
        Object.keys(details).forEach(function (nodeId) {
            if (nodeId.indexOf('tool_') === 0 && toolIds.indexOf(nodeId) === -1) toolIds.push(nodeId);
            if (nodeId.indexOf('memory_file_') === 0 && memoryIds.indexOf(nodeId) === -1) memoryIds.push(nodeId);
            if (nodeId.indexOf('instruction_file_') === 0 && instructionIds.indexOf(nodeId) === -1) instructionIds.push(nodeId);
            if (nodeId.indexOf('research_file_') === 0 && researchIds.indexOf(nodeId) === -1) researchIds.push(nodeId);
            if (nodeId.indexOf('rules_file_') === 0 && rulesIds.indexOf(nodeId) === -1) rulesIds.push(nodeId);
            if (nodeId.indexOf('category_') === 0 && categoryIds.indexOf(nodeId) === -1) categoryIds.push(nodeId);
            if (nodeId.indexOf('mcp_server_') === 0 && mcpIds.indexOf(nodeId) === -1) mcpIds.push(nodeId);
            if ((nodeId.indexOf('job_file_') === 0 || nodeId.indexOf('job_cron_') === 0) && jobIds.indexOf(nodeId) === -1) jobIds.push(nodeId);
            if (nodeId.indexOf('sub_agent_file_') === 0 && subAgentIds.indexOf(nodeId) === -1) subAgentIds.push(nodeId);
        });
        this.toolExecuting = !!(data.gettingAvailTools || toolIds.length || details.tools);
        this.memoryToolExecuting = !!(data.checkingMemory || memoryIds.length || details.memory);
        this.instructionToolExecuting = !!(data.checkingInstructions || instructionIds.length || details.instructions);
        this.mcpToolExecuting = !!(data.checkingMcps || mcpIds.length || details.mcps);
        this.jobExecuting = !!(data.checkingJobs || jobIds.length || details.jobs);
        this.subAgentsToolExecuting = !!(subAgentIds.length || details.sub_agents);
        this.activeSubAgentIds = subAgentIds;
        if (data.gettingAvailTools || details.tools || toolIds.length) { this.markSectionActivity('tools'); this.markNodeActivity(toolIds); }
        if (data.checkingMemory || details.memory || memoryIds.length) { this.markSectionActivity('memory'); this.markNodeActivity(memoryIds); }
        if (data.checkingInstructions || details.instructions || instructionIds.length) { this.markSectionActivity('instructions'); this.markNodeActivity(instructionIds); }
        if (data.checkingResearch || details.research || researchIds.length) { this.markSectionActivity('research'); this.markNodeActivity(researchIds); }
        if (data.checkingRules || details.rules || rulesIds.length) { this.markSectionActivity('rules'); this.markNodeActivity(rulesIds); }
        if (data.checkingCategories || details.categories || categoryIds.length) { this.markSectionActivity('categories'); this.markNodeActivity(categoryIds); }
        if (data.checkingMcps || details.mcps || mcpIds.length) { this.markSectionActivity('mcps'); this.markNodeActivity(mcpIds); }
        if (data.checkingJobs || details.jobs || jobIds.length) { this.markSectionActivity('jobs'); this.markNodeActivity(jobIds); }
        if (this.subAgentsToolExecuting) { this.markSectionActivity('sub_agents'); this.markNodeActivity(subAgentIds); }
        if (data.thinking || data.isThinking) { this.recentAgentActivityUntil = this.holdUntil(); this.markNodeActivity(['agent']); }
        this.dispatchGraphActivity();
    }

    applySnapshotFromStatus(data) {
        if (!data || typeof data !== 'object') return;
        var details = data.executionDetailsByNode && typeof data.executionDetailsByNode === 'object' ? data.executionDetailsByNode : {};
        var toolIds = Array.isArray(data.activeToolIds) ? data.activeToolIds.slice() : [];
        var memoryIds = Array.isArray(data.activeMemoryIds) ? data.activeMemoryIds.slice() : [];
        var instructionIds = Array.isArray(data.activeInstructionIds) ? data.activeInstructionIds.slice() : [];
        var researchIds = Array.isArray(data.activeResearchIds) ? data.activeResearchIds.slice() : [];
        var rulesIds = Array.isArray(data.activeRulesIds) ? data.activeRulesIds.slice() : [];
        var categoryIds = Array.isArray(data.activeCategoryIds) ? data.activeCategoryIds.slice() : [];
        var mcpIds = Array.isArray(data.activeMcpIds) ? data.activeMcpIds.slice() : [];
        var jobIds = Array.isArray(data.activeJobIds) ? data.activeJobIds.slice() : [];
        var subAgentIds = Array.isArray(data.activeSubAgentIds) ? data.activeSubAgentIds.slice() : [];
        Object.keys(details).forEach(function (nodeId) {
            if (nodeId.indexOf('tool_') === 0 && toolIds.indexOf(nodeId) === -1) toolIds.push(nodeId);
            if (nodeId.indexOf('memory_file_') === 0 && memoryIds.indexOf(nodeId) === -1) memoryIds.push(nodeId);
            if (nodeId.indexOf('instruction_file_') === 0 && instructionIds.indexOf(nodeId) === -1) instructionIds.push(nodeId);
            if (nodeId.indexOf('research_file_') === 0 && researchIds.indexOf(nodeId) === -1) researchIds.push(nodeId);
            if (nodeId.indexOf('rules_file_') === 0 && rulesIds.indexOf(nodeId) === -1) rulesIds.push(nodeId);
            if (nodeId.indexOf('category_') === 0 && categoryIds.indexOf(nodeId) === -1) categoryIds.push(nodeId);
            if (nodeId.indexOf('mcp_server_') === 0 && mcpIds.indexOf(nodeId) === -1) mcpIds.push(nodeId);
            if ((nodeId.indexOf('job_file_') === 0 || nodeId.indexOf('job_cron_') === 0) && jobIds.indexOf(nodeId) === -1) jobIds.push(nodeId);
            if (nodeId.indexOf('sub_agent_file_') === 0 && subAgentIds.indexOf(nodeId) === -1) subAgentIds.push(nodeId);
        });
        this.isThinking = !!data.thinking;
        this.gettingAvailTools = !!data.gettingAvailTools;
        this.checkingMemory = !!data.checkingMemory;
        this.checkingInstructions = !!data.checkingInstructions;
        this.checkingMcps = !!data.checkingMcps;
        this.checkingJobs = !!data.checkingJobs;
        this.activeToolIds = toolIds;
        this.activeMemoryIds = memoryIds;
        this.activeInstructionIds = instructionIds;
        this.activeResearchIds = researchIds;
        this.activeRulesIds = rulesIds;
        this.activeCategoryIds = categoryIds;
        this.activeMcpIds = mcpIds;
        this.activeJobIds = jobIds;
        this.activeSubAgentIds = subAgentIds;
        this.executionDetailsByNode = details;
        this.toolExecuting = !!(this.gettingAvailTools || toolIds.length || details.tools);
        this.memoryToolExecuting = !!(this.checkingMemory || memoryIds.length || details.memory);
        this.instructionToolExecuting = !!(this.checkingInstructions || instructionIds.length || details.instructions);
        this.mcpToolExecuting = !!(this.checkingMcps || mcpIds.length || details.mcps);
        this.jobExecuting = !!(this.checkingJobs || jobIds.length || details.jobs);
        this.subAgentsToolExecuting = !!(subAgentIds.length || details.sub_agents);
        this.isAccessingMemoryFile = !!(data.isAccessingMemoryFile || memoryIds.length > 0);
        if (this.isThinking) { this.recentAgentActivityUntil = this.holdUntil(); this.markNodeActivity(['agent']); }
        if (this.toolExecuting) { this.markSectionActivity('tools'); this.markNodeActivity(toolIds); }
        if (this.memoryToolExecuting) { this.markSectionActivity('memory'); this.markNodeActivity(memoryIds); }
        if (this.instructionToolExecuting) { this.markSectionActivity('instructions'); this.markNodeActivity(instructionIds); }
        if (data.checkingResearch || researchIds.length || details.research) { this.markSectionActivity('research'); this.markNodeActivity(researchIds); }
        if (data.checkingRules || rulesIds.length || details.rules) { this.markSectionActivity('rules'); this.markNodeActivity(rulesIds); }
        if (data.checkingCategories || categoryIds.length || details.categories) { this.markSectionActivity('categories'); this.markNodeActivity(categoryIds); }
        if (this.mcpToolExecuting) { this.markSectionActivity('mcps'); this.markNodeActivity(mcpIds); }
        if (this.jobExecuting) { this.markSectionActivity('jobs'); this.markNodeActivity(jobIds); }
        if (this.subAgentsToolExecuting) { this.markSectionActivity('sub_agents'); this.markNodeActivity(subAgentIds); }
        if (memoryIds.length > 0) {
            var durationMs = this.memoryFileAccessHoldMs;
            var until = this.nowMs() + Math.max(0, parseInt(durationMs, 10) || 3200);
            memoryIds.forEach(function (nodeId) {
                if (nodeId && nodeId.indexOf('memory_file_') === 0) this.recentNodeActivityUntil[nodeId] = until;
            }, this);
        }
        var durationMs = Math.max(this.activityHoldMs, parseInt(data.durationMs, 10) || 0);
        this.dispatchGraphActivity(durationMs);
    }

    setThinking(thinking) {
        var newVal = !!thinking;
        if (this.isThinking === newVal) {
            return;
        }
        this.isThinking = newVal;
        if (newVal) {
            this.recentAgentActivityUntil = this.holdUntil();
            this.markNodeActivity(['agent']);
        }
        this.dispatchGraphActivity();
    }
    setMemoryToolExecuting(value) { this.memoryToolExecuting = !!value; if (this.memoryToolExecuting) { this.markSectionActivity('memory'); this.markNodeActivity(this.activeMemoryIds.concat(this.backgroundActiveMemoryIds)); } this.dispatchGraphActivity(); }
    setToolExecuting(value) { this.toolExecuting = !!value; if (this.toolExecuting) { this.markSectionActivity('tools'); this.markNodeActivity(this.activeToolIds.concat(this.backgroundActiveToolIds)); } this.dispatchGraphActivity(); }
    setInstructionToolExecuting(value) { this.instructionToolExecuting = !!value; if (this.instructionToolExecuting) { this.markSectionActivity('instructions'); this.markNodeActivity(this.activeInstructionIds.concat(this.backgroundActiveInstructionIds)); } this.dispatchGraphActivity(); }
    setGettingAvailTools(gettingAvailTools) { this.gettingAvailTools = !!gettingAvailTools; this.toolExecuting = !!(this.gettingAvailTools || this.activeToolIds.length || this.backgroundActiveToolIds.length); if (this.toolExecuting) { this.markSectionActivity('tools'); this.markNodeActivity(this.activeToolIds.concat(this.backgroundActiveToolIds)); } this.dispatchGraphActivity(); }
    setCheckingMemory(checkingMemory) { this.checkingMemory = !!checkingMemory; this.memoryToolExecuting = !!(this.checkingMemory || this.activeMemoryIds.length || this.backgroundActiveMemoryIds.length); if (this.memoryToolExecuting) { this.markSectionActivity('memory'); this.markNodeActivity(this.activeMemoryIds.concat(this.backgroundActiveMemoryIds)); } this.dispatchGraphActivity(); }
    setCheckingInstructions(checkingInstructions) { this.checkingInstructions = !!checkingInstructions; this.instructionToolExecuting = !!(this.checkingInstructions || this.activeInstructionIds.length || this.backgroundActiveInstructionIds.length); if (this.instructionToolExecuting) { this.markSectionActivity('instructions'); this.markNodeActivity(this.activeInstructionIds.concat(this.backgroundActiveInstructionIds)); } this.dispatchGraphActivity(); }
    setCheckingMcps(checkingMcps) { this.checkingMcps = !!checkingMcps; this.mcpToolExecuting = !!(this.checkingMcps || this.activeMcpIds.length || this.backgroundActiveMcpIds.length); if (this.mcpToolExecuting) { this.markSectionActivity('mcps'); this.markNodeActivity(this.activeMcpIds.concat(this.backgroundActiveMcpIds)); } this.dispatchGraphActivity(); }
    setCheckingJobs(checkingJobs) { this.checkingJobs = !!checkingJobs; this.jobExecuting = !!(this.checkingJobs || this.activeJobIds.length || this.backgroundJobIds.length); if (this.jobExecuting) { this.markSectionActivity('jobs'); this.markNodeActivity(this.activeJobIds.concat(this.backgroundJobIds)); } this.dispatchGraphActivity(); }
    setActiveToolIds(activeToolIds) { this.activeToolIds = Array.isArray(activeToolIds) ? activeToolIds : []; this.toolExecuting = !!(this.gettingAvailTools || this.activeToolIds.length || this.backgroundActiveToolIds.length); if (this.toolExecuting) { this.markSectionActivity('tools'); this.markNodeActivity(this.activeToolIds); } this.dispatchGraphActivity(); }
    setActiveMemoryIds(activeMemoryIds) {
        this.activeMemoryIds = Array.isArray(activeMemoryIds) ? activeMemoryIds : [];
        this.memoryToolExecuting = !!(this.checkingMemory || this.activeMemoryIds.length || this.backgroundActiveMemoryIds.length);
        if (this.activeMemoryIds.length > 0) {
            this.isAccessingMemoryFile = true;
            this.markMemoryFileNodesActive(this.activeMemoryIds);
        } else {
            this.isAccessingMemoryFile = false;
            if (this.memoryToolExecuting) { this.markSectionActivity('memory'); this.markNodeActivity(this.activeMemoryIds); }
            this.dispatchGraphActivity();
        }
    }
    setAccessingMemoryFile(accessing) { this.isAccessingMemoryFile = !!accessing; this.dispatchGraphActivity(); }
    setActiveInstructionIds(activeInstructionIds) { this.activeInstructionIds = Array.isArray(activeInstructionIds) ? activeInstructionIds : []; this.instructionToolExecuting = !!(this.checkingInstructions || this.activeInstructionIds.length || this.backgroundActiveInstructionIds.length); if (this.instructionToolExecuting) { this.markSectionActivity('instructions'); this.markNodeActivity(this.activeInstructionIds); } this.dispatchGraphActivity(); }
    setActiveMcpIds(activeMcpIds) { this.activeMcpIds = Array.isArray(activeMcpIds) ? activeMcpIds : []; this.mcpToolExecuting = !!(this.checkingMcps || this.activeMcpIds.length || this.backgroundActiveMcpIds.length); if (this.mcpToolExecuting) { this.markSectionActivity('mcps'); this.markNodeActivity(this.activeMcpIds); } this.dispatchGraphActivity(); }
    setActiveJobIds(activeJobIds) { this.activeJobIds = Array.isArray(activeJobIds) ? activeJobIds : []; this.jobExecuting = !!(this.checkingJobs || this.activeJobIds.length || this.backgroundJobIds.length); if (this.jobExecuting) { this.markSectionActivity('jobs'); this.markNodeActivity(this.activeJobIds); } this.dispatchGraphActivity(); }
    setActiveSubAgentIds(activeSubAgentIds) {
        this.activeSubAgentIds = Array.isArray(activeSubAgentIds) ? activeSubAgentIds : [];
        var details = this.executionDetailsByNode && typeof this.executionDetailsByNode === 'object' ? this.executionDetailsByNode : {};
        this.subAgentsToolExecuting = !!(this.activeSubAgentIds.length || details.sub_agents || this.backgroundActiveSubAgentIds.length);
        if (this.subAgentsToolExecuting) { this.markSectionActivity('sub_agents'); this.markNodeActivity(this.activeSubAgentIds.concat(this.backgroundActiveSubAgentIds)); }
        this.dispatchGraphActivity();
    }
    setActiveResearchIds(activeResearchIds) { this.activeResearchIds = Array.isArray(activeResearchIds) ? activeResearchIds : []; this.dispatchGraphActivity(); }
    setActiveRulesIds(activeRulesIds) { this.activeRulesIds = Array.isArray(activeRulesIds) ? activeRulesIds : []; this.dispatchGraphActivity(); }
    setActiveCategoryIds(activeCategoryIds) { this.activeCategoryIds = Array.isArray(activeCategoryIds) ? activeCategoryIds : []; if (this.activeCategoryIds.length) { this.markSectionActivity('categories'); this.markNodeActivity(this.activeCategoryIds); } this.dispatchGraphActivity(); }
    setBackgroundActiveCategoryIds(backgroundActiveCategoryIds) { this.backgroundActiveCategoryIds = Array.isArray(backgroundActiveCategoryIds) ? backgroundActiveCategoryIds : []; if (this.backgroundActiveCategoryIds.length) { this.markSectionActivity('categories'); this.markNodeActivity(this.backgroundActiveCategoryIds); } this.dispatchGraphActivity(); }
    setBackgroundCheckingJobs(backgroundCheckingJobs) { this.backgroundCheckingJobs = !!backgroundCheckingJobs; this.jobExecuting = !!(this.backgroundCheckingJobs || this.checkingJobs || this.activeJobIds.length || this.backgroundJobIds.length); if (this.jobExecuting) { this.markSectionActivity('jobs'); this.markNodeActivity(this.backgroundJobIds); } this.dispatchGraphActivity(); }
    setBackgroundGettingAvailTools(backgroundGettingAvailTools) { this.backgroundGettingAvailTools = !!backgroundGettingAvailTools; this.toolExecuting = !!(this.backgroundGettingAvailTools || this.gettingAvailTools || this.activeToolIds.length || this.backgroundActiveToolIds.length); if (this.toolExecuting) { this.markSectionActivity('tools'); this.markNodeActivity(this.backgroundActiveToolIds); } this.dispatchGraphActivity(); }
    setBackgroundCheckingMemory(backgroundCheckingMemory) { this.backgroundCheckingMemory = !!backgroundCheckingMemory; this.memoryToolExecuting = !!(this.backgroundCheckingMemory || this.checkingMemory || this.activeMemoryIds.length || this.backgroundActiveMemoryIds.length); if (this.memoryToolExecuting) { this.markSectionActivity('memory'); this.markNodeActivity(this.backgroundActiveMemoryIds); } this.dispatchGraphActivity(); }
    setBackgroundCheckingInstructions(backgroundCheckingInstructions) { this.backgroundCheckingInstructions = !!backgroundCheckingInstructions; this.instructionToolExecuting = !!(this.backgroundCheckingInstructions || this.checkingInstructions || this.activeInstructionIds.length || this.backgroundActiveInstructionIds.length); if (this.instructionToolExecuting) { this.markSectionActivity('instructions'); this.markNodeActivity(this.backgroundActiveInstructionIds); } this.dispatchGraphActivity(); }
    setBackgroundCheckingMcps(backgroundCheckingMcps) { this.backgroundCheckingMcps = !!backgroundCheckingMcps; this.mcpToolExecuting = !!(this.backgroundCheckingMcps || this.checkingMcps || this.activeMcpIds.length || this.backgroundActiveMcpIds.length); if (this.mcpToolExecuting) { this.markSectionActivity('mcps'); this.markNodeActivity(this.backgroundActiveMcpIds); } this.dispatchGraphActivity(); }
    setBackgroundJobIds(backgroundJobIds) { this.backgroundJobIds = Array.isArray(backgroundJobIds) ? backgroundJobIds : []; this.jobExecuting = !!(this.backgroundCheckingJobs || this.checkingJobs || this.activeJobIds.length || this.backgroundJobIds.length); if (this.jobExecuting) { this.markSectionActivity('jobs'); this.markNodeActivity(this.backgroundJobIds); } this.dispatchGraphActivity(); }
    setBackgroundActiveToolIds(backgroundActiveToolIds) { this.backgroundActiveToolIds = Array.isArray(backgroundActiveToolIds) ? backgroundActiveToolIds : []; this.toolExecuting = !!(this.backgroundGettingAvailTools || this.gettingAvailTools || this.activeToolIds.length || this.backgroundActiveToolIds.length); if (this.toolExecuting) { this.markSectionActivity('tools'); this.markNodeActivity(this.backgroundActiveToolIds); } this.dispatchGraphActivity(); }
    setBackgroundActiveMemoryIds(backgroundActiveMemoryIds) { this.backgroundActiveMemoryIds = Array.isArray(backgroundActiveMemoryIds) ? backgroundActiveMemoryIds : []; this.memoryToolExecuting = !!(this.backgroundCheckingMemory || this.checkingMemory || this.activeMemoryIds.length || this.backgroundActiveMemoryIds.length); if (this.memoryToolExecuting) { this.markSectionActivity('memory'); this.markNodeActivity(this.backgroundActiveMemoryIds); } this.dispatchGraphActivity(); }
    setBackgroundActiveInstructionIds(backgroundActiveInstructionIds) { this.backgroundActiveInstructionIds = Array.isArray(backgroundActiveInstructionIds) ? backgroundActiveInstructionIds : []; this.instructionToolExecuting = !!(this.backgroundCheckingInstructions || this.checkingInstructions || this.activeInstructionIds.length || this.backgroundActiveInstructionIds.length); if (this.instructionToolExecuting) { this.markSectionActivity('instructions'); this.markNodeActivity(this.backgroundActiveInstructionIds); } this.dispatchGraphActivity(); }
    setBackgroundActiveMcpIds(backgroundActiveMcpIds) { this.backgroundActiveMcpIds = Array.isArray(backgroundActiveMcpIds) ? backgroundActiveMcpIds : []; this.mcpToolExecuting = !!(this.backgroundCheckingMcps || this.checkingMcps || this.activeMcpIds.length || this.backgroundActiveMcpIds.length); if (this.mcpToolExecuting) { this.markSectionActivity('mcps'); this.markNodeActivity(this.backgroundActiveMcpIds); } this.dispatchGraphActivity(); }
    setBackgroundExecutionDetailsByNode(backgroundExecutionDetailsByNode) { this.backgroundExecutionDetailsByNode = backgroundExecutionDetailsByNode && typeof backgroundExecutionDetailsByNode === 'object' ? backgroundExecutionDetailsByNode : {}; this.dispatchGraphActivity(); }

    applyBackgroundJobState(data) {
        if (!data || typeof data !== 'object') return;
        var runningIds = Array.isArray(data.activeJobIds) ? data.activeJobIds.slice() : [];
        this.backgroundCheckingJobs = !!data.checkingJobs || runningIds.length > 0;
        this.backgroundJobIds = runningIds;
        this.backgroundGettingAvailTools = !!data.gettingAvailTools;
        this.backgroundCheckingMemory = !!data.checkingMemory;
        this.backgroundCheckingInstructions = !!data.checkingInstructions;
        this.backgroundCheckingMcps = !!data.checkingMcps;
        this.backgroundActiveToolIds = Array.isArray(data.activeToolIds) ? data.activeToolIds.slice() : [];
        this.backgroundActiveMemoryIds = Array.isArray(data.activeMemoryIds) ? data.activeMemoryIds.slice() : [];
        this.backgroundActiveInstructionIds = Array.isArray(data.activeInstructionIds) ? data.activeInstructionIds.slice() : [];
        this.backgroundActiveMcpIds = Array.isArray(data.activeMcpIds) ? data.activeMcpIds.slice() : [];
        this.backgroundActiveSubAgentIds = Array.isArray(data.activeSubAgentIds) ? data.activeSubAgentIds.slice() : [];
        this.backgroundExecutionDetailsByNode = data.executionDetailsByNode && typeof data.executionDetailsByNode === 'object' ? data.executionDetailsByNode : {};
        this.jobExecuting = !!(this.backgroundCheckingJobs || this.checkingJobs || this.activeJobIds.length || this.backgroundJobIds.length);
        this.subAgentsToolExecuting = !!(this.activeSubAgentIds.length || this.backgroundActiveSubAgentIds.length || (this.executionDetailsByNode && this.executionDetailsByNode.sub_agents) || (this.backgroundExecutionDetailsByNode && this.backgroundExecutionDetailsByNode.sub_agents));
        if (this.jobExecuting) {
            this.markSectionActivity('jobs');
            this.markNodeActivity(runningIds);
        }
        if (this.subAgentsToolExecuting) {
            this.markSectionActivity('sub_agents');
            this.markNodeActivity(this.backgroundActiveSubAgentIds.concat(this.activeSubAgentIds));
        }
        var durationMs = Math.max(this.activityHoldMs, parseInt(data.durationMs, 10) || 0) || 3200;
        this.dispatchGraphActivity(durationMs);
    }
    setExecutionDetailsByNode(executionDetailsByNode) {
        this.executionDetailsByNode = executionDetailsByNode && typeof executionDetailsByNode === 'object' ? executionDetailsByNode : {};
        var detailNodeIds = Object.keys(this.executionDetailsByNode).filter(function (key) {
            return key === 'agent' || key.indexOf('tool_') === 0 || key.indexOf('memory_file_') === 0 || key.indexOf('instruction_file_') === 0 || key.indexOf('mcp_server_') === 0 || key.indexOf('job_file_') === 0 || key.indexOf('job_cron_') === 0 || key.indexOf('category_') === 0 || key.indexOf('sub_agent_file_') === 0;
        });
        if (detailNodeIds.length) this.markNodeActivity(detailNodeIds);
        var d = this.executionDetailsByNode;
        var bd = this.backgroundExecutionDetailsByNode;
        this.subAgentsToolExecuting = !!(this.activeSubAgentIds.length || this.backgroundActiveSubAgentIds.length || (d && d.sub_agents) || (bd && bd.sub_agents));
        if (d && d.sub_agents) this.markSectionActivity('sub_agents');
        this.dispatchGraphActivity();
    }
    setAgentNode(node) { this.parentAgentNode = node; }
    setNodeGroup(nodeId, node) { this.nodeGroups[nodeId] = node; }
}

window.agentState = new AgentState();
