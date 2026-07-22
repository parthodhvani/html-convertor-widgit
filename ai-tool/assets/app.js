(function () {
  const form = document.getElementById('generate-form');
  if (!form) return;

  const status = document.getElementById('status');
  const logEl = document.getElementById('log');
  const result = document.getElementById('result');
  const submitBtn = document.getElementById('submit-btn');

  form.addEventListener('submit', async function (event) {
    event.preventDefault();

    status.hidden = false;
    result.hidden = true;
    result.innerHTML = '';
    logEl.textContent = 'Starting… Claude generation can take several minutes.\n';
    document.body.classList.add('busy');
    if (submitBtn) submitBtn.disabled = true;

    const data = new FormData(form);
    data.set('ajax', '1');

    try {
      const response = await fetch('process.php', {
        method: 'POST',
        headers: { Accept: 'application/json' },
        body: data,
      });

      const payload = await response.json();
      const logs = payload.logs || [];
      logEl.textContent = logs.length ? logs.join('\n') : logEl.textContent;

      if (!response.ok || !payload.ok) {
        throw new Error(payload.error || 'Generation failed.');
      }

      const report = payload.report || {};
      result.hidden = false;
      result.innerHTML = [
        '<div class="notice notice-ok">Website generated successfully.</div>',
        '<dl class="report-grid">',
        '<dt>Local URL</dt><dd><a href="' + escapeHtml(report.local_url || '#') + '" target="_blank" rel="noopener">' + escapeHtml(report.local_url || '') + '</a></dd>',
        '<dt>Folder</dt><dd><code>' + escapeHtml(report.folder_path || '') + '</code></dd>',
        '<dt>Database</dt><dd><code>' + escapeHtml(report.database_name || '') + '</code></dd>',
        '<dt>Admin</dt><dd><code>' + escapeHtml(report.admin_username || '') + '</code> / <code>' + escapeHtml(report.admin_password || '') + '</code></dd>',
        '</dl>',
        '<p><a class="btn" href="' + escapeHtml(report.local_url || '#') + '" target="_blank" rel="noopener">Open website</a></p>',
        '<h2>Full report</h2>',
        '<pre class="log">' + escapeHtml(JSON.stringify(report, null, 2)) + '</pre>',
      ].join('');
    } catch (err) {
      result.hidden = false;
      result.innerHTML = '<div class="notice notice-error">' + escapeHtml(err.message || String(err)) + '</div>';
    } finally {
      document.body.classList.remove('busy');
      if (submitBtn) submitBtn.disabled = false;
    }
  });

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }
})();
