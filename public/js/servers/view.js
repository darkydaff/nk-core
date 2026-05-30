/* public/js/servers/view.js */

/**
 * Server View Module
 * Handles all real-time interactions, search, and monitoring for the server details page.
 */
class ServerView {
    constructor(config) {
        this.serverId = config.serverId;
        this.serverName = config.serverName;
        this.serverHost = config.serverHost;
        this.serverStatus = config.serverStatus;
        this.beszelUrl = config.beszelUrl;
        this.labels = config.labels;
        this.csrfToken = config.csrfToken;

        this.searchTimeout = null;
        this.transitionPoll = null;
        this.logsRefreshTimer = null;
        
        this.centrifugoUrl = config.centrifugoUrl;
        this.connectionToken = config.connectionToken;
        this.subscriptionToken = config.subscriptionToken;
        this.currentJobId = config.currentJobId;
        this.maxEventId = 0;
        this.centrifuge = null;
        this.subscription = null;

        this.init();
    }

    init() {
        this.cacheElements();
        this.bindEvents();
        this.startPolling();
        
        // Inject Premium Telemetry Animation Sheet dynamically
        if (!document.getElementById('telemetry-animations')) {
            const style = document.createElement('style');
            style.id = 'telemetry-animations';
            style.innerHTML = `
                .pulse-glow {
                    animation: telemetryPulse 0.4s ease-out;
                }
                @keyframes telemetryPulse {
                    0% { filter: brightness(1); transform: scale(1); }
                    50% { filter: brightness(1.4); transform: scale(1.04); }
                    100% { filter: brightness(1); transform: scale(1); }
                }
                .bg-emerald-500\\/10, .bg-primary\\/10 {
                    transition: all 0.3s ease-in-out;
                }
            `;
            document.head.appendChild(style);
        }

        // Initial load
        requestAnimationFrame(() => {
            this.performSearch();
            this.initBeszel();
            this.beszelInterval = NK.registerInterval(() => {
                if (document.visibilityState === 'visible') {
                    this.initBeszel();
                }
            }, 15000);
        });

        // Initialize Centrifugo for deployments and telemetry
        this.initCentrifuge();

        this.initVisibilityListener();
    }

    cacheElements() {
        this.searchInput = document.getElementById('clientSearch');
        this.resultsContainer = document.getElementById('searchResults');
        this.statusFilter = document.getElementById('statusFilter');
        this.trafficFilter = document.getElementById('trafficFilter');
        this.sortFilter = document.getElementById('sortFilter');
        this.batchActionBar = document.getElementById('batchActionBar');
        this.selectedCountText = document.getElementById('selectedCountText');
        this.logsModal = document.getElementById('logsModal');
        this.logsBody = document.getElementById('logsBody');
        this.logsCount = document.getElementById('logsCount');
        this.logsAutoScroll = document.getElementById('logsAutoScroll');
    }

    bindEvents() {
        if (this.searchInput) {
            this.searchInput.addEventListener('input', () => {
                clearTimeout(this.searchTimeout);
                this.searchTimeout = setTimeout(() => this.performSearch(), 300);
            });
        }

        [this.statusFilter, this.trafficFilter, this.sortFilter].forEach(el => {
            if (el) el.addEventListener('change', () => this.performSearch());
        });

        const selectAll = document.getElementById('selectAll');
        if (selectAll) {
            selectAll.addEventListener('change', (e) => {
                const checkboxes = document.querySelectorAll('.client-checkbox');
                checkboxes.forEach(cb => cb.checked = e.target.checked);
                this.updateBatchBar();
            });
        }

        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') this.closeLogsModal();
        });
    }

    performSearch(isAutoRefresh = false) {
        if (!this.searchInput) return;

        const query = this.searchInput.value.trim();
        const status = this.statusFilter.value;
        const traffic = this.trafficFilter.value;
        const sort = this.sortFilter.value;
        
        const selectedIds = Array.from(document.querySelectorAll('.client-checkbox:checked')).map(cb => cb.value);
        
        clearTimeout(this.transitionPoll);
        
        const url = `/api/search-clients?q=${encodeURIComponent(query)}&server_id=${this.serverId}&status=${status}&traffic=${traffic}&sort=${sort}`;
        
        if (this.resultsContainer.innerHTML.trim() === '' && !isAutoRefresh) {
            this.resultsContainer.innerHTML = `
                <div class="flex items-center justify-center h-64">
                    <div class="text-center">
                        <div class="animate-spin text-primary text-2xl mb-3">
                            <i class="fas fa-circle-notch"></i>
                        </div>
                        <p class="text-sm text-muted">${this.labels.searching}</p>
                    </div>
                </div>
            `;
        }

        fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(response => response.json())
            .then(data => {
                if (data.error) throw new Error(data.error);
                this.renderResults(data.results, isAutoRefresh);
                
                document.querySelectorAll('.client-checkbox').forEach(cb => {
                    if (selectedIds.includes(cb.value)) cb.checked = true;
                });
                this.updateBatchBar();

                if (data.summary) {
                    const s = data.summary;
                    document.getElementById('summary-total').innerText = s.total;
                    document.getElementById('summary-online').innerText = s.online;
                    document.getElementById('summary-inbound').innerText = (s.traffic.received / 1073741824).toFixed(2);
                    document.getElementById('summary-outbound').innerText = (s.traffic.sent / 1073741824).toFixed(2);
                }
            })
            .catch(err => {
                console.error('Search failed:', err);
                if (!isAutoRefresh) {
                    this.resultsContainer.innerHTML = `<div class="p-10 text-center text-red-500">Error: ${err.message}</div>`;
                }
            })
            .finally(() => {
                if (document.visibilityState === 'visible') {
                    this.transitionPoll = NK.registerTimeout(() => this.performSearch(true), 10000);
                }
            });
    }

    renderResults(results, isAutoRefresh = false) {
        if (!this.clientDbStatuses) this.clientDbStatuses = new Map();
        if (results && results.length > 0) {
            results.forEach(client => {
                this.clientDbStatuses.set(Number(client.id), client.db_status);
            });
        }

        if (!results || results.length === 0) {
            this.resultsContainer.innerHTML = `
                <div class="flex flex-col items-center justify-center h-64 text-center p-10">
                    <i class="fas fa-search text-muted text-4xl mb-4"></i>
                    <p class="text-secondary">${this.labels.noMatches}</p>
                </div>
            `;
            return;
        }

        const query = this.searchInput ? this.searchInput.value.trim() : '';
        const isMobile = window.innerWidth < 768;
        let html = '';

        if (isMobile) {
            html = `<div class="grid grid-cols-1 gap-4 p-4">`;
            results.forEach(client => {
                const isDisabled = (client.db_status === 'disabled');
                const highlightedName = NK.highlightMatch(client.name, query);
                const highlightedIp = NK.highlightMatch(client.external_ip || 'No IP', query);
                html += `
                    <div class="panel p-4 space-y-4 relative group" id="client-card-${client.id}">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <input type="checkbox" name="client_ids[]" value="${client.id}" class="client-checkbox rounded border-default bg-base text-primary focus:ring-primary/20 cursor-pointer" onchange="window.serverView.updateBatchBar()">
                                <div class="w-10 h-10 rounded-lg bg-surface-hover flex items-center justify-center text-2xl">${client.flag}</div>
                                <div>
                                    <span class="text-sm font-bold ${isDisabled ? 'text-muted line-through' : ''} block">${highlightedName}</span>
                                </div>
                            </div>
                            <div class="flex items-center gap-1.5">
                                ${NK.renderStatusBadge(client.db_status, client.connection_status, this.labels)}
                            </div>
                        </div>
                        <div class="flex items-center justify-between text-[10px] bg-panel/50 rounded p-2">
                            <div class="flex flex-col">
                                <span class="text-muted uppercase font-bold tracking-tighter">${this.labels.traffic}</span>
                                <span class="font-mono font-medium">${client.total_traffic}</span>
                                <div class="flex gap-1.5 mt-1">
                                    <span class="inline-flex items-center justify-center px-1.5 py-0.5 rounded-md bg-emerald-500/10 text-emerald-500 border border-emerald-500/20 text-[9px] font-bold leading-none"><i class="fas fa-arrow-down mr-1 opacity-70"></i>${client.speed_down}</span>
                                    <span class="inline-flex items-center justify-center px-1.5 py-0.5 rounded-md bg-primary/10 text-primary border border-primary/20 text-[9px] font-bold leading-none"><i class="fas fa-arrow-up mr-1 opacity-70"></i>${client.speed_up}</span>
                                </div>
                            </div>
                            <div class="flex flex-col text-right">
                                <span class="text-muted uppercase font-bold tracking-tighter">${this.labels.lastSeenLabel}</span>
                                <span class="font-mono font-medium">${client.last_seen}</span>
                            </div>
                        </div>
                        <div class="flex items-center justify-between pt-2 border-t border-default/50">
                            <code class="text-[10px] text-secondary font-mono">${highlightedIp}</code>
                            <div class="flex items-center gap-1">
                                <button onclick="window.serverView.clientAction(${client.id}, 'sync-stats', this)" class="p-1.5 btn-action btn-action-sync" title="${this.labels.sync}"><i class="fas fa-sync-alt text-xs"></i></button>
                                <button onclick="window.serverView.clientAction(${client.id}, '${isDisabled ? 'restore' : 'revoke'}', this)" class="p-1.5 btn-action ${isDisabled ? 'btn-action-restore' : 'btn-action-revoke'}" title="${isDisabled ? this.labels.restore : this.labels.revoke}"><i class="fas ${isDisabled ? 'fa-user-check' : 'fa-user-slash'} text-xs"></i></button>
                                <button onclick="window.serverView.clientAction(${client.id}, 'delete', this)" class="p-1.5 btn-action btn-action-delete" title="${this.labels.delete}"><i class="fas fa-trash-alt text-xs"></i></button>
                                <a href="/clients/${client.id}" class="ml-2 btn-edit">${this.labels.edit}</a>
                            </div>
                        </div>
                    </div>
                `;
            });
            html += `</div>`;
        } else {
            // Desktop Table View (Supports live DOM diffing on updates)
            if (isAutoRefresh && this.resultsContainer.querySelector('table')) {
                const tbody = this.resultsContainer.querySelector('tbody');
                if (tbody) {
                    const newIds = results.map(c => String(c.id));

                    // Remove stale rows
                    Array.from(tbody.querySelectorAll('tr[id^="client-row-"]')).forEach(tr => {
                        const rowId = tr.id.replace('client-row-', '');
                        if (!newIds.includes(rowId)) {
                            tr.remove();
                        }
                    });

                    // Update existing or add new rows in place
                    results.forEach((client, index) => {
                        const isDisabled = (client.db_status === 'disabled');
                        const highlightedName = NK.highlightMatch(client.name, query);
                        const highlightedIp = NK.highlightMatch(client.external_ip || 'No IP', query);

                        let row = document.getElementById(`client-row-${client.id}`);
                        if (row) {
                            // 1. Update status/IP cell
                            const statusCell = row.querySelector('.cell-status');
                            if (statusCell) {
                                const statusHtml = `
                                    <div class="flex flex-col gap-1">
                                        ${NK.renderStatusBadge(client.db_status, client.connection_status, this.labels)}
                                        <code class="text-[10px] text-secondary font-mono">${highlightedIp}</code>
                                    </div>
                                `;
                                if (statusCell.innerHTML !== statusHtml) statusCell.innerHTML = statusHtml;
                            }

                            // 2. Update traffic/speed cell
                            const trafficCell = row.querySelector('.cell-traffic');
                            if (trafficCell) {
                                const trafficHtml = `
                                    <div class="flex flex-col">
                                        <span class="font-medium">${client.total_traffic}</span>
                                        <div class="flex gap-1.5 mt-1">
                                            <span class="inline-flex items-center justify-center px-1.5 py-0.5 rounded-md bg-emerald-500/10 text-emerald-500 border border-emerald-500/20 text-[9px] font-bold leading-none"><i class="fas fa-arrow-down mr-1 opacity-70"></i>${client.speed_down}</span>
                                            <span class="inline-flex items-center justify-center px-1.5 py-0.5 rounded-md bg-primary/10 text-primary border border-primary/20 text-[9px] font-bold leading-none"><i class="fas fa-arrow-up mr-1 opacity-70"></i>${client.speed_up}</span>
                                        </div>
                                        <span class="text-[10px] text-muted uppercase tracking-tighter">${client.last_seen}</span>
                                    </div>
                                `;
                                if (trafficCell.innerHTML !== trafficHtml) trafficCell.innerHTML = trafficHtml;
                            }

                            // 3. Update actions cell
                            const actionsCell = row.querySelector('.cell-actions');
                            if (actionsCell) {
                                const actionsHtml = `
                                    <div class="flex items-center justify-end gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                                        <button onclick="window.serverView.clientAction(${client.id}, 'sync-stats', this)" class="p-1.5 btn-action btn-action-sync" title="${this.labels.sync}">
                                            <i class="fa-solid fa-rotate text-[10px]"></i>
                                        </button>
                                        <button onclick="window.serverView.clientAction(${client.id}, '${isDisabled ? 'restore' : 'revoke'}', this)" class="p-1.5 btn-action ${isDisabled ? 'btn-action-restore' : 'btn-action-revoke'}" title="${isDisabled ? this.labels.restore : this.labels.revoke}">
                                            <i class="fas ${isDisabled ? 'fa-user-check' : 'fa-user-slash'} text-xs"></i>
                                        </button>
                                        <button onclick="window.serverView.clientAction(${client.id}, 'delete', this)" class="p-1.5 btn-action btn-action-delete" title="${this.labels.delete}">
                                            <i class="fas fa-trash-alt text-xs"></i>
                                        </button>
                                        <a href="/clients/${client.id}" class="ml-2 btn-edit">
                                            ${this.labels.edit}
                                        </a>
                                    </div>
                                `;
                                if (actionsCell.innerHTML !== actionsHtml) actionsCell.innerHTML = actionsHtml;
                            }

                            // 4. Ensure visual ordering
                            const childRows = Array.from(tbody.querySelectorAll('tr[id^="client-row-"]'));
                            const currentIndex = childRows.indexOf(row);
                            if (currentIndex !== index) {
                                tbody.insertBefore(row, tbody.children[index] || null);
                            }
                        } else {
                            // Insert a newly created row in place
                            const tempDiv = document.createElement('tbody');
                            tempDiv.innerHTML = `
                                <tr class="hover:bg-surface-hover/50 transition-colors group" id="client-row-${client.id}">
                                    <td class="px-5 py-4">
                                        <input type="checkbox" name="client_ids[]" value="${client.id}" class="client-checkbox rounded border-default bg-base text-primary focus:ring-primary/20 cursor-pointer" onchange="window.serverView.updateBatchBar()">
                                    </td>
                                    <td class="px-5 py-4">
                                        <div class="flex items-center">
                                            <div class="w-10 h-10 rounded-lg bg-surface-hover flex items-center justify-center mr-3 text-2xl">
                                                ${client.flag}
                                            </div>
                                            <div>
                                                <span class="text-sm font-medium ${isDisabled ? 'text-muted line-through' : ''} block">${highlightedName}</span>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-5 py-4 whitespace-nowrap cell-status">
                                        <div class="flex flex-col gap-1">
                                            ${NK.renderStatusBadge(client.db_status, client.connection_status, this.labels)}
                                            <code class="text-[10px] text-secondary font-mono">${highlightedIp}</code>
                                        </div>
                                    </td>
                                    <td class="px-5 py-4 font-mono text-xs whitespace-nowrap cell-traffic">
                                        <div class="flex flex-col">
                                            <span class="font-medium">${client.total_traffic}</span>
                                            <div class="flex gap-1.5 mt-1">
                                                <span class="inline-flex items-center justify-center px-1.5 py-0.5 rounded-md bg-emerald-500/10 text-emerald-500 border border-emerald-500/20 text-[9px] font-bold leading-none"><i class="fas fa-arrow-down mr-1 opacity-70"></i>${client.speed_down}</span>
                                                <span class="inline-flex items-center justify-center px-1.5 py-0.5 rounded-md bg-primary/10 text-primary border border-primary/20 text-[9px] font-bold leading-none"><i class="fas fa-arrow-up mr-1 opacity-70"></i>${client.speed_up}</span>
                                            </div>
                                            <span class="text-[10px] text-muted uppercase tracking-tighter">${client.last_seen}</span>
                                        </div>
                                    </td>
                                    <td class="px-5 py-4 text-right whitespace-nowrap cell-actions">
                                        <div class="flex items-center justify-end gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                                            <button onclick="window.serverView.clientAction(${client.id}, 'sync-stats', this)" class="p-1.5 btn-action btn-action-sync" title="${this.labels.sync}">
                                                <i class="fa-solid fa-rotate text-[10px]"></i>
                                            </button>
                                            <button onclick="window.serverView.clientAction(${client.id}, '${isDisabled ? 'restore' : 'revoke'}', this)" class="p-1.5 btn-action ${isDisabled ? 'btn-action-restore' : 'btn-action-revoke'}" title="${isDisabled ? this.labels.restore : this.labels.revoke}">
                                                <i class="fas ${isDisabled ? 'fa-user-check' : 'fa-user-slash'} text-xs"></i>
                                            </button>
                                            <button onclick="window.serverView.clientAction(${client.id}, 'delete', this)" class="p-1.5 btn-action btn-action-delete" title="${this.labels.delete}">
                                                <i class="fas fa-trash-alt text-xs"></i>
                                            </button>
                                            <a href="/clients/${client.id}" class="ml-2 btn-edit">
                                                ${this.labels.edit}
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            `;
                            const newRow = tempDiv.firstElementChild;
                            tbody.insertBefore(newRow, tbody.children[index] || null);
                        }
                    });
                }
                return;
            }

            // Otherwise, render full table on initial load/manual filter
            html = `
                <div class="table-wrapper">
                    <table class="w-full min-w-full text-left border-collapse">
                        <thead>
                            <tr class="bg-panel/50">
                                <th class="px-5 py-3 text-left w-10">
                                    <input type="checkbox" id="selectAll" class="rounded border-default bg-base text-primary focus:ring-primary/20 cursor-pointer">
                                </th>
                                <th class="px-5 py-3 text-left text-[10px] uppercase tracking-widest text-muted font-bold">${this.labels.clientServer}</th>
                                <th class="px-5 py-3 text-left text-[10px] uppercase tracking-widest text-muted font-bold whitespace-nowrap">${this.labels.statusIp}</th>
                                <th class="px-5 py-3 text-left text-[10px] uppercase tracking-widest text-muted font-bold whitespace-nowrap">${this.labels.trafficSeen}</th>
                                <th class="px-5 py-3 text-right text-[10px] uppercase tracking-widest text-muted font-bold whitespace-nowrap">${this.labels.actions}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-700/50">
            `;

            results.forEach(client => {
                const isDisabled = (client.db_status === 'disabled');
                const highlightedName = NK.highlightMatch(client.name, query);
                const highlightedIp = NK.highlightMatch(client.external_ip || 'No IP', query);
                html += `
                    <tr class="hover:bg-surface-hover/50 transition-colors group" id="client-row-${client.id}">
                        <td class="px-5 py-4">
                            <input type="checkbox" name="client_ids[]" value="${client.id}" class="client-checkbox rounded border-default bg-base text-primary focus:ring-primary/20 cursor-pointer" onchange="window.serverView.updateBatchBar()">
                        </td>
                        <td class="px-5 py-4">
                            <div class="flex items-center">
                                <div class="w-10 h-10 rounded-lg bg-surface-hover flex items-center justify-center mr-3 text-2xl">
                                    ${client.flag}
                                </div>
                                <div>
                                    <span class="text-sm font-medium ${isDisabled ? 'text-muted line-through' : ''} block">${highlightedName}</span>
                                </div>
                            </div>
                        </td>
                        <td class="px-5 py-4 whitespace-nowrap cell-status">
                            <div class="flex flex-col gap-1">
                                ${NK.renderStatusBadge(client.db_status, client.connection_status, this.labels)}
                                <code class="text-[10px] text-secondary font-mono">${highlightedIp}</code>
                            </div>
                        </td>
                        <td class="px-5 py-4 font-mono text-xs whitespace-nowrap cell-traffic">
                            <div class="flex flex-col">
                                <span class="font-medium">${client.total_traffic}</span>
                                <div class="flex gap-1.5 mt-1">
                                    <span class="inline-flex items-center justify-center px-1.5 py-0.5 rounded-md bg-emerald-500/10 text-emerald-500 border border-emerald-500/20 text-[9px] font-bold leading-none"><i class="fas fa-arrow-down mr-1 opacity-70"></i>${client.speed_down}</span>
                                    <span class="inline-flex items-center justify-center px-1.5 py-0.5 rounded-md bg-primary/10 text-primary border border-primary/20 text-[9px] font-bold leading-none"><i class="fas fa-arrow-up mr-1 opacity-70"></i>${client.speed_up}</span>
                                </div>
                                <span class="text-[10px] text-muted uppercase tracking-tighter">${client.last_seen}</span>
                            </div>
                        </td>
                        <td class="px-5 py-4 text-right whitespace-nowrap cell-actions">
                            <div class="flex items-center justify-end gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                                <button onclick="window.serverView.clientAction(${client.id}, 'sync-stats', this)" class="p-1.5 btn-action btn-action-sync" title="${this.labels.sync}">
                                    <i class="fa-solid fa-rotate text-[10px]"></i>
                                </button>
                                <button onclick="window.serverView.clientAction(${client.id}, '${isDisabled ? 'restore' : 'revoke'}', this)" class="p-1.5 btn-action ${isDisabled ? 'btn-action-restore' : 'btn-action-revoke'}" title="${isDisabled ? this.labels.restore : this.labels.revoke}">
                                    <i class="fas ${isDisabled ? 'fa-user-check' : 'fa-user-slash'} text-xs"></i>
                                </button>
                                <button onclick="window.serverView.clientAction(${client.id}, 'delete', this)" class="p-1.5 btn-action btn-action-delete" title="${this.labels.delete}">
                                    <i class="fas fa-trash-alt text-xs"></i>
                                </button>
                                <a href="/clients/${client.id}" class="ml-2 btn-edit">
                                    ${this.labels.edit}
                                </a>
                            </div>
                        </td>
                    </tr>
                `;
            });
            html += `</tbody></table></div>`;
        }

        this.resultsContainer.innerHTML = html;

        const selectAll = document.getElementById('selectAll');
        if (selectAll) {
            selectAll.addEventListener('change', (e) => {
                const checkboxes = document.querySelectorAll('.client-checkbox');
                checkboxes.forEach(cb => cb.checked = e.target.checked);
                this.updateBatchBar();
            });
        }

        // Re-cache telemetry row selectors for dynamic updates
        if (this.telemetrySubscription) {
            this.cacheTelemetryRows();
        }
    }

    updateBatchBar() {
        const selected = document.querySelectorAll('.client-checkbox:checked');
        if (this.batchActionBar) {
            if (selected.length > 0) {
                this.batchActionBar.classList.remove('hidden');
                this.batchActionBar.classList.add('flex');
                this.selectedCountText.innerText = `${selected.length} SELECTED`;
            } else {
                this.batchActionBar.classList.add('hidden');
                this.batchActionBar.classList.remove('flex');
            }
        }
    }

    async handleBatchAction(action) {
        const selected = Array.from(document.querySelectorAll('.client-checkbox:checked')).map(cb => cb.value);
        if (selected.length === 0) return;

        const confirmMsg = action === 'delete' ? this.labels.confirmDelete : null;
        if (confirmMsg && !confirm(confirmMsg)) return;

        const result = await NK.handleAjaxAction('/clients/batch', { ids: selected, action: action }, null);
        if (result && result.success) {
            this.performSearch(true);
        }
    }

    async clientAction(id, action, btn) {
        const confirmMsg = action === 'delete' ? this.labels.confirmDelete : null;
        const result = await NK.handleAjaxAction(`/clients/${id}/${action}`, {}, confirmMsg);
        if (result && result.success) {
            this.performSearch(true);
        }
    }

    handleDeleteServer(serverId, serverName) {
        const input = prompt(`To delete this server, please type its name exactly: "${serverName}"`);
        if (input === null) return;
        
        const trimmedInput = input.trim();
        const expectedName = serverName.trim();
        if (trimmedInput === expectedName) {
            NK.handleAjaxAction(`/servers/${serverId}/delete`, { confirm_name: trimmedInput });
        } else {
            alert(`Name doesn't match. Expected: "${expectedName}", You typed: "${trimmedInput}"`);
        }
    }

    async syncStats(serverId) {
        const result = await NK.handleAjaxAction(`/servers/${serverId}/sync-stats`);
        if (result && result.success) {
            this.performSearch(true);
        }
    }

    async toggleTelemetry(serverId) {
        const btn = document.getElementById('telemetryToggleBtn');
        const icon = document.getElementById('telemetryToggleIcon');
        const text = document.getElementById('telemetryToggleText');
        const badge = document.getElementById('telemetryModeBadge');
        
        if (btn) btn.disabled = true;
        if (icon) {
            icon.className = 'fas fa-spinner fa-spin';
        }
        if (text) {
            const isPush = badge && badge.innerText.includes('PUSH');
            text.innerText = isPush ? 'Reinstalling...' : 'Enabling...';
        }

        try {
            const result = await NK.handleAjaxAction(`/servers/${serverId}/toggle-telemetry`);
            if (result && result.success) {
                if (badge) {
                    badge.innerText = result.mode === 'push' ? 'Push (Active)' : 'SSH (Legacy)';
                    badge.className = `text-xs font-mono font-bold uppercase ${result.mode === 'push' ? 'text-primary' : 'text-slate-400'}`;
                }
                if (text) {
                    text.innerText = result.mode === 'push' ? 'Reinstall Agent' : 'Enable Push';
                }
                
                if (window.showToast) {
                    window.showToast(result.message || 'Success!', 'success');
                } else {
                    alert(result.message || 'Success!');
                }
                
                // Immediately pull stats to update last seen status
                this.syncStats(serverId);
            }
        } catch (err) {
            console.error('Failed to toggle telemetry:', err);
        } finally {
            if (btn) btn.disabled = false;
            if (icon) {
                icon.className = 'fas fa-magic';
            }
        }
    }

    initBeszel() {
        if (!this.serverHost) return;
        fetch(`/api/monitoring/beszel/${this.serverHost}`)
            .then(response => response.json())
            .then(res => {
                if (res.success && res.data) {
                    const data = res.data;
                    const info = data.info || {};
                    const healthSection = document.getElementById('beszel-health-section');
                    if (healthSection) healthSection.classList.remove('hidden');

                    const hubLink = document.querySelector('a[title="Open Beszel Hub"]');
                    if (hubLink && data.id) {
                        const baseUrl = this.beszelUrl.replace(/\/$/, '');
                        hubLink.href = `${baseUrl}/system/${data.id}`;
                    }

                    // CPU
                    const cpu = info.cpu || 0;
                    const cpuVal = document.getElementById('beszel-cpu-val');
                    const cpuBar = document.getElementById('beszel-cpu-bar');
                    const cpuInfo = document.getElementById('beszel-cpu-info');
                    if (cpuVal) cpuVal.innerText = cpu.toFixed(1) + '%';
                    if (cpuBar) cpuBar.style.width = cpu + '%';
                    if (cpuInfo) cpuInfo.innerText = (info.t || '?') + ' Threads';

                    // RAM
                    const ram = info.mp || 0;
                    const ramVal = document.getElementById('beszel-ram-val');
                    const ramBar = document.getElementById('beszel-ram-bar');
                    if (ramVal) ramVal.innerText = ram.toFixed(1) + '%';
                    if (ramBar) ramBar.style.width = ram + '%';
                    const stats = data.stats || {};
                    const totalMem = stats.m || data.m || info.m;
                    if (totalMem) {
                        const ramInfo = document.getElementById('beszel-ram-info');
                        if (ramInfo) ramInfo.innerText = totalMem.toFixed(1) + ' GB Capacity';
                    }

                    // Disk
                    const disk = info.dp || 0;
                    const diskVal = document.getElementById('beszel-disk-val');
                    const diskBar = document.getElementById('beszel-disk-bar');
                    if (diskVal) diskVal.innerText = disk.toFixed(1) + '%';
                    if (diskBar) diskBar.style.width = disk + '%';
                    const totalDisk = stats.d || data.d || info.d;
                    if (totalDisk) {
                        const diskInfo = document.getElementById('beszel-disk-info');
                        if (diskInfo) diskInfo.innerText = totalDisk.toFixed(0) + ' GB Total';
                    }

                    // Bandwidth
                    let rx_mbps = 0, tx_mbps = 0, total_mbps = 0;
                    if (info.bb) {
                        total_mbps = (info.bb * 8) / 1000000;
                        if (stats.nr || stats.ns) {
                            rx_mbps = (stats.nr || 0) * 8; tx_mbps = (stats.ns || 0) * 8;
                        } else if (stats.b && Array.isArray(stats.b)) {
                            tx_mbps = (stats.b[0] * 8) / (15 * 1000000); rx_mbps = (stats.b[1] * 8) / (15 * 1000000);
                        }
                    } else {
                        if (stats.nr || stats.ns) {
                            rx_mbps = (stats.nr || 0) * 8; tx_mbps = (stats.ns || 0) * 8;
                        } else if (stats.b && Array.isArray(stats.b)) {
                            tx_mbps = (stats.b[0] * 8) / (15 * 1000000); rx_mbps = (stats.b[1] * 8) / (15 * 1000000);
                        }
                        total_mbps = rx_mbps + tx_mbps;
                    }

                    const fmtBw = (val) => val >= 1000 ? (val / 1000).toFixed(2) + ' Gbps' : val.toFixed(1) + ' Mbps';
                    const bwRx = document.getElementById('beszel-bw-rx');
                    const bwTx = document.getElementById('beszel-bw-tx');
                    const bwTotal = document.getElementById('beszel-bw-total');
                    if (bwRx) bwRx.innerText = fmtBw(rx_mbps);
                    if (bwTx) bwTx.innerText = fmtBw(tx_mbps);
                    if (bwTotal) bwTotal.innerText = fmtBw(total_mbps);

                    // Load & Uptime
                    const loadEl = document.getElementById('beszel-load');
                    const uptimeEl = document.getElementById('beszel-uptime');
                    if (info.la && loadEl) loadEl.innerText = info.la.map(l => l.toFixed(2)).join(' ');
                    if (info.u && uptimeEl) {
                        const days = Math.floor(info.u / 86400);
                        const hours = Math.floor((info.u % 86400) / 3600);
                        uptimeEl.innerText = `Uptime: ${days}d ${hours}h`;
                    }
                }
            }).catch(err => console.error('Beszel error:', err));
    }

    initCentrifuge() {
        if (!this.centrifugoUrl || !this.connectionToken) return;
        if (this.centrifuge) return; // Already initialized

        console.log(`[Centrifugo] Connecting to WebSocket: ${this.centrifugoUrl}`);
        
        this.centrifuge = new Centrifuge(this.centrifugoUrl, {
            token: this.connectionToken
        });

        this.centrifuge.on('connected', () => {
            console.log('[Centrifugo] Connection established');
            // Dynamically subscribe to real-time telemetry if the page is currently visible
            if (document.visibilityState === 'visible') {
                this.subscribeTelemetry();
            }
            // Subscribe to deployments if actively deploying
            if (this.serverStatus === 'deploying' && this.currentJobId) {
                this.subscribeJobEvents();
            }
        });

        this.centrifuge.on('disconnected', (ctx) => {
            console.warn('[Centrifugo] Connection disconnected', ctx);
        });

        this.centrifuge.connect();
    }

    subscribeJobEvents() {
        if (!this.centrifuge || !this.currentJobId || !this.subscriptionToken) return;
        if (this.subscription) return; // Already subscribed

        console.log(`[Centrifugo] Subscribing to job channel: job:${this.currentJobId}`);
        this.subscription = this.centrifuge.newSubscription(`job:${this.currentJobId}`, {
            token: this.subscriptionToken
        });

        this.subscription.on('publication', (ctx) => {
            this.processJobEvent(ctx.data);
        });

        this.subscription.subscribe();

        // Fetch deployment event history for hydration
        fetch(`/api/jobs/${this.currentJobId}/events`)
            .then(r => r.json())
            .then(d => {
                if (d.success && d.events) {
                    d.events.forEach(event => this.processJobEvent(event));
                }
            })
            .catch(e => console.error("[Centrifugo] Deployment history hydration failed", e));
    }

    subscribeTelemetry() {
        if (!this.centrifuge) return;
        if (this.telemetrySubscription) return; // Already subscribed

        console.log(`[Telemetry] Subscribing to public telemetry channel: server:telemetry:${this.serverId}`);
        this.telemetrySubscription = this.centrifuge.newSubscription(`server:telemetry:${this.serverId}`);

        let pendingTelemetry = null;
        let telemetryFrameQueued = false;

        this.telemetrySubscription.on('publication', (ctx) => {
            pendingTelemetry = ctx.data;

            if (!telemetryFrameQueued) {
                telemetryFrameQueued = true;
                requestAnimationFrame(() => {
                    this.hydrateTelemetry(pendingTelemetry);
                    telemetryFrameQueued = false;
                });
            }
        });

        this.telemetrySubscription.subscribe();
        
        // Cache initial DOM row selectors for fast O(1) loop lookups
        this.cacheTelemetryRows();
    }

    unsubscribeTelemetry() {
        if (this.telemetrySubscription) {
            console.log(`[Telemetry] Unsubscribing from telemetry channel: server:telemetry:${this.serverId}`);
            if (this.centrifuge) {
                this.centrifuge.removeSubscription(this.telemetrySubscription);
            } else {
                this.telemetrySubscription.unsubscribe();
            }
            this.telemetrySubscription = null;
            this.telemetryRows = null; // Clear cached row selectors to defend against leaks
        }
    }

    cacheTelemetryRows() {
        this.telemetryRows = new Map();
        
        // Match both mobile card views ("client-card-") and desktop row views ("client-row-")
        const items = document.querySelectorAll('[id^="client-row-"], [id^="client-card-"]');
        items.forEach(el => {
            const id = Number(el.id.replace('client-row-', '').replace('client-card-', ''));
            if (id) {
                // Highly performant sub-selectors for client rows
                const speedDownSpan = el.querySelector('.bg-emerald-500\\/10');
                const speedUpSpan = el.querySelector('.bg-primary\\/10');
                const trafficSpan = el.querySelector('.cell-traffic span.font-medium') || el.querySelector('.font-mono.font-medium');
                const statusContainer = el.querySelector('.cell-status div') || el.querySelector('.flex.items-center.gap-1\\.5');
                const seenSpan = el.querySelector('.cell-traffic span.text-muted') || el.querySelector('.font-mono.font-medium + .text-muted') || el.querySelector('.font-mono.font-medium + div + .text-muted');

                this.telemetryRows.set(id, {
                    el,
                    speedDown: speedDownSpan,
                    speedUp: speedUpSpan,
                    traffic: trafficSpan,
                    status: statusContainer,
                    seen: seenSpan
                });
            }
        });
    }

    hydrateTelemetry(data) {
        if (!data || !Array.isArray(data.clients)) return;

        // Ensure status map is initialized
        if (!this.clientDbStatuses) {
            this.clientDbStatuses = new Map();
        }

        // Cache rows on first publish if not already indexed
        if (!this.telemetryRows || this.telemetryRows.size === 0) {
            this.cacheTelemetryRows();
        }

        data.clients.forEach(client => {
            const row = this.telemetryRows.get(Number(client.id));
            if (!row) return;

            // 1. Hydrate Download Speed with a beautiful micro-pulse anim
            if (row.speedDown) {
                const innerText = `<i class="fas fa-arrow-down mr-1 opacity-70"></i>${client.down}`;
                if (row.speedDown.innerHTML !== innerText) {
                    row.speedDown.innerHTML = innerText;
                    row.speedDown.classList.add('pulse-glow');
                    setTimeout(() => row.speedDown.classList.remove('pulse-glow'), 400);
                }
            }

            // 2. Hydrate Upload Speed with a beautiful micro-pulse anim
            if (row.speedUp) {
                const innerText = `<i class="fas fa-arrow-up mr-1 opacity-70"></i>${client.up}`;
                if (row.speedUp.innerHTML !== innerText) {
                    row.speedUp.innerHTML = innerText;
                    row.speedUp.classList.add('pulse-glow');
                    setTimeout(() => row.speedUp.classList.remove('pulse-glow'), 400);
                }
            }

            // 3. Hydrate Total Traffic
            if (row.traffic) {
                if (row.traffic.innerText !== client.traffic) {
                    row.traffic.innerText = client.traffic;
                }
            }

            // 4. Hydrate Last Seen status
            if (row.seen) {
                if (row.seen.innerText !== client.seen) {
                    row.seen.innerText = client.seen;
                }
            }

            // 5. Hydrate Status Badge (Online/Offline)
            if (row.status) {
                const dbStatus = this.clientDbStatuses.get(Number(client.id)) || 'active';
                const connStatus = client.online ? 'online' : 'offline';
                const badgeHtml = NK.renderStatusBadge(dbStatus, connStatus, this.labels);
                if (row.status.innerHTML.trim() !== badgeHtml.trim()) {
                    row.status.innerHTML = badgeHtml;
                }
            }
        });
    }

    processJobEvent(event) {
        if (event.id && event.id <= this.maxEventId) return;
        if (event.id) this.maxEventId = event.id;

        const stepMappings = {
            'test_connection': 'step-init',
            'prepare_system': 'step-init',
            'install_docker': 'step-env',
            'install_kernel_module': 'step-env',
            'create_dirs': 'step-env',
            'find_port': 'step-orch',
            'create_dockerfile': 'step-orch',
            'create_scripts': 'step-orch',
            'build_image': 'step-orch',
            'run_container': 'step-orch',
            'init_config': 'step-final',
            'finalize_deployment': 'step-final'
        };

        switch (event.type) {
            case 'step.start':
                const startStepId = stepMappings[event.payload.step];
                if (startStepId) {
                    this.updateProgressStep(startStepId, 'active');
                }
                break;
            case 'step.end':
                const endStepId = stepMappings[event.payload.step];
                if (endStepId) {
                    // Check if this was the last sub-step of a phase to mark it completed
                    // For simplicity, we mark it active and let the next one mark previous as completed
                    // but we can also be more specific.
                    if (event.payload.step === 'prepare_system') this.updateProgressStep('step-init', 'completed');
                    if (event.payload.step === 'create_dirs') this.updateProgressStep('step-env', 'completed');
                    if (event.payload.step === 'run_container') this.updateProgressStep('step-orch', 'completed');
                    if (event.payload.step === 'finalize_deployment') this.updateProgressStep('step-final', 'completed');
                }
                break;
            case 'step.error':
                const errorStepId = stepMappings[event.payload.step];
                if (errorStepId) {
                    this.updateProgressStep(errorStepId, 'error');
                }
                break;
            case 'job.success':
                this.updateProgressStep('step-final', 'completed');
                setTimeout(() => window.location.reload(), 2000);
                break;
            case 'job.error':
                // Mark current active as error
                const activeStep = document.querySelector('.deployment-step.active');
                if (activeStep) {
                    activeStep.classList.remove('active');
                    activeStep.classList.add('error');
                }
                break;
        }
    }

    updateProgressStep(stepId, state) {
        const el = document.getElementById(stepId);
        if (!el) return;

        // Remove previous states
        el.classList.remove('active', 'completed', 'error', 'pending');
        el.classList.add(state);

        // If completed, make sure previous steps are also completed
        if (state === 'completed') {
            let prev = el.previousElementSibling;
            while (prev && prev.classList.contains('deployment-step')) {
                prev.classList.remove('active', 'error', 'pending');
                prev.classList.add('completed');
                prev = prev.previousElementSibling;
            }
        }
        
        // If active, make sure previous steps are completed
        if (state === 'active') {
            let prev = el.previousElementSibling;
            while (prev && prev.classList.contains('deployment-step')) {
                prev.classList.remove('active', 'error', 'pending');
                prev.classList.add('completed');
                prev = prev.previousElementSibling;
            }
        }
    }

    startPolling() {
        if (this.serverStatus !== 'deploying') return;
        if (this.pollTimer) clearInterval(this.pollTimer);

        const pollStatus = () => {
            if (document.visibilityState !== 'visible') return;
            fetch(`/servers/${this.serverId}/status`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(r => r.json())
            .then(data => {
                if (data.status && data.status !== 'deploying') {
                    localStorage.removeItem('globalScrollPos');
                    clearInterval(this.pollTimer);
                    window.location.reload();
                }
            })
            .catch(() => {});
        };
        this.pollTimer = NK.registerInterval(pollStatus, 2500);
    }

    openLogsModal() {
        if (this.logsRefreshTimer !== null) {
            clearTimeout(this.logsRefreshTimer);
            this.logsRefreshTimer = null;
        }
        this.logsModal.classList.remove('hidden');
        document.body.style.overflow = 'hidden';
        this.fetchLogs();
        NK.initFocusTrap(this.logsModal);
    }

    closeLogsModal() {
        this.logsModal.classList.add('hidden');
        document.body.style.overflow = '';
        if (this.logsRefreshTimer !== null) {
            clearTimeout(this.logsRefreshTimer);
            this.logsRefreshTimer = null;
        }
        NK.destroyFocusTrap(this.logsModal);
    }

    refreshLogs() {
        if (this.logsRefreshTimer !== null) {
            clearTimeout(this.logsRefreshTimer);
            this.logsRefreshTimer = null;
        }
        this.fetchLogs();
    }

    fetchLogs() {
        fetch(`/servers/${this.serverId}/logs`, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(r => r.json())
        .then(data => {
            this.renderLogs(data.logs || []);
            if (this.logsModal && !this.logsModal.classList.contains('hidden') && document.visibilityState === 'visible') {
                this.logsRefreshTimer = NK.registerTimeout(() => this.fetchLogs(), 5000);
            }
        })
        .catch(err => {
            this.logsBody.innerHTML = `<p class="text-red-400">${this.labels.logsFailed}: ${err.message}</p>`;
            if (this.logsModal && !this.logsModal.classList.contains('hidden') && document.visibilityState === 'visible') {
                this.logsRefreshTimer = NK.registerTimeout(() => this.fetchLogs(), 8000);
            }
        });
    }

    initVisibilityListener() {
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') {
                this.performSearch(true);
                this.initBeszel();
                this.subscribeTelemetry();
                if (this.serverStatus === 'deploying') {
                    this.startPolling();
                }
            } else {
                this.unsubscribeTelemetry();
                if (this.transitionPoll) {
                    clearTimeout(this.transitionPoll);
                    this.transitionPoll = null;
                }
                if (this.pollTimer) {
                    clearInterval(this.pollTimer);
                    this.pollTimer = null;
                }
                if (this.logsRefreshTimer) {
                    clearTimeout(this.logsRefreshTimer);
                    this.logsRefreshTimer = null;
                }
                if (this.beszelInterval) {
                    clearInterval(this.beszelInterval);
                    this.beszelInterval = null;
                }
            }
        });
    }

    renderLogs(logs) {
        if (!logs || logs.length === 0) {
            this.logsBody.innerHTML = `<p class="text-slate-500 italic">${this.labels.noLogs}</p>`;
            this.logsCount.textContent = `0 ${this.labels.logEntries}`;
            return;
        }

        this.logsCount.textContent = logs.length + ' ' + this.labels.logEntries;

        const lines = logs.map(entry => {
            const level = (entry.level_name || 'INFO').toUpperCase();
            const channel = entry.channel_source || entry.channel || '';
            const msg = entry.message || '';
            const ctx = entry.context || {};
            const rawDt = entry.datetime || '';
            const dtPart = rawDt.includes('T')
                ? rawDt.split('T')[1]?.split('+')[0]?.split('-')[0]?.substring(0,8)
                : rawDt.substring(11,19);

            let levelClass = 'text-slate-400';
            if (['ERROR','CRITICAL','ALERT','EMERGENCY'].includes(level)) {
                levelClass = 'text-red-400 font-bold';
            } else if (level === 'WARNING') {
                levelClass = 'text-yellow-400';
            } else if (level === 'INFO') {
                levelClass = (channel === 'ssh' || ctx.command) ? 'text-blue-400' : 'text-cyan-400';
            } else if (level === 'DEBUG') {
                levelClass = 'text-slate-500';
            }

            const ctxFiltered = Object.fromEntries(Object.entries(ctx).filter(([k]) => k !== 'server_id'));
            const ctxStr = Object.keys(ctxFiltered).length ? ' <span class="text-slate-600">' + this.escapeHtml(JSON.stringify(ctxFiltered)) + '</span>' : '';

            let finalClass = levelClass;
            if (ctx.exit_code === 0 || (ctx.output && ctx.exit_code === undefined)) {
                finalClass = 'text-green-400';
            } else if (ctx.exit_code !== undefined && ctx.exit_code !== 0) {
                finalClass = 'text-red-400 font-bold';
            }

            const channelBadge = `<span class="text-[9px] px-1 py-0.5 rounded bg-slate-800 text-slate-500 mr-1.5 uppercase tracking-wider">${this.escapeHtml(channel)}</span>`;

            return `<div class="py-0.5 flex flex-wrap gap-1 items-baseline border-b border-slate-800/40">`
                + `<span class="text-slate-600 flex-shrink-0 mr-1">${this.escapeHtml(dtPart || '??:??:??')}</span>`
                + channelBadge
                + `<span class="${finalClass} flex-shrink-0">[${level}]</span> `
                + `<span class="text-slate-200 break-all">${this.escapeHtml(msg)}</span>`
                + ctxStr
                + `</div>`;
        });

        this.logsBody.innerHTML = lines.join('');

        if (this.logsAutoScroll?.checked) {
            this.logsBody.scrollTop = this.logsBody.scrollHeight;
        }
    }

    escapeHtml(str) {
        if (typeof str !== 'string') str = JSON.stringify(str);
        return str
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }
}

// Global initialization
window.ServerView = ServerView;
