import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            // 소셜링 앱(Vue SPA) 진입점. 폰트는 디자인대로 Google Fonts(Jua, Noto Sans KR)를 blade에서 로드
            input: ['resources/css/social.css', 'resources/js/app/main.js'],
            refresh: true,
        }),
        vue({
            template: {
                // laravel-vite-plugin이 상대 경로 asset URL을 처리하도록
                transformAssetUrls: { base: null, includeAbsolute: false },
            },
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
