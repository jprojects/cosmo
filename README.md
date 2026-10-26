# Cosmo

Panel central para controlar las instalaciones de **Quasar**, **Neutron** y las aplicaciones que vengan: ver
en qué versión está cada una y cómo están, actualizarlas desde GitHub, hacer y guardar copias de seguridad y
saber quién hizo qué.

PHP 8.1+ sin dependencias y **sin base de datos**: los datos son ficheros JSON en `storage/`.
Habla con cada instalación por su API remota (`?r=remote`): la definió Quasar (0.2.0 o superior) y Neutron
(1.0.0 o superior) la replica con las mismas acciones y respuestas.

## Qué hace

- **Panel de instalaciones**: aplicación y versión (y si hay una más nueva de esa aplicación), estado
  (responde / caída), PHP, última copia, errores del log en 24 h y avisos. Búsqueda, filtros (también por
  aplicación) y operaciones sobre varias a la vez (actualizar cada una a la última de su aplicación, copia,
  comprobar).
- **Avisos de salud** por instalación: caída, BD sin conexión, modo mantenimiento, `debug` activado, sin HTTPS,
  correo en modo `log`, errores en el log, sin copias o copias antiguas, ficheros del núcleo cambiados a mano,
  poco disco, sin permiso de escritura, extensiones que faltan, PHP sin soporte, reloj desfasado. En Neutron,
  además: formulario sin reCAPTCHA, sin contraseña del editor y `site/` sin permiso de escritura. Si una
  instalación responde como otra aplicación distinta de la dada de alta, se avisa y no se actualiza.
- **Actualizaciones** desde las etiquetas `vX.Y.Z` de GitHub, con el CHANGELOG de lo que cambia. Copia previa,
  mantenimiento, autoprueba con vuelta atrás automática, migración de BD y comprobación final.
- **Copias de seguridad** de los tipos de cada aplicación (Quasar: completa, BD o ficheros; Neutron: completa o
  solo el contenido de `site/`): se crean en la instalación y se pueden descargar o
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

### Aplicaciones y GitHub

Las aplicaciones que se pueden dar de alta están en `apps` (`config/config.php`): nombre, repositorio de GitHub,
primera versión con API remota (`min_version`) y tipos de copia (`backups`). La clave es el identificador que
la aplicación devuelve en su estado (`"app": "neutron"`). Para añadir una nueva basta con una entrada más,
siempre que su API remota replique la de Quasar.

Si los repositorios son privados, crea un token *fine-grained* con acceso a ellos y permiso
**Contents: Read-only**, y ponlo en `config.local.php`:

```php
'github' => ['token' => 'github_pat_…'],
'apps' => ['neutron' => ['repo' => 'otra-cuenta/neutron']],   // solo si cambia algo de config.php
```

(El antiguo `github.repo` se sigue respetando como repositorio de Quasar.)

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

1. En la instalación, en su `config.local.php` (`config/config.local.php` en Quasar; el botón **Generar** del
   formulario de Cosmo crea un token y enseña el fragmento a pegar):

   ```php
   'remote' => ['token' => '…64 caracteres…', 'allow_ips' => ['IP del servidor de Cosmo']],
   ```

2. En Cosmo: **Nueva instalación**, con la aplicación, la URL donde se abre y el mismo token. **Probar
   conexión** lo comprueba sin guardar y elige la aplicación que detecta.

Las instalaciones dadas de alta antes de que hubiera varias aplicaciones son de Quasar.

Las instalaciones con Quasar 0.1.x se ven (versión y estado básico), pero para copias y actualizaciones hay
que subirlas a mano a la 0.2.0 la primera vez.

## Cómo es una actualización

Desde la ficha (o varias a la vez desde el panel), Cosmo:

1. Pide el estado y comprueba que la versión destino tiene sentido (no es la misma, no es anterior salvo que
   se permita, no baja de su `min_version`, la instalación es de la aplicación dada de alta, el paquete cabe
   en el `upload_max_filesize` del hosting).
2. Descarga de GitHub el ZIP de la etiqueta del repositorio de su aplicación y comprueba que su `VERSION` coincide.
3. Lo sube a la instalación, que hace la copia previa, entra en mantenimiento, copia solo los ficheros del
   núcleo (su `core.json`), se autoprueba y, si la versión nueva no arranca, lo deshace todo.
4. Pide la migración (BD en Quasar; carpetas de datos en Neutron) y la instalación sale del mantenimiento.
5. Vuelve a comprobar el estado y confirma la versión.

Si la migración falla, la instalación se queda en mantenimiento y la ficha ofrece **Reintentar migración**.
Si alguien cambió a mano ficheros del núcleo, la actualización se detiene y los enumera; se puede forzar.

## Estructura

```
config/            config.php (+ config.local.php: clave, usuarios, token de GitHub; no versionar)
bin/               setup.php · hash-password.php · cron.php
public/index.php   único punto de entrada
src/Core/          App (rutas), Auth, Session, View, Store (JSON), Crypto, Log
src/Services/      Apps, Installations, QuasarClient, GitHub, Monitor, Health, Operations, LocalBackups, Activity, Notifier
src/Controllers/   una clase por pantalla
templates/         plantillas PHP, assets/ (CSS y JS sin dependencias) e icons.php (Lucide)
storage/data/      installations.json (tokens cifrados), status/, history/, activity.jsonl, schedule.json
storage/backups/   copias guardadas en Cosmo, por instalación
storage/packages/  ZIP de cada versión ({aplicación}-X.Y.Z.zip)
storage/cache/     etiquetas y CHANGELOG de GitHub, por aplicación
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
- Restringe la API de cada instalación a la IP de Cosmo con `remote.allow_ips`.
