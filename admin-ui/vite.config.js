import vue from '@vitejs/plugin-vue';
import { defineConfig } from 'vitest/config';

// Islands use <script setup> only (S12), so the Options API stays out of the runtime (ADM-8).
export default defineConfig({
    base: '/admin-build/',
    plugins: [vue()],
    define: {
        __VUE_OPTIONS_API__: 'false',
        __VUE_PROD_DEVTOOLS__: 'false',
        __VUE_PROD_HYDRATION_MISMATCH_DETAILS__: 'false',
    },
    build: {
        outDir: '../public/admin-build',
        emptyOutDir: true,
        manifest: true,
        rolldownOptions: {
            input: 'src/main.js',
        },
    },
    test: {
        environment: 'jsdom',
        include: ['tests/**/*.test.js'],
        setupFiles: ['tests/setup.js'],
    },
});
