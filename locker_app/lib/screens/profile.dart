import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:locker_app/screens/instance_selection.dart';
import 'package:locker_app/screens/login.dart';
import 'package:locker_app/services/user_service.dart';
import 'package:provider/provider.dart';

class ProfileScreen extends StatelessWidget {
  const ProfileScreen({super.key});
  static const route = '/profile';

  @override
  Widget build(BuildContext context) {
    final userService = context.watch<UserService>();
    final name = userService.user;
    final instanceInfo = userService.instanceInfo;

    return Scaffold(
      body: Center(
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 840),
          child: Padding(
            padding: const EdgeInsets.all(16.0),
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'Profil',
                  style: Theme.of(context).textTheme.headlineMedium,
                ),
                const SizedBox(height: 24),
                Text(
                  'Name: $name',
                  style: Theme.of(context).textTheme.bodyLarge,
                ),
                const SizedBox(height: 16),
                if (instanceInfo != null) ...[
                  Row(
                    children: [
                      const Icon(Icons.dns),
                      const SizedBox(width: 8),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              instanceInfo.name,
                              style: Theme.of(context).textTheme.titleMedium,
                            ),
                            Text(
                              userService.instanceUrl,
                              style: Theme.of(context).textTheme.bodyMedium,
                            ),
                          ],
                        ),
                      ),
                    ],
                  ),
                ],
              ],
            ),
          ),
        ),
      ),
      bottomNavigationBar: Padding(
        padding: const EdgeInsets.only(bottom: 16.0),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            TextButton.icon(
              onPressed: () async {
                await userService.logout();
                await userService.clearInstance();
                if (context.mounted) {
                  context.go(InstanceSelectionScreen.route);
                }
              },
              icon: const Icon(Icons.settings),
              label: const Text('Instanz ändern'),
            ),
            TextButton.icon(
              onPressed: () async {
                await userService.logout();
                if (context.mounted) {
                  context.go(LoginScreen.route);
                }
              },
              icon: const Icon(Icons.logout),
              label: const Text('Logout'),
            ),
          ],
        ),
      ),
    );
  }
}
