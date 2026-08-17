// Zona horaria real del navegador para el registro. Sin esto, el día local
// del usuario se calcularía con la zona del servidor, y todos los registros
// de después de medianoche caerían en el día equivocado.
(() => {
    const campo = document.getElementById('timezone');

    if (campo && typeof Intl !== 'undefined') {
        const zona = Intl.DateTimeFormat().resolvedOptions().timeZone;

        if (zona) {
            campo.value = zona;
        }
    }
})();
