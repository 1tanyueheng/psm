import { defineConfig, loadEnv } from 'vite';

const mode = 'production';
const env = loadEnv(mode, process.cwd(), '');
const config = defineConfig({
  plugins: [],
  build: {
    base: '/build/',
    outDir: 'dist',
    emptyOutDir: true,
    sourcemap: false,
    chunkSizeWarningLimit: 900,
  }
});

const resolved = await config({mode, command: 'build'});
console.log('base:', resolved.build.base);
console.log('outDir:', resolved.build.outDir);
