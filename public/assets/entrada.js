/*
 * La pantalla de una grabación.
 *
 * Hace una sola cosa: que al tocar un hueco, el audio salte a ese punto. La
 * pantalla dice «faltan 5 s entre 0:25 y 0:30 y puedes escribirlo tú», y sin
 * poder escuchar justo ese trozo eso es pedirle a alguien que recuerde de
 * memoria lo que dijo hace tres días.
 *
 * El resto de la reproducción la hace el elemento <audio> del navegador, que
 * ya sabe hacerlo mejor que nosotros. Ningún texto aquí dentro.
 */
(() => {
    'use strict';

    // --- el área de texto crece con lo que hay dentro ---------------------
    //
    // El alto lo estima el servidor por el número de caracteres, pero eso no
    // sabe el ancho real de la pantalla: en un móvil estrecho, un texto que
    // cabía en seis líneas ocupa nueve y la última quedaba cortada. Aquí ya se
    // puede medir.
    const texto = document.querySelector('.transcript');

    if (texto) {
        const ajustar = () => {
            texto.style.height = 'auto';
            texto.style.height = texto.scrollHeight + 'px';
        };

        ajustar();
        texto.addEventListener('input', ajustar);
        window.addEventListener('resize', ajustar);
    }

    // --- saltar al hueco --------------------------------------------------

    const audio = document.querySelector('[data-audio]');

    if (!audio) return;

    document.querySelectorAll('[data-seek]').forEach((punto) => {
        punto.addEventListener('click', () => {
            const segundos = Number(punto.dataset.seek);

            if (!Number.isFinite(segundos)) return;

            // Un pelín antes del hueco: el corte casi nunca cae en un silencio
            // limpio, y las últimas palabras de antes ayudan a situarse.
            audio.currentTime = Math.max(0, segundos - 1.5);
            audio.play();
        });
    });
})();
