//
// AUTO-GENERATED FILE, DO NOT MODIFY!
//
// @dart=2.18

// ignore_for_file: unused_element, unused_import
// ignore_for_file: always_put_required_named_parameters_first
// ignore_for_file: constant_identifier_names
// ignore_for_file: lines_longer_than_80_chars

part of openapi.api;

class ItemLoan {
  /// Returns a new [ItemLoan] instance.
  ItemLoan({
    required this.id,
    required this.item,
    required this.userId,
    required this.borrowedAt,
    required this.returnedAt,
  });

  int id;

  Item item;

  int userId;

  DateTime borrowedAt;

  DateTime? returnedAt;

  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      other is ItemLoan &&
          other.id == id &&
          other.item == item &&
          other.userId == userId &&
          other.borrowedAt == borrowedAt &&
          other.returnedAt == returnedAt;

  @override
  int get hashCode =>
      // ignore: unnecessary_parenthesis
      (id.hashCode) +
      (item.hashCode) +
      (userId.hashCode) +
      (borrowedAt.hashCode) +
      (returnedAt == null ? 0 : returnedAt!.hashCode);

  @override
  String toString() =>
      'ItemLoan[id=$id, item=$item, userId=$userId, borrowedAt=$borrowedAt, returnedAt=$returnedAt]';

  Map<String, dynamic> toJson() {
    final json = <String, dynamic>{};
    json[r'id'] = this.id;
    json[r'item'] = this.item;
    json[r'user_id'] = this.userId;
    json[r'borrowed_at'] = this.borrowedAt.toUtc().toIso8601String();
    if (this.returnedAt != null) {
      json[r'returned_at'] = this.returnedAt!.toUtc().toIso8601String();
    } else {
      json[r'returned_at'] = null;
    }
    return json;
  }

  /// Returns a new [ItemLoan] instance and imports its values from
  /// [value] if it's a [Map], null otherwise.
  // ignore: prefer_constructors_over_static_methods
  static ItemLoan? fromJson(dynamic value) {
    if (value is Map) {
      final json = value.cast<String, dynamic>();

      // Ensure that the map contains the required keys.
      // Note 1: the values aren't checked for validity beyond being non-null.
      // Note 2: this code is stripped in release mode!
      assert(() {
        requiredKeys.forEach((key) {
          assert(json.containsKey(key),
              'Required key "ItemLoan[$key]" is missing from JSON.');
          assert(json[key] != null,
              'Required key "ItemLoan[$key]" has a null value in JSON.');
        });
        return true;
      }());

      return ItemLoan(
        id: mapValueOfType<int>(json, r'id')!,
        item: Item.fromJson(json[r'item'])!,
        userId: mapValueOfType<int>(json, r'user_id')!,
        borrowedAt: mapDateTime(json, r'borrowed_at', r'')!,
        returnedAt: mapDateTime(json, r'returned_at', r''),
      );
    }
    return null;
  }

  static List<ItemLoan> listFromJson(
    dynamic json, {
    bool growable = false,
  }) {
    final result = <ItemLoan>[];
    if (json is List && json.isNotEmpty) {
      for (final row in json) {
        final value = ItemLoan.fromJson(row);
        if (value != null) {
          result.add(value);
        }
      }
    }
    return result.toList(growable: growable);
  }

  static Map<String, ItemLoan> mapFromJson(dynamic json) {
    final map = <String, ItemLoan>{};
    if (json is Map && json.isNotEmpty) {
      json = json.cast<String, dynamic>(); // ignore: parameter_assignments
      for (final entry in json.entries) {
        final value = ItemLoan.fromJson(entry.value);
        if (value != null) {
          map[entry.key] = value;
        }
      }
    }
    return map;
  }

  // maps a json object with a list of ItemLoan-objects as value to a dart map
  static Map<String, List<ItemLoan>> mapListFromJson(
    dynamic json, {
    bool growable = false,
  }) {
    final map = <String, List<ItemLoan>>{};
    if (json is Map && json.isNotEmpty) {
      // ignore: parameter_assignments
      json = json.cast<String, dynamic>();
      for (final entry in json.entries) {
        map[entry.key] = ItemLoan.listFromJson(
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
    'item',
    'user_id',
    'borrowed_at',
    'returned_at',
  };
}
