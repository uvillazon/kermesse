/// Configuración de la app. La URL se define al compilar, nunca en el código:
/// `flutter run --dart-define=API_BASE_URL=https://host/api/v1`
class AppConfig {
  const AppConfig._();

  static const String apiBaseUrl = String.fromEnvironment(
    'API_BASE_URL',
    // 10.0.2.2 es el host de la máquina desde el emulador Android.
    defaultValue: 'http://10.0.2.2:8000/api/v1',
  );

  static const Duration requestTimeout = Duration(seconds: 15);
}
