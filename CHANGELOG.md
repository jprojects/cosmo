# Cambios

Formato: una sección por versión, la más reciente arriba. Cada versión se etiqueta en git como `vX.Y.Z`.

## 0.3.0 — 2026-10-28

Instalaciones nuevas desde Cosmo, por FTP.

- **Servidores**: el FTP de cada cuenta de hosting (contraseña cifrada, FTPS), con prueba de conexión.
- **Plantilla de configuración** de Quasar para las instalaciones nuevas: idioma, con qué se entra, verificación en
  dos pasos, SMTP, límite de la IA y tablas de informes.
- **Nueva instalación**: con el dominio y la base de datos ya creados en el hosting, Cosmo comprueba la carpeta y el
  HTTPS, sube el paquete y un instalador de un solo uso, instala (configuración, tablas, administrador con
  contraseña generada), da de alta la instalación, la comprueba y hace la primera copia. Vuelta atrás si falla.
  Explorador de carpetas del FTP. Requiere Quasar 0.10.0 o superior.
- Confirmación genérica `data-confirm` en formularios y botones; copiar al portapapeles.

## 0.2.1 — 2026-10-28

Compatibilidad con PHP 8.5.

- Fuera las llamadas a `curl_close()` (sin efecto desde PHP 8.0 y obsoleta en 8.5): con PHP 8.5 la página de
  versiones, las comprobaciones de las instalaciones y los avisos daban error 500.
- Los avisos de obsolescencia de PHP se anotan en el log como `WARNING` y ya no interrumpen la página; el resto de
  avisos y errores sigue igual.

## 0.2.0 — 2026-10-26

Varias aplicaciones: Quasar y Neutron (y las que repliquen su API remota).

- Config `apps` (nombre, repositorio de GitHub, primera versión con API remota y tipos de copia de cada una), con
  Quasar y Neutron de serie. `github.repo` de las configuraciones anteriores sigue valiendo como repositorio de Quasar.
- Cada instalación tiene su aplicación (las ya dadas de alta pasan a Quasar). Al probar la conexión se detecta por el
  `app` del estado; si una instalación responde como otra aplicación, se avisa y no se actualiza.
- Panel: aplicación y versión de cada instalación, última versión por aplicación, filtro por aplicación y
  «Actualizar a la última» lleva cada una a la última de la suya. Página de versiones por aplicación.
- Copias con los tipos de cada aplicación (Neutron: completa o solo el contenido de `site/`).
- Avisos de Neutron: formulario sin reCAPTCHA, sin contraseña del editor y `site/` sin permiso de escritura.
- Caché de GitHub y paquetes descargados por aplicación (`github-{app}-…`, `{app}-X.Y.Z.zip`).

## 0.1.0 — 2026-10-23

Primera versión: panel central de instalaciones de Quasar (estado, avisos de salud, actualizaciones desde GitHub con
copia previa, autoprueba y vuelta atrás, copias de seguridad locales y en Cosmo, copias programadas, log,
integridad, mantenimiento, disponibilidad, actividad, avisos por webhook y usuarios con roles).
