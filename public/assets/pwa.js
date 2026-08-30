/*
 * Instalación: registro del service worker y el paso que explica cómo añadir la
 * aplicación a la pantalla de inicio.
 *
 * Por qué hay onboarding y no solo un manifest: en iOS **no existe** el aviso
 * de instalación. Hay que entrar en Compartir → Añadir a inicio, y eso no lo
 * descubre nadie por su cuenta (06-diseno-y-tono.md §6). Y el icono no es un
 * adorno: el bucle de captura muere con la fricción, y es la diferencia entre
 * tres toques y ocho.
 *
 * Se enseña una vez y se puede cerrar. No vuelve. Insistir sería justo la
 * gamificación que el documento de tono prohíbe.
 */
import * as cola from './offline.js';

const CERRADO = 'maimind.instalacion.cerrada';

// --- service worker -------------------------------------------------------

if ('serviceWorker' in navigator) {
    // Tras la carga: registrarlo antes compite por el ancho de banda con lo que
    // la persona ha venido a ver.
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(() => {
            // Sin service worker la aplicación funciona igual, solo que no abre
            // sin red. No hay nada que decirle al usuario sobre esto.
        });
    });
}

// --- invitación a instalar ------------------------------------------------

const panel = document.querySelector('[data-install]');

if (panel) {
    const cerrar    = panel.querySelector('[data-install-close]');
    const boton     = panel.querySelector('[data-install-button]');
    const pasosIos  = panel.querySelector('[data-install-ios]');

    let prompt = null;

    const yaInstalada = window.matchMedia('(display-mode: standalone)').matches
        || window.navigator.standalone === true;

    const cerradaAntes = () => {
        try {
            return localStorage.getItem(CERRADO) === '1';
        } catch (e) {
            // Modo privado: sin memoria de que se cerró. Peor eso que reventar.
            return false;
        }
    };

    const mostrar = (conIos) => {
        if (yaInstalada || cerradaAntes()) return;

        pasosIos.hidden = !conIos;
        boton.hidden = conIos;
        panel.hidden = false;
    };

    // Android y escritorio: el navegador avisa de que se puede instalar, y
    // entonces —y solo entonces— aparece el botón.
    window.addEventListener('beforeinstallprompt', (evento) => {
        evento.preventDefault();
        prompt = evento;
        mostrar(false);
    });

    boton.addEventListener('click', async () => {
        if (!prompt) return;

        prompt.prompt();
        await prompt.userChoice;

        prompt = null;
        panel.hidden = true;
    });

    cerrar.addEventListener('click', () => {
        panel.hidden = true;

        try {
            localStorage.setItem(CERRADO, '1');
        } catch (e) {
            // Se volverá a enseñar la próxima vez. Es lo que hay.
        }
    });

    window.addEventListener('appinstalled', () => {
        panel.hidden = true;
    });

    // iOS: no hay evento ninguno, así que se explican los pasos a mano.
    //
    // `standalone` en navigator solo existe en Safari de iOS, así que
    // comprobar que existe ES la detección. No se lee el user-agent: eso
    // caduca, esto no.
    if ('standalone' in window.navigator && !window.navigator.standalone) {
        mostrar(true);
    }

    // Instalada o no, conviene pedir que no borren la cola.
    if (yaInstalada) cola.pedirPersistencia();
}
