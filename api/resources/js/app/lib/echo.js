/**
 * Reverb(WebSocket) 연결. 로그인 후 한 번만 만든다.
 * 앱은 쿠키가 아니라 토큰을 쓰므로 채널 인증도 /api/broadcasting/auth + Bearer 토큰.
 */
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { auth } from './auth';

window.Pusher = Pusher;

export function connectEcho() {
    if (window.Echo) return window.Echo;

    const scheme = import.meta.env.VITE_REVERB_SCHEME ?? 'https';

    window.Echo = new Echo({
        broadcaster: 'reverb',
        key: import.meta.env.VITE_REVERB_APP_KEY,
        wsHost: import.meta.env.VITE_REVERB_HOST ?? window.location.hostname,
        wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
        wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
        forceTLS: scheme === 'https',
        enabledTransports: ['ws', 'wss'],
        authEndpoint: '/api/broadcasting/auth',
        bearerToken: auth.token,
    });

    return window.Echo;
}

export function disconnectEcho() {
    window.Echo?.disconnect();
    window.Echo = undefined;
}
