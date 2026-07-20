const { local } = require('./config');
const sender = require('./senders/wwebjs');
const worker = require('./worker');
const web = require('./web/server');

console.log('=== WA Store Notify — גשר ===');
if (!local.WP_URL || !local.API_KEY) {
  console.error('שים לב: WP_URL / API_KEY חסרים ב-.env — הגשר יעלה אבל לא יוכל למשוך הודעות.');
}

// דף סטטוס מקומי (127.0.0.1)
web.start(sender);

// חיבור וואטסאפ
sender.init();

// לולאת העבודה (heartbeat + claim/send/report)
worker.start(sender);

process.on('unhandledRejection', (r) => console.error('unhandledRejection:', r));
