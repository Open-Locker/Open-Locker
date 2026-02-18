import 'package:flutter/material.dart';
import 'package:locker_app/services/item_service.dart';
import 'package:locker_app/widgets/item_list.dart';
import 'package:locker_app/widgets/no_items_borrowed.dart';
import 'package:provider/provider.dart';

class BorrowedItems extends StatelessWidget {
  const BorrowedItems({
    super.key,
  });

  @override
  Widget build(BuildContext context) {
    return Consumer(builder: (context, ItemService service, child) {
      return FutureBuilder(
        future: service.getBorrowedItems(),
        builder: (context, snapshot) {
          if (snapshot.connectionState == ConnectionState.waiting) {
            return Center(child: CircularProgressIndicator());
          }
          if (snapshot.hasError) {
            return Center(child: Text('An error occurred'));
          }

          final items = snapshot.data!;

          if (items.isEmpty) {
            return NoItemsBorrowed();
          }

          return ItemList(items: items);
        },
      );
    });
  }
}
