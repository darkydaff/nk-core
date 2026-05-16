/* public/js/core.js */
window.NK = {
    // Global Toast System
    toast: function(message, type = 'info') {
        let toastContainer = document.getElementById('toast-container');
        if (!toastContainer) {
            toastContainer = document.createElement('div');
            toastContainer.id = 'toast-container';
            document.body.appendChild(toastContainer);
        }

        // Force container to Top Right with explicit styles
        Object.assign(toastContainer.style, {
            position: 'fixed',
            top: '1.5rem',
            right: '1.5rem',
            zIndex: '10000',
            display: 'flex',
            flexDirection: 'column',
            gap: '0.75rem',
            maxWidth: 'calc(100vw - 3rem)',
            width: '400px',
            pointerEvents: 'none',
            alignItems: 'flex-end'
        });

        const toast = document.createElement('div');
        const colors = {
            success: 'bg-emerald-500/10 border-emerald-500/20 text-emerald-400',
            error: 'bg-red-500/10 border-red-500/20 text-red-400',
            warning: 'bg-amber-500/10 border-amber-500/20 text-amber-400',
            info: 'bg-primary/10 border-primary/20 text-primary'
        };

        const icons = {
            success: 'fa-check-circle',
            error: 'fa-exclamation-circle',
            warning: 'fa-exclamation-triangle',
            info: 'fa-info-circle'
        };

        toast.className = `flex items-center p-4 rounded-xl border ${colors[type]} shadow-2xl transition-all duration-300 transform translate-x-8 opacity-0 w-full backdrop-blur-md`;
        toast.innerHTML = `<i class="fas ${icons[type]} mr-3 flex-shrink-0"></i><span class="text-sm font-bold tracking-tight">${message}</span>`;
        
        // Add to top of list
        toastContainer.prepend(toast);
        
        // Trigger animation
        requestAnimationFrame(() => {
            toast.classList.remove('translate-x-8', 'opacity-0');
        });

        setTimeout(() => {
            toast.classList.add('translate-x-8', 'opacity-0');
            setTimeout(() => toast.remove(), 300);
        }, 6000);
    },

    // Unified Status Badge Generator
    renderStatusBadge: function(dbStatus, connStatus, labels) {
        const isOnline = connStatus === 'online';
        const isNever = connStatus === 'never';
        const status = dbStatus || 'active';
        
        const isRevoked = status === 'disabled' || status === 'revoked';
        const isDeleting = status === 'deleting';
        const isProvisioning = status === 'provisioning';
        const isVerifying = status === 'verifying';
        const isError = status === 'error';

        let badgeClass = 'bg-slate-500/10 border-slate-500/20 text-slate-500';
        let dotClass = 'bg-slate-400';
        let statusText = labels.offline || 'Offline';

        if (isRevoked) {
            badgeClass = 'bg-amber-500/10 border-amber-500/20 text-amber-500';
            dotClass = 'bg-amber-500';
            statusText = labels.revoked || 'Revoked';
        } else if (isDeleting) {
            badgeClass = 'bg-red-500/10 border-red-500/20 text-red-500';
            dotClass = 'bg-red-500 animate-pulse';
            statusText = labels.deleting || 'Deleting';
        } else if (isProvisioning) {
            badgeClass = 'bg-sky-500/10 border-sky-500/20 text-sky-500';
            dotClass = 'bg-sky-500 animate-pulse';
            statusText = labels.provisioning || 'Provisioning';
        } else if (isVerifying) {
            badgeClass = 'bg-sky-500/10 border-sky-500/20 text-sky-500';
            dotClass = 'bg-sky-500 animate-pulse';
            statusText = labels.verifying || 'Verifying';
        } else if (isError) {
            badgeClass = 'bg-red-500/10 border-red-500/20 text-red-500';
            dotClass = 'bg-red-600';
            statusText = labels.error || 'Error';
        } else if (isOnline) {
            badgeClass = 'bg-sky-500/10 border-sky-500/20 text-sky-500';
            dotClass = 'bg-sky-500 animate-pulse';
            statusText = labels.online || 'Online';
        } else if (isNever) {
            badgeClass = 'bg-slate-500/10 border-slate-500/20 text-slate-500';
            dotClass = 'bg-slate-400';
            statusText = labels.never || 'Never';
        }

        return `
            <div class="inline-flex items-center justify-center px-2 py-0.5 rounded-md ${badgeClass} border w-fit font-bold tracking-wide uppercase text-[10px] leading-none">
                <span class="w-1 h-1 rounded-full ${dotClass} mr-1.5 flex-shrink-0"></span>
                ${statusText}
            </div>
        `;
    },

    // Centralized AJAX Action Handler
    handleAjaxAction: async function(url, data = {}, confirmMsg = null) {
        if (confirmMsg && !confirm(confirmMsg)) return;

        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content
                },
                body: JSON.stringify(data)
            });

            const result = await response.json();
            if (result.success) {
                if (result.message) this.toast(result.message, 'success');
                if (result.redirect) window.location.href = result.redirect;
                else if (result.reload) window.location.reload();
            } else {
                this.toast(result.error || 'Action failed', 'error');
            }
        } catch (error) {
            this.toast('Network error: ' + error.message, 'error');
        }
    },

    // Centralized Form Handler
    handleAjaxForm: async function(event, url, hasFile = false) {
        event.preventDefault();
        const form = event.target;
        const submitBtn = form.querySelector('button[type="submit"]');
        const originalBtnHtml = submitBtn ? submitBtn.innerHTML : null;

        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-circle-notch fa-spin mr-2"></i> Processing...';
        }

        try {
            const formData = hasFile ? new FormData(form) : new URLSearchParams(new FormData(form));
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    ...(!hasFile ? { 'Content-Type': 'application/x-www-form-urlencoded' } : {})
                },
                body: formData
            });

            const result = await response.json();
            if (result.success) {
                if (result.message) this.toast(result.message, 'success');
                if (result.redirect) window.location.href = result.redirect;
                else if (result.reload) window.location.reload();
            } else {
                this.toast(result.error || 'Form submission failed', 'error');
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalBtnHtml;
                }
            }
        } catch (error) {
            this.toast('Network error: ' + error.message, 'error');
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalBtnHtml;
            }
        }
    },
    // Centralized Copy Utility
    copyToClipboard: async function(text, btn) {
        try {
            if (navigator.clipboard && window.isSecureContext) {
                await navigator.clipboard.writeText(text);
            } else {
                const textArea = document.createElement("textarea");
                textArea.value = text;
                textArea.style.position = "fixed";
                textArea.style.left = "-9999px";
                document.body.appendChild(textArea);
                textArea.focus();
                textArea.select();
                document.execCommand('copy');
                textArea.remove();
            }

            if (btn) {
                const icon = btn.querySelector('i');
                const originalHtml = btn.innerHTML;
                if (icon) {
                    const originalClass = icon.className;
                    icon.className = 'fas fa-check text-emerald-500';
                    setTimeout(() => icon.className = originalClass, 2000);
                } else {
                    btn.innerText = 'Copied!';
                    setTimeout(() => btn.innerText = originalHtml, 2000);
                }
            }
            this.toast('Copied to clipboard', 'success');
        } catch (err) {
            this.toast('Failed to copy', 'error');
        }
    }
};

// Legacy compatibility aliases
window.handleAjaxAction = (url, data, msg) => NK.handleAjaxAction(url, data, msg);
window.handleAjaxForm = (event, url, file) => NK.handleAjaxForm(event, url, file);
window.showToast = (msg, type) => NK.toast(msg, type);
window.copyToClipboard = (text, btn) => NK.copyToClipboard(text, btn);
