const path = require('path');
require('dotenv').config({ path: path.join(__dirname, '..', '.env') });

const local = {
  WP_URL: (process.env.WP_URL || '').replace(/\/+$/, ''),
  API_KEY: process.env.API_KEY || '',
  PORT: Number(process.env.PORT || 3200),
  POLL_MS: Number(process.env.POLL_MS || 30000),
  WORKER_ID: process.env.WORKER_ID || 'pc-home',
};

// config מרוחק מגיע מתשובת ה-heartbeat; עד אז — ברירות מחדל בטוחות (שמרניות)
const DEFAULT_REMOTE = {
  tz: 'Asia/Jerusalem',
  transactional: { min_gap_s: 30, max_gap_s: 90, hourly_cap: 30, daily_cap: 150, quiet_from: '21:00', quiet_to: '08:00' },
  campaign: { min_gap_s: 90, max_gap_s: 240, hourly_cap: 15, daily_cap: 60, window_from: '10:00', window_to: '16:00' },
  warmup: { enabled: false, started: '', ramp: [10, 25, 50, 100] },
  typo_ratio: 0,
  optout_keywords: ['הסר'],
  optout_confirm: 'הוסרת מרשימת התפוצה שלנו.',
};

const state = {
  remote: DEFAULT_REMOTE,
  commands: { pause: false, resume_breaker: false },
};

function setRemote(config, commands) {
  if (config && typeof config === 'object') {
    state.remote = { ...DEFAULT_REMOTE, ...config };
  }
  if (commands && typeof commands === 'object') {
    state.commands = commands;
  }
}

module.exports = { local, state, setRemote, DEFAULT_REMOTE };
