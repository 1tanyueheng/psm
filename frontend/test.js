import config from './vite.config.js';
const res = await config({mode: 'production'});
console.log('base:', res.build.base);
