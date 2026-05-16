/* public/js/modules/monitoring.js */
const Monitoring = {
    // Polls health for a list of servers using the batch API
    pollFleetHealth: async function(callback) {
        try {
            const response = await fetch('/api/servers/health-batch');
            const data = await response.json();
            if (data.servers && callback) {
                callback(data.servers);
            }
        } catch (e) {
            console.error('Fleet health poll failed:', e);
        }
    },

    // Fetches individual server stats from Beszel (legacy/detailed)
    fetchServerDetails: async function(ip) {
        try {
            const response = await fetch(`/api/monitoring/beszel/${ip}`);
            return await response.json();
        } catch (e) {
            return { success: false };
        }
    },

    // Formats bandwidth for display
    formatBandwidth: function(bps) {
        const mbps = (bps * 8) / 1000000;
        return mbps.toFixed(1) + ' Mbps';
    }
};

window.NK_Monitoring = Monitoring;
