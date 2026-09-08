import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:task_flow/main.dart';

void main() {
  testWidgets('App launches and shows splash screen', (WidgetTester tester) async {
    await tester.pumpWidget(const TaskFlowApp());

    expect(find.byType(TaskFlowApp), findsOneWidget);
  });
}