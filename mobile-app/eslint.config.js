const { defineConfig, globalIgnores } = require('eslint/config');
const expoConfig = require('eslint-config-expo/flat');
const eslintPluginPrettierRecommended = require('eslint-plugin-prettier/recommended');

module.exports = defineConfig([
  globalIgnores(['dist/*', '.expo/*']),
  expoConfig,
  eslintPluginPrettierRecommended,
  {
    // SDK 57 adds compiler diagnostics for existing state/animation patterns.
    // Keep them visible without making a compiler refactor part of the SDK upgrade.
    rules: {
      'react-hooks/preserve-manual-memoization': 'warn',
      'react-hooks/refs': 'warn',
      'react-hooks/set-state-in-effect': 'warn',
    },
  },
  {
    files: ['**/*.test.js', '**/*.test.ts', '**/*.test.tsx'],
    rules: {
      'no-undef': 'off',
    },
  },
]);
