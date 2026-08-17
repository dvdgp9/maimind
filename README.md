# MaiMind

Plataforma de autoobservación y análisis longitudinal. El usuario graba un audio contando lo
que le pasa; el sistema lo convierte en información estructurada que se acumula durante meses
y años para permitir después ver evolución, comparar períodos y descubrir patrones.

No es un diario. El objetivo es un sistema de observación estructurada que separa hechos de
interpretaciones y de inferencias de la IA, conserva el histórico de revisiones, y **nunca
presenta una correlación como una causa**.

## Documentación

Léela antes de tocar código. El diseño está cerrado; lo que queda es ejecutarlo.

| Documento | Qué contiene |
|---|---|
| [00 — Crítica y decisiones](docs/design/00-critica-y-decisiones.md) | Riesgos del planteamiento y qué se hace en su lugar |
| [01 — Modelo núcleo](docs/design/01-modelo-nucleo.md) | Modelo conceptual, temporal, evidencia, versionado, relaciones |
| [02 — Esquema de BD](docs/design/02-esquema-mysql.md) | Esquema relacional completo |
| [03 — Extracción](docs/design/03-extraccion.md) | Contrato JSON y reglas de extracción |
| [04 — Arquitectura](docs/design/04-arquitectura.md) | Arquitectura, despliegue, privacidad, i18n, analítica |
| [05 — Catálogo core](docs/design/05-catalogo-core.md) | Las 40 variables y por qué esas |
| [06 — Diseño y tono](docs/design/06-diseno-y-tono.md) | Paleta, iconos, tono y reglas de honestidad visual |
| [API OpenRouter](docs/api/openrouter.md) | Transcripción y extracción |
| [Scratchpad](.cursor/scratchpad.md) | Estado del proyecto y plan por fases |

## Stack

PHP 8.3 vanilla + Composer · MariaDB 11.4 · sin framework · OpenRouter para transcripción
(Whisper) y extracción.

## Puesta en marcha

```bash
composer install
cp .env.example .env
```

Crear la base de datos (cualquier MariaDB reciente sirve en local):

```sql
CREATE DATABASE maimind CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'maimind'@'127.0.0.1' IDENTIFIED BY 'tu_password';
GRANT ALL PRIVILEGES ON maimind.* TO 'maimind'@'127.0.0.1';
```

Rellenar `DB_*` en `.env` y comprobar:

```bash
php bin/check
```

Verifica versiones, extensiones, autoload, i18n, ULID, permisos de `storage/`, el logger y
—lo importante— que la conexión trabaja **en UTC, en modo estricto y con
`utf8mb4_unicode_ci`**. Debe salir en verde antes de seguir.

Migraciones:

```bash
php bin/migrate                    # aplica las pendientes
php bin/migrate status             # qué hay aplicado y qué falta
php bin/migrate --pretend          # enseña qué haría, sin tocar nada
php bin/migrate --create-database  # crea la base de datos si no existe
```

Los ficheros van en `migrations/`, nombrados `NNN_descripcion.sql` y se aplican en orden.
**Nunca edites una migración ya aplicada**: `bin/migrate` lo detecta por checksum y avisa,
pero no la reaplica. Para cambiar algo, escribe una migración nueva.

**Si una migración falla a mitad**, las sentencias anteriores ya se aplicaron y la migración
no queda registrada — el DDL hace commit implícito y no hay rollback posible. En desarrollo,
lo más limpio es empezar de cero:

```bash
mariadb -e "DROP DATABASE maimind"
php bin/migrate --create-database
```

En producción con datos reales hay que deshacer a mano lo que sí se aplicó antes de
reintentar. Por eso conviene probar toda migración en local primero.

Clave de aplicación (firma los testigos CSRF):

```bash
php bin/key
```

Catálogo core (40 variables + dominios vitales). Idempotente, se puede reejecutar cada vez
que se afine el catálogo:

```bash
php bin/seed             # aplica
php bin/seed --pretend   # enseña qué cambiaría
```

```bash
./vendor/bin/phpunit             # tests
php -S localhost:8080 -t public  # servidor de desarrollo
```

### Paridad exacta con producción (opcional)

`docker-compose.yml` levanta MariaDB 11.4 y PHP 8.3, las versiones reales de producción.
Útil antes de un despliegue o para perseguir un bug que solo aparece allí:

```bash
docker compose up -d && docker compose exec app php bin/check
```

La base de datos queda en el **3307** para no chocar con un MariaDB local en el 3306.

## Reglas del proyecto

- **Todo en UTC.** `occurred_date` es el día *local* del usuario y se calcula aparte.
- **CSS solo en `public/assets/styles.css`.** Nada en línea.
- **Nunca un emoji.** Iconos Phosphor (peso Light) vía `icon('nombre')`. Un emoji propone
  una emoción, y esta app no propone emociones. Hay un test que lo comprueba.
- **No hay color de "malo" ni de "bueno"** en la paleta, y es deliberado. Ver doc 06.
- **Los slugs y enums no se traducen**: son identificadores. La UI sí, vía `t()`.
- **Nunca registrar en el log** transcripciones, audio ni contenido de observaciones.
  Identificadores sí; contenido no.
- **`storage/` fuera de la raíz web.** Contiene audio de usuarios.
- **Todo acceso a datos de usuario pasa por un repositorio que extiende
  `UserScopedRepository`.** Ese objeto no existe sin `user_id` y filtra por él en cada
  consulta. Escribir SQL suelto contra tablas de usuario salta el aislamiento.
- Los datos derivados (agregados, líneas base, hipótesis) deben poder borrarse y regenerarse
  enteros desde los datos estructurados. Si no se puede, es un bug de diseño.
