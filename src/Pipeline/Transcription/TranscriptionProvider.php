<?php

declare(strict_types=1);

namespace MaiMind\Pipeline\Transcription;

/**
 * De audio a texto.
 *
 * El núcleo depende de esta firma y de nada más: cambiar de proveedor es una
 * clase nueva y una línea de configuración (04-arquitectura.md §1).
 *
 * **Tiene que ser ASR de verdad, no un LLM multimodal.** Un LLM «limpia» el
 * habla: quita muletillas, termina frases a medias, parafrasea. Para casi
 * cualquier producto eso es una mejora; para este es destructivo, porque todo
 * el anclaje de evidencia guarda citas literales con offsets sobre este texto.
 * Si el transcriptor reescribe, esas citas señalan palabras que nadie dijo.
 *
 * Quien implemente esto **lanza TranscriptionFailed** y no devuelve nulos: la
 * cola necesita saber si reintentar, y eso no se puede deducir de un null.
 */
interface TranscriptionProvider
{
    /**
     * @param  string|null  $languageHint  ISO 639-1. Ayuda, no obliga: alguien
     *   puede cambiar de idioma a mitad de una grabación.
     *
     * @throws TranscriptionFailed
     */
    public function transcribe(AudioRef $audio, ?string $languageHint = null): TranscriptionResult;

    /** Lo que se guarda en transcripts.provider. */
    public function name(): string;
}
