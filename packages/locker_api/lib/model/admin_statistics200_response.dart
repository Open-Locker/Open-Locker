//
// AUTO-GENERATED FILE, DO NOT MODIFY!
//
// @dart=2.18

// ignore_for_file: unused_element, unused_import
// ignore_for_file: always_put_required_named_parameters_first
// ignore_for_file: constant_identifier_names
// ignore_for_file: lines_longer_than_80_chars

part of openapi.api;

class AdminStatistics200Response {
  /// Returns a new [AdminStatistics200Response] instance.
  AdminStatistics200Response({
    required this.statistics,
  });

  AdminStatistics200ResponseStatistics statistics;

  @override
  bool operator ==(Object other) => identical(this, other) || other is AdminStatistics200Response &&
    other.statistics == statistics;

  @override
  int get hashCode =>
    // ignore: unnecessary_parenthesis
    (statistics.hashCode);

  @override
  String toString() => 'AdminStatistics200Response[statistics=$statistics]';

  Map<String, dynamic> toJson() {
    final json = <String, dynamic>{};
      json[r'statistics'] = this.statistics;
    return json;
  }

  /// Returns a new [AdminStatistics200Response] instance and imports its values from
  /// [value] if it's a [Map], null otherwise.
  // ignore: prefer_constructors_over_static_methods
  static AdminStatistics200Response? fromJson(dynamic value) {
    if (value is Map) {
      final json = value.cast<String, dynamic>();

      // Ensure that the map contains the required keys.
      // Note 1: the values aren't checked for validity beyond being non-null.
      // Note 2: this code is stripped in release mode!
      assert(() {
        requiredKeys.forEach((key) {
          assert(json.containsKey(key), 'Required key "AdminStatistics200Response[$key]" is missing from JSON.');
          assert(json[key] != null, 'Required key "AdminStatistics200Response[$key]" has a null value in JSON.');
        });
        return true;
      }());

      return AdminStatistics200Response(
        statistics: AdminStatistics200ResponseStatistics.fromJson(json[r'statistics'])!,
      );
    }
    return null;
  }

  static List<AdminStatistics200Response> listFromJson(dynamic json, {bool growable = false,}) {
    final result = <AdminStatistics200Response>[];
    if (json is List && json.isNotEmpty) {
      for (final row in json) {
        final value = AdminStatistics200Response.fromJson(row);
        if (value != null) {
          result.add(value);
        }
      }
    }
    return result.toList(growable: growable);
  }

  static Map<String, AdminStatistics200Response> mapFromJson(dynamic json) {
    final map = <String, AdminStatistics200Response>{};
    if (json is Map && json.isNotEmpty) {
      json = json.cast<String, dynamic>(); // ignore: parameter_assignments
      for (final entry in json.entries) {
        final value = AdminStatistics200Response.fromJson(entry.value);
        if (value != null) {
          map[entry.key] = value;
        }
      }
    }
    return map;
  }

  // maps a json object with a list of AdminStatistics200Response-objects as value to a dart map
  static Map<String, List<AdminStatistics200Response>> mapListFromJson(dynamic json, {bool growable = false,}) {
    final map = <String, List<AdminStatistics200Response>>{};
    if (json is Map && json.isNotEmpty) {
      // ignore: parameter_assignments
      json = json.cast<String, dynamic>();
      for (final entry in json.entries) {
        map[entry.key] = AdminStatistics200Response.listFromJson(entry.value, growable: growable,);
      }
    }
    return map;
  }

  /// The list of required keys that must be present in a JSON.
  static const requiredKeys = <String>{
    'statistics',
  };
}

