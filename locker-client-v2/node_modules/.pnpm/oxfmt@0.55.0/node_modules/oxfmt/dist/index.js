import { i as sortTailwindClasses, n as formatEmbeddedDoc, r as formatFile, t as formatEmbeddedCode } from "./apis-DkdEeIjV.js";
import { n as toNullable, t as toFormatFileResult } from "./napi-callbacks-Sb-usWTs.js";
//#region src-js/index.ts
/**
* Define an oxfmt configuration with type inference.
*/
function defineConfig(config) {
	return config;
}
let BINDINGS_CACHE = null;
/**
* Format the given source text according to the specified options.
*/
async function format(fileName, sourceText, options) {
	if (typeof fileName !== "string") throw new TypeError("`fileName` must be a string");
	if (typeof sourceText !== "string") throw new TypeError("`sourceText` must be a string");
	BINDINGS_CACHE ??= await import("./bindings-NuElWCOb.js").then((n) => n.t);
	return BINDINGS_CACHE.format(fileName, sourceText, options ?? {}, (options, code) => toFormatFileResult(formatFile({
		options,
		code
	})), (options, code) => toNullable(formatEmbeddedCode({
		options,
		code
	})), (options, texts) => toNullable(formatEmbeddedDoc({
		options,
		texts
	})), (options, classes) => toNullable(sortTailwindClasses({
		options,
		classes
	})));
}
/**
* Format a JS/TS snippet for Prettier `textToDoc()` plugin flow.
*/
async function jsTextToDoc(sourceExt, sourceText, oxfmtPluginOptionsJson, parentContext) {
	BINDINGS_CACHE ??= await import("./bindings-NuElWCOb.js").then((n) => n.t);
	return BINDINGS_CACHE.jsTextToDoc(sourceExt, sourceText, oxfmtPluginOptionsJson, parentContext, () => toFormatFileResult(Promise.reject("formatFile is unavailable for jsTextToDoc")), (options, code) => toNullable(formatEmbeddedCode({
		options,
		code
	})), (options, texts) => toNullable(formatEmbeddedDoc({
		options,
		texts
	})), (options, classes) => toNullable(sortTailwindClasses({
		options,
		classes
	})));
}
//#endregion
export { defineConfig, format, jsTextToDoc };
