//
// AUTO-GENERATED FILE, DO NOT MODIFY!
//
// @dart=2.18

// ignore_for_file: unused_element, unused_import
// ignore_for_file: always_put_required_named_parameters_first
// ignore_for_file: constant_identifier_names
// ignore_for_file: lines_longer_than_80_chars

part of openapi.api;

class LockerApi {
  LockerApi([ApiClient? apiClient]) : apiClient = apiClient ?? defaultApiClient;

  final ApiClient apiClient;

  /// Returns a list of all available lockers
  ///
  /// Note: This method returns the HTTP [Response].
  Future<Response> adminLockersIndexWithHttpInfo() async {
    // ignore: prefer_const_declarations
    final path = r'/admin/lockers';

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

  /// Returns a list of all available lockers
  Future<List<Locker>?> adminLockersIndex() async {
    final response = await adminLockersIndexWithHttpInfo();
    if (response.statusCode >= HttpStatus.badRequest) {
      throw ApiException(response.statusCode, await _decodeBodyBytes(response));
    }
    // When a remote server returns no body with a status of 204, we shall not decode it.
    // At the time of writing this, `dart:convert` will throw an "Unexpected end of input"
    // FormatException when trying to decode an empty string.
    if (response.body.isNotEmpty &&
        response.statusCode != HttpStatus.noContent) {
      final responseBody = await _decodeBodyBytes(response);
      return (await apiClient.deserializeAsync(responseBody, 'List<Locker>')
              as List)
          .cast<Locker>()
          .toList(growable: false);
    }
    return null;
  }

  /// Manually opens a locker
  ///
  /// Note: This method returns the HTTP [Response].
  ///
  /// Parameters:
  ///
  /// * [int] locker (required):
  ///   The locker ID
  Future<Response> adminLockersOpenWithHttpInfo(
    int locker,
  ) async {
    // ignore: prefer_const_declarations
    final path = r'/admin/lockers/{locker}/open'
        .replaceAll('{locker}', locker.toString());

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

  /// Manually opens a locker
  ///
  /// Parameters:
  ///
  /// * [int] locker (required):
  ///   The locker ID
  Future<ItemsBorrow200Response?> adminLockersOpen(
    int locker,
  ) async {
    final response = await adminLockersOpenWithHttpInfo(
      locker,
    );
    if (response.statusCode >= HttpStatus.badRequest) {
      throw ApiException(response.statusCode, await _decodeBodyBytes(response));
    }
    // When a remote server returns no body with a status of 204, we shall not decode it.
    // At the time of writing this, `dart:convert` will throw an "Unexpected end of input"
    // FormatException when trying to decode an empty string.
    if (response.body.isNotEmpty &&
        response.statusCode != HttpStatus.noContent) {
      return await apiClient.deserializeAsync(
        await _decodeBodyBytes(response),
        'ItemsBorrow200Response',
      ) as ItemsBorrow200Response;
    }
    return null;
  }
}
