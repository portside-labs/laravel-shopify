import AppLayout from '@/layouts/app-layout';
import { createInertiaApp, http, router } from '@inertiajs/react';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

// App Bridge issues a short-lived ID token that identifies the shop and the
// staff member using the app. Sending it with every request keeps the app
// signed in without relying on cookies inside the Shopify admin's iframe.
http.onRequest(async (config) => {
    config.headers = {
        ...config.headers,
        Authorization: `Bearer ${await shopify.idToken()}`,
    };

    return config;
});

// When the app can no longer be authenticated, reload the page so that it
// bounces through App Bridge for a fresh ID token.
router.on('httpException', (event) => {
    if (event.detail.response.status === 401) {
        event.preventDefault();
        window.location.reload();
    }
});

// The admin's own loading bar reflects Inertia visits...
router.on('start', () => shopify.loading(true));
router.on('finish', () => shopify.loading(false));

// ...and flash messages surface as admin toasts.
router.on('flash', (event) => {
    if (event.detail.flash.toast) {
        shopify.toast.show(event.detail.flash.toast);
    }
});

// Navigation items are rendered in the admin's sidebar, outside of the
// iframe, so App Bridge announces clicks on them for Inertia to handle.
document.addEventListener('shopify:navigate', (event) => {
    const href = (event.target as HTMLElement | null)?.getAttribute('href');

    if (href) {
        event.preventDefault();
        router.visit(href);
    }
});

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: () => AppLayout,
    progress: false,
});
