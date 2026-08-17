<?php

declare(strict_types=1);

namespace MaiMind\Tests\Http;

use InvalidArgumentException;
use MaiMind\Domain\Auth\SessionManager;
use MaiMind\Repository\EntryRepository;
use MaiMind\Tests\AppTestCase;

/**
 * Criterio de éxito de la tarea 0.6:
 * el usuario A no puede leer ni un registro del usuario B por ninguna ruta.
 */
final class AislamientoTest extends AppTestCase
{
    public function test_A_no_ve_los_registros_de_B_en_el_listado(): void
    {
        $a = $this->crearUsuario('a');
        $b = $this->crearUsuario('b');

        $uidA = $this->crearEntrada($a);
        $uidB = $this->crearEntrada($b);

        $respuesta = $this->getComo($this->iniciarSesion($a), '/api/entries');

        $this->assertSame(200, $respuesta->status);

        $uids = array_column(json_decode($respuesta->body, true)['entries'], 'uid');

        $this->assertContains($uidA, $uids);
        $this->assertNotContains($uidB, $uids);
    }

    public function test_A_recibe_404_al_pedir_un_registro_de_B_por_su_uid(): void
    {
        $a = $this->crearUsuario('a');
        $b = $this->crearUsuario('b');

        $uidB = $this->crearEntrada($b);

        $respuesta = $this->getComo($this->iniciarSesion($a), '/api/entries/' . $uidB);

        // 404 y no 403: un 403 confirmaría que ese identificador existe.
        $this->assertSame(404, $respuesta->status);
        $this->assertStringNotContainsString($uidB, $respuesta->body);
    }

    public function test_cada_usuario_ve_lo_suyo_y_solo_lo_suyo(): void
    {
        $a = $this->crearUsuario('a');
        $b = $this->crearUsuario('b');

        $uidA = $this->crearEntrada($a);
        $uidB = $this->crearEntrada($b);

        $comoA = json_decode($this->getComo($this->iniciarSesion($a), '/api/entries')->body, true);
        $comoB = json_decode($this->getComo($this->iniciarSesion($b), '/api/entries')->body, true);

        $this->assertSame([$uidA], array_column($comoA['entries'], 'uid'));
        $this->assertSame([$uidB], array_column($comoB['entries'], 'uid'));
    }

    public function test_sin_sesion_las_rutas_privadas_no_devuelven_datos(): void
    {
        $b = $this->crearUsuario('b');
        $uidB = $this->crearEntrada($b);

        $api = $this->get('/api/entries');
        $this->assertSame(401, $api->status);
        $this->assertStringNotContainsString($uidB, $api->body);

        $detalle = $this->get('/api/entries/' . $uidB);
        $this->assertSame(401, $detalle->status);

        $inicio = $this->get('/');
        $this->assertSame(302, $inicio->status);
        $this->assertSame('/acceder', $inicio->header('Location'));
    }

    public function test_una_sesion_falsificada_no_sirve(): void
    {
        $this->crearUsuario('b');

        foreach ([
            str_repeat('a', 64),          // formato válido, no existe
            'demasiado-corto',
            str_repeat('z', 64),          // no hexadecimal
            '',
        ] as $falso) {
            $respuesta = $this->get('/api/entries', [SessionManager::COOKIE => $falso]);

            $this->assertSame(401, $respuesta->status, "Testigo falso aceptado: {$falso}");
        }
    }

    public function test_el_testigo_de_sesion_no_se_guarda_en_claro(): void
    {
        $a = $this->crearUsuario('a');
        $token = $this->iniciarSesion($a);

        $guardados = $this->pdo->query('SELECT id FROM sessions')->fetchAll(\PDO::FETCH_COLUMN);

        // Quien consiga una copia de la tabla no puede suplantar a nadie.
        $this->assertNotContains($token, $guardados);
        $this->assertContains(hash('sha256', $token), $guardados);
    }

    public function test_cerrar_sesion_invalida_el_testigo(): void
    {
        $a     = $this->crearUsuario('a');
        $token = $this->iniciarSesion($a);

        $this->assertSame(200, $this->getComo($token, '/api/entries')->status);

        $salida = $this->post('/salir', ['_csrf' => $this->csrfDeSesion($token)], [
            SessionManager::COOKIE => $token,
        ]);

        $this->assertSame(302, $salida->status);
        $this->assertSame(401, $this->getComo($token, '/api/entries')->status);
    }

    public function test_una_sesion_caducada_no_sirve(): void
    {
        $a     = $this->crearUsuario('a');
        $token = $this->iniciarSesion($a);

        $this->pdo->prepare('UPDATE sessions SET expires_at = DATE_SUB(NOW(3), INTERVAL 1 DAY) WHERE id = ?')
            ->execute([hash('sha256', $token)]);

        $this->assertSame(401, $this->getComo($token, '/api/entries')->status);
    }

    public function test_suspender_la_cuenta_corta_las_sesiones_abiertas(): void
    {
        $a     = $this->crearUsuario('a');
        $token = $this->iniciarSesion($a);

        $this->assertSame(200, $this->getComo($token, '/api/entries')->status);

        $this->pdo->prepare("UPDATE users SET status = 'suspended' WHERE id = ?")->execute([$a->id]);

        $this->assertSame(401, $this->getComo($token, '/api/entries')->status);

        // Y además se borra la sesión, no solo se rechaza.
        $quedan = (int) $this->pdo->query(
            'SELECT COUNT(*) FROM sessions WHERE user_id = ' . $a->id
        )->fetchColumn();

        $this->assertSame(0, $quedan);
    }

    public function test_el_repositorio_no_existe_sin_usuario(): void
    {
        // La garantía de fondo: no hay forma de construir un repositorio de datos
        // de usuario sin decir de quién son.
        foreach ([0, -1] as $invalido) {
            try {
                new EntryRepository($this->pdo, $invalido);
                $this->fail("Se aceptó user_id = {$invalido}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_el_repositorio_ignora_un_user_id_pasado_a_mano(): void
    {
        $a = $this->crearUsuario('a');
        $b = $this->crearUsuario('b');

        $repoA = new EntryRepository($this->pdo, $a->id);

        // Intentar escribir en el espacio de B: el repositorio impone el suyo.
        $uid = $repoA->createDraft(
            capturedAt: '2026-08-16 12:00:00',
            localDate: '2026-08-16',
            timezone: 'Europe/Madrid',
            utcOffsetMinutes: 120,
            extra: ['user_id' => $b->id],
        );

        $repoB = new EntryRepository($this->pdo, $b->id);

        $this->assertNotNull($repoA->findByUid($uid));
        $this->assertNull($repoB->findByUid($uid), 'La fila acabó en el espacio equivocado');
    }

    public function test_las_rutas_privadas_no_filtran_datos_en_el_html(): void
    {
        $a = $this->crearUsuario('a');
        $b = $this->crearUsuario('b');

        $uidB = $this->crearEntrada($b);

        $html = $this->getComo($this->iniciarSesion($a), '/');

        $this->assertSame(200, $html->status);
        $this->assertStringNotContainsString($uidB, $html->body);
        $this->assertStringNotContainsString($b->email, $html->body);
    }
}
