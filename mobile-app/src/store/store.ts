import { configureStore, createListenerMiddleware } from '@reduxjs/toolkit';
import { setupListeners } from '@reduxjs/toolkit/query';

import { authReducer } from '@/src/store/authSlice';
import { baseApi } from '@/src/store/baseApi';
import {
  organizationPersistenceListener,
  organizationReducer,
} from '@/src/store/organizationSlice';

const organizationPersistence = createListenerMiddleware();

organizationPersistence.startListening({
  matcher: organizationPersistenceListener.matcher as never,
  effect: async (action) => {
    await organizationPersistenceListener.effect(action as { type: string; payload?: unknown });
  },
});

export const store = configureStore({
  reducer: {
    auth: authReducer,
    organization: organizationReducer,
    [baseApi.reducerPath]: baseApi.reducer,
  },
  middleware: (getDefaultMiddleware) =>
    getDefaultMiddleware().prepend(organizationPersistence.middleware).concat(baseApi.middleware),
});

setupListeners(store.dispatch);

export type RootState = ReturnType<typeof store.getState>;
export type AppDispatch = typeof store.dispatch;
