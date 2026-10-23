# Cosmo

Panel central para controlar las instalaciones de **Quasar**: ver en qué versión está cada una y cómo
están, actualizarlas desde GitHub, hacer y guardar copias de seguridad y saber quién hizo qué.

PHP 8.1+ sin dependencias y **sin base de datos**: los datos son ficheros JSON en `storage/`.
Habla con cada Quasar por su API remota (`?r=remote`, Quasar 0.2.0 o superior).

## Qué hace

- **Panel de instalaciones**: estado (responde / caída), versión y si hay una más nueva, PHP, última copia,
  errores del log en 24 h y avisos. Búsqueda, filtros y operaciones sobre varias a la vez
  (actualizar, copia, comprobar).
- **Avisos de salud** por instalación: caída, BD sin conexión, modo mantenimiento, `debug` activado, sin HTTPS,
  correo en modo `log`, errores en el log, sin copias o copias antiguas, ficheros del núcleo cambiados a mano,
  poco disco, sin permiso de escritura, extensiones que faltan, PHP sin soporte, reloj desfasado.
- **Actualizaciones** desde las etiquetas `vX.Y.Z` de GitHub, con el CHANGELOG de lo que cambia. Copia previa,
  mantenimiento, autoprueba con vuelta atrás automática, migración de BD y comprobación final.
- **Copias de seguridad** completas, de BD o de ficheros: se crean en la instalación y se pueden descargar o
  guardar en Cosmo (segunda ubicación, con sha256 verificado). Copias programadas diarias o semanales.
- **Filtrado con IA** (Quasar 0.5.0+): si cada instalación lo tiene activado, modelo, gasto del mes respecto a su
  límite, consultas y errores. Total del mes en el panel, filtro «Con IA» y avisos al 80 % y al agotar el límite.
- **Log** e **integridad** de cada instalación, **mantenimiento** on/off y **disponibilidad** (uptime y tiempo
  de respuesta de 7 días).
- **Actividad**: registro de todas las acciones (usuario, IP, resultado), filtrable.
- **Avisos por webhook** (Slack/Mattermost, Discord, ntfy o JSON) cuando una instalación cae o se recupera,
  o falla una copia o una actualización.
- Usuarios en la configuración, con tres roles.

## Instalación

```bash
php bin/setup.php                  # comprueba el entorno y crea config/config.local.php
php -S localhost:8080 -t public    # en producción, DocumentRoot → public/
```

`setup.php` genera la **clave de cifrado** de los tokens (`key`) y el primer usuario administrador.
Guarda una copia de `config/config.local.php`: sin la clave hay que volver a introducir los tokens.

Requiere las extensiones `curl`, `zip`, `mbstring`, `json` y `sodium` u `openssl` (para cifrar los tokens). `storage/` necesita permiso de
escritura y debe quedar fuera de la web (si el hosting no permite apuntar a `public/`, el `.htaccess` de la
raíz lo redirige todo allí).

### Cron

Comprobaciones y copias programadas:

```cron
*/10 * * * * php /ruta/a/cosmo/bin/cron.php --quiet
```

`--check` solo comprueba, `--backups` solo hace las copias programadas. Nunca corren dos a la vez.

### GitHub

El repositorio de Quasar es privado: crea un token *fine-grained* con acceso solo a ese repositorio y
permiso **Contents: Read-only**, y ponlo en `config.local.php`:

```php
'github' => ['repo' => 'jprojects/quasar', 'token' => 'github_pat_…'],
```

Las etiquetas y el CHANGELOG se guardan 10 minutos en caché (`github.cache_ttl`); los paquetes descargados
se guardan en `storage/packages` (una etiqueta publicada no cambia).

## Usuarios

En `config/config.local.php`:

```php
'users' => [
    'kim'  => ['name' => 'Kim',  'email' => 'kim@example.com', 'password' => '$2y$…', 'role' => 'admin'],
    'joan' => ['name' => 'Joan', 'password' => '$2y$…', 'role' => 'operator'],
    'anna' => ['name' => 'Anna', 'password' => '$2y$…', 'role' => 'viewer', 'active' => false],
],
```

El hash se genera con `php bin/hash-password.php`. Quitar un usuario de la lista (o `active => false`) cierra
su sesión en la siguiente petición.

| Rol | Puede |
|---|---|
| `admin` | todo: dar de alta, editar y eliminar instalaciones |
| `operator` | comprobar, copias, actualizar, migrar y mantenimiento |
| `viewer` | solo consultar |

Tras `login_max_attempts` fallos (por usuario o por IP) el acceso se bloquea `login_lockout` segundos.

## Dar de alta una instalación

1. En el Quasar, en `config/config.local.php` (el botón **Generar** del formulario de Cosmo crea un token y
   enseña el fragmento a pegar):

   ```php
   'remote' => ['token' => '…64 caracteres…', 'allow_ips' => ['IP del servidor de Cosmo']],
   ```

2. En Cosmo: **Nueva instalación**, con la URL donde se abre Quasar y el mismo token. **Probar conexión**
   lo comprueba sin guardar.

Las instalaciones con Quasar 0.1.x se ven (versión y estado básico), pero para copias y actualizaciones hay
que subirlas a mano a la 0.2.0 la primera vez.

## Cómo es una actualización

Desde la ficha (o varias a la vez desde el panel), Cosmo:

1. Pide el estado y comprueba que la versión destino tiene sentido (no es la misma, no es anterior salvo que
   se permita, no baja de la 0.2.0, el paquete cabe en el `upload_max_filesize` del hosting).
2. Descarga de GitHub el ZIP de la etiqueta y comprueba que su `VERSION` coincide.
3. Lo sube a la instalación, que hace la copia previa, entra en mantenimiento, copia solo los ficheros del
   núcleo (`core.json` de Quasar), se autoprueba y, si la versión nueva no arranca, lo deshace todo.
4. Pide la migración de la BD (con el código nuevo) y la instalación sale del mantenimiento.
5. Vuelve a comprobar el estado y confirma la versión.

Si la migración falla, la instalación se queda en mantenimiento y la ficha ofrece **Reintentar migración**.
Si alguien cambió a mano ficheros del núcleo, la actualización se detiene y los enumera; se puede forzar.

## Estructura

```
config/            config.php (+ config.local.php: clave, usuarios, token de GitHub; no versionar)
bin/               setup.php · hash-password.php · cron.php
public/index.php   único punto de entrada
src/Core/          App (rutas), Auth, Session, View, Store (JSON), Crypto, Log
src/Services/      Installations, QuasarClient, GitHub, Monitor, Health, Operations, LocalBackups, Activity, Notifier
src/Controllers/   una clase por pantalla
templates/         plantillas PHP, assets/ (CSS y JS sin dependencias) e icons.php (Lucide)
storage/data/      installations.json (tokens cifrados), status/, history/, activity.jsonl, schedule.json
storage/backups/   copias guardadas en Cosmo, por instalación
storage/packages/  ZIP de cada versión de Quasar
storage/cache/     etiquetas y CHANGELOG de GitHub
storage/logs/      log de Cosmo
```

## Configuración

Ver `config/config.php`. Lo más habitual en `config.local.php`:

```php
return [
    'key' => '…',                                    // la genera bin/setup.php
    'users' => [ … ],
    'github' => ['token' => 'github_pat_…'],
    'notify' => ['webhook' => 'https://hooks.slack.com/services/…', 'format' => 'slack'],
    'monitor' => ['stale_backup_days' => 3],
    'backups' => ['local_keep' => 14],
];
```

## Seguridad

- Los tokens de las instalaciones se guardan cifrados (libsodium, o AES-256-GCM de OpenSSL si no está) con la clave de `config.local.php`.
- Todas las acciones exigen sesión, el rol adecuado y token CSRF; cada una queda en la actividad.
- Las peticiones a las instalaciones verifican el certificado SSL (se puede desactivar por instalación) y no
  siguen redirecciones.
- Cabeceras de seguridad y CSP estricta (sin scripts en línea).
- Restringe la API de cada Quasar a la IP de Cosmo con `remote.allow_ips`.
