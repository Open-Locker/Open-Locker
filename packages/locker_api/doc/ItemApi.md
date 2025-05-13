# locker_api.api.ItemApi

## Load the API package
```dart
import 'package:locker_api/api.dart';
```

All URIs are relative to *http://open-locker.test/api*

Method | HTTP request | Description
------------- | ------------- | -------------
[**itemsBorrow**](ItemApi.md#itemsborrow) | **POST** /items/{item}/borrow | Borrow a Item
[**itemsBorrowed**](ItemApi.md#itemsborrowed) | **GET** /items/borrowed | Get all Items from User
[**itemsIndex**](ItemApi.md#itemsindex) | **GET** /items | Get all Items
[**itemsLoanHistory**](ItemApi.md#itemsloanhistory) | **GET** /items/loan-history | Get loan history for the current user
[**itemsReturn**](ItemApi.md#itemsreturn) | **POST** /items/{item}/return | Returns a Item


# **itemsBorrow**
> ItemsBorrow200Response itemsBorrow(item)

Borrow a Item

### Example
```dart
import 'package:locker_api/api.dart';
// TODO Configure HTTP Bearer authorization: http
// Case 1. Use String Token
//defaultApiClient.getAuthentication<HttpBearerAuth>('http').setAccessToken('YOUR_ACCESS_TOKEN');
// Case 2. Use Function which generate token.
// String yourTokenGeneratorFunction() { ... }
//defaultApiClient.getAuthentication<HttpBearerAuth>('http').setAccessToken(yourTokenGeneratorFunction);

final api_instance = ItemApi();
final item = 56; // int | The item ID

try {
    final result = api_instance.itemsBorrow(item);
    print(result);
} catch (e) {
    print('Exception when calling ItemApi->itemsBorrow: $e\n');
}
```

### Parameters

Name | Type | Description  | Notes
------------- | ------------- | ------------- | -------------
 **item** | **int**| The item ID | 

### Return type

[**ItemsBorrow200Response**](ItemsBorrow200Response.md)

### Authorization

[http](../README.md#http)

### HTTP request headers

 - **Content-Type**: Not defined
 - **Accept**: application/json

[[Back to top]](#) [[Back to API list]](../README.md#documentation-for-api-endpoints) [[Back to Model list]](../README.md#documentation-for-models) [[Back to README]](../README.md)

# **itemsBorrowed**
> List<Item> itemsBorrowed()

Get all Items from User

### Example
```dart
import 'package:locker_api/api.dart';
// TODO Configure HTTP Bearer authorization: http
// Case 1. Use String Token
//defaultApiClient.getAuthentication<HttpBearerAuth>('http').setAccessToken('YOUR_ACCESS_TOKEN');
// Case 2. Use Function which generate token.
// String yourTokenGeneratorFunction() { ... }
//defaultApiClient.getAuthentication<HttpBearerAuth>('http').setAccessToken(yourTokenGeneratorFunction);

final api_instance = ItemApi();

try {
    final result = api_instance.itemsBorrowed();
    print(result);
} catch (e) {
    print('Exception when calling ItemApi->itemsBorrowed: $e\n');
}
```

### Parameters
This endpoint does not need any parameter.

### Return type

[**List<Item>**](Item.md)

### Authorization

[http](../README.md#http)

### HTTP request headers

 - **Content-Type**: Not defined
 - **Accept**: application/json

[[Back to top]](#) [[Back to API list]](../README.md#documentation-for-api-endpoints) [[Back to Model list]](../README.md#documentation-for-models) [[Back to README]](../README.md)

# **itemsIndex**
> List<Item> itemsIndex()

Get all Items

### Example
```dart
import 'package:locker_api/api.dart';
// TODO Configure HTTP Bearer authorization: http
// Case 1. Use String Token
//defaultApiClient.getAuthentication<HttpBearerAuth>('http').setAccessToken('YOUR_ACCESS_TOKEN');
// Case 2. Use Function which generate token.
// String yourTokenGeneratorFunction() { ... }
//defaultApiClient.getAuthentication<HttpBearerAuth>('http').setAccessToken(yourTokenGeneratorFunction);

final api_instance = ItemApi();

try {
    final result = api_instance.itemsIndex();
    print(result);
} catch (e) {
    print('Exception when calling ItemApi->itemsIndex: $e\n');
}
```

### Parameters
This endpoint does not need any parameter.

### Return type

[**List<Item>**](Item.md)

### Authorization

[http](../README.md#http)

### HTTP request headers

 - **Content-Type**: Not defined
 - **Accept**: application/json

[[Back to top]](#) [[Back to API list]](../README.md#documentation-for-api-endpoints) [[Back to Model list]](../README.md#documentation-for-models) [[Back to README]](../README.md)

# **itemsLoanHistory**
> List<ItemLoan> itemsLoanHistory()

Get loan history for the current user

### Example
```dart
import 'package:locker_api/api.dart';
// TODO Configure HTTP Bearer authorization: http
// Case 1. Use String Token
//defaultApiClient.getAuthentication<HttpBearerAuth>('http').setAccessToken('YOUR_ACCESS_TOKEN');
// Case 2. Use Function which generate token.
// String yourTokenGeneratorFunction() { ... }
//defaultApiClient.getAuthentication<HttpBearerAuth>('http').setAccessToken(yourTokenGeneratorFunction);

final api_instance = ItemApi();

try {
    final result = api_instance.itemsLoanHistory();
    print(result);
} catch (e) {
    print('Exception when calling ItemApi->itemsLoanHistory: $e\n');
}
```

### Parameters
This endpoint does not need any parameter.

### Return type

[**List<ItemLoan>**](ItemLoan.md)

### Authorization

[http](../README.md#http)

### HTTP request headers

 - **Content-Type**: Not defined
 - **Accept**: application/json

[[Back to top]](#) [[Back to API list]](../README.md#documentation-for-api-endpoints) [[Back to Model list]](../README.md#documentation-for-models) [[Back to README]](../README.md)

# **itemsReturn**
> ItemsBorrow200Response itemsReturn(item)

Returns a Item

### Example
```dart
import 'package:locker_api/api.dart';
// TODO Configure HTTP Bearer authorization: http
// Case 1. Use String Token
//defaultApiClient.getAuthentication<HttpBearerAuth>('http').setAccessToken('YOUR_ACCESS_TOKEN');
// Case 2. Use Function which generate token.
// String yourTokenGeneratorFunction() { ... }
//defaultApiClient.getAuthentication<HttpBearerAuth>('http').setAccessToken(yourTokenGeneratorFunction);

final api_instance = ItemApi();
final item = 56; // int | The item ID

try {
    final result = api_instance.itemsReturn(item);
    print(result);
} catch (e) {
    print('Exception when calling ItemApi->itemsReturn: $e\n');
}
```

### Parameters

Name | Type | Description  | Notes
------------- | ------------- | ------------- | -------------
 **item** | **int**| The item ID | 

### Return type

[**ItemsBorrow200Response**](ItemsBorrow200Response.md)

### Authorization

[http](../README.md#http)

### HTTP request headers

 - **Content-Type**: Not defined
 - **Accept**: application/json

[[Back to top]](#) [[Back to API list]](../README.md#documentation-for-api-endpoints) [[Back to Model list]](../README.md#documentation-for-models) [[Back to README]](../README.md)

