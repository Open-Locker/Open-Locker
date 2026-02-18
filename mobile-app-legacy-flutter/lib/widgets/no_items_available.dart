import 'package:flutter/material.dart';

class NoItemsAvailable extends StatelessWidget {
  const NoItemsAvailable({
    super.key,
  });

  @override
  Widget build(BuildContext context) {
    return Column(
      mainAxisAlignment: MainAxisAlignment.center,
      children: [
        Text('No items available'),
      ],
    );
  }
}
