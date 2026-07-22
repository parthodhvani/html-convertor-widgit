const { join } = require('path');

// Keep Chromium cache inside the plugin so shared hosting / other users
// never try to write to a developer machine path like /home/parth.
module.exports = {
  cacheDirectory: join(__dirname, '.cache', 'puppeteer'),
};
