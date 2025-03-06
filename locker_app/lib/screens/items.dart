import 'package:flutter/material.dart';
import 'package:locker_app/widgets/available_items.dart';
import 'package:locker_app/widgets/side_nav.dart';

class ItemsScreen extends StatelessWidget {
  const ItemsScreen({super.key});
  static const route = '/items';

  @override
  Widget build(BuildContext context) {
    final useSideNavRail = MediaQuery.sizeOf(context).width >= 600;
    return Scaffold(
      appBar: AppBar(
        title: Text('Items'),
        backgroundColor: Theme.of(context).primaryColor,
      ),
      body: Row(
        children: [
          if (useSideNavRail)
            const SideNav(
              selectedIndex: 1,
            ),
          const Expanded(
            child: AvailableItems(),
          ),
        ],
      ),
    );
  }
}
