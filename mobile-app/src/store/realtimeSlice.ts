import { createSlice, type PayloadAction } from '@reduxjs/toolkit';

type RealtimeState = {
  livePaused: boolean;
};

const initialState: RealtimeState = {
  livePaused: false,
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

const realtimeSlice = createSlice({
  name: 'realtime',
  initialState,
  reducers: {
    realtimeConnectionChanged(state, action: PayloadAction<string>) {
      state.livePaused = isLivePaused(state.livePaused, action.payload);
    },
    realtimeReset() {
      return initialState;
    },
  },
});

export const { realtimeConnectionChanged, realtimeReset } = realtimeSlice.actions;
export const realtimeReducer = realtimeSlice.reducer;
