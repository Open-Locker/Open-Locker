//
// AUTO-GENERATED FILE, DO NOT MODIFY!
//
// @dart=2.18

// ignore_for_file: unused_element, unused_import
// ignore_for_file: always_put_required_named_parameters_first
// ignore_for_file: constant_identifier_names
// ignore_for_file: lines_longer_than_80_chars

part of openapi.api;

class Locker {
  /// Returns a new [Locker] instance.
  Locker({
    required this.id,
    required this.name,
    required this.isOpen,
  });

  int id;

  String name;

  bool isOpen;

  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      other is Locker &&
          other.id == id &&
          other.name == name &&
          other.isOpen == isOpen;

  @override
  int get hashCode =>
      // ignore: unnecessary_parenthesis
      (id.hashCode) + (name.hashCode) + (isOpen.hashCode);

  @override
  String toString() => 'Locker[id=$id, name=$name, isOpen=$isOpen]';

  Map<String, dynamic> toJson() {
    final json = <String, dynamic>{};
    json[r'id'] = this.id;
    json[r'name'] = this.name;
    json[r'is_open'] = this.isOpen;
    return json;
  }

  /// Returns a new [Locker] instance and imports its values from
  /// [value] if it's a [Map], null otherwise.
  // ignore: prefer_constructors_over_static_methods
  static Locker? fromJson(dynamic value) {
    if (value is Map) {
      final json = value.cast<String, dynamic>();

      // Ensure that the map contains the required keys.
      // Note 1: the values aren't checked for validity beyond being non-null.
      // Note 2: this code is stripped in release mode!
      assert(() {
        requiredKeys.forEach((key) {
          assert(json.containsKey(key),
              'Required key "Locker[$key]" is missing from JSON.');
          assert(json[key] != null,
              'Required key "Locker[$key]" has a null value in JSON.');
        });
        return true;
      }());

      return Locker(
        id: mapValueOfType<int>(json, r'id')!,
        name: mapValueOfType<String>(json, r'name')!,
        isOpen: mapValueOfType<bool>(json, r'is_open')!,
      );
    }
    return null;
  }

  static List<Locker> listFromJson(
    dynamic json, {
    bool growable = false,
  }) {
    final result = <Locker>[];
    if (json is List && json.isNotEmpty) {
      for (final row in json) {
        final value = Locker.fromJson(row);
        if (value != null) {
          result.add(value);
        }
      }
    }
    return result.toList(growable: growable);
  }

  static Map<String, Locker> mapFromJson(dynamic json) {
    final map = <String, Locker>{};
    if (json is Map && json.isNotEmpty) {
      json = json.cast<String, dynamic>(); // ignore: parameter_assignments
      for (final entry in json.entries) {
        final value = Locker.fromJson(entry.value);
        if (value != null) {
          map[entry.key] = value;
        }
      }
    }
    return map;
  }

  // maps a json object with a list of Locker-objects as value to a dart map
  static Map<String, List<Locker>> mapListFromJson(
    dynamic json, {
    bool growable = false,
  }) {
    final map = <String, List<Locker>>{};
    if (json is Map && json.isNotEmpty) {
      // ignore: parameter_assignments
      json = json.cast<String, dynamic>();
      for (final entry in json.entries) {
        map[entry.key] = Locker.listFromJson(
          entry.value,
          growable: growable,
        );
      }
    }
    return map;
  }

  /// The list of required keys that must be present in a JSON.
  static const requiredKeys = <String>{
    'id',
    'name',
    'is_open',
  };
}
