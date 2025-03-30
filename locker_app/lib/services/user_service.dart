import 'package:locker_api/api.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'dart:convert';

class UserWithToken {
  final User user;
  final String token;
  final String instanceUrl;
  final InstanceInfo instanceInfo;

  UserWithToken({
    required this.user,
    required this.token,
    required this.instanceUrl,
    required this.instanceInfo,
  });
}

class InstanceInfo {
  final String name;
  final String type;
  final String apiVersion;
  final String identifier;
  final String environment;
  final String timestamp;

  InstanceInfo({
    required this.name,
    required this.type,
    required this.apiVersion,
    required this.identifier,
    required this.environment,
    required this.timestamp,
  });

  factory InstanceInfo.fromJson(Map<String, dynamic> json) {
    return InstanceInfo(
      name: json['name'] as String,
      type: json['type'] as String,
      apiVersion: json['api_version'] as String,
      identifier: json['identifier'] as String,
      environment: json['environment'] as String,
      timestamp: json['timestamp'] as String,
    );
  }
}

class UserService {
  User? _user;
  String? _token;
  String? _instanceUrl;
  InstanceInfo? _instanceInfo;
  bool _initialized = false;
  final _secureStorage = const FlutterSecureStorage();

  bool get initialized => _initialized;
  bool get isAuthenticated => _initialized && token.isNotEmpty;
  String get user => _user?.name ?? '';
  String get token => _token ?? '';
  String get instanceUrl => _instanceUrl ?? '';
  InstanceInfo? get instanceInfo => _instanceInfo;

  UserService() {
    _loadPersistedData();
  }

  Future<void> _loadPersistedData() async {
    final prefs = await SharedPreferences.getInstance();
    final savedInstanceUrl = prefs.getString('instance_url') ?? '';
    final savedInstanceInfo = prefs.getString('instance_info');
    
    if (savedInstanceUrl.isNotEmpty) {
      _instanceUrl = savedInstanceUrl;
      if (savedInstanceInfo != null) {
        _instanceInfo = InstanceInfo.fromJson(json.decode(savedInstanceInfo));
      }
    }
    
    final savedToken = await _secureStorage.read(key: 'token');
    if (savedToken != null) {
      await setToken(savedToken);
    }
    _initialized = true;
  }

  Future<void> logout() async {
    _user = null;
    _token = '';
    await _clearToken();
  }

  Future<void> clearInstance() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove('instance_url');
    await prefs.remove('instance_info');
    _instanceUrl = null;
    _instanceInfo = null;
  }

  Future<UserWithToken?> setToken(String token) async {
    _token = token;
    await _persistToken();
    await _loadUser();

    if (_user == null) {
      _token = '';
      await _clearToken();
      return null;
    }

    return UserWithToken(
      user: _user!,
      token: token,
      instanceUrl: _instanceUrl!,
      instanceInfo: _instanceInfo!,
    );
  }

  Future<void> setInstanceUrl(String url) async {
    _instanceUrl = url;
    await _persistInstanceUrl();
  }

  Future<void> setInstanceInfo(InstanceInfo info) async {
    _instanceInfo = info;
    await _persistInstanceInfo();
  }

  Future<void> _loadUser() async {
    try {
      final client = ApiClient(
        basePath: '$_instanceUrl/api',
        authentication: HttpBearerAuth()..accessToken = token,
      );
      _user = await AuthApi(client).authUser();
    } catch (e) {
      await logout();
    }
  }

  Future<void> _persistToken() async {
    await _secureStorage.write(key: 'token', value: token);
  }

  Future<void> _persistInstanceUrl() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString('instance_url', _instanceUrl ?? '');
  }

  Future<void> _persistInstanceInfo() async {
    if (_instanceInfo != null) {
      final prefs = await SharedPreferences.getInstance();
      await prefs.setString('instance_info', json.encode({
        'name': _instanceInfo!.name,
        'type': _instanceInfo!.type,
        'api_version': _instanceInfo!.apiVersion,
        'identifier': _instanceInfo!.identifier,
        'environment': _instanceInfo!.environment,
        'timestamp': _instanceInfo!.timestamp,
      }));
    }
  }

  Future<void> _clearToken() async {
    await _secureStorage.delete(key: 'token');
  }

  Future<UserWithToken> login(String email, String password) async {
    var authLoginRequest = AuthLoginRequest(email: email, password: password);
    final client = ApiClient(
      basePath: '$_instanceUrl/api',
      authentication: HttpBearerAuth()..accessToken = '',
    );
    final tokenResponse = await AuthApi(client).authLogin(authLoginRequest);

    if (tokenResponse == null || tokenResponse.token.isEmpty) {
      throw Exception('No token in response');
    }

    final userWithToken = await setToken(tokenResponse.token);

    if (userWithToken == null) {
      throw Exception('No user in response');
    }

    return userWithToken;
  }

  Future<void> waitForInitialization() async {
    if (!_initialized) {
      await _loadPersistedData();
    }
  }
}
