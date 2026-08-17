<?php

declare(strict_types=1);

namespace MaiMind\Tests\Domain;

use DateTimeImmutable;
use DateTimeZone;
use MaiMind\Domain\Capture\CaptureClock;
use PHPUnit\Framework\TestCase;

/**
 * El día local es el dato del que dependen TODOS los agregados diarios.
 * Si se calcula mal, la serie temporal miente desde el primer registro.
 */
final class CaptureClockTest extends TestCase
{
    private function reloj(string $ahoraUtc): CaptureClock
    {
        return new CaptureClock(new DateTimeImmutable($ahoraUtc, new DateTimeZone('UTC')));
    }

    public function test_el_dia_local_no_es_el_dia_utc(): void
    {
        // 00:30 en Madrid (verano) son las 22:30 UTC del día ANTERIOR.
        // Si se usara el día UTC, todo lo grabado de madrugada caería mal.
        $r = $this->reloj('2026-08-16 22:30:00');

        $resultado = $r->resolve('2026-08-16T22:30:00Z', 'Europe/Madrid', 120);

        $this->assertSame('2026-08-17', $resultado['local_date']);
        $this->assertStringStartsWith('2026-08-16 22:30', $resultado['captured_at']);
    }

    public function test_funciona_al_oeste_de_greenwich(): void
    {
        // 02:00 UTC son las 21:00 del día anterior en Ciudad de México.
        $r = $this->reloj('2026-08-17 02:00:00');

        $resultado = $r->resolve('2026-08-17T02:00:00Z', 'America/Mexico_City', -360);

        $this->assertSame('2026-08-16', $resultado['local_date']);
    }

    public function test_deduce_el_desfase_de_la_zona_si_no_llega(): void
    {
        $r = $this->reloj('2026-08-16 22:30:00');

        $resultado = $r->resolve('2026-08-16T22:30:00Z', 'Europe/Madrid', null);

        $this->assertSame(120, $resultado['utc_offset']);
        $this->assertSame('2026-08-17', $resultado['local_date']);
    }

    public function test_descarta_un_desfase_imposible(): void
    {
        $r = $this->reloj('2026-08-16 12:00:00');

        // No existe ningún huso a +25 horas.
        $resultado = $r->resolve('2026-08-16T12:00:00Z', 'Europe/Madrid', 1500);

        $this->assertSame(120, $resultado['utc_offset'], 'Debe caer a la zona declarada');
    }

    public function test_un_reloj_de_cliente_disparatado_se_corrige(): void
    {
        $r = $this->reloj('2026-08-16 12:00:00');

        $resultado = $r->resolve('2019-01-01T00:00:00Z', 'Europe/Madrid', 120);

        $this->assertTrue($resultado['clock_was_adjusted']);
        $this->assertStringStartsWith('2026-08-16', $resultado['captured_at']);
    }

    public function test_un_reloj_ligeramente_desviado_se_respeta(): void
    {
        // Media hora de desfase es un reloj mal puesto, no un ataque, y el
        // usuario sabe mejor que el servidor cuándo estaba hablando.
        $r = $this->reloj('2026-08-16 12:00:00');

        $resultado = $r->resolve('2026-08-16T11:30:00Z', 'Europe/Madrid', 120);

        $this->assertFalse($resultado['clock_was_adjusted']);
        $this->assertStringStartsWith('2026-08-16 11:30', $resultado['captured_at']);
    }

    public function test_sin_datos_del_cliente_usa_el_servidor(): void
    {
        $r = $this->reloj('2026-08-16 12:00:00');

        $resultado = $r->resolve(null, null, null);

        $this->assertFalse($resultado['clock_was_adjusted'], 'No hay nada que corregir');
        $this->assertSame('UTC', $resultado['timezone']);
        $this->assertSame(0, $resultado['utc_offset']);
        $this->assertSame('2026-08-16', $resultado['local_date']);
    }

    public function test_rechaza_una_zona_horaria_inventada(): void
    {
        $r = $this->reloj('2026-08-16 12:00:00');

        $resultado = $r->resolve('2026-08-16T12:00:00Z', 'Marte/Olympus', 120);

        $this->assertSame('UTC', $resultado['timezone']);
        // El desfase sí llegaba y es válido: se conserva.
        $this->assertSame(120, $resultado['utc_offset']);
    }

    public function test_guarda_los_dos_relojes_por_separado(): void
    {
        $r = $this->reloj('2026-08-16 23:05:00');

        $resultado = $r->resolve('2026-08-16T23:00:00Z', 'Europe/Madrid', 120);

        // Cuándo grabó y cuándo llegó son datos distintos y ambos se conservan.
        $this->assertStringStartsWith('2026-08-16 23:00', $resultado['captured_at']);
        $this->assertStringStartsWith('2026-08-16 23:05', $resultado['received_at']);
    }
}
