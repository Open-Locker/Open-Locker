const { defineConfig, globalIgnores } = require('eslint/config');
const expoConfig = require('eslint-config-expo/flat');
const eslintPluginPrettierRecommended = require('eslint-plugin-prettier/recommended');

module.exports = defineConfig([
  globalIgnores(['dist/*', '.expo/*']),
  expoConfig,
  eslintPluginPrettierRecommended,
  {
    files: ['**/*.test.js', '**/*.test.ts', '**/*.test.tsx'],
    rules: {
      'no-undef': 'off',
    },
  },
]);
