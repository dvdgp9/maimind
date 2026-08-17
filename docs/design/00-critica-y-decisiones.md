# 00 — Crítica del planteamiento y decisiones de diseño

> Este documento existe porque el prompt original pedía explícitamente que no se aceptaran
> sus premisas sin discutirlas. Aquí están las objeciones reales, ordenadas por impacto.
> Lo que sobrevive a esta crítica es lo que se implementa en los documentos 01–04.

---

## A. Riesgos que pueden hacer que el producto sea peor que no tener nada

Estos no son detalles técnicos. Son formas en que la aplicación puede fabricar creencias
falsas sobre la propia vida del usuario, que es exactamente el daño que este producto
debería evitar.

### A1. La máquina de falsos descubrimientos (riesgo nº1)

El prompt pide (§8) un inventario de 100–200+ variables y (§22–25) un motor que busque
correlaciones, clusters, secuencias y hallazgos inesperados.

200 variables son **19.900 pares**. Con un umbral ingenuo de p&lt;0,05 y sin corrección,
esperas **~1.000 "hallazgos significativos" puramente por azar**. El sistema tal y como
está descrito encontraría, con datos completamente aleatorios, que "el café de la tarde
se asocia a peor ánimo los martes". Y lo presentaría con lenguaje prudente — lo cual lo
hace peor, no mejor: el lenguaje prudente da credibilidad a un artefacto estadístico.

En un producto sobre salud mental esto no es un bug menor. Es el mecanismo por el cual la
app le enseña al usuario supersticiones sobre sí mismo.

**Decisión.** El motor analítico se diseña con frenos desde el primer día:

- Umbral mínimo de datos por hallazgo (N de días, N de entradas independientes, N de
  ocurrencias del evento) antes de mostrar nada.
- Se reporta **tamaño de efecto**, no significación. Nunca un p-valor a pelo.
- Distinción explícita entre **hipótesis pre-registrada por el usuario** ("creo que dormir
  mal me pone irritable") y **hallazgo exploratorio**. La primera se puede evaluar. El
  segundo solo se puede *proponer para observación futura*.
- Un hallazgo exploratorio nunca se presenta como resultado: se convierte en una
  **hipótesis en seguimiento** que se valida contra datos *posteriores* a su formulación.
  Esto es validación out-of-sample y es la única defensa honesta contra el dragado de datos.
- Se limita el espacio de búsqueda: la búsqueda automática opera sobre el **core** de
  variables, no sobre las 200.

### A2. Circularidad: correlacionar dos frases de la misma grabación no es evidencia

Si el usuario dice *"discutí con X y me quedé fatal toda la tarde"*, el sistema extrae un
evento (discusión) y una medición (ánimo bajo). Correlacionarlos **no descubre nada**:
son la misma frase. Lo único que mide es que el usuario cree que están conectados.

Esto contamina todo el análisis, porque el 80% de las asociaciones que el sistema podría
"descubrir" vendrán de frases donde el usuario ya declaró la conexión.

**Decisión.** Dos campos de primera clase, y la analítica los usa para filtrar:

- `links.user_declared` — el usuario afirmó la relación.
- `links.same_entry` — ambos extremos salen de la misma grabación.

Un hallazgo solo cuenta como **observacional** si se sostiene con datos que provienen de
entradas distintas y sin vínculo declarado. Las asociaciones declaradas se muestran aparte,
etiquetadas como *"lo que tú cuentas"*, que es información valiosa pero de otra naturaleza:
es un mapa de las teorías del usuario sobre sí mismo, no un mapa de su vida.

### A3. Datos ausentes no aleatorios (el silencio es el dato más importante)

La gente graba menos cuando está mal — o graba mucho más. En una crisis se deja de usar
la app durante dos semanas. Ese hueco **no es información neutra**, y si lo tratas como
"sin datos" todas tus medias están sesgadas hacia los días en que el usuario tenía energía
para hablar.

El prompt no menciona esto en ningún punto, y es probablemente el sesgo más grave de
cualquier sistema de autorregistro.

**Decisión.** La cobertura es una entidad de primer nivel (`day_coverage`). Se modela el
hueco explícitamente, se muestra en las gráficas (no se interpola en silencio), y la propia
**densidad de registro se trata como una variable analizable** — "¿qué pasa en los períodos
en los que dejo de registrar?" es una de las preguntas más útiles que el sistema puede
responder.

### A4. Precisión inventada: la IA no puede medir tu ánimo en escala 0–10

Si el usuario dice *"estoy fatal"* y el modelo escribe `ánimo = 2.0`, eso no es una
medición. Es un número inventado que a los tres meses se ha convertido en una serie
temporal con tendencia, media y desviación típica. Basura con decimales.

**Decisión.**

- Los valores **inferidos por IA** se guardan en **bandas ordinales** (`none / slight /
  moderate / strong / extreme`), nunca en continuo, y siempre con `source='ai_inferred'`.
- El continuo 0–10 se reserva para cuando el usuario **dice un número** o lo toca en la UI.
- Toda agregación y toda gráfica distingue visualmente lo dicho de lo inferido.
- Se introduce el **`mood_hint`**: un toque opcional de 1–5 antes de grabar. Un segundo de
  fricción a cambio de la única señal cuantitativa del sistema que no pasa por un LLM.
  Sirve además como *control*: permite medir cuánto se desvía la inferencia de la IA del
  autoinforme directo, es decir, permite auditar al propio extractor.

### A5. 200 variables degradan la extracción

Pedirle a un LLM que rellene 200 slots por transcripción produce peores resultados que
pedirle 30: aumenta la alucinación de campos, la inconsistencia entre ejecuciones y el
coste. Y produce deriva de sinónimos: `cansancio`, `agotamiento`, `sin energía` y `fatiga`
acaban siendo cuatro variables distintas con 5 observaciones cada una, en vez de una con 20.

**Decisión.** Se invierte la propuesta del §8/§17:

- Un **core reducido y bien definido** (~30–40 variables) que el extractor conoce por
  nombre y extrae de forma fiable.
- Un **vocabulario abierto**: cualquier concepto recurrente que el usuario mencione entra
  como `variable candidata`. Cuando supera un umbral de recurrencia se **promociona** a
  variable analizable, con confirmación del usuario.
- Una capa de **alias y canonicalización** con fusión asistida, porque la deriva de
  sinónimos es inevitable y hay que poder repararla.

El inventario exhaustivo de 200 variables sigue siendo útil — pero como **taxonomía de
referencia para el promotor de candidatas**, no como esquema que el extractor deba rellenar.

### A6. Reactividad: observar cambia lo observado

Registrar tu ánimo a diario cambia tu ánimo, y cambia sobre todo *cómo lo nombras*. A los
seis meses el usuario habla el idioma de la app. Esto no se puede eliminar, pero sí se
puede no ignorar: cualquier tendencia a largo plazo está confundida con el aprendizaje del
usuario sobre el propio sistema.

**Decisión.** Se registra `prompt_version` y `extraction_run` en cada dato, y las
comparaciones de largo plazo advierten cuando cruzan un cambio de versión del extractor.
Se documenta la reactividad como limitación permanente en la propia UI de análisis.

### A7. Riesgo clínico

Alguien que graba audios diarios sobre su malestar puede estar en dificultad real. El
prompt acierta en excluir el diagnóstico automático (§33), pero no dice nada sobre qué
hace el sistema cuando los datos muestran un deterioro sostenido.

**Decisión (mínimo viable, no negociable):** la app no diagnostica, no alarma y no
interpreta el deterioro. Sí muestra hechos ("llevas 12 días con ánimo por debajo de tu
línea base habitual") sin causalidad ni pronóstico. Se define una política explícita de
contenido para señales de crisis antes de exponer análisis al usuario final. Pendiente de
decidir contigo (ver §D).

---

## B. Errores de modelado en el planteamiento original

### B1. "Corrección" y "reinterpretación" no son lo mismo, y el prompt los mezcla

El §12 y el §14 describen dos operaciones que el prompt trata casi igual, pero que exigen
comportamientos opuestos:

| | Corrección | Reinterpretación |
|---|---|---|
| Ejemplo | *"dije las 17:00 pero fueron las 15:30"* | *"por la mañana dije que estaba fatal; ahora creo que no lo estaba tanto"* |
| ¿El dato original era falso? | Sí, error factual | **No.** Era verdad en su momento |
| Qué debe pasar | El valor vigente cambia | **Ambos valores conviven** |
| En analítica | Se usa el corregido | Depende de la pregunta |

Tratar una reinterpretación como corrección **destruye el dato más interesante del
sistema**: la diferencia entre cómo se vivió algo y cómo se recuerda.

**Decisión — el mecanismo de "lentes":**

- **Corrección** → se muta la fila vigente y el estado anterior queda en `revisions`.
- **Reinterpretación** → se inserta una **fila nueva** con `lens='as_understood'`, y la
  original conserva `lens='as_experienced'` marcada con `superseded_by_id`.
- La analítica elige lente: *"cómo lo viví"* vs *"cómo lo entiendo ahora"*. Y la **brecha
  entre ambas lentes es en sí misma una variable**: con qué frecuencia y en qué dirección
  este usuario reinterpreta su pasado. Eso es un hallazgo potencialmente más valioso que
  cualquier correlación.

### B2. Falta el "alcance temporal": las generalizaciones no son mediciones

El §20 lista *"últimamente"* junto a *"ayer"*. No son lo mismo. *"Últimamente duermo mal"*
no es una medición del martes: es una **afirmación habitual** sobre un período difuso. Si
entra en la serie temporal como un punto, la contamina; si entra como intervalo, inventa
30 mediciones que el usuario nunca hizo.

Lo mismo con el futuro (*"mañana tengo la reunión y me da pánico"*) y con lo atemporal
(*"soy una persona ansiosa"`).

**Decisión.** `temporal_scope` como campo obligatorio:
`point | interval | daily_summary | habitual | future | atemporal`.
Las series temporales consumen solo `point` e `interval`. `habitual` alimenta un canal
distinto (autopercepción agregada), que es interesante precisamente porque se puede
contrastar con los datos puntuales: *"dices que últimamente duermes mal; tus registros
puntuales de sueño en ese período no se desvían de tu media"*. Ese contraste es producto.

### B3. Cinco tablas casi idénticas (emociones, pensamientos, conductas...) sería un error

El §7 enumera diez tipos de cosas y es tentador hacer diez tablas. Pero conducta y evento
son estructuralmente lo mismo (algo que ocurre en el tiempo, con agencia distinta);
emoción y valoración subjetiva son lo mismo (una variable con un valor en un momento); y
un "estado" es una medición con intervalo.

**Decisión.** Colapsan en **dos** tablas estructuradas + catálogos:

- `measurements` — todo lo que es (variable, valor, tiempo). Emociones, ratings, horas de
  sueño, estados sostenidos. Es la tabla que escanea la analítica.
- `observations` — todo lo que es narrativo y discreto: eventos, conductas, pensamientos,
  interpretaciones, hechos, planes. Discriminadas por `kind`.

Ni una tabla de 200 columnas, ni EAV puro, ni diez tablas gemelas.

### B4. El extractor no debe tener permiso de escritura

El §12 pide que el sistema "detecte revisiones y actualice". Si el LLM puede modificar el
histórico directamente, un error de extracción reescribe silenciosamente el pasado — que
es justo lo que el Principio 5 quiere evitar.

**Decisión.** El LLM es una **función pura**: transcripción + contexto → propuesta JSON.
No escribe. Un reconciliador determinista aplica las propuestas según reglas explícitas, y
toda propuesta que toque datos ya existentes pasa por **confirmación del usuario**. Además
el LLM nunca inventa IDs: recibe una lista cerrada de candidatos y solo puede elegir de ella.

### B5. El bucle de revisión ES el producto, y el §26 lo omite

La UI propuesta es *abrir → grabar → guardar*. Perfecto para capturar. Pero si nadie mira
nunca lo que la IA extrajo, la base de datos se llena de datos plausibles y falsos, y a los
seis meses el análisis es ficción bien formateada.

**Decisión.** Se añade una segunda pantalla, igual de barata: *"esto es lo que he
entendido"* — tarjetas confirmables/corregibles en dos toques. No es un formulario: es un
sí/no sobre 4–6 elementos. Cada confirmación eleva `epistemic_status` a `user_confirmed`,
y la analítica puede restringirse a datos confirmados. Sin esto, el Principio 4 ("la IA
puede equivocarse") es decorativo: se declara la falibilidad pero no se corrige nunca.

### B6. Anclaje de evidencia

No basta con marcar un dato como `ai_inferred`. Hay que poder ver **de qué palabras salió**.

**Decisión.** Todo dato estructurado guarda la cita literal y los offsets sobre la
transcripción. Coste: dos enteros y un varchar. Beneficio: la revisión del usuario se
vuelve trivial, los errores del extractor se vuelven visibles y auditables, y se puede
reprocesar comparando versiones del prompt.

---

## C. Decisiones técnicas tomadas

| Tema | Decisión | Motivo |
|---|---|---|
| BD objetivo | **MariaDB 11.4** | Es lo que corre el servidor (verificado). El esquema usa JSON solo como blob opaco, así que la divergencia con MySQL no nos toca. Misma versión en Docker en local |
| PHP objetivo | **8.3** | Producción tiene 8.3.30. Local tiene 8.4.11 → fijar 8.3 en Docker |
| Transcripción | **OpenRouter**, endpoint `/audio/transcriptions`, Whisper large-v3-turbo | ASR real y verbatim (no LLM que parafrasea), una sola clave para transcripción y extracción, céntimos al mes. Ver `docs/api/openrouter.md` |
| Whisper autoalojado | **Descartado por ahora** | El servidor son 2 vCPU compartidas con un servidor de correo. faster-whisper en CPU va a RTF ~2,5: sirve para lotes nocturnos, no para "grabo y en un minuto está". Sigue detrás de la interfaz por si algún día hay GPU |
| Retención de audio | **30 días**, luego purga; configurable | Ventana suficiente para reescuchar y para re-transcribir si cambiamos de proveedor. Pasado eso el valor cae y el riesgo no |
| Deterioro sostenido | **Solo hechos** | Nunca interpretar ni pronosticar. Recursos de ayuda siempre accesibles, nunca disparados reactivamente — eso ya sería interpretar |
| PKs | `BIGINT UNSIGNED AUTO_INCREMENT` + `uid` ULID público | Rendimiento en las tablas grandes; el ULID evita exponer IDs secuenciales en URLs |
| `user_id` universal | `0` = catálogo global, no `NULL` | `NULL` rompe las claves únicas en MySQL |
| Tiempos | `DATETIME(3)` en **UTC** + zona horaria del cliente aparte | Se registran DST y viajes sin ambigüedad |
| Cola de trabajos | Tabla `jobs` + worker systemd | Un VPS Hetzner lo aguanta de sobra; Redis se añade solo si hace falta |
| Embeddings | BLOB en MySQL + fuerza bruta por usuario | Con &lt;100k vectores por usuario es instantáneo. Detrás de una interfaz para poder cambiar a pgvector/sqlite-vec sin tocar el resto |
| Framework PHP | Vanilla + Composer (PSR-4), sin framework pesado | Propuesta, no imposición — ver decisión pendiente D4 |
| Audio | Se borra por defecto tras transcribir (configurable) | Minimización de datos; la transcripción ya es el material sensible |

---

## D. Decisiones — estado

### Resueltas (2026-08-16)

| # | Decisión | Resolución |
|---|---|---|
| D1 | Proveedor de transcripción | OpenRouter + Whisper large-v3-turbo. Autoalojado descartado por hardware |
| D2 | Retención de audio | 30 días, luego purga. Configurable por usuario |
| D3 | Deterioro sostenido | Solo hechos. Sin interpretar, sin pronosticar, sin alarmas reactivas |
| D4 | Framework PHP | **Vanilla + Composer.** Decisión del usuario: con Laravel ha tenido problemas que no controlaba |
| D7 | Motor de BD | MariaDB 11.4 (es lo que hay en producción) |
| D8 | Dominio | Subdominio de `iaiapro.com` para empezar; dominio propio más adelante |
| D5 | Idioma | Producto en español. Slugs y enums en inglés porque son identificadores, no texto. Andamiaje i18n mínimo desde la tarea 0.1: coste ~2-3 h ahora frente a días de retrofit. Ver `04-arquitectura.md` §4.bis |
| D10 | Política de datos OpenRouter | Enrutar solo a proveedores que no entrenan. Configurar antes de la primera transcripción real |

### Abiertas

6. **RGPD.** Multiusuario + datos de salud mental = categoría especial (Art. 9 RGPD). Con
   usuarios reales hace falta base legal, política de privacidad, encargados del tratamiento
   (OpenRouter y los proveedores a los que enrute) y derecho de supresión efectivo. El modelo
   lo contempla; es trabajo de producto además de código. No bloquea el desarrollo, sí el
   lanzamiento.

9. **Aislamiento en un servidor compartido.** La máquina de producción aloja también iaiaPRO,
   un servidor de correo y otros tres usuarios de Hestia, con 2 vCPU y 3,7 GB. MaiMind añade
   un worker permanente y una base de datos. Hay que decidir si convive ahí o acaba en su
   propia máquina; para el prototipo convive, con la concurrencia del worker limitada a 1.

10. **Política de datos en OpenRouter.** Restringir el enrutado a proveedores que no entrenan
    con los datos enviados. Debe configurarse **antes** de enviar la primera transcripción real.

---

## E. Qué queda deliberadamente fuera del núcleo

No porque no importe, sino porque diseñarlo ahora sin datos reales es adivinar:

- Inventario cerrado de 200 variables → se sustituye por core + promoción de candidatas (A5).
- Motor de hipótesis y clustering → necesita ≥60–90 días de datos reales para tener sentido.
- Compartición con profesionales, permisos y auditoría → el esquema los contempla
  (`grants`, `consents`, `audit_log`), la implementación espera.
- Integración con dispositivos (sueño, pasos) → el modelo ya admite `source='device'`.
