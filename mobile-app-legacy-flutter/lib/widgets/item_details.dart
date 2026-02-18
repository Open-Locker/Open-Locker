import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:locker_api/api.dart';
import 'package:locker_app/screens/home.dart';
import 'package:locker_app/services/item_service.dart';
import 'package:provider/provider.dart';

class ItemDetails extends StatelessWidget {
  const ItemDetails({
    super.key,
    required this.itemId,
  });

  final String itemId;

  @override
  Widget build(BuildContext context) {
    return Consumer(
        builder: (BuildContext context, ItemService service, child) {
      return FutureBuilder(
        future: service.getItem(int.parse(itemId)),
        builder: (context, snapshot) {
          if (snapshot.connectionState == ConnectionState.waiting) {
            return Center(child: CircularProgressIndicator());
          }
          if (snapshot.hasError) {
            return Center(child: Text('An error occurred'));
          }

          return ItemCard(item: snapshot.data!);
        },
      );
    });
  }
}

class ItemCard extends StatelessWidget {
  const ItemCard({
    super.key,
    required this.item,
  });

  final Item item;

  @override
  Widget build(BuildContext context) {
    return Consumer(
      builder: (BuildContext context, ItemService service, child) {
        return Column(
          children: [
            Card(
              elevation: 8,
              child: Column(
                children: [
                  ListTile(
                    title: Text(item.name),
                    subtitle: Text(item.description),
                    isThreeLine: true,
                    leading: Image.network(
                        'https://developers.elementor.com/docs/assets/img/elementor-placeholder-image.png'),
                  ),
                  Padding(
                    padding: const EdgeInsets.all(8.0),
                    child: Row(
                      mainAxisAlignment: MainAxisAlignment.spaceEvenly,
                      children: [
                        ElevatedButton(
                          onPressed: () async {
                            if (item.borrowedAt != null) {
                              await service.returnItem(item.id);
                            } else {
                              await service.borrowItem(item.id);
                            }
                            context.go(HomeScreen.route);
                          },
                          child: item.borrowedAt != null
                              ? Text('Zurückgeben')
                              : Text('Ausleihen'),
                        ),
                      ],
                    ),
                  )
                ],
              ),
            ),
          ],
        );
      },
    );
  }
}
