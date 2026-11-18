import {test} from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {runInNewContext} from 'node:vm';
import {createStorage,Repository} from '../js/data.js';
const seed=JSON.parse(readFileSync(new URL('../data/source.json',import.meta.url)));

test('El HTML abre un script clásico local que llega al tablero sin fetch ni módulos',()=>{
  const html=readFileSync(new URL('../index.html',import.meta.url),'utf8');
  assert.match(html,/<script defer src="assets\/app.bundle.js"/);assert.doesNotMatch(html,/type="module"/);
  const bundle=readFileSync(new URL('../assets/app.bundle.js',import.meta.url),'utf8');
  for(const protocol of ['file:','http:']){
    const memory=new Map(),storage={getItem:k=>memory.get(k)??null,setItem:(k,v)=>memory.set(k,v),removeItem:k=>memory.delete(k)};
    const elements=Object.fromEntries(['#app','#main','#modal','#toast','#backup-file','#chart-tooltip'].map(id=>[id,{innerHTML:'',addEventListener(){},close(){}}]));
    const document={querySelector:s=>elements[s]??null,querySelectorAll:()=>[],addEventListener(){}};
    const window={localStorage:storage,addEventListener(){}};
    runInNewContext(bundle,{document,window,localStorage:storage,location:{protocol,hash:''},console,setTimeout,clearTimeout,fetch(){throw Error('El arranque no debe hacer peticiones de red.');}});
    assert.match(elements['#app'].innerHTML,/class="shell/);
    assert.match(elements['#main'].innerHTML,/Tablero central/);
    assert.match(elements['#main'].innerHTML,/1\.024 M/);
    assert.doesNotMatch(elements['#app'].innerHTML,/No se pudo abrir el tablero/);
  }
});
test('Si el navegador bloquea almacenamiento, el repositorio funciona durante la sesión',()=>{
  const adapter=createStorage(()=>{throw Error('SecurityError');});assert.equal(adapter.persistent,false);
  const repository=new Repository(adapter.storage,{source:seed});const d=repository.load('source');d.animals[0].reproductive='VACIA';repository.save(d);
  assert.equal(repository.load('source').animals[0].reproductive,'VACIA');repository.reset('source');assert.equal(repository.load('source').animals[0].reproductive,seed.animals[0].reproductive);
});
