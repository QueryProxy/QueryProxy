import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
            // latin-ext is not optional: without it Ş/ş, Ğ/ğ and İ fall back to a
            // system face mid-word, which mangles most Turkish names in the UI.
            fonts: [
                bunny('Space Grotesk', {
                    weights: [600],
                    subsets: ['latin', 'latin-ext'],
                    preload: [{ weight: 600 }],
                }),
                bunny('IBM Plex Sans', {
                    weights: [400, 500, 600],
                    subsets: ['latin', 'latin-ext'],
                    preload: [{ weight: 400 }],
                }),
                bunny('IBM Plex Mono', {
                    weights: [400, 500],
                    subsets: ['latin', 'latin-ext'],
                    preload: [{ weight: 400 }],
                }),
            ],
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
