const fs = require('fs');
const path = require('path');
const { state } = require('./config');

const DATA_DIR = path.join(__dirname, '..', 'data');
const COUNTERS_FILE = path.join(DATA_DIR, 'counters.json');

function ensureDir() {
  if (!fs.existsSync(DATA_DIR)) fs.mkdirSync(DATA_DIR, { recursive: true });
}

// counters נשמר לדיסק — עמיד ל-restart של pm2 (caps לא מתאפסים)
let counters = load();

function load() {
  try {
    return JSON.parse(fs.readFileSync(COUNTERS_FILE, 'utf8'));
  } catch {
    return { sent: [], campaignSent: [] }; // מערכי timestamps (ms)
  }
}

function save() {
  ensureDir();
  try { fs.writeFileSync(COUNTERS_FILE, JSON.stringify(counters)); } catch (e) { console.error('שמירת counters נכשלה:', e.message); }
}

// שעון לפי אזור הזמן של החנות (מגיע מה-heartbeat), לא שעון ה-PC
function storeNow() {
  const tz = state.remote.tz || 'Asia/Jerusalem';
  const parts = new Intl.DateTimeFormat('en-GB', {
    timeZone: tz, hour: '2-digit', minute: '2-digit', hour12: false,
  }).formatToParts(new Date());
  const h = Number(parts.find((p) => p.type === 'hour').value);
  const m = Number(parts.find((p) => p.type === 'minute').value);
  return { minutes: h * 60 + m };
}

function toMinutes(hhmm) {
  const [h, m] = String(hhmm).split(':').map(Number);
  return (h || 0) * 60 + (m || 0);
}

function inQuietHours() {
  const t = storeNow().minutes;
  const from = toMinutes(state.remote.transactional.quiet_from);
  const to = toMinutes(state.remote.transactional.quiet_to);
  // שעות שקט חוצות חצות (21:00→08:00)
  return from > to ? (t >= from || t < to) : (t >= from && t < to);
}

function inCampaignWindow() {
  const t = storeNow().minutes;
  const from = toMinutes(state.remote.campaign.window_from);
  const to = toMinutes(state.remote.campaign.window_to);
  return t >= from && t < to;
}

function prune() {
  const dayAgo = Date.now() - 24 * 3600 * 1000;
  counters.sent = counters.sent.filter((t) => t > dayAgo);
  counters.campaignSent = counters.campaignSent.filter((t) => t > dayAgo);
}

function countWithin(arr, ms) {
  const since = Date.now() - ms;
  return arr.filter((t) => t > since).length;
}

// חימום: תקרה יומית אפקטיבית לפי יום החימום
function warmupDailyCap(configuredDaily) {
  const w = state.remote.warmup;
  if (!w || !w.enabled || !w.started) return configuredDaily;
  const start = Date.parse(w.started + 'T00:00:00');
  if (isNaN(start)) return configuredDaily;
  const day = Math.floor((Date.now() - start) / (24 * 3600 * 1000));
  if (day < 0) return 0;
  if (day >= w.ramp.length) return configuredDaily;
  return Math.min(configuredDaily, w.ramp[day]);
}

function warmupDay() {
  const w = state.remote.warmup;
  if (!w || !w.enabled || !w.started) return 0;
  const start = Date.parse(w.started + 'T00:00:00');
  if (isNaN(start)) return 0;
  return Math.max(0, Math.floor((Date.now() - start) / (24 * 3600 * 1000)) + 1);
}

/** אילו סוגי הודעות מותר לשלוח כרגע (לפי שעה, חלון, caps, חימום) */
function allowedKinds() {
  prune();
  // בדיקה ידנית של מנהל ("שלח לי לבדיקה") — לא הודעה יזומה ללקוח, אז לא כפופה
  // לשעות שקט: המנהל צריך לוודא שהצינור עובד בכל שעה, לא רק בשעות הפעילות.
  // עדיין נספרת בתוך ה-caps (recordSent) כדי לא לפתוח פרצה לעקיפתם.
  const kinds = ['test'];
  if (inQuietHours()) return kinds;

  const tr = state.remote.transactional;
  const trDaily = warmupDailyCap(tr.daily_cap);
  const trOk = countWithin(counters.sent, 3600e3) < tr.hourly_cap
    && countWithin(counters.sent, 24 * 3600e3) < trDaily;
  if (trOk) {
    kinds.push('status', 'item_change', 'free');
  }

  const cp = state.remote.campaign;
  const cpDaily = warmupDailyCap(cp.daily_cap);
  const cpOk = inCampaignWindow()
    && countWithin(counters.campaignSent, 3600e3) < cp.hourly_cap
    && countWithin(counters.campaignSent, 24 * 3600e3) < cpDaily;
  if (cpOk && trOk) {
    kinds.push('campaign');
  }
  return kinds;
}

function recordSent(kind) {
  const now = Date.now();
  counters.sent.push(now);
  if (kind === 'campaign') counters.campaignSent.push(now);
  save();
}

/** מרווח אקראי (ms) להמתין אחרי שליחה, לפי סוג ההודעה */
function gapAfter(kind) {
  const c = kind === 'campaign' ? state.remote.campaign : state.remote.transactional;
  const min = c.min_gap_s * 1000;
  const max = c.max_gap_s * 1000;
  return min + Math.random() * Math.max(0, max - min);
}

function snapshot() {
  prune();
  return {
    sent_hour: countWithin(counters.sent, 3600e3),
    sent_today: countWithin(counters.sent, 24 * 3600e3),
    campaign_today: countWithin(counters.campaignSent, 24 * 3600e3),
  };
}

module.exports = { allowedKinds, recordSent, gapAfter, snapshot, warmupDay, inQuietHours };
