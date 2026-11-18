import { build } from 'esbuild';
await build({
  entryPoints:['js/app.js'],outfile:'assets/app.bundle.js',bundle:true,
  format:'iife',target:'es2020',charset:'utf8',legalComments:'none',
});
console.log('Mockup preparado: assets/app.bundle.js (incluye los datos iniciales).');
