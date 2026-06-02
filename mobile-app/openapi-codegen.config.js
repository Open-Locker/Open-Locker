// Load .env (if present) so EXPO_PUBLIC_API_BASE_URL is available; in CI the
// variable may already be set in the environment, so a missing file is fine.
try {
  process.loadEnvFile();
} catch {
  // no .env file — rely on the ambient environment
}

const base = process.env.EXPO_PUBLIC_API_BASE_URL;

if (!base) {
  throw new Error(
    'EXPO_PUBLIC_API_BASE_URL is not set. Set it in .env before running pnpm generate:api.',
  );
}

module.exports = {
  schemaFile: new URL('/docs/api.json', base).href,
  apiFile: './src/store/baseApi.ts',
  apiImport: 'baseApi',
  outputFile: './src/store/generatedApi.ts',
  exportName: 'openLockerApi',
  hooks: true,
  tag: true,
};
