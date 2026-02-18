import 'package:flutter/material.dart';
import 'package:locker_app/widgets/item_details.dart';

class ItemDetailsScreen extends StatelessWidget {
  final String itemId;
  const ItemDetailsScreen({super.key, required this.itemId});
  static const route = '/items/:id';

  @override
  Widget build(BuildContext context) {
    return ItemDetails(itemId: itemId); 
  }
}
