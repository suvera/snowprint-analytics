import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// The dashboard is served by Swoole's static handler from public/ui/ at /ui/
// (decision D10). `npm run dev` proxies the API to a local Snowprint on :7669.
export default defineConfig({
  plugins: [react()],
  base: '/ui/',
  build: {
    outDir: '../public/ui',
    emptyOutDir: true,
  },
  server: {
    proxy: {
      '/api': 'http://localhost:7669',
    },
  },
  test: {
    environment: 'jsdom',
    globals: true,
  },
});
