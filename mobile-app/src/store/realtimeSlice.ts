import { createSlice, type PayloadAction } from '@reduxjs/toolkit';

type RealtimeState = {
  /** The socket connection itself is down. */
  connectionPaused: boolean;
  /** Channels whose subscription the server refused while the socket stayed up. */
  failedChannels: string[];
};

const initialState: RealtimeState = {
  connectionPaused: false,
  failedChannels: [],
};

/**
 * Maps a Pusher connection state onto whether live updates are paused.
 * `initialized` and `connecting` keep the previous answer, so the first connect
 * does not flash a notice and a reconnect attempt does not hide one early.
 */
export function isLivePaused(previous: boolean, connectionState: string): boolean {
  switch (connectionState) {
    case 'connected':
      return false;
    case 'unavailable':
    case 'failed':
    case 'disconnected':
      return true;
    default:
      return previous;
  }
}

/**
 * Live updates are paused when the socket is down or any channel failed to
 * subscribe: a refused channel receives nothing while the socket looks fine.
 */
export function selectLivePaused(state: { realtime: RealtimeState }): boolean {
  return state.realtime.connectionPaused || state.realtime.failedChannels.length > 0;
}

const realtimeSlice = createSlice({
  name: 'realtime',
  initialState,
  reducers: {
    realtimeConnectionChanged(state, action: PayloadAction<string>) {
      state.connectionPaused = isLivePaused(state.connectionPaused, action.payload);
      // Pusher resubscribes every channel after (re)connecting, so earlier
      // refusals are stale; the new attempts report again.
      if (action.payload === 'connected') {
        state.failedChannels = [];
      }
    },
    realtimeSubscriptionFailed(state, action: PayloadAction<string>) {
      if (!state.failedChannels.includes(action.payload)) {
        state.failedChannels.push(action.payload);
      }
    },
    realtimeSubscriptionSucceeded(state, action: PayloadAction<string>) {
      state.failedChannels = state.failedChannels.filter((name) => name !== action.payload);
    },
    realtimeReset() {
      return initialState;
    },
  },
});

export const {
  realtimeConnectionChanged,
  realtimeSubscriptionFailed,
  realtimeSubscriptionSucceeded,
  realtimeReset,
} = realtimeSlice.actions;
export const realtimeReducer = realtimeSlice.reducer;
