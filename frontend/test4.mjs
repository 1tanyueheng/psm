import { build } from 'vite';
const result = await build({
  configFile: '/app/vite.config.js',
  mode: 'production',
  logLevel: 'info'
});
// Check output
import fs from 'fs';
const html = fs.readFileSync('/app/dist/index.html', 'utf-8');
console.log('HTML:', html.substring(0, 500));
