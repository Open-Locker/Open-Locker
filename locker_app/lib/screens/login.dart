import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:locker_app/screens/home.dart';
import 'package:locker_app/screens/instance_selection.dart';
import 'package:locker_app/services/user_service.dart';
import 'package:locker_app/widgets/login_form.dart';
import 'package:provider/provider.dart';

class LoginScreen extends StatelessWidget {
  const LoginScreen({super.key});
  static const route = '/login';

  @override
  Widget build(BuildContext context) {
    final userService = context.watch<UserService>();
    final instanceInfo = userService.instanceInfo;

    return Scaffold(
      body: Center(
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 840),
          child: Padding(
            padding: const EdgeInsets.all(24.0),
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              children: <Widget>[
                if (instanceInfo != null) ...[
                  Row(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      const Icon(Icons.dns, size: 32),
                      const SizedBox(width: 12),
                      Text(
                        instanceInfo.name,
                        style: Theme.of(context).textTheme.headlineMedium,
                      ),
                    ],
                  ),
                  const SizedBox(height: 32),
                ],
                LoginForm(
                  onSubmit: (String email, String password) =>
                      _onFormSubmitted(context, email, password),
                ),
                const SizedBox(height: 24),
                TextButton.icon(
                  onPressed: () async {
                    await userService.clearInstance();
                    if (context.mounted) {
                      context.go(InstanceSelectionScreen.route);
                    }
                  },
                  icon: const Icon(Icons.settings),
                  label: const Text('Instanz ändern'),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  _onFormSubmitted(BuildContext context, String email, String password) async {
    await Provider.of<UserService>(context, listen: false)
        .login(email, password);
    context.go(HomeScreen.route);
  }
}
