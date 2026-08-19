const { local, state, setRemote } = require('./config');
const wp = require('./wpclient');
const pacer = require('./pacer');
const breaker = require('./breaker');
const optout = require('./optout');
const depcheck = require('./depcheck');
const quiet = require('./logquiet');

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

let sender = null;
let lastHeartbeat = 0;

async function heartbeat() {
  const st = sender.getState();
  const payload = {
    state: st.state,
    me: st.me,
    qr_data_url: st.hasQr ? await sender.qrDataUrl() : null,
    counters: pacer.snapshot(),
    breaker: breaker.status(),
    warmup_day: pacer.warmupDay(),
    version: require('../package.json').version,
    deps: await depcheck.checkDaily(),
  };
  try {
    const res = await wp.heartbeat(payload);
    if (res) {
      setRemote(res.config, res.commands);
      if (res.commands && res.commands.resume_breaker) {
        breaker.resume();
        console.log('התקבלה פקודת חידוש שליחה מ-WP');
      }
    }
    lastHeartbeat = Date.now();
    quiet.ok('heartbeat', 'heartbeat: התקשורת עם האתר חזרה לתקינות');
  } catch (e) {
    quiet.fail('heartbeat', 'heartbeat נכשל: ' + e.message);
  }
}

async function processOne(msg) {
  const kind = msg.kind || 'status';
  const report = { id: msg.id, claim_token: msg.claim_token };
  try {
    const registered = await sender.verifyNumber(msg.phone);
    if (!registered) {
      // לא מזין את מפסק הזרם — זה איתות איכות-נתונים (מספר לא בוואטסאפ), לא תקלת שליחה/חיבור
      Object.assign(report, { status: 'invalid_number' });
      return report;
    }
    const { waMsgId } = await sender.sendText(msg.phone, msg.body);
    Object.assign(report, { status: 'sent', wa_msg_id: waMsgId });
    breaker.recordSuccess();
    pacer.recordSent(kind);
  } catch (e) {
    Object.assign(report, { status: 'failed', error: e.message });
    breaker.recordFailure();
    console.error(`שליחה ל-${msg.phone} נכשלה:`, e.message);
  }
  return report;
}

async function loop() {
  // heartbeat אם הגיע הזמן
  if (Date.now() - lastHeartbeat > 60000) {
    await heartbeat();
  }

  const st = sender.getState();
  if (st.state !== 'ready') { await sleep(15000); return; }
  if (breaker.isOpen()) { await sleep(15000); return; }

  // בהשהיה כללית ממשיכים למשוך בכוונה — ה-claim בצד WP מחזיר אז רק הודעות
  // שהמנהל דחף ידנית ("שלח מיידית"/"שלח לי לבדיקה"). בלי זה הכפתורים האלה
  // נראים כאילו עבדו אבל ההודעה נתקעת בתור בלי שום סימן למה.
  const paused = !!state.commands.pause;

  // בהשהיה מתעלמים מ-caps/שעות שקט: ממילא יגיעו רק הודעות שנדחפו ידנית,
  // וכל הסוגים צריכים להיות זמינים כדי שהשרת יוכל להחזיר אותן.
  const kinds = paused
    ? ['status', 'item_change', 'free', 'test', 'campaign']
    : pacer.allowedKinds();
  if (!kinds.length) { await sleep(30000); return; }

  let batch = [];
  try {
    const res = await wp.claim(local.WORKER_ID, 3, kinds);
    batch = (res && res.messages) || [];
    quiet.ok('claim', 'claim: התקשורת עם האתר חזרה לתקינות');
  } catch (e) {
    quiet.fail('claim', 'claim נכשל: ' + e.message);
    await sleep(20000);
    return;
  }

  if (!batch.length) {
    await sleep(local.POLL_MS + Math.random() * 15000);
    return;
  }

  for (const msg of batch) {
    if (breaker.isOpen()) break;
    // בדיקה חוזרת של caps/שעות שקט לפני כל שליחה (batch עלול להתמשך) — מדלגים
    // עליה כשמושהים, כי אז כל מה שהתקבל הוא הודעה שהמנהל דחף ידנית ומצפה שתצא.
    if (!paused && (state.commands.pause || !pacer.allowedKinds().includes(msg.kind))) {
      break;
    }
    const report = await processOne(msg);
    // דיווח מיידי אחרי כל הודעה — קריסה מאבדת לכל היותר אחת (ה-claim יפוג ויוחזר)
    try {
      await wp.report([report]);
    } catch (e) {
      console.error('report נכשל:', e.message);
    }
    if (report.status === 'sent') {
      await sleep(pacer.gapAfter(msg.kind)); // המרווח האנושי
    } else {
      await sleep(2000);
    }
  }
}

async function start(senderRef) {
  sender = senderRef;
  optout.init(sender);
  console.log('worker התחיל. ממתין לחיבור וואטסאפ...');
  // לולאה אינסופית — כל איטרציה מגינה על עצמה
  for (;;) {
    try {
      await loop();
    } catch (e) {
      console.error('שגיאה בלולאת worker:', e.message);
      await sleep(10000);
    }
  }
}

module.exports = { start, heartbeat };
