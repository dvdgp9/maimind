/*
 * Cola local de grabaciones pendientes de subir.
 *
 * Por qué existe: la gente graba en el metro, en el campo y en un avión, y una
 * grabación es lo único irrepetible que maneja esta aplicación. Todo lo demás
 * se puede recalcular; lo que alguien acaba de contar, no.
 *
 * IndexedDB y no localStorage porque hay que guardar un Blob de audio de varios
 * megas, y localStorage solo admite cadenas y unos pocos megas en total.
 *
 * Cada elemento lleva un `token` que se genera **una vez**, al terminar de
 * grabar, y no cambia entre reintentos. Es lo que permite al servidor
 * reconocer una grabación repetida en vez de crear una segunda entrada.
 *
 * Nada aquí lanza hacia fuera: si IndexedDB no está disponible —modo privado de
 * iOS, cuota agotada, un navegador raro— quien llama se entera por el valor
 * devuelto y avisa al usuario. Perder la grabación en silencio es lo único
 * inaceptable.
 *
 * Los mensajes de Error van en inglés a propósito: son para quien programa, no
 * para quien usa la aplicación, igual que los slugs y los enums. Lo que lee el
 * usuario sale siempre de resources/lang.
 */

const BASE     = 'maimind';
const ALMACEN  = 'pendientes';
const VERSION  = 1;

let conexion = null;

export function disponible() {
    return typeof indexedDB !== 'undefined';
}

function abrir() {
    if (conexion) return conexion;

    conexion = new Promise((resolver, rechazar) => {
        if (!disponible()) {
            rechazar(new Error('IndexedDB unavailable'));
            return;
        }

        const peticion = indexedDB.open(BASE, VERSION);

        peticion.addEventListener('upgradeneeded', () => {
            const bd = peticion.result;

            if (!bd.objectStoreNames.contains(ALMACEN)) {
                const almacen = bd.createObjectStore(ALMACEN, { keyPath: 'token' });

                // Se envían en el orden en que se grabaron: si alguien registró
                // tres cosas sin cobertura, el orden es parte del dato.
                almacen.createIndex('creadoEn', 'creadoEn');
            }
        });

        peticion.addEventListener('success', () => resolver(peticion.result));
        peticion.addEventListener('error', () => rechazar(peticion.error));
        peticion.addEventListener('blocked', () => rechazar(new Error('IndexedDB blocked')));
    });

    // Una conexión fallida no se cachea: el siguiente intento vuelve a probar.
    conexion.catch(() => { conexion = null; });

    return conexion;
}

function transaccion(modo, trabajo) {
    return abrir().then((bd) => new Promise((resolver, rechazar) => {
        const tx = bd.transaction(ALMACEN, modo);
        const peticion = trabajo(tx.objectStore(ALMACEN));

        tx.addEventListener('error', () => rechazar(tx.error));
        tx.addEventListener('abort', () => rechazar(tx.error || new Error('Transaction aborted')));
        tx.addEventListener('complete', () => resolver(peticion ? peticion.result : undefined));
    }));
}

/** @returns {Promise<boolean>} si la grabación quedó a salvo en disco */
export async function guardar(elemento) {
    try {
        await transaccion('readwrite', (almacen) => almacen.put(elemento));

        return true;
    } catch (e) {
        return false;
    }
}

export async function todas() {
    try {
        const elementos = await transaccion('readonly', (almacen) => almacen.getAll());

        return (elementos || []).sort((a, b) => a.creadoEn - b.creadoEn);
    } catch (e) {
        return [];
    }
}

export async function contar() {
    try {
        return (await transaccion('readonly', (almacen) => almacen.count())) || 0;
    } catch (e) {
        return 0;
    }
}

export async function borrar(token) {
    try {
        await transaccion('readwrite', (almacen) => almacen.delete(token));

        return true;
    } catch (e) {
        return false;
    }
}

/**
 * Pide al navegador que no borre estos datos para hacer sitio.
 *
 * Importa sobre todo en iOS, que limpia el almacenamiento de los sitios que
 * llevan tiempo sin visitarse. Sin esto, una grabación en cola puede
 * desaparecer sola. Es una petición: el navegador decide, y en algunos casos
 * pregunta al usuario.
 */
export async function pedirPersistencia() {
    if (!navigator.storage || !navigator.storage.persist) return false;

    try {
        if (await navigator.storage.persisted()) return true;

        return await navigator.storage.persist();
    } catch (e) {
        return false;
    }
}

/** Identificador estable de una grabación, el mismo en todos sus reintentos. */
export function nuevoToken() {
    if (crypto && crypto.randomUUID) {
        return crypto.randomUUID().replace(/-/g, '');
    }

    const bytes = new Uint8Array(16);
    crypto.getRandomValues(bytes);

    return Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
}
