import { baseApi as api } from "./baseApi";
export const addTagTypes = [
  "AppInfo",
  "Auth",
  "Compartment",
  "LockerBankStatus",
  "MosquittoAuth",
  "Organization",
  "Terms",
] as const;
const injectedRtkApi = api
  .enhanceEndpoints({
    addTagTypes,
  })
  .injectEndpoints({
    endpoints: (build) => ({
      identify: build.query<IdentifyApiResponse, IdentifyApiArg>({
        query: (queryArg) => ({
          url: `/identify`,
          headers: {
            "Accept-Language": queryArg["Accept-Language"],
          },
        }),
        providesTags: ["AppInfo"],
      }),
      postLogin: build.mutation<PostLoginApiResponse, PostLoginApiArg>({
        query: (queryArg) => ({
          url: `/login`,
          method: "POST",
          body: queryArg.loginRequest,
          headers: {
            "Accept-Language": queryArg["Accept-Language"],
          },
        }),
        invalidatesTags: ["Auth"],
      }),
      postPasswordEmail: build.mutation<
        PostPasswordEmailApiResponse,
        PostPasswordEmailApiArg
      >({
        query: (queryArg) => ({
          url: `/password/email`,
          method: "POST",
          body: queryArg.sendPasswordResetRequest,
          headers: {
            "Accept-Language": queryArg["Accept-Language"],
          },
        }),
        invalidatesTags: ["Auth"],
      }),
      postResetPassword: build.mutation<
        PostResetPasswordApiResponse,
        PostResetPasswordApiArg
      >({
        query: (queryArg) => ({
          url: `/reset-password`,
          method: "POST",
          body: queryArg.resetPasswordRequest,
          headers: {
            "Accept-Language": queryArg["Accept-Language"],
          },
        }),
        invalidatesTags: ["Auth"],
      }),
      postLogout: build.mutation<PostLogoutApiResponse, PostLogoutApiArg>({
        query: (queryArg) => ({
          url: `/logout`,
          method: "POST",
          headers: {
            "Accept-Language": queryArg["Accept-Language"],
          },
        }),
        invalidatesTags: ["Auth"],
      }),
      getUser: build.query<GetUserApiResponse, GetUserApiArg>({
        query: (queryArg) => ({
          url: `/user`,
          headers: {
            "Accept-Language": queryArg["Accept-Language"],
          },
        }),
        providesTags: ["Auth"],
      }),
      putProfile: build.mutation<PutProfileApiResponse, PutProfileApiArg>({
        query: (queryArg) => ({
          url: `/profile`,
          method: "PUT",
          body: queryArg.updateProfileRequest,
          headers: {
            "Accept-Language": queryArg["Accept-Language"],
          },
        }),
        invalidatesTags: ["Auth"],
      }),
      putPassword: build.mutation<PutPasswordApiResponse, PutPasswordApiArg>({
        query: (queryArg) => ({
          url: `/password`,
          method: "PUT",
          body: queryArg.changePasswordRequest,
          headers: {
            "Accept-Language": queryArg["Accept-Language"],
          },
        }),
        invalidatesTags: ["Auth"],
      }),
      getVerifyEmailByIdAndHash: build.query<
        GetVerifyEmailByIdAndHashApiResponse,
        GetVerifyEmailByIdAndHashApiArg
      >({
        query: (queryArg) => ({
          url: `/verify-email/${queryArg.id}/${queryArg.hash}`,
          headers: {
            "Accept-Language": queryArg["Accept-Language"],
          },
        }),
        providesTags: ["Auth"],
      }),
      postEmailVerificationNotification: build.mutation<
        PostEmailVerificationNotificationApiResponse,
        PostEmailVerificationNotificationApiArg
      >({
        query: (queryArg) => ({
          url: `/email/verification-notification`,
          method: "POST",
          headers: {
            "Accept-Language": queryArg["Accept-Language"],
          },
        }),
        invalidatesTags: ["Auth"],
      }),
      getCompartments: build.query<
        GetCompartmentsApiResponse,
        GetCompartmentsApiArg
      >({
        query: (queryArg) => ({
          url: `/compartments`,
          headers: {
            "Accept-Language": queryArg["Accept-Language"],
          },
        }),
        providesTags: ["Compartment"],
      }),
      getCompartmentsAccessible: build.query<
        GetCompartmentsAccessibleApiResponse,
        GetCompartmentsAccessibleApiArg
      >({
        query: (queryArg) => ({
          url: `/compartments/accessible`,
          headers: {
            "Accept-Language": queryArg["Accept-Language"],
          },
        }),
        providesTags: ["Compartment"],
      }),
      postCompartmentsByCompartmentOpen: build.mutation<
        PostCompartmentsByCompartmentOpenApiResponse,
        PostCompartmentsByCompartmentOpenApiArg
      >({
        query: (queryArg) => ({
          url: `/compartments/${queryArg.compartment}/open`,
          method: "POST",
          headers: {
            "Accept-Language": queryArg["Accept-Language"],
          },
        }),
        invalidatesTags: ["Compartment"],
      }),
      putCompartmentsByCompartmentContentNote: build.mutation<
        PutCompartmentsByCompartmentContentNoteApiResponse,
        PutCompartmentsByCompartmentContentNoteApiArg
      >({
        query: (queryArg) => ({
          url: `/compartments/${queryArg.compartment}/content-note`,
          method: "PUT",
          body: queryArg.updateCompartmentContentNoteRequest,
          headers: {
            "Accept-Language": queryArg["Accept-Language"],
          },
        }),
        invalidatesTags: ["Compartment"],
      }),
      getCompartmentsOpenRequestsByCommandId: build.query<
        GetCompartmentsOpenRequestsByCommandIdApiResponse,
        GetCompartmentsOpenRequestsByCommandIdApiArg
      >({
        query: (queryArg) => ({
          url: `/compartments/open-requests/${queryArg.commandId}`,
          headers: {
            "Accept-Language": queryArg["Accept-Language"],
          },
        }),
        providesTags: ["Compartment"],
      }),
      getLockerBanksByLockerBankStatus: build.query<
        GetLockerBanksByLockerBankStatusApiResponse,
        GetLockerBanksByLockerBankStatusApiArg
      >({
        query: (queryArg) => ({
          url: `/locker-banks/${queryArg.lockerBank}/status`,
          headers: {
            "Accept-Language": queryArg["Accept-Language"],
          },
        }),
        providesTags: ["LockerBankStatus"],
      }),
      postMosqAuth: build.mutation<PostMosqAuthApiResponse, PostMosqAuthApiArg>(
        {
          query: (queryArg) => ({
            url: `/mosq/auth`,
            method: "POST",
            body: queryArg.authRequest,
            headers: {
              "Accept-Language": queryArg["Accept-Language"],
            },
          }),
          invalidatesTags: ["MosquittoAuth"],
        },
      ),
      postMosqAcl: build.mutation<PostMosqAclApiResponse, PostMosqAclApiArg>({
        query: (queryArg) => ({
          url: `/mosq/acl`,
          method: "POST",
          body: queryArg.aclRequest,
          headers: {
            "Accept-Language": queryArg["Accept-Language"],
          },
        }),
        invalidatesTags: ["MosquittoAuth"],
      }),
      getOrganizations: build.query<
        GetOrganizationsApiResponse,
        GetOrganizationsApiArg
      >({
        query: (queryArg) => ({
          url: `/organizations`,
          headers: {
            "Accept-Language": queryArg["Accept-Language"],
          },
        }),
        providesTags: ["Organization"],
      }),
      getTermsCurrent: build.query<
        GetTermsCurrentApiResponse,
        GetTermsCurrentApiArg
      >({
        query: (queryArg) => ({
          url: `/terms/current`,
          headers: {
            "Accept-Language": queryArg["Accept-Language"],
          },
        }),
        providesTags: ["Terms"],
      }),
      postTermsAccept: build.mutation<
        PostTermsAcceptApiResponse,
        PostTermsAcceptApiArg
      >({
        query: (queryArg) => ({
          url: `/terms/accept`,
          method: "POST",
          headers: {
            "Accept-Language": queryArg["Accept-Language"],
          },
        }),
        invalidatesTags: ["Terms"],
      }),
    }),
    overrideExisting: false,
  });
export { injectedRtkApi as openLockerApi };
export type IdentifyApiResponse = /** status 200  */ {
  name: "Open-Locker";
  type: "backend";
  api_version: "v1";
  version: string;
  identifier: "open-locker-backend";
  environment: string;
  timestamp: string;
};
export type IdentifyApiArg = {
  /** Preferred language for server-rendered strings (API messages, web pages, and request-triggered emails). Falls back to the application default when omitted or unsupported. */
  "Accept-Language"?: "en" | "de";
};
export type PostLoginApiResponse =
  /** status 200 `TokenResponse` */ TokenResponse;
export type PostLoginApiArg = {
  /** Preferred language for server-rendered strings (API messages, web pages, and request-triggered emails). Falls back to the application default when omitted or unsupported. */
  "Accept-Language"?: "en" | "de";
  loginRequest: LoginRequest;
};
export type PostPasswordEmailApiResponse = /** status 200  */ {
  message: "Link zum Zur\u00FCcksetzen des Passworts gesendet";
};
export type PostPasswordEmailApiArg = {
  /** Preferred language for server-rendered strings (API messages, web pages, and request-triggered emails). Falls back to the application default when omitted or unsupported. */
  "Accept-Language"?: "en" | "de";
  sendPasswordResetRequest: SendPasswordResetRequest;
};
export type PostResetPasswordApiResponse = /** status 200  */ {
  message: string;
};
export type PostResetPasswordApiArg = {
  /** Preferred language for server-rendered strings (API messages, web pages, and request-triggered emails). Falls back to the application default when omitted or unsupported. */
  "Accept-Language"?: "en" | "de";
  resetPasswordRequest: ResetPasswordRequest;
};
export type PostLogoutApiResponse = /** status 200  */ {
  message: "Erfolgreich abgemeldet";
};
export type PostLogoutApiArg = {
  /** Preferred language for server-rendered strings (API messages, web pages, and request-triggered emails). Falls back to the application default when omitted or unsupported. */
  "Accept-Language"?: "en" | "de";
};
export type GetUserApiResponse = /** status 200 `User` */ User;
export type GetUserApiArg = {
  /** Preferred language for server-rendered strings (API messages, web pages, and request-triggered emails). Falls back to the application default when omitted or unsupported. */
  "Accept-Language"?: "en" | "de";
};
export type PutProfileApiResponse = /** status 200 `User` */ User;
export type PutProfileApiArg = {
  /** Preferred language for server-rendered strings (API messages, web pages, and request-triggered emails). Falls back to the application default when omitted or unsupported. */
  "Accept-Language"?: "en" | "de";
  updateProfileRequest: UpdateProfileRequest;
};
export type PutPasswordApiResponse = /** status 200  */ {
  message: "Passwort erfolgreich aktualisiert";
};
export type PutPasswordApiArg = {
  /** Preferred language for server-rendered strings (API messages, web pages, and request-triggered emails). Falls back to the application default when omitted or unsupported. */
  "Accept-Language"?: "en" | "de";
  changePasswordRequest: ChangePasswordRequest;
};
export type GetVerifyEmailByIdAndHashApiResponse = /** status 200 `ApiError` */
  | {
      message: "E-Mail best\u00E4tigt";
    }
  | ApiError;
export type GetVerifyEmailByIdAndHashApiArg = {
  id: string;
  hash: string;
  /** Preferred language for server-rendered strings (API messages, web pages, and request-triggered emails). Falls back to the application default when omitted or unsupported. */
  "Accept-Language"?: "en" | "de";
};
export type PostEmailVerificationNotificationApiResponse =
  /** status 200 `ApiError` */
    | {
        message: "Link zur E-Mail-Best\u00E4tigung gesendet";
      }
    | ApiError;
export type PostEmailVerificationNotificationApiArg = {
  /** Preferred language for server-rendered strings (API messages, web pages, and request-triggered emails). Falls back to the application default when omitted or unsupported. */
  "Accept-Language"?: "en" | "de";
};
export type GetCompartmentsApiResponse =
  /** status 200 `AccessibleCompartments` */ AccessibleCompartments;
export type GetCompartmentsApiArg = {
  /** Preferred language for server-rendered strings (API messages, web pages, and request-triggered emails). Falls back to the application default when omitted or unsupported. */
  "Accept-Language"?: "en" | "de";
};
export type GetCompartmentsAccessibleApiResponse =
  /** status 200 `AccessibleCompartments` */ AccessibleCompartments;
export type GetCompartmentsAccessibleApiArg = {
  /** Preferred language for server-rendered strings (API messages, web pages, and request-triggered emails). Falls back to the application default when omitted or unsupported. */
  "Accept-Language"?: "en" | "de";
};
export type PostCompartmentsByCompartmentOpenApiResponse =
  /** status 200  */ 202;
export type PostCompartmentsByCompartmentOpenApiArg = {
  /** The compartment ID */
  compartment: string;
  /** Preferred language for server-rendered strings (API messages, web pages, and request-triggered emails). Falls back to the application default when omitted or unsupported. */
  "Accept-Language"?: "en" | "de";
};
export type PutCompartmentsByCompartmentContentNoteApiResponse =
  /** status 200 `CompartmentContentNote` */ CompartmentContentNote;
export type PutCompartmentsByCompartmentContentNoteApiArg = {
  /** The compartment ID */
  compartment: string;
  /** Preferred language for server-rendered strings (API messages, web pages, and request-triggered emails). Falls back to the application default when omitted or unsupported. */
  "Accept-Language"?: "en" | "de";
  updateCompartmentContentNoteRequest: UpdateCompartmentContentNoteRequest;
};
export type GetCompartmentsOpenRequestsByCommandIdApiResponse =
  /** status 200 `CompartmentOpenStatus` */ CompartmentOpenStatus;
export type GetCompartmentsOpenRequestsByCommandIdApiArg = {
  commandId: string;
  /** Preferred language for server-rendered strings (API messages, web pages, and request-triggered emails). Falls back to the application default when omitted or unsupported. */
  "Accept-Language"?: "en" | "de";
};
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
  /** Preferred language for server-rendered strings (API messages, web pages, and request-triggered emails). Falls back to the application default when omitted or unsupported. */
  "Accept-Language"?: "en" | "de";
};
export type PostMosqAuthApiResponse = /** status 200  */ {
  allow: boolean;
  ok: boolean;
};
export type PostMosqAuthApiArg = {
  /** Preferred language for server-rendered strings (API messages, web pages, and request-triggered emails). Falls back to the application default when omitted or unsupported. */
  "Accept-Language"?: "en" | "de";
  authRequest: AuthRequest;
};
export type PostMosqAclApiResponse = /** status 200  */ string;
export type PostMosqAclApiArg = {
  /** Preferred language for server-rendered strings (API messages, web pages, and request-triggered emails). Falls back to the application default when omitted or unsupported. */
  "Accept-Language"?: "en" | "de";
  aclRequest: AclRequest;
};
export type GetOrganizationsApiResponse = /** status 200  */ {
  id: string;
  name: string;
  slug: string;
}[];
export type GetOrganizationsApiArg = {
  /** Preferred language for server-rendered strings (API messages, web pages, and request-triggered emails). Falls back to the application default when omitted or unsupported. */
  "Accept-Language"?: "en" | "de";
};
export type GetTermsCurrentApiResponse =
  /** status 200 `CurrentTerms` */ CurrentTerms;
export type GetTermsCurrentApiArg = {
  /** Preferred language for server-rendered strings (API messages, web pages, and request-triggered emails). Falls back to the application default when omitted or unsupported. */
  "Accept-Language"?: "en" | "de";
};
export type PostTermsAcceptApiResponse = /** status 200  */ {
  message: "Nutzungsbedingungen erfolgreich akzeptiert";
  accepted_version: number;
  accepted_at: string;
};
export type PostTermsAcceptApiArg = {
  /** Preferred language for server-rendered strings (API messages, web pages, and request-triggered emails). Falls back to the application default when omitted or unsupported. */
  "Accept-Language"?: "en" | "de";
};
export type TokenResponse = {
  token: string;
  first_name: string;
  last_name?: string | null;
  verified: boolean;
};
export type LoginRequest = {
  email: string;
  password: string;
};
export type SendPasswordResetRequest = {
  email: string;
};
export type ResetPasswordRequest = {
  token: string;
  email: string;
  password: string;
  password_confirmation: string;
};
export type User = {
  id: number;
  first_name: string;
  last_name?: string | null;
  email: string;
  email_verified_at?: string | null;
  is_admin: boolean;
  terms_last_accepted_version?: number | null;
  terms_current_version?: number | null;
  terms_current_accepted: boolean;
  created_at?: string | null;
  updated_at?: string | null;
};
export type UpdateProfileRequest = {
  first_name: string;
  last_name: string;
  email: string;
};
export type ChangePasswordRequest = {
  current_password: string;
  password: string;
  password_confirmation: string;
};
export type ApiError = {
  status: boolean;
  message: string;
};
export type AccessibleCompartments = {
  status: boolean;
  locker_banks: {
    id: string;
    name: string;
    location_description: string | null;
    last_compartment_state_change_at?: string | null;
    /** What the app colours each bank by, sent with the list so the
        first paint is right; the realtime event keeps it current.
        Never null: the column defaults to 'unknown'. Heartbeat
        timestamps stay on the dedicated status endpoint, which is
        the only caller that needs them. */
    connection_status: string;
    compartments: {
      id: string;
      number: number;
      door_state: string;
      door_state_changed_at?: string | null;
      content_note?: string | null;
      content_note_updated_at?: string | null;
      content_note_updated_by_user_id?: number | null;
    }[];
  }[];
};
export type CompartmentContentNote = {
  status: boolean;
  compartment_id: string;
  content_note?: string | null;
  content_note_updated_at: string;
  content_note_updated_by_user_id?: number | null;
};
export type UpdateCompartmentContentNoteRequest = {
  note?: string | null;
};
export type CompartmentOpenStatus = {
  status: boolean;
  command_id: string;
  /** `opened` now means the door was observed open, not that the unlock
    pulse was sent — that is `acknowledged`. */
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
  acknowledged_at?: string | null;
  opened_at?: string | null;
  open_detection_ms?: number | null;
  failed_at?: string | null;
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
export type CurrentTerms = {
  document_name: string;
  version: string;
  content: string;
  published_at: string;
  current_accepted: string;
};
export const {
  useIdentifyQuery,
  usePostLoginMutation,
  usePostPasswordEmailMutation,
  usePostResetPasswordMutation,
  usePostLogoutMutation,
  useGetUserQuery,
  usePutProfileMutation,
  usePutPasswordMutation,
  useGetVerifyEmailByIdAndHashQuery,
  usePostEmailVerificationNotificationMutation,
  useGetCompartmentsQuery,
  useGetCompartmentsAccessibleQuery,
  usePostCompartmentsByCompartmentOpenMutation,
  usePutCompartmentsByCompartmentContentNoteMutation,
  useGetCompartmentsOpenRequestsByCommandIdQuery,
  useGetLockerBanksByLockerBankStatusQuery,
  usePostMosqAuthMutation,
  usePostMosqAclMutation,
  useGetOrganizationsQuery,
  useGetTermsCurrentQuery,
  usePostTermsAcceptMutation,
} = injectedRtkApi;
