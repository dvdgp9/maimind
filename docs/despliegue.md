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

## Pendiente

- Cron de purga de audio a los 30 días (necesita la tarea 1.2).
- Worker de la cola (tarea 1.3).
