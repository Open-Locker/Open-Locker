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

/** After the app gave up waiting, a late locker answer can still come in. */
export const OPEN_STATUS_LATE_POLL_MS = 10_000;

/**
 * Polls until the backend reports an outcome, not until the app times out: a
 * late "door opened" or "did not open" must still reach the user when realtime
 * is down. Returns 0 to stop.
 */
export function openStatusPollInterval(state: string | undefined, timedOut: boolean): number {
  if (state !== undefined && isOpenFinished(toOpenProgress(state))) return 0;
  return timedOut ? OPEN_STATUS_LATE_POLL_MS : OPEN_STATUS_POLL_MS;
}

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

function openProgressRank(progress: OpenProgress): number {
  if (progress === 'sending') return 0;
  if (progress === 'unlocking') return 1;
  return 2;
}

/**
 * Whether a reported backend state may replace the one already known. Realtime
 * events arrive out of order and a slow poll can return after a newer event,
 * so progress only moves forward and a finished request never changes.
 */
export function isOpenStateAdvance(current: string, next: string): boolean {
  const currentProgress = toOpenProgress(current);
  return (
    !isOpenFinished(currentProgress) &&
    openProgressRank(toOpenProgress(next)) >= openProgressRank(currentProgress)
  );
}

export function currentOpenProgress(state: string | undefined, timedOut: boolean): OpenProgress {
  const reported = state === undefined ? 'sending' : toOpenProgress(state);
  return timedOut && !isOpenFinished(reported) ? 'noResponse' : reported;
}

/** A first failure is worth a retry; Get help is offered after the second. */
export const GET_HELP_AFTER_PROBLEMS = 2;

/** Consecutive failed open requests on one compartment. */
export type OpenProblemTally = {
  compartmentId: string | null;
  count: number;
  lastCommandId: string | null;
};

export const NO_OPEN_PROBLEMS: OpenProblemTally = {
  compartmentId: null,
  count: 0,
  lastCommandId: null,
};

/**
 * The tally to use when a compartment's sheet opens. Closing and reopening the
 * sheet to retry is what people do at a stuck door, so the count survives
 * that; it starts over only for a different compartment.
 */
export function tallyForCompartment(
  tally: OpenProblemTally,
  compartmentId: string,
): OpenProblemTally {
  return tally.compartmentId === compartmentId ? tally : { ...NO_OPEN_PROBLEMS, compartmentId };
}

/**
 * Counts each open request at most once: a request can report one problem after
 * another (no response, then a late "did not open"). A successful open resets
 * the count.
 */
export function tallyOpenOutcome(
  tally: OpenProblemTally,
  commandId: string,
  progress: OpenProgress,
): OpenProblemTally {
  const tone = openProgressTone(progress);
  if (tone === 'success') return { ...tally, count: 0, lastCommandId: commandId };
  if (tone !== 'problem' || tally.lastCommandId === commandId) return tally;
  return { ...tally, count: tally.count + 1, lastCommandId: commandId };
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
