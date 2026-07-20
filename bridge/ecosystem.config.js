module.exports = {
  apps: [
    {
      name: 'wa-store-notify',
      script: 'src/index.js',
      cwd: __dirname,
      autorestart: true,
      max_memory_restart: '600M',
      restart_delay: 5000,
    },
  ],
};
