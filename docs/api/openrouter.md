# OpenRouter — transcripción y extracción

Documentación verificada el **2026-08-16**. OpenRouter lanzó su endpoint de transcripción el
**22 de julio de 2026**. Reverificar antes de implementar si ha pasado tiempo.

MaiMind usa OpenRouter para **dos cosas distintas** con endpoints distintos:

| Uso | Endpoint | Interfaz en el código |
|---|---|---|
| Transcribir audio | `POST /api/v1/audio/transcriptions` | `TranscriptionProvider` |
| Extraer datos estructurados | `POST /api/v1/chat/completions` | `ExtractionProvider` |

---

## 1. Transcripción

### Endpoint

```
POST https://openrouter.ai/api/v1/audio/transcriptions
Authorization: Bearer $OPENROUTER_API_KEY
```

### Dos formatos de petición

**JSON con base64** (el que usaremos: control total sobre el cuerpo):

```json
{
  "model": "openai/whisper-1",
  "input_audio": { "data": "<base64 crudo>", "format": "webm" },
  "language": "es",
  "response_format": "verbose_json",
  "timestamp_granularities": ["segment"]
}
```

⚠️ `input_audio.data` es **base64 de los bytes crudos**, *no* un data URI. Nada de
`data:audio/webm;base64,...`.

**Multipart estilo OpenAI**: campos `file` + `model`. Límite de 25 MB.

### Parámetros

| Parámetro | Obligatorio | Notas |
|---|---|---|
| `model` | Sí | Slug del modelo STT |
| `input_audio.data` | Sí | Base64 crudo |
| `input_audio.format` | Sí | `wav`, `mp3`, `flac`, `m4a`, `ogg`, `webm`, `aac` |
| `language` | No | ISO-639-1. **Pasar `es`** — no dejar autodetectar, ahorra latencia y errores |
| `response_format` | No | `json` (defecto) o `verbose_json` |
| `timestamp_granularities` | No | `["segment"]` o `["word"]`; **requiere `verbose_json`** |
| `temperature` | No | 0–1. **Usar 0** para transcripción |
| `provider` | No | Opciones específicas del proveedor |

### Respuesta

```json
{
  "text": "transcripción completa",
  "usage": {
    "seconds": 9.2,
    "total_tokens": 113,
    "input_tokens": 83,
    "output_tokens": 30,
    "cost": 0.000508
  }
}
```

`usage.cost` viene en la propia respuesta → se guarda directo en
`transcripts.cost_micros`. No hay que estimar nada.

### Límites

- **25 MB** por fichero.
- **~60 s de tiempo de proceso** (no es un tope de duración de audio, pero condiciona los
  audios largos → habrá que trocear en la importación masiva).
- **No se puede pasar audio por URL.** Siempre base64 o multipart.
- Los timestamps solo funcionan en proveedores compatibles con OpenAI (OpenAI, Groq,
  Together). **Otros devuelven 400** — hay que degradar con elegancia si el proveedor
  enrutado no los soporta.
- No hay salida SRT/VTT.

### Elección de modelo

Descubrir los slugs disponibles en tiempo de implementación:

```
GET https://openrouter.ai/api/v1/models?output_modalities=transcription
```

Los modelos tipo Whisper se facturan **por duración**; los más nuevos, **por token**.
Preferencia para MaiMind, en orden:

1. **Whisper large-v3-turbo** — verbatim, 99+ idiomas, barato y rápido.
2. **whisper-1** — más lento, referencia conocida.
3. Evitar los STT basados en LLM que "limpian" el habla: en este producto las muletillas,
   vacilaciones y frases a medias **son señal**, y el anclaje de evidencia depende de que
   la transcripción sea literal.

### Configuración fija para MaiMind

```php
[
  'language'                => 'es',
  'temperature'             => 0,
  'response_format'         => 'verbose_json',
  'timestamp_granularities' => ['segment'],
]
```

Los segmentos con marca de tiempo se guardan en `transcripts.segments` (JSON). Son útiles
para reproducir el audio desde un punto y para el anclaje de evidencia.

---

## 2. Extracción

Endpoint estándar de chat, compatible con OpenAI:

```
POST https://openrouter.ai/api/v1/chat/completions
```

Puntos a verificar en implementación (fase 3):

- **Salida estructurada.** Confirmar el soporte de `response_format: {"type":"json_schema"}`
  para el modelo elegido. Si no está garantizado, el validador de PHP contra nuestro JSON
  Schema es obligatorio de todas formas (ver `03-extraccion.md`) y se reintenta con el error
  de validación en el mensaje.
- **Política de datos.** OpenRouter permite restringir el enrutado a proveedores que no
  entrenan con los datos. **Configurarlo antes de enviar una sola transcripción real**:
  esto es el material más sensible del sistema.
- **Fijar el modelo explícitamente**, sin enrutado automático. Un cambio silencioso de
  modelo altera la extracción y rompe la comparabilidad longitudinal de los datos.
  El modelo concreto queda registrado en `extraction_runs.model`.

---

## 3. Notas operativas

- **Una sola clave** para transcripción y extracción. En variable de entorno, nunca en el repo.
- **Reintentos** con backoff exponencial en el worker; `jobs.max_attempts = 5`.
- **Coste real** siempre desde `usage.cost` de la respuesta, nunca estimado. Se acumula por
  usuario para poder detectar abuso y para saber el coste unitario real del producto.
- **Timeouts**: 120 s en transcripción, 180 s en extracción. Por debajo de eso se cortan
  peticiones legítimas.

---

## 4. Política de datos (decisión D10)

**Verificado contra la documentación de OpenRouter el 2026-08-30.**

Son **dos controles independientes**, y hacen falta los dos. La nota original de
D10 solo pedía el primero, que no basta:

| Clave | Valor | Qué garantiza |
|---|---|---|
| `data_collection` | `"deny"` | El proveedor **no entrena** con lo que se le manda |
| `zdr` | `true` | El proveedor **no conserva** la petición ni la respuesta |

Un proveedor puede cumplir uno y no el otro: guardar registros treinta días sin
entrenar con ellos satisface `data_collection` y viola ZDR. Para este material
—la voz de alguien contando cómo está, categoría especial del art. 9 RGPD— hacen
falta los dos.

Van dentro del objeto `provider`, en el cuerpo de la petición:

```json
{
  "model": "openai/whisper-1",
  "input_audio": { "data": "…", "format": "webm" },
  "provider": {
    "data_collection": "deny",
    "zdr": true
  }
}
```

### Cuenta y petición se combinan, no se pisan

La configuración de la cuenta se hereda por defecto, y **la petición solo puede
restringir más, nunca menos**: el parámetro por petición se combina con un OR
con lo que tenga la cuenta. Es decir, mandarlo siempre no puede empeorar nada.

Por eso en MaiMind se hacen **las dos cosas**:

1. **En la cuenta de OpenRouter** (una vez, a mano): activar la restricción en
   *Settings → Privacy*.
2. **En cada petición**, desde `src/Providers/OpenRouter/DataPolicy.php`, que es
   el único sitio donde se construye ese bloque. Si cada proveedor lo copiara
   por su cuenta, uno se lo dejaría.

Lo segundo protege de lo primero: que alguien toque el panel de OpenRouter no
puede hacer que empiece a salir material sin restringir.

### Cierra hacia el lado seguro

`DataPolicy::assertNotLoosened()` revienta si la configuración se ha aflojado, y
`bin/check` lo comprueba. Se prefiere que no salga una transcripción a que salga
hacia donde no debe: un fallo se ve, y una grabación en el conjunto de
entrenamiento de alguien no se ve y no se puede retirar.

### Lo que OpenRouter conserva de todas formas

ZDR cubre el contenido de peticiones y respuestas. Los **metadatos** —número de
tokens, latencia, marcas de tiempo— se conservan igualmente. No llevan contenido,
pero sí revelan cuándo y cuánto graba cada persona. A tener en cuenta cuando se
aborde el RGPD (decisión 6, abierta).

---

## Fuentes

- https://openrouter.ai/blog/announcements/announcing-audio-apis/
- https://openrouter.ai/blog/tutorials/transcription-on-openrouter/
- https://openrouter.ai/collections/speech-to-text-models
- Provider routing (`data_collection`, `zdr`, herencia de la cuenta) — https://openrouter.ai/docs/features/provider-routing · consultado el 2026-08-30
