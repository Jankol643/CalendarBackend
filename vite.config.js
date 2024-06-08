import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css',
            'resources/css/calendar.css',
            'resources/sass/calendar.scss',
            'resources/js/app.js'],
            refresh: true,
        }),
    ],
});
