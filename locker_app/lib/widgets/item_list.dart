import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:locker_api/api.dart';

class ItemList extends StatelessWidget {
  const ItemList({
    super.key,
    required this.items,
  });

  final List<Item> items;

  @override
  Widget build(BuildContext context) {
    return GridView.builder(
      itemCount: items.length,
      itemBuilder: (context, index) {
        final item = items[index];
        return ListTile(
          leading: Hero(
            tag: 'item-${item.id}',
            child: Image.network(item.imageUrl),
          ),
          title: Text(item.name),
          subtitle: Text(item.description),
          onTap: () => context.go('/items/${item.id}'),
        );
      },
      gridDelegate: SliverGridDelegateWithMaxCrossAxisExtent(
          maxCrossAxisExtent: 420, childAspectRatio: 2),
    );
  }
}
