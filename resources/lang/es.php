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
        'offline_queued' => 'Sin conexión. Se enviará en cuanto vuelvas a tener.',
        'recording'     => 'Grabando',
        'cancel'        => 'Descartar',
        'no_entries'    => 'Todavía no has grabado nada',
        'retry'         => 'Reintentar',
        'today'         => 'hoy',
        'yesterday'     => 'ayer',
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

    'errors' => [
        'generic'        => 'Algo ha fallado. Inténtalo de nuevo.',
        'csrf'           => 'El formulario ha caducado. Inténtalo otra vez.',
        'not_found'      => 'No encontrado',
        'unauthorized'   => 'Necesitas iniciar sesión',
        'audio_too_big'  => 'La grabación es demasiado larga',
        'audio_bad_type' => 'Ese formato de audio no vale',
        'audio_missing'  => 'No ha llegado la grabación',
        'audio_empty'    => 'La grabación ha salido vacía',
        'mic_denied'     => 'Necesito permiso para usar el micrófono',
        'mic_missing'    => 'No encuentro ningún micrófono',
        'insecure'       => 'Grabar necesita una conexión segura (https)',
    ],
];
