const { local } = require('./config');

const BASE = `${local.WP_URL}/wp-json/wa-notify/v1`;

async function call(method, path, body) {
  if (!local.WP_URL || !local.API_KEY) {
    throw new Error('WP_URL / API_KEY חסרים ב-.env');
  }
  const opts = {
    method,
    headers: { 'X-WSN-Api-Key': local.API_KEY, 'Content-Type': 'application/json' },
  };
  if (body !== undefined) opts.body = JSON.stringify(body);

  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), 20000);
  try {
    const res = await fetch(BASE + path, { ...opts, signal: controller.signal });
    const text = await res.text();
    let data = null;
    try { data = text ? JSON.parse(text) : null; } catch { /* לא-JSON */ }
    if (!res.ok) {
      const err = new Error(`HTTP ${res.status}${data && data.message ? ': ' + data.message : ''}`);
      err.status = res.status;
      throw err;
    }
    return data;
  } finally {
    clearTimeout(timer);
  }
}

module.exports = {
  ping: () => call('GET', '/ping'),
  claim: (worker_id, max, kinds) => call('POST', '/claim', { worker_id, max, kinds }),
  report: (results) => call('POST', '/report', { results }),
  optout: (events) => call('POST', '/optout', { events }),
  heartbeat: (payload) => call('POST', '/heartbeat', payload),
  queue: (limit) => call('GET', `/queue?limit=${encodeURIComponent(limit)}`),
};
