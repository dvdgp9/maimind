/*
 * Captura de audio.
 *
 * Un solo botón con dos estados. Todo lo demás está subordinado a que soltar el
 * botón sea lo único que el usuario tenga que hacer.
 *
 * Notas de compatibilidad:
 *  - El tipo MIME se negocia con isTypeSupported(), nunca leyendo el user-agent.
 *    Safari solo grabó audio/mp4 (AAC) hasta la 18.4; desde ahí también webm/opus.
 *  - getUserMedia exige contexto seguro: https, o localhost en desarrollo.
 *  - Al parar hay que soltar las pistas del micrófono a mano, o el indicador de
 *    grabación del sistema se queda encendido.
 */
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

    const MAX_MS = 10 * 60 * 1000;   // corte de seguridad: 10 minutos

    let grabadora = null;
    let pistas    = null;
    let trozos    = [];
    let inicio    = 0;
    let cronometro = null;
    let corte     = null;
    let humor     = null;
    let descartar = false;

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

    async function enviar(blob, tipo, duracionMs) {
        pinta('uploading', raiz.dataset.msgSaving);

        const datos = new FormData();

        datos.append('audio', blob, 'captura');
        datos.append('mime', tipo);
        datos.append('duration_ms', String(duracionMs));
        datos.append('captured_at', new Date(inicio).toISOString());
        datos.append('timezone', Intl.DateTimeFormat().resolvedOptions().timeZone || '');
        // Minutos POR DELANTE de UTC: getTimezoneOffset() los da al revés.
        datos.append('utc_offset', String(-new Date().getTimezoneOffset()));
        datos.append('_csrf', csrf);

        if (humor !== null) datos.append('mood_hint', String(humor));

        try {
            const respuesta = await fetch('/api/entries', {
                method: 'POST',
                body: datos,
                headers: { 'X-CSRF-Token': csrf, Accept: 'application/json' },
            });

            if (!respuesta.ok) {
                const cuerpo = await respuesta.json().catch(() => ({}));
                pinta('error', cuerpo.error || raiz.dataset.msgGeneric);
                return;
            }

            pinta('saved', raiz.dataset.msgSaved);
            reiniciaHumor();

            // La lista de la 1.4 llegará sola; de momento basta con refrescar.
            setTimeout(() => window.location.reload(), 1200);
        } catch (e) {
            // La cola sin conexión llega en la tarea 1.4. Hasta entonces se
            // avisa en vez de perder la grabación en silencio.
            pinta('error', navigator.onLine ? raiz.dataset.msgGeneric : raiz.dataset.msgOffline);
        }
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
    document.addEventListener('visibilitychange', () => {
        if (document.hidden && raiz.dataset.state === 'recording') parar();
    });

    window.addEventListener('pagehide', soltarMicrofono);

    pinta('idle', '');
})();
