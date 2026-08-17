# 05 — El catálogo core de variables

40 variables. El dato vive en `resources/seeds/variables_core.php`; aquí está el porqué.

---

## 1. El criterio de admisión

Una variable entra en el core si cumple **las cuatro**:

1. **La gente lo dice de verdad.** No "lo que un psicólogo querría medir", sino lo que
   alguien dice en voz alta hablando solo con el móvil. Si nadie lo verbaliza nunca, no es
   una variable core: es candidata a emerger del vocabulario del usuario.
2. **Se puede extraer sin adivinar.** Si hace falta interpretar para rellenarla, o va con
   `requires_confirm`, o no entra.
3. **Sostiene alguna pregunta real** de las que el producto existe para responder.
4. **No es sinónimo de otra.** Los casi-sinónimos entran como **alias**, no como variables.

Y una regla de salida: el core se mantiene pequeño. Pedirle a un modelo que rellene
doscientos huecos da peores resultados que pedirle cuarenta.

### El coste de extracción no se mide en variables

Parecería que 15 emociones cuestan quince veces más que una. No: para el extractor son
**una sola decisión** — *"¿se nombra alguna emoción? elige de esta lista"*. Lo que encarece
la extracción es el número de decisiones distintas, no el tamaño de los vocabularios
cerrados dentro de cada una.

Por eso el vocabulario emocional puede ser rico sin degradar nada, mientras que añadir una
decimoquinta métrica de sueño sí costaría.

---

## 2. Estados de fondo ≠ emociones

La distinción estructural más importante del catálogo, y la razón de la migración `002`:

| | Estados de fondo | Emociones |
|---|---|---|
| Cuáles | ánimo, energía, estrés | tristeza, enfado, ilusión… |
| Qué son | Un nivel continuo | Un episodio |
| Cuándo existen | Siempre, todo el día | Cuando pasa algo |
| Cómo se miden | Ordinal 1–5 | Banda de intensidad |
| En una gráfica | Una línea | Un punto sobre un suceso |

Meterlas en la misma categoría daría una interfaz con dieciocho "emociones" de dos
naturalezas distintas, y una analítica que trataría igual una línea continua y un suceso.

Los tres estados de fondo son **el esqueleto de todas las series temporales**. Son los que
se dibujan.

---

## 3. Una sola escala, con anclas verbales, y la misma que el dedo

Todos los ordinales van de **1 a 5 con anclas verbales**, nunca de 0 a 10.

Dos razones:

**Un LLM no puede medir tu ánimo en escala 0–10.** Si el usuario dice *"estoy bien"* y el
modelo escribe `6.5`, eso no es una medición: es un número inventado que a los tres meses se
ha convertido en una serie temporal con media y tendencia. Con anclas verbales, *"estoy
bien"* → 4 es un **mapeo a una definición**, no una estimación.

**Es la misma escala que `entries.mood_hint`**, el toque opcional de 1–5 antes de grabar. Eso
permite comparar directamente lo que extrae la IA con lo que el usuario marca con el dedo —
es decir, **auditar al extractor con datos del propio usuario**. Si la IA sistemáticamente
puntúa el ánimo por encima del toque, eso es medible y corregible.

Cuando el usuario sí dice un número sobre diez, se convierte con una tabla documentada y se
conserva la frase literal en `value_text`. Lossy, pero declarado.

---

## 4. Las emociones se diseñaron en español

El documento [04 §4.bis](04-arquitectura.md) advierte que el vocabulario emocional no se
traduce. Dos entradas del catálogo lo demuestran, y ambas llevan la etiqueta inglesa marcada
como aproximación:

- **Agobio** → *overwhelm (aprox.)*. El inglés recoge la sobrecarga pero pierde el ahogo.
- **Ilusión** → *positive anticipation (aprox.)*. No hay palabra inglesa; la etiqueta es una
  descripción.

Y **vergüenza** se conserva unida a propósito, aunque el inglés la parta en *shame* y
*embarrassment*. Partirla porque otro idioma la parte sería importar una distinción que el
hablante no hace.

Un test comprueba que estas aproximaciones sigan declaradas, para que nadie las dé por
traducciones buenas más adelante.

### Las 15 emociones

**Negativas (11):** tristeza · ansiedad · enfado · miedo · frustración · culpa · vergüenza ·
soledad · irritabilidad · agobio · apatía

**Positivas (4):** alegría · ilusión · satisfacción · conexión

Las fronteras están definidas en `extraction_hint` de cada una, porque son donde el
extractor se equivocaría:

- **ansiedad / miedo** → el miedo tiene objeto; la ansiedad no.
- **enfado / irritabilidad** → el enfado va dirigido; la irritabilidad es umbral bajo.
- **culpa / vergüenza** → la culpa es sobre un acto; la vergüenza sobre uno mismo.
- **tristeza / apatía** → la tristeza duele; la apatía es no querer nada.
- **estrés / agobio** → el estrés es la presión; el agobio, no poder con ella.
- **soledad / conexión** → no son inversas: la conexión es **con alguien** y lleva
  `target_entity_id`; la soledad es un estado sin objeto. Esa diferencia estructural es lo
  que justifica tener las dos.

---

## 5. Lo que se dejó fuera, y por qué

**Cansancio.** Es energía baja, no una variable. Como variable aparte permitiría guardar
`energía = 8` y `cansancio = 8` a la vez, dos filas contradictorias. *"Agotado"*,
*"reventado"*, *"hecho polvo"* son **alias** del extremo bajo de `state.energy`.

**Calma.** Mismo caso: es estrés bajo. *"Tranquilo"*, *"relajado"*, *"en calma"* son alias
de `state.stress` con valor 1–2.

**Procrastinación.** Es una etiqueta con juicio, no una observación. Los hechos que hay
debajo (no avanzó, evitó una tarea) se capturan mejor como observaciones.

**Sensación de avance.** Es el ejemplo del planteamiento original de variable personal
emergente. Se deja fuera a propósito para que lo sea: es la mejor demostración del mecanismo
de promoción.

**Alimentación, clima, ubicación.** Rara vez se narran de forma utilizable, o vendrán mejor
de un dispositivo o una API que del habla. Del cuerpo se conserva **apetito**, que es la
señal que sí se verbaliza.

**Hora de acostarse.** Se dice a menudo, pero el envoltorio de medianoche complica el modelo
y las horas dormidas ya recogen casi toda la carga analítica. Candidata para más adelante.

---

## 6. La polaridad solo se asigna cuando es definicional

`polarity` decide cómo colorea la interfaz y qué cuenta como "mejorar". Asignarla donde no
toca es **contestar la pregunta antes de mirar los datos**.

Las siete conductas del core llevan `neutral`, sin excepción:

| Variable | Por qué neutral |
|---|---|
| Salir de casa | Hay quien se recupera saliendo y quien se recupera quedándose |
| Tiempo de pantallas | Dar por hecho que las pantallas son malas es exactamente el prejuicio a evitar |
| Retraimiento | A veces la soledad restaura. Es lo que hay que averiguar |
| Ejercicio | Para esta persona en particular, está por ver |
| Alcohol, cafeína | Ídem |
| Contacto social | Más no siempre es mejor |

También `neutral`: **horas de sueño** (dormir de más también es señal), **apetito** (la
escala está centrada en 3 = lo normal y ambos extremos son notables) y **carga de trabajo**
(hay quien rinde mejor con presión).

Solo llevan polaridad las variables **definicionalmente** aversivas o deseables: emociones,
estrés, dolor, tensión, rumiación, autocrítica, preocupación, conflicto, fragmentación del
sueño. Un test comprueba que ninguna conducta se cuele con polaridad.

---

## 7. Cuándo una variable es solo una bandera

`behavior.social_contact`, `social.conflict` y `social.support` son booleanos, no recuentos.

Deliberado: **cuando la cantidad se deduce mejor contando observaciones, la variable es solo
una bandera diaria.** Con quién, cuánto y de qué sale de las observaciones y sus entidades;
la variable existe para poder comparar días con y sin conflicto sin recorrer el histórico
entero.

Del mismo modo, el tipo de valor sigue a cómo habla la gente:

| Variable | Tipo | Porque |
|---|---|---|
| Horas de sueño | numérico | *"He dormido seis horas"* se dice |
| Fragmentación | ordinal | *"Me desperté varias veces"* no es un número |
| Tiempo de pantallas | ordinal | Nadie dice *"cuatro horas y doce minutos"* |
| Ejercicio | duración | Y `value_bool` cuando solo dice que lo hizo |

Esa última fila importa: **"ha pasado, cantidad desconocida" es un dato legítimo.** El
extractor no debe rellenar el hueco a ojo.

---

## 8. Cinco variables piden confirmación siempre

`requires_confirm = 1` en: **retraimiento**, **sensación de control**, **autocrítica**,
**culpa** y **vergüenza**.

Todas comparten que rellenarlas exige interpretar. Llamar *"retraimiento"* al tiempo que
alguien pasa solo es un juicio sobre su intención, no una observación de un hecho. Deducir
culpa del tono de voz es exactamente lo que la regla R4 prohíbe.

---

## 9. Los alias son el puente

310 alias en español, mínimo cuatro por variable, y **cada uno pertenece a una sola
variable** — validado en el seeder, que revienta si dos la reclaman. Un alias ambiguo dejaría
al extractor sin criterio y la clave única de la tabla se quedaría con el primero en
silencio.

Los alias son donde vive el español real: *"de mal café"*, *"rayado"*, *"comerme la cabeza"*,
*"hasta arriba"*, *"me da corte"*, *"hecho polvo"*. Son también el mecanismo que absorbe la
deriva de sinónimos sin inflar el catálogo.

---

## 10. Cómo cambiará esto

El catálogo se siembra con `bin/seed`, no con una migración, precisamente porque **va a
cambiar**: se afinarán definiciones y `extraction_hint` con transcripciones reales delante, y
alguna variable emergente se promoverá al core.

El seeder es idempotente y conserva lo que es historia y no definición: `occurrence_count`,
`first_seen_at`, el `uid` y los alias que no vengan del propio seed.

**Lo que aún no se puede decidir sin datos:** si 15 emociones son demasiadas o pocas, si las
anclas verbales se aplican de forma consistente, y si alguna frontera (agobio/estrés,
apatía/tristeza) resulta impracticable en el habla real. Se revisa cuando haya unas cuantas
semanas de transcripciones.
