<?php

declare(strict_types=1);

namespace MaiMind\Providers\OpenRouter;

use RuntimeException;

/**
 * Por dónde se le permite a OpenRouter enrutar lo que sale de aquí.
 *
 * Decisión D10. El material que viaja a estos endpoints es lo más sensible del
 * sistema: la voz de alguien contando cómo está. En términos del RGPD es
 * categoría especial (art. 9), y a diferencia de una contraseña no se puede
 * rotar después.
 *
 * **Son dos controles distintos y hacen falta los dos**, cosa que la nota
 * original de D10 no distinguía:
 *
 *  - `data_collection: "deny"` — el proveedor no entrena con lo que se le manda.
 *  - `zdr: true` — el proveedor no conserva la petición ni la respuesta.
 *
 * Un proveedor puede cumplir uno y no el otro: guardar registros treinta días
 * sin entrenar con ellos satisface `data_collection` y viola ZDR. Pedir solo lo
 * primero, que es lo que decía la nota, dejaría copias de las grabaciones en
 * sitios sobre los que no tenemos ningún control.
 *
 * **Se manda en cada petición y no solo se confía en la configuración de la
 * cuenta.** Las dos se combinan con un OR y la petición solo puede restringir
 * más, nunca menos, así que mandarlo siempre no puede empeorar nada — y en
 * cambio protege de que alguien toque el panel de OpenRouter sin que el código
 * se entere.
 *
 * Verificado contra la documentación de OpenRouter el 2026-08-30.
 * Ver docs/api/openrouter.md §4.
 */
final class DataPolicy
{
    /** No se puede entrenar con esto. */
    public const SIN_ENTRENAMIENTO = 'deny';

    /** No se puede conservar esto. */
    public const SIN_RETENCION = true;

    /**
     * El bloque `provider` que acompaña a toda petición a OpenRouter.
     *
     * @return array{data_collection:string, zdr:bool}
     */
    public static function forRequest(): array
    {
        self::assertNotLoosened();

        return [
            'data_collection' => self::SIN_ENTRENAMIENTO,
            'zdr'             => self::SIN_RETENCION,
        ];
    }

    /**
     * Falla si alguien ha aflojado la política en la configuración.
     *
     * Cierra hacia el lado seguro a propósito: preferimos que no salga una
     * transcripción a que salga hacia un proveedor que entrena con ella. Un
     * fallo se ve; una grabación en el conjunto de entrenamiento de alguien, no,
     * y no se puede deshacer.
     */
    /** @param array<string,mixed>|null $configurada  por defecto, la de config/ */
    public static function assertNotLoosened(?array $configurada = null): void
    {
        $configurada ??= (array) config('services.openrouter.privacy', []);

        if (($configurada['data_collection'] ?? null) !== self::SIN_ENTRENAMIENTO) {
            throw new RuntimeException(
                'La política de datos de OpenRouter permite entrenamiento. '
                . 'Ver la decisión D10 en docs/design/00-critica-y-decisiones.md.'
            );
        }

        if (($configurada['zdr'] ?? null) !== self::SIN_RETENCION) {
            throw new RuntimeException(
                'La política de datos de OpenRouter no exige retención cero. '
                . 'Ver la decisión D10 en docs/design/00-critica-y-decisiones.md.'
            );
        }
    }

    /**
     * Añade la política a un cuerpo de petición.
     *
     * Es el único sitio por el que debería construirse: si cada proveedor la
     * copia por su cuenta, uno se la dejará.
     *
     * @param  array<string,mixed>  $cuerpo
     * @return array<string,mixed>
     */
    public static function applyTo(array $cuerpo): array
    {
        // Se sobrescribe lo que hubiera. Esto no es un valor por defecto que
        // quien llama pueda ajustar.
        $cuerpo['provider'] = [
            ...(array) ($cuerpo['provider'] ?? []),
            ...self::forRequest(),
        ];

        return $cuerpo;
    }
}
