import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:shopup_panel/main.dart';
import 'package:shopup_panel/services/api_service.dart';
import 'package:shopup_panel/services/auth_service.dart';

void main() {
  testWidgets('Sin sesión válida muestra la pantalla de login',
      (tester) async {
    TestWidgetsFlutterBinding.ensureInitialized();
    SharedPreferences.setMockInitialValues({});
    await ApiService.I.load();
    await AuthService.I.load();
    await tester.pumpWidget(const ShopUpApp());
    await tester.pumpAndSettle(const Duration(seconds: 2));
    expect(find.text('Entrar'), findsOneWidget);
  });
}
