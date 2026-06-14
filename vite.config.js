import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import { fileURLToPath, URL } from 'node:url';

/**
 * Builds the Vue admin app into assets/admin/ with stable filenames
 * (app.js / app.css) so PHP can enqueue them without reading a manifest.
 */
export default defineConfig({
  plugins: [vue()],
  build: {
    outDir: 'assets/admin',
    emptyOutDir: true,
    manifest: false,
    cssCodeSplit: false,
    rollupOptions: {
      input: fileURLToPath(new URL('./resources/admin/main.js', import.meta.url)),
      output: {
        entryFileNames: 'app.js',
        assetFileNames: (info) => {
          const name = info.name || info.names?.[0] || '';
          return name.endsWith('.css') ? 'app.css' : 'assets/[name][extname]';
        },
      },
    },
  },
});
