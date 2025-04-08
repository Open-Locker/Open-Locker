//
// AUTO-GENERATED FILE, DO NOT MODIFY!
//
// @dart=2.18

// ignore_for_file: unused_element, unused_import
// ignore_for_file: always_put_required_named_parameters_first
// ignore_for_file: constant_identifier_names
// ignore_for_file: lines_longer_than_80_chars

part of openapi.api;

class Item {
  /// Returns a new [Item] instance.
  Item({
    required this.id,
    required this.name,
    required this.description,
    required this.imageUrl,
    required this.lockerId,
    this.borrowedAt,
    required this.createdAt,
    required this.updatedAt,
  });

  int id;

  String name;

  String description;

  String imageUrl;

  int? lockerId;

  DateTime? borrowedAt;

  DateTime? createdAt;

  DateTime? updatedAt;

  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      other is Item &&
          other.id == id &&
          other.name == name &&
          other.description == description &&
          other.imageUrl == imageUrl &&
          other.lockerId == lockerId &&
          other.borrowedAt == borrowedAt &&
          other.createdAt == createdAt &&
          other.updatedAt == updatedAt;

  @override
  int get hashCode =>
      // ignore: unnecessary_parenthesis
      (id.hashCode) +
      (name.hashCode) +
      (description.hashCode) +
      (imageUrl.hashCode) +
      (lockerId == null ? 0 : lockerId!.hashCode) +
      (borrowedAt == null ? 0 : borrowedAt!.hashCode) +
      (createdAt == null ? 0 : createdAt!.hashCode) +
      (updatedAt == null ? 0 : updatedAt!.hashCode);

  @override
  String toString() =>
      'Item[id=$id, name=$name, description=$description, imageUrl=$imageUrl, lockerId=$lockerId, borrowedAt=$borrowedAt, createdAt=$createdAt, updatedAt=$updatedAt]';

  Map<String, dynamic> toJson() {
    final json = <String, dynamic>{};
    json[r'id'] = this.id;
    json[r'name'] = this.name;
    json[r'description'] = this.description;
    json[r'image_url'] = this.imageUrl;
    if (this.lockerId != null) {
      json[r'locker_id'] = this.lockerId;
    } else {
      json[r'locker_id'] = null;
    }
    if (this.borrowedAt != null) {
      json[r'borrowed_at'] = this.borrowedAt!.toUtc().toIso8601String();
    } else {
      json[r'borrowed_at'] = null;
    }
    if (this.createdAt != null) {
      json[r'created_at'] = this.createdAt!.toUtc().toIso8601String();
    } else {
      json[r'created_at'] = null;
    }
    if (this.updatedAt != null) {
      json[r'updated_at'] = this.updatedAt!.toUtc().toIso8601String();
    } else {
      json[r'updated_at'] = null;
    }
    return json;
  }

  /// Returns a new [Item] instance and imports its values from
  /// [value] if it's a [Map], null otherwise.
  // ignore: prefer_constructors_over_static_methods
  static Item? fromJson(dynamic value) {
    if (value is Map) {
      final json = value.cast<String, dynamic>();

      // Ensure that the map contains the required keys.
      // Note 1: the values aren't checked for validity beyond being non-null.
      // Note 2: this code is stripped in release mode!
      assert(() {
        requiredKeys.forEach((key) {
          assert(json.containsKey(key),
              'Required key "Item[$key]" is missing from JSON.');
          assert(json[key] != null,
              'Required key "Item[$key]" has a null value in JSON.');
        });
        return true;
      }());

      return Item(
        id: mapValueOfType<int>(json, r'id')!,
        name: mapValueOfType<String>(json, r'name')!,
        description: mapValueOfType<String>(json, r'description')!,
        imageUrl: mapValueOfType<String>(json, r'image_url')!,
        lockerId: mapValueOfType<int>(json, r'locker_id'),
        borrowedAt: json[r'borrowed_at'] == null
            ? null
            : mapDateTime(json, r'borrowed_at', r''),
        createdAt: mapDateTime(json, r'created_at', r''),
        updatedAt: mapDateTime(json, r'updated_at', r''),
      );
    }
    return null;
  }

  static List<Item> listFromJson(
    dynamic json, {
    bool growable = false,
  }) {
    final result = <Item>[];
    if (json is List && json.isNotEmpty) {
      for (final row in json) {
        final value = Item.fromJson(row);
        if (value != null) {
          result.add(value);
        }
      }
    }
    return result.toList(growable: growable);
  }

  static Map<String, Item> mapFromJson(dynamic json) {
    final map = <String, Item>{};
    if (json is Map && json.isNotEmpty) {
      json = json.cast<String, dynamic>(); // ignore: parameter_assignments
      for (final entry in json.entries) {
        final value = Item.fromJson(entry.value);
        if (value != null) {
          map[entry.key] = value;
        }
      }
    }
    return map;
  }

  // maps a json object with a list of Item-objects as value to a dart map
  static Map<String, List<Item>> mapListFromJson(
    dynamic json, {
    bool growable = false,
  }) {
    final map = <String, List<Item>>{};
    if (json is Map && json.isNotEmpty) {
      // ignore: parameter_assignments
      json = json.cast<String, dynamic>();
      for (final entry in json.entries) {
        map[entry.key] = Item.listFromJson(
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
    'description',
    'image_url',
    'locker_id',
    'created_at',
    'updated_at',
  };
}
