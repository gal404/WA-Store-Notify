/**
 * לוג שקט לכשלים חוזרים.
 *
 * הבעיה שזה פותר: פנייה לאתר נכשלת מדי פעם מסיבות חולפות (גמגום רשת, 503
 * רגעי). כשכל כשל כזה נרשם כשורה אדומה, החלון מתמלא ברעש — וקשה להבחין
 * בתקלה אמיתית. כאן רושמים את הכשל הראשון, משתיקים את החזרות, ומדווחים
 * סיכום תקופתי; וכשהתקשורת חוזרת — רושמים שורה אחת שהכול תקין.
 */

const SUMMARY_EVERY_MS = 5 * 60 * 1000; // סיכום אחת לכמה דקות
const state = new Map(); // key -> { count, since, lastLog }

function fail(key, message) {
  const now = Date.now();
  const s = state.get(key) || { count: 0, since: now, lastLog: 0 };
  s.count += 1;

  if (s.count === 1) {
    console.error(message);           // הכשל הראשון — מדווח מיד
    s.lastLog = now;
  } else if (now - s.lastLog >= SUMMARY_EVERY_MS) {
    const mins = Math.max(1, Math.round((now - s.since) / 60000));
    console.error(`${message} — ${s.count} כשלונות ב-${mins} דקות האחרונות (מנסה שוב)`);
    s.lastLog = now;
  }
  state.set(key, s);
}

/** נקרא כשהפעולה הצליחה. אם קדמו לה כשלים — מדווח התאוששות ומאפס. */
function ok(key, message) {
  const s = state.get(key);
  if (s && s.count > 0) {
    console.log(message || `${key}: התקשורת חזרה לתקינות (אחרי ${s.count} כשלונות)`);
    state.delete(key);
  }
}

module.exports = { fail, ok };
