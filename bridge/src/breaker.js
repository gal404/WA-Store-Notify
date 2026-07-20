// מפסק זרם: N כשלונות שליחה רצופים → עצירה זמנית + סימון להתראה.
// טריפה שנייה בתוך 24ש → נשאר פתוח עד פקודת resume_breaker מ-WP.

const THRESHOLD = 3;
const COOLDOWN_MS = 30 * 60 * 1000;

let consecutive = 0;
let openUntil = 0;
let latch = false;         // דורש חידוש ידני
let lastTripAt = 0;

function recordSuccess() {
  consecutive = 0;
}

function recordFailure() {
  consecutive += 1;
  if (consecutive >= THRESHOLD) {
    const now = Date.now();
    if (now - lastTripAt < 24 * 3600 * 1000) {
      latch = true; // טריפה חוזרת ביממה — נעילה עד חידוש ידני
    }
    lastTripAt = now;
    openUntil = now + COOLDOWN_MS;
    consecutive = 0; // כדי שהטריפה הבאה תדרוש שוב 3 כשלונות רצופים חדשים, לא כשלון בודד
  }
}

function isOpen() {
  return latch || Date.now() < openUntil;
}

function resume() {
  latch = false;
  openUntil = 0;
  consecutive = 0;
}

function status() {
  return { open: isOpen(), consecutive_failures: consecutive, latched: latch };
}

module.exports = { recordSuccess, recordFailure, isOpen, resume, status };
