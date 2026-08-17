# 04 — Arquitectura lógica y despliegue

Objetivo: PHP 8.3 + MariaDB 11.4 sobre el VPS existente, multiusuario desde el día uno, y
**ningún proveedor de IA acoplado al núcleo**.

## 0. El terreno real (verificado 2026-08-16)

| | |
|---|---|
| Host | `mail.claara.tech` — 2 vCPU Xeon Skylake, 3,7 GB RAM, 22 GB libres |
| Sistema | Ubuntu 22.04.5 LTS + HestiaCP |
| Web | nginx 1.29.5 delante de Apache 2.4.66 (montaje típico de Hestia) |
| PHP | 8.3.30 con `pcntl`, `pdo_mysql`, `curl`, `sodium`, `apcu`, `intl`, `zip` |
| BD | MariaDB 11.4.10 |
| Composer | 2.2.6 (antiguo — actualizar o construir en local y desplegar) |
| Convive con | iaiaPRO, un servidor de correo y otros 3 usuarios de Hestia |
| Destino | Subdominio de `iaiapro.com` |

Tres consecuencias directas sobre el diseño:

1. **PHP 8.3, no 8.4.** Local y producción deben coincidir.
2. **Nada de Whisper autoalojado.** 2 vCPU compartidas con correo y otra aplicación. En CPU,
   faster-whisper va a RTF ~2,5: cinco minutos de cómputo por cada dos de audio, compitiendo
   con el servidor web. Sirve para lotes nocturnos, no para "grabo y en un minuto lo tengo".
3. **Worker con concurrencia 1** y `nice`. El trabajo es de espera a APIs, no de CPU, así que
   con uno sobra — pero no puede competir con el correo.

---

## 1. Capas

```
┌─────────────────────────────────────────────────────────────┐
│  CLIENTE  ·  PWA (HTML + JS vanilla + CSS en styles.css)    │
│  MediaRecorder → subida → estado del procesado → revisión   │
│  Funciona offline: cola local (IndexedDB) y reintento       │
└───────────────────────────┬─────────────────────────────────┘
                            │ HTTPS / JSON
┌───────────────────────────▼─────────────────────────────────┐
│  APP PHP (nginx + php-fpm)                                  │
│    Auth · Capture API · Read API · Review API · Analytics   │
│    Repositorios  →  TODA consulta lleva user_id inyectado   │
└───────┬──────────────────────────────────┬──────────────────┘
        │ encola                           │ lee/escribe
┌───────▼──────────┐              ┌────────▼─────────┐
│  jobs (MySQL)    │              │   MySQL 8.4      │
│  FOR UPDATE      │              │   niveles 1/2/3  │
│  SKIP LOCKED     │              └──────────────────┘
└───────┬──────────┘
        │
┌───────▼──────────────────────────────────────────────────────┐
│  WORKER (systemd, proceso PHP largo)                         │
│   transcribe → extract → resolve → reconcile → rollup → embed│
└───────┬──────────────────────────────────────────────────────┘
        │  interfaces, no proveedores
┌───────▼──────────┐  ┌──────────────────┐  ┌─────────────────┐
│ TranscriptionSvc │  │  LlmExtractorSvc │  │  EmbeddingSvc   │
│  ::transcribe()  │  │  ::extract()     │  │  ::embed()      │
└──────────────────┘  └──────────────────┘  └─────────────────┘
  OpenRouter            OpenRouter             OpenRouter
  /audio/transcriptions /chat/completions      (o local)
  Whisper large-v3-turbo
```

**Un solo proveedor, dos endpoints distintos, dos interfaces distintas.** Que hoy ambos
salgan por OpenRouter es una decisión de configuración, no de arquitectura: transcripción y
extracción se pueden separar en cualquier momento sin tocar el núcleo. Detalle de la API en
`docs/api/openrouter.md`.

Un matiz que no es negociable: la transcripción usa **ASR real (Whisper), no un LLM
multimodal**. Un LLM "limpia" el habla — quita muletillas, corrige frases a medias,
parafrasea. Para casi cualquier producto eso es una mejora; para este es destructivo, porque
todo el anclaje de evidencia guarda **citas literales con offsets** sobre el texto. Si el
transcriptor reescribe, esas citas apuntan a palabras que el usuario nunca dijo.

### Las tres interfaces que garantizan la sustituibilidad

```php
interface TranscriptionProvider {
    public function transcribe(AudioRef $audio, ?string $languageHint): TranscriptionResult;
    public function name(): string;
}

interface ExtractionProvider {
    public function run(ExtractionRequest $req): ExtractionResult; // JSON validado + coste
    public function promptVersion(): string;
}

interface EmbeddingProvider {
    public function embed(array $texts): array;  // vectores float32
    public function dimensions(): int;
}
```

El núcleo depende solo de estas tres firmas. Cambiar de proveedor = una clase nueva y una
línea de configuración. El proveedor concreto queda registrado en cada fila
(`transcripts.provider`, `extraction_runs.model`), así que siempre se sabe qué motor produjo
qué dato — necesario para comparar calidad entre proveedores con datos reales.

---

## 2. Flujo de una grabación

```
[cliente]  graba (mood_hint opcional) → sube audio
[app]      valida, guarda fichero, INSERT entries (pipeline_state='captured')
           encola job 'transcribe'
           responde 202 con el uid  ← el usuario ya puede cerrar la app
[worker]   transcribe    → INSERT transcripts          → state='transcribed'
           extract (A)   → INSERT extraction_runs      → state='extracting'
           resolve (B)   → emparejamiento con catálogo
           reconcile     → INSERT observations/measurements/links
                         → state='reconciled' | 'needs_review'
           rollup        → UPSERT daily_metrics, day_coverage
           embed         → INSERT embeddings
[cliente]  al volver: badge de "N cosas por revisar"
```

Cada paso es un job independiente, idempotente y reintentable. Si falla la extracción, la
transcripción no se pierde y se puede reintentar sola.

---

## 3. Estructura del proyecto

```
maimind/
├─ public/                 # único directorio expuesto por nginx
│   ├─ index.php
│   ├─ assets/styles.css   # todo el CSS aquí (regla del proyecto)
│   └─ assets/app.js
├─ src/
│   ├─ Http/               # rutas, controladores, middleware auth
│   ├─ Domain/             # Entry, Observation, Measurement, Variable, Revision…
│   ├─ Repository/         # acceso a datos; user_id obligatorio en el constructor
│   ├─ Pipeline/           # Transcriber, Extractor, Resolver, Reconciler
│   ├─ Providers/          # implementaciones concretas de las 3 interfaces
│   ├─ Analytics/          # rollups, baselines, hipótesis
│   └─ Support/            # ULID, tiempo, config, logging
├─ resources/
│   ├─ prompts/            # versionados: extract.v1.md, resolve.v1.md…
│   ├─ schemas/            # JSON Schema del contrato de extracción
│   └─ seeds/              # catálogo core de variables
├─ migrations/             # 001_init.sql, 002_…  aplicadas por bin/migrate
├─ storage/                # audio (fuera de public/), logs, tmp
├─ bin/                    # migrate, worker, cron
├─ tests/
└─ docs/design/
```

Regla de aislamiento: **los repositorios reciben `user_id` en el constructor** y lo aplican
en todas las consultas. No hay forma de escribir accidentalmente un `SELECT` sin filtrar,
porque el objeto no existe sin usuario.

---

## 4. Despliegue

| Componente | Elección |
|---|---|
| Dominio | Subdominio de `iaiapro.com`, dado de alta en Hestia |
| Web | nginx + Apache + php-fpm 8.3 (el montaje que ya tiene Hestia) |
| BD | MariaDB 11.4, base de datos y usuario propios de MaiMind |
| Worker | unidad systemd con `Restart=always`, concurrencia 1, `Nice=10` |
| Cron | rollups nocturnos, purga de audio a 30 días, evaluación de hipótesis |
| TLS | Let's Encrypt vía Hestia |
| Backups | `mariadb-dump` cifrado + `storage/` fuera de la máquina, diario, **con restauración probada** |
| Cifrado en reposo | audio cifrado a nivel de aplicación (el disco del host no está bajo nuestro control) |

**En local:** MariaDB 11.4 y PHP 8.3 en Docker, mismas versiones que producción.

**Lo que hace falta dar de alta en el servidor** (operaciones de Hestia, a acordar antes del
despliegue): subdominio + vhost, base de datos y usuario, directorio de `storage/` fuera de
la raíz web, unidad systemd del worker y entradas de cron.

⚠️ **Nota de seguridad.** El usuario `codex` tiene `NOPASSWD: ALL`, es decir root sin
contraseña, pese a estar descrito como "sudo limitado". Cualquier automatización que corra
con esa cuenta tiene control total de una máquina que además sirve correo y otra aplicación
en producción. Merece revisarse con independencia de este proyecto.

---

## 4.bis Multiidioma

El producto arranca **solo en español**, pero preparado para no tener que reescribirse.
Alcance deliberadamente pequeño — cinco piezas, nada más:

| Pieza | Implementación |
|---|---|
| Textos de UI | `resources/lang/{es,en}.php` + helper `t($clave, $params)` |
| Idioma activo | `users.locale` → `Accept-Language` → `es` por defecto |
| Fechas y números | `IntlDateFormatter` / `NumberFormatter` (la extensión `intl` está en el servidor) |
| Catálogos | `variables.name_i18n`, `definition_i18n`, `tags.name_i18n` (JSON opaco) |
| Alias | `variable_aliases.lang` — *"triste"* y *"sad"* apuntan a la misma variable |

Reglas:

- **Los slugs y los enums nunca se traducen.** `emotion.sadness`, `kind='event'` son
  identificadores. La etiqueta visible se traduce; el identificador no.
- **El prompt de extracción se escribe en inglés** (los modelos rinden mejor y el prompt es
  código), pero `label`, `summary` y `verbatim` que produce el LLM se quedan **en el idioma
  del usuario**: son contenido suyo, no interfaz.
- `language` de la transcripción sale de `users.locale`, nunca fijo.

### Lo que la traducción no arregla

El vocabulario emocional **no mapea 1:1 entre lenguas**. *Ilusión* no tiene equivalente
limpio en inglés; *vergüenza* se reparte entre *shame* y *embarrassment*, que son estados
distintos. Diseñar el catálogo core en inglés y traducirlo produciría un vocabulario emocional
sutilmente equivocado para hispanohablantes — y ese catálogo es la base de todo el análisis.

Por eso: **el core se diseña en español como idioma de primera clase**, con sus propias
definiciones, y los slugs en inglés como meros identificadores. Cuando llegue un idioma
nuevo, su catálogo se diseña de cero, no se traduce. La estructura lo soporta; lo que hay que
evitar es la tentación de traducir cuando llegue el momento.

**Fuera de alcance:** negociación de locales, motor de pluralización, RTL, backend de
traducciones. Si hace falta, se añade.

---

## 5. Privacidad y seguridad

Este sistema almacena, en texto plano estructurado, el material más sensible que una persona
puede producir sobre sí misma. El diseño lo asume:

1. **Minimización.** El audio se borra tras transcribir (por defecto, configurable). La
   transcripción es más sensible que el audio y también más útil: se conserva, pero es
   borrable por el usuario sin perder los datos estructurados derivados.
2. **Cifrado.** TLS en tránsito; disco cifrado; audio cifrado en aplicación. El cifrado por
   usuario de las transcripciones se evalúa después: impide la búsqueda semántica del lado
   servidor, y hay que decidir conscientemente qué se sacrifica.
3. **Proveedores externos como encargados del tratamiento.** Transcripción y extracción ven
   el contenido. Hay que documentarlo, elegir proveedores con acuerdo de no entrenamiento y
   decírselo al usuario sin letra pequeña.
4. **Borrado real.** Papelera de 30 días → purga física, incluidos derivados, embeddings y
   copias. Exportación completa en JSON antes de borrar.
5. **Auditoría.** Todo acceso a datos de otro usuario (profesionales) queda en `audit_log`.
   El usuario puede ver quién ha mirado qué.
6. **Seudonimización para compartir.** `entities.pseudonym` permite enseñar patrones sin
   revelar nombres.

---

## 6. Búsqueda semántica

Los embeddings **complementan** el modelo estructurado; no lo sustituyen. Sirven para
recuperar contexto ("¿cuándo me he sentido parecido a esto?") y para alimentar la etapa B
con candidatos relevantes; **nunca para calcular métricas**.

Implementación inicial: vectores float32 en BLOB, coseno por fuerza bruta filtrando por
`user_id`. Con 20k vectores por usuario son milisegundos en PHP. Detrás de `EmbeddingProvider`
+ un `VectorIndex`, para poder mover a pgvector o sqlite-vec sin tocar nada más. No merece
la pena montar infraestructura vectorial antes de tener el primer usuario con un año de datos.

---

## 7. Analítica: qué se puede construir sobre este modelo

Ordenado por lo que es honesto mostrar según cuántos datos hay.

| Nivel | Necesita | Qué ofrece |
|---|---|---|
| **Descriptivo** | días | Línea de tiempo, día a día, conteos, calendario de estado |
| **Series** | ~2–3 semanas | Evolución de variables core, con huecos visibles |
| **Línea base** | ~6 semanas | Desviación respecto a lo habitual, períodos atípicos |
| **Comparativo** | ~8 semanas | Con/sin ejercicio, laborable/finde, contextos |
| **Antes/después** | ~10 eventos del mismo tipo | Qué pasa alrededor de eventos recurrentes |
| **Correlacional** | ~90 días | Asociaciones — solo core, solo con tamaño de efecto y solo `user_declared=0 AND same_entry=0` |
| **Secuencial** | ~120 días | Secuencias frecuentes X→Y→Z |
| **Hipótesis** | pre-registro + datos posteriores | Evaluación out-of-sample |

Reglas transversales, aplicadas en el motor y no a criterio de quien consulte:

- Ningún análisis se muestra sin cumplir su mínimo de datos; en su lugar, "aún no hay
  suficientes datos" con cuántos faltan.
- Todo resultado lleva N, período, cobertura y proporción de datos inferidos vs confirmados.
- Se reporta tamaño de efecto e intervalo, nunca un p-valor suelto.
- Vocabulario obligatorio: *"aparece asociado a"*, *"suele preceder a"*, *"los datos son
  compatibles con"*. Nunca *"provoca"*, *"causa"* ni *"por eso".*
- La resolución temporal del análisis nunca supera la `time_precision` de los datos que usa.
- Las dos lentes (`as_experienced` / `as_understood`) se pueden alternar en cualquier vista.

---

## 8. Orden de construcción

```
FASE 0  esqueleto + migraciones + auth              ← cimientos
FASE 1  captura de audio y almacenamiento           ← ya se pueden acumular datos reales
FASE 2  transcripción (worker + proveedor)
FASE 3  extracción + reconciliación
FASE 4  UI de revisión                              ← aquí los datos empiezan a ser fiables
FASE 5  timeline y vista de día
FASE 6  rollups + primeras gráficas
FASE 7  línea base y desviaciones
FASE 8  comparativas y antes/después
FASE 9  hipótesis pre-registradas
FASE 10 búsqueda semántica y modo investigación
FASE 11 compartición con profesionales
```

La clave del orden: la **fase 1 se despliega en cuanto exista**, aunque no haya análisis.
Cada día sin capturar es un día de datos que no se recupera, y todo lo de la fase 6 en
adelante necesita meses de historial para significar algo. Grabar antes que analizar.
