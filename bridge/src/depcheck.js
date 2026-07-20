// בדיקת גרסה יומית: משווה את whatsapp-web.js המותקן מול הגרסה האחרונה ב-npm,
// ומוודא שה-Chromium של puppeteer עדיין קיים על הדיסק. read-only בלבד —
// לא מבצע npm update, רק מדווח דרך ה-heartbeat כדי ש-WP יציג התראה.

const fs = require('fs');
const path = require('path');

const DATA_DIR = path.join(__dirname, '..', 'data');
const FILE = path.join(DATA_DIR, 'depcheck.json');
const CHECK_INTERVAL_MS = 24 * 3600 * 1000;

let cache = load();

function load() {
  try { return JSON.parse(fs.readFileSync(FILE, 'utf8')); } catch { return null; }
}

function save(data) {
  if (!fs.existsSync(DATA_DIR)) fs.mkdirSync(DATA_DIR, { recursive: true });
  try { fs.writeFileSync(FILE, JSON.stringify(data)); } catch (e) { console.error('שמירת depcheck נכשלה:', e.message); }
}

function checkPuppeteer() {
  try {
    const puppeteer = require('puppeteer');
    const execPath = puppeteer.executablePath();
    return { ok: fs.existsSync(execPath) };
  } catch (e) {
    return { ok: false };
  }
}

async function fetchLatestVersion() {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), 10000);
  try {
    const res = await fetch('https://registry.npmjs.org/whatsapp-web.js/latest', { signal: controller.signal });
    if (!res.ok) return null;
    const data = await res.json();
    return data.version || null;
  } catch {
    return null;
  } finally {
    clearTimeout(timer);
  }
}

// השוואת גרסאות סמנטיות (major.minor.patch) — מספיק כדי לזהות "יש עדכון"
function isNewer(latest, installed) {
  if (!latest || !installed) return false;
  const a = latest.split('.').map(Number);
  const b = installed.split('.').map(Number);
  for (let i = 0; i < 3; i++) {
    if ((a[i] || 0) > (b[i] || 0)) return true;
    if ((a[i] || 0) < (b[i] || 0)) return false;
  }
  return false;
}

async function run() {
  const installed = require('whatsapp-web.js/package.json').version;
  const latest = await fetchLatestVersion();
  const pptr = checkPuppeteer();
  const result = {
    checked_at: new Date().toISOString(),
    wwebjs_installed: installed,
    wwebjs_latest: latest,
    update_available: isNewer(latest, installed),
    puppeteer_ok: pptr.ok,
  };
  cache = result;
  save(result);
  return result;
}

/** מריץ בדיקה רק אם עברה יממה מאז הבדיקה האחרונה; אחרת מחזיר את התוצאה השמורה */
async function checkDaily() {
  if (cache && Date.now() - Date.parse(cache.checked_at) < CHECK_INTERVAL_MS) {
    return cache;
  }
  try {
    return await run();
  } catch (e) {
    console.error('בדיקת עדכון תלויות נכשלה:', e.message);
    return cache; // לא מפיל את ה-heartbeat — פשוט מנסה שוב בפעם הבאה
  }
}

module.exports = { checkDaily };
