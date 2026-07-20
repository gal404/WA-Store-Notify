const path = require('path');
const fs = require('fs');
const express = require('express');
const { local, state } = require('../config');
const pacer = require('../pacer');
const breaker = require('../breaker');

// דף סטטוס מקומי — 127.0.0.1 בלבד. WP לא יכול להגיע ל-PC, אז יש דף מקומי לפעולות.
function start(sender) {
  const app = express();
  app.use(express.json());

  const html = fs.readFileSync(path.join(__dirname, 'status.html'), 'utf8');

  app.get('/', (_req, res) => res.type('html').send(html));

  app.get('/api/status', async (_req, res) => {
    const st = sender.getState();
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
    });
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
