import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

class SideNav extends StatelessWidget {
  final int selectedIndex;
  const SideNav({super.key, required this.selectedIndex});

  @override
  Widget build(BuildContext context) {
    return NavigationRail(
      destinations: const [
        NavigationRailDestination(
          icon: Icon(Icons.list),
          label: Text('Verlauf'),
        ),
        NavigationRailDestination(
          icon: Icon(Icons.unarchive),
          label: Text('Ausleihen'),
        ),
        NavigationRailDestination(
          icon: Icon(Icons.account_circle),
          label: Text('Profil'),
        ),
      ],
      onDestinationSelected: (int index) {
        if (index == 0) {
          context.go('/home');
        } else if (index == 1) {
          context.go('/items');
        } else if (index == 2) {
          context.go('/profile');
        }
      },
      selectedIndex: selectedIndex,
      labelType: NavigationRailLabelType.all,
    );
  }
}
