# 01 — Modelo núcleo: conceptual, temporal, evidencia, versionado y relaciones

Este documento define lo **irreversible**: las decisiones que, si se cambian dentro de seis
meses, obligan a migrar datos que ya no se pueden reconstruir. Todo lo demás (variables,
gráficas, análisis) se puede rehacer encima.

---

## 1. Los tres niveles de datos

Regla dura: **nunca se mezclan**, y cada nivel solo depende del anterior.

```
NIVEL 1 — CRUDO (inmutable, es la verdad de origen)
  audio → transcripción → texto literal
  Nunca se edita. Si se re-transcribe, se añade una versión nueva.

NIVEL 2 — ESTRUCTURADO (revisable, versionado)
  observaciones, mediciones, entidades, vínculos
  Producido por extracción + reconciliación. Corregible por el usuario.
  Cada dato apunta a las palabras exactas del nivel 1 que lo originaron.

NIVEL 3 — DERIVADO (desechable, siempre recalculable)
  agregados diarios, líneas base, correlaciones, hipótesis, clusters, embeddings
  Se puede borrar entero y regenerar. Lleva versión de algoritmo.
  Nunca es fuente de nada.
```

Si un dato del nivel 3 no se puede regenerar desde el nivel 2, es un bug de diseño.

---

## 2. Entidades del modelo conceptual

```
                       ┌──────────┐
                       │  users   │  identidad + aislamiento
                       └────┬─────┘
                            │
                       ┌────▼─────┐
                       │ entries  │  ← una grabación / captura
                       └────┬─────┘     captured_at, mood_hint, audio
                            │
                   ┌────────▼─────────┐
                   │   transcripts    │  N por entry (re-transcribible)
                   └────────┬─────────┘
                            │
                   ┌────────▼─────────┐
                   │ extraction_runs  │  propuesta JSON del LLM, sin escribir
                   └────────┬─────────┘
                            │  (reconciliación determinista)
              ┌─────────────┴──────────────┐
              │                            │
      ┌───────▼────────┐          ┌────────▼───────┐
      │  observations  │◄────────►│  measurements  │
      │  (cualitativo) │  links   │ (cuantitativo) │
      └───┬────────┬───┘          └────────┬───────┘
          │        │                       │
    ┌─────▼──┐ ┌───▼────┐            ┌─────▼──────┐
    │entities│ │  tags  │            │ variables  │
    │personas│ │dominios│            │  catálogo  │
    │lugares │ │ temas  │            │core+propias│
    └────────┘ └────────┘            └────────────┘

          ┌──────────────┐        ┌──────────────┐
          │  revisions   │        │ day_coverage │
          │  histórico   │        │ el silencio  │
          └──────────────┘        └──────────────┘
```

### 2.1 `observations` — lo narrativo y discreto

Una observación es **una unidad de sentido que ocurrió o se pensó**. Discriminada por `kind`:

| `kind` | Qué es | Ejemplo |
|---|---|---|
| `event` | Algo que pasó, con agencia externa o compartida | "discusión con Marta" |
| `behavior` | Algo que **yo** hice | "me fui a correr", "cancelé el plan" |
| `state` | Condición sostenida sin variable clara | "raro todo el día" |
| `thought` | Contenido mental literal | "no le importo" |
| `interpretation` | Lectura que el usuario hace de un hecho | "entendí que me estaba rechazando" |
| `attribution` | Intención atribuida a otro | "lo hizo para molestarme" |
| `fact` | Hecho objetivo sin carga | "llovió", "vino mi hermano" |
| `plan` | Intención futura | "mañana tengo la reunión" |
| `reflection` | Metacomentario del usuario sobre sí mismo | "me doy cuenta de que siempre hago esto" |

La separación `event` / `interpretation` / `thought` es la que materializa el **Principio 1**
(registrar antes de interpretar). *"Marta tardó dos horas en contestar"* es `fact`.
*"Está pasando de mí"* es `interpretation`. Van en filas distintas, vinculadas. Esa
separación es la que permite después preguntar *"¿qué hechos suelo interpretar como rechazo?"*.

### 2.2 `measurements` — lo cuantificable

`(usuario, variable, valor, tiempo, evidencia)`. Es la tabla que escanea toda la analítica.

Cubre emociones con intensidad, ratings subjetivos, cantidades objetivas y estados con
intervalo. Puede colgar de una observación (`observation_id`) o existir sola.

*"Estuve nervioso desde las 15:00 hasta la cena"* → una medición de `emotion.anxiety` con
`occurred_start=15:00`, `occurred_end≈21:00`, `temporal_scope='interval'`. No hace falta
una tabla de "estados".

### 2.3 `variables` — catálogo con dos orígenes

- `user_id = 0` → **core universal**, ~30–40 variables que el extractor conoce por nombre.
- `user_id = N` → **variable propia**, emergente del vocabulario del usuario.

Ciclo de vida de una variable propia:

```
mención suelta → candidate → (recurrencia ≥ umbral) → propuesta al usuario → active
                     │                                                          │
                     └────── nunca recurre → archived        alias/duplicado ────┴→ merged
```

Esto resuelve el §17 del prompt sin obligar a definir 200 variables por adelantado, y hace
del sistema algo que **aprende el idioma del usuario** en vez de imponerle el suyo.

### 2.4 `entities` y `tags`

`entities`: personas, lugares, organizaciones, proyectos, objetos. Con alias
(*"Marta"*, *"ella"*, *"mi jefa"* → misma entidad) y con `pseudonym` para poder mostrar
datos a un profesional sin exponer nombres reales.

`tags`: dominios vitales (trabajo, pareja, familia, salud, ocio) y temas emergentes.
Vocabulario controlado pequeño + extensible.

---

## 3. Modelo temporal

Cuatro relojes distintos, y confundirlos arruina el análisis:

| Reloj | Dónde vive | Qué responde |
|---|---|---|
| **Captura** | `entries.captured_at` | ¿Cuándo lo contó? |
| **Ocurrencia** | `occurred_start` / `occurred_end` | ¿Cuándo pasó? |
| **Revisión** | `revisions.created_at` | ¿Cuándo cambió de opinión? |
| **Cómputo** | `computed_at` (nivel 3) | ¿Cuándo se calculó este derivado? |

### 3.1 Campos temporales (idénticos en `observations` y `measurements`)

```
occurred_start     DATETIME(3) NULL   -- UTC
occurred_end       DATETIME(3) NULL   -- NULL si es instante
occurred_date      DATE NULL          -- día LOCAL del usuario, desnormalizado para rollups
time_precision     ENUM(exact, minute, hour, part_of_day, day, week, month, unknown)
temporal_scope     ENUM(point, interval, daily_summary, habitual, future, atemporal)
duration_seconds   INT NULL           -- duración DECLARADA (puede no cuadrar con end-start)
time_expression    VARCHAR(120)       -- literal: "esta mañana", "hace dos días"
time_resolution    ENUM(explicit_absolute, explicit_relative, inferred_context,
                        assumed_recording_time, unknown)
```

Tres detalles que importan:

- **`occurred_date` es el día local**, no el UTC. Sin esto, todo lo grabado después de
  medianoche en verano cae en el día equivocado y los rollups diarios mienten.
- **`duration_seconds` es independiente de `end - start`**. *"Estuve nerviosísimo, como
  dos horas"* dicho a las 23:00: duración declarada 2h, inicio desconocido. Son datos
  distintos y no se deben derivar el uno del otro.
- **`time_resolution = assumed_recording_time`** marca el caso por defecto peligroso: la IA
  asumió que "ahora" es el momento de grabar. Es correcto casi siempre y hay que poder
  filtrarlo cuando no lo es.

### 3.2 Precisión → intervalo de incertidumbre

`time_precision` no es decorativa: define la ventana real del dato.

| Precisión | Ventana | Uso analítico |
|---|---|---|
| `exact` / `minute` | ±1 min | Análisis intradía, secuencias por horas |
| `hour` | ±30 min | Análisis intradía grueso |
| `part_of_day` | mañana/tarde/noche | Solo agregados por franja |
| `day` | día completo | Solo series diarias |
| `week` / `month` | difuso | **Excluido de series temporales** |
| `unknown` | — | Solo contexto cualitativo |

Un análisis "qué pasa en las 3 horas siguientes a X" solo puede usar datos con precisión
`hour` o mejor. Esto se aplica en el motor, no se deja al criterio de quien escriba la consulta.

### 3.3 `temporal_scope`: la distinción que falta en casi todos los diarios

```
point          → un instante                    → SÍ entra en series temporales
interval       → un tramo con inicio y fin      → SÍ (ponderado por duración)
daily_summary  → "hoy en general he estado..."  → SÍ, como valor del día
habitual       → "últimamente duermo mal"       → NO. Canal aparte: autopercepción
future         → "mañana tengo la reunión"      → NO. Canal aparte: anticipación
atemporal      → "soy una persona ansiosa"      → NO. Canal aparte: autoconcepto
```

Los tres canales aparte no son residuos: contrastar `habitual` contra los `point` del mismo
período produce uno de los hallazgos más útiles del sistema — la distancia entre lo que el
usuario cree que le pasa últimamente y lo que sus propios registros muestran.

---

## 4. Modelo de evidencia y confianza

Cuatro ejes **ortogonales**. El error clásico es meterlos en un solo campo `confidence`.

### Eje 1 — Procedencia (`source`): ¿de dónde salió?

| Valor | Significado |
|---|---|
| `user_explicit` | El usuario lo dijo con esas palabras |
| `user_implicit` | Se deduce sin ambigüedad de lo que dijo ("no he pegado ojo" → mala calidad de sueño) |
| `ai_inferred` | El modelo lo propone; el usuario no lo dijo |
| `calculated` | Derivado por regla determinista (duración = fin − inicio) |
| `device` | Wearable, importación automática |
| `imported` | Migrado de otro sistema |

### Eje 2 — Estado epistémico (`epistemic_status`): ¿en qué punto de validación está?

```
asserted ────────► user_confirmed      (el usuario lo ratificó en la revisión)
   │
inferred ────┬───► user_confirmed
             ├───► user_rejected       (el usuario dice que no)
             └───► uncertain           (queda marcado como dudoso)
                            │
   cualquiera ──────────────┴────────► superseded  (reemplazado por una revisión)
```

### Eje 3 — Confianza (`confidence`, 0.00–1.00): ¿cuánto se fía el extractor?

Solo tiene sentido con `source='ai_inferred'` o `user_implicit`. Un dato `user_explicit`
no lleva confianza: el usuario lo dijo.

### Eje 4 — Certeza declarada (`certainty_reported`): ¿cuánto se fía **el usuario**?

`certain | probable | unsure | speculative`. *"Creo que fue por la reunión, pero no estoy
seguro"* → el usuario mismo está marcando incertidumbre. Es un dato distinto de la confianza
del modelo y hay que conservarlo por separado.

### Anclaje de evidencia

```
evidence_quote  VARCHAR(500)  -- las palabras exactas
evidence_start  / evidence_end -- offsets sobre transcripts.text
```

Todo dato estructurado debe poder responder *"¿de dónde has sacado eso?"* señalando texto.
Un dato sin anclaje solo es legítimo si `source ∈ (calculated, device, imported)`.

---

## 5. Versionado: corrección vs reinterpretación

La distinción central del sistema (ver crítica B1).

### 5.1 Corrección — el original era erróneo

> *"Dije las 17:00 pero en realidad fueron las 15:30."*

```
1. Se guarda el snapshot previo completo en `revisions` (JSON).
2. Se mutan los campos afectados en la fila vigente.
3. revision_type = 'correction'. Se registra actor, motivo y entry que la provocó.
```

La analítica normal usa la fila vigente. El histórico existe para auditar y para poder
deshacer. **Nunca se sobrescribe en silencio.**

### 5.2 Reinterpretación — ambas versiones son verdad

> 09:00 *"Estoy fatal."* → 23:00 *"Ahora creo que por la mañana no estaba tan mal."*

```
1. La fila original NO se toca. Conserva lens='as_experienced'.
2. Se INSERTA una fila nueva con lens='as_understood', su propio occurred_* apuntando al
   mismo momento del pasado, y su propio created_at (cuándo se reinterpretó).
3. link(nueva → original, relation='reinterprets').
4. La original recibe superseded_by_id = nueva.id  (marca, no borrado).
5. revision_type = 'reinterpretation'.
```

### 5.3 Las dos lentes

```sql
-- "Cómo lo viví"       → la experiencia en tiempo real, sin retoques
WHERE lens = 'as_experienced'

-- "Cómo lo entiendo hoy" → la lectura vigente
WHERE superseded_by_id IS NULL
```

Un solo predicado cada una. Y la diferencia entre ambas series es medible: **la tasa de
reinterpretación, su dirección (¿siempre suavizo el pasado?) y su latencia** son variables
derivadas de pleno derecho. El prompt lo intuía en §14; aquí queda operativo.

### 5.4 Otros tipos de revisión

`refinement` (añadir detalle sin contradecir) · `confirmation` (el usuario ratifica) ·
`rejection` (el usuario niega una inferencia) · `retraction` (el usuario retira algo que dijo) ·
`merge` (dos entidades o variables eran la misma).

---

## 6. Modelo de relaciones

Tabla `links` polimórfica entre `observations` y `measurements`. Vocabulario **deliberadamente
pobre en causalidad**, siguiendo el Principio 2.

### Relaciones temporales (observables, no interpretativas)
`precedes` · `follows` · `co_occurs` · `overlaps` · `part_of`

### Relaciones semánticas
`about` (una emoción es *sobre* un evento) · `elaborates` · `contradicts` · `similar_to`

### Relaciones de versión
`revises` · `reinterprets` · `confirms` · `rejects`

### Causalidad — solo la declarada, y marcada como tal
`user_claims_caused` · `user_claims_caused_by` · `response_to`

**No existe** una relación `causes` a secas. El sistema no puede afirmarla, y no tener el
verbo disponible es más eficaz que recordar no usarlo.

### Los dos campos que salvan la analítica

```
user_declared TINYINT(1)  -- el usuario afirmó la conexión (no es un hallazgo tuyo)
same_entry    TINYINT(1)  -- ambos extremos salen de la misma grabación (circular)
lag_seconds   INT NULL    -- distancia temporal, cuando la precisión lo permite
```

Un "descubrimiento" solo cuenta como observacional si se sostiene con
`user_declared = 0 AND same_entry = 0`. Sin estos dos campos, el motor de hallazgos
redescubre las opiniones del usuario y se las devuelve como si fueran ciencia (crítica A2).

---

## 7. Línea base y cobertura

### 7.1 Línea base personal

Por `(usuario, variable, ventana)`: mediana, MAD, p10/p50/p90, N, `valid_from`/`valid_to`.

- **Mediana y MAD, no media y desviación típica.** Estos datos tienen outliers reales y
  distribuciones asimétricas; la media es frágil.
- **Ventana móvil** (p. ej. 90 días) además de la global: la línea base de una persona
  cambia, y comparar el mes actual contra los primeros datos de hace dos años no dice nada.
- **Sin datos suficientes no hay línea base.** Estado explícito `insufficient_data` que la
  UI muestra como tal. Nunca una gráfica con aspecto de conclusión sobre 9 observaciones.

### 7.2 Cobertura — el silencio es dato

`day_coverage`: por usuario y día → nº de entradas, primera/última hora, minutos de audio,
si es hueco, longitud de la racha de huecos.

Sostiene tres cosas que sin esto son imposibles:

1. Las gráficas **no interpolan huecos en silencio**: se ven.
2. Los agregados llevan **indicador de cobertura**; una media de 2 días no se dibuja igual
   que una de 28.
3. La **densidad de registro es analizable**: "¿qué caracteriza los períodos en los que
   dejo de registrar?" (crítica A3).

---

## 8. Aislamiento multiusuario

- **`user_id` en absolutamente todas las tablas de datos**, incluidas las derivadas. Sin
  excepciones ni "se deduce por join".
- Toda consulta pasa por un repositorio que inyecta `user_id`. Ningún SQL suelto por ahí.
- Índices compuestos **siempre con `user_id` en primera posición**.
- `grants` + `consents` para la compartición futura con profesionales: alcance limitado
  (qué variables, qué rango de fechas), caducidad obligatoria, revocable, y todo acceso
  registrado en `audit_log`. Diseñado ahora, implementado después.
