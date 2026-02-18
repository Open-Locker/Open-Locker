import 'package:flutter/material.dart';
import 'package:locker_app/widgets/bottom_nav.dart';
import 'package:locker_app/widgets/side_nav.dart';

class ScaffoldWithNavBar extends StatelessWidget {
  const ScaffoldWithNavBar({
    super.key,
    required this.child,
    required this.location,
  });

  final Widget child;
  final String location;

  int _calculateSelectedIndex(String location) {
    if (location.startsWith('/home')) return 0;
    if (location.startsWith('/items')) return 1;
    if (location.startsWith('/profile')) return 2;
    return 0;
  }

  @override
  Widget build(BuildContext context) {
    final useSideNavRail = MediaQuery.sizeOf(context).width >= 600;
    final selectedIndex = _calculateSelectedIndex(location);
    return Scaffold(
      appBar: AppBar(
        title: Text(location),
        backgroundColor: Theme.of(context).primaryColor,
      ),
      body: useSideNavRail
          ? Row(
              children: [
                if (useSideNavRail)
                  SideNav(
                    selectedIndex: selectedIndex,
                  ),
                Expanded(
                  child: child,
                ),
              ],
            )
          : child,
      bottomNavigationBar: useSideNavRail
          ? null
          : BottomNav(
              selectedIndex: selectedIndex,
            ),
    );
  }
}
