import 'package:flutter/material.dart';
import 'package:locker_app/widgets/borrowed_items.dart';

class HomeScreen extends StatelessWidget {
  const HomeScreen({super.key});
  static const route = '/home';

  @override
  Widget build(BuildContext context) {
    return const BorrowedItems();
  }
}
