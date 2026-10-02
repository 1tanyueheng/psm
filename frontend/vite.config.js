import { defineConfig, loadEnv } from 'vite'
import react from '@vitejs/plugin-react'

// The SPA builds into the API's public directory so a single Render service
// can serve both the static shell and /api. In development the dev server
// proxies /api to the Laravel container, which avoids CORS entirely.
export default defineConfig(({ mode }) => {
  // loadEnv is the supported way to read VITE_* values here. Reading
  // process.env directly would miss anything set in a .env file.
  const env = loadEnv(mode, process.cwd(), '')

  return {
    plugins: [react()],

    server: {
      host: '0.0.0.0',
      port: 5173,
      // The source tree is bind-mounted into the container, so inotify events
      // never reach the watcher and edited modules keep being served from the
      // stale module graph (the page then renders the old code). Polling is
      // cheap here and makes edits take effect without restarting the service.
      watch: { usePolling: true, interval: 300 },
      proxy: {
        '/api': {
          target: env.VITE_PROXY_TARGET || 'http://localhost:8000',
          changeOrigin: true,
        },
      },
    },

    build: {
      base: '/build/',
      outDir: 'dist',
      emptyOutDir: true,
      sourcemap: false,
      chunkSizeWarningLimit: 900,
      rollupOptions: {
        output: {
          // Split the vendor bundle so a React or router update does not
          // invalidate the whole cached bundle for every user.
          manualChunks: {
            react: ['react', 'react-dom', 'react-router-dom'],
          },
        },
      },
    },
  }
})
