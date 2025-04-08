# locker_api.api.AuthApi

## Load the API package
```dart
import 'package:locker_api/api.dart';
```

All URIs are relative to *http://localhost/api*

Method | HTTP request | Description
------------- | ------------- | -------------
[**adminUsersRegister**](AuthApi.md#adminusersregister) | **POST** /admin/users/register | Register
[**authLogin**](AuthApi.md#authlogin) | **POST** /login | Login
[**authLogout**](AuthApi.md#authlogout) | **POST** /logout | Logout
[**authUser**](AuthApi.md#authuser) | **GET** /user | Get current User
[**passwordEmail**](AuthApi.md#passwordemail) | **POST** /password/email | Send Password E-Mail
[**passwordStore**](AuthApi.md#passwordstore) | **POST** /reset-password | Reset Password with Token
[**verificationSend**](AuthApi.md#verificationsend) | **POST** /email/verification-notification | Send Email Verification Notification
[**verificationVerify**](AuthApi.md#verificationverify) | **GET** /verify-email/{id}/{hash} | Verify Email Address


# **adminUsersRegister**
> TokenResponse adminUsersRegister(adminUsersRegisterRequest)

Register

### Example
```dart
import 'package:locker_api/api.dart';
// TODO Configure HTTP Bearer authorization: http
// Case 1. Use String Token
//defaultApiClient.getAuthentication<HttpBearerAuth>('http').setAccessToken('YOUR_ACCESS_TOKEN');
// Case 2. Use Function which generate token.
// String yourTokenGeneratorFunction() { ... }
//defaultApiClient.getAuthentication<HttpBearerAuth>('http').setAccessToken(yourTokenGeneratorFunction);

final api_instance = AuthApi();
final adminUsersRegisterRequest = AdminUsersRegisterRequest(); // AdminUsersRegisterRequest | 

try {
    final result = api_instance.adminUsersRegister(adminUsersRegisterRequest);
    print(result);
} catch (e) {
    print('Exception when calling AuthApi->adminUsersRegister: $e\n');
}
```

### Parameters

Name | Type | Description  | Notes
------------- | ------------- | ------------- | -------------
 **adminUsersRegisterRequest** | [**AdminUsersRegisterRequest**](AdminUsersRegisterRequest.md)|  | 

### Return type

[**TokenResponse**](TokenResponse.md)

### Authorization

[http](../README.md#http)

### HTTP request headers

 - **Content-Type**: application/json
 - **Accept**: application/json

[[Back to top]](#) [[Back to API list]](../README.md#documentation-for-api-endpoints) [[Back to Model list]](../README.md#documentation-for-models) [[Back to README]](../README.md)

# **authLogin**
> TokenResponse authLogin(authLoginRequest)

Login

### Example
```dart
import 'package:locker_api/api.dart';

final api_instance = AuthApi();
final authLoginRequest = AuthLoginRequest(); // AuthLoginRequest | 

try {
    final result = api_instance.authLogin(authLoginRequest);
    print(result);
} catch (e) {
    print('Exception when calling AuthApi->authLogin: $e\n');
}
```

### Parameters

Name | Type | Description  | Notes
------------- | ------------- | ------------- | -------------
 **authLoginRequest** | [**AuthLoginRequest**](AuthLoginRequest.md)|  | 

### Return type

[**TokenResponse**](TokenResponse.md)

### Authorization

No authorization required

### HTTP request headers

 - **Content-Type**: application/json
 - **Accept**: application/json

[[Back to top]](#) [[Back to API list]](../README.md#documentation-for-api-endpoints) [[Back to Model list]](../README.md#documentation-for-models) [[Back to README]](../README.md)

# **authLogout**
> AdminUsersMakeAdmin400Response authLogout()

Logout

### Example
```dart
import 'package:locker_api/api.dart';
// TODO Configure HTTP Bearer authorization: http
// Case 1. Use String Token
//defaultApiClient.getAuthentication<HttpBearerAuth>('http').setAccessToken('YOUR_ACCESS_TOKEN');
// Case 2. Use Function which generate token.
// String yourTokenGeneratorFunction() { ... }
//defaultApiClient.getAuthentication<HttpBearerAuth>('http').setAccessToken(yourTokenGeneratorFunction);

final api_instance = AuthApi();

try {
    final result = api_instance.authLogout();
    print(result);
} catch (e) {
    print('Exception when calling AuthApi->authLogout: $e\n');
}
```

### Parameters
This endpoint does not need any parameter.

### Return type

[**AdminUsersMakeAdmin400Response**](AdminUsersMakeAdmin400Response.md)

### Authorization

[http](../README.md#http)

### HTTP request headers

 - **Content-Type**: Not defined
 - **Accept**: application/json

[[Back to top]](#) [[Back to API list]](../README.md#documentation-for-api-endpoints) [[Back to Model list]](../README.md#documentation-for-models) [[Back to README]](../README.md)

# **authUser**
> User authUser()

Get current User

### Example
```dart
import 'package:locker_api/api.dart';
// TODO Configure HTTP Bearer authorization: http
// Case 1. Use String Token
//defaultApiClient.getAuthentication<HttpBearerAuth>('http').setAccessToken('YOUR_ACCESS_TOKEN');
// Case 2. Use Function which generate token.
// String yourTokenGeneratorFunction() { ... }
//defaultApiClient.getAuthentication<HttpBearerAuth>('http').setAccessToken(yourTokenGeneratorFunction);

final api_instance = AuthApi();

try {
    final result = api_instance.authUser();
    print(result);
} catch (e) {
    print('Exception when calling AuthApi->authUser: $e\n');
}
```

### Parameters
This endpoint does not need any parameter.

### Return type

[**User**](User.md)

### Authorization

[http](../README.md#http)

### HTTP request headers

 - **Content-Type**: Not defined
 - **Accept**: application/json

[[Back to top]](#) [[Back to API list]](../README.md#documentation-for-api-endpoints) [[Back to Model list]](../README.md#documentation-for-models) [[Back to README]](../README.md)

# **passwordEmail**
> AdminUsersMakeAdmin400Response passwordEmail(passwordEmailRequest)

Send Password E-Mail

### Example
```dart
import 'package:locker_api/api.dart';
// TODO Configure HTTP Bearer authorization: http
// Case 1. Use String Token
//defaultApiClient.getAuthentication<HttpBearerAuth>('http').setAccessToken('YOUR_ACCESS_TOKEN');
// Case 2. Use Function which generate token.
// String yourTokenGeneratorFunction() { ... }
//defaultApiClient.getAuthentication<HttpBearerAuth>('http').setAccessToken(yourTokenGeneratorFunction);

final api_instance = AuthApi();
final passwordEmailRequest = PasswordEmailRequest(); // PasswordEmailRequest | 

try {
    final result = api_instance.passwordEmail(passwordEmailRequest);
    print(result);
} catch (e) {
    print('Exception when calling AuthApi->passwordEmail: $e\n');
}
```

### Parameters

Name | Type | Description  | Notes
------------- | ------------- | ------------- | -------------
 **passwordEmailRequest** | [**PasswordEmailRequest**](PasswordEmailRequest.md)|  | 

### Return type

[**AdminUsersMakeAdmin400Response**](AdminUsersMakeAdmin400Response.md)

### Authorization

[http](../README.md#http)

### HTTP request headers

 - **Content-Type**: application/json
 - **Accept**: application/json

[[Back to top]](#) [[Back to API list]](../README.md#documentation-for-api-endpoints) [[Back to Model list]](../README.md#documentation-for-models) [[Back to README]](../README.md)

# **passwordStore**
> AdminUsersMakeAdmin400Response passwordStore(passwordStoreRequest)

Reset Password with Token

### Example
```dart
import 'package:locker_api/api.dart';
// TODO Configure HTTP Bearer authorization: http
// Case 1. Use String Token
//defaultApiClient.getAuthentication<HttpBearerAuth>('http').setAccessToken('YOUR_ACCESS_TOKEN');
// Case 2. Use Function which generate token.
// String yourTokenGeneratorFunction() { ... }
//defaultApiClient.getAuthentication<HttpBearerAuth>('http').setAccessToken(yourTokenGeneratorFunction);

final api_instance = AuthApi();
final passwordStoreRequest = PasswordStoreRequest(); // PasswordStoreRequest | 

try {
    final result = api_instance.passwordStore(passwordStoreRequest);
    print(result);
} catch (e) {
    print('Exception when calling AuthApi->passwordStore: $e\n');
}
```

### Parameters

Name | Type | Description  | Notes
------------- | ------------- | ------------- | -------------
 **passwordStoreRequest** | [**PasswordStoreRequest**](PasswordStoreRequest.md)|  | 

### Return type

[**AdminUsersMakeAdmin400Response**](AdminUsersMakeAdmin400Response.md)

### Authorization

[http](../README.md#http)

### HTTP request headers

 - **Content-Type**: application/json
 - **Accept**: application/json

[[Back to top]](#) [[Back to API list]](../README.md#documentation-for-api-endpoints) [[Back to Model list]](../README.md#documentation-for-models) [[Back to README]](../README.md)

# **verificationSend**
> AdminUsersMakeAdmin400Response verificationSend()

Send Email Verification Notification

### Example
```dart
import 'package:locker_api/api.dart';
// TODO Configure HTTP Bearer authorization: http
// Case 1. Use String Token
//defaultApiClient.getAuthentication<HttpBearerAuth>('http').setAccessToken('YOUR_ACCESS_TOKEN');
// Case 2. Use Function which generate token.
// String yourTokenGeneratorFunction() { ... }
//defaultApiClient.getAuthentication<HttpBearerAuth>('http').setAccessToken(yourTokenGeneratorFunction);

final api_instance = AuthApi();

try {
    final result = api_instance.verificationSend();
    print(result);
} catch (e) {
    print('Exception when calling AuthApi->verificationSend: $e\n');
}
```

### Parameters
This endpoint does not need any parameter.

### Return type

[**AdminUsersMakeAdmin400Response**](AdminUsersMakeAdmin400Response.md)

### Authorization

[http](../README.md#http)

### HTTP request headers

 - **Content-Type**: Not defined
 - **Accept**: application/json

[[Back to top]](#) [[Back to API list]](../README.md#documentation-for-api-endpoints) [[Back to Model list]](../README.md#documentation-for-models) [[Back to README]](../README.md)

# **verificationVerify**
> AdminUsersMakeAdmin400Response verificationVerify(id, hash)

Verify Email Address

### Example
```dart
import 'package:locker_api/api.dart';
// TODO Configure HTTP Bearer authorization: http
// Case 1. Use String Token
//defaultApiClient.getAuthentication<HttpBearerAuth>('http').setAccessToken('YOUR_ACCESS_TOKEN');
// Case 2. Use Function which generate token.
// String yourTokenGeneratorFunction() { ... }
//defaultApiClient.getAuthentication<HttpBearerAuth>('http').setAccessToken(yourTokenGeneratorFunction);

final api_instance = AuthApi();
final id = id_example; // String | 
final hash = hash_example; // String | 

try {
    final result = api_instance.verificationVerify(id, hash);
    print(result);
} catch (e) {
    print('Exception when calling AuthApi->verificationVerify: $e\n');
}
```

### Parameters

Name | Type | Description  | Notes
------------- | ------------- | ------------- | -------------
 **id** | **String**|  | 
 **hash** | **String**|  | 

### Return type

[**AdminUsersMakeAdmin400Response**](AdminUsersMakeAdmin400Response.md)

### Authorization

[http](../README.md#http)

### HTTP request headers

 - **Content-Type**: Not defined
 - **Accept**: application/json

[[Back to top]](#) [[Back to API list]](../README.md#documentation-for-api-endpoints) [[Back to Model list]](../README.md#documentation-for-models) [[Back to README]](../README.md)

