(function () {
  'use strict';

  const currencyFormatter = new Intl.NumberFormat('en-GB', {
    style: 'currency',
    currency: 'GBP'
  });

  function money(value) {
    return currencyFormatter.format(Number(value || 0));
  }

  function dateRange(session) {
    if (!session) return '';
    const start = [session.startDate, session.startTime].filter(Boolean).join(' ');
    const endDate = session.endDate && session.endDate !== session.startDate ? session.endDate : '';
    const end = [endDate, session.endTime].filter(Boolean).join(' ');
    return end ? `${start} – ${end}` : start;
  }

  const siteBase = window.SCL_API_BASE || (function () {
    const pathname = window.location.pathname || '/';
    return pathname.slice(0, pathname.lastIndexOf('/') + 1) || '/';
  })();

  function resolveApiPath(path) {
    if (/^https?:\/\//i.test(path)) return path;
    if (path.indexOf('/api/') === 0) return siteBase + path.slice(1);
    if (path.indexOf('api/') === 0) return siteBase + path;
    return path;
  }

  function api(path, options) {
    const opts = options || {};
    const headers = opts.headers || {};
    if (opts.body && !(opts.body instanceof FormData)) {
      headers['Content-Type'] = 'application/json';
    }
    if (opts.csrfToken) {
      headers['X-CSRF-Token'] = opts.csrfToken;
      delete opts.csrfToken;
    }
    return fetch(resolveApiPath(path), { ...opts, headers, credentials: opts.credentials || 'same-origin' }).then(async response => {
      const type = response.headers.get('content-type') || '';
      const isJson = type.includes('application/json');
      const payload = isJson ? await response.json() : await response.text();
      if (!response.ok) {
        const message = isJson && payload.error ? payload.error : `Request failed (${response.status})`;
        throw new Error(message);
      }
      return payload;
    });
  }

  function getValue(id) {
    const el = document.getElementById(id);
    return el ? el.value.trim() : '';
  }

  function setText(id, value) {
    const el = document.getElementById(id);
    if (el) el.textContent = value;
  }

  function show(elOrId, display) {
    const el = typeof elOrId === 'string' ? document.getElementById(elOrId) : elOrId;
    if (el) el.style.display = display || 'block';
  }

  function hide(elOrId) {
    const el = typeof elOrId === 'string' ? document.getElementById(elOrId) : elOrId;
    if (el) el.style.display = 'none';
  }

  function downloadText(filename, text) {
    const blob = new Blob([text], { type: 'text/plain;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
  }

  function downloadCsv(filename, text) {
    const blob = new Blob([text], { type: 'text/csv;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
  }

  function readQuery(name) {
    return new URLSearchParams(window.location.search).get(name) || '';
  }

  window.SCL = {
    api,
    money,
    dateRange,
    getValue,
    setText,
    show,
    hide,
    downloadText,
    downloadCsv,
    readQuery
  };
})();
