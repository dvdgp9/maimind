<?php

declare(strict_types=1);

/**
 * CATÁLOGO CORE DE VARIABLES
 *
 * 40 variables. El razonamiento de por qué estas y no otras está en
 * docs/design/05-catalogo-core.md. Resumen de los criterios:
 *
 *   1. Que la gente lo diga de verdad al hablar sola en un audio. Si nadie lo
 *      dice nunca en voz alta, no va en el core: va al vocabulario emergente.
 *   2. Que se pueda extraer sin adivinar.
 *   3. Que sostenga alguna de las preguntas que el producto existe para responder.
 *   4. Que no sea sinónimo de otra. Los casi-sinónimos van como alias.
 *
 * Convenciones:
 *
 *   - `slug` en inglés porque es un identificador, no un texto. Nunca se traduce.
 *   - Etiquetas y definiciones en español como idioma de primera clase, no
 *     traducidas del inglés. Las etiquetas en inglés son provisionales y algunas
 *     son aproximaciones declaradas (agobio, ilusión).
 *   - Escala ordinal 1..5 SIEMPRE con anclas verbales. Es la misma escala que
 *     `entries.mood_hint`, para poder comparar lo que extrae la IA con lo que el
 *     usuario toca con el dedo, y así auditar al extractor.
 *   - `polarity` solo se asigna cuando es definicional. En las conductas es
 *     `neutral` a propósito: si esta persona mejora o empeora saliendo de casa
 *     es justo lo que la aplicación debe descubrir, no lo que debe presuponer.
 */

$ANCLAS_INTENSIDAD = '1 = nada · 2 = un poco · 3 = moderado · 4 = bastante · 5 = muchísimo.';

return [

    // =================================================================
    // ESTADOS DE FONDO — el esqueleto de todas las series temporales.
    // Son continuos y de fondo. Las emociones, en cambio, son episodios.
    // =================================================================

    [
        'slug'      => 'state.mood',
        'name'      => 'Ánimo',
        'name_i18n' => ['es' => 'Ánimo', 'en' => 'Mood'],
        'definition' => 'Cómo se encuentra en conjunto, con independencia de emociones concretas.',
        'category' => 'state', 'value_type' => 'ordinal',
        'scale_min' => 1, 'scale_max' => 5, 'unit' => null,
        'polarity' => 'higher_better', 'temporal_kind' => 'instant',
        'objectivity' => 'subjective', 'requires_confirm' => 0,
        'extraction_hint' => 'Valoración global del estado, no una emoción concreta. '
            . 'Anclas: 1 = muy mal · 2 = mal · 3 = regular · 4 = bien · 5 = muy bien. '
            . 'Si dice una nota sobre 10, convertir: 1-2→1, 3-4→2, 5-6→3, 7-8→4, 9-10→5, '
            . 'y guardar la frase original en value_text. '
            . 'Misma escala que el toque previo a grabar (mood_hint).',
        'aliases' => ['ánimo', 'estado de ánimo', 'cómo estoy', 'me encuentro',
                      'estoy bien', 'estoy mal', 'estoy fatal', 'estoy regular',
                      'de bajón', 'hundido', 'animado'],
    ],

    [
        'slug'      => 'state.energy',
        'name'      => 'Energía',
        'name_i18n' => ['es' => 'Energía', 'en' => 'Energy'],
        'definition' => 'Capacidad disponible para hacer cosas. El cansancio es el extremo bajo '
            . 'de esta variable, no una variable aparte.',
        'category' => 'state', 'value_type' => 'ordinal',
        'scale_min' => 1, 'scale_max' => 5, 'unit' => null,
        'polarity' => 'higher_better', 'temporal_kind' => 'instant',
        'objectivity' => 'subjective', 'requires_confirm' => 0,
        'extraction_hint' => 'Anclas: 1 = agotado · 2 = con poca energía · 3 = normal · '
            . '4 = con energía · 5 = lleno de energía. '
            . 'IMPORTANTE: "cansado", "agotado", "reventado", "sin fuerzas" son ESTA variable '
            . 'con valor bajo. No crear una variable de cansancio.',
        'aliases' => ['energía', 'cansado', 'cansancio', 'agotado', 'agotamiento',
                      'reventado', 'hecho polvo', 'sin fuerzas', 'con pilas',
                      'fatiga', 'somnolencia', 'con sueño'],
    ],

    [
        'slug'      => 'state.stress',
        'name'      => 'Estrés',
        'name_i18n' => ['es' => 'Estrés', 'en' => 'Stress'],
        'definition' => 'Sensación de presión por demandas que superan lo que se puede atender.',
        'category' => 'state', 'value_type' => 'ordinal',
        'scale_min' => 1, 'scale_max' => 5, 'unit' => null,
        'polarity' => 'higher_worse', 'temporal_kind' => 'instant',
        'objectivity' => 'subjective', 'requires_confirm' => 0,
        'extraction_hint' => $ANCLAS_INTENSIDAD . ' '
            . 'IMPORTANTE: "tranquilo", "relajado", "en calma" son ESTA variable con valor 1 o 2. '
            . 'No crear una variable de calma: sería el inverso de esta y produciría filas '
            . 'contradictorias. Distinguir de agobio: el estrés es presión por demanda; '
            . 'el agobio es la sensación de no poder con ello.',
        'aliases' => ['estrés', 'estresado', 'presión', 'tenso', 'a tope',
                      'tranquilo', 'relajado', 'en calma', 'calmado', 'sereno'],
    ],

    // =================================================================
    // EMOCIONES — episódicas, se enganchan a acontecimientos.
    //
    // Se guardan como banda de intensidad, no como número: convertir
    // "bastante nervioso" en 8.0 fabrica una precisión que nadie ha medido,
    // y a los tres meses eso es una serie temporal con tendencia.
    //
    // Coste de extracción: las 15 son UNA decisión ("¿se nombra alguna
    // emoción? elige de esta lista"), no quince. Por eso el vocabulario
    // emocional puede ser rico sin degradar la extracción.
    // =================================================================

    [
        'slug'      => 'emotion.sadness',
        'name'      => 'Tristeza',
        'name_i18n' => ['es' => 'Tristeza', 'en' => 'Sadness'],
        'definition' => 'Pena, aflicción, ganas de llorar. Suele apuntar a una pérdida.',
        'category' => 'emotion', 'value_type' => 'category_intensity',
        'polarity' => 'higher_worse', 'temporal_kind' => 'episodic',
        'objectivity' => 'subjective', 'requires_confirm' => 0,
        'extraction_hint' => 'Distinguir de apatía (que es no sentir ni querer nada) y de '
            . 'ánimo bajo (que es la valoración global).',
        'aliases' => ['tristeza', 'triste', 'pena', 'llorar', 'llorando',
                      'apenado', 'melancolía', 'nostalgia', 'jodido'],
    ],

    [
        'slug'      => 'emotion.anxiety',
        'name'      => 'Ansiedad',
        'name_i18n' => ['es' => 'Ansiedad', 'en' => 'Anxiety'],
        'definition' => 'Inquietud anticipatoria, difusa, sin objeto concreto.',
        'category' => 'emotion', 'value_type' => 'category_intensity',
        'polarity' => 'higher_worse', 'temporal_kind' => 'episodic',
        'objectivity' => 'subjective', 'requires_confirm' => 0,
        'extraction_hint' => 'La ansiedad no tiene objeto definido; el miedo sí. '
            . '"Nervioso" en español coloquial suele ser esto.',
        'aliases' => ['ansiedad', 'ansioso', 'nervios', 'nervioso', 'inquieto',
                      'angustia', 'angustiado', 'desasosiego', 'intranquilo'],
    ],

    [
        'slug'      => 'emotion.anger',
        'name'      => 'Enfado',
        'name_i18n' => ['es' => 'Enfado', 'en' => 'Anger'],
        'definition' => 'Ira dirigida a alguien o algo concreto, incluido uno mismo.',
        'category' => 'emotion', 'value_type' => 'category_intensity',
        'polarity' => 'higher_worse', 'temporal_kind' => 'episodic',
        'objectivity' => 'subjective', 'requires_confirm' => 0,
        'extraction_hint' => 'Si se dice contra quién, rellenar target_entity_ref: '
            . '"enfadado conmigo mismo" y "enfadado con mi hermano" son datos distintos. '
            . 'Distinguir de irritabilidad, que no tiene objeto.',
        'aliases' => ['enfado', 'enfadado', 'cabreo', 'cabreado', 'rabia',
                      'ira', 'furioso', 'indignado', 'mosqueado', 'quemado'],
    ],

    [
        'slug'      => 'emotion.fear',
        'name'      => 'Miedo',
        'name_i18n' => ['es' => 'Miedo', 'en' => 'Fear'],
        'definition' => 'Temor ante algo identificable.',
        'category' => 'emotion', 'value_type' => 'category_intensity',
        'polarity' => 'higher_worse', 'temporal_kind' => 'episodic',
        'objectivity' => 'subjective', 'requires_confirm' => 0,
        'extraction_hint' => 'Requiere un objeto concreto. Sin objeto identificable es ansiedad.',
        'aliases' => ['miedo', 'temor', 'asustado', 'pánico', 'terror', 'acojonado'],
    ],

    [
        'slug'      => 'emotion.frustration',
        'name'      => 'Frustración',
        'name_i18n' => ['es' => 'Frustración', 'en' => 'Frustration'],
        'definition' => 'Malestar por un obstáculo que impide avanzar hacia algo que se quiere.',
        'category' => 'emotion', 'value_type' => 'category_intensity',
        'polarity' => 'higher_worse', 'temporal_kind' => 'episodic',
        'objectivity' => 'subjective', 'requires_confirm' => 0,
        'extraction_hint' => 'Hay un obstáculo y una meta. Sin meta bloqueada es enfado.',
        'aliases' => ['frustración', 'frustrado', 'impotencia', 'no avanzo',
                      'atascado', 'harto', 'no hay manera'],
    ],

    [
        'slug'      => 'emotion.guilt',
        'name'      => 'Culpa',
        'name_i18n' => ['es' => 'Culpa', 'en' => 'Guilt'],
        'definition' => 'Malestar por algo que se ha hecho o dejado de hacer.',
        'category' => 'emotion', 'value_type' => 'category_intensity',
        'polarity' => 'higher_worse', 'temporal_kind' => 'episodic',
        'objectivity' => 'subjective', 'requires_confirm' => 1,
        'extraction_hint' => 'La culpa es sobre un ACTO ("no debí decir eso"). '
            . 'La vergüenza es sobre uno mismo ("soy un desastre"). '
            . 'Extraer solo si se nombra o se describe con claridad: no inferirla del tono.',
        'aliases' => ['culpa', 'culpable', 'remordimiento', 'mala conciencia',
                      'no debí', 'me arrepiento'],
    ],

    [
        'slug'      => 'emotion.shame',
        'name'      => 'Vergüenza',
        'name_i18n' => ['es' => 'Vergüenza', 'en' => 'Shame / embarrassment'],
        'definition' => 'Malestar por cómo uno queda ante los demás o ante sí mismo. '
            . 'En español una sola palabra cubre lo que el inglés parte en shame y '
            . 'embarrassment; se conserva unida a propósito.',
        'category' => 'emotion', 'value_type' => 'category_intensity',
        'polarity' => 'higher_worse', 'temporal_kind' => 'episodic',
        'objectivity' => 'subjective', 'requires_confirm' => 1,
        'extraction_hint' => 'Sobre uno mismo, no sobre un acto concreto (eso es culpa). '
            . 'Extraer solo si se nombra o se describe con claridad.',
        'aliases' => ['vergüenza', 'avergonzado', 'ridículo', 'bochorno',
                      'me da corte', 'humillado'],
    ],

    [
        'slug'      => 'emotion.loneliness',
        'name'      => 'Soledad',
        'name_i18n' => ['es' => 'Soledad', 'en' => 'Loneliness'],
        'definition' => 'Sensación de falta de vínculo. No es lo mismo que estar solo: '
            . 'se puede sentir acompañado a solas y muy solo rodeado de gente.',
        'category' => 'emotion', 'value_type' => 'category_intensity',
        'polarity' => 'higher_worse', 'temporal_kind' => 'episodic',
        'objectivity' => 'subjective', 'requires_confirm' => 0,
        'extraction_hint' => 'Es la sensación, NO el hecho de estar sin compañía. '
            . 'Estar solo sin malestar no es soledad: eso, si acaso, es behavior.social_contact.',
        'aliases' => ['soledad', 'solo', 'me siento solo', 'aislado por dentro',
                      'nadie', 'incomprendido', 'desconectado'],
    ],

    [
        'slug'      => 'emotion.irritability',
        'name'      => 'Irritabilidad',
        'name_i18n' => ['es' => 'Irritabilidad', 'en' => 'Irritability'],
        'definition' => 'Umbral bajo: todo molesta. Es un estado, no un episodio dirigido.',
        'category' => 'emotion', 'value_type' => 'category_intensity',
        'polarity' => 'higher_worse', 'temporal_kind' => 'episodic',
        'objectivity' => 'subjective', 'requires_confirm' => 0,
        'extraction_hint' => 'Sin objeto concreto. Si hay alguien o algo determinado, es enfado.',
        'aliases' => ['irritable', 'irritabilidad', 'todo me molesta', 'susceptible',
                      'de mal café', 'de mala leche', 'saltando por todo', 'arisco'],
    ],

    [
        'slug'      => 'emotion.overwhelm',
        'name'      => 'Agobio',
        'name_i18n' => ['es' => 'Agobio', 'en' => 'Overwhelm (aprox.)'],
        'definition' => 'Sensación de no poder con lo que hay encima. Mezcla presión, '
            . 'ahogo y falta de salida. La etiqueta inglesa es una aproximación: '
            . '"overwhelm" no recoge el matiz de ahogo del término español.',
        'category' => 'emotion', 'value_type' => 'category_intensity',
        'polarity' => 'higher_worse', 'temporal_kind' => 'episodic',
        'objectivity' => 'subjective', 'requires_confirm' => 0,
        'extraction_hint' => 'El estrés es presión por demanda; el agobio es la sensación '
            . 'de no poder con ella. Pueden aparecer juntos y son datos distintos.',
        'aliases' => ['agobio', 'agobiado', 'abrumado', 'superado', 'no puedo con todo',
                      'me ahogo', 'sobrepasado', 'desbordado'],
    ],

    [
        'slug'      => 'emotion.apathy',
        'name'      => 'Apatía',
        'name_i18n' => ['es' => 'Apatía', 'en' => 'Apathy'],
        'definition' => 'Ausencia de ganas y de interés. No es tristeza ni cansancio: '
            . 'se puede tener energía de sobra y aun así no apetecer nada.',
        'category' => 'emotion', 'value_type' => 'category_intensity',
        'polarity' => 'higher_worse', 'temporal_kind' => 'episodic',
        'objectivity' => 'subjective', 'requires_confirm' => 0,
        'extraction_hint' => 'Distinguir de energía baja (que es no poder) y de tristeza '
            . '(que es dolor). La apatía es no querer.',
        'aliases' => ['apatía', 'desgana', 'sin ganas', 'no me apetece nada',
                      'me da igual todo', 'indiferencia', 'vacío', 'desmotivado'],
    ],

    [
        'slug'      => 'emotion.joy',
        'name'      => 'Alegría',
        'name_i18n' => ['es' => 'Alegría', 'en' => 'Joy'],
        'definition' => 'Emoción positiva viva, ligada a algo que ha pasado.',
        'category' => 'emotion', 'value_type' => 'category_intensity',
        'polarity' => 'higher_better', 'temporal_kind' => 'episodic',
        'objectivity' => 'subjective', 'requires_confirm' => 0,
        'extraction_hint' => 'Episódica. El bienestar de fondo va en state.mood.',
        'aliases' => ['alegría', 'alegre', 'contento', 'feliz', 'felicidad',
                      'me ha encantado', 'genial', 'eufórico'],
    ],

    [
        'slug'      => 'emotion.anticipation',
        'name'      => 'Ilusión',
        'name_i18n' => ['es' => 'Ilusión', 'en' => 'Positive anticipation (aprox.)'],
        'definition' => 'Ganas cálidas de que llegue algo. El inglés no tiene un término '
            . 'equivalente; la etiqueta inglesa es una descripción, no una traducción. '
            . 'Es una de las razones por las que este catálogo se diseña en español '
            . 'y no se traduce.',
        'category' => 'emotion', 'value_type' => 'category_intensity',
        'polarity' => 'higher_better', 'temporal_kind' => 'episodic',
        'objectivity' => 'subjective', 'requires_confirm' => 0,
        'extraction_hint' => 'Mira hacia delante. Si el objeto está en el futuro, vincular '
            . 'con la observación de tipo `plan` correspondiente.',
        'aliases' => ['ilusión', 'ilusionado', 'con ganas', 'me hace ilusión',
                      'esperanza', 'expectación', 'deseando'],
    ],

    [
        'slug'      => 'emotion.satisfaction',
        'name'      => 'Satisfacción',
        'name_i18n' => ['es' => 'Satisfacción', 'en' => 'Satisfaction'],
        'definition' => 'Gusto por algo conseguido o bien hecho.',
        'category' => 'emotion', 'value_type' => 'category_intensity',
        'polarity' => 'higher_better', 'temporal_kind' => 'episodic',
        'objectivity' => 'subjective', 'requires_confirm' => 0,
        'extraction_hint' => 'Ligada a un logro. Sin logro detrás, probablemente sea alegría.',
        'aliases' => ['satisfacción', 'satisfecho', 'orgulloso', 'orgullo',
                      'bien conmigo mismo', 'contento con lo que he hecho', 'logro'],
    ],

    [
        'slug'      => 'emotion.connection',
        'name'      => 'Conexión',
        'name_i18n' => ['es' => 'Conexión', 'en' => 'Connection'],
        'definition' => 'Sensación de cercanía con alguien concreto.',
        'category' => 'emotion', 'value_type' => 'category_intensity',
        'polarity' => 'higher_better', 'temporal_kind' => 'episodic',
        'objectivity' => 'subjective', 'requires_confirm' => 0,
        'extraction_hint' => 'Casi siempre lleva target_entity_ref: la conexión es CON alguien. '
            . 'Ahí está la diferencia estructural con la soledad, que es un estado sin objeto.',
        'aliases' => ['conexión', 'conectado', 'cercanía', 'cerca de', 'entendido',
                      'a gusto con', 'me sentí acompañado', 'complicidad'],
    ],

    // =================================================================
    // COGNICIÓN — cómo funciona la cabeza, no qué se siente.
    // =================================================================

    [
        'slug'      => 'cognition.rumination',
        'name'      => 'Rumiación',
        'name_i18n' => ['es' => 'Rumiación', 'en' => 'Rumination'],
        'definition' => 'Dar vueltas a lo mismo, hacia atrás, sin llegar a ninguna parte.',
        'category' => 'cognition', 'value_type' => 'ordinal',
        'scale_min' => 1, 'scale_max' => 5, 'unit' => null,
        'polarity' => 'higher_worse', 'temporal_kind' => 'instant',
        'objectivity' => 'subjective', 'requires_confirm' => 0,
        'extraction_hint' => $ANCLAS_INTENSIDAD . ' '
            . 'Mira al pasado y es repetitiva. Si mira al futuro, es preocupación. '
            . '"No paro de darle vueltas" es la expresión más habitual en español.',
        'aliases' => ['rumiación', 'darle vueltas', 'no paro de pensar', 'obsesionado',
                      'no me lo quito de la cabeza', 'una y otra vez', 'rayado', 'comerme la cabeza'],
    ],

    [
        'slug'      => 'cognition.worry',
        'name'      => 'Preocupación',
        'name_i18n' => ['es' => 'Preocupación', 'en' => 'Worry'],
        'definition' => 'Anticipar lo que puede salir mal.',
        'category' => 'cognition', 'value_type' => 'ordinal',
        'scale_min' => 1, 'scale_max' => 5, 'unit' => null,
        'polarity' => 'higher_worse', 'temporal_kind' => 'instant',
        'objectivity' => 'subjective', 'requires_confirm' => 0,
        'extraction_hint' => $ANCLAS_INTENSIDAD . ' '
            . 'Mira al futuro. La rumiación mira al pasado. La ansiedad es la emoción; '
            . 'esto es el contenido mental.',
        'aliases' => ['preocupación', 'preocupado', 'me preocupa', 'y si',
                      'anticipando', 'temiendo que', 'agobiado por lo que pueda pasar'],
    ],

    [
        'slug'      => 'cognition.focus',
        'name'      => 'Concentración',
        'name_i18n' => ['es' => 'Concentración', 'en' => 'Focus'],
        'definition' => 'Capacidad de sostener la atención en lo que se está haciendo.',
        'category' => 'cognition', 'value_type' => 'ordinal',
        'scale_min' => 1, 'scale_max' => 5, 'unit' => null,
        'polarity' => 'higher_better', 'temporal_kind' => 'instant',
        'objectivity' => 'subjective', 'requires_confirm' => 0,
        'extraction_hint' => '1 = incapaz de concentrarme · 3 = normal · 5 = muy concentrado.',
        'aliases' => ['concentración', 'concentrado', 'disperso', 'no me centro',
                      'despistado', 'la cabeza en otro sitio', 'foco', 'claridad mental'],
    ],

    [
        'slug'      => 'cognition.self_criticism',
        'name'      => 'Autocrítica',
        'name_i18n' => ['es' => 'Autocrítica', 'en' => 'Self-criticism'],
        'definition' => 'Hablarse a uno mismo con dureza.',
        'category' => 'cognition', 'value_type' => 'ordinal',
        'scale_min' => 1, 'scale_max' => 5, 'unit' => null,
        'polarity' => 'higher_worse', 'temporal_kind' => 'instant',
        'objectivity' => 'subjective', 'requires_confirm' => 1,
        'extraction_hint' => $ANCLAS_INTENSIDAD . ' '
            . 'Extraer del contenido literal de lo que dice sobre sí mismo, no del tono. '
            . 'Guardar también el pensamiento como observación de tipo `thought`.',
        'aliases' => ['autocrítica', 'soy un desastre', 'lo hago todo mal', 'soy tonto',
                      'no valgo', 'me machaco', 'qué inútil soy', 'no sirvo'],
    ],

    [
        'slug'      => 'cognition.perceived_control',
        'name'      => 'Sensación de control',
        'name_i18n' => ['es' => 'Sensación de control', 'en' => 'Perceived control'],
        'definition' => 'Hasta qué punto siente que lo que hace cambia algo.',
        'category' => 'cognition', 'value_type' => 'ordinal',
        'scale_min' => 1, 'scale_max' => 5, 'unit' => null,
        'polarity' => 'higher_better', 'temporal_kind' => 'instant',
        'objectivity' => 'subjective', 'requires_confirm' => 1,
        'extraction_hint' => '1 = nada en mi mano · 3 = a medias · 5 = totalmente en mi mano. '
            . 'Es interpretativa: extraer solo si lo dice con claridad, nunca deducirla '
            . 'de que las cosas le hayan salido bien o mal.',
        'aliases' => ['control', 'en mi mano', 'no depende de mí', 'no puedo hacer nada',
                      'me supera', 'lo tengo controlado', 'a la deriva', 'impotente'],
    ],

    // =================================================================
    // FÍSICO
    // =================================================================

    [
        'slug'      => 'physical.pain',
        'name'      => 'Dolor',
        'name_i18n' => ['es' => 'Dolor', 'en' => 'Pain'],
        'definition' => 'Dolor físico, de cualquier localización.',
        'category' => 'physical', 'value_type' => 'ordinal',
        'scale_min' => 1, 'scale_max' => 5, 'unit' => null,
        'polarity' => 'higher_worse', 'temporal_kind' => 'instant',
        'objectivity' => 'subjective', 'requires_confirm' => 0,
        'extraction_hint' => $ANCLAS_INTENSIDAD . ' '
            . 'Guardar la localización en value_text si se dice ("cabeza", "espalda").',
        'aliases' => ['dolor', 'me duele', 'dolorido', 'migraña', 'jaqueca',
                      'molestia física', 'punzada'],
    ],

    [
        'slug'      => 'physical.tension',
        'name'      => 'Tensión corporal',
        'name_i18n' => ['es' => 'Tensión corporal', 'en' => 'Body tension'],
        'definition' => 'Rigidez o agarrotamiento. Es la cara somática del estrés, y puede '
            . 'ir por su cuenta: se puede estar tenso sin sentirse estresado, y al revés.',
        'category' => 'physical', 'value_type' => 'ordinal',
        'scale_min' => 1, 'scale_max' => 5, 'unit' => null,
        'polarity' => 'higher_worse', 'temporal_kind' => 'instant',
        'objectivity' => 'subjective', 'requires_confirm' => 0,
        'extraction_hint' => $ANCLAS_INTENSIDAD . ' '
            . 'Solo sensaciones CORPORALES. Que se disocie del estrés declarado es '
            . 'precisamente lo interesante, así que no derivar la una de la otra.',
        'aliases' => ['tensión', 'agarrotado', 'contracturado', 'rígido',
                      'nudo en el estómago', 'opresión en el pecho', 'mandíbula apretada'],
    ],

    [
        'slug'      => 'physical.appetite',
        'name'      => 'Apetito',
        'name_i18n' => ['es' => 'Apetito', 'en' => 'Appetite'],
        'definition' => 'Ganas de comer respecto a lo habitual en esta persona.',
        'category' => 'physical', 'value_type' => 'ordinal',
        'scale_min' => 1, 'scale_max' => 5, 'unit' => null,
        'polarity' => 'neutral', 'temporal_kind' => 'instant',
        'objectivity' => 'subjective', 'requires_confirm' => 0,
        'extraction_hint' => '1 = sin nada de hambre · 3 = lo normal · 5 = mucha más hambre. '
            . 'Escala centrada: 3 es lo habitual y ambos extremos son notables. '
            . 'Por eso la polaridad es neutral.',
        'aliases' => ['apetito', 'hambre', 'sin hambre', 'no me entra nada',
                      'comiendo mucho', 'ansiedad por comer', 'inapetente'],
    ],

    // =================================================================
    // SUEÑO — el factor de confusión clásico de cualquier análisis de ánimo.
    // =================================================================

    [
        'slug'      => 'sleep.duration',
        'name'      => 'Horas de sueño',
        'name_i18n' => ['es' => 'Horas de sueño', 'en' => 'Sleep duration'],
        'definition' => 'Horas dormidas. Cantidad, separada de la calidad percibida.',
        'category' => 'sleep', 'value_type' => 'numeric',
        'scale_min' => 0, 'scale_max' => 16, 'unit' => 'horas',
        // Neutral y no higher_better: dormir de más también es señal.
        'polarity' => 'neutral', 'temporal_kind' => 'daily',
        'objectivity' => 'mixed', 'requires_confirm' => 1,
        'extraction_hint' => 'Solo si da una cantidad. "He dormido poco" NO es un número: '
            . 'eso va a sleep.quality o se deja sin extraer. '
            . 'Aceptar rangos ("unas seis o siete") tomando el punto medio y bajando '
            . 'time_precision.',
        'aliases' => ['horas de sueño', 'he dormido', 'dormí', 'me acosté', 'me levanté'],
    ],

    [
        'slug'      => 'sleep.quality',
        'name'      => 'Calidad del sueño',
        'name_i18n' => ['es' => 'Calidad del sueño', 'en' => 'Sleep quality'],
        'definition' => 'Cómo de reparador ha sido el sueño, con independencia de las horas.',
        'category' => 'sleep', 'value_type' => 'ordinal',
        'scale_min' => 1, 'scale_max' => 5, 'unit' => null,
        'polarity' => 'higher_better', 'temporal_kind' => 'daily',
        'objectivity' => 'subjective', 'requires_confirm' => 0,
        'extraction_hint' => '1 = fatal · 3 = normal · 5 = estupendamente. '
            . 'NUNCA fusionar con las horas: dormir ocho horas mal y cinco bien son '
            . 'datos distintos y esa disociación es analíticamente valiosa.',
        'aliases' => ['calidad del sueño', 'he dormido bien', 'he dormido mal',
                      'descansado', 'sin descansar', 'sueño reparador', 'como un tronco'],
    ],

    [
        'slug'      => 'sleep.fragmentation',
        'name'      => 'Fragmentación del sueño',
        'name_i18n' => ['es' => 'Fragmentación del sueño', 'en' => 'Sleep fragmentation'],
        'definition' => 'Cuánto se ha interrumpido el sueño.',
        'category' => 'sleep', 'value_type' => 'ordinal',
        'scale_min' => 1, 'scale_max' => 5, 'unit' => null,
        'polarity' => 'higher_worse', 'temporal_kind' => 'daily',
        'objectivity' => 'subjective', 'requires_confirm' => 0,
        'extraction_hint' => '1 = de un tirón · 3 = algún despertar · 5 = muy roto. '
            . 'Ordinal y no recuento: "me desperté varias veces" no es un número, '
            . 'y convertirlo en uno sería inventarlo.',
        'aliases' => ['despertares', 'me desperté', 'sueño roto', 'de un tirón',
                      'dando vueltas en la cama', 'desvelado', 'insomnio'],
    ],

    // =================================================================
    // CONDUCTA
    //
    // Todas con polaridad NEUTRAL, y es deliberado. Si salir de casa o
    // estar solo le sienta bien o mal a ESTA persona es exactamente lo
    // que la aplicación debe descubrir, no lo que debe presuponer.
    //
    // Cuando la cantidad se deduce mejor contando observaciones, la
    // variable es solo una bandera diaria.
    // =================================================================

    [
        'slug'      => 'behavior.exercise',
        'name'      => 'Ejercicio',
        'name_i18n' => ['es' => 'Ejercicio', 'en' => 'Exercise'],
        'definition' => 'Actividad física intencionada.',
        'category' => 'behavior', 'value_type' => 'duration',
        'scale_min' => 0, 'scale_max' => 600, 'unit' => 'minutos',
        'polarity' => 'neutral', 'temporal_kind' => 'daily',
        'objectivity' => 'objective', 'requires_confirm' => 0,
        'extraction_hint' => 'Si dice cuánto, guardar los minutos en value_num. Si solo dice '
            . 'que lo hizo, value_bool = true y value_num vacío: "ha pasado, cantidad '
            . 'desconocida" es un dato legítimo y no hay que rellenarlo a ojo.',
        'aliases' => ['ejercicio', 'entrenar', 'entrenamiento', 'correr', 'gimnasio',
                      'nadar', 'bici', 'caminar', 'andar', 'paseo', 'deporte', 'yoga'],
    ],

    [
        'slug'      => 'behavior.social_contact',
        'name'      => 'Contacto social',
        'name_i18n' => ['es' => 'Contacto social', 'en' => 'Social contact'],
        'definition' => 'Ha habido trato con alguien más allá de lo funcional.',
        'category' => 'behavior', 'value_type' => 'boolean',
        'polarity' => 'neutral', 'temporal_kind' => 'daily',
        'objectivity' => 'objective', 'requires_confirm' => 0,
        'extraction_hint' => 'Bandera diaria. El detalle de con quién y cuánto sale de las '
            . 'observaciones y sus entidades, no de esta variable.',
        'aliases' => ['he quedado', 'he visto a', 'he hablado con', 'hemos cenado',
                      'tomar algo', 'con gente', 'reunión con amigos'],
    ],

    [
        'slug'      => 'behavior.withdrawal',
        'name'      => 'Retraimiento',
        'name_i18n' => ['es' => 'Retraimiento', 'en' => 'Withdrawal'],
        'definition' => 'Apartarse activamente del contacto, no simplemente estar solo.',
        'category' => 'behavior', 'value_type' => 'boolean',
        'polarity' => 'neutral', 'temporal_kind' => 'daily',
        'objectivity' => 'objective', 'requires_confirm' => 1,
        'extraction_hint' => 'Requiere una decisión de apartarse: no coger el teléfono, '
            . 'cancelar, no querer ver a nadie. Estar solo sin más NO es esto. '
            . 'Llamar "retraimiento" a la soledad de alguien es una interpretación, '
            . 'así que siempre pasa por confirmación del usuario.',
        'aliases' => ['aislarme', 'no quiero ver a nadie', 'he cancelado', 'no cogí el teléfono',
                      'me he encerrado', 'pasar de todo el mundo', 'no contesté'],
    ],

    [
        'slug'      => 'behavior.outdoors',
        'name'      => 'Salir de casa',
        'name_i18n' => ['es' => 'Salir de casa', 'en' => 'Went outside'],
        'definition' => 'Ha salido de casa por algún motivo.',
        'category' => 'behavior', 'value_type' => 'boolean',
        'polarity' => 'neutral', 'temporal_kind' => 'daily',
        'objectivity' => 'objective', 'requires_confirm' => 0,
        'extraction_hint' => 'Muy señalado cuando NO ocurre: "no he salido de casa en todo '
            . 'el día" es una frase muy frecuente y muy informativa. Registrar el false '
            . 'con el mismo cuidado que el true.',
        'aliases' => ['he salido', 'no he salido de casa', 'todo el día en casa',
                      'me dio el aire', 'salí a dar una vuelta'],
    ],

    [
        'slug'      => 'behavior.screen_time',
        'name'      => 'Tiempo de pantallas',
        'name_i18n' => ['es' => 'Tiempo de pantallas', 'en' => 'Screen time'],
        'definition' => 'Cuánto tiempo ha pasado con móvil, ordenador o televisión '
            . 'fuera del trabajo.',
        'category' => 'behavior', 'value_type' => 'ordinal',
        'scale_min' => 1, 'scale_max' => 5, 'unit' => null,
        // Neutral: dar por hecho que las pantallas son malas sería presuponer
        // justo lo que hay que averiguar.
        'polarity' => 'neutral', 'temporal_kind' => 'daily',
        'objectivity' => 'subjective', 'requires_confirm' => 0,
        'extraction_hint' => '1 = casi nada · 3 = lo normal · 5 = muchísimo. '
            . 'Ordinal y no minutos: nadie dice "cuatro horas y doce minutos", '
            . 'así que un número sería inventado.',
        'aliases' => ['pantalla', 'móvil', 'el teléfono', 'scroll', 'redes',
                      'tele', 'series', 'youtube', 'tiktok'],
    ],

    [
        'slug'      => 'behavior.alcohol',
        'name'      => 'Alcohol',
        'name_i18n' => ['es' => 'Alcohol', 'en' => 'Alcohol'],
        'definition' => 'Consumiciones alcohólicas tomadas.',
        'category' => 'behavior', 'value_type' => 'count',
        'scale_min' => 0, 'scale_max' => 30, 'unit' => 'consumiciones',
        'polarity' => 'neutral', 'temporal_kind' => 'daily',
        'objectivity' => 'objective', 'requires_confirm' => 1,
        'extraction_hint' => 'Contar solo si da cantidad o esta se deduce sin ambigüedad '
            . '("un par de cervezas" = 2). "Bebí bastante" no es un número: dejarlo en '
            . 'value_text con value_bool = true.',
        'aliases' => ['alcohol', 'cerveza', 'cervezas', 'vino', 'copa', 'copas',
                      'beber', 'bebí', 'resaca'],
    ],

    [
        'slug'      => 'behavior.caffeine',
        'name'      => 'Cafeína',
        'name_i18n' => ['es' => 'Cafeína', 'en' => 'Caffeine'],
        'definition' => 'Cafés o equivalentes tomados.',
        'category' => 'behavior', 'value_type' => 'count',
        'scale_min' => 0, 'scale_max' => 20, 'unit' => 'cafés',
        'polarity' => 'neutral', 'temporal_kind' => 'daily',
        'objectivity' => 'objective', 'requires_confirm' => 0,
        'extraction_hint' => 'Solo si da cantidad. Guardar la hora si se menciona: '
            . 'el café de la tarde y el de la mañana no son el mismo dato para el sueño.',
        'aliases' => ['café', 'cafés', 'cafeína', 'té', 'bebida energética', 'cortado'],
    ],

    // =================================================================
    // SOCIAL — banderas diarias; el detalle vive en observaciones y entidades.
    // =================================================================

    [
        'slug'      => 'social.conflict',
        'name'      => 'Conflicto',
        'name_i18n' => ['es' => 'Conflicto', 'en' => 'Conflict'],
        'definition' => 'Ha habido una discusión, un roce o una tensión con alguien.',
        'category' => 'social', 'value_type' => 'boolean',
        'polarity' => 'higher_worse', 'temporal_kind' => 'episodic',
        'objectivity' => 'objective', 'requires_confirm' => 0,
        'extraction_hint' => 'Crear ADEMÁS una observación de tipo `event` con las entidades '
            . 'implicadas. Esta variable es solo la bandera para poder comparar días '
            . 'con y sin conflicto sin recorrer todas las observaciones.',
        'aliases' => ['discusión', 'discutir', 'discutí', 'bronca', 'pelea',
                      'roce', 'tensión con', 'me enfadé con', 'malentendido'],
    ],

    [
        'slug'      => 'social.support',
        'name'      => 'Apoyo recibido',
        'name_i18n' => ['es' => 'Apoyo recibido', 'en' => 'Support received'],
        'definition' => 'Alguien le ha escuchado, ayudado o acompañado.',
        'category' => 'social', 'value_type' => 'boolean',
        'polarity' => 'neutral', 'temporal_kind' => 'episodic',
        'objectivity' => 'objective', 'requires_confirm' => 0,
        'extraction_hint' => 'Requiere que lo describa como recibido, no solo que hubiera '
            . 'compañía. Crear también la observación con la entidad implicada.',
        'aliases' => ['me ayudó', 'me escuchó', 'me apoyó', 'estuvo ahí',
                      'me vino bien hablar', 'me echó una mano', 'desahogarme'],
    ],

    // =================================================================
    // TRABAJO
    // =================================================================

    [
        'slug'      => 'work.hours',
        'name'      => 'Horas trabajadas',
        'name_i18n' => ['es' => 'Horas trabajadas', 'en' => 'Hours worked'],
        'definition' => 'Horas dedicadas al trabajo o los estudios.',
        'category' => 'work', 'value_type' => 'numeric',
        'scale_min' => 0, 'scale_max' => 24, 'unit' => 'horas',
        'polarity' => 'neutral', 'temporal_kind' => 'daily',
        'objectivity' => 'mixed', 'requires_confirm' => 1,
        'extraction_hint' => 'Solo si da una cantidad. "He currado un montón" no es un '
            . 'número: eso es work.load.',
        'aliases' => ['horas trabajadas', 'he trabajado', 'jornada', 'he currado',
                      'me he quedado hasta', 'toda la mañana trabajando'],
    ],

    [
        'slug'      => 'work.load',
        'name'      => 'Carga de trabajo',
        'name_i18n' => ['es' => 'Carga de trabajo', 'en' => 'Workload'],
        'definition' => 'Cuánto había que hacer, con independencia de las horas.',
        'category' => 'work', 'value_type' => 'ordinal',
        'scale_min' => 1, 'scale_max' => 5, 'unit' => null,
        // Mucha carga no es necesariamente malo: hay quien rinde mejor así.
        'polarity' => 'neutral', 'temporal_kind' => 'daily',
        'objectivity' => 'subjective', 'requires_confirm' => 0,
        'extraction_hint' => '1 = muy poca · 3 = lo normal · 5 = muchísima. '
            . 'Es la cantidad de trabajo, no el malestar que produce: eso es '
            . 'state.stress o emotion.overwhelm.',
        'aliases' => ['carga de trabajo', 'mucho lío', 'hasta arriba', 'liado',
                      'día tranquilo en el trabajo', 'sin nada que hacer', 'marrones'],
    ],
];
