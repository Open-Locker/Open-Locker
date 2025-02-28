import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:locker_app/models/user_service.dart';
import 'package:locker_app/widgets/bottom_nav.dart';
import 'package:locker_app/widgets/side_nav.dart';
import 'package:provider/provider.dart';

class ProfileScreen extends StatelessWidget {
  const ProfileScreen({super.key});
  static const route = '/profile';

  @override
  Widget build(BuildContext context) {
    final useSideNavRail = MediaQuery.sizeOf(context).width >= 600;
    final name = context.watch<UserService>().user;
    return Scaffold(
        appBar: AppBar(
          title: Text('Profile'),
          backgroundColor: Theme.of(context).primaryColor,
          actions: [
            IconButton(
              icon: const Icon(Icons.logout),
              onPressed: () {
                context.read<UserService>().clearUser();
                context.go('/login');
              },
            ),
          ],
        ),
        body: Row(
          children: [
            if (useSideNavRail)
              const SideNav(
                selectedIndex: 1,
              ),
            Expanded(
              child: Center(child: Text(name)),
            ),
          ],
        ),
        bottomNavigationBar: useSideNavRail
            ? null
            : const BottomNav(
                selectedIndex: 1,
              ));
  }
}
