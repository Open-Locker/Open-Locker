//
// AUTO-GENERATED FILE, DO NOT MODIFY!
//
// @dart=2.18

// ignore_for_file: unused_element, unused_import
// ignore_for_file: always_put_required_named_parameters_first
// ignore_for_file: constant_identifier_names
// ignore_for_file: lines_longer_than_80_chars

part of openapi.api;


class AuthApi {
  AuthApi([ApiClient? apiClient]) : apiClient = apiClient ?? defaultApiClient;

  final ApiClient apiClient;

  /// Register
  ///
  /// Note: This method returns the HTTP [Response].
  ///
  /// Parameters:
  ///
  /// * [AdminUsersRegisterRequest] adminUsersRegisterRequest (required):
  Future<Response> adminUsersRegisterWithHttpInfo(AdminUsersRegisterRequest adminUsersRegisterRequest,) async {
    // ignore: prefer_const_declarations
    final path = r'/admin/users/register';

    // ignore: prefer_final_locals
    Object? postBody = adminUsersRegisterRequest;

    final queryParams = <QueryParam>[];
    final headerParams = <String, String>{};
    final formParams = <String, String>{};

    const contentTypes = <String>['application/json'];


    return apiClient.invokeAPI(
      path,
      'POST',
      queryParams,
      postBody,
      headerParams,
      formParams,
      contentTypes.isEmpty ? null : contentTypes.first,
    );
  }

  /// Register
  ///
  /// Parameters:
  ///
  /// * [AdminUsersRegisterRequest] adminUsersRegisterRequest (required):
  Future<TokenResponse?> adminUsersRegister(AdminUsersRegisterRequest adminUsersRegisterRequest,) async {
    final response = await adminUsersRegisterWithHttpInfo(adminUsersRegisterRequest,);
    if (response.statusCode >= HttpStatus.badRequest) {
      throw ApiException(response.statusCode, await _decodeBodyBytes(response));
    }
    // When a remote server returns no body with a status of 204, we shall not decode it.
    // At the time of writing this, `dart:convert` will throw an "Unexpected end of input"
    // FormatException when trying to decode an empty string.
    if (response.body.isNotEmpty && response.statusCode != HttpStatus.noContent) {
      return await apiClient.deserializeAsync(await _decodeBodyBytes(response), 'TokenResponse',) as TokenResponse;
    
    }
    return null;
  }

  /// Login
  ///
  /// Note: This method returns the HTTP [Response].
  ///
  /// Parameters:
  ///
  /// * [AuthLoginRequest] authLoginRequest (required):
  Future<Response> authLoginWithHttpInfo(AuthLoginRequest authLoginRequest,) async {
    // ignore: prefer_const_declarations
    final path = r'/login';

    // ignore: prefer_final_locals
    Object? postBody = authLoginRequest;

    final queryParams = <QueryParam>[];
    final headerParams = <String, String>{};
    final formParams = <String, String>{};

    const contentTypes = <String>['application/json'];


    return apiClient.invokeAPI(
      path,
      'POST',
      queryParams,
      postBody,
      headerParams,
      formParams,
      contentTypes.isEmpty ? null : contentTypes.first,
    );
  }

  /// Login
  ///
  /// Parameters:
  ///
  /// * [AuthLoginRequest] authLoginRequest (required):
  Future<TokenResponse?> authLogin(AuthLoginRequest authLoginRequest,) async {
    final response = await authLoginWithHttpInfo(authLoginRequest,);
    if (response.statusCode >= HttpStatus.badRequest) {
      throw ApiException(response.statusCode, await _decodeBodyBytes(response));
    }
    // When a remote server returns no body with a status of 204, we shall not decode it.
    // At the time of writing this, `dart:convert` will throw an "Unexpected end of input"
    // FormatException when trying to decode an empty string.
    if (response.body.isNotEmpty && response.statusCode != HttpStatus.noContent) {
      return await apiClient.deserializeAsync(await _decodeBodyBytes(response), 'TokenResponse',) as TokenResponse;
    
    }
    return null;
  }

  /// Logout
  ///
  /// Note: This method returns the HTTP [Response].
  Future<Response> authLogoutWithHttpInfo() async {
    // ignore: prefer_const_declarations
    final path = r'/logout';

    // ignore: prefer_final_locals
    Object? postBody;

    final queryParams = <QueryParam>[];
    final headerParams = <String, String>{};
    final formParams = <String, String>{};

    const contentTypes = <String>[];


    return apiClient.invokeAPI(
      path,
      'POST',
      queryParams,
      postBody,
      headerParams,
      formParams,
      contentTypes.isEmpty ? null : contentTypes.first,
    );
  }

  /// Logout
  Future<AdminUsersMakeAdmin400Response?> authLogout() async {
    final response = await authLogoutWithHttpInfo();
    if (response.statusCode >= HttpStatus.badRequest) {
      throw ApiException(response.statusCode, await _decodeBodyBytes(response));
    }
    // When a remote server returns no body with a status of 204, we shall not decode it.
    // At the time of writing this, `dart:convert` will throw an "Unexpected end of input"
    // FormatException when trying to decode an empty string.
    if (response.body.isNotEmpty && response.statusCode != HttpStatus.noContent) {
      return await apiClient.deserializeAsync(await _decodeBodyBytes(response), 'AdminUsersMakeAdmin400Response',) as AdminUsersMakeAdmin400Response;
    
    }
    return null;
  }

  /// Get current User
  ///
  /// Note: This method returns the HTTP [Response].
  Future<Response> authUserWithHttpInfo() async {
    // ignore: prefer_const_declarations
    final path = r'/user';

    // ignore: prefer_final_locals
    Object? postBody;

    final queryParams = <QueryParam>[];
    final headerParams = <String, String>{};
    final formParams = <String, String>{};

    const contentTypes = <String>[];


    return apiClient.invokeAPI(
      path,
      'GET',
      queryParams,
      postBody,
      headerParams,
      formParams,
      contentTypes.isEmpty ? null : contentTypes.first,
    );
  }

  /// Get current User
  Future<User?> authUser() async {
    final response = await authUserWithHttpInfo();
    if (response.statusCode >= HttpStatus.badRequest) {
      throw ApiException(response.statusCode, await _decodeBodyBytes(response));
    }
    // When a remote server returns no body with a status of 204, we shall not decode it.
    // At the time of writing this, `dart:convert` will throw an "Unexpected end of input"
    // FormatException when trying to decode an empty string.
    if (response.body.isNotEmpty && response.statusCode != HttpStatus.noContent) {
      return await apiClient.deserializeAsync(await _decodeBodyBytes(response), 'User',) as User;
    
    }
    return null;
  }

  /// Send Password E-Mail
  ///
  /// Note: This method returns the HTTP [Response].
  ///
  /// Parameters:
  ///
  /// * [PasswordEmailRequest] passwordEmailRequest (required):
  Future<Response> passwordEmailWithHttpInfo(PasswordEmailRequest passwordEmailRequest,) async {
    // ignore: prefer_const_declarations
    final path = r'/password/email';

    // ignore: prefer_final_locals
    Object? postBody = passwordEmailRequest;

    final queryParams = <QueryParam>[];
    final headerParams = <String, String>{};
    final formParams = <String, String>{};

    const contentTypes = <String>['application/json'];


    return apiClient.invokeAPI(
      path,
      'POST',
      queryParams,
      postBody,
      headerParams,
      formParams,
      contentTypes.isEmpty ? null : contentTypes.first,
    );
  }

  /// Send Password E-Mail
  ///
  /// Parameters:
  ///
  /// * [PasswordEmailRequest] passwordEmailRequest (required):
  Future<AdminUsersMakeAdmin400Response?> passwordEmail(PasswordEmailRequest passwordEmailRequest,) async {
    final response = await passwordEmailWithHttpInfo(passwordEmailRequest,);
    if (response.statusCode >= HttpStatus.badRequest) {
      throw ApiException(response.statusCode, await _decodeBodyBytes(response));
    }
    // When a remote server returns no body with a status of 204, we shall not decode it.
    // At the time of writing this, `dart:convert` will throw an "Unexpected end of input"
    // FormatException when trying to decode an empty string.
    if (response.body.isNotEmpty && response.statusCode != HttpStatus.noContent) {
      return await apiClient.deserializeAsync(await _decodeBodyBytes(response), 'AdminUsersMakeAdmin400Response',) as AdminUsersMakeAdmin400Response;
    
    }
    return null;
  }

  /// Reset Password with Token
  ///
  /// Note: This method returns the HTTP [Response].
  ///
  /// Parameters:
  ///
  /// * [PasswordStoreRequest] passwordStoreRequest (required):
  Future<Response> passwordStoreWithHttpInfo(PasswordStoreRequest passwordStoreRequest,) async {
    // ignore: prefer_const_declarations
    final path = r'/reset-password';

    // ignore: prefer_final_locals
    Object? postBody = passwordStoreRequest;

    final queryParams = <QueryParam>[];
    final headerParams = <String, String>{};
    final formParams = <String, String>{};

    const contentTypes = <String>['application/json'];


    return apiClient.invokeAPI(
      path,
      'POST',
      queryParams,
      postBody,
      headerParams,
      formParams,
      contentTypes.isEmpty ? null : contentTypes.first,
    );
  }

  /// Reset Password with Token
  ///
  /// Parameters:
  ///
  /// * [PasswordStoreRequest] passwordStoreRequest (required):
  Future<AdminUsersMakeAdmin400Response?> passwordStore(PasswordStoreRequest passwordStoreRequest,) async {
    final response = await passwordStoreWithHttpInfo(passwordStoreRequest,);
    if (response.statusCode >= HttpStatus.badRequest) {
      throw ApiException(response.statusCode, await _decodeBodyBytes(response));
    }
    // When a remote server returns no body with a status of 204, we shall not decode it.
    // At the time of writing this, `dart:convert` will throw an "Unexpected end of input"
    // FormatException when trying to decode an empty string.
    if (response.body.isNotEmpty && response.statusCode != HttpStatus.noContent) {
      return await apiClient.deserializeAsync(await _decodeBodyBytes(response), 'AdminUsersMakeAdmin400Response',) as AdminUsersMakeAdmin400Response;
    
    }
    return null;
  }

  /// Send Email Verification Notification
  ///
  /// Note: This method returns the HTTP [Response].
  Future<Response> verificationSendWithHttpInfo() async {
    // ignore: prefer_const_declarations
    final path = r'/email/verification-notification';

    // ignore: prefer_final_locals
    Object? postBody;

    final queryParams = <QueryParam>[];
    final headerParams = <String, String>{};
    final formParams = <String, String>{};

    const contentTypes = <String>[];


    return apiClient.invokeAPI(
      path,
      'POST',
      queryParams,
      postBody,
      headerParams,
      formParams,
      contentTypes.isEmpty ? null : contentTypes.first,
    );
  }

  /// Send Email Verification Notification
  Future<AdminUsersMakeAdmin400Response?> verificationSend() async {
    final response = await verificationSendWithHttpInfo();
    if (response.statusCode >= HttpStatus.badRequest) {
      throw ApiException(response.statusCode, await _decodeBodyBytes(response));
    }
    // When a remote server returns no body with a status of 204, we shall not decode it.
    // At the time of writing this, `dart:convert` will throw an "Unexpected end of input"
    // FormatException when trying to decode an empty string.
    if (response.body.isNotEmpty && response.statusCode != HttpStatus.noContent) {
      return await apiClient.deserializeAsync(await _decodeBodyBytes(response), 'AdminUsersMakeAdmin400Response',) as AdminUsersMakeAdmin400Response;
    
    }
    return null;
  }

  /// Verify Email Address
  ///
  /// Note: This method returns the HTTP [Response].
  ///
  /// Parameters:
  ///
  /// * [String] id (required):
  ///
  /// * [String] hash (required):
  Future<Response> verificationVerifyWithHttpInfo(String id, String hash,) async {
    // ignore: prefer_const_declarations
    final path = r'/verify-email/{id}/{hash}'
      .replaceAll('{id}', id)
      .replaceAll('{hash}', hash);

    // ignore: prefer_final_locals
    Object? postBody;

    final queryParams = <QueryParam>[];
    final headerParams = <String, String>{};
    final formParams = <String, String>{};

    const contentTypes = <String>[];


    return apiClient.invokeAPI(
      path,
      'GET',
      queryParams,
      postBody,
      headerParams,
      formParams,
      contentTypes.isEmpty ? null : contentTypes.first,
    );
  }

  /// Verify Email Address
  ///
  /// Parameters:
  ///
  /// * [String] id (required):
  ///
  /// * [String] hash (required):
  Future<AdminUsersMakeAdmin400Response?> verificationVerify(String id, String hash,) async {
    final response = await verificationVerifyWithHttpInfo(id, hash,);
    if (response.statusCode >= HttpStatus.badRequest) {
      throw ApiException(response.statusCode, await _decodeBodyBytes(response));
    }
    // When a remote server returns no body with a status of 204, we shall not decode it.
    // At the time of writing this, `dart:convert` will throw an "Unexpected end of input"
    // FormatException when trying to decode an empty string.
    if (response.body.isNotEmpty && response.statusCode != HttpStatus.noContent) {
      return await apiClient.deserializeAsync(await _decodeBodyBytes(response), 'AdminUsersMakeAdmin400Response',) as AdminUsersMakeAdmin400Response;
    
    }
    return null;
  }
}
