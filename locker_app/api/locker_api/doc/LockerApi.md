# locker_api.api.LockerApi

## Load the API package
```dart
import 'package:locker_api/api.dart';
```

All URIs are relative to *http://localhost/api*

Method | HTTP request | Description
------------- | ------------- | -------------
[**adminLockersIndex**](LockerApi.md#adminlockersindex) | **GET** /admin/lockers | Returns a list of all available lockers
[**adminLockersOpen**](LockerApi.md#adminlockersopen) | **POST** /admin/lockers/{locker}/open | Manually opens a locker


# **adminLockersIndex**
> List<Locker> adminLockersIndex()

Returns a list of all available lockers

### Example
```dart
import 'package:locker_api/api.dart';
// TODO Configure HTTP Bearer authorization: http
// Case 1. Use String Token
//defaultApiClient.getAuthentication<HttpBearerAuth>('http').setAccessToken('YOUR_ACCESS_TOKEN');
// Case 2. Use Function which generate token.
// String yourTokenGeneratorFunction() { ... }
//defaultApiClient.getAuthentication<HttpBearerAuth>('http').setAccessToken(yourTokenGeneratorFunction);

final api_instance = LockerApi();

try {
    final result = api_instance.adminLockersIndex();
    print(result);
} catch (e) {
    print('Exception when calling LockerApi->adminLockersIndex: $e\n');
}
```

### Parameters
This endpoint does not need any parameter.

### Return type

[**List<Locker>**](Locker.md)

### Authorization

[http](../README.md#http)

### HTTP request headers

 - **Content-Type**: Not defined
 - **Accept**: application/json

[[Back to top]](#) [[Back to API list]](../README.md#documentation-for-api-endpoints) [[Back to Model list]](../README.md#documentation-for-models) [[Back to README]](../README.md)

# **adminLockersOpen**
> ItemsBorrow200Response adminLockersOpen(locker)

Manually opens a locker

### Example
```dart
import 'package:locker_api/api.dart';
// TODO Configure HTTP Bearer authorization: http
// Case 1. Use String Token
//defaultApiClient.getAuthentication<HttpBearerAuth>('http').setAccessToken('YOUR_ACCESS_TOKEN');
// Case 2. Use Function which generate token.
// String yourTokenGeneratorFunction() { ... }
//defaultApiClient.getAuthentication<HttpBearerAuth>('http').setAccessToken(yourTokenGeneratorFunction);

final api_instance = LockerApi();
final locker = 56; // int | The locker ID

try {
    final result = api_instance.adminLockersOpen(locker);
    print(result);
} catch (e) {
    print('Exception when calling LockerApi->adminLockersOpen: $e\n');
}
```

### Parameters

Name | Type | Description  | Notes
------------- | ------------- | ------------- | -------------
 **locker** | **int**| The locker ID | 

### Return type

[**ItemsBorrow200Response**](ItemsBorrow200Response.md)

### Authorization

[http](../README.md#http)

### HTTP request headers

 - **Content-Type**: Not defined
 - **Accept**: application/json

[[Back to top]](#) [[Back to API list]](../README.md#documentation-for-api-endpoints) [[Back to Model list]](../README.md#documentation-for-models) [[Back to README]](../README.md)

