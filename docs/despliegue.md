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

## Pendiente

- Nada del despliegue. Lo siguiente es la tarea 1.5 (PWA instalable), que añade
  el service worker: hasta entonces, abrir la aplicación **sin red** no funciona
  aunque la cola de grabaciones sí.
