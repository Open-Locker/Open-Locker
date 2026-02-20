import { baseApi as api } from "./baseApi";
export const addTagTypes = [
  "Admin",
  "AppInfo",
  "Auth",
  "Compartment",
  "Item",
  "LockerBankStatus",
  "MosquittoAuth",
] as const;
const injectedRtkApi = api
  .enhanceEndpoints({
    addTagTypes,
  })
  .injectEndpoints({
    endpoints: (build) => ({
      getAdminUsers: build.query<GetAdminUsersApiResponse, GetAdminUsersApiArg>(
        {
          query: () => ({ url: `/admin/users` }),
          providesTags: ["Admin"],
        },
      ),
      postAdminUsersByUserMakeAdmin: build.mutation<
        PostAdminUsersByUserMakeAdminApiResponse,
        PostAdminUsersByUserMakeAdminApiArg
      >({
        query: (queryArg) => ({
          url: `/admin/users/${queryArg.user}/make-admin`,
          method: "POST",
        }),
        invalidatesTags: ["Admin"],
      }),
      postAdminUsersByUserRemoveAdmin: build.mutation<
        PostAdminUsersByUserRemoveAdminApiResponse,
        PostAdminUsersByUserRemoveAdminApiArg
      >({
        query: (queryArg) => ({
          url: `/admin/users/${queryArg.user}/remove-admin`,
          method: "POST",
        }),
        invalidatesTags: ["Admin"],
      }),
      getAdminStatistics: build.query<
        GetAdminStatisticsApiResponse,
        GetAdminStatisticsApiArg
      >({
        query: () => ({ url: `/admin/statistics` }),
        providesTags: ["Admin"],
      }),
      identify: build.query<IdentifyApiResponse, IdentifyApiArg>({
        query: () => ({ url: `/identify` }),
        providesTags: ["AppInfo"],
      }),
      postLogin: build.mutation<PostLoginApiResponse, PostLoginApiArg>({
        query: (queryArg) => ({
          url: `/login`,
          method: "POST",
          body: queryArg.body,
        }),
        invalidatesTags: ["Auth"],
      }),
      getPasswordEmail: build.query<
        GetPasswordEmailApiResponse,
        GetPasswordEmailApiArg
      >({
        query: (queryArg) => ({
          url: `/password/email`,
          params: {
            email: queryArg.email,
          },
        }),
        providesTags: ["Auth"],
      }),
      postResetPassword: build.mutation<
        PostResetPasswordApiResponse,
        PostResetPasswordApiArg
      >({
        query: (queryArg) => ({
          url: `/reset-password`,
          method: "POST",
          body: queryArg.body,
        }),
        invalidatesTags: ["Auth"],
      }),
      postLogout: build.mutation<PostLogoutApiResponse, PostLogoutApiArg>({
        query: () => ({ url: `/logout`, method: "POST" }),
        invalidatesTags: ["Auth"],
      }),
      getUser: build.query<GetUserApiResponse, GetUserApiArg>({
        query: () => ({ url: `/user` }),
        providesTags: ["Auth"],
      }),
      getVerifyEmailByIdAndHash: build.query<
        GetVerifyEmailByIdAndHashApiResponse,
        GetVerifyEmailByIdAndHashApiArg
      >({
        query: (queryArg) => ({
          url: `/verify-email/${queryArg.id}/${queryArg.hash}`,
        }),
        providesTags: ["Auth"],
      }),
      postEmailVerificationNotification: build.mutation<
        PostEmailVerificationNotificationApiResponse,
        PostEmailVerificationNotificationApiArg
      >({
        query: () => ({
          url: `/email/verification-notification`,
          method: "POST",
        }),
        invalidatesTags: ["Auth"],
      }),
      postAdminUsersRegister: build.mutation<
        PostAdminUsersRegisterApiResponse,
        PostAdminUsersRegisterApiArg
      >({
        query: (queryArg) => ({
          url: `/admin/users/register`,
          method: "POST",
          body: queryArg.body,
        }),
        invalidatesTags: ["Auth"],
      }),
      getCompartments: build.query<
        GetCompartmentsApiResponse,
        GetCompartmentsApiArg
      >({
        query: () => ({ url: `/compartments` }),
        providesTags: ["Compartment"],
      }),
      getCompartmentsAccessible: build.query<
        GetCompartmentsAccessibleApiResponse,
        GetCompartmentsAccessibleApiArg
      >({
        query: () => ({ url: `/compartments/accessible` }),
        providesTags: ["Compartment"],
      }),
      postCompartmentsByCompartmentOpen: build.mutation<
        PostCompartmentsByCompartmentOpenApiResponse,
        PostCompartmentsByCompartmentOpenApiArg
      >({
        query: (queryArg) => ({
          url: `/compartments/${queryArg.compartment}/open`,
          method: "POST",
        }),
        invalidatesTags: ["Compartment"],
      }),
      getCompartmentsOpenRequestsByCommandId: build.query<
        GetCompartmentsOpenRequestsByCommandIdApiResponse,
        GetCompartmentsOpenRequestsByCommandIdApiArg
      >({
        query: (queryArg) => ({
          url: `/compartments/open-requests/${queryArg.commandId}`,
        }),
        providesTags: ["Compartment"],
      }),
      getItems: build.query<GetItemsApiResponse, GetItemsApiArg>({
        query: () => ({ url: `/items` }),
        providesTags: ["Item"],
      }),
      getLockerBanksByLockerBankStatus: build.query<
        GetLockerBanksByLockerBankStatusApiResponse,
        GetLockerBanksByLockerBankStatusApiArg
      >({
        query: (queryArg) => ({
          url: `/locker-banks/${queryArg.lockerBank}/status`,
        }),
        providesTags: ["LockerBankStatus"],
      }),
      postMosqAuth: build.mutation<PostMosqAuthApiResponse, PostMosqAuthApiArg>(
        {
          query: (queryArg) => ({
            url: `/mosq/auth`,
            method: "POST",
            body: queryArg.authRequest,
          }),
          invalidatesTags: ["MosquittoAuth"],
        },
      ),
      postMosqAcl: build.mutation<PostMosqAclApiResponse, PostMosqAclApiArg>({
        query: (queryArg) => ({
          url: `/mosq/acl`,
          method: "POST",
          body: queryArg.aclRequest,
        }),
        invalidatesTags: ["MosquittoAuth"],
      }),
    }),
    overrideExisting: false,
  });
export { injectedRtkApi as openLockerApi };
export type GetAdminUsersApiResponse = /** status 200 Array of `User` */ User[];
export type GetAdminUsersApiArg = void;
export type PostAdminUsersByUserMakeAdminApiResponse = /** status 200  */ {
  message: string | any[] | null;
  user: User;
};
export type PostAdminUsersByUserMakeAdminApiArg = {
  /** The user ID */
  user: number;
};
export type PostAdminUsersByUserRemoveAdminApiResponse = /** status 200  */ {
  message: string | any[] | null;
  user: User;
};
export type PostAdminUsersByUserRemoveAdminApiArg = {
  /** The user ID */
  user: number;
};
export type GetAdminStatisticsApiResponse = /** status 200  */ {
  statistics: {
    /** Total number of users */
    total_users: number;
    /** Total number of items */
    total_items: number;
    /** Total number of access grants */
    total_compartment_accesses: number;
    /** Number of currently active grants */
    active_compartment_accesses: number;
  };
};
export type GetAdminStatisticsApiArg = void;
export type IdentifyApiResponse = /** status 200  */ {
  name: "Open-Locker";
  type: "backend";
  api_version: "v1";
  version: string;
  identifier: "open-locker-backend";
  environment: string;
  timestamp: string;
};
export type IdentifyApiArg = void;
export type PostLoginApiResponse =
  /** status 200 `TokenResponseResource` */ TokenResponse;
export type PostLoginApiArg = {
  body: {
    email: string;
    password: string;
  };
};
export type GetPasswordEmailApiResponse = /** status 200  */ {
  message: string | any[] | null;
};
export type GetPasswordEmailApiArg = {
  email: string;
};
export type PostResetPasswordApiResponse = /** status 200  */ {
  message: string | any[] | null;
};
export type PostResetPasswordApiArg = {
  body: {
    token: string;
    email: string;
    password: string;
    password_confirmation: string;
  };
};
export type PostLogoutApiResponse = /** status 200  */ {
  message: string | any[] | null;
};
export type PostLogoutApiArg = void;
export type GetUserApiResponse = /** status 200 `UserResource` */ User;
export type GetUserApiArg = void;
export type GetVerifyEmailByIdAndHashApiResponse =
  /** status 200 `ApiErrorResource` */
    | {
        message: string | any[] | null;
      }
    | ApiError;
export type GetVerifyEmailByIdAndHashApiArg = {
  id: string;
  hash: string;
};
export type PostEmailVerificationNotificationApiResponse =
  /** status 200 `ApiError` */
    | {
        message: string | any[] | null;
      }
    | ApiError;
export type PostEmailVerificationNotificationApiArg = void;
export type PostAdminUsersRegisterApiResponse =
  /** status 200 `TokenResponse` */ TokenResponse;
export type PostAdminUsersRegisterApiArg = {
  body: {
    name: string;
    email: string;
    password: string;
    password_confirmation: string;
  };
};
export type GetCompartmentsApiResponse =
  /** status 200 `AccessibleCompartmentsResource` */ AccessibleCompartments;
export type GetCompartmentsApiArg = void;
export type GetCompartmentsAccessibleApiResponse =
  /** status 200 `AccessibleCompartments` */ AccessibleCompartments;
export type GetCompartmentsAccessibleApiArg = void;
export type PostCompartmentsByCompartmentOpenApiResponse =
  /** status 200  */ 202;
export type PostCompartmentsByCompartmentOpenApiArg = {
  /** The compartment ID */
  compartment: string;
};
export type GetCompartmentsOpenRequestsByCommandIdApiResponse =
  /** status 200 `CompartmentOpenStatusResource` */ CompartmentOpenStatus;
export type GetCompartmentsOpenRequestsByCommandIdApiArg = {
  commandId: string;
};
export type GetItemsApiResponse =
  /** status 200 Array of `ItemResource` */ Item[];
export type GetItemsApiArg = void;
export type GetLockerBanksByLockerBankStatusApiResponse = /** status 200  */ {
  id: string;
  connection_status: string;
  connection_status_changed_at: string;
  last_heartbeat_at: string;
  heartbeat_interval_seconds: number;
  heartbeat_timeout_seconds: number;
};
export type GetLockerBanksByLockerBankStatusApiArg = {
  /** The locker bank ID */
  lockerBank: string;
};
export type PostMosqAuthApiResponse = /** status 200  */ {
  allow: boolean;
  ok: boolean;
};
export type PostMosqAuthApiArg = {
  authRequest: AuthRequest;
};
export type PostMosqAclApiResponse = /** status 200  */ string;
export type PostMosqAclApiArg = {
  aclRequest: AclRequest;
};
export type User = {
  id: number;
  name: string;
  email: string;
  email_verified_at?: string | null;
  is_admin: boolean;
  created_at?: string | null;
  updated_at?: string | null;
};
export type ApiError = {
  status: boolean;
  message: string;
};
export type TokenResponse = {
  token: string;
  name: string;
  verified: boolean;
};
export type AccessibleCompartments = {
  status: boolean;
  locker_banks: {
    id: string;
    name: string;
    location_description: string | null;
    compartments: {
      id: string;
      number: number;
      item: {
        id: string;
        name: string;
        description: string;
      } | null;
    }[];
  }[];
};
export type CompartmentOpenStatus = {
  status: boolean;
  command_id: string;
  state: string;
  compartment_id?: string | null;
  authorization_type?: string | null;
  error_code?: string | null;
  error_message?: string | null;
  denied_reason?: string | null;
  requested_at?: string | null;
  accepted_at?: string | null;
  denied_at?: string | null;
  sent_at?: string | null;
  opened_at?: string | null;
  failed_at?: string | null;
};
export type Item = {
  id: number;
  name: string;
  description: string;
  image_url?: string | null;
  compartment_id?: string | null;
  created_at?: string | null;
  updated_at?: string | null;
};
export type AuthRequest = {
  username: string;
  password?: string | null;
  clientid?: string | null;
};
export type AclRequest = {
  username: string;
  clientid: string;
  topic: string;
  acc: number;
};
export const {
  useGetAdminUsersQuery,
  usePostAdminUsersByUserMakeAdminMutation,
  usePostAdminUsersByUserRemoveAdminMutation,
  useGetAdminStatisticsQuery,
  useIdentifyQuery,
  usePostLoginMutation,
  useGetPasswordEmailQuery,
  usePostResetPasswordMutation,
  usePostLogoutMutation,
  useGetUserQuery,
  useGetVerifyEmailByIdAndHashQuery,
  usePostEmailVerificationNotificationMutation,
  usePostAdminUsersRegisterMutation,
  useGetCompartmentsQuery,
  useGetCompartmentsAccessibleQuery,
  usePostCompartmentsByCompartmentOpenMutation,
  useGetCompartmentsOpenRequestsByCommandIdQuery,
  useGetItemsQuery,
  useGetLockerBanksByLockerBankStatusQuery,
  usePostMosqAuthMutation,
  usePostMosqAclMutation,
} = injectedRtkApi;
