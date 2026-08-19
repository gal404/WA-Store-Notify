const { local } = require('./config');

const BASE = `${local.WP_URL}/wp-json/wa-notify/v1`;

// כשלים חולפים (גמגום רשת, עומס רגעי בשרת) — שווה לנסות שוב לפני שמדווחים.
// 4xx אחרים (401 מפתח שגוי, 404) לא יסתדרו מעצמם, ולכן נכשלים מיד.
const RETRYABLE_STATUS = new Set([408, 429, 500, 502, 503, 504]);
const RETRY_DELAYS_MS = [1000, 3000]; // 2 ניסיונות חוזרים

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

function isRetryable(err) {
  if (err && err.status) return RETRYABLE_STATUS.has(err.status);
  return true; // שגיאת רשת/timeout — אין status, כמעט תמיד חולף
}

async function once(method, path, body) {
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

async function call(method, path, body) {
  if (!local.WP_URL || !local.API_KEY) {
    throw new Error('WP_URL / API_KEY חסרים ב-.env');
  }
  let lastErr;
  for (let attempt = 0; attempt <= RETRY_DELAYS_MS.length; attempt++) {
    try {
      return await once(method, path, body);
    } catch (e) {
      lastErr = e;
      if (attempt === RETRY_DELAYS_MS.length || !isRetryable(e)) break;
      await sleep(RETRY_DELAYS_MS[attempt]);
    }
  }
  // מסמנים כמה ניסיונות נעשו — כדי שההודעה בלוג תשקף שזה לא כשל בודד
  lastErr.attempts = RETRY_DELAYS_MS.length + 1;
  throw lastErr;
}

module.exports = {
  ping: () => call('GET', '/ping'),
  claim: (worker_id, max, kinds) => call('POST', '/claim', { worker_id, max, kinds }),
  report: (results) => call('POST', '/report', { results }),
  optout: (events) => call('POST', '/optout', { events }),
  heartbeat: (payload) => call('POST', '/heartbeat', payload),
  queue: (limit) => call('GET', `/queue?limit=${encodeURIComponent(limit)}`),
  history: (page, per) => call('GET', `/history?page=${encodeURIComponent(page)}&per=${encodeURIComponent(per)}`),
};
