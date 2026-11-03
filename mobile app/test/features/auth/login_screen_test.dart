import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mocktail/mocktail.dart';
import 'package:water_project/core/auth/session_service.dart';
import 'package:water_project/features/auth/presentation/cubit/auth_cubit.dart';
import 'package:water_project/features/auth/presentation/pages/login_screen.dart';

class _MockSessionService extends Mock implements SessionService {}

void main() {
  testWidgets('login email is prefilled and remains editable', (tester) async {
    final cubit = AuthCubit(_MockSessionService());
    addTearDown(cubit.close);

    await tester.pumpWidget(
      MaterialApp(
        home: BlocProvider.value(value: cubit, child: const LoginScreen()),
      ),
    );

    final fields = find.byType(TextFormField);
    final email = fields.first;
    expect(
      tester.widget<TextFormField>(email).controller!.text,
      'admin@water.test',
    );
    expect(
      tester.widget<TextFormField>(fields.at(1)).controller!.text,
      isEmpty,
    );

    await tester.enterText(email, 'other@water.test');
    await tester.tap(find.byTooltip('إظهار كلمة المرور'));
    await tester.pump();
    expect(
      tester.widget<TextFormField>(email).controller!.text,
      'other@water.test',
    );
  });
}
