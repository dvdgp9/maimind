# 03 — Extracción: contrato JSON y reglas

Principio rector: **el LLM no escribe en la base de datos.** Es una función pura
`(transcripción, contexto) → propuesta JSON`. Un reconciliador determinista decide qué se
aplica, qué se encola para revisión del usuario y qué se descarta.

---

## 1. Pipeline en tres etapas

```
Etapa A — EXTRACT
  entrada: transcripción + fecha/hora/zona de captura + catálogo core de variables
  salida:  observaciones, mediciones, entidades y vínculos NUEVOS (solo temp_ids)
  el modelo NO ve el histórico → no puede alterarlo

Etapa B — RESOLVE
  entrada: salida de A + lista CERRADA de candidatos recuperados de BD
           (entidades del usuario, variables activas, observaciones recientes)
  salida:  emparejamientos temp_id → id real, o "es nuevo"
  el modelo elige de una lista; nunca inventa un id

Etapa C — REVISION_DETECT   (solo si A o B marcan señales de revisión)
  entrada: fragmento + observaciones candidatas de los últimos N días
  salida:  propuesta de revisión con tipo, objetivo y campos afectados
  TODA salida de C requiere confirmación del usuario antes de aplicarse
```

Separar A de B es lo que impide que el modelo "recuerde mal" el pasado: en A no tiene el
pasado delante, y en B solo puede señalar, no redactar.

---

## 2. Esquema JSON de la etapa A

```jsonc
{
  "schema_version": "1.0",
  "language": "es",
  "recording_context": {
    "captured_at": "2026-08-16T21:04:00Z",
    "local_time":  "2026-08-16T23:04:00+02:00",
    "timezone":    "Europe/Madrid"
  },

  // Anclajes de evidencia. Todo lo demás apunta aquí.
  "segments": [
    { "id": "s1", "quote": "a las tres he tenido una discusión con Marta",
      "char_start": 0, "char_end": 43 }
  ],

  "entities": [
    { "temp_id": "e1", "mention": "Marta", "type": "person",
      "suggested_role": "amiga", "segment_ids": ["s1"], "confidence": 0.95 }
  ],

  "observations": [
    {
      "temp_id": "o1",
      "kind": "event",
      "label": "discusión",
      "summary": "Discusión con Marta sobre los planes del fin de semana",
      "verbatim": null,
      "agency": "mutual",
      "valence_reported": "negative",
      "significance": 70,
      "certainty_reported": "certain",
      "time": {
        "scope": "point",
        "start": "2026-08-16T13:00:00Z",
        "end": null,
        "precision": "hour",
        "duration_seconds": null,
        "expression": "a las tres",
        "resolution": "explicit_relative",
        "is_recurring": false
      },
      "entities": [ { "ref": "e1", "role": "participant" } ],
      "tags": ["relaciones", "conflicto"],
      "evidence": { "segment_ids": ["s1"], "source": "user_explicit", "confidence": 0.95 }
    }
  ],

  "measurements": [
    {
      "temp_id": "m1",
      "variable_slug": "emotion.anxiety",     // del core, o null si es nueva
      "variable_proposal": null,              // se rellena solo si variable_slug es null
      "value": { "intensity_band": "strong", "num": null, "code": null, "bool": null,
                 "text": "bastante nervioso" },
      "target_entity_ref": null,
      "observation_ref": "o1",
      "time": {
        "scope": "interval",
        "start": "2026-08-16T13:00:00Z",
        "end":   "2026-08-16T21:04:00Z",
        "precision": "hour",
        "duration_seconds": null,
        "expression": "desde entonces",
        "resolution": "explicit_relative"
      },
      "evidence": { "segment_ids": ["s1"], "source": "user_explicit", "confidence": 0.9 },
      "certainty_reported": "certain"
    }
  ],

  "links": [
    { "from": "o1", "to": "m1", "relation": "user_claims_caused",
      "user_declared": true, "confidence": 0.9, "segment_ids": ["s1"] }
  ],

  // Señales de que el usuario está corrigiendo o releyendo algo pasado.
  // NO se resuelve aquí: solo se señala para la etapa C.
  "revision_signals": [
    { "quote": "en realidad no fue a las cinco, fue a las tres y media",
      "signal_type": "time_correction",
      "hint": "referencia a un evento previo del mismo día",
      "confidence": 0.8 }
  ],

  // Obligatorio: lo que el modelo NO ha sabido resolver.
  "unresolved": [
    { "quote": "lo de siempre con el tema ese", "reason": "referent_unknown" }
  ],

  "extraction_notes": {
    "audio_quality_issue": false,
    "content_out_of_scope": false,     // habla de otra persona, no de sí mismo
    "nothing_extractable": false
  }
}
```

### Campos que no son opcionales aunque lo parezcan

- **`segments`** — sin cita literal y offsets no hay dato. Es lo que hace auditable al modelo.
- **`unresolved`** — obliga al modelo a declarar sus lagunas en vez de rellenarlas. Un
  extractor que nunca devuelve `unresolved` está inventando.
- **`time.scope`** — sin esto, "últimamente duermo mal" contamina la serie diaria.
- **`links.user_declared`** — sin esto, el motor de análisis confunde las teorías del
  usuario con hallazgos propios.

---

## 3. Reglas de extracción

### R1 — Qué es una observación nueva

Crear una observación cuando hay **un suceso, acción o contenido mental identificable con
límites propios**. Dos criterios prácticos:

- Si se puede fechar por separado → observación separada.
- Si se puede valorar por separado → observación separada.

*"Discutí con Marta y luego me fui a correr"* → dos observaciones (`event` + `behavior`),
vinculadas por `precedes`. No una sola con dos frases.

### R2 — Qué es una medición

Crear una medición cuando hay **un estado interno o una cantidad atribuible a una variable**.

- Emoción nombrada → medición de la variable de emoción, con banda de intensidad.
- Cantidad objetiva ("dormí seis horas") → medición numérica con unidad.
- Valoración subjetiva ("he dormido fatal") → medición ordinal, variable distinta de la
  anterior. **Nunca se fusionan cantidad objetiva y valoración subjetiva en un solo dato.**

### R3 — Intensidad: bandas, no números inventados

| El usuario dice | Se registra |
|---|---|
| "un siete sobre diez" | `num = 7`, `source = user_explicit` |
| "bastante nervioso" | `intensity_band = strong`, `source = user_explicit` |
| "hoy raro" | `intensity_band = slight`, `source = ai_inferred`, `confidence ≤ 0.5` |
| tono tenso pero sin nombrar emoción | **no se extrae medición**; a lo sumo `unresolved` |

El extractor **no puede** convertir "bastante nervioso" en `8.0`. Esa cifra sería del modelo,
no del usuario, y a los tres meses nadie distinguiría una de otra en una gráfica.

### R4 — Cuándo NO inferir

Prohibido inferir:
- Una emoción que el usuario no ha nombrado ni descrito.
- Una relación causal que el usuario no ha afirmado.
- Una hora concreta cuando solo hay una referencia difusa (se usa `precision` gruesa).
- Un diagnóstico, etiqueta clínica o rasgo de personalidad. **Nunca.**
- Intenciones de terceros que el usuario no haya atribuido explícitamente.

Ante duda: `unresolved`. Un hueco honesto vale más que un dato plausible.

### R5 — Resolución temporal

```
1. Hora absoluta explícita          → precision = exact|minute,  resolution = explicit_absolute
2. Relativa resoluble               → calcular desde local_time,  resolution = explicit_relative
     "hace dos horas", "ayer por la tarde", "el lunes"
3. Franja del día                   → precision = part_of_day
     mañana 06–12 · tarde 12–20 · noche 20–02  (configurable por usuario)
4. Presente sin marca               → start = local_time, resolution = assumed_recording_time
5. Difuso o generalizador           → scope = habitual, sin start/end
6. Irresoluble                      → precision = unknown + entrada en unresolved
```

Siempre se guarda `expression` con las palabras originales, aunque se haya resuelto la fecha.
Si más adelante mejora el parser, se puede reprocesar.

### R6 — Variables nuevas

Si el concepto no está en el core:

```jsonc
"variable_slug": null,
"variable_proposal": {
  "suggested_slug": "custom.sensacion_de_avance",
  "name": "Sensación de avance",
  "category": "cognition",
  "value_type": "ordinal",
  "polarity": "higher_better",
  "rationale": "el usuario lo menciona como un estado valorable, no como un evento"
}
```

El reconciliador la crea con `status='candidate'`. Solo se promueve a `active` (y por tanto
solo entra en la analítica) tras **N apariciones en días distintos** + confirmación del
usuario. Umbral inicial propuesto: 4 apariciones en 3 días distintos.

### R7 — Señales de revisión

El extractor **solo señala**. Marcadores típicos: *"en realidad"*, *"me equivoqué"*,
*"pensándolo mejor"*, *"ahora creo que"*, *"no era X, era Y"*, *"antes dije"*.

La etapa C decide el tipo, y aquí está la distinción crítica del sistema:

| Señal | Tipo | Efecto |
|---|---|---|
| *"no fue a las cinco, fue a las tres"* | `correction` | Muta la fila vigente; snapshot a `revisions` |
| *"creo que no era enfado, era tristeza"* | `correction` | Muta; el usuario corrige un error de nombre |
| *"esta mañana dije que estaba fatal, ahora creo que no tanto"* | `reinterpretation` | **Fila nueva** con `lens='as_understood'`; la original intacta |
| *"sí, efectivamente estaba nervioso"* | `confirmation` | Sube `epistemic_status` a `user_confirmed` |
| *"eso que dije ayer no era verdad"* | `retraction` | Marca `user_rejected`; no se borra |

La regla para separar corrección de reinterpretación: **¿el usuario dice que se equivocó al
describir, o que ahora lo ve distinto?** Lo primero es corrección. Lo segundo es
reinterpretación y ambas versiones se conservan. Si el modelo no puede decidir, propone
`reinterpretation` — es la opción que no destruye información.

### R8 — Contenido fuera de alcance

Si el audio habla de la experiencia de **otra persona**, o es una nota práctica ("comprar
pan"), o no contiene material autobiográfico: `content_out_of_scope = true` y no se extrae
nada. Registrar la vida de terceros en el sistema de otro es un problema de privacidad, no
solo de ruido.

### R9 — Idempotencia

Reprocesar la misma transcripción con la misma `prompt_version` no debe duplicar datos. La
reconciliación calcula una clave de deduplicación por `(entry_id, kind, label_normalizado,
occurred_start, variable_id)`. Un reproceso con versión de prompt **nueva** crea un
`extraction_run` nuevo cuyos datos **sustituyen** a los del anterior (marcando los viejos
como `superseded`), salvo los que el usuario ya confirmó o corrigió a mano: **lo tocado por
el usuario es intocable para el reprocesado.**

---

## 4. Reglas del reconciliador (determinista, sin IA)

```
POR CADA elemento propuesto:

  1. Validar contra el JSON Schema. Fallo → run.status='schema_error', no se aplica nada.
  2. Validar coherencia temporal:
       - occurred_start <= occurred_end
       - occurred_start no puede ser futuro salvo scope='future'/'plan'
       - occurred_start no anterior al alta del usuario (salvo marcado como retrospectivo)
     Fallo → el elemento se marca 'uncertain' y va a revisión, no se descarta.
  3. Resolver variable: slug del core → id. No existe → candidata (R6).
  4. Resolver entidad: alias exacto → id. Sin match → entidad candidata.
  5. Deduplicar (R9).
  6. Insertar con source/confidence/epistemic_status del extractor.
  7. Los links heredan same_entry=1 si ambos extremos vienen de esta misma entrada.
  8. Encolar para revisión del usuario todo lo que cumpla:
       source='ai_inferred' AND confidence < 0.75
       OR variable.requires_confirm = 1
       OR es una propuesta de revisión (etapa C)  ← SIEMPRE
  9. entries.pipeline_state = 'reconciled' o 'needs_review'
```

Regla dura: **ninguna propuesta que modifique datos existentes se aplica sin confirmación
explícita del usuario.** Las inserciones nuevas sí se aplican automáticamente (con su marca
de inferencia), porque exigir confirmación de todo mataría el producto.

---

## 5. UI de revisión — el bucle que hace que los datos valgan algo

Tras procesar una entrada, el usuario ve *"Esto es lo que he entendido"*: entre 3 y 6
tarjetas, cada una con la **cita literal** que la originó.

```
┌──────────────────────────────────────────────┐
│  Discusión con Marta          hoy, ~15:00    │
│  "a las tres he tenido una discusión…"       │
│                                    [✓]  [✎]  │
├──────────────────────────────────────────────┤
│  Nerviosismo   fuerte    15:00 → ahora       │
│  "y desde entonces bastante nervioso"        │
│                                    [✓]  [✎]  │
├──────────────────────────────────────────────┤
│  ¿"sensación de avance" es algo que quieres  │
│   seguir?  (3ª vez que lo mencionas)         │
│                          [seguir]  [ignorar] │
└──────────────────────────────────────────────┘
```

Reglas de UX:
- Nunca más de 6 tarjetas. Si hay más, se priorizan por confianza baja y por impacto.
- El botón grande es **confirmar**; corregir es el camino secundario.
- Saltarse la revisión es legítimo: los datos quedan como `inferred` y la analítica lo sabe.
- Las propuestas de **revisión del pasado** siempre se muestran con las dos versiones
  visibles y la pregunta explícita: *"¿te equivocaste, o ahora lo ves distinto?"* — porque
  esa respuesta es exactamente la que el sistema no puede adivinar y sí necesita.
