//
// AUTO-GENERATED FILE, DO NOT MODIFY!
//
// @dart=2.18

// ignore_for_file: unused_element, unused_import
// ignore_for_file: always_put_required_named_parameters_first
// ignore_for_file: constant_identifier_names
// ignore_for_file: lines_longer_than_80_chars

part of openapi.api;

class Identify200Response {
  /// Returns a new [Identify200Response] instance.
  Identify200Response({
    required this.name,
    required this.type,
    required this.apiVersion,
    required this.identifier,
    required this.environment,
    required this.timestamp,
  });

  String name;

  String type;

  String apiVersion;

  String identifier;

  String environment;

  String timestamp;

  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      other is Identify200Response &&
          other.name == name &&
          other.type == type &&
          other.apiVersion == apiVersion &&
          other.identifier == identifier &&
          other.environment == environment &&
          other.timestamp == timestamp;

  @override
  int get hashCode =>
      // ignore: unnecessary_parenthesis
      (name.hashCode) +
      (type.hashCode) +
      (apiVersion.hashCode) +
      (identifier.hashCode) +
      (environment.hashCode) +
      (timestamp.hashCode);

  @override
  String toString() =>
      'Identify200Response[name=$name, type=$type, apiVersion=$apiVersion, identifier=$identifier, environment=$environment, timestamp=$timestamp]';

  Map<String, dynamic> toJson() {
    final json = <String, dynamic>{};
    json[r'name'] = this.name;
    json[r'type'] = this.type;
    json[r'api_version'] = this.apiVersion;
    json[r'identifier'] = this.identifier;
    json[r'environment'] = this.environment;
    json[r'timestamp'] = this.timestamp;
    return json;
  }

  /// Returns a new [Identify200Response] instance and imports its values from
  /// [value] if it's a [Map], null otherwise.
  // ignore: prefer_constructors_over_static_methods
  static Identify200Response? fromJson(dynamic value) {
    if (value is Map) {
      final json = value.cast<String, dynamic>();

      // Ensure that the map contains the required keys.
      // Note 1: the values aren't checked for validity beyond being non-null.
      // Note 2: this code is stripped in release mode!
      assert(() {
        requiredKeys.forEach((key) {
          assert(json.containsKey(key),
              'Required key "Identify200Response[$key]" is missing from JSON.');
          assert(json[key] != null,
              'Required key "Identify200Response[$key]" has a null value in JSON.');
        });
        return true;
      }());

      return Identify200Response(
        name: mapValueOfType<String>(json, r'name')!,
        type: mapValueOfType<String>(json, r'type')!,
        apiVersion: mapValueOfType<String>(json, r'api_version')!,
        identifier: mapValueOfType<String>(json, r'identifier')!,
        environment: mapValueOfType<String>(json, r'environment')!,
        timestamp: mapValueOfType<String>(json, r'timestamp')!,
      );
    }
    return null;
  }

  static List<Identify200Response> listFromJson(
    dynamic json, {
    bool growable = false,
  }) {
    final result = <Identify200Response>[];
    if (json is List && json.isNotEmpty) {
      for (final row in json) {
        final value = Identify200Response.fromJson(row);
        if (value != null) {
          result.add(value);
        }
      }
    }
    return result.toList(growable: growable);
  }

  static Map<String, Identify200Response> mapFromJson(dynamic json) {
    final map = <String, Identify200Response>{};
    if (json is Map && json.isNotEmpty) {
      json = json.cast<String, dynamic>(); // ignore: parameter_assignments
      for (final entry in json.entries) {
        final value = Identify200Response.fromJson(entry.value);
        if (value != null) {
          map[entry.key] = value;
        }
      }
    }
    return map;
  }

  // maps a json object with a list of Identify200Response-objects as value to a dart map
  static Map<String, List<Identify200Response>> mapListFromJson(
    dynamic json, {
    bool growable = false,
  }) {
    final map = <String, List<Identify200Response>>{};
    if (json is Map && json.isNotEmpty) {
      // ignore: parameter_assignments
      json = json.cast<String, dynamic>();
      for (final entry in json.entries) {
        map[entry.key] = Identify200Response.listFromJson(
          entry.value,
          growable: growable,
        );
      }
    }
    return map;
  }

  /// The list of required keys that must be present in a JSON.
  static const requiredKeys = <String>{
    'name',
    'type',
    'api_version',
    'identifier',
    'environment',
    'timestamp',
  };
}
