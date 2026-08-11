import fs from 'node:fs';
import path from 'node:path';
import { randomUUID } from 'node:crypto';

export interface AtomicWriteFileSystem {
  openSync: typeof fs.openSync;
  writeSync: typeof fs.writeSync;
  fsyncSync: typeof fs.fsyncSync;
  closeSync: typeof fs.closeSync;
  chmodSync: typeof fs.chmodSync;
  fchmodSync: typeof fs.fchmodSync;
  renameSync: typeof fs.renameSync;
  unlinkSync: typeof fs.unlinkSync;
  mkdirSync: typeof fs.mkdirSync;
}

interface AtomicWriteOptions {
  mode?: number;
  fileSystem?: AtomicWriteFileSystem;
}

export class PersistentStateCorruptedError extends Error {
  constructor(
    public readonly stateType: string,
    public readonly filePath: string,
    options?: ErrorOptions,
  ) {
    super(`${stateType} is corrupt or incomplete: ${filePath}`, options);
    this.name = 'PersistentStateCorruptedError';
  }
}

export function ensurePrivateDirectory(
  directoryPath: string,
  fileSystem: Pick<
    AtomicWriteFileSystem,
    'mkdirSync' | 'chmodSync' | 'openSync' | 'fchmodSync' | 'closeSync'
  > = fs,
): void {
  fileSystem.mkdirSync(directoryPath, { recursive: true, mode: 0o700 });
  if (process.platform === 'win32') {
    fileSystem.chmodSync(directoryPath, 0o700);
    return;
  }

  const descriptor = fileSystem.openSync(
    directoryPath,
    fs.constants.O_RDONLY | fs.constants.O_DIRECTORY | fs.constants.O_NOFOLLOW,
  );
  try {
    fileSystem.fchmodSync(descriptor, 0o700);
  } finally {
    fileSystem.closeSync(descriptor);
  }
}

export function readPrivateFileSync(filePath: string): Buffer {
  const noFollowFlag = process.platform === 'win32' ? 0 : fs.constants.O_NOFOLLOW;
  const descriptor = fs.openSync(filePath, fs.constants.O_RDONLY | noFollowFlag);
  try {
    fs.fchmodSync(descriptor, 0o600);
    return fs.readFileSync(descriptor);
  } finally {
    fs.closeSync(descriptor);
  }
}

export function atomicWriteFileSync(
  filePath: string,
  contents: string | Buffer,
  options: AtomicWriteOptions = {},
): void {
  const fileSystem = options.fileSystem ?? fs;
  const mode = options.mode ?? 0o600;
  const directory = path.dirname(filePath);
  const temporaryPath = path.join(
    directory,
    `.${path.basename(filePath)}.${process.pid}.${randomUUID()}.tmp`,
  );
  const buffer = Buffer.isBuffer(contents) ? contents : Buffer.from(contents, 'utf8');
  let descriptor: number | null = null;

  ensurePrivateDirectory(directory, fileSystem);

  try {
    descriptor = fileSystem.openSync(temporaryPath, 'wx', mode);
    let offset = 0;
    while (offset < buffer.length) {
      const written = fileSystem.writeSync(
        descriptor,
        buffer,
        offset,
        buffer.length - offset,
        null,
      );
      if (written === 0) {
        throw new Error(`Unable to complete persistent write: ${filePath}`);
      }
      offset += written;
    }
    fileSystem.fchmodSync(descriptor, mode);
    fileSystem.fsyncSync(descriptor);
    fileSystem.closeSync(descriptor);
    descriptor = null;
    fileSystem.renameSync(temporaryPath, filePath);
  } catch (error) {
    if (descriptor !== null) {
      try {
        fileSystem.closeSync(descriptor);
      } catch {
        // Preserve the original write error.
      }
    }
    try {
      fileSystem.unlinkSync(temporaryPath);
    } catch (cleanupError) {
      if (!isMissingFileError(cleanupError)) {
        throw persistenceCleanupError(filePath, error, cleanupError);
      }
    }
    throw error;
  }

  flushDirectoryBestEffort(directory, fileSystem);
}

function persistenceCleanupError(
  filePath: string,
  primaryError: unknown,
  cleanupError: unknown,
): AggregateError {
  return new AggregateError(
    [primaryError, cleanupError],
    `Failed to persist ${filePath} and remove its temporary file`,
    { cause: primaryError },
  );
}

function flushDirectoryBestEffort(
  directory: string,
  fileSystem: Pick<AtomicWriteFileSystem, 'openSync' | 'fsyncSync' | 'closeSync'>,
): void {
  let descriptor: number | null = null;
  let operationError: unknown;
  try {
    descriptor = fileSystem.openSync(directory, 'r');
    fileSystem.fsyncSync(descriptor);
  } catch (error) {
    operationError = error;
  }

  let closeError: unknown;
  if (descriptor !== null) {
    try {
      fileSystem.closeSync(descriptor);
    } catch (error) {
      closeError = error;
    }
  }

  if (operationError !== undefined && !isUnsupportedDirectoryFsyncError(operationError)) {
    const cause =
      closeError === undefined
        ? operationError
        : new AggregateError([operationError, closeError], 'Directory flush and close failed', {
            cause: operationError,
          });
    throw directoryFlushError(directory, cause);
  }
  if (closeError !== undefined) {
    throw directoryFlushError(directory, closeError);
  }
}

function directoryFlushError(directory: string, cause: unknown): Error {
  return new Error(
    `Persistent state rename completed, but directory flush failed for ${directory}`,
    {
      cause,
    },
  );
}

function isUnsupportedDirectoryFsyncError(error: unknown): boolean {
  if (!(error instanceof Error) || !('code' in error)) {
    return false;
  }
  if (error.code === 'EINVAL' || error.code === 'ENOTSUP' || error.code === 'EOPNOTSUPP') {
    return true;
  }
  return process.platform === 'win32' && (error.code === 'EISDIR' || error.code === 'EPERM');
}

function isMissingFileError(error: unknown): boolean {
  return error instanceof Error && 'code' in error && error.code === 'ENOENT';
}
