/**
 * What the user is told about one open request. Collapses the backend's
 * `CompartmentOpenRequestStatus` into the steps a user can act on; the
 * synchronous refusals (terms, verification, access) never get here because
 * the open call itself answers them with an error.
 */
export type OpenProgress =
  | 'sending'
  | 'unlocking'
  | 'opened'
  | 'alreadyOpen'
  | 'jammed'
  | 'failed'
  | 'denied'
  | 'noResponse';

export type OpenProgressTone = 'pending' | 'success' | 'problem';

/**
 * The backend has no timeout of its own for a locker that never answers, so the
 * app stops waiting after this. It sits well past the locker client's door
 * detection window (one heartbeat interval, 10 s by default) plus MQTT latency.
 */
export const NO_RESPONSE_AFTER_MS = 45_000;

/** REST fallback for when realtime events are missed; realtime is the fast path. */
export const OPEN_STATUS_POLL_MS = 3_000;

const PROGRESS_BY_STATE: Record<string, OpenProgress> = {
  pending: 'sending',
  requested: 'sending',
  accepted: 'sending',
  sent: 'sending',
  acknowledged: 'unlocking',
  opened: 'opened',
  already_open: 'alreadyOpen',
  door_jammed: 'jammed',
  failed: 'failed',
  denied: 'denied',
};

const TONE: Record<OpenProgress, OpenProgressTone> = {
  sending: 'pending',
  unlocking: 'pending',
  opened: 'success',
  alreadyOpen: 'success',
  jammed: 'problem',
  failed: 'problem',
  denied: 'problem',
  noResponse: 'problem',
};

/** An unknown state is treated as still in flight, so the timeout still ends it. */
export function toOpenProgress(state: string): OpenProgress {
  return PROGRESS_BY_STATE[state] ?? 'sending';
}

export function isOpenFinished(progress: OpenProgress): boolean {
  return TONE[progress] !== 'pending';
}

export function openProgressTone(progress: OpenProgress): OpenProgressTone {
  return TONE[progress];
}

/** Events can arrive out of order; progress only ever moves forward. */
export function openProgressRank(progress: OpenProgress): number {
  if (progress === 'sending') return 0;
  if (progress === 'unlocking') return 1;
  return 2;
}

export function currentOpenProgress(state: string | undefined, timedOut: boolean): OpenProgress {
  const reported = state === undefined ? 'sending' : toOpenProgress(state);
  return timedOut && !isOpenFinished(reported) ? 'noResponse' : reported;
}

/** A first failure is worth a retry; Get help is offered after the second. */
export const GET_HELP_AFTER_PROBLEMS = 2;

/** Consecutive failed attempts on one compartment; a successful open resets the count. */
export function nextProblemCount(count: number, progress: OpenProgress): number {
  const tone = openProgressTone(progress);
  if (tone === 'problem') return count + 1;
  if (tone === 'success') return 0;
  return count;
}

/**
 * The open endpoint answers 202 with `{ command_id }`, but the generated client
 * types the body as the bare status code, so narrow it here at the boundary.
 */
export function readCommandId(response: unknown): string | null {
  if (typeof response !== 'object' || response === null) return null;
  const commandId = (response as { command_id?: unknown }).command_id;
  return typeof commandId === 'string' && commandId !== '' ? commandId : null;
}
