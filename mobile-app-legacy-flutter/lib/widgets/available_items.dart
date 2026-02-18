import 'package:flutter/material.dart';
import 'package:locker_app/services/item_service.dart';
import 'package:locker_app/widgets/item_list.dart';
import 'package:locker_app/widgets/no_items_available.dart';
import 'package:provider/provider.dart';

class AvailableItems extends StatelessWidget {
  const AvailableItems({
    super.key,
  });

  @override
  Widget build(BuildContext context) {
    return Consumer(builder: (context, ItemService itemService, child) {
      return FutureBuilder(
        future: itemService.getItems().then((items) =>
            items.where((item) => item.borrowedAt == null).toList()),
        builder: (context, snapshot) {
          if (snapshot.connectionState == ConnectionState.waiting) {
            return const Center(
              child: CircularProgressIndicator(),
            );
          }

          if (snapshot.hasError) {
            return Center(
              child: Padding(
                padding: const EdgeInsets.all(16.0),
                child: Column(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    const Icon(
                      Icons.error_outline,
                      color: Colors.red,
                      size: 48,
                    ),
                    const SizedBox(height: 16),
                    Text(
                      'Fehler beim Laden der Items',
                      style: Theme.of(context).textTheme.titleLarge,
                    ),
                    const SizedBox(height: 8),
                    Text(
                      snapshot.error.toString(),
                      textAlign: TextAlign.center,
                      style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                            color: Theme.of(context).colorScheme.error,
                          ),
                    ),
                    const SizedBox(height: 16),
                    FilledButton.icon(
                      onPressed: () {
                        // Force rebuild
                        (context as Element).markNeedsBuild();
                      },
                      icon: const Icon(Icons.refresh),
                      label: const Text('Erneut versuchen'),
                    ),
                  ],
                ),
              ),
            );
          }

          final items = snapshot.data!;

          if (items.isEmpty) {
            return const NoItemsAvailable();
          }

          return ItemList(items: items);
        },
      );
    });
  }
}
