import '../css/app.css';
import './bootstrap';

import { createInertiaApp, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, h } from 'vue';
import { ZiggyVue } from 'ziggy-js';
import i18n, { applyLocale } from './i18n';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

// Glob patterns for pages
const pages = import.meta.glob('./Pages/**/*.vue');
const pluginPages = import.meta.glob('../../plugins/*/resources/js/Pages/**/*.vue');

// Keep the running i18n instance in sync with the server-resolved locale on
// every Inertia navigation. Without this the language only applied on a hard
// page load: after a client-side transition (e.g. logging in, which navigates
// to /dashboard without re-booting the app) the new `locale` prop arrived but
// was never applied, so the UI stayed in the boot language until a manual
// reload. The shared prop already carries the correct value on every response.
router.on('navigate', (event) => {
    const locale = event?.detail?.page?.props?.locale;
    if (locale) {
        applyLocale(locale);
    }
});

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) => {
        // Check if this is a plugin page (format: Plugin::PluginName/PagePath)
        if (name.startsWith('Plugin::')) {
            const [, pluginPath] = name.split('::');
            const [pluginName, ...pagePath] = pluginPath.split('/');
            const pageName = pagePath.join('/');
            const path = `../../plugins/${pluginName}/resources/js/Pages/${pageName}.vue`;

            if (pluginPages[path]) {
                return pluginPages[path]();
            }

            // Fallback: try to resolve from main pages
            console.warn(`Plugin page not found: ${path}, falling back to main pages`);
        }

        // Default: resolve from main pages
        return resolvePageComponent(
            `./Pages/${name}.vue`,
            pages,
        );
    },
    setup({ el, App, props, plugin }) {
        // Honour the locale the server resolved (SetLocale reads the user's
        // saved preference; HandleInertiaRequests shares it as `locale`).
        // localStorage covers fast client-side switches; document.lang and 'en'
        // are last-resort fallbacks.
        const serverLocale = props?.initialPage?.props?.locale;
        applyLocale(
            serverLocale
            || localStorage.getItem('locale')
            || document.documentElement.lang
            || 'en'
        );

        return createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .use(i18n)
            .mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});
