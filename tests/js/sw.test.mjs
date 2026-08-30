/*
 * El service worker.
 *
 *   node --test tests/js/
 *
 * Lo que se comprueba aquí no es que el fichero exista —eso ya lo mira
 * PwaTest— sino las decisiones que toma, que son las que hacen daño en
 * silencio si están mal.
 */
import test from 'node:test';
import assert from 'node:assert/strict';

import { cargarWorker, peticion } from './entorno.mjs';

// La versión la inyecta PHP al servir el fichero; aquí llega sin sustituir.
const VERSION = '__VERSION__';

const navegacion = (url) => peticion(url, { modo: 'navigate' });

test('al instalarse precarga la concha, la página sin conexión incluida', async () => {
    const w = cargarWorker();

    await w.disparar('install', w.eventoCiclo());

    const estaticos = [...w.almacenes.entries()].find(([n]) => n.includes('estaticos'));

    assert.ok(estaticos, 'No abrió el almacén de estáticos');

    const añadidas = estaticos[1].añadidas;

    assert.ok(añadidas.includes('/sin-conexion'));
    assert.ok(añadidas.includes('/assets/styles.css'));
    assert.ok(añadidas.includes('/assets/capture.js'));
    assert.ok(añadidas.includes('/assets/offline.js'));
});

test('al activarse borra las cachés de versiones anteriores y solo esas', async () => {
    const w = cargarWorker();

    await w.caches.open('maimind-estaticos-viejo');
    await w.caches.open('maimind-paginas-viejo');
    await w.caches.open(`maimind-estaticos-${VERSION}`);
    // De otra aplicación en el mismo origen. Ni tocarla.
    await w.caches.open('otra-cosa-viejo');

    await w.disparar('activate', w.eventoCiclo());

    assert.deepEqual(w.borrados.sort(), ['maimind-estaticos-viejo', 'maimind-paginas-viejo']);
});

test('una respuesta de la API nunca se cachea', async () => {
    // Caducan en cuanto se graba algo: servir la de ayer sería mentir sobre
    // cuántas entradas tiene alguien.
    const w = cargarWorker();

    const evento = await w.disparar('fetch', w.eventoFetch(peticion('https://maimind.test/api/entries')));

    assert.equal(evento.respondio, null, 'El worker se metió en una petición de la API');
});

test('una subida no pasa por el worker', async () => {
    // La cola de IndexedDB es la única que sabe si una grabación ya llegó.
    const w = cargarWorker();

    const evento = await w.disparar(
        'fetch',
        w.eventoFetch(peticion('https://maimind.test/api/entries', { metodo: 'POST' })),
    );

    assert.equal(evento.respondio, null);
    assert.deepEqual(w.borrados, [], 'Una subida no puede tirar cachés');
});

test('al cerrar sesión se borra el HTML cacheado', async () => {
    // Lleva el nombre de quien entró y su testigo CSRF. Sin esto, el siguiente
    // que abriera la aplicación en ese teléfono vería la pantalla del anterior.
    const w = cargarWorker();

    await w.disparar(
        'fetch',
        w.eventoFetch(peticion('https://maimind.test/salir', { metodo: 'POST' })),
    );

    assert.ok(w.borrados.some((n) => n.includes('paginas')));
    assert.ok(!w.borrados.some((n) => n.includes('estaticos')), 'Se llevó por delante los estáticos');
});

test('lo de otros orígenes se deja en paz', async () => {
    const w = cargarWorker();

    const evento = await w.disparar('fetch', w.eventoFetch(navegacion('https://ejemplo.test/lo-que-sea')));

    assert.equal(evento.respondio, null);
});

test('una página se pide a la red primero y se guarda para cuando no la haya', async () => {
    const respuesta = { ok: true, type: 'basic', clone: () => ({ copia: true }) };
    const w = cargarWorker(async () => respuesta);

    const evento = await w.disparar('fetch', w.eventoFetch(navegacion('https://maimind.test/')));

    assert.equal(await evento.respondio, respuesta, 'No devolvió lo que dijo la red');

    const paginas = [...w.almacenes.entries()].find(([n]) => n.includes('paginas'));

    assert.ok(paginas, 'La página no se guardó para cuando no haya red');
    assert.ok(await paginas[1].match('/'));
});

test('sin red, una página ya vista se sirve de la caché', async () => {
    const w = cargarWorker(async () => { throw new Error('sin red'); });

    const paginas = await w.caches.open(`maimind-paginas-${VERSION}`);
    await paginas.put(navegacion('https://maimind.test/'), { guardada: true });

    const evento = await w.disparar('fetch', w.eventoFetch(navegacion('https://maimind.test/')));

    assert.deepEqual(await evento.respondio, { guardada: true });
});

test('sin red y sin copia, se enseña la página sin conexión', async () => {
    const w = cargarWorker(async () => { throw new Error('sin red'); });

    await w.disparar('install', w.eventoCiclo());

    const evento = await w.disparar('fetch', w.eventoFetch(navegacion('https://maimind.test/otra')));

    const servida = await evento.respondio;

    assert.ok(servida && servida.precargada, 'No cayó en la página sin conexión');
});

test('un estático se sirve de la caché sin tocar la red', async () => {
    let fueALaRed = false;

    const w = cargarWorker(async () => { fueALaRed = true; return { ok: true, type: 'basic', clone: () => ({}) }; });

    await w.disparar('install', w.eventoCiclo());

    const evento = await w.disparar('fetch', w.eventoFetch(peticion('https://maimind.test/assets/styles.css')));

    assert.ok(await evento.respondio);
    assert.equal(fueALaRed, false, 'Fue a la red teniendo el fichero cacheado');
});

test('un estático que no estaba se pide y se guarda', async () => {
    const respuesta = { ok: true, type: 'basic', clone: () => ({ copia: true }) };
    const w = cargarWorker(async () => respuesta);

    const evento = await w.disparar('fetch', w.eventoFetch(peticion('https://maimind.test/icons/nuevo.png')));

    assert.equal(await evento.respondio, respuesta);

    const estaticos = [...w.almacenes.entries()].find(([n]) => n.includes('estaticos'));

    assert.ok(await estaticos[1].match('/icons/nuevo.png'));
});
