// מנוע "שגיאת כתיב" מכוונת — בוחר מילה עברית בטוחה ומשבש אות אחת.
// לעולם לא בתוך מספרים, קישורים, אנגלית, אימוג'י או מילים קצרות.

const HEB = /^[֐-׿]{4,}$/; // מילה עברית באורך 4+ (בלי ניקוד/סימנים)

function isSafeWord(w) {
  if (!HEB.test(w)) return false;
  if (/\d/.test(w)) return false;      // אין ספרות
  if (w.includes('http')) return false;
  return true;
}

/**
 * מקבל טקסט מלא, מחזיר { wrong } עם שיבוש אות במילה בטוחה אחת,
 * או null אם לא נמצאה מילה מתאימה (אז לא עושים typo).
 */
function makeTypo(text) {
  const tokens = text.split(/(\s+)/); // שומר רווחים
  const candidates = [];
  tokens.forEach((tok, i) => {
    if (isSafeWord(tok)) candidates.push(i);
  });
  if (!candidates.length) return null;

  const idx = candidates[Math.floor(Math.random() * candidates.length)];
  const word = tokens[idx];
  const pos = Math.floor(Math.random() * word.length);

  let broken;
  if (Math.random() < 0.5 && word.length > 4) {
    // השמטת אות
    broken = word.slice(0, pos) + word.slice(pos + 1);
  } else {
    // שכפול/החלפת אות שכנה (החלפת סדר שתי אותיות)
    const p = Math.min(pos, word.length - 2);
    broken = word.slice(0, p) + word[p + 1] + word[p] + word.slice(p + 2);
  }
  if (broken === word) return null;

  const arr = tokens.slice();
  arr[idx] = broken;
  return { wrong: arr.join('') };
}

module.exports = { makeTypo, isSafeWord };
