/* public/js/modules/dashboard.js */
const Dashboard = {
    chart: null,
    labels: {},
    lastTraffic: null,

    init: function(config) {
        this.labels = config.labels;
        this.initChart();
        this.initSearch();
        this.startPolling();
    },

    initChart: function() {
        const ctx = document.getElementById('trafficChart')?.getContext('2d');
        if (!ctx) return;

        const getThemeColors = () => {
            const isDark = document.documentElement.classList.contains('dark');
            return {
                grid: isDark ? 'rgba(255, 255, 255, 0.05)' : 'rgba(0, 0, 0, 0.05)',
                text: isDark ? 'rgba(255, 255, 255, 0.5)' : 'rgba(0, 0, 0, 0.5)',
                tooltipBg: isDark ? '#111421' : '#ffffff',
                tooltipText: isDark ? '#f8fafc' : '#1c1917',
                tooltipBorder: isDark ? '#23293d' : '#e6e1d6'
            };
        };

        const colors = getThemeColors();
        this.chart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: [],
                datasets: [
                    {
                        label: this.labels.download,
                        borderColor: '#10b981',
                        backgroundColor: 'rgba(16, 185, 129, 0.1)',
                        fill: true,
                        tension: 0.4,
                        data: []
                    },
                    {
                        label: this.labels.upload,
                        borderColor: '#3b82f6',
                        backgroundColor: 'rgba(59, 130, 246, 0.1)',
                        fill: true,
                        tension: 0.4,
                        data: []
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: colors.tooltipBg,
                        titleColor: colors.tooltipText,
                        bodyColor: colors.tooltipText,
                        borderColor: colors.tooltipBorder,
                        borderWidth: 1,
                        padding: 10,
                        bodyFont: { size: 11, weight: '600' },
                        titleFont: { size: 10, weight: '700' },
                        callbacks: {
                            label: (context) => ` ${context.dataset.label}: ${context.parsed.y.toFixed(2)} Mbps`
                        }
                    }
                },
                scales: {
                    y: { 
                        grid: { color: colors.grid }, 
                        ticks: { 
                            color: colors.text,
                            callback: (value) => value + ' Mb/s'
                        } 
                    },
                    x: { grid: { display: false }, ticks: { color: colors.text } }
                }
            }
        });

        // Load history
        fetch('/api/monitoring/traffic-history')
            .then(r => r.json())
            .then(data => {
                if (data.history) {
                    this.chart.data.labels = data.history.map(h => h.label);
                    this.chart.data.datasets[0].data = data.history.map(h => h.speed_down_mb);
                    this.chart.data.datasets[1].data = data.history.map(h => h.speed_up_mb);
                    this.chart.update('none');
                }
            });

        window.addEventListener('themeChanged', () => {
            const nc = getThemeColors();
            this.chart.options.scales.y.grid.color = nc.grid;
            this.chart.options.scales.y.ticks.color = nc.text;
            this.chart.options.scales.x.ticks.color = nc.text;
            this.chart.update();
        });
    },

    initSearch: function() {
        const input = document.getElementById('fleetSearch');
        if (!input) return;

        let timeout;
        input.addEventListener('input', () => {
            clearTimeout(timeout);
            timeout = setTimeout(() => this.performSearch(), 300);
        });

        ['statusFilter', 'trafficFilter', 'sortFilter'].forEach(id => {
            document.getElementById(id)?.addEventListener('change', () => this.performSearch());
        });

        this.performSearch();
    },

    performSearch: async function(isAutoRefresh = false) {
        const q = document.getElementById('fleetSearch').value;
        const status = document.getElementById('statusFilter').value;
        const traffic = document.getElementById('trafficFilter').value;
        const sort = document.getElementById('sortFilter').value;

        try {
            const response = await fetch(`/api/search-clients?q=${encodeURIComponent(q)}&status=${status}&traffic=${traffic}&sort=${sort}`);
            const data = await response.json();
            this.renderResults(data.results);
            
            // Update Summary Cards if present
            if (data.summary) {
                const s = data.summary;
                const totalEl = document.getElementById('summary-total-clients');
                const activeEl = document.getElementById('summary-active-clients');
                const downEl = document.getElementById('summary-total-download');
                const upEl = document.getElementById('summary-total-upload');

                if (totalEl) totalEl.innerText = s.total_clients;
                if (activeEl) {
                    const dot = activeEl.querySelector('.rounded-full');
                    activeEl.innerHTML = (dot ? dot.outerHTML : '') + ` ${s.active_clients} ${this.labels.online || 'Online'}`;
                }
                if (downEl) downEl.innerText = (s.traffic.received / 1073741824).toFixed(2) + ' GB';
                if (upEl) upEl.innerText = (s.traffic.sent / 1073741824).toFixed(2) + ' GB';
            }

            if (data.summary?.traffic) this.updateChart(data.summary.traffic);
        } catch (e) {
            console.error('Search failed:', e);
        } finally {
            // Schedule next refresh
            if (this.pollTimer) clearTimeout(this.pollTimer);
            this.pollTimer = setTimeout(() => {
                if (document.visibilityState === 'visible') {
                    this.performSearch(true);
                } else {
                    this.pollTimer = setTimeout(() => this.performSearch(true), 30000);
                }
            }, 10000);
        }
    },

    updateChart: function(traffic) {
        if (!this.chart || !this.lastTraffic) {
            this.lastTraffic = traffic;
            return;
        }

        // Calculate bits per second from byte diff
        // 15 seconds is the polling interval
        const diffDown = Math.max(0, (traffic.received - this.lastTraffic.received));
        const diffUp = Math.max(0, (traffic.sent - this.lastTraffic.sent));
        this.lastTraffic = traffic;

        const mbpsDown = (diffDown * 8) / 15 / 1024 / 1024;
        const mbpsUp = (diffUp * 8) / 15 / 1024 / 1024;

        if (this.chart.data.labels.length >= 60) {
            this.chart.data.labels.shift();
            this.chart.data.datasets[0].data.shift();
            this.chart.data.datasets[1].data.shift();
        }

        this.chart.data.labels.push(new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' }));
        this.chart.data.datasets[0].data.push(mbpsDown);
        this.chart.data.datasets[1].data.push(mbpsUp);
        this.chart.update('none');
    },

    renderResults: function(results) {
        const container = document.getElementById('searchResults');
        if (!container) return;

        if (!results || results.length === 0) {
            container.innerHTML = `<div class="p-20 text-center opacity-30 animate-in fade-in slide-in-from-bottom-4">
                <i class="fas fa-search text-4xl mb-4"></i>
                <p class="text-sm font-medium tracking-tight">${this.labels.noMatches || 'No matches found'}</p>
            </div>`;
            return;
        }

        const queryInput = document.getElementById('fleetSearch');
        const query = queryInput ? queryInput.value.trim() : '';

        let html = `
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="text-[10px] uppercase tracking-wider text-muted border-b border-default/50 bg-panel/20">
                            <th class="px-5 py-3 w-10">
                                <input type="checkbox" id="selectAllClients" onchange="NK_Dashboard.toggleSelectAll(this)" class="rounded border-default bg-base text-primary focus:ring-primary/20 cursor-pointer">
                            </th>
                            <th class="px-5 py-3 font-bold">${this.labels.client || 'Client'}</th>
                            <th class="px-5 py-3 font-bold text-left whitespace-nowrap">${this.labels.status || 'Status'} / IP</th>
                            <th class="px-5 py-3 font-bold text-right whitespace-nowrap">${this.labels.traffic || 'Traffic'} / ${this.labels.lastSeenLabel || 'Seen'}</th>
                            <th class="px-5 py-3 font-bold text-right whitespace-nowrap">${this.labels.actions || 'Actions'}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-default/30">
        `;

        results.forEach(c => {
            const isOnline = c.connection_status === 'online';
            const dbStatus = c.db_status || c.status;
            const isRevoked = dbStatus === 'disabled' || dbStatus === 'revoked';

            const highlightedName = NK.highlightMatch(c.name, query);
            const highlightedIp = NK.highlightMatch(c.external_ip || 'No IP', query);

            html += `
                <tr class="group hover:bg-surface-hover/30 transition-colors animate-in fade-in duration-300">
                    <td class="px-5 py-4">
                        <input type="checkbox" name="client_ids[]" value="${c.id}" onchange="NK_Dashboard.updateBatchUI()" class="client-checkbox rounded border-default bg-base text-primary focus:ring-primary/20 cursor-pointer">
                    </td>
                    <td class="px-5 py-4">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-lg bg-base border border-default flex items-center justify-center text-2xl group-hover:bg-primary/5 transition-colors shadow-sm flex-shrink-0 overflow-hidden leading-none">
                                ${c.flag || '<i class="fas fa-user text-xs text-muted"></i>'}
                            </div>
                            <div>
                                <div class="text-sm font-bold ${isRevoked ? 'text-muted line-through' : 'text-primary'} flex items-center gap-2">
                                    ${highlightedName}
                                </div>
                                <div class="text-[10px] text-muted font-mono uppercase tracking-widest flex items-center gap-1.5 mt-0.5 opacity-60">
                                    <i class="fas fa-server text-[9px]"></i>
                                    ${c.server_name}
                                </div>
                            </div>
                        </div>
                    </td>
                    <td class="px-5 py-4 text-left whitespace-nowrap">
                        <div class="flex flex-col gap-1">
                            ${NK.renderStatusBadge(dbStatus, c.connection_status, this.labels)}
                            <code class="text-[10px] text-secondary font-mono">${highlightedIp}</code>
                        </div>
                    </td>
                    <td class="px-5 py-4 text-right whitespace-nowrap">
                        <div class="flex flex-col items-end gap-1">
                            <div class="flex flex-col items-end font-mono">
                                <div class="flex items-center gap-2 text-[11px] font-bold text-primary">
                                    <i class="fas fa-exchange-alt text-[9px] opacity-40"></i>
                                    ${c.total_traffic || '0.00 MB'}
                                </div>
                                ${isOnline ? `
                                    <div class="flex items-center gap-1.5 text-[9px] mt-1">
                                        <span class="inline-flex items-center justify-center px-1.5 py-0.5 rounded-md bg-emerald-500/10 text-emerald-500 border border-emerald-500/20 font-bold leading-none">
                                            <i class="fas fa-arrow-down mr-1 opacity-70"></i> ${c.speed_down}
                                        </span>
                                        <span class="inline-flex items-center justify-center px-1.5 py-0.5 rounded-md bg-primary/10 text-primary border border-primary/20 font-bold leading-none">
                                            <i class="fas fa-arrow-up mr-1 opacity-70"></i> ${c.speed_up}
                                        </span>
                                    </div>
                                ` : ''}
                            </div>
                            <span class="text-[10px] text-muted uppercase tracking-tighter font-medium">${c.last_seen || '-'}</span>
                        </div>
                    </td>
                    <td class="px-5 py-4 text-right whitespace-nowrap">
                        <div class="flex items-center justify-end gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                            <button onclick="NK_Dashboard.clientAction(${c.id}, 'sync-stats', 'Sync stats for ${c.name}?')" class="p-1.5 text-muted hover:text-cyan-400 transition-colors" title="${this.labels.sync}">
                                <i class="fa-solid fa-rotate text-[11px]"></i>
                            </button>
                            <button onclick="NK_Dashboard.clientAction(${c.id}, '${isRevoked ? 'restore' : 'revoke'}', '${isRevoked ? 'Restore' : 'Revoke'} client ${c.name}?')" class="p-1.5 ${isRevoked ? 'text-green-500 hover:text-green-400' : 'text-muted hover:text-orange-400'} transition-colors" title="${isRevoked ? this.labels.restore : this.labels.revoke}">
                                <i class="fas ${isRevoked ? 'fa-user-check' : 'fa-user-slash'} text-xs"></i>
                            </button>
                            <button onclick="NK_Dashboard.clientAction(${c.id}, 'delete', 'Delete client ${c.name}?')" class="p-1.5 text-muted hover:text-red-500 transition-colors" title="${this.labels.delete}">
                                <i class="fas fa-trash-alt text-xs"></i>
                            </button>
                            <a href="/clients/${c.id}" class="ml-2 text-[10px] bg-panel hover:bg-primary text-secondary hover:text-white px-2 py-1 rounded border border-default uppercase font-bold tracking-wider transition-all shadow-sm">
                                ${this.labels.edit || 'Edit'}
                            </a>
                        </div>
                    </td>
                </tr>
            `;
        });

        html += `
                    </tbody>
                </table>
            </div>
        `;
        container.innerHTML = html;
    },

    toggleSelectAll: function(master) {
        document.querySelectorAll('.client-checkbox').forEach(cb => {
            cb.checked = master.checked;
        });
        this.updateBatchUI();
    },

    updateBatchUI: function() {
        const selected = document.querySelectorAll('.client-checkbox:checked');
        const bar = document.getElementById('batchActionBar');
        const text = document.getElementById('selectedCountText');
        
        if (selected.length > 0) {
            bar.classList.remove('hidden');
            bar.classList.add('flex');
            text.innerText = `${selected.length} SELECTED`;
        } else {
            bar.classList.add('hidden');
            bar.classList.remove('flex');
        }
    },

    clientAction: async function(id, action, confirmMsg = null) {
        const result = await NK.handleAjaxAction(`/clients/${id}/${action}`, {}, confirmMsg);
        if (result && result.success) {
            this.performSearch(true);
        }
    },

    handleBatchAction: async function(action) {
        const selectedIds = Array.from(document.querySelectorAll('.client-checkbox:checked')).map(cb => cb.value);
        if (selectedIds.length === 0) return;

        const confirmMsg = action === 'delete' ? this.labels.confirmDelete : null;
        if (confirmMsg && !confirm(confirmMsg)) return;

        const result = await NK.handleAjaxAction('/clients/batch', { ids: selectedIds, action: action }, null);
        if (result && result.success) {
            this.performSearch(true);
        }
    },

    startPolling: function() {
        // performSearch handles its own polling via hardened timeout loop
        setInterval(() => {
            NK_Monitoring.pollFleetHealth((serverStatuses) => {
                Object.entries(serverStatuses).forEach(([id, status]) => {
                    const card = document.querySelector(`[data-server-id="${id}"]`);
                    if (!card) return;
                    
                    // Update status indicators if they exist
                    const statusDot = card.querySelector('.server-status-dot');
                    if (statusDot) {
                        const isOnline = status.toLowerCase() === 'online' || status.toLowerCase() === 'active';
                        statusDot.className = `server-status-dot w-2 h-2 rounded-full ${isOnline ? 'bg-emerald-500 animate-pulse' : 'bg-red-500'}`;
                    }
                });
            });
        }, 15000);
    }
};

window.NK_Dashboard = Dashboard;
