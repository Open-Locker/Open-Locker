import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:locker_app/screens/home.dart';
import 'package:locker_app/screens/instance_selection.dart';
import 'package:locker_app/screens/item_details.dart';
import 'package:locker_app/screens/items.dart';
import 'package:locker_app/screens/login.dart';
import 'package:locker_app/screens/profile.dart';
import 'package:locker_app/screens/splash.dart';
import 'package:locker_app/services/user_service.dart';
import 'package:locker_app/widgets/scaffold_with_nav_bar.dart';
import 'package:provider/provider.dart';

final _rootNavigatorKey = GlobalKey<NavigatorState>();
final _shellNavigatorKey = GlobalKey<NavigatorState>();

GoRouter router() {
  return GoRouter(
    navigatorKey: _rootNavigatorKey,
    initialLocation: SplashScreen.route,
    routes: [
      GoRoute(
        path: SplashScreen.route,
        builder: (context, state) => const SplashScreen(),
      ),
      GoRoute(
        path: InstanceSelectionScreen.route,
        builder: (context, state) => const InstanceSelectionScreen(),
      ),
      GoRoute(
        path: LoginScreen.route,
        pageBuilder: (context, state) => CustomTransitionPage(
          key: state.pageKey,
          child: const LoginScreen(),
          transitionsBuilder: (context, animation, secondaryAnimation, child) {
            return FadeTransition(
              opacity: animation,
              child: child,
            );
          },
          transitionDuration: const Duration(milliseconds: 300),
        ),
      ),
      ShellRoute(
        navigatorKey: _shellNavigatorKey,
        pageBuilder: (context, state, child) => CustomTransitionPage(
          key: state.pageKey,
          child: ScaffoldWithNavBar(
            location: state.uri.path,
            child: child,
          ),
          transitionsBuilder: (context, animation, secondaryAnimation, child) {
            return FadeTransition(
              opacity: animation,
              child: child,
            );
          },
          transitionDuration: const Duration(milliseconds: 300),
        ),
        routes: [
          GoRoute(
            path: HomeScreen.route,
            builder: (context, state) => const HomeScreen(),
          ),
          GoRoute(
            path: ItemsScreen.route,
            builder: (context, state) => const ItemsScreen(),
            routes: [
              GoRoute(
                path: ':id',
                parentNavigatorKey: _rootNavigatorKey,
                pageBuilder: (context, state) => CustomTransitionPage(
                  key: state.pageKey,
                  child: ItemDetailsScreen(
                      itemId: state.pathParameters['id'] ?? ''),
                  transitionsBuilder:
                      (context, animation, secondaryAnimation, child) {
                    return SlideTransition(
                      position: animation.drive(
                        Tween(
                          begin: const Offset(1.0, 0.0),
                          end: Offset.zero,
                        ).chain(CurveTween(curve: Curves.easeInOut)),
                      ),
                      child: child,
                    );
                  },
                  transitionDuration: const Duration(milliseconds: 300),
                ),
              ),
            ],
          ),
          GoRoute(
            path: ProfileScreen.route,
            builder: (context, state) => const ProfileScreen(),
          ),
        ],
      ),
    ],
    redirect: (context, state) async {
      final userService = context.read<UserService>();

      // Wenn wir auf dem Splash-Screen sind, keine Weiterleitung
      if (state.matchedLocation == SplashScreen.route) {
        return null;
      }

      // Wenn wir auf dem Instance Selection Screen sind und keine Instanz ausgewählt ist,
      // bleiben wir dort
      if (state.matchedLocation == InstanceSelectionScreen.route &&
          userService.instanceUrl.isEmpty) {
        return null;
      }

      // Wenn wir auf dem Instance Selection Screen sind und eine Instanz ausgewählt ist,
      // gehen wir zum Login
      if (state.matchedLocation == InstanceSelectionScreen.route &&
          userService.instanceUrl.isNotEmpty) {
        return LoginScreen.route;
      }

      // Wenn wir auf dem Login-Screen sind und nicht authentifiziert sind,
      // bleiben wir dort
      if (state.matchedLocation == LoginScreen.route &&
          !userService.isAuthenticated) {
        return null;
      }

      // Wenn wir nicht authentifiziert sind und keine Instanz ausgewählt ist,
      // gehen wir zur Instanz-Auswahl
      if (!userService.isAuthenticated &&
          userService.instanceUrl.isEmpty &&
          state.matchedLocation != InstanceSelectionScreen.route) {
        return InstanceSelectionScreen.route;
      }

      // Wenn wir nicht authentifiziert sind und eine Instanz ausgewählt ist,
      // gehen wir zum Login
      if (!userService.isAuthenticated &&
          userService.instanceUrl.isNotEmpty &&
          state.matchedLocation != LoginScreen.route) {
        return LoginScreen.route;
      }

      // Wenn wir authentifiziert sind und auf der Login-Route sind,
      // gehen wir zur Home-Route
      if (userService.isAuthenticated &&
          state.matchedLocation == LoginScreen.route) {
        return HomeScreen.route;
      }

      // In allen anderen Fällen bleiben wir wo wir sind
      return null;
    },
  );
}
