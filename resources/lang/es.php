<?php

declare(strict_types=1);

/**
 * Textos de interfaz en español.
 *
 * Aquí NO van: slugs, enums, ni nombres de variables o etiquetas del catálogo.
 * Los primeros son identificadores; los segundos viven en columnas *_i18n.
 * Ver docs/design/04-arquitectura.md §4.bis.
 */
return [
    'app' => [
        'name'    => 'MaiMind',
        'tagline' => 'Observa tu vida. Deja que los patrones aparezcan solos.',
    ],

    'capture' => [
        'greeting'      => '¿Cómo estás?',
        'record'        => 'Grabar',
        'stop'          => 'Parar',
        'saving'        => 'Guardando…',
        'saved'         => 'Guardado',
        'mood_hint'     => '¿Cómo andas ahora mismo?',
        'mood_skip'     => 'Prefiero no decirlo',
        'last_entry'    => 'Último registro',
        'queued'         => 'Sin conexión. Guardada aquí; se enviará sola.',
        'pending_one'    => '1 grabación esperando a enviarse',
        'pending_many'   => ':count grabaciones esperando a enviarse',
        'sending_queue'  => 'Enviando lo que quedó pendiente…',
        'session_gone'   => 'Vuelve a entrar y se enviará lo que quedó pendiente.',
        'recording'     => 'Grabando',
        'cancel'        => 'Descartar',
        'no_entries'    => 'Todavía no has grabado nada',
        'retry'         => 'Reintentar',
        'today'         => 'hoy',
        'yesterday'     => 'ayer',
    ],

    'entry' => [
        'back'            => 'Volver',
        'transcript'      => 'Lo que dijiste',
        'edit_hint'       => 'Si el transcriptor se ha equivocado, corrígelo aquí.',
        'save'            => 'Guardar corrección',
        'saved'           => 'Corrección guardada',
        'edited_by_you'   => 'Corregido por ti',
        'machine_said'    => 'Transcrito por :model',
        'words'           => ':count palabras',
        'not_yet'         => 'Todavía no está transcrita',
        'in_progress'     => 'Transcribiendo…',
        'failed'          => 'No se ha podido transcribir',
        'audio_gone'      => 'El audio ya no está: se borra a los :days días',
        // Se dice sin dramatizar y sin culpar a nadie: es un hecho sobre el dato.
        'gap_notice'      => 'Faltan :seconds s de audio sin transcribir',
        'gap_explain'     => 'El transcriptor se saltó ese trozo. Puedes escribirlo tú si lo recuerdas.',
        'mood_was'        => 'Antes de grabar marcaste :value de 5',
    ],

    'review' => [
        'title'          => 'Esto es lo que he entendido',
        'confirm'        => 'Sí, es así',
        'edit'           => 'Corregir',
        'reject'         => 'No, eso no',
        'skip'           => 'Ahora no',
        'pending'        => '{count} cosas por revisar',
        // La pregunta que el sistema no puede adivinar y sí necesita.
        'revision_question' => '¿Te equivocaste, o ahora lo ves distinto?',
        'was_wrong'      => 'Me equivoqué',
        'see_differently' => 'Ahora lo veo distinto',
        'new_variable'   => '¿Quieres seguir «:name»? Lo has mencionado :count veces',
        'track_it'       => 'Seguirlo',
        'ignore_it'      => 'Ignorar',
    ],

    'evidence' => [
        'said_by_you'  => 'Lo dijiste tú',
        'inferred'     => 'Deducido, puede fallar',
        'confirmed'    => 'Confirmado por ti',
        'as_experienced' => 'Cómo lo viviste',
        'as_understood'  => 'Cómo lo ves ahora',
    ],

    'analysis' => [
        // Vocabulario obligatorio: nunca «provoca» ni «causa».
        'associated_with' => 'aparece asociado a',
        'precedes'        => 'suele preceder a',
        'compatible_with' => 'los datos son compatibles con',
        'your_claim'      => 'lo que tú cuentas',
        'observed'        => 'lo que muestran los datos',
        'insufficient'    => 'Aún no hay datos suficientes',
        'need_more'       => 'Faltan :count días para poder mirar esto',
        'no_data_gap'     => 'Sin registros',
        'baseline'        => 'tu línea base habitual',
    ],


    'auth' => [
        'sign_in'      => 'Entrar',
        'sign_up'      => 'Crear cuenta',
        'sign_out'     => 'Salir',
        'email'        => 'Correo',
        'password'     => 'Contraseña',
        'display_name' => 'Cómo quieres que te llame',
        'min_chars'    => 'Al menos 10 caracteres',
        'no_account'   => '¿Todavía no tienes cuenta?',
        'have_account' => '¿Ya tienes cuenta?',

        // Mismo mensaje para correo desconocido y contraseña incorrecta:
        // distinguirlos convertiría el formulario en un buscador de cuentas.
        'invalid_credentials' => 'El correo o la contraseña no son correctos',
        'invalid_email'       => 'Ese correo no parece válido',
        'password_too_short'  => 'La contraseña necesita al menos 10 caracteres',
        'password_is_email'   => 'La contraseña no puede ser tu propio correo',
        'email_taken'         => 'Ya hay una cuenta con ese correo',
        'account_inactive'    => 'Esta cuenta no está activa',
        'too_many_attempts'   => 'Demasiados intentos. Prueba de nuevo en :minutes minutos',
    ],

    'install' => [
        'title'      => 'Ponla en tu pantalla de inicio',
        'why'        => 'Se abre a pantalla completa y de un toque, sin pasar por el navegador.',
        'ios_steps'  => 'Toca Compartir, abajo, y luego «Añadir a pantalla de inicio».',
        'action'     => 'Instalar',
        'dismiss'    => 'Ahora no',
    ],

    'offline' => [
        'title'      => 'Sin conexión',
        'body'       => 'No he podido cargar la aplicación. Vuelve a intentarlo cuando tengas red.',
        'queue_safe' => 'Lo que hayas grabado sigue guardado en este dispositivo y se enviará solo.',
    ],

    'errors' => [
        'generic'        => 'Algo ha fallado. Inténtalo de nuevo.',
        'csrf'           => 'El formulario ha caducado. Inténtalo otra vez.',
        'not_found'      => 'No encontrado',
        'unauthorized'   => 'Necesitas iniciar sesión',
        'audio_too_big'  => 'La grabación es demasiado larga',
        'audio_bad_type' => 'Ese formato de audio no vale',
        'audio_missing'  => 'No ha llegado la grabación',
        'audio_empty'    => 'La grabación ha salido vacía',
        'queue_failed'   => 'No he podido guardar la grabación en este dispositivo.',
        'mic_denied'     => 'Necesito permiso para usar el micrófono',
        'mic_missing'    => 'No encuentro ningún micrófono',
        'insecure'       => 'Grabar necesita una conexión segura (https)',
    ],
];
