import Alpine from 'alpinejs';
import persist from '@alpinejs/persist';
import collapse from '@alpinejs/collapse';

window.Alpine = Alpine;

if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch((error) => {
            console.error('Service worker registration failed:', error);
        });
    });
}

let deferredInstallPrompt = null;
const installButton = document.getElementById('pwa-install-button');

const handleInstallPrompt = async () => {
    if (!deferredInstallPrompt) {
        return;
    }

    deferredInstallPrompt.prompt();
    await deferredInstallPrompt.userChoice;
    deferredInstallPrompt = null;

    if (installButton) {
        installButton.hidden = true;
    }
};

window.addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault();
    deferredInstallPrompt = event;

    if (installButton) {
        installButton.hidden = false;
    }
});

window.addEventListener('appinstalled', () => {
    deferredInstallPrompt = null;

    if (installButton) {
        installButton.hidden = true;
    }
});

if (installButton) {
    installButton.addEventListener('click', handleInstallPrompt);
}

// --- Global processing modal -----------------------------------------------
// Every POST action shows a modal from the moment it is submitted until the
// server responds (navigation replaces the page, which hides it again). This
// guarantees the user always sees "something is happening" instead of a dead
// click, even for forms that were not explicitly wired with data-modal-action.
const ensureProcessModal = () => {
    let modal = document.getElementById('global-process-modal');
    if (modal) {
        return modal;
    }
    modal = document.createElement('div');
    modal.id = 'global-process-modal';
    modal.setAttribute('role', 'dialog');
    modal.setAttribute('aria-modal', 'true');
    modal.setAttribute('aria-live', 'assertive');
    modal.className = 'fixed inset-0 z-[1000000] flex items-center justify-center bg-black/40 backdrop-blur-sm';
    modal.style.display = 'none';
    modal.innerHTML = `
        <div class="mx-4 w-full max-w-sm rounded-2xl bg-white p-6 text-center shadow-2xl dark:bg-gray-900">
            <div class="mx-auto mb-4 h-12 w-12 animate-spin rounded-full border-4 border-brand-200 border-t-brand-600 dark:border-brand-900 dark:border-t-brand-400"></div>
            <p class="text-base font-semibold text-gray-800 dark:text-white">Memproses…</p>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Mohon tunggu, jangan tutup halaman ini.</p>
        </div>`;
    document.body.appendChild(modal);
    return modal;
};

const showProcessModal = () => {
    const modal = ensureProcessModal();
    modal.style.display = 'flex';
    document.body.classList.add('overflow-hidden');
};

const hideProcessModal = () => {
    const modal = document.getElementById('global-process-modal');
    if (modal) {
        modal.style.display = 'none';
    }
    document.body.classList.remove('overflow-hidden');
};


// --- Top progress bar -------------------------------------------------------
// One slim bar for every kind of waiting: full navigations, async posts and
// soft polls. Replaces the old full-screen preloader, which blocked the whole
// page for an artificial delay on every load.
let busyCount = 0;

const progressBar = () => document.getElementById('top-progress');

export const markBusy = () => {
    busyCount += 1;
    progressBar()?.classList.add('is-busy');
};

export const markIdle = () => {
    busyCount = Math.max(0, busyCount - 1);
    if (busyCount === 0) {
        progressBar()?.classList.remove('is-busy');
    }
};

// --- Session splash -----------------------------------------------------------
// Branded MITO loader, shown once per browser session on first paint and
// skipped synchronously in <head> on repeat visits. Fades fast: one short
// brand beat, then out of the way.
document.addEventListener('DOMContentLoaded', () => {
    const splash = document.getElementById('mito-splash');
    if (!splash) {
        return;
    }
    if (document.documentElement.classList.contains('mito-splash-seen')) {
        splash.remove();
        return;
    }
    window.setTimeout(() => {
        if (!splash.isConnected) {
            return;
        }
        splash.style.transition = 'opacity 0.25s ease';
        splash.style.opacity = '0';
        window.setTimeout(() => splash.remove(), 260);
        try {
            sessionStorage.setItem('mito-splash-seen', '1');
        } catch {
            // Private mode: splash simply shows again next visit.
        }
    }, 550);
});

// --- Toasts -----------------------------------------------------------------
// Non-blocking notices. Success toasts dismiss themselves; error toasts stay
// until dismissed so the message is still there when the user looks back.
const toastStack = () => document.getElementById('toast-stack');

export const showToast = (message, type = 'success') => {
    const stack = toastStack();
    if (!stack || !message) {
        return;
    }

    const colors = {
        success: 'border-success-200 bg-success-50 text-success-700 dark:border-success-500/20 dark:bg-success-500/10 dark:text-success-300',
        error: 'border-error-200 bg-error-50 text-error-700 dark:border-error-500/20 dark:bg-error-500/10 dark:text-error-400',
        info: 'border-gray-200 bg-white text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200',
    };

    const el = document.createElement('div');
    el.className = `toast flex items-start gap-2 rounded-xl border px-4 py-3 text-sm font-medium shadow-theme-md ${colors[type] ?? colors.info}`;
    el.setAttribute('role', type === 'error' ? 'alert' : 'status');

    const text = document.createElement('span');
    text.className = 'flex-1';
    text.textContent = message;
    el.appendChild(text);

    const close = document.createElement('button');
    close.type = 'button';
    close.setAttribute('aria-label', 'Dismiss');
    close.className = 'opacity-60 transition-opacity hover:opacity-100';
    close.textContent = '×';
    close.addEventListener('click', () => dismissToast(el));
    el.appendChild(close);

    stack.appendChild(el);

    if (type !== 'error') {
        window.setTimeout(() => dismissToast(el), 4000);
    }
};

const dismissToast = (el) => {
    if (!el.isConnected) {
        return;
    }
    el.classList.add('is-leaving');
    window.setTimeout(() => el.remove(), 200);
};

window.showToast = showToast;

// Flash messages rendered server-side become toasts on load, so a redirect
// still reads as one smooth notice instead of a stacked banner push.
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-flash-toast]').forEach((node) => {
        showToast(node.dataset.flashToast, node.dataset.flashType || 'success');
        node.remove();
    });
});

// --- Scroll reveal ----------------------------------------------------------
const revealObserver =
    'IntersectionObserver' in window
        ? new IntersectionObserver(
              (entries) => {
                  entries.forEach((entry) => {
                      if (entry.isIntersecting) {
                          entry.target.classList.add('is-visible');
                          revealObserver.unobserve(entry.target);
                      }
                  });
              },
              { rootMargin: '0px 0px -40px 0px', threshold: 0.05 },
          )
        : null;

const observeReveals = (root = document) => {
    if (!revealObserver) {
        root.querySelectorAll('.reveal').forEach((el) => el.classList.add('is-visible'));
        return;
    }
    root.querySelectorAll('.reveal:not(.is-visible)').forEach((el) => revealObserver.observe(el));
};

document.addEventListener('DOMContentLoaded', () => observeReveals());

// --- Buttons: busy state, no double submit -----------------------------------
document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) {
        return;
    }
    // GET filter forms stay instant; the bar alone is the feedback.
    if ((form.method || 'get').toLowerCase() === 'get') {
        markBusy();
        return;
    }
    // Any POST action gets a processing modal from submit until the server
    // responds (the next page load hides it again).
    showProcessModal();
    const submit = form.querySelector('button[type="submit"]');
    if (!submit || submit.disabled || form.dataset.noBusy !== undefined || form.matches('[data-modal-action]')) {
        return;
    }
    submit.disabled = true;
    submit.setAttribute('aria-busy', 'true');
    if (!submit.querySelector('.btn-spinner')) {
        const spinner = document.createElement('span');
        spinner.className = 'btn-spinner';
        spinner.setAttribute('aria-hidden', 'true');
        submit.prepend(spinner);
    }
    submit.classList.add('opacity-80');
});

document.addEventListener('DOMContentLoaded', () => {
    // Debounced search: typing filters without a click, and without losing
    // focus to a reload on every keystroke.
    document.querySelectorAll('input[data-autosearch]').forEach((input) => {
        let timer = null;
        input.addEventListener('input', () => {
            window.clearTimeout(timer);
            timer = window.setTimeout(() => {
                const form = input.closest('form');
                if (form && (form.method || 'get').toLowerCase() === 'get') {
                    markBusy();
                    form.requestSubmit();
                }
            }, 450);
        });
    });
});

window.addEventListener('pageshow', () => { markIdle(); hideProcessModal(); });

// --- Soft live regions: update in place, never full-reload -------------------
// Commands and services used `setTimeout(location.reload)`, which flashed the
// whole shell every 10-30s. These poll quietly and patch only their region.
const patchRegion = async (region) => {
    const url = region.dataset.pollUrl || window.location.href;
    try {
        const response = await fetch(url, {
            headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html' },
            credentials: 'same-origin',
        });
        if (!response.ok) {
            return;
        }
        const html = await response.text();
        const doc = new DOMParser().parseFromString(html, 'text/html');
        const fresh = doc.querySelector(`[data-live-region="${region.dataset.liveRegion}"]`);
        if (fresh) {
            region.innerHTML = fresh.innerHTML;
            observeReveals(region);
        }
        const stamp = document.querySelector('[data-live-stamp]');
        const freshStamp = doc.querySelector('[data-live-stamp]');
        if (stamp && freshStamp) {
            stamp.textContent = freshStamp.textContent;
        }
    } catch {
        // A failed poll is silence, not an error: the next tick retries and
        // the page keeps working with slightly older numbers.
    }
};

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-live-region][data-poll-interval]').forEach((region) => {
        const seconds = Number(region.dataset.pollInterval || '0');
        if (!seconds || seconds < 5) {
            return;
        }
        window.setInterval(() => {
            if (document.hidden) {
                return;
            }
            patchRegion(region);
        }, seconds * 1000);
    });

    // Fleet: the refresh endpoint already returns exactly the fields that
    // change, so patch cells directly instead of re-rendering the table.
    const fleet = document.querySelector('[data-fleet-refresh-url]');
    if (fleet) {
        const refreshFleet = async () => {
            if (document.hidden) {
                return;
            }
            try {
                const response = await fetch(fleet.dataset.fleetRefreshUrl, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
                    credentials: 'same-origin',
                });
                if (!response.ok) {
                    return;
                }
                const payload = await response.json();
                const online = document.querySelector('[data-fleet-online]');
                if (online && typeof payload.online === 'number') {
                    online.textContent = String(payload.online);
                }
                Object.entries(payload.devices || {}).forEach(([id, state]) => {
                    const row = document.querySelector(`[data-fleet-device="${id}"]`);
                    if (!row) {
                        return;
                    }
                    const reported = row.querySelector('[data-fleet-reported-at]');
                    if (reported && state.reported_at) {
                        reported.textContent = state.reported_at;
                    }

                    const badge = row.querySelector('[data-fleet-status-badge]');
                    if (badge && state.status !== 'revoked') {
                        // Same vocabulary as the first paint, in one table rather
                        // than a nested ternary: anything the server invents later
                        // renders as itself instead of falling through a chain.
                        const labels = {
                            online: 'online',
                            service: 'service active · tray not detected',
                            stale: 'stale',
                            'never scanned': 'never scanned',
                        };
                        badge.textContent = labels[state.status] ?? state.status;
                        badge.classList.toggle('bg-success-50', state.status === 'online');
                        badge.classList.toggle('text-success-600', state.status === 'online');
                        badge.classList.toggle('dark:bg-success-500/15', state.status === 'online');
                        badge.classList.toggle('dark:text-success-500', state.status === 'online');
                        badge.classList.toggle('bg-brand-50', state.status === 'service');
                        badge.classList.toggle('text-brand-500', state.status === 'service');
                        badge.classList.toggle('dark:bg-brand-500/15', state.status === 'service');
                        badge.classList.toggle('dark:text-brand-400', state.status === 'service');
                        badge.classList.toggle('bg-warning-50', state.status === 'stale');
                        badge.classList.toggle('text-warning-600', state.status === 'stale');
                        badge.classList.toggle('dark:bg-warning-500/15', state.status === 'stale');
                        badge.classList.toggle('dark:text-warning-500', state.status === 'stale');
                        badge.classList.toggle('bg-gray-100', !['online', 'service', 'stale'].includes(state.status));
                        badge.classList.toggle('text-gray-600', !['online', 'service', 'stale'].includes(state.status));
                        badge.classList.toggle('dark:bg-gray-700/50', !['online', 'service', 'stale'].includes(state.status));
                        badge.classList.toggle('dark:text-gray-400', !['online', 'service', 'stale'].includes(state.status));
                    }

                    // Why the device is offline, when the server can say. The
                    // server sends no reason for a device it has heard from, so
                    // this is a straight show/hide rather than a second copy of
                    // "which statuses count as healthy".
                    const reason = row.querySelector('[data-fleet-reason]');
                    if (reason) {
                        if (state.reason) {
                            reason.textContent = state.reason;
                            reason.classList.remove('hidden');
                        } else {
                            reason.textContent = '';
                            reason.classList.add('hidden');
                        }
                    }
                    const battery = row.querySelector('[data-fleet-battery]');
                    if (battery && state.battery_percent !== null && state.battery_percent !== undefined) {
                        battery.innerHTML = `<span class="font-medium ${state.battery_alert ? 'text-error-500' : 'text-gray-700 dark:text-gray-300'}">${state.battery_percent}%</span><span class="block text-theme-xs text-gray-500 dark:text-gray-400">health ${state.battery_health ?? '—'}%</span>`;
                    }
                    const disk = row.querySelector('[data-fleet-disk]');
                    if (disk && state.disk_free_percent !== null && state.disk_free_percent !== undefined) {
                        disk.innerHTML = `<span class="font-medium ${state.disk_free_percent <= 10 ? 'text-error-500' : 'text-gray-700 dark:text-gray-300'}">${state.disk_free_percent}%</span><span class="block text-theme-xs text-gray-500 dark:text-gray-400">${state.disk_free_gb ?? '—'} GB free</span>`;
                    }
                });
            } catch {
                // Same rule as above: silence, retry next tick.
            }
        };
        window.setInterval(refreshFleet, 15000);
    }
});

Alpine.plugin(persist);
Alpine.plugin(collapse);
Alpine.start();
