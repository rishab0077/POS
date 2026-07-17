import { cpSync, mkdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

const staticAssets = {
    'resources/js/jquery.min.js': 'public/js/jquery.min.js',
    'resources/js/pos.js': 'public/js/pos.js',
    'resources/js/KOT.js': 'public/js/KOT.js',
    'node_modules/chart.js/dist/chart.umd.js': 'public/js/chart.js',
    'node_modules/flatpickr/dist/flatpickr.min.js': 'public/js/datepicker.js',
    'resources/js/datatable/buttons.dataTables.js': 'public/js/datatable/buttons.dataTables.js',
    'resources/js/datatable/buttons.print.min.js': 'public/js/datatable/buttons.print.min.js',
    'resources/js/datatable/dataTables.buttons.js': 'public/js/datatable/dataTables.buttons.js',
    'resources/js/datatable/dataTables.min.js': 'public/js/datatable/dataTables.min.js',
    'resources/js/datatable/html5.min.js': 'public/js/datatable/html5.min.js',
    'resources/js/datatable/jszip.min.js': 'public/js/datatable/jszip.min.js',
    'resources/js/datatable/pdfmake.min.js': 'public/js/datatable/pdfmake.min.js',
    'resources/js/datatable/vfs_fonts.js': 'public/js/datatable/vfs_fonts.js',
    'node_modules/sortablejs/Sortable.min.js': 'public/js/Sortable.min.js',
    'node_modules/flatpickr/dist/flatpickr.css': 'public/css/flatpickr.css',
    'resources/css/pos.css': 'public/css/pos.css',
    'resources/css/datatable/dataTables.min.css': 'public/css/datatable/dataTables.min.css',
    'resources/css/datatable/buttons.dataTables.css': 'public/css/datatable/buttons.dataTables.css',
    'resources/audio/select.wav': 'public/audio/select.wav',
};

function copyStaticAssets() {
    return {
        name: 'copy-static-pos-assets',
        apply: 'build',
        closeBundle() {
            for (const [source, destination] of Object.entries(staticAssets)) {
                const target = resolve(process.cwd(), destination);
                mkdirSync(dirname(target), { recursive: true });
                cpSync(resolve(process.cwd(), source), target);
            }
        },
    };
}

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/js/kitchen.js',
            ],
            refresh: true,
        }),
        copyStaticAssets(),
    ],
    server: {
        watch: {
            usePolling: true,
            interval: 1000,
        },
    },
});
