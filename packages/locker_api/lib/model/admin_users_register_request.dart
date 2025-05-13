//
// AUTO-GENERATED FILE, DO NOT MODIFY!
//
// @dart=2.18

// ignore_for_file: unused_element, unused_import
// ignore_for_file: always_put_required_named_parameters_first
// ignore_for_file: constant_identifier_names
// ignore_for_file: lines_longer_than_80_chars

part of openapi.api;

class AdminUsersRegisterRequest {
  /// Returns a new [AdminUsersRegisterRequest] instance.
  AdminUsersRegisterRequest({
    required this.name,
    required this.email,
    required this.password,
    required this.passwordConfirmation,
  });

  String name;

  String email;

  String password;

  String passwordConfirmation;

  @override
  bool operator ==(Object other) => identical(this, other) || other is AdminUsersRegisterRequest &&
    other.name == name &&
    other.email == email &&
    other.password == password &&
    other.passwordConfirmation == passwordConfirmation;

  @override
  int get hashCode =>
    // ignore: unnecessary_parenthesis
    (name.hashCode) +
    (email.hashCode) +
    (password.hashCode) +
    (passwordConfirmation.hashCode);

  @override
  String toString() => 'AdminUsersRegisterRequest[name=$name, email=$email, password=$password, passwordConfirmation=$passwordConfirmation]';

  Map<String, dynamic> toJson() {
    final json = <String, dynamic>{};
      json[r'name'] = this.name;
      json[r'email'] = this.email;
      json[r'password'] = this.password;
      json[r'password_confirmation'] = this.passwordConfirmation;
    return json;
  }

  /// Returns a new [AdminUsersRegisterRequest] instance and imports its values from
  /// [value] if it's a [Map], null otherwise.
  // ignore: prefer_constructors_over_static_methods
  static AdminUsersRegisterRequest? fromJson(dynamic value) {
    if (value is Map) {
      final json = value.cast<String, dynamic>();

      // Ensure that the map contains the required keys.
      // Note 1: the values aren't checked for validity beyond being non-null.
      // Note 2: this code is stripped in release mode!
      assert(() {
        requiredKeys.forEach((key) {
          assert(json.containsKey(key), 'Required key "AdminUsersRegisterRequest[$key]" is missing from JSON.');
          assert(json[key] != null, 'Required key "AdminUsersRegisterRequest[$key]" has a null value in JSON.');
        });
        return true;
      }());

      return AdminUsersRegisterRequest(
        name: mapValueOfType<String>(json, r'name')!,
        email: mapValueOfType<String>(json, r'email')!,
        password: mapValueOfType<String>(json, r'password')!,
        passwordConfirmation: mapValueOfType<String>(json, r'password_confirmation')!,
      );
    }
    return null;
  }

  static List<AdminUsersRegisterRequest> listFromJson(dynamic json, {bool growable = false,}) {
    final result = <AdminUsersRegisterRequest>[];
    if (json is List && json.isNotEmpty) {
      for (final row in json) {
        final value = AdminUsersRegisterRequest.fromJson(row);
        if (value != null) {
          result.add(value);
        }
      }
    }
    return result.toList(growable: growable);
  }

  static Map<String, AdminUsersRegisterRequest> mapFromJson(dynamic json) {
    final map = <String, AdminUsersRegisterRequest>{};
    if (json is Map && json.isNotEmpty) {
      json = json.cast<String, dynamic>(); // ignore: parameter_assignments
      for (final entry in json.entries) {
        final value = AdminUsersRegisterRequest.fromJson(entry.value);
        if (value != null) {
          map[entry.key] = value;
        }
      }
    }
    return map;
  }

  // maps a json object with a list of AdminUsersRegisterRequest-objects as value to a dart map
  static Map<String, List<AdminUsersRegisterRequest>> mapListFromJson(dynamic json, {bool growable = false,}) {
    final map = <String, List<AdminUsersRegisterRequest>>{};
    if (json is Map && json.isNotEmpty) {
      // ignore: parameter_assignments
      json = json.cast<String, dynamic>();
      for (final entry in json.entries) {
        map[entry.key] = AdminUsersRegisterRequest.listFromJson(entry.value, growable: growable,);
      }
    }
    return map;
  }

  /// The list of required keys that must be present in a JSON.
  static const requiredKeys = <String>{
    'name',
    'email',
    'password',
    'password_confirmation',
  };
}

