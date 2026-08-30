/*
 * Captura de audio.
 *
 * Un solo botón con dos estados. Todo lo demás está subordinado a que soltar el
 * botón sea lo único que el usuario tenga que hacer.
 *
 * Una grabación es lo único irrepetible que maneja esta aplicación, así que la
 * regla que manda aquí es: **nunca perder audio en silencio**. Si no se puede
 * subir, se guarda en la cola local y se dice; si tampoco se puede guardar, se
 * dice también, y la grabación sigue en memoria hasta que la persona decida.
 *
 * Notas de compatibilidad:
 *  - El tipo MIME se negocia con isTypeSupported(), nunca leyendo el user-agent.
 *    Safari solo grabó audio/mp4 (AAC) hasta la 18.4; desde ahí también webm/opus.
 *  - getUserMedia exige contexto seguro: https, o localhost en desarrollo.
 *  - Al parar hay que soltar las pistas del micrófono a mano, o el indicador de
 *    grabación del sistema se queda encendido.
 */
import * as cola from './offline.js';

(() => {
    'use strict';

    const raiz = document.querySelector('.capture');
    if (!raiz) return;

    const boton     = raiz.querySelector('[data-record]');
    const estado    = raiz.querySelector('[data-status]');
    const cancelar  = raiz.querySelector('[data-cancel]');
    const iconoIdle = raiz.querySelector('[data-icon-idle]');
    const iconoBusy = raiz.querySelector('[data-icon-busy]');
    const puntos    = Array.from(raiz.querySelectorAll('[data-mood-value]'));
    const csrf      = raiz.dataset.csrf;

    const panelCola   = document.querySelector('[data-queue]');
    const textoCola   = panelCola && panelCola.querySelector('[data-queue-count]');
    const botonCola   = panelCola && panelCola.querySelector('[data-queue-retry]');
    const motivoCola  = panelCola && panelCola.querySelector('[data-queue-reason]');

    const MAX_MS = 10 * 60 * 1000;   // corte de seguridad: 10 minutos

    let grabadora = null;
    let pistas    = null;
    let trozos    = [];
    let inicio    = 0;
    let cronometro = null;
    let corte     = null;
    let humor     = null;
    let descartar = false;
    let vaciando  = false;

    // --- tipo de audio ----------------------------------------------------

    function tipoSoportado() {
        const preferencias = [
            'audio/webm;codecs=opus',   // más pequeño a igual calidad
            'audio/ogg;codecs=opus',
            'audio/mp4',                // Safari anterior a la 18.4
            'audio/webm',
        ];

        if (typeof MediaRecorder === 'undefined') return null;

        return preferencias.find((t) => MediaRecorder.isTypeSupported(t)) || null;
    }

    // --- interfaz ---------------------------------------------------------

    function pinta(modo, texto) {
        raiz.dataset.state = modo;
        estado.textContent = texto || '';

        const grabando = modo === 'recording';

        iconoIdle.hidden = grabando;
        iconoBusy.hidden = !grabando;
        cancelar.hidden  = !grabando;

        boton.disabled = modo === 'uploading';
        boton.setAttribute('aria-pressed', grabando ? 'true' : 'false');
    }

    function reloj() {
        const s = Math.floor((Date.now() - inicio) / 1000);
        return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
    }

    // --- toque opcional de 1 a 5 ------------------------------------------

    puntos.forEach((punto) => {
        punto.addEventListener('click', () => {
            const valor = Number(punto.dataset.moodValue);

            // Volver a tocar el mismo lo quita: es opcional de verdad.
            humor = humor === valor ? null : valor;

            puntos.forEach((otro) => {
                const activo = humor !== null && Number(otro.dataset.moodValue) <= humor;
                otro.setAttribute('aria-pressed', activo ? 'true' : 'false');
            });
        });
    });

    // --- grabación --------------------------------------------------------

    async function empezar() {
        if (!window.isSecureContext) {
            pinta('error', raiz.dataset.msgInsecure);
            return;
        }

        const tipo = tipoSoportado();

        if (!navigator.mediaDevices || !tipo) {
            pinta('error', raiz.dataset.msgMicMissing);
            return;
        }

        try {
            pistas = await navigator.mediaDevices.getUserMedia({
                audio: { echoCancellation: true, noiseSuppression: true },
            });
        } catch (e) {
            pinta('error', e && e.name === 'NotFoundError'
                ? raiz.dataset.msgMicMissing
                : raiz.dataset.msgMicDenied);
            return;
        }

        trozos = [];
        descartar = false;
        grabadora = new MediaRecorder(pistas, { mimeType: tipo });

        grabadora.addEventListener('dataavailable', (e) => {
            if (e.data && e.data.size > 0) trozos.push(e.data);
        });

        grabadora.addEventListener('stop', () => {
            soltarMicrofono();

            if (descartar) {
                pinta('idle', '');
                return;
            }

            enviar(new Blob(trozos, { type: tipo }), tipo, Date.now() - inicio);
        });

        inicio = Date.now();
        grabadora.start();

        pinta('recording', `${raiz.dataset.msgRecording} 0:00`);

        cronometro = setInterval(() => {
            estado.textContent = `${raiz.dataset.msgRecording} ${reloj()}`;
        }, 250);

        corte = setTimeout(parar, MAX_MS);
    }

    function parar() {
        clearInterval(cronometro);
        clearTimeout(corte);

        if (grabadora && grabadora.state !== 'inactive') {
            grabadora.stop();
        }
    }

    function soltarMicrofono() {
        // Sin esto, el punto de "grabando" del sistema se queda encendido.
        if (pistas) {
            pistas.getTracks().forEach((p) => p.stop());
            pistas = null;
        }
    }

    // --- envío ------------------------------------------------------------

    /** Lo que viaja al servidor y, si no llega, lo que se guarda en la cola. */
    function nuevoElemento(blob, tipo, duracionMs) {
        return {
            // Se genera una sola vez, aquí. El mismo testigo en todos los
            // reintentos es lo que permite al servidor reconocer una grabación
            // repetida en vez de crear una segunda entrada.
            token: cola.nuevoToken(),
            blob,
            mime: tipo,
            duracionMs,
            // La hora de la grabación, no la del envío: una entrada subida tres
            // días después sigue siendo del día en que se grabó.
            capturadoEn: new Date(inicio).toISOString(),
            zona: Intl.DateTimeFormat().resolvedOptions().timeZone || '',
            // Minutos POR DELANTE de UTC: getTimezoneOffset() los da al revés.
            offset: -new Date(inicio).getTimezoneOffset(),
            humor,
            creadoEn: Date.now(),
        };
    }

    function cuerpo(elemento) {
        const datos = new FormData();

        datos.append('audio', elemento.blob, 'captura');
        datos.append('mime', elemento.mime);
        datos.append('duration_ms', String(elemento.duracionMs));
        datos.append('captured_at', elemento.capturadoEn);
        datos.append('timezone', elemento.zona);
        datos.append('utc_offset', String(elemento.offset));
        datos.append('client_token', elemento.token);
        datos.append('_csrf', csrf);

        if (elemento.humor !== null && elemento.humor !== undefined) {
            datos.append('mood_hint', String(elemento.humor));
        }

        return datos;
    }

    /**
     * Un intento de subida, clasificado por si conviene repetirlo.
     *
     * La distinción importa: reintentar eternamente algo que el servidor nunca
     * va a aceptar gasta datos del usuario para nada, y descartar algo que solo
     * falló porque no había cobertura pierde una grabación.
     *
     * @returns {Promise<{estado:'ok'|'reintentable'|'rechazado', mensaje?:string}>}
     */
    async function intentar(elemento) {
        let respuesta;

        try {
            respuesta = await fetch('/api/entries', {
                method: 'POST',
                body: cuerpo(elemento),
                headers: { 'X-CSRF-Token': csrf, Accept: 'application/json' },
            });
        } catch (e) {
            // fetch solo lanza por problemas de red, nunca por un 4xx o 5xx.
            return { estado: 'reintentable' };
        }

        if (respuesta.ok) return { estado: 'ok' };

        // La sesión caducó. La grabación es perfectamente buena; lo que falta
        // es volver a entrar. No se descarta jamás por esto.
        if (respuesta.status === 401 || respuesta.status === 419) {
            return { estado: 'reintentable', mensaje: raiz.dataset.msgSessionGone };
        }

        // El servidor no puede ahora, pero podrá luego.
        if (respuesta.status >= 500 || respuesta.status === 429) {
            return { estado: 'reintentable' };
        }

        const detalle = await respuesta.json().catch(() => ({}));

        // 413, 415, 422: reintentarlo no lo va a arreglar.
        return { estado: 'rechazado', mensaje: detalle.error || raiz.dataset.msgGeneric };
    }

    async function enviar(blob, tipo, duracionMs) {
        const elemento = nuevoElemento(blob, tipo, duracionMs);

        pinta('uploading', raiz.dataset.msgSaving);

        const resultado = await intentar(elemento);

        if (resultado.estado === 'ok') {
            pinta('saved', raiz.dataset.msgSaved);
            reiniciaHumor();
            setTimeout(() => window.location.reload(), 1200);
            return;
        }

        if (resultado.estado === 'rechazado') {
            pinta('error', resultado.mensaje);
            return;
        }

        // A la cola. Antes se pide persistencia, que es justo cuando tiene
        // sentido pedirla: hay algo que perder.
        await cola.pedirPersistencia();

        if (!await cola.guardar(elemento)) {
            // Ni se pudo subir ni se pudo guardar. Se dice con todas las letras
            // en vez de dejar creer que está a salvo.
            pinta('error', raiz.dataset.msgQueueFailed);
            return;
        }

        pinta('queued', resultado.mensaje || raiz.dataset.msgQueued);
        reiniciaHumor();
        await pintaCola();
    }

    // --- cola sin conexión ------------------------------------------------

    async function pintaCola() {
        if (!panelCola) return;

        const pendientes = await cola.todas();
        const n = pendientes.length;

        panelCola.hidden = n === 0;

        if (n === 0) return;

        textoCola.textContent = n === 1
            ? raiz.dataset.msgPendingOne
            : raiz.dataset.msgPendingMany.replace(':count', String(n));

        // Si alguna quedó rechazada, su motivo se enseña: es la única forma de
        // que la persona sepa por qué esa no se va a ir sola. El texto viene
        // del servidor, que ya lo saca de los ficheros de idioma.
        const rechazada = pendientes.find((e) => e.rechazada);

        motivoCola.textContent = rechazada ? rechazada.rechazada : '';
        motivoCola.hidden = !rechazada;
    }

    /**
     * Intenta subir lo que quedó pendiente.
     *
     * Se para en el primer fallo reintentable: si no hay cobertura, insistir
     * con las nueve siguientes no va a ir mejor.
     */
    async function vaciar() {
        if (vaciando || !cola.disponible()) return;

        const pendientes = await cola.todas();

        if (pendientes.length === 0) {
            await pintaCola();
            return;
        }

        vaciando = true;

        // Solo el texto, sin tocar el estado: vaciar la cola no puede dejar el
        // botón de grabar bloqueado. Si alguien quiere grabar mientras se
        // envía lo de ayer, que pueda.
        estado.textContent = raiz.dataset.msgSendingQueue;

        let enviadas = 0;

        try {
            for (const elemento of pendientes) {
                if (elemento.rechazada) continue;

                const resultado = await intentar(elemento);

                if (resultado.estado === 'ok') {
                    await cola.borrar(elemento.token);
                    enviadas++;
                    continue;
                }

                if (resultado.estado === 'rechazado') {
                    // No se borra: borrar audio de alguien sin decírselo es lo
                    // único que esta cola no puede hacer. Se marca para no
                    // reintentarla y se enseña el motivo.
                    elemento.rechazada = resultado.mensaje;
                    await cola.guardar(elemento);
                    continue;
                }

                break;
            }
        } finally {
            vaciando = false;
        }

        if (raiz.dataset.state === 'idle') estado.textContent = '';

        await pintaCola();

        if (enviadas > 0) window.location.reload();
    }

    function reiniciaHumor() {
        humor = null;
        puntos.forEach((p) => p.setAttribute('aria-pressed', 'false'));
    }

    // --- eventos ----------------------------------------------------------

    boton.addEventListener('click', () => {
        if (raiz.dataset.state === 'recording') parar();
        else empezar();
    });

    cancelar.addEventListener('click', () => {
        descartar = true;
        parar();
    });

    // Si la pestaña se va a segundo plano mientras graba, se cierra la
    // grabación con lo que haya en vez de arriesgarse a que el sistema la mate.
    // Al volver, se aprovecha para intentar vaciar la cola.
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            if (raiz.dataset.state === 'recording') parar();
            return;
        }

        vaciar();
    });

    window.addEventListener('pagehide', soltarMicrofono);

    // Los tres momentos en que tiene sentido reintentar: al recuperar la red,
    // al volver a la aplicación (arriba) y cuando la persona lo pide.
    window.addEventListener('online', vaciar);

    if (botonCola) botonCola.addEventListener('click', vaciar);

    pinta('idle', '');

    // Al cargar: puede haber quedado algo de la última vez.
    vaciar();
})();
