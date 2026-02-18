import 'package:flutter/material.dart';
import 'package:locker_app/common/theme.dart';
import 'package:locker_app/router.dart';
import 'package:locker_app/services/item_service.dart';
import 'package:locker_app/services/user_service.dart';
import 'package:provider/provider.dart';

void main() {
  runApp(const MainApp());
}

class MainApp extends StatelessWidget {
  const MainApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MultiProvider(
      providers: [
        Provider(create: (_) => UserService()),
        ProxyProvider<UserService, ItemService>(
          update: (_, userService, __) => ItemService(userService: userService),
        ),
      ],
      child: MaterialApp.router(
        title: 'Open Locker',
        theme: appTheme,
        routerConfig: router(),
      ),
    );
  }
}
