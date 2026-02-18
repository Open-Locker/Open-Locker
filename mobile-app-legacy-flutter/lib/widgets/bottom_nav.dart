import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

class BottomNav extends StatelessWidget {
  final int selectedIndex;
  const BottomNav({super.key, required this.selectedIndex});

  @override
  Widget build(BuildContext context) {
    return BottomNavigationBar(
      items: const [
        BottomNavigationBarItem(
          icon: Icon(Icons.list),
          label: 'Verlauf',
        ),
        BottomNavigationBarItem(
          icon: Icon(Icons.unarchive),
          label: 'Ausleihen',
        ),
        BottomNavigationBarItem(
          icon: Icon(Icons.account_circle),
          label: 'Profil',
        ),
      ],
      onTap: (index) {
        if (index == selectedIndex) return;
        
        if (index == 0) {
          context.go('/home');
        } else if (index == 1) {
          context.go('/items');
        } else if (index == 2) {
          context.go('/profile');
        }
      },
      currentIndex: selectedIndex,
    );
  }
}
