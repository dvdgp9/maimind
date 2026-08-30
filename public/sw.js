/*
 * Service worker.
 *
 * Hace dos cosas y ninguna más: que la aplicación abra sin red, y que se pueda
 * instalar. No hay notificaciones ni sincronización en segundo plano —lo
 * primero está descartado hasta tener datos reales delante (06-diseno-y-tono
 * §6), y lo segundo no hace falta: la cola de grabaciones ya reintenta sola.
 *
 * Dos almacenes separados a propósito:
 *
 *  - ESTATICOS: css, js, iconos y manifest. No llevan datos de nadie.
 *  - PAGINAS:   el HTML de la pantalla principal, que **sí** lleva el nombre de
 *               quien ha entrado y su testigo CSRF. Por eso se borra al salir
 *               de la sesión, y por eso vive aparte: para poder borrarlo sin
 *               llevarse por delante lo demás.
 *
 * El número de versión invalida la caché entera. Cambiarlo al tocar cualquier
 * fichero de public/assets es obligatorio; si no, se sirve el viejo.
 */

const VERSION   = 'v1';
const ESTATICOS = `maimind-estaticos-${VERSION}`;
const PAGINAS   = `maimind-paginas-${VERSION}`;

const CONCHA = [
    '/sin-conexion',
    '/assets/styles.css',
    '/assets/capture.js',
    '/assets/offline.js',
    '/manifest.webmanifest',
    '/icons/icon-192.png',
    '/icons/icon-512.png',
    '/icons/apple-touch-icon.png',
];

self.addEventListener('install', (evento) => {
    evento.waitUntil(
        caches.open(ESTATICOS)
            .then((cache) => cache.addAll(CONCHA))
            // Sin esperar a que se cierren las pestañas viejas: la versión
            // anterior no sabe nada que esta necesite conservar.
            .then(() => self.skipWaiting()),
    );
});

self.addEventListener('activate', (evento) => {
    evento.waitUntil(
        caches.keys()
            .then((nombres) => Promise.all(
                nombres
                    .filter((n) => n.startsWith('maimind-') && !n.endsWith(VERSION))
                    .map((n) => caches.delete(n)),
            ))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (evento) => {
    const peticion = evento.request;
    const url = new URL(peticion.url);

    // Solo lo propio. Y solo GET: una subida no se cachea ni se reintenta desde
    // aquí, que para eso está la cola de IndexedDB, que sí sabe cuándo una
    // grabación ya llegó.
    if (url.origin !== self.location.origin) return;

    if (peticion.method !== 'GET') {
        // Al cerrar sesión se tira el HTML cacheado. Si no, el siguiente que
        // abriera la aplicación en este teléfono vería la pantalla del anterior
        // con su nombre encima.
        if (url.pathname === '/salir') {
            evento.waitUntil(caches.delete(PAGINAS));
        }

        return;
    }

    // La API nunca se cachea: sus respuestas caducan en cuanto se graba algo.
    if (url.pathname.startsWith('/api/')) return;

    evento.respondWith(
        peticion.mode === 'navigate'
            ? paginaPrimeroLaRed(peticion)
            : estaticoPrimeroLaCache(peticion),
    );
});

/**
 * Páginas: primero la red, y la caché solo si no hay.
 *
 * Al revés que los estáticos porque el HTML lleva datos que cambian —el último
 * registro, cuántas entradas hay— y enseñar los de anteayer sería mentir.
 */
async function paginaPrimeroLaRed(peticion) {
    try {
        const respuesta = await fetch(peticion);

        if (respuesta.ok && respuesta.type === 'basic') {
            const cache = await caches.open(PAGINAS);

            cache.put(peticion, respuesta.clone());
        }

        return respuesta;
    } catch (e) {
        const guardada = await caches.match(peticion);

        if (guardada) return guardada;

        // Ni red ni copia: pasó algo que no se puede tapar.
        return caches.match('/sin-conexion');
    }
}

/** Estáticos: primero la caché. Llevan versión en el nombre del almacén. */
async function estaticoPrimeroLaCache(peticion) {
    const guardado = await caches.match(peticion);

    if (guardado) return guardado;

    const respuesta = await fetch(peticion);

    if (respuesta.ok && respuesta.type === 'basic') {
        const cache = await caches.open(ESTATICOS);

        cache.put(peticion, respuesta.clone());
    }

    return respuesta;
}
