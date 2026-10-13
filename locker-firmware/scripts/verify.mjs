#!/usr/bin/env node
/*
 * Static and host gates for the locker firmware.
 *
 * Runs every check a slice PR must pass without hardware; see section 4.1 of
 * docs/plans/esp32-rs485-hexagonal-client.md. Node built-ins only.
 *
 *     node scripts/verify.mjs                 # everything, as CI runs it
 *     node scripts/verify.mjs --skip-target   # without the ESP-IDF build
 *
 * The host build uses the default CMake generator and C compiler. Set the
 * CMAKE_GENERATOR and CC environment variables to choose others.
 */

import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import { SchemaSet } from './json-schema.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const REPO = path.dirname(ROOT);
const PLAN = path.join(REPO, 'docs', 'plans', 'esp32-rs485-hexagonal-client.md');
const COMPONENTS = path.join(ROOT, 'components');
const HOST_TESTS = path.join(ROOT, 'tests', 'host');
const BUILD = path.join(ROOT, 'build', 'verify');
const HOST_BUILD = path.join(BUILD, 'host');
const SCRIPTS = path.join(ROOT, 'scripts');
const SCHEMAS = path.join(REPO, 'docs', 'asyncapi', 'schemas');
// The host tests append every message they encode to this file.
const EMITTED_MESSAGES = path.join(BUILD, 'emitted-messages.jsonl');

// Slices whose plan IDs must all have a test. Extend it in each slice PR.
const IMPLEMENTED_SLICES = [1, 2, 3, 4, 5];

const MIN_LINE_COVERAGE = 90.0;

// What each component may include and require besides itself. A component not
// named here is an adapter. `main` is the composition root and is not checked.
const ALLOWED_DEPENDENCIES = {
  lc_domain: [],
  lc_ports: ['lc_domain'],
  lc_application: ['lc_domain', 'lc_ports'],
};
const ADAPTER_DEPENDENCIES = ['lc_domain', 'lc_ports'];

// Components that must build on a host: no SDK or RTOS header may appear in
// them. Their line coverage is measured.
const PURE_COMPONENTS = [
  'lc_domain',
  'lc_application',
  'lc_mqtt_codec',
  'lc_rs485_lock_board',
  'lc_journal',
];
const HEADER_ONLY_PURE_COMPONENTS = ['lc_ports'];
const STANDARD_HEADERS = [
  'assert.h',
  'ctype.h',
  'errno.h',
  'inttypes.h',
  'limits.h',
  'stdarg.h',
  'stdbool.h',
  'stddef.h',
  'stdint.h',
  'stdio.h',
  'stdlib.h',
  'string.h',
];
const EXTRA_PURE_HEADERS = { lc_mqtt_codec: ['cJSON.h'] };

// Pure files in components that also hold SDK code; analysed with the rest.
const EXTRA_ANALYSED_SOURCES = [
  'lc_usb_console/line.c',
  'lc_usb_console/console.c',
  'lc_usb_console/setup_json.c',
];

const INCLUDE = /^\s*#\s*include\s*[<"]([^>"]+)[>"]/gm;
const PLAN_ID = /^\| (S(\d+)-H(\d+)) \|/gm;
const TEST_DEFINITION = /^void (test_\w+)\(void\)/gm;
const TEST_RUN = /RUN_TEST\((test_\w+)\)/g;
const TEST_ID = /^test_S(\d+)_H(\d+)_/;
// Node tests of the tools: test('S5-H16 what it does', ...)
const TOOL_TEST_ID = /^test\('S(\d+)-H(\d+) /gm;

const read = (file) => fs.readFileSync(file, 'utf8');
const posix = (file) => file.split(path.sep).join('/');
const relative = (file) => posix(path.relative(ROOT, file));
const captures = (pattern, text) => [...text.matchAll(pattern)].map((match) => match[1]);

function directories(parent) {
  return fs
    .readdirSync(parent, { withFileTypes: true })
    .filter((entry) => entry.isDirectory())
    .map((entry) => path.join(parent, entry.name))
    .sort();
}

/** Files below `directory`, any depth, whose name matches `pattern`. */
function filesBelow(directory, pattern) {
  if (!fs.existsSync(directory)) {
    return [];
  }
  return fs
    .readdirSync(directory, { recursive: true, withFileTypes: true })
    .filter((entry) => entry.isFile() && pattern.test(entry.name))
    .map((entry) => path.join(entry.parentPath, entry.name))
    .sort();
}

/** `.c` files directly in a component, not in its subdirectories. */
function sourcesOf(component) {
  const directory = path.join(COMPONENTS, component);
  if (!fs.existsSync(directory)) {
    return [];
  }
  return fs
    .readdirSync(directory)
    .filter((name) => name.endsWith('.c'))
    .map((name) => path.join(directory, name))
    .sort();
}

const components = () => directories(COMPONENTS);

function which(program) {
  const extensions = process.platform === 'win32' ? (process.env.PATHEXT ?? '.EXE').split(';') : [''];
  for (const directory of (process.env.PATH ?? '').split(path.delimiter)) {
    for (const extension of extensions) {
      const candidate = path.join(directory, program + extension);
      if (directory && fs.existsSync(candidate) && fs.statSync(candidate).isFile()) {
        return candidate;
      }
    }
  }
  return null;
}

/** Every plan ID of an implemented slice has one test; no test invents one. */
function checkPlanIds() {
  const errors = [];
  const plan = read(PLAN);
  const listed = new Set();
  const planned = new Set();
  for (const match of plan.matchAll(PLAN_ID)) {
    listed.add(match[1]);
    if (IMPLEMENTED_SLICES.includes(Number(match[2]))) {
      planned.add(match[1]);
    }
  }
  if (planned.size === 0) {
    errors.push(`no plan IDs found in ${path.basename(PLAN)} for slices ${IMPLEMENTED_SLICES}`);
  }

  const tested = new Map();
  for (const source of filesBelow(HOST_TESTS, /^test_.*\.c$/).filter(
    (file) => path.dirname(file) === HOST_TESTS,
  )) {
    const name = path.basename(source);
    const text = read(source);
    const defined = captures(TEST_DEFINITION, text);
    const run = captures(TEST_RUN, text);
    for (const test of [...new Set(defined)].filter((test) => !run.includes(test)).sort()) {
      errors.push(`${name}: ${test} is defined but never run`);
    }
    for (const test of [...new Set(run)].filter((test) => !defined.includes(test)).sort()) {
      errors.push(`${name}: ${test} is run but not defined`);
    }
    for (const test of [...new Set(run)].sort()) {
      if (run.filter((other) => other === test).length > 1) {
        errors.push(`${name}: ${test} is run more than once`);
      }
    }
    for (const test of defined) {
      const match = TEST_ID.exec(test);
      if (match === null) {
        continue;
      }
      const planId = `S${Number(match[1])}-H${match[2]}`;
      if (tested.has(planId)) {
        errors.push(`${planId} is tested twice: ${tested.get(planId)} and ${test}`);
      }
      tested.set(planId, test);
      if (!listed.has(planId)) {
        errors.push(`${name}: ${test} carries an ID the plan does not list`);
      }
    }
  }

  for (const source of filesBelow(SCRIPTS, /\.test\.mjs$/)) {
    for (const match of read(source).matchAll(TOOL_TEST_ID)) {
      const planId = `S${Number(match[1])}-H${match[2]}`;
      if (tested.has(planId)) {
        errors.push(`${planId} is tested twice: ${tested.get(planId)} and ${path.basename(source)}`);
      }
      tested.set(planId, path.basename(source));
      if (!listed.has(planId)) {
        errors.push(`${path.basename(source)}: ${planId} is a test ID the plan does not list`);
      }
    }
  }

  for (const planId of [...planned].filter((id) => !tested.has(id)).sort()) {
    errors.push(`${planId} is in the plan but has no test`);
  }
  if (errors.length === 0) {
    console.log(`  ${planned.size} plan IDs, each with one test`);
  }
  return errors;
}

/** Includes and component requirements follow the allowed dependencies. */
function checkLayers() {
  const errors = [];
  let checked = 0;
  for (const component of components()) {
    const name = path.basename(component);
    const allowed = new Set([...(ALLOWED_DEPENDENCIES[name] ?? ADAPTER_DEPENDENCIES), name]);
    const pure = PURE_COMPONENTS.includes(name) || HEADER_ONLY_PURE_COMPONENTS.includes(name);
    const pureHeaders = new Set([...STANDARD_HEADERS, ...(EXTRA_PURE_HEADERS[name] ?? [])]);

    for (const source of filesBelow(component, /\.[ch]$/)) {
      checked += 1;
      for (const header of captures(INCLUDE, read(source))) {
        const owner = header.split('/', 1)[0];
        if (owner.startsWith('lc_')) {
          if (!allowed.has(owner)) {
            errors.push(`${relative(source)}: ${name} must not include ${header}`);
          }
        } else if (pure && !pureHeaders.has(header)) {
          errors.push(`${relative(source)}: ${name} is pure and must not include ${header}`);
        }
      }
    }

    const manifest = path.join(component, 'CMakeLists.txt');
    if (fs.existsSync(manifest)) {
      const required = new Set(read(manifest).match(/\blc_\w+/g) ?? []);
      for (const other of [...required].filter((other) => !allowed.has(other)).sort()) {
        errors.push(`${relative(manifest)}: ${name} must not require ${other}`);
      }
    }
  }
  if (errors.length === 0) {
    console.log(`  ${checked} files in ${components().length} components`);
  }
  return errors;
}

function includeFlags() {
  const flags = components()
    .map((component) => path.join(component, 'include'))
    .filter((include) => fs.existsSync(include))
    .map((include) => `-I${include}`);
  // cJSON is fetched by the host CMake project; see configureHost().
  const cjson = path.join(HOST_BUILD, '_deps', 'cjson-src');
  return fs.existsSync(cjson) ? [...flags, `-I${cjson}`] : flags;
}

function analysedSources() {
  return [
    ...PURE_COMPONENTS.flatMap(sourcesOf),
    ...EXTRA_ANALYSED_SOURCES.map((source) => path.join(COMPONENTS, source)),
  ];
}

/** Compiler static analysis of the pure sources; any finding fails. */
function checkStaticAnalysis() {
  if (!configureHost()) {
    return ['cmake configure failed'];
  }
  let command;
  if (which('clang')) {
    command = ['clang', '--analyze', '--analyzer-output', 'text', '-Xclang', '-analyzer-werror', '-o', '-'];
  } else if (which('gcc')) {
    command = ['gcc', '-fanalyzer', '-Werror', '-fsyntax-only'];
  } else {
    return ['neither clang nor gcc found for static analysis'];
  }

  const errors = [];
  const sources = analysedSources();
  for (const source of sources) {
    const result = spawnSync(
      command[0],
      [...command.slice(1), '-std=c11', '-Wall', '-Wextra', ...includeFlags(), source],
      { cwd: BUILD, encoding: 'utf8' },
    );
    const stderr = result.stderr ?? String(result.error ?? '');
    if (result.status !== 0 || stderr.includes('warning:')) {
      errors.push(`${relative(source)}:\n${stderr.trim()}`);
    }
  }
  if (errors.length === 0) {
    console.log(`  ${sources.length} sources analysed with ${command[0]}`);
  }
  return errors;
}

function run(command, cwd = ROOT) {
  console.log('  $ ' + command.join(' '));
  return spawnSync(command[0], command.slice(1), { cwd, stdio: 'inherit' }).status === 0;
}

let hostConfigured = null;

/** Configures the host CMake project once; it also fetches Unity and cJSON. */
function configureHost() {
  hostConfigured ??= run([
    'cmake',
    '-S',
    HOST_TESTS,
    '-B',
    HOST_BUILD,
    '-DCMAKE_BUILD_TYPE=Debug',
    '-DLC_SANITIZE=ON',
    '-DLC_COVERAGE=ON',
  ]);
  return hostConfigured;
}

/** Every host suite, built with the sanitizers and coverage recording. */
function checkHostTests() {
  const host = HOST_BUILD;
  // Counts from an earlier run would be added to this one.
  for (const stale of filesBelow(host, /\.gcda$/)) {
    fs.unlinkSync(stale);
  }
  fs.rmSync(EMITTED_MESSAGES, { force: true });
  process.env.LC_EMITTED_MESSAGES = EMITTED_MESSAGES;
  if (!configureHost()) {
    return ['`cmake -S` failed'];
  }
  const steps = [
    ['cmake', '--build', host],
    ['ctest', '--test-dir', host, '--output-on-failure'],
  ];
  for (const step of steps) {
    if (!run(step)) {
      return [`\`${step.slice(0, 2).join(' ')}\` failed`];
    }
  }
  return [];
}

/** The Node tests of the tools in scripts/, with the Node test runner. */
function checkToolTests() {
  const files = filesBelow(SCRIPTS, /\.test\.mjs$/);
  if (files.length === 0) {
    return [];
  }
  return run([process.execPath, '--test', ...files]) ? [] : ['the tool tests failed'];
}

/** Every message the host tests encoded is valid against its AsyncAPI schema. */
function checkEmittedMessages() {
  const lines = fs.existsSync(EMITTED_MESSAGES)
    ? read(EMITTED_MESSAGES)
        .split('\n')
        .filter((line) => line.trim() !== '')
    : [];
  if (lines.length === 0) {
    return ['no messages were recorded; did the host tests run?'];
  }

  const schemas = new SchemaSet(SCHEMAS);
  const errors = [];
  const seen = new Set();
  for (const [index, line] of lines.entries()) {
    try {
      const { schema, message } = JSON.parse(line);
      seen.add(schema);
      for (const problem of schemas.validateFile(path.join(SCHEMAS, schema), message)) {
        errors.push(`message ${index + 1} against ${schema}: ${problem}`);
      }
    } catch (error) {
      errors.push(`message ${index + 1}: ${error.message}`);
    }
  }
  if (errors.length === 0) {
    console.log(`  ${lines.length} messages valid against ${seen.size} schemas`);
  }
  return errors;
}

function gcovCommand() {
  const cache = read(path.join(BUILD, 'host', 'CMakeCache.txt'));
  const compiler = /^CMAKE_C_COMPILER:\w+=(.*)$/m.exec(cache);
  if (compiler && path.basename(compiler[1].trim()).includes('clang')) {
    return ['llvm-cov', 'gcov'];
  }
  return ['gcov'];
}

/** Line coverage of each pure component that has sources so far. */
function checkCoverage() {
  const notes = filesBelow(path.join(BUILD, 'host'), /\.gcda$/);
  if (notes.length === 0) {
    return ['no coverage data; did the host tests run?'];
  }
  const output = path.join(BUILD, 'coverage');
  fs.rmSync(output, { recursive: true, force: true });
  fs.mkdirSync(output, { recursive: true });

  const gcov = gcovCommand();
  const lines = new Map();
  for (const note of notes) {
    const result = spawnSync(gcov[0], [...gcov.slice(1), '-o', path.dirname(note), note], {
      cwd: output,
      encoding: 'utf8',
    });
    if (result.status !== 0) {
      const stderr = result.stderr ?? String(result.error ?? '');
      return [`${gcov.join(' ')} failed on ${path.basename(note)}:\n${stderr.trim()}`];
    }
    const reports = result.stdout.matchAll(/File '([^']+)'\s+Lines executed:([\d.]+)% of (\d+)/g);
    for (const [, file, percent, total] of reports) {
      // Sources only: an inline function in a header is counted once for every
      // file that includes it, with the lines that file never called.
      const match = /\/components\/(\w+)\/.*\.c$/.exec(file.replaceAll('\\', '/'));
      if (match === null || !PURE_COMPONENTS.includes(match[1])) {
        continue;
      }
      const counts = lines.get(match[1]) ?? [0, 0];
      counts[0] += Math.round((Number(percent) * Number(total)) / 100);
      counts[1] += Number(total);
      lines.set(match[1], counts);
    }
  }

  const errors = [];
  for (const name of PURE_COMPONENTS) {
    if (sourcesOf(name).length === 0) {
      continue;
    }
    if (!lines.has(name)) {
      errors.push(`${name} has sources but no coverage data`);
      continue;
    }
    const [covered, total] = lines.get(name);
    const percent = (100.0 * covered) / total;
    console.log(`  ${name}: ${percent.toFixed(1)}% of ${total} lines`);
    if (percent < MIN_LINE_COVERAGE) {
      errors.push(`${name} line coverage ${percent.toFixed(1)}% is below ${MIN_LINE_COVERAGE}%`);
    }
  }
  return errors;
}

/** ESP-IDF build for the board. Needs an ESP-IDF shell, with `idf.py` on the PATH. */
function checkTargetBuild() {
  const tool = which('idf.py');
  if (tool === null) {
    return ['idf.py not found; run from an ESP-IDF shell or pass --skip-target'];
  }
  return run([tool, 'build']) ? [] : ['target build failed'];
}

function main() {
  const options = process.argv.slice(2);
  const unknown = options.filter((option) => option !== '--skip-target');
  if (unknown.length > 0) {
    console.error(`usage: node scripts/verify.mjs [--skip-target]\nunknown: ${unknown.join(' ')}`);
    return 2;
  }
  // The target build then counts as not run, and the summary says so.
  const skipTarget = options.includes('--skip-target');

  fs.mkdirSync(BUILD, { recursive: true });
  const checks = [
    ['plan IDs', checkPlanIds],
    ['layer includes', checkLayers],
    ['static analysis', checkStaticAnalysis],
    ['host tests', checkHostTests],
    ['message schemas', checkEmittedMessages],
    ['tool tests', checkToolTests],
    ['line coverage', checkCoverage],
  ];
  if (!skipTarget) {
    checks.push(['target build', checkTargetBuild]);
  }

  const failed = [];
  for (const [name, check] of checks) {
    console.log(`== ${name}`);
    if ((name === 'line coverage' || name === 'message schemas') && failed.includes('host tests')) {
      console.log('  not run: the host tests failed');
      continue;
    }
    const errors = check();
    for (const error of errors) {
      console.log(`  FAIL ${error}`);
    }
    if (errors.length > 0) {
      failed.push(name);
    }
  }

  console.log();
  if (failed.length > 0) {
    console.log('FAILED: ' + failed.join(', '));
    return 1;
  }
  console.log(skipTarget ? 'PASSED, target build NOT RUN' : 'PASSED');
  return 0;
}

process.exit(main());
