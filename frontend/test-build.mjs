import { build } from 'vite';
await build({
  configFile: '/app/vite.config.js',
  mode: 'production',
  logLevel: 'info'
});
console.log('done');
