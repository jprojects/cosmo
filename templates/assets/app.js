/* Cosmo · comportamiento del panel. Sin dependencias. */
(() => {
  'use strict';

  const $ = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));
  const csrf = () => $('meta[name="csrf-token"]')?.content || '';

  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const icon = (name, cls = '') => `<svg class="cs-icon ${cls}" aria-hidden="true"><use href="#cs-i-${name}"></use></svg>`;

  const bytes = (n) => {
    if (n == null) return '—';
    const u = ['B', 'KB', 'MB', 'GB', 'TB'];
    let i = 0;
    while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
    return (i === 0 ? n : n.toLocaleString('es-ES', { maximumFractionDigits: n >= 100 ? 0 : 1 })) + ' ' + u[i];
  };
  const when = (iso) => {
    if (!iso) return '—';
    const d = new Date(iso);
    return d.toLocaleDateString('es-ES', { day: '2-digit', month: '2-digit', year: 'numeric' }) + ' ' +
      d.toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit' });
  };

  /** Petición al panel: devuelve el JSON; lanza Error con el mensaje del servidor si falla. */
  async function api(url, data = null) {
    const opts = { headers: { Accept: 'application/json', 'X-Requested-With': 'fetch', 'X-CSRF-Token': csrf() } };
    if (data !== null) {
      opts.method = 'POST';
      const body = new URLSearchParams();
      body.set('_csrf', csrf());
      for (const [k, v] of Object.entries(data)) {
        if (Array.isArray(v)) v.forEach((x) => body.append(k + '[]', x));
        else if (v !== undefined && v !== null) body.set(k, v);
      }
      opts.body = body;
    }
    let res;
    try {
      res = await fetch(url, opts);
    } catch (e) {
      throw new Error('Sin conexión con Cosmo');
    }
    let json = null;
    try { json = await res.json(); } catch (e) { /* no JSON */ }
    if (!res.ok && (!json || !json.steps)) {
      throw new Error(json?.error || `Error ${res.status}`);
    }
    return json;
  }

  function toast(type, text, ms = 6000) {
    const box = $('#cs-toasts');
    if (!box) return;
    const el = document.createElement('div');
    el.className = `cs-toast cs-toast-${type}`;
    const ic = { success: 'circle-check', error: 'circle-x', warning: 'triangle-alert' }[type] || 'info';
    el.innerHTML = `${icon(ic, 'cs-c-' + (type === 'error' ? 'danger' : type))}<span>${esc(text)}</span>`;
    box.appendChild(el);
    setTimeout(() => el.remove(), ms);
  }

  /** Diálogo de confirmación. Devuelve una promesa con true/false. */
  function confirmDialog(title, text, okLabel = 'Aceptar', danger = false) {
    return new Promise((resolve) => {
      const dlg = document.createElement('dialog');
      dlg.className = 'cs-dialog';
      dlg.innerHTML = `<div class="cs-dialog-head"><h2>${esc(title)}</h2></div>
        <div class="cs-dialog-body"><p>${esc(text)}</p></div>
        <div class="cs-dialog-foot"><button class="cs-btn" type="button" value="no">Cancelar</button>
        <button class="cs-btn ${danger ? 'cs-btn-danger' : 'cs-btn-primary'}" type="button" value="yes">${esc(okLabel)}</button></div>`;
      document.body.appendChild(dlg);
      dlg.addEventListener('click', (e) => {
        const b = e.target.closest('button');
        if (b) dlg.close(b.value);
      });
      dlg.addEventListener('close', () => { resolve(dlg.returnValue === 'yes'); dlg.remove(); });
      dlg.showModal();
      $('button[value="yes"]', dlg).focus();
    });
  }

  function busy(btn, on, label) {
    if (!btn) return;
    if (on) {
      btn.dataset.html = btn.innerHTML;
      btn.disabled = true;
      btn.innerHTML = icon('loader-circle', 'cs-spin') + (label ? ' ' + esc(label) : '');
    } else if (btn.dataset.html !== undefined) {
      btn.disabled = false;
      btn.innerHTML = btn.dataset.html;
      delete btn.dataset.html;
    }
  }

  const stepsHtml = (steps) => steps.map((s) =>
    `<li>${icon(s.ok ? 'circle-check' : 'circle-x', s.ok ? 'cs-c-success' : 'cs-c-danger')}<span>${esc(s.text)}</span></li>`).join('');

  // ---------------------------------------------------------------- general

  // Confirmación antes de enviar: data-confirm en el formulario o en el botón que lo envía
  document.addEventListener('submit', (e) => {
    const form = e.target;
    const btn = e.submitter;
    const text = btn?.dataset.confirm || form.dataset.confirm;
    if (!text || form.dataset.confirmed) return;
    e.preventDefault();
    e.stopImmediatePropagation();
    const danger = btn?.classList.contains('cs-btn-danger');
    confirmDialog('Confirmar', text, btn ? btn.textContent.trim() : 'Aceptar', danger).then((ok) => {
      if (!ok) return;
      form.dataset.confirmed = '1';
      form.requestSubmit(btn || undefined);
      delete form.dataset.confirmed;
    });
  }, true);

  document.addEventListener('submit', (e) => {
    const form = e.target.closest('form[data-busy]');
    if (form) busy($('button[type="submit"]', form), true, form.dataset.busy);
  });

  // Copiar al portapapeles: [data-copy] copia el [data-copy-text] de su mismo contenedor
  document.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-copy]');
    if (!b) return;
    const text = $('[data-copy-text]', b.parentElement)?.textContent.trim() || '';
    try {
      await navigator.clipboard.writeText(text);
      toast('success', 'Copiado');
    } catch {
      toast('error', 'No se ha podido copiar: selecciónalo a mano');
    }
  });

  document.addEventListener('click', (e) => {
    const open = e.target.closest('[data-open-dialog]');
    if (open) document.getElementById(open.dataset.openDialog)?.showModal();
    const close = e.target.closest('[data-close-dialog]');
    if (close) close.closest('dialog')?.close();
  });

  // ---------------------------------------------------------------- panel: filtros y selección

  const list = $('[data-installations]');
  if (list) {
    const rows = $$('[data-row]', list);
    const text = $('[data-filter-text]', list);
    const kind = $('[data-filter-kind]', list);
    const count = $('[data-filter-count]', list);
    const bar = $('[data-bulkbar]', list);
    const all = $('[data-check-all]', list);

    const apply = () => {
      const q = text.value.trim().toLowerCase();
      const k = kind.value;
      let n = 0;
      rows.forEach((r) => {
        const show = (!q || r.dataset.search.includes(q)) && (!k || r.dataset.kinds.split(' ').includes(k));
        r.hidden = !show;
        if (!show) { const c = $('[data-check]', r); if (c) c.checked = false; }
        n += show ? 1 : 0;
      });
      count.textContent = n === rows.length ? '' : `${n} de ${rows.length}`;
      syncBar();
      try { sessionStorage.setItem('cs-filter', JSON.stringify({ q: text.value, k })); } catch (e) { /* sin almacenamiento */ }
    };
    const selected = () => rows.filter((r) => $('[data-check]', r)?.checked);
    const syncBar = () => {
      if (!bar) return;
      const n = selected().length;
      bar.hidden = n === 0;
      $('[data-bulk-count]', bar).textContent = n === 1 ? '1 seleccionada' : `${n} seleccionadas`;
      if (all) all.checked = n > 0 && n === rows.filter((r) => !r.hidden).length;
    };

    try {
      const saved = JSON.parse(sessionStorage.getItem('cs-filter') || 'null');
      if (saved) { text.value = saved.q || ''; kind.value = saved.k || ''; }
    } catch (e) { /* nada */ }
    text.addEventListener('input', apply);
    kind.addEventListener('change', apply);
    list.addEventListener('change', (e) => { if (e.target.matches('[data-check]')) syncBar(); });
    all?.addEventListener('change', () => {
      rows.forEach((r) => { const c = $('[data-check]', r); if (c && !r.hidden) c.checked = all.checked; });
      syncBar();
    });
    apply();

    // Operaciones sobre varias instalaciones, una detrás de otra
    const dlg = $('[data-bulk-dialog]');
    bar?.addEventListener('click', async (e) => {
      const b = e.target.closest('[data-bulk]');
      if (!b) return;
      const op = b.dataset.bulk;
      const targets = selected();
      if (op === 'clear') { targets.forEach((r) => { $('[data-check]', r).checked = false; }); syncBar(); return; }
      if (op === 'check') {
        busy(b, true);
        try {
          const r = await api('index.php?r=check', { ids: targets.map((t) => t.dataset.id) });
          toast(r.failed ? 'warning' : 'success', `${r.checked} comprobadas${r.failed ? `, ${r.failed} no responden` : ''}`);
          location.reload();
        } catch (err) { toast('error', err.message); busy(b, false); }
        return;
      }

      const noApi = targets.filter((t) => t.dataset.api !== '1');
      // Al actualizar a la última, cada una va a la de su aplicación: las que ya la tienen se saltan
      const upToDate = op === 'update' ? targets.filter((t) => t.dataset.api === '1' && t.dataset.outdated !== '1') : [];
      const skip = (t) => t.dataset.api !== '1' || upToDate.includes(t);
      const title = op === 'update'
        ? `Actualizar ${targets.length - upToDate.length} a la ${b.dataset.version === 'latest' ? 'última versión' : b.dataset.version}`
        : `Copia ${b.dataset.type === 'db' ? 'de datos' : 'completa'} de ${targets.length}`;
      $('[data-bulk-title]', dlg).textContent = title;
      $('[data-bulk-options]', dlg).innerHTML = (op === 'update'
        ? `<label class="cs-check"><input type="checkbox" data-opt-backup checked> Copia completa antes de cada actualización</label>
           <p class="cs-small cs-muted">Se actualizan de una en una; si una falla, se sigue con la siguiente.</p>`
        : `<label class="cs-check"><input type="checkbox" data-opt-pull checked> Guardar también en Cosmo</label>`)
        + (noApi.length ? `<p class="cs-small cs-c-warning">${noApi.length} se saltarán (no responden o no tienen API remota): ${esc(noApi.map((t) => t.dataset.name).join(', '))}</p>` : '')
        + (upToDate.length ? `<p class="cs-small cs-muted">${upToDate.length} ya están al día: ${esc(upToDate.map((t) => t.dataset.name).join(', '))}</p>` : '');
      const log = $('[data-bulk-log]', dlg);
      log.innerHTML = '';
      const run = $('[data-bulk-run]', dlg);
      const closeBtn = $('[data-bulk-close]', dlg);
      run.hidden = false;
      run.disabled = false;
      dlg.showModal();

      run.onclick = async () => {
        run.disabled = true;
        closeBtn.disabled = true;
        $('[data-bulk-options]', dlg).querySelectorAll('input').forEach((i) => { i.disabled = true; });
        const withBackup = $('[data-opt-backup]', dlg)?.checked;
        const pull = $('[data-opt-pull]', dlg)?.checked;
        let okCount = 0;
        for (const t of targets) {
          if (skip(t)) continue;
          const li = document.createElement('li');
          li.innerHTML = `${icon('loader-circle', 'cs-spin')}<span><strong>${esc(t.dataset.name)}</strong> · en curso…</span>`;
          log.appendChild(li);
          try {
            const r = op === 'update'
              ? await api('index.php?r=update', { id: t.dataset.id, version: b.dataset.version, backup: withBackup ? '1' : '0' })
              : await api('index.php?r=backup-create', { id: t.dataset.id, type: b.dataset.type, pull: pull ? '1' : '0' });
            const last = r.steps.filter((s) => !s.ok).map((s) => s.text).join(' · ') || r.steps[r.steps.length - 1]?.text || '';
            li.innerHTML = `${icon(r.ok ? 'circle-check' : 'circle-x', r.ok ? 'cs-c-success' : 'cs-c-danger')}<span><strong>${esc(t.dataset.name)}</strong> · ${esc(last)}</span>`;
            okCount += r.ok ? 1 : 0;
          } catch (err) {
            li.innerHTML = `${icon('circle-x', 'cs-c-danger')}<span><strong>${esc(t.dataset.name)}</strong> · ${esc(err.message)}</span>`;
          }
        }
        const total = targets.filter((t) => !skip(t)).length;
        const li = document.createElement('li');
        li.innerHTML = `<strong>Terminado: ${okCount} de ${total} correctas</strong>`;
        log.appendChild(li);
        run.hidden = true;
        closeBtn.disabled = false;
        closeBtn.onclick = () => location.reload();
      };
      closeBtn.onclick = () => dlg.close();
    });
  }

  // ---------------------------------------------------------------- ficha de instalación

  const page = $('[data-install]');
  if (page) {
    const id = page.dataset.install;
    const urls = JSON.parse(page.dataset.urls);
    const canOperate = page.dataset.canOperate === '1';

    // Mantenimiento
    $('[data-maintenance]')?.addEventListener('click', async (e) => {
      const b = e.currentTarget;
      const on = b.dataset.maintenance === '1';
      const ok = await confirmDialog(on ? '¿Activar el modo mantenimiento?' : '¿Quitar el modo mantenimiento?',
        on ? 'Los usuarios verán un aviso de mantenimiento y no podrán entrar hasta que lo quites.' : 'Los usuarios podrán volver a entrar.',
        on ? 'Activar' : 'Quitar', on);
      if (!ok) return;
      busy(b, true);
      try {
        await api(urls.maintenance, { id, on: on ? '1' : '0' });
        location.reload();
      } catch (err) { toast('error', err.message); busy(b, false); }
    });

    // Copias
    const box = $('[data-backups]');
    const typeLabel = JSON.parse(page.dataset.backupTypes || '{}');
    const loadBackups = async () => {
      if (!box) return;
      try {
        const r = await api(urls.backups);
        renderBackups(r);
      } catch (err) {
        box.innerHTML = `<div class="cs-empty cs-c-danger">${esc(err.message)}</div>`;
      }
    };
    const renderBackups = (r) => {
      const local = new Set(r.local.map((l) => l.file));
      let html = '';
      if (r.remote_error) {
        html += `<div class="cs-card-body cs-c-danger cs-small">${icon('circle-x')} En la instalación: ${esc(r.remote_error)}</div>`;
      } else if (!r.remote.length) {
        html += '<div class="cs-empty cs-small">No hay copias en la instalación</div>';
      } else {
        html += `<table class="cs-table"><thead><tr><th>En la instalación</th><th>Tipo</th><th class="cs-right">Tamaño</th><th>Versión</th><th></th></tr></thead><tbody>`;
        for (const b of r.remote) {
          html += `<tr><td class="cs-nowrap" title="${esc(b.file)}">${esc(when(b.created_at))}${b.label ? `<div class="cs-row-sub">${esc(b.label)}</div>` : ''}</td>
            <td><span class="cs-badge">${esc(typeLabel[b.type] || b.type)}</span></td>
            <td class="cs-right cs-nowrap">${esc(bytes(b.size))}</td><td>${esc(b.version || '')}</td>
            <td class="cs-right cs-nowrap">${canOperate ? `
              <a class="cs-btn cs-btn-sm cs-btn-ghost cs-btn-icon" href="${esc(urls.backupDownload + '&file=' + encodeURIComponent(b.file))}" title="Descargar">${icon('download')}</a>
              ${local.has(b.file) ? `<span class="cs-badge cs-badge-success" title="Ya está en Cosmo">${icon('check', 'cs-icon-sm')} Cosmo</span>`
                : `<button class="cs-btn cs-btn-sm cs-btn-ghost cs-btn-icon" type="button" data-pull="${esc(b.file)}" title="Guardar en Cosmo">${icon('cloud-download')}</button>`}
              <button class="cs-btn cs-btn-sm cs-btn-ghost cs-btn-icon cs-btn-danger" type="button" data-delete-remote="${esc(b.file)}" title="Borrar de la instalación">${icon('trash-2')}</button>` : ''}
            </td></tr>`;
        }
        html += '</tbody></table>';
        if (r.disk_free) html += `<div class="cs-card-body cs-small cs-muted">Espacio libre en el hosting: ${esc(bytes(r.disk_free))}</div>`;
      }
      if (r.local.length) {
        html += `<table class="cs-table"><thead><tr><th>Guardadas en Cosmo (${esc(bytes(r.local_size))})</th><th></th></tr></thead><tbody>`;
        for (const l of r.local) {
          html += `<tr><td class="cs-mono cs-small">${esc(l.file)}<div class="cs-row-sub">${esc(bytes(l.size))} · guardada ${esc(when(l.saved_at))}</div></td>
            <td class="cs-right cs-nowrap">${canOperate ? `
              <a class="cs-btn cs-btn-sm cs-btn-ghost cs-btn-icon" href="${esc(urls.localDownload + '&file=' + encodeURIComponent(l.file))}" title="Descargar">${icon('download')}</a>
              <button class="cs-btn cs-btn-sm cs-btn-ghost cs-btn-icon cs-btn-danger" type="button" data-delete-local="${esc(l.file)}" title="Borrar de Cosmo">${icon('trash-2')}</button>` : ''}
            </td></tr>`;
        }
        html += '</tbody></table>';
      }
      box.innerHTML = html;
    };
    if (box && !box.textContent.includes('API remota')) loadBackups();
    $('[data-backups-reload]')?.addEventListener('click', loadBackups);

    $$('[data-backup]').forEach((b) => b.addEventListener('click', async () => {
      const pull = $('[data-backup-pull]')?.checked;
      $$('[data-backup]').forEach((x) => { x.disabled = true; });
      busy(b, true, 'Creando…');
      try {
        const r = await api(urls.backupCreate, { id, type: b.dataset.backup, pull: pull ? '1' : '0' });
        toast(r.ok ? 'success' : 'warning', r.steps.map((s) => s.text).join(' · '), 9000);
      } catch (err) { toast('error', err.message, 9000); }
      busy(b, false);
      $$('[data-backup]').forEach((x) => { x.disabled = false; });
      loadBackups();
    }));

    box?.addEventListener('click', async (e) => {
      const pull = e.target.closest('[data-pull]');
      const delRemote = e.target.closest('[data-delete-remote]');
      const delLocal = e.target.closest('[data-delete-local]');
      try {
        if (pull) {
          busy(pull, true);
          const r = await api(urls.backupPull, { id, file: pull.dataset.pull });
          toast('success', r.message);
        } else if (delRemote) {
          if (!await confirmDialog('¿Borrar la copia de la instalación?', delRemote.dataset.deleteRemote + ' se borrará del hosting. Si está guardada en Cosmo, esa se conserva.', 'Borrar', true)) return;
          await api(urls.backupDelete, { id, file: delRemote.dataset.deleteRemote });
          toast('success', 'Copia borrada de la instalación');
        } else if (delLocal) {
          if (!await confirmDialog('¿Borrar la copia guardada en Cosmo?', delLocal.dataset.deleteLocal + ' se borrará de Cosmo.', 'Borrar', true)) return;
          await api(urls.localDelete, { id, file: delLocal.dataset.deleteLocal });
          toast('success', 'Copia borrada de Cosmo');
        } else {
          return;
        }
      } catch (err) { toast('error', err.message, 9000); }
      loadBackups();
    });

    // Log
    const logs = $('[data-logs]');
    $('[data-logs-load]')?.addEventListener('click', async (e) => {
      const b = e.currentTarget;
      busy(b, true);
      try {
        const r = await api(urls.logs + '&level=' + encodeURIComponent($('[data-log-level]').value));
        if (!r.entries.length) {
          logs.innerHTML = '<div class="cs-empty cs-small">Nada en los últimos 7 días</div>';
        } else {
          const lvl = { CRITICAL: 'danger', ERROR: 'danger', WARNING: 'warning', NOTICE: 'info', INFO: 'info', DEBUG: '' };
          logs.innerHTML = '<div class="cs-table-wrap"><table class="cs-table cs-log"><tbody>' + r.entries.map((x) => `<tr>
            <td class="cs-nowrap cs-muted">${esc(x.time)}</td>
            <td><span class="cs-badge ${lvl[x.level] ? 'cs-badge-' + lvl[x.level] : ''}">${esc(x.level)}</span></td>
            <td class="cs-log-msg">${esc(x.message)}<div class="cs-row-sub">${esc(x.channel)} · ${esc(x.context)}</div>${x.detail ? `<details><summary class="cs-small">traza</summary><pre>${esc(x.detail)}</pre></details>` : ''}</td>
          </tr>`).join('') + '</tbody></table></div>';
        }
      } catch (err) { logs.innerHTML = `<div class="cs-empty cs-c-danger">${esc(err.message)}</div>`; }
      busy(b, false);
    });

    // Integridad
    $('[data-integrity-load]')?.addEventListener('click', async (e) => {
      const b = e.currentTarget;
      const out = $('[data-integrity]');
      busy(b, true);
      try {
        const r = await api(urls.integrity);
        let html = '';
        if (!r.manifest) {
          html += '<p class="cs-muted cs-small">Sin manifiesto: se crea en la primera actualización desde Cosmo.</p>';
        } else if (!r.modified.length && !r.missing.length) {
          html += `<p class="cs-c-success cs-small">${icon('circle-check')} Los ficheros del núcleo coinciden con la versión ${esc(r.version)} instalada.</p>`;
        } else {
          if (r.modified.length) html += `<p class="cs-small"><strong>Modificados (${r.modified.length}):</strong></p><ul class="cs-small cs-mono">${r.modified.map((f) => `<li>${esc(f)}</li>`).join('')}</ul>`;
          if (r.missing.length) html += `<p class="cs-small"><strong>Faltan (${r.missing.length}):</strong></p><ul class="cs-small cs-mono">${r.missing.map((f) => `<li>${esc(f)}</li>`).join('')}</ul>`;
        }
        if (r.updates?.length) {
          html += '<p class="cs-small" style="margin-top:10px"><strong>Actualizaciones</strong></p><ul class="cs-small">' + r.updates.slice(0, 10).map((u) =>
            `<li>${esc(when(u.installed_at))} · ${esc(u.from)} → ${esc(u.to)} · ${u.copied} copiados, ${u.deleted} borrados</li>`).join('') + '</ul>';
        }
        out.innerHTML = html;
      } catch (err) { out.innerHTML = `<p class="cs-c-danger">${esc(err.message)}</p>`; }
      busy(b, false);
    });

    // Actualización
    const upForm = $('[data-update-form]');
    upForm?.addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(upForm);
      const version = fd.get('version');
      if (!version) { toast('warning', 'Elige la versión a instalar'); return; }
      const backup = fd.get('backup') === '1';
      const ok = await confirmDialog(`¿Actualizar a la ${version}?`,
        (backup ? 'Primero se hará una copia completa en la instalación. ' : 'Sin copia previa. ')
        + 'Durante la actualización la aplicación queda en mantenimiento (normalmente unos segundos). Si la versión nueva no arranca, se deshace sola.',
        'Actualizar');
      if (!ok) return;
      const btn = $('button[type="submit"]', upForm);
      const log = $('[data-update-log]');
      log.hidden = false;
      log.innerHTML = `<li>${icon('loader-circle', 'cs-spin')}<span>Actualizando… no cierres esta página</span></li>`;
      busy(btn, true, 'Actualizando…');
      try {
        const r = await api(urls.update, { id, version, backup: backup ? '1' : '0', force: fd.get('force') === '1' ? '1' : '0', downgrade: fd.get('downgrade') === '1' ? '1' : '0' });
        log.innerHTML = stepsHtml(r.steps);
        toast(r.ok ? 'success' : 'error', r.ok ? `Actualizada a la ${version}` : 'La actualización no se ha completado', 9000);
        if (r.ok) setTimeout(() => location.reload(), 2500);
      } catch (err) {
        log.innerHTML = stepsHtml([{ ok: false, text: err.message }]);
      }
      busy(btn, false);
    });

    $('[data-migrate]')?.addEventListener('click', async (e) => {
      const b = e.currentTarget;
      busy(b, true);
      try {
        const r = await api(urls.migrate, { id });
        toast('success', 'Migración: ' + (r.steps.join(', ') || 'al día'));
        setTimeout(() => location.reload(), 1200);
      } catch (err) { toast('error', err.message, 9000); busy(b, false); }
    });
  }

  // ---------------------------------------------------------------- formulario de instalación

  // ---------------------------------------------------------------- servidores: probar el FTP

  const serverForm = $('[data-server-form]');
  if (serverForm) {
    const result = $('[data-test-result]', serverForm);
    $('[data-test-ftp]', serverForm).addEventListener('click', async (e) => {
      const b = e.currentTarget;
      const f = serverForm.elements;
      busy(b, true, 'Probando…');
      result.innerHTML = '';
      try {
        const r = await api(serverForm.dataset.testUrl, {
          id: f.id?.value || '', host: f.host.value, port: f.port.value, user: f.user.value, password: f.password.value,
          base: f.base.value, ftps: f.ftps.checked ? '1' : '0', verify_ssl: f.verify_ssl.checked ? '1' : '0',
        });
        result.innerHTML = `<span class="cs-c-success">${icon('circle-check')} Conecta: ${r.dirs} carpetas y ${r.files} ficheros en la carpeta inicial</span>`;
      } catch (err) {
        result.innerHTML = `<span class="cs-c-danger">${icon('circle-x')} ${esc(err.message)}</span>`;
      }
      busy(b, false);
    });
  }

  // ---------------------------------------------------------------- instalación nueva: explorar carpetas del FTP

  const provision = $('[data-provision-form]');
  if (provision) {
    const browser = $('[data-browser]', provision);
    const folder = provision.elements.folder;
    const serverSelect = provision.elements.server;
    const url = provision.elements.url;
    const open = async (path) => {
      browser.hidden = false;
      browser.innerHTML = `<div class="cs-muted">${icon('loader-circle', 'cs-spin')} Leyendo el FTP…</div>`;
      try {
        const q = new URLSearchParams({ id: serverSelect.value, path });
        const r = await api(`${provision.dataset.browseUrl}&${q}`);
        const empty = r.dirs.length === 0 && r.files === 0;
        browser.innerHTML = `<div class="cs-browser-head"><span class="cs-mono">${esc(r.path)}</span>
            <span class="cs-muted cs-small">${r.dirs.length} carpetas · ${r.files} ficheros</span>
            <button class="cs-btn cs-btn-sm cs-btn-primary" type="button" data-pick="${esc(r.path)}">${icon('check')} Usar esta carpeta</button></div>
          <ul class="cs-browser-list">
            ${r.parent !== null ? `<li><button type="button" data-go="${esc(r.parent)}">${icon('corner-left-up')} ..</button></li>` : ''}
            ${r.dirs.map((d) => `<li><button type="button" data-go="${esc((r.path === '/' ? '' : r.path) + '/' + d)}">${icon('folder')} ${esc(d)}</button></li>`).join('')}
            ${empty ? '<li class="cs-muted">Carpeta vacía</li>' : ''}
          </ul>`;
      } catch (err) {
        browser.innerHTML = `<span class="cs-c-danger">${icon('circle-x')} ${esc(err.message)}</span>`;
      }
    };
    $('[data-browse]', provision).addEventListener('click', () => open(folder.value || serverSelect.selectedOptions[0].dataset.base || '/'));
    browser.addEventListener('click', (e) => {
      const go = e.target.closest('[data-go]');
      if (go) open(go.dataset.go);
      const pick = e.target.closest('[data-pick]');
      if (pick) {
        folder.value = pick.dataset.pick;
        browser.hidden = true;
        // Una carpeta con nombre de dominio da la URL
        const last = pick.dataset.pick.split('/').pop();
        if (!url.value && /^[a-z0-9-]+(\.[a-z0-9-]+)+$/i.test(last)) url.value = `https://${last}`;
      }
    });
    serverSelect.addEventListener('change', () => { browser.hidden = true; });
  }

  const form = $('[data-install-form]');
  if (form) {
    const tokenInput = $('#f-token', form);
    const appSelect = $('#f-app', form);
    const typeSelect = $('#f-btype', form);
    // Los tipos de copia programada dependen de la aplicación
    const syncBackupTypes = () => {
      const types = JSON.parse(appSelect.dataset.backupTypes || '{}')[appSelect.value];
      if (!types) return;
      const current = typeSelect.value;
      typeSelect.innerHTML = Object.entries(types).map(([k, label]) => `<option value="${esc(k)}">${esc(label)}</option>`).join('');
      typeSelect.value = types[current] ? current : Object.keys(types)[0];
    };
    appSelect.addEventListener('change', syncBackupTypes);
    const snippet = $('[data-token-snippet]', form);
    const result = $('[data-test-result]', form);
    const showSnippet = () => {
      const t = tokenInput.value.trim();
      snippet.hidden = t.length < 32;
      $('[data-snippet-text]', snippet).textContent = `'remote' => [\n    'token' => '${t}',\n],`;
    };
    $('[data-generate-token]', form).addEventListener('click', () => {
      const a = new Uint8Array(32);
      crypto.getRandomValues(a);
      tokenInput.value = Array.from(a, (b) => b.toString(16).padStart(2, '0')).join('');
      showSnippet();
    });
    tokenInput.addEventListener('input', showSnippet);
    $('[data-test-connection]', form).addEventListener('click', async (e) => {
      const b = e.currentTarget;
      busy(b, true, 'Probando…');
      result.innerHTML = '';
      try {
        const r = await api(form.dataset.testUrl, {
          id: form.elements.id?.value || '',
          url: form.elements.url.value,
          token: tokenInput.value,
          verify_ssl: form.elements.verify_ssl.checked ? '1' : '',
        });
        let notice = r.warning || '';
        if (r.ok && r.app && r.app !== appSelect.value) {
          if (r.known) {
            appSelect.value = r.app;
            syncBackupTypes();
            notice = `Es ${r.app_name}: aplicación cambiada. ${notice}`;
          } else {
            notice = `Responde como «${r.app}», que no está en la configuración de Cosmo (apps). ${notice}`;
          }
        }
        result.innerHTML = r.ok
          ? `<span class="cs-c-success">${icon('circle-check')} Responde: ${esc(r.name)} · ${esc(r.app_name || '')} ${esc(r.version)} · ${r.ms} ms</span>${notice ? `<div class="cs-c-warning">${esc(notice.trim())}</div>` : ''}`
          : `<span class="cs-c-danger">${icon('circle-x')} ${esc(r.error)}</span>`;
      } catch (err) {
        result.innerHTML = `<span class="cs-c-danger">${icon('circle-x')} ${esc(err.message)}</span>`;
      }
      busy(b, false);
    });
  }
})();
