import 'package:flutter/material.dart';

class NoItemsBorrowed extends StatelessWidget {
  const NoItemsBorrowed({
    super.key,
  });

  @override
  Widget build(BuildContext context) {
    return Column(
      mainAxisAlignment: MainAxisAlignment.center,
      children: [
        Text('No items borrowed'),
      ],
    );
  }
}
