# MaiMind — Scratchpad de coordinación

Estado: **Planner** · Última actualización: 2026-08-16

---

## Background and Motivation

Plataforma de autoobservación y análisis longitudinal. El usuario abre la app, pulsa grabar,
habla libremente, y la IA convierte ese relato en información estructurada que se acumula
durante meses o años para permitir después visualizar evolución, comparar períodos y
descubrir patrones.

No es un diario. El objetivo es un sistema de observación estructurada capaz de sostener
análisis longitudinal real, agnóstico respecto a las hipótesis del usuario, que separe
hechos de interpretaciones y de inferencias de la IA, que conserve el histórico de revisiones
y que nunca presente una correlación como una causa.

**Decisiones de arranque acordadas con el usuario (2026-08-16):**

| Decisión | Elección |
|---|---|
| Enfoque | Diseño del núcleo + prototipo funcionando (no documento exhaustivo primero) |
| Alcance | Producto multiusuario desde el principio |
| Stack | PHP 8.3 vanilla + Composer (sin framework) + MariaDB 11.4 |
| Hosting | VPS existente (`mail.claara.tech`), subdominio de `iaiapro.com` |
| Transcripción | OpenRouter, endpoint `/audio/transcriptions`, Whisper large-v3-turbo |
| Extracción | OpenRouter, `/chat/completions` |
| Retención de audio | 30 días, luego purga |
| Deterioro sostenido | Solo hechos, nunca interpretación |
| Idioma | Español. Slugs y enums en inglés (son identificadores). Andamiaje i18n mínimo desde 0.1 |
| Interfaz | **Mobile-first sin excepciones**, análisis incluido. PWA instalable |

**Documentos de diseño producidos:**

- `docs/design/00-critica-y-decisiones.md` — crítica del planteamiento, riesgos, decisiones pendientes
- `docs/design/01-modelo-nucleo.md` — modelo conceptual, temporal, evidencia, versionado, relaciones
- `docs/design/02-esquema-mysql.md` — esquema relacional completo
- `docs/design/03-extraccion.md` — contrato JSON y reglas de extracción
- `docs/design/04-arquitectura.md` — arquitectura, despliegue, privacidad, analítica
- `docs/design/05-catalogo-core.md` — las 40 variables core y por qué esas
- `docs/design/06-diseno-y-tono.md` — paleta, iconos, tono, honestidad visual
- `docs/api/openrouter.md` — API de OpenRouter verificada (transcripción + extracción)

---

## Key Challenges and Analysis

Los siete problemas que determinan si esto funciona o produce basura convincente.
Análisis completo en `docs/design/00-critica-y-decisiones.md`.

1. **Falsos descubrimientos.** 200 variables = ~20.000 pares = ~1.000 "hallazgos" por puro
   azar. Mitigación: core reducido, mínimos de datos, tamaño de efecto en vez de p-valores,
   e hipótesis pre-registradas validadas contra datos posteriores a su formulación.

2. **Circularidad.** Correlacionar dos frases de la misma grabación no descubre nada: mide
   lo que el usuario ya cree. Mitigación: `links.user_declared` y `links.same_entry`; un
   hallazgo solo cuenta si se sostiene con ambos a 0.

3. **Datos ausentes no aleatorios.** La gente deja de grabar justo cuando está peor. Sin
   modelar el silencio, todas las medias están sesgadas. Mitigación: `day_coverage` como
   entidad de primer nivel, huecos visibles en las gráficas, densidad de registro analizable.

4. **Precisión inventada.** "Estoy fatal" → `ánimo = 2.0` es un número del modelo, no del
   usuario, y a los tres meses es una serie temporal con tendencia. Mitigación: los valores
   inferidos van en bandas ordinales; el continuo se reserva a lo que el usuario dice o toca;
   `mood_hint` (1 toque, 1–5) como única señal cuantitativa sin LLM de por medio, que además
   sirve para auditar al extractor.

5. **Corrección ≠ reinterpretación.** *"Fueron las 15:30, no las 17:00"* es un error factual.
   *"Ahora creo que no estaba tan mal"* no lo es: ambas versiones son verdad. Tratarlas igual
   destruye el dato más interesante del sistema. Mitigación: mecanismo de lentes
   (`as_experienced` / `as_understood`), y la brecha entre ambas como variable derivada.

6. **El bucle de revisión es el producto.** Grabar y no revisar nunca llena la base de datos
   de datos plausibles y falsos. Mitigación: pantalla de confirmación en dos toques, con la
   cita literal que originó cada dato.

7. **Modelado sin caer en los dos extremos.** Ni tabla de 200 columnas ni EAV puro ni diez
   tablas gemelas. Solución: `observations` (cualitativo, discriminado por `kind`) +
   `measurements` (cuantitativo) + catálogos.

**Decisiones pendientes que necesitan al usuario** (detalle en doc 00, §D):
proveedor de transcripción · retención de audio · política ante señales de crisis ·
framework PHP (vanilla vs Laravel) · idioma de slugs internos · alcance RGPD.

---

## High-level Task Breakdown

Cada tarea tiene criterio de éxito verificable. El Executor hace **una** y espera validación.

### FASE 0 — Cimientos

**0.1 Esqueleto del proyecto**
Estructura de directorios, `composer.json` con PSR-4, config por entorno (`.env`), autoload,
logger, `.gitignore`, `git init`. Incluye el andamiaje i18n mínimo: `resources/lang/es.php`,
helper `t()`, y resolución de locale (`users.locale` → `Accept-Language` → `es`).
✅ `composer install` funciona, un script de prueba autocarga una clase de `src/`, y
`t('app.name')` devuelve el texto en español.

**0.2 Entorno local en Docker**
`docker-compose.yml` con MariaDB 11.4 y PHP 8.3, mismas versiones que producción. Conexión
PDO en UTC, `utf8mb4`, modo estricto.
✅ Un script conecta, hace `SELECT VERSION()` y devuelve 11.4.x; `php -v` devuelve 8.3.x.

**0.3 Runner de migraciones**
`bin/migrate` que aplica ficheros SQL en orden y registra en `schema_migrations`. Idempotente.
✅ Ejecutar dos veces seguidas no rompe ni reaplica nada.

**0.4 Migración inicial del esquema**
Todo el DDL del doc 02.
✅ Migración limpia sin errores; `SHOW TABLES` lista las 26 tablas; las FK existen.

**0.5 Seed del catálogo core de variables**
30–40 variables core (`user_id=0`) con categoría, tipo, escala, polaridad y `extraction_hint`.
Slugs en inglés como identificadores; **etiquetas y definiciones en español como idioma de
primera clase** (no traducidas del inglés — ver `04-arquitectura.md` §4.bis). Alias en español
con `lang='es'`.
✅ `SELECT COUNT(*) FROM variables WHERE user_id=0 AND is_core=1` ≥ 30, todas con hint, y
`name_i18n->>'$.es'` poblado en todas.

**0.6 Auth y aislamiento multiusuario**
Registro, login, sesiones, hash Argon2id, middleware. Repositorio base que exige `user_id`.
✅ Test: el usuario A no puede leer ni un registro del usuario B por ninguna ruta.

### FASE 1 — Captura (desplegar en cuanto exista)

**1.1 Pantalla de grabación**
PWA mínima: fecha, `mood_hint` opcional (1–5), botón grabar, MediaRecorder, subida.
✅ En móvil se graba 30 s, se sube y aparece una fila en `entries`.

**1.2 API de captura y almacenamiento**
Validación de tipo/tamaño, guardado fuera de `public/`, sha256, `entries` con hora local y
offset UTC del cliente, respuesta 202.
✅ El audio se reproduce desde disco; `local_date` es correcto grabando a las 00:30.

**1.3 Cola de trabajos y worker**
Tabla `jobs`, reclamo con `FOR UPDATE SKIP LOCKED`, worker PHP, unidad systemd, reintentos
con backoff.
✅ Dos workers en paralelo no procesan nunca el mismo job (test de concurrencia).

**1.4 Modo offline del cliente**
Cola en IndexedDB, reintento al recuperar red. `navigator.storage.persist()` para reducir el
riesgo de que iOS limpie los datos del sitio y se pierdan grabaciones en cola.
✅ Grabar en avión y recuperar conexión → la entrada llega íntegra.

**1.5 PWA instalable**
Manifest, iconos, service worker, y onboarding de instalación para iOS (allí no hay prompt:
hay que explicarlo). Sin notificaciones — ver `06-diseno-y-tono.md` §6.
✅ Instalable desde Android y desde iOS; abre a pantalla completa desde el icono.

### FASE 2 — Transcripción
✅ API documentada en `docs/api/openrouter.md` (verificada 2026-08-16).

**2.1** Interfaz `TranscriptionProvider` + implementación fake para tests.
**2.2** Implementación OpenRouter + job `transcribe` + coste real desde `usage.cost`.
**2.3** Vista de la transcripción y edición manual del texto.

### FASE 3 — Extracción
⚠️ Antes de 3.2: reverificar el soporte de salida estructurada del modelo elegido y
**configurar la política de datos de OpenRouter** (solo proveedores que no entrenan).

**3.1** JSON Schema del contrato + validador PHP + fixtures de los 10 escenarios del prompt.
**3.2** Prompt `extract.v1` + `ExtractionProvider` + job.
**3.3** Reconciliador determinista (reglas del doc 03 §4) con tests unitarios.
**3.4** Etapa B (resolve) contra catálogo cerrado de entidades y variables.
**3.5** Etapa C (detección de revisiones) — solo propone, nunca aplica.

### FASE 4 — Revisión
**4.1** Pantalla "esto es lo que he entendido", máx. 6 tarjetas con cita literal.
**4.2** Confirmar / corregir / rechazar → `revisions` + `epistemic_status`.
**4.3** Flujo de revisión del pasado con la pregunta *"¿te equivocaste o ahora lo ves distinto?"*.
**4.4** Promoción de variables candidatas.

### FASE 5–11
Timeline y vista de día · rollups y gráficas · línea base · comparativas y antes/después ·
hipótesis pre-registradas · búsqueda semántica y modo investigación · compartición con
profesionales. Se detallarán al llegar: diseñarlas ahora sin datos reales es adivinar.

---

## Project Status Board

- [x] 0.1 Esqueleto del proyecto — *pendiente de verificación del usuario*
- [x] 0.2 Entorno de base de datos local — *pendiente de verificación del usuario*
- [x] 0.3 Runner de migraciones
- [x] 0.4 Migración inicial del esquema
- [x] 0.5 Seed del catálogo core de variables
- [x] 0.6 Auth y aislamiento multiusuario
- [x] 1.1 Pantalla de grabación
- [x] 1.2 API de captura y almacenamiento
- [x] 1.3 Cola de trabajos y worker
- [x] 1.4 Modo offline del cliente

---

## Current Status / Progress Tracking

**2026-08-16 — Planner.** Repo vacío. Escritos los cinco documentos de diseño del núcleo,
el plan de fases y `docs/api/openrouter.md`.

Reconocimiento del servidor de producción en solo lectura: **es MariaDB 11.4, no MySQL**, y
**PHP 8.3, no 8.4**. Documentos corregidos en consecuencia. El esquema no ha necesitado
cambios de fondo porque el JSON ya se usaba como blob opaco.

Resueltas D1, D2, D3, D4, D5, D7, D8, D10.

**2026-08-16 — Executor, tarea 0.1 terminada.** Esqueleto en pie:

- Estructura de directorios según `04-arquitectura.md` §3, `git init` (sin commit todavía).
- `composer.json` con PSR-4 (`MaiMind\` → `src/`), `platform.php = 8.3.30` para que Composer
  resuelva contra la versión de producción aunque en local haya 8.4.
- Soporte: `Env`, `Config` (notación de puntos), `Logger` (PSR-3, JSON por línea), `Lang`,
  `Ulid`, helpers `config()` / `t()` / `e()` / `base_path()`.
- Andamiaje i18n completo: `resources/lang/{es,en}.php`, resolución de locale
  (usuario → `Accept-Language` → `es`), y un test que **falla si las claves de es y en
  divergen**.
- `bin/check`: 22 comprobaciones de entorno. `php bin/check` → todo en verde.
- 14 tests, 71 aserciones, en verde.

Criterio de éxito de 0.1 cumplido: `composer install` funciona, el autoload carga clases de
`src/`, y `t('app.name')` devuelve el texto en español.

**2026-08-16 — Executor, tarea 0.2 terminada.**

No hay Docker en la máquina local, pero sí un MariaDB 12.2 de Homebrew corriendo. Se ha
creado la base `maimind` y un usuario dedicado, sin tocar las bases existentes del usuario
(`ppress_dev`, `umilpdfe_prisma`, `test`).

- `src/Support/Database.php`: fábrica PDO con tres invariantes verificados —
  sesión en UTC, modo estricto y consultas preparadas nativas. `time_zone`, `sql_mode` y
  `collation_connection` van en `MYSQL_ATTR_INIT_COMMAND` para que se reapliquen si el
  driver reconecta.
- `bin/check` ampliado con 11 comprobaciones de base de datos, incluida la comparación del
  reloj del servidor contra la hora UTC de PHP.
- `docker-compose.yml` + `Dockerfile` con MariaDB 11.4 y PHP 8.3 como opción de paridad
  exacta con producción (puerto 3307 para no chocar con el MariaDB local).
- 25 tests, 85 aserciones, en verde. `php bin/check` en verde con un aviso de versión.

**Dos fallos reales detectados por las comprobaciones** (ver Lessons).

Criterio de éxito de 0.2 cumplido con una desviación: el motor local es MariaDB **12.2**, no
11.4. El esquema no usa nada específico de versión y `bin/check` avisa de la divergencia;
`docker-compose.yml` da paridad exacta cuando haga falta.

**2026-08-16 — Executor, tarea 0.3 terminada.**

- `src/Support/SqlSplitter.php`: parte un fichero SQL en sentencias respetando cadenas,
  identificadores con comillas invertidas, escapes, comillas duplicadas y comentarios. Existe
  para poder decir *qué* sentencia falló en vez de "algo ha fallado en este fichero".
  No soporta `DELIMITER` (el esquema no usa procedimientos ni triggers).
- `src/Support/Migrator.php`: aplica pendientes en orden, registra checksum, nº de sentencias
  y tiempo. Detecta **drift** (migración aplicada cuyo fichero cambió) y **missing**
  (registrada sin fichero). Bloqueo con `GET_LOCK` contra despliegues simultáneos.
- `bin/migrate` con `status`, `--pretend` y `--create-database`.
- Sin rollback, deliberadamente: el DDL hace commit implícito en MySQL/MariaDB, así que una
  transacción daría falsa seguridad. Si falla, corta y no registra; la siguiente ejecución
  reintenta.

Verificado extremo a extremo con una migración desechable, ya eliminada: status → pretend →
migrate → migrate (idempotente) → drift → missing → fallo de sintaxis. Códigos de salida
correctos (1 en fallo, 0 en éxito). Base de datos limpia; solo queda `schema_migrations`,
vacía.

**47 tests, 122 aserciones, en verde.**

Criterio de éxito de 0.3 cumplido: ejecutar dos veces seguidas no rompe ni reaplica nada.

**2026-08-16 — Executor, tarea 0.4 terminada.**

`migrations/001_esquema_inicial.sql`, 25 sentencias, aplicada en 542 ms sobre una base
recién creada. Resultado verificado contra `information_schema`:

- **26 tablas** (25 del diseño + `schema_migrations`)
- **38 claves foráneas**
- **24 restricciones CHECK** (7 propias + 17 de validez de JSON que añade MariaDB)
- **98 índices**
- Todas las tablas en InnoDB y en `utf8mb4_unicode_ci`, sin excepciones

`tests/Schema/EsquemaInicialTest.php` fija los invariantes del diseño para que una
migración futura no pueda romperlos en silencio: collation portable en todas las tablas,
`DATETIME` siempre con milisegundos, `user_id` en toda tabla de datos, los catálogos
universales sin FK a `users`, ausencia de una relación `causes` a secas, presencia de
`user_declared` y `same_entry`, existencia de las dos lentes, e índices de la analítica
empezando por `user_id`. Los CHECK se comprueban intentando violarlos de verdad.

**62 tests, 166 aserciones, en verde.**

Tres fallos reales encontrados y corregidos durante la tarea (ver Lessons).

**2026-08-16 — Executor, tarea 0.5 terminada.**

**40 variables core, 10 dominios vitales, 310 alias en español.** Razonamiento completo en
`docs/design/05-catalogo-core.md`.

Decisión estructural que obligó a la migración `002_categoria_state.sql`: **ánimo, energía y
estrés no son emociones**. Son estados de fondo continuos que forman el esqueleto de las
series temporales; las emociones son episodios que se enganchan a acontecimientos. Categoría
`state` propia.

Otras decisiones de fondo:

- **Escala ordinal 1–5 con anclas verbales en todo el sistema**, y la misma que
  `entries.mood_hint`. Permite contrastar lo que extrae la IA con lo que el usuario toca con
  el dedo, es decir, auditar al extractor con datos del propio usuario.
- **Las siete conductas llevan polaridad `neutral`, sin excepción.** Si salir de casa o mirar
  el móvil le sienta bien o mal a esta persona es lo que hay que descubrir, no lo que hay que
  presuponer. La polaridad solo se asigna cuando es definicional.
- **Sin variables inversas.** El cansancio es energía baja y la calma es estrés bajo: van como
  alias, no como variables. Tenerlas aparte permitiría guardar filas contradictorias.
- **El tipo de valor sigue a cómo habla la gente.** Fragmentación del sueño y tiempo de
  pantallas son ordinales porque nadie los dice en números; las horas de sueño son numéricas
  porque sí se dicen.
- `agobio` e `ilusión` llevan la etiqueta inglesa marcada como aproximación, y un test
  comprueba que siga marcada.

`bin/seed` (idempotente, con `--pretend`) en vez de migración: el catálogo va a cambiar
cuando haya transcripciones reales delante. Conserva `occurrence_count`, `first_seen_at`,
`uid` y los alias que no sean del seed.

**85 tests, 887 aserciones, en verde.**

Criterio de éxito de 0.5 cumplido: 40 variables core con `is_core=1`, todas con
`extraction_hint` y con `name_i18n->>'$.es'` poblado.

**2026-08-17 — Executor, tarea 0.6 terminada. FASE 0 COMPLETA.**

Capa HTTP mínima escrita a mano: `Request`, `Response`, `Router`, `View`, `Kernel`. El
Kernel es el punto único por el que pasa todo y donde viven las tres garantías: ninguna ruta
privada se ejecuta sin usuario resuelto, ningún POST sin CSRF válido, y **los repositorios
solo se construyen a partir del usuario que la sesión ha resuelto**.

- `UserScopedRepository`: el objeto no existe sin `user_id` (revienta con 0 o negativo) y
  todos sus métodos inyectan el filtro. `insert()` impone el `user_id` propio, así que
  pasarle otro a mano no sirve de nada.
- Argon2id (64 MiB, 4 pasadas), verificado disponible en local y en producción. Si no lo
  estuviera, la aplicación no arranca en vez de caer a bcrypt en silencio.
- Sesiones en base de datos: el navegador recibe el testigo, la base guarda solo su SHA-256.
  Cookie HttpOnly + SameSite=Lax + Secure según el esquema de `APP_URL`.
- CSRF en dos modos: HMAC derivado de la sesión para rutas privadas, doble envío por cookie
  para acceso y registro, que aún no tienen sesión.
- Freno de fuerza bruta (`003_login_throttle.sql`): 5 intentos por correo y 20 por IP en 15
  minutos. Solo guarda hashes; esa tabla no debe ser un registro de quién intentó entrar.
- Enumeración de cuentas cerrada por los dos lados: mismo mensaje para correo desconocido y
  contraseña incorrecta, y `wasteTime()` iguala el coste temporal.
- Cabeceras de seguridad y CSP restrictiva en toda respuesta.
- `bin/key` para generar `APP_KEY`.

**Criterio de éxito cumplido y verificado por 13 tests de aislamiento**: A no ve los
registros de B en el listado, recibe 404 (no 403) al pedirlos por uid, no los ve en el HTML,
y sin sesión no hay datos por ninguna ruta. Más sesiones falsificadas, caducadas y de cuentas
suspendidas.

Verificado además de extremo a extremo con `php -S` y curl: redirección sin sesión, 401 en la
API, registro con CSRF, cookie HttpOnly emitida, acceso posterior correcto y cabeceras de
seguridad presentes.

**113 tests, 988 aserciones, en verde.**

---

## Executor's Feedback or Assistance Requests

*(vacío — aún no se ha ejecutado ninguna tarea)*

**Fase 0 completa.** Lo siguiente es 1.1 (pantalla de grabación).

**2026-08-17 — Diseño fijado** (fuera del plan de fases, a petición del usuario).

Paleta cerrada sobre *Almond Hearth / Velvet Curfew / Obsidian Ink*, con el fondo aclarado a
`#FAF3EA` y el almendra original degradado de fondo a color de tarjeta. Tema oscuro completo;
el acento se levanta a `#B06B77` porque el burdeos es invisible sobre obsidiana.

Se descartó una segunda paleta (Sangria + Jade) por un motivo estructural, no estético: rojo
y verde son el eje bien/mal, y la app no juzga. Además la paleta *invitaba* al error — con
esos dos colores a mano, las gráficas acabarían coloreando valencia.

Iconos: **Phosphor peso Light** (MIT), 17 SVG en `resources/icons/`, insertados en línea con
`icon()`. Elegido sobre Lucide e Iconoir porque tiene seis pesos: como el color no puede dar
énfasis, el peso del trazo es la única palanca de jerarquía disponible.

`tests/Http/DisenoTest.php` hace cumplir las reglas que se erosionan solas: ni un emoji en
toda la interfaz, ningún color fuera de la paleta, sin CSS en línea, sin rachas ni
celebraciones, y el vocabulario de análisis sin lenguaje causal.

Verificado en navegador, claro y oscuro, a 375px.

**123 tests, 1077 aserciones, en verde.**

**2026-08-17 — Executor, tarea 1.1 terminada.**

Pantalla de captura funcionando de punta a punta: fecha en la zona del usuario, toque
opcional de 1–5, botón de grabar con cronómetro, y subida a `POST /api/entries`.

- `capture.js`: negocia el tipo con `MediaRecorder.isTypeSupported()` — nunca leyendo el
  user-agent. Prefiere `audio/webm;codecs=opus` y cae a `audio/mp4` en Safari anterior a la
  18.4. Suelta las pistas del micrófono al parar (si no, el indicador del sistema se queda
  encendido) y cierra la grabación si la pestaña pasa a segundo plano. **Sin un solo texto
  dentro**: los lee de atributos `data-msg-*` para que sigan viviendo en los ficheros de idioma.
- `CaptureClock`: resuelve los dos relojes y calcula el **día local**. El reloj del cliente se
  acepta dentro de ±48 h y fuera de ahí se usa el del servidor, dejando aviso.
- `AudioStore`: `audio/{uid usuario}/{año}/{mes}/{uid entrada}.{ext}`. El uid y no el id
  numérico, para que un listado del directorio no revele cuántas cuentas hay.
- `Format`: fechas con `IntlDateFormatter` en la zona e idioma del usuario.

**150 tests, 1158 aserciones, en verde.** Verificado además con curl subiendo un webm/opus
real de 30 s generado con ffmpeg, y en navegador en claro y oscuro a 375px.

Cuatro fallos reales encontrados y corregidos (ver Lessons). Los dos primeros solo aparecieron
en la verificación extremo a extremo, no en los tests.

Pendiente para 1.2: purga real del audio a los 30 días, y encolar el trabajo de
transcripción (necesita 1.3).

**2026-08-30 — Executor, tareas 1.3 y 1.2 terminadas.**

Se hicieron en este orden a propósito: al leer el código, 1.2 ya estaba escrita
casi entera dentro de 1.1 (validación, guardado fuera de `public/`, sha256, hora
local y offset), y lo que le faltaba —purga real y encolado— **dependía de 1.3**,
no al revés.

**La cola.** `JobQueue` sobre la tabla `jobs`, sin Redis ni servicio aparte: la
máquina comparte dos núcleos y la cola va a ver decenas de trabajos al día, no
miles por segundo. Decisiones que no son de estilo:

- **El intento se suma al reclamar, no al fallar.** Si un trabajo tumba al worker
  (OOM, `kill -9`), no hay nadie que registre el fallo y ese trabajo volvería a
  tumbarlo para siempre. Sumando al reclamar, un trabajo venenoso se muere solo.
- **`dedupe_key` con unicidad en el motor** (migración `004`), no comprobación en
  PHP: dos procesos ganan una comprobación a la vez, una UNIQUE KEY no. Se apoya
  a propósito en que NULL no colisiona —la misma regla que obligó a `user_id = 0`
  en los catálogos, aquí a favor— y la clave se pone a NULL al terminar, para que
  el mismo trabajo se pueda encolar mañana.
- **Un tipo sin manejador se aplaza, no se mata.** `transcribe` ya se encola hoy
  aunque la fase 2 no exista: el trabajo es correcto, lo que falta es el código
  que lo atiende. Se aparta una hora sin gastar intentos y **se ejecutará solo
  cuando la fase 2 llegue al servidor**, sobre el audio que todavía esté en plazo.
  `bin/jobs` los saca a la vista para que un tipo mal escrito no espere eternamente.
- Sin rollback de trabajos a medias: cada manejador debe ser idempotente, y está
  escrito en la interfaz.

**La purga.** `PurgeAudioHandler`, **un trabajo por usuario y no uno global**. Con
un solo SELECT sobre toda la tabla sería más corto, pero entonces existiría en el
sistema un sitio que lee entradas sin filtrar por usuario, que es justo la grieta
que la regla de aislamiento existe para no tener. `bin/cron` encola; el worker
purga. Una sola implementación, con reintentos y registro.

**La captura ahora responde 202 y no 201**, que es lo que dice el plan: la
grabación está guardada, pero el trabajo sobre ella acaba de empezar. Encolar
puede fallar sin que la grabación se pierda — devolver 500 haría que el cliente
reintentase la subida y duplicase la entrada.

Piezas nuevas: `bin/worker` (parada ordenada con SIGTERM), `bin/cron`, `bin/jobs`,
`deploy/maimind-worker.service`, y cinco comprobaciones más en `bin/check`
(incluida la de que el motor admite `SKIP LOCKED`). `bin/deploy` reinicia el
worker.

**186 tests, 1270 aserciones, en verde.**

Criterio de éxito de 1.3 cumplido y **verificado por partida doble**: el test de
concurrencia retiene el bloqueo de una fila desde una conexión y comprueba que
la otra coge la siguiente en vez de esperarla. Para que no fuera un test que pasa
por casualidad, se quitó `SKIP LOCKED` a mano y se comprobó que entonces **falla
de verdad**: 50 s bloqueado y `SQLSTATE[HY000] 1205 Lock wait timeout exceeded`.

Criterio de 1.2 cumplido y verificado de extremo a extremo con los ejecutables
reales: `bin/cron` encoló, encolar dos veces no duplicó, `bin/worker --once`
borró **el fichero vencido y solo ese** (el que estaba en plazo siguió en disco),
y la fila quedó en `purged` con `audio_path` a NULL.

Un fallo real encontrado y corregido (ver Lessons).

**2026-08-30 — Executor, tarea 1.4 terminada.**

Cola local en IndexedDB, en `public/assets/offline.js`, y la orquestación en
`capture.js`. IndexedDB y no localStorage porque hay que guardar un Blob de
varios megas. `capture.js` pasa a ser un módulo ES y lo importa.

La regla que manda en todo el fichero: **nunca perder audio en silencio**. Si no
se puede subir, se guarda y se dice; si tampoco se puede guardar —modo privado
de iOS, cuota agotada— se dice también, con su propio mensaje, en vez de dejar
creer que está a salvo.

**La decisión de fondo fue la idempotencia** (migración `005`). Una cola que
reintenta sin un testigo estable produce duplicados en cuanto una respuesta se
pierde por el camino: el servidor guardó la entrada, el móvil no se enteró, y al
recuperar la red la vuelve a subir. Nadie lo notaría —son dos filas plausibles—
y a los seis meses esa grabación contaría dos veces en todas las medias. En una
base longitudinal, un duplicado silencioso es peor que un error ruidoso. El
cliente genera un testigo al terminar de grabar y lo conserva entre reintentos;
`entries.client_token` es único **por usuario**, no en toda la tabla, porque
viene de fuera y un testigo adivinado no puede tapar la grabación de nadie.

Los fallos se clasifican, que es lo que distingue una cola útil de una que
machaca: sin red, 5xx y 429 se reintentan; **401 y 419 también** —la sesión
caducó, la grabación es buena, solo hay que volver a entrar—; y 413, 415 y 422
no, porque reintentarlos no los va a arreglar. Una rechazada **no se borra**:
se marca, se queda en la cola y se enseña el motivo. Borrar audio de alguien sin
decírselo es lo único que esta cola no puede hacer.

Se reintenta en los tres momentos en que tiene sentido: al recuperar la red, al
volver a la aplicación y cuando la persona lo pide. `navigator.storage.persist()`
se pide justo cuando hay algo que perder, no al arrancar.

**195 tests, 1324 aserciones, en verde.**

Criterio de éxito cumplido y verificado en un navegador real a 390 px, claro y
oscuro, contra la aplicación corriendo: una grabación metida en la cola se sube
sola al recargar, con su `mood_hint` y con **la hora en que se grabó**, no la del
envío. Volver a encolar el mismo testigo dejó **una sola entrada** en el
servidor. Y una que el servidor rechaza se quedó en la cola, marcada, con su
motivo en pantalla.

⚠️ **Límite conocido de 1.4**: la cola cubre perder la conexión con la
aplicación ya abierta. Abrirla en frío sin red todavía no funciona —no hay nada
cacheado— y eso lo arregla el service worker de la 1.5.

Dos fallos reales encontrados por los tests nuevos y uno de aislamiento de la
propia suite (ver Lessons).

Pendientes por fase:
- D9 alta en Hestia (subdominio, BD, usuario, systemd, cron) → antes del primer despliegue
- D10 política de datos de OpenRouter → **antes de enviar la primera transcripción real**
- D6 RGPD → antes de tener usuarios reales

⚠️ **Seguridad, ajeno a este proyecto.** El usuario `codex` tiene `NOPASSWD: ALL` (root sin
contraseña) pese a estar descrito como "sudo limitado", en una máquina que sirve correo e
iaiaPRO en producción. Comunicado al usuario el 2026-08-16.

---

## Despliegue

**https://maimind.iaiapro.com** — montado el 2026-08-30, funcionando en el servidor.
Detalle y trampas en `docs/despliegue.md`.

- Dominio Hestia, base `dvdgp_maimind`, docroot en `public_html/public`, `storage/` fuera.
- Migraciones y catálogo aplicados en producción. `bin/check` en verde.
- `bin/deploy` deja el despliegue en un comando.

**En línea desde el 2026-08-30.** El usuario creó el registro `A` en LucusHost
(`maimind.iaiapro.com` → `91.98.155.109`); certificado Let's Encrypt emitido (caduca el
2026-11-28, renovación automática de Hestia) y `SSL_FORCE` activo.

Verificado desde fuera: HTTP redirige a HTTPS, registro y sesión funcionan, la cookie sale
`Secure` y `HttpOnly`, y ninguna ruta sensible responde. El arreglo de `open_basedir`
sobrevivió a las operaciones de Hestia (emisión de certificado y forzado de SSL).

Worker y cron escritos y documentados (`deploy/maimind-worker.service`, `bin/cron`).
**Pendiente de instalar en el servidor**: copiar la unidad, `systemctl enable --now`
y añadir la entrada de cron. Instrucciones en `docs/despliegue.md`.

## Convenciones acordadas

- **El usuario no verifica a mano.** Ejecutar las pruebas y los scripts de comprobación,
  presentar la salida real como evidencia y continuar. (Acordado 2026-08-16; anula el paso
  de verificación manual del CLAUDE.md global.) Se sigue preguntando por decisiones de
  diseño y por acciones difíciles de revertir.

## Lessons

- **Producción ≠ lo que se asume.** Se creía MySQL: es MariaDB 11.4.10. Se creía PHP 8.4: es
  8.3.30. Comprobar el terreno antes de fijar versiones en un documento de diseño.
- El servidor es compartido: 2 vCPU / 3,7 GB con iaiaPRO, correo y otros 3 usuarios de Hestia.
  Eso descarta cualquier procesado pesado en local (Whisper autoalojado incluido).
- Usar `JSON` solo como **almacenamiento opaco** —nunca indexar dentro— hizo el esquema
  portable entre MySQL y MariaDB sin tocar una línea. La higiene de modelado salió gratis.
- **El atributo `hidden` deja de funcionar** en cuanto una regla CSS fija `display`
  (`flex`, `grid`, `inline-flex`…): gana en especificidad al `display: none` del navegador y
  el elemento se queda visible sin que nadie se entere. Hace falta
  `[hidden] { display: none !important; }`.
- **`sprintf` no avisa si sobran argumentos.** Un `%s/%s/%s.%s` con cinco valores se comió la
  extensión del fichero y produjo rutas como `2026/08.01M06…`. Silencioso.
- **Generar un identificador en dos sitios acaba en dos identificadores distintos.** El
  fichero de audio se nombraba con un ULID y la fila usaba otro. El uid se genera una vez y
  se pasa.
- **El servidor embebido de PHP enruta TODO por el script de router**, estáticos incluidos.
  Hay que devolver `false` desde `public/index.php` para que sirva los ficheros tal cual.
  En producción lo hace nginx, así que el fallo solo aparece en desarrollo.
- En MySQL y MariaDB, `NULL` en una columna de una `UNIQUE KEY` permite duplicados. Por eso
  los catálogos usan `user_id = 0` para las filas universales, no `NULL`.
- OpenRouter tiene endpoint de transcripción propio desde el 22/07/2026
  (`POST /api/v1/audio/transcriptions`), con Whisper real. No hace falta un proveedor ASR
  aparte, y no hay que usar LLM multimodal para transcribir: parafrasea y rompe el anclaje
  de evidencia.
- `input_audio.data` de OpenRouter es base64 **crudo**, no un `data:` URI.
- Entorno local: PHP 8.4.11, MariaDB client 12.2, Composer 2.8.11, Node 22.15. Docker debe
  fijar PHP 8.3 y MariaDB 11.4 para igualar producción.
- **MariaDB 11.4+ cambió la collation por defecto de utf8mb4** a `utf8mb4_uca1400_ai_ci`,
  que **no existe en MySQL**. `charset=utf8mb4` en el DSN fija el juego de caracteres pero
  no la collation, así que el servidor imponía la suya. Se fija a mano
  `utf8mb4_unicode_ci` en el `INIT_COMMAND`. Sin esto, el orden y la comparación de cadenas
  cambiarían según el motor, en silencio. **Afecta también a producción**, que corre 11.4.
- **`SELECT ? AS n` con `execute([42])` NO comprueba si los enteros vuelven como enteros.**
  PDO envía el parámetro como cadena y el servidor devuelve una cadena, con razón. Hace
  falta una columna realmente entera. El primer test estaba mal, no el código.
- Se pueden combinar `time_zone`, `sql_mode` y `collation_connection` en un solo `SET`, que
  es lo que permite meterlos todos en `MYSQL_ATTR_INIT_COMMAND` (solo admite una sentencia)
  y que se reapliquen al reconectar.
- **Un test que reclama de una cola compartida tiene que acotar lo suyo.** Los
  tests de la cola hacían `claim()` sin filtro y se llevaban trabajos reales que
  había dejado una verificación manual en la base de desarrollo. Fallaban por
  algo que no era el código. Ahora reclaman siempre por tipo, y el `Worker`
  acepta una lista de tipos —que además hacía falta de verdad, para poder
  levantar un segundo worker dedicado a lo rápido cuando una transcripción larga
  tenga a la purga esperando detrás. Se comprobó encolando un intruso a
  propósito y volviendo a pasar la suite.
- **Un atributo `data-msg-*` que ya nadie lee arrastra una clave de idioma
  muerta en cada fichero de idioma.** El test que empareja lo que pide el JS con
  lo que pone la vista se escribió en un solo sentido y encontró un huérfano;
  escrito en los dos, encontró otro que era al revés —una clave añadida y nunca
  usada—. Los dos sentidos, o el fichero de idiomas se pudre solo.
- **Comprobar `function_exists` de una función y llamar a otra no es comprobar
  nada.** `bin/worker` miraba `pcntl_async_signals` y luego llamaba a
  `pcntl_signal`: en producción la primera existe y la segunda está en
  `disable_functions`, así que el worker moría en bucle de reinicio con
  *Call to undefined function*. En local no aparecía porque ahí ninguna está
  deshabilitada. Se reprodujo con `php -d disable_functions=pcntl_signal`. La
  guarda comprueba ahora las cuatro piezas que se usan —las dos funciones y las
  constantes SIGTERM/SIGINT, que también vienen de la extensión— y `bin/check`
  lo avisa, que es donde debería haber salido antes que en un journal.
- **Las funciones flecha capturan por valor, siempre.** El worker paraba con
  `shouldStop: fn () => $parar` y el manejador de SIGTERM escribía en `$parar`:
  la señal llegaba, se registraba en el log, y el proceso seguía corriendo tan
  tranquilo, porque la flecha se había quedado con el `false` del arranque. `use
  (&$x)` lo arregla, pero lo que se hizo fue quitar la variable de en medio: el
  estado de parada vive ahora dentro del Worker (`stop()`), donde no hay captura
  que equivocar y además se puede testear. **Solo apareció en la verificación
  real**, no en los tests.
- **Un test de concurrencia hay que romperlo para creérselo.** El de `SKIP
  LOCKED` pasaba; quitando `SKIP LOCKED` del SQL, pasaba igual de rápido hasta
  que se le añadió la retención del bloqueo desde otra conexión. Solo entonces
  falló como debía (1205, *lock wait timeout*, tras 50 s). Un test de exclusión
  mutua que nunca se ha visto fallar no prueba nada.
- **El vocabulario emocional no se traduce, se diseña.** *Ilusión* no tiene equivalente limpio
  en inglés; *vergüenza* se reparte entre *shame* y *embarrassment*. Un catálogo core traducido
  produce un vocabulario sutilmente equivocado, y sobre ese catálogo se apoya todo el análisis.
  Cada idioma diseña el suyo.
