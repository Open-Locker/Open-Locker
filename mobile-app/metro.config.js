const { getDefaultConfig } = require('expo/metro-config');

// laravel-echo's package "main" points at a minified CJS build whose exported
// `Echo` class does not survive Metro's CJS interop ("constructor is not
// callable"). The ESM build (dist/echo.js) exports the class cleanly, so force
// Metro to resolve this one package to it.
const laravelEchoEsm = require.resolve('laravel-echo').replace(/echo\.common\.js$/, 'echo.js');

module.exports = (() => {
  const config = getDefaultConfig(__dirname);
  const { transformer, resolver } = config;

  config.transformer = {
    ...transformer,
    babelTransformerPath: require.resolve('react-native-svg-transformer/expo'),
  };
  config.resolver = {
    ...resolver,
    assetExts: resolver.assetExts.filter((ext) => ext !== 'svg'),
    sourceExts: [...resolver.sourceExts, 'svg'],
    resolveRequest: (context, moduleName, platform) => {
      if (moduleName === 'laravel-echo') {
        return { type: 'sourceFile', filePath: laravelEchoEsm };
      }
      return context.resolveRequest(context, moduleName, platform);
    },
  };

  return config;
})();
