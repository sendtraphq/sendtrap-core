import { onMounted, onUnmounted } from 'vue';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

/**
 * Plan 06 Phase 3b slice 9 (§4.2): lazily constructs window.Echo on first
 * use (rather than resources/js/echo.js's former unconditional `window.Echo
 * = new Echo(...)` at every page's load time) and degrades to a silent
 * no-op — never a hard error — when Reverb isn't configured
 * (VITE_REVERB_APP_KEY unset), a plausible Community config. Cloud always
 * has this configured, so its behavior is unchanged: Echo still connects,
 * just constructed the first time a component actually needs it instead of
 * on every request regardless of whether that request's page uses live
 * updates at all.
 */
function ensureEcho() {
    if (typeof window === 'undefined') return null;
    if (window.Echo) return window.Echo;

    const key = import.meta.env.VITE_REVERB_APP_KEY;
    if (!key) return null;

    window.Pusher = Pusher;
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;

    window.Echo = new Echo({
        broadcaster: 'reverb',
        key,
        wsHost: import.meta.env.VITE_REVERB_HOST,
        wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
        wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
        forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
        enabledTransports: ['ws', 'wss'],
        // Private/presence channels authorize against /broadcasting/auth,
        // which is protected by the web (session + CSRF) middleware.
        auth: {
            headers: {
                'X-CSRF-TOKEN': csrf,
            },
        },
    });

    return window.Echo;
}

/**
 * Subscribe to one event on a private channel for the lifetime of the
 * calling component. `channelName` may be a getter function so the caller
 * can react to a prop changing (e.g. a different inbox id); it's only
 * re-read at mount time here, matching the original Inboxes/Show.vue's own
 * subscribe-once-on-mount behavior.
 *
 * @param {string} channelName
 * @param {string} eventName
 * @param {(payload: any) => void} handler
 */
export function usePrivateChannel(channelName, eventName, handler) {
    let channel = null;

    onMounted(() => {
        const echo = ensureEcho();
        if (!echo) return;
        channel = echo.private(channelName);
        channel.listen(eventName, handler);
    });

    onUnmounted(() => {
        if (channel) window.Echo?.leave(channelName);
    });
}
