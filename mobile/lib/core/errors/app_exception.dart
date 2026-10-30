/// Error uniforme que la capa de datos entrega a dominio y presentación.
sealed class AppException implements Exception {
  const AppException(this.message);

  final String message;

  @override
  String toString() => '$runtimeType: $message';
}

/// Sin conexión, tiempo agotado o respuesta ilegible.
class NetworkException extends AppException {
  const NetworkException([super.message = 'Sin conexión con el servidor']);
}

/// Respuesta HTTP de error del backend.
class ApiException extends AppException {
  const ApiException(this.statusCode, super.message, {this.retryAfter});

  final int statusCode;

  /// Segundos sugeridos por el servidor (por ejemplo, con 429).
  final int? retryAfter;

  bool get isRateLimited => statusCode == 429;

  bool get isUnauthorized => statusCode == 401 || statusCode == 403;
}
