import { configureStore } from '@reduxjs/toolkit';
import { setupListeners } from '@reduxjs/toolkit/query';

import { authReducer } from '@/src/store/authSlice';
import { baseApi } from '@/src/store/baseApi';
import { organizationReducer } from '@/src/store/organizationSlice';

export const store = configureStore({
  reducer: {
    auth: authReducer,
    organization: organizationReducer,
    [baseApi.reducerPath]: baseApi.reducer,
  },
  middleware: (getDefaultMiddleware) => getDefaultMiddleware().concat(baseApi.middleware),
});

setupListeners(store.dispatch);

export type RootState = ReturnType<typeof store.getState>;
export type AppDispatch = typeof store.dispatch;
