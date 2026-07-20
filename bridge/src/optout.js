const fs = require('fs');
const path = require('path');
const { state } = require('./config');
const wp = require('./wpclient');

const DATA_DIR = path.join(__dirname, '..', 'data');
const QUEUE_FILE = path.join(DATA_DIR, 'optout-queue.json');

let queue = load();
let sender = null;

function load() {
  try { return JSON.parse(fs.readFileSync(QUEUE_FILE, 'utf8')); } catch { return []; }
}
function save() {
  if (!fs.existsSync(DATA_DIR)) fs.mkdirSync(DATA_DIR, { recursive: true });
  try { fs.writeFileSync(QUEUE_FILE, JSON.stringify(queue)); } catch (e) { console.error('שמירת optout נכשלה:', e.message); }
}

function normalize(s) {
  return String(s || '').trim().replace(/[.!?,־-]+$/u, '').trim();
}

function isOptOut(body) {
  const b = normalize(body).toLowerCase();
  return state.remote.optout_keywords.some((k) => normalize(k).toLowerCase() === b);
}

// נקרא מהמאזין של ה-sender על כל הודעה נכנסת פרטית
function handleIncoming(evt) {
  if (!evt || !isOptOut(evt.body)) return;
  const phone = String(evt.phone || '').replace(/\D/g, '');
  if (!phone) return;
  console.log(`בקשת הסרה מ-${phone}`);
  queue.push({ phone, body: evt.body.slice(0, 60), received_at: new Date().toISOString() });
  save();
  // אישור מיידי — מענה להודעה נכנסת הוא חוקי ומצופה, סיכון נמוך
  if (sender) {
    sender.sendText(phone, state.remote.optout_confirm).catch(() => {});
  }
  flush();
}

// שידור התור ל-WP; נכשל בשקט ונשמר לניסיון הבא (עמיד ל-WP כבוי)
async function flush() {
  if (!queue.length) return;
  const batch = queue.slice(0, 20);
  try {
    await wp.optout(batch.map((e) => ({ phone: e.phone, body: e.body, received_at: e.received_at })));
    queue = queue.slice(batch.length);
    save();
    if (queue.length) flush();
  } catch (e) {
    console.error('שידור הסרות ל-WP נכשל (יינסה שוב):', e.message);
  }
}

function init(senderRef) {
  sender = senderRef;
  sender.onIncomingMessage(handleIncoming);
}

module.exports = { init, flush, isOptOut };
