const path = require('path');
const fs = require('fs');
const crypto = require('crypto');
const express = require('express');
const { local, state } = require('../config');
const pacer = require('../pacer');
const breaker = require('../breaker');
const wp = require('../wpclient');

// דף סטטוס מקומי — 127.0.0.1 בלבד. WP לא יכול להגיע ל-PC, אז יש דף מקומי לפעולות.
// השוואה עמידה ל-timing attacks (אורכים שונים -> false בלי לדלוף מידע)
function safeEqual(a, b) {
  const ba = Buffer.from(String(a));
  const bb = Buffer.from(String(b));
  if (ba.length !== bb.length) return false;
  return crypto.timingSafeEqual(ba, bb);
}

/**
 * הדשבורד מאזין על 127.0.0.1 בלבד, ולכן מקומית הוא בטוח בלי סיסמה.
 * ברגע שחושפים אותו החוצה (ngrok וכו') הבקשות עדיין מגיעות מ-127.0.0.1 —
 * המנהרה רצה על אותו מחשב — אז זיהוי לפי IP לא יעזור. לכן מזהים לפי הכותרות
 * ש-ngrok/פרוקסי מוסיפים, ודורשים אימות. נכשל-סגור: אם אין סיסמה מוגדרת,
 * בקשה שהגיעה דרך מנהרה נחסמת לגמרי במקום להיחשף בלי הגנה.
 * חשוב: ה-API הזה יכול לשלוח הודעות בשם החנות, לנתק את החשבון ולחשוף
 * מספרי לקוחות — חשיפה לא מאובטחת שלו היא בעיה אמיתית, לא תיאורטית.
 */
function guard(req, res, next) {
  const tunneled = !!(req.headers['x-forwarded-for'] || req.headers['x-forwarded-host'] || req.headers['ngrok-skip-browser-warning']);

  // גישה מקומית נשארת ללא חיכוך: מי שיש לו גישה למחשב יכול ממילא לקרוא את .env,
  // אז סיסמה כאן לא מוסיפה הגנה אמיתית — רק טרחה בכל פתיחה של הדשבורד.
  if (!tunneled) {
    return next();
  }

  if (!local.DASH_PASS) {
    return res.status(403).type('html').send(
      '<meta charset="utf-8"><div style="font-family:sans-serif;direction:rtl;padding:24px;max-width:520px;margin:auto">' +
      '<h2>הגישה מרחוק חסומה</h2>' +
      '<p>הדשבורד נחשף דרך מנהרה אבל לא הוגדרה סיסמה, והוא מאפשר לשלוח הודעות בשם החנות ולנתק את חשבון הוואטסאפ.</p>' +
      '<p>כדי לאפשר גישה מרחוק, הוסף ל-<code>bridge/.env</code>:<br><code>DASH_PASS=סיסמה-חזקה</code><br>ואז הפעל מחדש את הגשר.</p>' +
      '</div>'
    );
  }

  const header = req.headers.authorization || '';
  if (header.startsWith('Basic ')) {
    const decoded = Buffer.from(header.slice(6), 'base64').toString('utf8');
    const idx = decoded.indexOf(':');
    const user = decoded.slice(0, idx);
    const pass = decoded.slice(idx + 1);
    if (safeEqual(user, local.DASH_USER) && safeEqual(pass, local.DASH_PASS)) {
      return next();
    }
  }
  res.set('WWW-Authenticate', 'Basic realm="WA Store Notify", charset="UTF-8"');
  return res.status(401).send('נדרש אימות');
}

function start(sender) {
  const app = express();
  app.use(express.json());
  app.use(guard);

  const html = fs.readFileSync(path.join(__dirname, 'status.html'), 'utf8');

  app.get('/', (_req, res) => res.type('html').send(html));

  app.get('/api/status', async (_req, res) => {
    const st = sender.getState();
    let queue = null;
    try { queue = await wp.queue(20); } catch (e) { queue = { error: e.message }; }
    res.json({
      ok: true,
      state: st.state,
      me: st.me,
      hasQr: st.hasQr,
      qr: st.hasQr ? await sender.qrDataUrl() : null,
      counters: pacer.snapshot(),
      breaker: breaker.status(),
      warmup_day: pacer.warmupDay(),
      quiet: pacer.inQuietHours(),
      paused: !!state.commands.pause,
      wp_url: local.WP_URL,
      queue,
      rules: state.remote,
    });
  });

  // שליחה מיידית ישירות מהגשר — לא עוברת דרך תור ה-WP, לצורך בדיקה/אבחון מהיר בלבד.
  // עדיין עוברת דרך sender.sendText (אימות מספר + דימוי הקלדה), אבל בלי caps/שעות שקט/יומן.
  app.post('/api/send-now', async (req, res) => {
    const phone = String((req.body && req.body.phone) || '').replace(/\D/g, '');
    const text = String((req.body && req.body.text) || '').trim();
    if (!phone || !text) return res.status(400).json({ ok: false, error: 'צריך מספר וטקסט' });
    if (sender.getState().state !== 'ready') {
      return res.status(409).json({ ok: false, error: 'הוואטסאפ לא מחובר' });
    }
    try {
      const registered = await sender.verifyNumber(phone);
      if (!registered) return res.status(422).json({ ok: false, error: 'המספר הזה לא רשום בוואטסאפ' });
      const result = await sender.sendText(phone, text);
      res.json({ ok: true, waMsgId: result.waMsgId });
    } catch (e) {
      res.status(500).json({ ok: false, error: e.message });
    }
  });

  // היסטוריית הודעות מדפדפת — נקרא בנפרד מ-/api/status כדי שרענון הסטטוס
  // האוטומטי לא יקפיץ את המשתמש בחזרה לעמוד הראשון בזמן שהוא מדפדף.
  app.get('/api/history', async (req, res) => {
    const page = Math.max(1, parseInt(req.query.page, 10) || 1);
    const per = Math.min(50, Math.max(1, parseInt(req.query.per, 10) || 10));
    try {
      const data = await wp.history(page, per);
      // wpclient מחזיר null כשהתשובה אינה JSON (למשל דף שגיאה/תחזוקה עם קוד 200).
      // בלי הבדיקה הזו היינו מחזירים {ok:true} ריק, והדשבורד היה מציג "אין היסטוריה"
      // במקום לומר שהקריאה נכשלה — כלומר משקר למשתמש שאין הודעות.
      if (!data || !Array.isArray(data.items)) {
        return res.status(502).json({ ok: false, error: 'תשובה לא תקינה מ-WP (ייתכן שהתוסף לא מעודכן לגרסה שתומכת בהיסטוריה)' });
      }
      res.json({ ok: true, ...data });
    } catch (e) {
      res.status(502).json({ ok: false, error: e.message });
    }
  });

  // אבחון: ההודעה האחרונה בצ'אט — לבדוק אם הודעה שדווחה כ'נכשלה' בעצם נמסרה
  app.get('/api/recent-messages', async (req, res) => {
    const phone = String(req.query.phone || '').replace(/\D/g, '');
    if (!phone) return res.status(400).json({ ok: false, error: 'חסר מספר' });
    const lastMessage = await sender.getLastMessage(phone);
    res.json({ ok: true, lastMessage });
  });

  // אבחון זמני: עד כמה הבעיה רחבה — getChats() כללי מול getChatById ספציפי
  app.get('/api/debug-scope', async (_req, res) => {
    res.json(await sender.debugScope());
  });

  app.post('/api/pair', async (req, res) => {
    const number = String((req.body && req.body.number) || '').replace(/\D/g, '');
    if (!number) return res.status(400).json({ ok: false, error: 'מספר לא תקין' });
    if (sender.getState().state !== 'qr') return res.status(409).json({ ok: false, error: 'זמין רק בזמן המתנה לחיבור' });
    try {
      const code = await sender.requestPairingCode(number);
      res.json({ ok: true, code });
    } catch (e) {
      res.status(500).json({ ok: false, error: e.message });
    }
  });

  app.post('/api/logout', async (_req, res) => {
    try { await sender.logout(); res.json({ ok: true }); }
    catch (e) { res.status(500).json({ ok: false, error: e.message }); }
  });

  app.listen(local.PORT, '127.0.0.1', () =>
    console.log(`דף סטטוס מקומי: http://127.0.0.1:${local.PORT}`)
  );
}

module.exports = { start };
