import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [vue()],
    build: {
        outDir: '../dist',
        emptyOutDir: true,
        manifest: true,
        rollupOptions: {
            input: 'src/main.js',
        },
    },
});
