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

Alpine.plugin(persist);
Alpine.plugin(collapse);
Alpine.start();
