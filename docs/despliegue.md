# Despliegue

Producción: **https://maimind.iaiapro.com** · `mail.claara.tech` · HestiaCP · PHP 8.3 · MariaDB 11.4

```
/home/dvdgp/web/maimind.iaiapro.com/
└── public_html/          ← el repositorio entero
    ├── public/           ← docroot (CUSTOM_DOCROOT de Hestia)
    ├── storage/          ← audio y logs, FUERA del docroot
    └── .env              ← 600, nunca en git
```

## Desplegar

```bash
ssh iaiapro
sudo bash /home/dvdgp/web/maimind.iaiapro.com/public_html/bin/deploy
```

## El despliegue pide un usuario de GitHub

Si `bin/deploy` corta en `==> Código` con esto:

```
fatal: could not read Username for 'https://github.com'
fatal: expected flush after ref listing
```

**no es un problema de credenciales** —el repositorio es público y `curl` llega
sin autenticarse—: es la negociación **HTTP/2** con GitHub, que falla de forma
intermitente en esta máquina. La segunda línea es la pista real; la primera
manda a buscar por donde no es.

`bin/deploy` ya fuerza HTTP/1.1. Para un `git` a mano:

```bash
git -c http.version=HTTP/1.1 pull --ff-only origin main
```

## Las dos trampas de Hestia

Ambas costaron un rato y volverán a aparecer si alguien **reconstruye el dominio**
desde el panel. Si tras un cambio en Hestia la web da 500 o 404, es una de estas.

### 1. `open_basedir` se queda apuntando al docroot

Al fijar el docroot con `v-change-web-domain-docroot`, Hestia escribe en el pool de
PHP-FPM un `open_basedir` que termina en `public_html/public`. PHP entonces no puede
leer `src/`, `vendor/` ni `storage/`, y **todo da 500**.

Tiene que apuntar a la raíz de la aplicación:

```bash
sudo sed -i 's|public_html/public|public_html|' \
  /etc/php/8.3/fpm/pool.d/maimind.iaiapro.com.conf
sudo systemctl reload php8.3-fpm
```

Comprobación: `grep open_basedir` debe terminar en `public_html`, no en `public_html/public`.
El dominio `mapas.iaiapro.com` usa el mismo patrón y sirve de referencia.

### 2. El docroot debe colgar de `public_html`

`v-change-web-domain-docroot` rechaza cualquier ruta que no esté dentro de
`public_html/`. Por eso el repositorio **es** `public_html`, y el docroot es
`public_html/public`. Un symlink en `public_html` no funciona: Hestia genera el
vhost apuntando al dominio equivocado.

```bash
sudo /usr/local/hestia/bin/v-change-web-domain-docroot \
  dvdgp maimind.iaiapro.com maimind.iaiapro.com public
```

## DNS

El DNS de `iaiapro.com` **no** está en este servidor: lo llevan los nameservers de
LucusHost. Los subdominios se dan de alta allí, no en Hestia.

```
maimind.iaiapro.com    A    91.98.155.109
```

## Certificado

Requiere que el DNS resuelva primero:

```bash
sudo /usr/local/hestia/bin/v-add-letsencrypt-domain dvdgp maimind.iaiapro.com
```

**HTTPS no es opcional**: `getUserMedia` solo funciona en contexto seguro, así que sin
certificado la aplicación no puede grabar, que es lo único que hace.

## Worker de la cola

El worker procesa la cola `jobs`: hoy, la purga de audio; a partir de la fase 2,
la transcripción y la extracción.

```bash
sudo cp deploy/maimind-worker.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now maimind-worker
sudo journalctl -u maimind-worker -f
```

El proceso **sale solo** al llegar a `WORKER_MAX_JOBS_PER_RUN` y systemd lo
vuelve a levantar. No es un fallo: un PHP que vive semanas acumula memoria.

Concurrencia 1, `Nice=10` e `IOSchedulingClass=idle`: la máquina comparte dos
núcleos con el correo y con iaiaPRO, y el worker no puede competir con ellos.

Inspección desde la línea de órdenes:

```bash
php bin/jobs            # resumen y últimos trabajos
php bin/jobs dead       # los que ya no vuelven solos, con su error
php bin/jobs retry 42   # devolver uno a la cola
php bin/worker --once   # vaciar la cola a mano y salir
```

## Cron

Una entrada al día. Encola la purga de audio de cada usuario y borra los
trabajos ya hechos de hace más de una semana:

```
17 4 * * * cd /home/dvdgp/web/maimind.iaiapro.com/public_html && /usr/bin/php8.3 bin/cron diario >> storage/logs/cron.log 2>&1
```

El cron **no purga**: encola. Así la purga tiene una sola implementación —el
manejador del worker, con sus reintentos y su registro— en vez de dos que se
van separando con el tiempo. Lanzarlo dos veces el mismo día no encola nada la
segunda vez.

## Service worker: no hay nada que recordar

`resources/sw.js` **no es un fichero estático**: lo sirve PHP desde `/sw.js`
sustituyendo `__VERSION__` por una huella del contenido de todo lo que ese
worker cachea (`src/Support/AssetVersion.php`).

Es decir: tocar cualquier cosa de `public/assets/` invalida sola la caché de los
móviles ya instalados. No hay número que subir a mano. Y como la huella es del
contenido y no de la fecha, dos despliegues del mismo código no tiran la caché
de nadie.

Los iconos van versionados en `public/icons/`, así que el despliegue no tiene
que generarlos. Si cambia la paleta:

```bash
php bin/icons
```

## Probar

```bash
composer test        # PHPUnit y los tests del service worker
composer check       # comprobación de entorno
```

Los del service worker corren con Node (`node --test`) porque PHPUnit no puede
ejecutar JavaScript, y lo que decide ese fichero —qué cachea y qué borra al
cerrar sesión— no se puede comprobar buscando cadenas.

## OpenRouter: la parte que hay que hacer a mano

El código manda `data_collection: "deny"` y `zdr: true` en **cada** petición, y
`bin/check` falla si esa política se afloja. Con eso MaiMind está cubierta.

**Pendiente a propósito**: activarlo también en la cuenta. Es la red que cubriría
una petición que se escapara del código. Aplazado porque esa cuenta la comparten
otras APIs y restringir el enrutado a nivel de cuenta podría afectarlas.

> openrouter.ai → **Settings → Privacy** → desactivar los endpoints que
> entrenan o retienen datos.

Las dos se combinan con un OR y solo pueden restringir más, así que activarlo en
la cuenta no puede romper nada. Detalle en `docs/api/openrouter.md` §4.

## Transcripción: lo que hay que poner en el `.env` de producción

```
OPENROUTER_API_KEY=sk-or-...
TRANSCRIPTION_DRIVER=openrouter
OPENROUTER_TRANSCRIPTION_MODEL=openai/whisper-large-v3-turbo
```

Sin la clave, `bin/check` avisa y los trabajos `transcribe` mueren en el primer
intento con «Falta OPENROUTER_API_KEY» — a propósito: reintentarlo cinco veces
no hace aparecer una clave.

Para desarrollar sin gastar, `TRANSCRIPTION_DRIVER=fake` transcribe con un texto
de mentira. Las filas que deja llevan `provider = 'fake'` y coste 0, así que no
se pueden confundir con las de verdad al sumar gastos.

**El worker hay que reiniciarlo** al cambiar cualquiera de estas variables: lleva
el proveedor construido en memoria. `bin/deploy` ya lo hace.

## Pendiente

- Nada del despliegue. Lo siguiente es la fase 3 (extracción).
