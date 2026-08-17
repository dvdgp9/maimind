<?php

declare(strict_types=1);

namespace MaiMind\Tests\Support;

use InvalidArgumentException;
use MaiMind\Support\Ulid;
use PHPUnit\Framework\TestCase;

final class UlidTest extends TestCase
{
    public function test_genera_26_caracteres_validos(): void
    {
        $ulid = Ulid::generate();

        $this->assertSame(26, strlen($ulid));
        $this->assertTrue(Ulid::isValid($ulid));
    }

    public function test_no_usa_letras_ambiguas_de_crockford(): void
    {
        // I, L, O y U se excluyen para que un ULID leído en voz alta o copiado
        // a mano no se confunda con 1, 0 o V.
        for ($i = 0; $i < 50; $i++) {
            $this->assertDoesNotMatchRegularExpression('/[ILOU]/', Ulid::generate());
        }
    }

    public function test_recupera_la_marca_temporal(): void
    {
        $ms = 1_755_000_000_000;

        $this->assertSame($ms, Ulid::timestamp(Ulid::generate($ms)));
    }

    public function test_ordena_lexicograficamente_por_tiempo(): void
    {
        $antes   = Ulid::generate(1_700_000_000_000);
        $despues = Ulid::generate(1_800_000_000_000);

        $this->assertLessThan(0, strcmp($antes, $despues));
    }

    public function test_es_unico_en_el_mismo_milisegundo(): void
    {
        $ms = 1_755_000_000_000;

        $generados = [];

        for ($i = 0; $i < 1000; $i++) {
            $generados[] = Ulid::generate($ms);
        }

        $this->assertCount(1000, array_unique($generados));
    }

    public function test_rechaza_ulids_mal_formados(): void
    {
        $this->assertFalse(Ulid::isValid('corto'));
        $this->assertFalse(Ulid::isValid(str_repeat('I', 26)));

        $this->expectException(InvalidArgumentException::class);
        Ulid::timestamp('no-es-un-ulid');
    }
}
