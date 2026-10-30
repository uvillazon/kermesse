import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:kermesse_mobile/core/errors/app_exception.dart';
import 'package:kermesse_mobile/features/home/domain/health_repository.dart';
import 'package:kermesse_mobile/features/home/domain/health_status.dart';
import 'package:kermesse_mobile/features/home/presentation/home_page.dart';

class _FakeRepository implements HealthRepository {
  _FakeRepository({this.fail = false});

  final bool fail;

  @override
  Future<HealthStatus> check() async {
    if (fail) throw const NetworkException();
    return const HealthStatus(ok: true);
  }
}

Widget _wrap(HealthRepository repo) =>
    MaterialApp(home: HomePage(repository: repo));

void main() {
  testWidgets('muestra el título y el botón de conexión', (tester) async {
    await tester.pumpWidget(_wrap(_FakeRepository()));

    expect(find.text('Operador móvil'), findsOneWidget);
    expect(find.text('Probar conexión'), findsOneWidget);
  });

  testWidgets('informa servidor disponible', (tester) async {
    await tester.pumpWidget(_wrap(_FakeRepository()));
    await tester.tap(find.text('Probar conexión'));
    await tester.pumpAndSettle();

    expect(find.text('Servidor disponible'), findsOneWidget);
  });

  testWidgets('muestra el error uniforme si falla la red', (tester) async {
    await tester.pumpWidget(_wrap(_FakeRepository(fail: true)));
    await tester.tap(find.text('Probar conexión'));
    await tester.pumpAndSettle();

    expect(find.text('Sin conexión con el servidor'), findsOneWidget);
  });
}
