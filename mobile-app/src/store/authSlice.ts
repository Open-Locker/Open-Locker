import { createSlice, type PayloadAction } from '@reduxjs/toolkit';

type AuthState = {
  token: string | null;
  userName: string | null;
  isLoading: boolean;
  sessionExpired: boolean;
};

const initialState: AuthState = {
  token: null,
  userName: null,
  isLoading: true,
  sessionExpired: false,
};

const authSlice = createSlice({
  name: 'auth',
  initialState,
  reducers: {
    restoreAuth(state, action: PayloadAction<{ token: string | null; userName: string | null }>) {
      state.token = action.payload.token;
      state.userName = action.payload.userName;
      state.isLoading = false;
    },
    setCredentials(state, action: PayloadAction<{ token: string; userName: string }>) {
      state.token = action.payload.token;
      state.userName = action.payload.userName;
      state.isLoading = false;
      state.sessionExpired = false;
    },
    clearCredentials(state) {
      state.token = null;
      state.userName = null;
      state.isLoading = false;
      state.sessionExpired = false;
    },
    markSessionExpired(state) {
      state.token = null;
      state.userName = null;
      state.isLoading = false;
      state.sessionExpired = true;
    },
  },
});

export const { restoreAuth, setCredentials, clearCredentials, markSessionExpired } =
  authSlice.actions;
export const authReducer = authSlice.reducer;
