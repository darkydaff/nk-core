/* public/js/modules/settings.js */
const Settings = {
    init: function() {
        this.initTabs();
        this.initFileUpload();
    },

    initTabs: function() {
        const hash = window.location.hash.substring(1);
        if (hash) {
            this.showTab(hash);
        }
    },

    showTab: function(tab) {
        document.querySelectorAll('[id^="content-"]').forEach(el => el.classList.add('hidden'));
        const content = document.getElementById('content-' + tab);
        if (content) content.classList.remove('hidden');
        
        document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('tab-btn-active'));
        const btn = document.getElementById('tab-' + tab);
        if (btn) btn.classList.add('tab-btn-active');
        
        window.location.hash = tab;
    },

    confirmRestore: function(key, type) {
        document.getElementById('restoreKeyInput').value = key;
        document.getElementById('restoreTypeInput').value = type;
        document.getElementById('restoreModal').classList.remove('hidden');
    },

    closeRestoreModal: function() {
        document.getElementById('restoreModal').classList.add('hidden');
    },

    updateFileName: function(input) {
        const label = document.getElementById('file-name-label');
        if (input.files && input.files[0]) {
            label.textContent = input.files[0].name;
            label.classList.add('text-primary');
            label.classList.remove('text-secondary');
        }
    },

    deleteBackup: function(key, type, confirmMsg) {
        handleAjaxAction('/settings/delete-backup', { key: key, type: type }, confirmMsg);
    }
};

window.NK_Settings = Settings;
