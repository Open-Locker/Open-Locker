//
// AUTO-GENERATED FILE, DO NOT MODIFY!
//
// @dart=2.18

// ignore_for_file: unused_element, unused_import
// ignore_for_file: always_put_required_named_parameters_first
// ignore_for_file: constant_identifier_names
// ignore_for_file: lines_longer_than_80_chars

part of openapi.api;

class AdminStatistics200ResponseStatistics {
  /// Returns a new [AdminStatistics200ResponseStatistics] instance.
  AdminStatistics200ResponseStatistics({
    required this.totalUsers,
    required this.totalItems,
    required this.totalLoans,
    required this.activeLoans,
  });

  /// Total number of users
  int totalUsers;

  /// Total number of items
  int totalItems;

  /// Total number of loans
  int totalLoans;

  /// Number of active loans
  int activeLoans;

  @override
  bool operator ==(Object other) => identical(this, other) || other is AdminStatistics200ResponseStatistics &&
    other.totalUsers == totalUsers &&
    other.totalItems == totalItems &&
    other.totalLoans == totalLoans &&
    other.activeLoans == activeLoans;

  @override
  int get hashCode =>
    // ignore: unnecessary_parenthesis
    (totalUsers.hashCode) +
    (totalItems.hashCode) +
    (totalLoans.hashCode) +
    (activeLoans.hashCode);

  @override
  String toString() => 'AdminStatistics200ResponseStatistics[totalUsers=$totalUsers, totalItems=$totalItems, totalLoans=$totalLoans, activeLoans=$activeLoans]';

  Map<String, dynamic> toJson() {
    final json = <String, dynamic>{};
      json[r'total_users'] = this.totalUsers;
      json[r'total_items'] = this.totalItems;
      json[r'total_loans'] = this.totalLoans;
      json[r'active_loans'] = this.activeLoans;
    return json;
  }

  /// Returns a new [AdminStatistics200ResponseStatistics] instance and imports its values from
  /// [value] if it's a [Map], null otherwise.
  // ignore: prefer_constructors_over_static_methods
  static AdminStatistics200ResponseStatistics? fromJson(dynamic value) {
    if (value is Map) {
      final json = value.cast<String, dynamic>();

      // Ensure that the map contains the required keys.
      // Note 1: the values aren't checked for validity beyond being non-null.
      // Note 2: this code is stripped in release mode!
      assert(() {
        requiredKeys.forEach((key) {
          assert(json.containsKey(key), 'Required key "AdminStatistics200ResponseStatistics[$key]" is missing from JSON.');
          assert(json[key] != null, 'Required key "AdminStatistics200ResponseStatistics[$key]" has a null value in JSON.');
        });
        return true;
      }());

      return AdminStatistics200ResponseStatistics(
        totalUsers: mapValueOfType<int>(json, r'total_users')!,
        totalItems: mapValueOfType<int>(json, r'total_items')!,
        totalLoans: mapValueOfType<int>(json, r'total_loans')!,
        activeLoans: mapValueOfType<int>(json, r'active_loans')!,
      );
    }
    return null;
  }

  static List<AdminStatistics200ResponseStatistics> listFromJson(dynamic json, {bool growable = false,}) {
    final result = <AdminStatistics200ResponseStatistics>[];
    if (json is List && json.isNotEmpty) {
      for (final row in json) {
        final value = AdminStatistics200ResponseStatistics.fromJson(row);
        if (value != null) {
          result.add(value);
        }
      }
    }
    return result.toList(growable: growable);
  }

  static Map<String, AdminStatistics200ResponseStatistics> mapFromJson(dynamic json) {
    final map = <String, AdminStatistics200ResponseStatistics>{};
    if (json is Map && json.isNotEmpty) {
      json = json.cast<String, dynamic>(); // ignore: parameter_assignments
      for (final entry in json.entries) {
        final value = AdminStatistics200ResponseStatistics.fromJson(entry.value);
        if (value != null) {
          map[entry.key] = value;
        }
      }
    }
    return map;
  }

  // maps a json object with a list of AdminStatistics200ResponseStatistics-objects as value to a dart map
  static Map<String, List<AdminStatistics200ResponseStatistics>> mapListFromJson(dynamic json, {bool growable = false,}) {
    final map = <String, List<AdminStatistics200ResponseStatistics>>{};
    if (json is Map && json.isNotEmpty) {
      // ignore: parameter_assignments
      json = json.cast<String, dynamic>();
      for (final entry in json.entries) {
        map[entry.key] = AdminStatistics200ResponseStatistics.listFromJson(entry.value, growable: growable,);
      }
    }
    return map;
  }

  /// The list of required keys that must be present in a JSON.
  static const requiredKeys = <String>{
    'total_users',
    'total_items',
    'total_loans',
    'active_loans',
  };
}

