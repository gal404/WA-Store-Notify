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

    const sent = await client.sendMessage(chatId, outgoing);
    if (!sent) {
      // whatsapp-web.js לפעמים מחזיר undefined בלי לזרוק (תקלה בצד WhatsApp Web) —
      // בכוונה לא מנסים שוב כאן: יתכן שההודעה כן נמסרה, ורצון חוזר עלול לשלוח פעמיים.
      // נשארים עם דיווח כשל רגיל ל-backoff של ה-outbox (איטי יותר, בטוח יותר).
      throw new Error('sendMessage לא החזיר הודעה — יתכן כשל בוואטסאפ Web (או שכן נמסרה, לא בטוח)');
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

  onIncomingMessage(cb) { incomingCb = cb; },

  requestPairingCode(number) {
    return client.requestPairingCode(String(number).replace(/\D/g, ''));
  },

  logout: () => client.logout(),
};
