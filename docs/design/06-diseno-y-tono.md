# 06 — Diseño y tono

Este documento manda sobre cualquier decisión visual. Si algo del código lo contradice, el
error está en el código.

La regla que lo resume todo: **la aplicación observa, no opina.**

---

## 1. Paleta

| Token | Claro | Oscuro | Para qué |
|---|---|---|---|
| `--ground` | `#FAF3EA` | `#151311` | Fondo |
| `--surface` | `#EED3BA` | `#211B18` | Tarjetas |
| `--raised` | `#FFFDFA` | `#2A2320` | Tercera capa (campos) |
| `--line` | `#E8DACB` | `#332B27` | Separadores |
| `--ink` | `#151311` | `#EFE2D4` | Texto |
| `--ink-soft` | `#64534A` | `#A08D80` | Texto secundario |
| `--accent` | `#4B262F` | `#B06B77` | Lo que se toca |

Origen: *Almond Hearth* `#EED3BA`, *Velvet Curfew* `#4B262F`, *Obsidian Ink* `#151311`.

Dos decisiones que conviene entender:

**El almendra no es el fondo, son las tarjetas.** A pantalla completa cansa; en superficies
pequeñas funciona. Y al aclarar el fondo, la tarjeta se separa **sola**, por diferencia de
tono, sin necesitar borde ni sombra. Menos elementos haciendo el mismo trabajo.

**El burdeos se levanta en oscuro.** `#4B262F` sobre `#151311` es prácticamente invisible.
`#B06B77` es el único color que no viene de la paleta original, y existe solo porque hace
falta.

### No existe un color de "malo"

No hay rojo de alarma ni verde de acierto. **No es un olvido: está prohibido.**

Las conductas del catálogo llevan polaridad neutral porque si salir de casa o mirar el móvil
te sienta bien o mal es lo que la app tiene que descubrir, no lo que debe presuponer
(`05-catalogo-core.md` §6). Un semáforo en la interfaz contradiría eso en cada pantalla.

Consecuencia práctica: **no se añaden colores semánticos a la paleta.** Si algún día hace
falta un segundo acento —para distinguir las dos lentes en las gráficas, por ejemplo— se
busca en cálido (ocre, ámbar). Nunca en verde, porque contra el burdeos formaría el eje
bueno/malo, y además rojo-verde es el par que confunde alrededor del 8% de los hombres.

---

## 2. Iconos: Phosphor, peso Light

[phosphor-icons/core](https://github.com/phosphor-icons/core), MIT. Los SVG viven en
`resources/icons/` y se insertan en línea con `icon('nombre', 20)`.

Se eligió sobre Lucide e Iconoir por una razón concreta de este proyecto: como el color no
puede dar énfasis, **el peso del trazo es la palanca de jerarquía**. Phosphor tiene seis
pesos; los otros, uno.

| Peso | Cuándo |
|---|---|
| Light | Todo, por defecto |
| Regular | La acción principal de la pantalla |
| Fill | Estado activo o seleccionado |

Se insertan en línea, no como `<img>`: heredan `currentColor` y siguen al tema claro/oscuro
sin CSS extra, no cuestan una petición cada uno, y no dependen de nada externo — la CSP es
`default-src 'self'` y no va a relajarse.

### Nunca un emoji

Ni en la interfaz, ni en los textos, ni en los botones. Hay un test que lo comprueba.

Tres razones, la última es la que importa:

1. Lo dibuja el sistema operativo: cambia de forma entre plataformas y el diseño no lo controla.
2. Mete color saturado donde la paleta no lo quiere.
3. **Un emoji propone una emoción.** Una carita amarilla junto a un registro le está
   sugiriendo al usuario cómo debería sentirse con lo que acaba de contar. Es exactamente lo
   que este producto no puede hacer.

---

## 3. Tono

### Nada de gamificación

**Sin rachas.** Un contador de días seguidos sería activamente dañino aquí, por dos razones
independientes:

- Castiga los huecos, que son el dato más informativo del sistema. La gente deja de grabar
  justo cuando está peor, y eso es señal (`00-critica-y-decisiones.md` §A3).
- Hace que se grabe *para mantener la racha* en vez de porque hay algo que contar. Es la vía
  más rápida a llenar la base de ruido.

Tampoco: insignias, niveles, porcentajes de completitud, ni celebraciones.

### Cómo se escribe

| No | Sí |
|---|---|
| «¡Bien hecho!» | «Guardado» |
| «Llevas 7 días seguidos» | «Último registro: ayer, 23:04» |
| «Tu ánimo ha mejorado un 23%» | «Los últimos 5 días están por encima de tu media» |
| «Dormir poco te pone irritable» | «Los días con menos sueño aparecen asociados a más irritabilidad» |
| «Hoy vas mal» | (nada: la app no valora el día) |

Vocabulario obligatorio en analítica: *aparece asociado a*, *suele preceder a*, *los datos son
compatibles con*. Prohibido: *provoca*, *causa*, *por eso*, *deberías*.

### Deterioro sostenido

Solo hechos. *«Llevas 12 días por debajo de tu registro habitual.»* Sin interpretar, sin
pronosticar, sin sugerir causa.

Los recursos de ayuda están **siempre accesibles**, en un sitio fijo. Nunca aparecen porque
el sistema haya "detectado" algo: eso ya sería la interpretación que acabamos de prohibir, y
además convertiría un patrón en un diagnóstico delante de alguien que no lo ha pedido.

---

## 4. Las tres pantallas

### Captura — la más importante del producto

Fecha · saludo · toque opcional 1–5 · botón de grabar · último registro. **Nada más.**

Si grabar da pereza no hay datos, y sin datos las fases 6 a 11 no existen. Es la única
pantalla donde un segundo de fricción se traduce en meses de historial perdido. La regla no
es "hazla bonita": es **resistir la tentación de añadirle cosas**.

El toque de 1–5 es opcional y se ve que lo es. Es la única señal cuantitativa que no pasa por
un LLM, y sirve para auditar al extractor — pero si se vuelve obligatorio, deja de tocarse
con sinceridad.

### Revisión — la interacción más delicada

Máximo seis tarjetas, cada una con **la cita literal** que la originó. El botón grande es
*confirmar*; corregir es el camino secundario. Saltársela es legítimo.

Y la pregunta que el sistema no puede adivinar, planteada sin juicio:

> *Esta mañana dijiste que estabas fatal. Ahora dices que no tanto.*
> **[ Me equivoqué ]  [ Ahora lo veo distinto ]**

Nunca «¿cuál es la correcta?». Las dos lo son.

### Gráficas — la incertidumbre se ve

- **Los huecos no se interpolan.** Se dibujan como hueco, etiquetados.
- **Dicho por ti / deducido por la IA** se distingue con relleno (punto sólido contra punto
  hueco), no con color: funciona en ambos temas y con daltonismo, y no gasta colores.
- Toda cifra lleva N, período y cobertura.
- **«Aún no hay datos suficientes» es un estado visual de primera clase** (`.empty`), no una
  gráfica vacía con aspecto de conclusión.
- La resolución temporal del eje nunca supera la `time_precision` de los datos.

---

## 5. Mobile-first, sin excepciones

**Todo se diseña primero para 375px de ancho. Todo, incluido el análisis.**

Alguien abrirá el portátil alguna vez y ahí debe verse mejor, pero lo que se garantiza y lo
que se dibuja primero es el móvil.

La restricción no es un peaje: **empuja hacia el diseño honesto**. En 375px no cabe un panel
con veinte correlaciones, así que no se puede construir el volcado de hallazgos que
`00-critica-y-decisiones.md` §A1 quería evitar. Cabe un hallazgo, con su N, su período y su
matiz. Justo lo que hay que enseñar.

| Regla | Motivo |
|---|---|
| Nunca una matriz de correlaciones | No cabe, y no debería existir igualmente |
| Una variable por gráfica; sin leyendas multiserie | Comparar se hace alternando, no superponiendo |
| Agregado semanal por defecto, se baja a día | 90 días en 375px son 4px por día |
| El hallazgo es la frase; la gráfica es la prueba | Frase + minigráfica gana a gráfica grande |
| Antes/después apilados, nunca lado a lado | |
| Tocar, no pasar el ratón | Nada de información escondida en `hover` |
| El calendario de estado sí funciona | Siete columnas encajan de forma natural |

En pantalla ancha se **aprovecha** el espacio (más días visibles, comparación en paralelo),
nunca se estira el diseño de móvil. Y hurgar libremente entre las 40 variables seguirá siendo
una actividad de pantalla grande: el móvil sirve conclusiones, no exploración.

---

## 6. Instalación

PWA: manifest, service worker e icono en la pantalla de inicio. No es un extra — el bucle de
captura muere con la fricción, y un icono es la diferencia entre tres toques y ocho. Además
evita la tienda de aplicaciones, que en un producto como este significa un intermediario
menos viendo que te lo has instalado.

En iOS hay que añadirlo a mano desde Safari, así que hace falta un paso de onboarding que lo
explique: nadie lo descubre solo.

**Notificaciones: fuera de la Fase 1.** Un recordatorio diario roza la gamificación que
prohíbe §3. Si grabas porque te avisan y no porque tengas algo que contar, el registro cambia
de naturaleza. Se decide con datos reales delante.

---

## 7. Accesibilidad

- Contraste mínimo AA en ambos temas. `--ink-soft` sobre `--ground` está en ~5:1.
- Ningún significado transmitido solo por color.
- Los iconos decorativos van con `aria-hidden`; los que informan, con `aria-label`.
- `:focus-visible` siempre visible, con el acento.
- Se respeta `prefers-reduced-motion`.
- Objetivos táctiles de 44px como mínimo; el botón de grabar es de 96px.
