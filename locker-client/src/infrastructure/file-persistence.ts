import fs from 'node:fs';
import path from 'node:path';
import { randomUUID } from 'node:crypto';

export interface AtomicWriteFileSystem {
  openSync: typeof fs.openSync;
  writeSync: typeof fs.writeSync;
  fsyncSync: typeof fs.fsyncSync;
  closeSync: typeof fs.closeSync;
  chmodSync: typeof fs.chmodSync;
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
  fileSystem: Pick<AtomicWriteFileSystem, 'mkdirSync'> = fs,
): void {
  fileSystem.mkdirSync(directoryPath, { recursive: true, mode: 0o700 });
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
    fileSystem.fsyncSync(descriptor);
    fileSystem.closeSync(descriptor);
    descriptor = null;
    fileSystem.chmodSync(temporaryPath, mode);
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
        throw new Error(`Failed to persist ${filePath}`, {
          cause: cleanupError,
        });
      }
    }
    throw error;
  }

  flushDirectoryBestEffort(directory, fileSystem);
}

function flushDirectoryBestEffort(
  directory: string,
  fileSystem: Pick<AtomicWriteFileSystem, 'openSync' | 'fsyncSync' | 'closeSync'>,
): void {
  let descriptor: number | null = null;
  try {
    descriptor = fileSystem.openSync(directory, 'r');
    fileSystem.fsyncSync(descriptor);
  } catch {
    // The rename already committed; directory fsync is not supported on every filesystem.
  } finally {
    if (descriptor !== null) {
      try {
        fileSystem.closeSync(descriptor);
      } catch {
        // The data file was already flushed and renamed.
      }
    }
  }
}

function isMissingFileError(error: unknown): boolean {
  return error instanceof Error && 'code' in error && error.code === 'ENOENT';
}
