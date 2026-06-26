'use strict';

/**
 * Optional asset build. The plugin ships ready-to-run assets in assets/js and
 * assets/css, so this build step is NOT required for the plugin to function.
 * It compiles the source in assets/src into a minified bundle in assets/dist
 * for teams that want to extend the admin UI.
 */

const path = require('path');

module.exports = (env, argv) => {
  const mode = (argv && argv.mode) || 'production';
  return {
    mode,
    entry: {
      admin: path.resolve(__dirname, 'assets/src/admin.js'),
    },
    output: {
      path: path.resolve(__dirname, 'assets/dist'),
      filename: '[name].bundle.js',
      clean: true,
    },
    devtool: mode === 'production' ? false : 'source-map',
    module: {
      rules: [
        {
          test: /\.js$/,
          exclude: /node_modules/,
          use: [],
        },
      ],
    },
  };
};
