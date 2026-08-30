/*
 * Un service worker de mentira donde ejecutar el de verdad.
 *
 * Existe porque la lógica de sw.js —qué se cachea, qué no, y qué se borra al
 * cerrar sesión— es justo la clase de código cuyos errores no dan error:
 * cachear una respuesta de la API o dejar el HTML de otra persona en el
 * teléfono no rompe nada, solo hace daño en silencio. Y PHPUnit no puede
 * ejecutar JavaScript.
 *
 * Se carga con node:vm y no importándolo, porque un service worker es un
 * script clásico que se apoya en `self`, no un módulo.
 *
 * Se lee de resources/ y no de public/: el fichero no se sirve estático, lo
 * sirve PHP tras sustituirle la versión. Aquí `__VERSION__` se queda tal cual,
 * que para estas pruebas da igual — lo único que importa es que sea la misma
 * cadena en los nombres de los dos almacenes.
 */
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';

const RUTA = fileURLToPath(new URL('../../resources/sw.js', import.meta.url));

/** Caché en memoria con la parte de la API de CacheStorage que sw.js usa. */
class CacheFalsa {
    constructor() {
        this.entradas = new Map();
    }

    async put(peticion, respuesta) {
        this.entradas.set(clave(peticion), respuesta);
    }

    async addAll(rutas) {
        this.añadidas = rutas;

        for (const ruta of rutas) {
            this.entradas.set(ruta, { ok: true, precargada: true });
        }
    }

    async match(peticion) {
        return this.entradas.get(clave(peticion)) || undefined;
    }
}

function clave(peticion) {
    return typeof peticion === 'string' ? peticion : peticion.url.replace(/^https?:\/\/[^/]+/, '');
}

export function peticion(url, { metodo = 'GET', modo = 'no-cors' } = {}) {
    return { url, method: metodo, mode: modo };
}

/**
 * Carga sw.js en un contexto controlado.
 *
 * @param {(peticion) => Promise<object>} red  qué contesta la red en esta prueba
 */
export function cargarWorker(red = async () => ({ ok: true, type: 'basic', clone: () => ({}) })) {
    const oyentes = {};
    const almacenes = new Map();
    const borrados = [];

    const caches = {
        async open(nombre) {
            if (!almacenes.has(nombre)) almacenes.set(nombre, new CacheFalsa());

            return almacenes.get(nombre);
        },
        async keys() {
            return [...almacenes.keys()];
        },
        async delete(nombre) {
            borrados.push(nombre);

            return almacenes.delete(nombre);
        },
        async match(peticionOruta) {
            for (const almacen of almacenes.values()) {
                const encontrada = await almacen.match(peticionOruta);

                if (encontrada) return encontrada;
            }

            return undefined;
        },
    };

    const self = {
        addEventListener(tipo, oyente) {
            (oyentes[tipo] ||= []).push(oyente);
        },
        location: { origin: 'https://maimind.test' },
        async skipWaiting() {},
        clients: { async claim() {} },
    };

    const contexto = { self, caches, fetch: red, URL, console, Promise };
    contexto.globalThis = contexto;

    vm.createContext(contexto);
    vm.runInContext(readFileSync(RUTA, 'utf8'), contexto, { filename: 'sw.js' });

    /** Dispara un evento y espera a lo que el worker haya prometido. */
    const disparar = async (tipo, evento) => {
        for (const oyente of oyentes[tipo] || []) await oyente(evento);

        await Promise.all(evento.esperando || []);

        return evento;
    };

    const eventoFetch = (peticion) => ({
        request: peticion,
        respondio: null,
        esperando: [],
        respondWith(promesa) {
            this.respondio = promesa;
        },
        waitUntil(promesa) {
            this.esperando.push(promesa);
        },
    });

    const eventoCiclo = () => ({ esperando: [], waitUntil(p) { this.esperando.push(p); } });

    return { disparar, eventoFetch, eventoCiclo, almacenes, borrados, caches };
}
