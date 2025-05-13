//
// AUTO-GENERATED FILE, DO NOT MODIFY!
//
// @dart=2.18

// ignore_for_file: unused_element, unused_import
// ignore_for_file: always_put_required_named_parameters_first
// ignore_for_file: constant_identifier_names
// ignore_for_file: lines_longer_than_80_chars

part of openapi.api;

class ItemsBorrow200Response {
  /// Returns a new [ItemsBorrow200Response] instance.
  ItemsBorrow200Response({
    required this.status,
    required this.message,
  });

  bool status;

  String message;

  @override
  bool operator ==(Object other) => identical(this, other) || other is ItemsBorrow200Response &&
    other.status == status &&
    other.message == message;

  @override
  int get hashCode =>
    // ignore: unnecessary_parenthesis
    (status.hashCode) +
    (message.hashCode);

  @override
  String toString() => 'ItemsBorrow200Response[status=$status, message=$message]';

  Map<String, dynamic> toJson() {
    final json = <String, dynamic>{};
      json[r'status'] = this.status;
      json[r'message'] = this.message;
    return json;
  }

  /// Returns a new [ItemsBorrow200Response] instance and imports its values from
  /// [value] if it's a [Map], null otherwise.
  // ignore: prefer_constructors_over_static_methods
  static ItemsBorrow200Response? fromJson(dynamic value) {
    if (value is Map) {
      final json = value.cast<String, dynamic>();

      // Ensure that the map contains the required keys.
      // Note 1: the values aren't checked for validity beyond being non-null.
      // Note 2: this code is stripped in release mode!
      assert(() {
        requiredKeys.forEach((key) {
          assert(json.containsKey(key), 'Required key "ItemsBorrow200Response[$key]" is missing from JSON.');
          assert(json[key] != null, 'Required key "ItemsBorrow200Response[$key]" has a null value in JSON.');
        });
        return true;
      }());

      return ItemsBorrow200Response(
        status: mapValueOfType<bool>(json, r'status')!,
        message: mapValueOfType<String>(json, r'message')!,
      );
    }
    return null;
  }

  static List<ItemsBorrow200Response> listFromJson(dynamic json, {bool growable = false,}) {
    final result = <ItemsBorrow200Response>[];
    if (json is List && json.isNotEmpty) {
      for (final row in json) {
        final value = ItemsBorrow200Response.fromJson(row);
        if (value != null) {
          result.add(value);
        }
      }
    }
    return result.toList(growable: growable);
  }

  static Map<String, ItemsBorrow200Response> mapFromJson(dynamic json) {
    final map = <String, ItemsBorrow200Response>{};
    if (json is Map && json.isNotEmpty) {
      json = json.cast<String, dynamic>(); // ignore: parameter_assignments
      for (final entry in json.entries) {
        final value = ItemsBorrow200Response.fromJson(entry.value);
        if (value != null) {
          map[entry.key] = value;
        }
      }
    }
    return map;
  }

  // maps a json object with a list of ItemsBorrow200Response-objects as value to a dart map
  static Map<String, List<ItemsBorrow200Response>> mapListFromJson(dynamic json, {bool growable = false,}) {
    final map = <String, List<ItemsBorrow200Response>>{};
    if (json is Map && json.isNotEmpty) {
      // ignore: parameter_assignments
      json = json.cast<String, dynamic>();
      for (final entry in json.entries) {
        map[entry.key] = ItemsBorrow200Response.listFromJson(entry.value, growable: growable,);
      }
    }
    return map;
  }

  /// The list of required keys that must be present in a JSON.
  static const requiredKeys = <String>{
    'status',
    'message',
  };
}

