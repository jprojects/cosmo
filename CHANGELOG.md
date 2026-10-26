# Cambios

Formato: una sección por versión, la más reciente arriba. Cada versión se etiqueta en git como `vX.Y.Z`.

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
