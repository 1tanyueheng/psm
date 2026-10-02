import { loadEnv } from 'vite';
import react from '@vitejs/plugin-react';

const mode = 'production';
const env = loadEnv(mode, process.cwd(), '');

const config = {
  plugins: [react()],
  build: {
    base: '/build/',
    outDir: 'dist',
    emptyOutDir: true,
    sourcemap: false,
    chunkSizeWarningLimit: 900,
  }
};

console.log('base:', config.build.base);
