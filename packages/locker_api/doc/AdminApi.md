# locker_api.api.AdminApi

## Load the API package
```dart
import 'package:locker_api/api.dart';
```

All URIs are relative to *http://open-locker.test/api*

Method | HTTP request | Description
------------- | ------------- | -------------
[**adminStatistics**](AdminApi.md#adminstatistics) | **GET** /admin/statistics | Returns statistics about the system
[**adminUsersIndex**](AdminApi.md#adminusersindex) | **GET** /admin/users | List of alle users
[**adminUsersMakeAdmin**](AdminApi.md#adminusersmakeadmin) | **POST** /admin/users/{user}/make-admin | Macht einen Benutzer zum Administrator
[**adminUsersRemoveAdmin**](AdminApi.md#adminusersremoveadmin) | **POST** /admin/users/{user}/remove-admin | Removes administrator rights from a user


# **adminStatistics**
> AdminStatistics200Response adminStatistics()

Returns statistics about the system

### Example
```dart
import 'package:locker_api/api.dart';
// TODO Configure HTTP Bearer authorization: http
// Case 1. Use String Token
//defaultApiClient.getAuthentication<HttpBearerAuth>('http').setAccessToken('YOUR_ACCESS_TOKEN');
// Case 2. Use Function which generate token.
// String yourTokenGeneratorFunction() { ... }
//defaultApiClient.getAuthentication<HttpBearerAuth>('http').setAccessToken(yourTokenGeneratorFunction);

final api_instance = AdminApi();

try {
    final result = api_instance.adminStatistics();
    print(result);
} catch (e) {
    print('Exception when calling AdminApi->adminStatistics: $e\n');
}
```

### Parameters
This endpoint does not need any parameter.

### Return type

[**AdminStatistics200Response**](AdminStatistics200Response.md)

### Authorization

[http](../README.md#http)

### HTTP request headers

 - **Content-Type**: Not defined
 - **Accept**: application/json

[[Back to top]](#) [[Back to API list]](../README.md#documentation-for-api-endpoints) [[Back to Model list]](../README.md#documentation-for-models) [[Back to README]](../README.md)

# **adminUsersIndex**
> List<User> adminUsersIndex()

List of alle users

### Example
```dart
import 'package:locker_api/api.dart';
// TODO Configure HTTP Bearer authorization: http
// Case 1. Use String Token
//defaultApiClient.getAuthentication<HttpBearerAuth>('http').setAccessToken('YOUR_ACCESS_TOKEN');
// Case 2. Use Function which generate token.
// String yourTokenGeneratorFunction() { ... }
//defaultApiClient.getAuthentication<HttpBearerAuth>('http').setAccessToken(yourTokenGeneratorFunction);

final api_instance = AdminApi();

try {
    final result = api_instance.adminUsersIndex();
    print(result);
} catch (e) {
    print('Exception when calling AdminApi->adminUsersIndex: $e\n');
}
```

### Parameters
This endpoint does not need any parameter.

### Return type

[**List<User>**](User.md)

### Authorization

[http](../README.md#http)

### HTTP request headers

 - **Content-Type**: Not defined
 - **Accept**: application/json

[[Back to top]](#) [[Back to API list]](../README.md#documentation-for-api-endpoints) [[Back to Model list]](../README.md#documentation-for-models) [[Back to README]](../README.md)

# **adminUsersMakeAdmin**
> AdminUsersMakeAdmin200Response adminUsersMakeAdmin(user)

Macht einen Benutzer zum Administrator

### Example
```dart
import 'package:locker_api/api.dart';
// TODO Configure HTTP Bearer authorization: http
// Case 1. Use String Token
//defaultApiClient.getAuthentication<HttpBearerAuth>('http').setAccessToken('YOUR_ACCESS_TOKEN');
// Case 2. Use Function which generate token.
// String yourTokenGeneratorFunction() { ... }
//defaultApiClient.getAuthentication<HttpBearerAuth>('http').setAccessToken(yourTokenGeneratorFunction);

final api_instance = AdminApi();
final user = 56; // int | The user ID

try {
    final result = api_instance.adminUsersMakeAdmin(user);
    print(result);
} catch (e) {
    print('Exception when calling AdminApi->adminUsersMakeAdmin: $e\n');
}
```

### Parameters

Name | Type | Description  | Notes
------------- | ------------- | ------------- | -------------
 **user** | **int**| The user ID | 

### Return type

[**AdminUsersMakeAdmin200Response**](AdminUsersMakeAdmin200Response.md)

### Authorization

[http](../README.md#http)

### HTTP request headers

 - **Content-Type**: Not defined
 - **Accept**: application/json

[[Back to top]](#) [[Back to API list]](../README.md#documentation-for-api-endpoints) [[Back to Model list]](../README.md#documentation-for-models) [[Back to README]](../README.md)

# **adminUsersRemoveAdmin**
> AdminUsersMakeAdmin200Response adminUsersRemoveAdmin(user)

Removes administrator rights from a user

### Example
```dart
import 'package:locker_api/api.dart';
// TODO Configure HTTP Bearer authorization: http
// Case 1. Use String Token
//defaultApiClient.getAuthentication<HttpBearerAuth>('http').setAccessToken('YOUR_ACCESS_TOKEN');
// Case 2. Use Function which generate token.
// String yourTokenGeneratorFunction() { ... }
//defaultApiClient.getAuthentication<HttpBearerAuth>('http').setAccessToken(yourTokenGeneratorFunction);

final api_instance = AdminApi();
final user = 56; // int | The user ID

try {
    final result = api_instance.adminUsersRemoveAdmin(user);
    print(result);
} catch (e) {
    print('Exception when calling AdminApi->adminUsersRemoveAdmin: $e\n');
}
```

### Parameters

Name | Type | Description  | Notes
------------- | ------------- | ------------- | -------------
 **user** | **int**| The user ID | 

### Return type

[**AdminUsersMakeAdmin200Response**](AdminUsersMakeAdmin200Response.md)

### Authorization

[http](../README.md#http)

### HTTP request headers

 - **Content-Type**: Not defined
 - **Accept**: application/json

[[Back to top]](#) [[Back to API list]](../README.md#documentation-for-api-endpoints) [[Back to Model list]](../README.md#documentation-for-models) [[Back to README]](../README.md)

