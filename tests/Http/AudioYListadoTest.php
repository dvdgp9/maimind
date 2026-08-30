<?php

declare(strict_types=1);

namespace MaiMind\Tests\Http;

use MaiMind\Domain\User;
use MaiMind\Repository\EntryRepository;
use MaiMind\Support\Config;
use MaiMind\Support\Ulid;
use MaiMind\Tests\AppTestCase;

/**
 * Escuchar una grabación y encontrarla en la lista.
 *
 * Sin esto la aplicación no se puede usar de verdad: solo se llegaba a la
 * última grabación, y la pantalla pedía corregir un hueco de audio sin dejar
 * oírlo.
 */
final class AudioYListadoTest extends AppTestCase
{
    /** Crea una entrada con audio real en disco. */
    private function conAudio(User $user, string $bytes = 'esto-hace-de-audio-de-prueba'): string
    {
        $uid  = Ulid::generate();
        $ruta = sprintf('audio/%s/2026/08/%s.webm', $user->uid, $uid);
        $abs  = Config::basePath('storage/' . $ruta);

        if (! is_dir(dirname($abs))) {
            mkdir(dirname($abs), 0770, true);
        }

        file_put_contents($abs, $bytes);

        (new EntryRepository($this->pdo, $user->id))->createDraft(
            uid: $uid,
            capturedAt: '2026-08-30 10:00:00',
            localDate: '2026-08-30',
            timezone: 'Europe/Madrid',
            utcOffsetMinutes: 120,
            extra: [
                'audio_path'   => $ruta,
                'audio_bytes'  => strlen($bytes),
                'audio_sha256' => hash('sha256', $bytes),
                'audio_mime'   => 'audio/webm',
                'audio_state'  => 'present',
            ],
        );

        return $uid;
    }

    // --------------------------------------------------------- el audio

    public function test_se_puede_escuchar_la_propia_grabacion(): void
    {
        $a     = $this->crearUsuario('a');
        $token = $this->iniciarSesion($a);
        $uid   = $this->conAudio($a);

        $respuesta = $this->getComo($token, '/entrada/' . $uid . '/audio');

        $this->assertSame(200, $respuesta->status);
        $this->assertSame('audio/webm', $respuesta->header('Content-Type'));
        // Sin esto, algunos navegadores no dejan mover la barra.
        $this->assertSame('bytes', $respuesta->header('Accept-Ranges'));
        // Es el audio de una persona: ni cachés compartidas ni intermediarios.
        $this->assertStringContainsString('private', (string) $respuesta->header('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $respuesta->header('Cache-Control'));
    }

    public function test_el_audio_se_manda_desde_disco_y_no_desde_memoria(): void
    {
        // Una grabación puede pesar 25 MB y el servidor tiene 3,7 GB
        // compartidos: leerla entera a una cadena por cada reproducción es un
        // problema con diez personas.
        $a     = $this->crearUsuario('a');
        $token = $this->iniciarSesion($a);
        $uid   = $this->conAudio($a);

        $respuesta = $this->getComo($token, '/entrada/' . $uid . '/audio');

        $this->assertSame('', $respuesta->body, 'El cuerpo no debería llevar los bytes');
        $this->assertNotNull($respuesta->fileInfo());
        $this->assertStringContainsString('storage/audio/', $respuesta->fileInfo()['path']);
    }

    public function test_pedir_un_trozo_devuelve_ese_trozo(): void
    {
        $a     = $this->crearUsuario('a');
        $token = $this->iniciarSesion($a);
        $uid   = $this->conAudio($a, '0123456789');

        $respuesta = $this->get(
            '/entrada/' . $uid . '/audio',
            [\MaiMind\Domain\Auth\SessionManager::COOKIE => $token],
            ['range' => 'bytes=2-5'],
        );

        $this->assertSame(206, $respuesta->status);
        $this->assertSame('bytes 2-5/10', $respuesta->header('Content-Range'));
        $this->assertSame('4', $respuesta->header('Content-Length'));
        $this->assertSame(2, $respuesta->fileInfo()['offset']);
        $this->assertSame(4, $respuesta->fileInfo()['length']);
    }

    public function test_pedir_los_ultimos_bytes_tambien_funciona(): void
    {
        // `bytes=-3` son los tres últimos, no «del 0 al 3».
        $a     = $this->crearUsuario('a');
        $token = $this->iniciarSesion($a);
        $uid   = $this->conAudio($a, '0123456789');

        $respuesta = $this->get(
            '/entrada/' . $uid . '/audio',
            [\MaiMind\Domain\Auth\SessionManager::COOKIE => $token],
            ['range' => 'bytes=-3'],
        );

        $this->assertSame(206, $respuesta->status);
        $this->assertSame('bytes 7-9/10', $respuesta->header('Content-Range'));
    }

    public function test_un_rango_imposible_se_ignora_en_vez_de_reventar(): void
    {
        $a     = $this->crearUsuario('a');
        $token = $this->iniciarSesion($a);
        $uid   = $this->conAudio($a, '0123456789');

        foreach (['bytes=50-60', 'bytes=abc', 'tortugas', 'bytes=5-2'] as $rango) {
            $respuesta = $this->get(
                '/entrada/' . $uid . '/audio',
                [\MaiMind\Domain\Auth\SessionManager::COOKIE => $token],
                ['range' => $rango],
            );

            $this->assertSame(200, $respuesta->status, "Rango: {$rango}");
        }
    }

    public function test_no_se_puede_escuchar_la_grabacion_de_otro(): void
    {
        $a = $this->crearUsuario('a');
        $b = $this->crearUsuario('b');

        $uid = $this->conAudio($a);

        $this->assertSame(404, $this->getComo($this->iniciarSesion($b), '/entrada/' . $uid . '/audio')->status);
    }

    public function test_sin_sesion_no_hay_audio(): void
    {
        $a   = $this->crearUsuario('a');
        $uid = $this->conAudio($a);

        $respuesta = $this->get('/entrada/' . $uid . '/audio');

        $this->assertNotSame(200, $respuesta->status);
        $this->assertNull($respuesta->fileInfo());
    }

    public function test_un_audio_purgado_da_404_y_no_un_fichero_vacio(): void
    {
        $a     = $this->crearUsuario('a');
        $token = $this->iniciarSesion($a);
        $uid   = $this->conAudio($a);

        $this->pdo->prepare('UPDATE entries SET audio_state = ?, audio_path = NULL WHERE uid = ?')
            ->execute(['purged', $uid]);

        $this->assertSame(404, $this->getComo($token, '/entrada/' . $uid . '/audio')->status);
    }

    public function test_la_pantalla_ofrece_escuchar_solo_si_hay_audio(): void
    {
        $a     = $this->crearUsuario('a');
        $token = $this->iniciarSesion($a);
        $uid   = $this->conAudio($a);

        $this->assertStringContainsString(
            '/entrada/' . $uid . '/audio',
            $this->getComo($token, '/entrada/' . $uid)->body,
        );

        $this->pdo->prepare('UPDATE entries SET audio_state = ? WHERE uid = ?')->execute(['purged', $uid]);

        $this->assertStringNotContainsString(
            '<audio',
            $this->getComo($token, '/entrada/' . $uid)->body,
        );
    }

    // -------------------------------------------------------- el listado

    public function test_el_listado_ensena_todas_y_no_solo_la_ultima(): void
    {
        // Era el agujero: con tres grabaciones ya no se llegaba a la primera.
        $a     = $this->crearUsuario('a');
        $token = $this->iniciarSesion($a);

        $uids = [$this->conAudio($a), $this->conAudio($a), $this->conAudio($a)];

        $html = $this->getComo($token, '/grabaciones')->body;

        foreach ($uids as $uid) {
            $this->assertStringContainsString('/entrada/' . $uid, $html);
        }
    }

    public function test_el_listado_no_ensena_las_de_otro(): void
    {
        $a = $this->crearUsuario('a');
        $b = $this->crearUsuario('b');

        $suya = $this->conAudio($a);

        $this->assertStringNotContainsString(
            $suya,
            $this->getComo($this->iniciarSesion($b), '/grabaciones')->body,
        );
    }

    public function test_el_listado_vacio_lo_dice_sin_dramatizar(): void
    {
        $a = $this->crearUsuario('a');

        $html = $this->getComo($this->iniciarSesion($a), '/grabaciones')->body;

        $this->assertStringContainsString(t('capture.no_entries'), $html);
    }

    public function test_el_listado_no_promete_rachas(): void
    {
        // Dejar de grabar un martes no es un fracaso, y un sistema que lo trate
        // como tal cambia lo que la gente graba. Ver 06-diseno-y-tono.md §3.
        $a = $this->crearUsuario('a');
        $this->conAudio($a);

        $html = mb_strtolower($this->getComo($this->iniciarSesion($a), '/grabaciones')->body);

        foreach (['racha', 'seguidos', 'no falles', 'enhorabuena', 'objetivo'] as $palabra) {
            $this->assertStringNotContainsString($palabra, $html);
        }
    }
}
