# Pendiente (Cosmo)

- **Publicar la 0.4.0** (Nebula como aplicación: alta, actualización y copias; probada en local el 2026-11-05):
  commit, `git tag -a v0.4.0 -m "Cosmo 0.4.0"` y push. En el servidor: `config/config.php`, `VERSION`, `CHANGELOG.md`
  y `README.md` (no `config.local.php`, que tiene la clave de los tokens).
- **Instalar Nebula y Neutron desde Cosmo**: cada una necesita su `bin/cosmo-setup.php` (como Quasar 0.10.0) y
  añadirla en `AppTemplates::PROVISIONABLE` / `PROVISION_MIN`.
- **Log revisado** (idea, no confirmada): marcar el log de una instalación como revisado hasta una hora, para que el
  aviso de errores de las últimas 24 h desaparezca al momento cuando ya se ha solucionado.
