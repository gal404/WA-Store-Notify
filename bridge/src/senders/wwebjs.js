const path = require('path');
const { Client, LocalAuth } = require('whatsapp-web.js');
const QRCode = require('qrcode');
const { makeTypo } = require('../typo');
const { state: cfgState } = require('../config');

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

// מכונת המצבים — נגזר מ-PinookimWA/node-bridge/index.js
let state = 'starting'; // starting | qr | authenticated | ready | disconnected
let lastQr = null;
let meInfo = null;
let incomingCb = null;

const client = new Client({
  authStrategy: new LocalAuth({ dataPath: path.join(__dirname, '..', '..', 'session') }),
  puppeteer: { headless: true, args: ['--no-sandbox'] },
});

client.on('qr', (qr) => { lastQr = qr; state = 'qr'; console.log('QR מוכן — סרוק מדף הסטטוס המקומי או מ-WP'); });
client.on('authenticated', () => { lastQr = null; state = 'authenticated'; });
client.on('ready', () => {
  state = 'ready';
  lastQr = null;
  meInfo = { name: client.info?.pushname || '', number: client.info?.wid?.user || '' };
  console.log(`✔ מחובר בתור ${meInfo.name} (${meInfo.number})`);
});
client.on('disconnected', (reason) => {
  state = 'disconnected';
  meInfo = null;
  console.error('נותק מוואטסאפ:', reason, '— מנסה להתחבר מחדש...');
  setTimeout(() => client.initialize().catch(() => {}), 3000);
});

client.on('message', async (msg) => {
  try {
    if (msg.fromMe || !incomingCb) return;
    // רק צ'אטים פרטיים — קלט לא-מהימן, מטופל אך ורק כ-opt-out ב-worker
    if (!String(msg.from).endsWith('@c.us')) return;
    const contact = await msg.getContact();
    let phone = contact.number || String(msg.from).replace('@c.us', '');
    incomingCb({ phone, body: String(msg.body || '').slice(0, 300) });
  } catch (e) {
    console.error('שגיאה בקליטת הודעה נכנסת:', e.message);
  }
});

// בודק אם הודעה שלנו עם טקסט זהה נחתה בצ'אט לאחרונה — משמש כשsendMessage מחזיר
// falsy בלי לזרוק, כדי להבדיל בין "לא נשלחה באמת" לבין "נשלחה, ההחזרה רק נכשלה"
// (תקלה ידועה ב-whatsapp-web.js). קריטי: בלי זה עלולים לדווח 'נכשל' על הודעה
// שבאמת נמסרה, מה שגורם ל-retry מיותר ולשליחה כפולה בפועל ללקוח.
// משתמש ב-chat.lastMessage (מגיע עם נתוני הצ'אט עצמו) ולא ב-fetchMessages —
// fetchMessages נשען על window.Store.Msg.getMessagesById הפנימי של WhatsApp Web,
// שנמצא במצב שבור אצלנו כרגע (מחזיר שגיאה מכווצת/לא קריאה בכל קריאה).
async function verifyRecentSend(chatId, text) {
  try {
    const chat = await client.getChatById(chatId);
    const lm = chat.lastMessage;
    return lm && lm.fromMe && lm.body === text ? lm : null;
  } catch {
    return null;
  }
}

async function qrDataUrl() {
  if (!lastQr) return null;
  try { return await QRCode.toDataURL(lastQr, { margin: 1, width: 320 }); } catch { return null; }
}

module.exports = {
  init: () => client.initialize(),

  getState: () => ({ state, me: meInfo, hasQr: !!lastQr }),
  qrDataUrl,

  async verifyNumber(phoneE164) {
    // getNumberId מחזיר null (לא זורק) כשהמספר לא רשום בוואטסאפ — לכן שגיאה שנזרקת כאן
    // היא תקלה טכנית אמיתית (חיבור/קליינט), לא "מספר לא קיים", וצריכה לעלות ל-processOne
    // כ-failed רגיל (נסיון חוזר) ולא כ-invalid_number (מסומן קבוע ב-WP).
    const id = await client.getNumberId(phoneE164);
    return !!id;
  },

  /**
   * שליחה עם דימוי הקלדה אנושי (נגזר מ-PinookimWA /reply, בלי תקרת 8s של PHP).
   * אם typoRatio מפעיל — נשלחת "שגיאה" ואז Message.edit לתיקון תוך שניות.
   */
  async sendText(phoneE164, text) {
    const chatId = `${phoneE164}@c.us`;
    let typo = null;
    const ratio = Number(cfgState.remote.typo_ratio || 0);
    if (ratio > 0 && Math.floor(Math.random() * ratio) === 0) {
      typo = makeTypo(text);
    }
    const outgoing = typo ? typo.wrong : text;

    if (process.env.WSN_DEBUG_SKIP_TYPING !== '1') {
      try {
        const chat = await client.getChatById(chatId);
        await sleep(800 + Math.random() * 1200); // "קריאה"
        await chat.sendStateTyping();
        await sleep(Math.min(1000 + outgoing.length * 55 + Math.random() * 1500, 20000));
        await chat.clearState();
      } catch { /* חיווי הקלדה best-effort */ }
    }

    let sent = await client.sendMessage(chatId, outgoing);
    if (!sent) {
      // אומתה בפועל (2026-07-21): הודעה אמיתית נמסרה ללקוח בזמן ש-sendMessage החזיר
      // falsy — לכן לפני שמדווחים כשל, בודקים בהיסטוריית הצ'אט אם היא כן הגיעה.
      await sleep(1500);
      sent = await verifyRecentSend(chatId, outgoing);
      if (!sent) {
        throw new Error('sendMessage לא החזיר הודעה ולא אומתה בהיסטוריית הצ׳אט — כשל אמיתי כנראה');
      }
    }

    let edited = false;
    if (typo) {
      try {
        await sleep(3000 + Math.random() * 7000); // "שם לב לטעות"
        const res = await sent.edit(text);
        edited = res !== null; // @lid לא נתמך — מחזיר null, והשגיאה נשארת (גם אנושי)
      } catch { /* עריכה נכשלה — משאירים */ }
    }
    return { waMsgId: sent.id?._serialized || '', edited };
  },

  // אבחון: ההודעה האחרונה בצ'אט — לבדוק אם הודעה שדווחה כ'נכשלה' בעצם נמסרה.
  // מפורט מאוד בכוונה — כדי להבין איפה בדיוק זה נכשל (getChatById? getChats? lastMessage?)
  async getLastMessage(phoneE164) {
    const debug = {};
    try {
      debug.step = 'getChatById';
      const chat = await client.getChatById(`${phoneE164}@c.us`);
      debug.gotChat = !!chat;
      debug.step = 'lastMessage';
      const lm = chat.lastMessage;
      debug.hasLastMessage = !!lm;
      if (!lm) return { ...debug, error: 'no_last_message' };
      return { fromMe: lm.fromMe, body: lm.body, timestamp: lm.timestamp };
    } catch (e) {
      return { ...debug, error: e && e.message, name: e && e.name, stack: e && String(e.stack || '').slice(0, 300) };
    }
  },

  // אבחון זמני: עד כמה הבעיה רחבה
  async debugScope() {
    const out = {};
    try { out.getState = client.getState ? await client.getState() : 'n/a'; } catch (e) { out.getStateErr = e.message; }
    try { const chats = await client.getChats(); out.getChats = { count: chats.length, sample: chats.slice(0, 3).map((c) => ({ id: c.id && c.id._serialized, name: c.name, hasLastMessage: !!c.lastMessage })) }; } catch (e) { out.getChatsErr = e.message; }
    try { const info = client.info; out.info = info ? { pushname: info.pushname, wid: info.wid && info.wid._serialized } : null; } catch (e) { out.infoErr = e.message; }
    try { const own = await client.getChatById(client.info.wid._serialized); out.getChatByIdOwn = { ok: true, name: own.name }; } catch (e) { out.getChatByIdOwnErr = e.message; }
    return out;
  },

  onIncomingMessage(cb) { incomingCb = cb; },

  requestPairingCode(number) {
    return client.requestPairingCode(String(number).replace(/\D/g, ''));
  },

  logout: () => client.logout(),
};
