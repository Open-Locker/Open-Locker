import 'package:flutter/material.dart';
import 'package:locker_app/widgets/available_items.dart';

class ItemsScreen extends StatelessWidget {
  const ItemsScreen({super.key});
  static const route = '/items';

  @override
  Widget build(BuildContext context) {
    return AvailableItems();
  }
}
