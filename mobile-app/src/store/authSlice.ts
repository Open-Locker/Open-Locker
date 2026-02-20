import { createSlice, type PayloadAction } from '@reduxjs/toolkit';

type AuthState = {
  token: string | null;
  userName: string | null;
  isLoading: boolean;
};

const initialState: AuthState = {
  token: null,
  userName: null,
  isLoading: true,
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
    },
    clearCredentials(state) {
      state.token = null;
      state.userName = null;
      state.isLoading = false;
    },
  },
});

export const { restoreAuth, setCredentials, clearCredentials } = authSlice.actions;
export const authReducer = authSlice.reducer;
