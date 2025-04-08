# locker_api.api.AppInfoApi

## Load the API package
```dart
import 'package:locker_api/api.dart';
```

All URIs are relative to *http://localhost/api*

Method | HTTP request | Description
------------- | ------------- | -------------
[**identify**](AppInfoApi.md#identify) | **GET** /identify | Returns information for identifying the application


# **identify**
> Identify200Response identify()

Returns information for identifying the application

### Example
```dart
import 'package:locker_api/api.dart';
// TODO Configure HTTP Bearer authorization: http
// Case 1. Use String Token
//defaultApiClient.getAuthentication<HttpBearerAuth>('http').setAccessToken('YOUR_ACCESS_TOKEN');
// Case 2. Use Function which generate token.
// String yourTokenGeneratorFunction() { ... }
//defaultApiClient.getAuthentication<HttpBearerAuth>('http').setAccessToken(yourTokenGeneratorFunction);

final api_instance = AppInfoApi();

try {
    final result = api_instance.identify();
    print(result);
} catch (e) {
    print('Exception when calling AppInfoApi->identify: $e\n');
}
```

### Parameters
This endpoint does not need any parameter.

### Return type

[**Identify200Response**](Identify200Response.md)

### Authorization

[http](../README.md#http)

### HTTP request headers

 - **Content-Type**: Not defined
 - **Accept**: application/json

[[Back to top]](#) [[Back to API list]](../README.md#documentation-for-api-endpoints) [[Back to Model list]](../README.md#documentation-for-models) [[Back to README]](../README.md)

