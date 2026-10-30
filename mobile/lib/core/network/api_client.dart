import 'dart:async';
import 'dart:convert';

import 'package:http/http.dart' as http;

import '../config/app_config.dart';
import '../errors/app_exception.dart';

/// Cliente HTTP hacia `/api/v1`. Traduce cualquier fallo a [AppException].
class ApiClient {
  ApiClient({http.Client? client, String? baseUrl})
      : _client = client ?? http.Client(),
        _baseUrl = baseUrl ?? AppConfig.apiBaseUrl;

  final http.Client _client;
  final String _baseUrl;

  Future<Map<String, dynamic>> get(String path) => _send('GET', path);

  Future<Map<String, dynamic>> post(
    String path, {
    Map<String, dynamic>? body,
    Map<String, String>? headers,
  }) =>
      _send('POST', path, body: body, headers: headers);

  Future<Map<String, dynamic>> _send(
    String method,
    String path, {
    Map<String, dynamic>? body,
    Map<String, String>? headers,
  }) async {
    final uri = Uri.parse('$_baseUrl$path');
    final request = http.Request(method, uri)
      ..headers.addAll({
        'Accept': 'application/json',
        if (body != null) 'Content-Type': 'application/json',
        ...?headers,
      });
    if (body != null) request.body = jsonEncode(body);

    try {
      final streamed =
          await _client.send(request).timeout(AppConfig.requestTimeout);
      final response = await http.Response.fromStream(streamed);
      return _parse(response);
    } on TimeoutException {
      throw const NetworkException('Tiempo de espera agotado');
    } on http.ClientException {
      throw const NetworkException();
    }
  }

  Map<String, dynamic> _parse(http.Response response) {
    Map<String, dynamic> json = const {};
    if (response.body.isNotEmpty) {
      try {
        final decoded = jsonDecode(response.body);
        if (decoded is Map<String, dynamic>) json = decoded;
      } on FormatException {
        if (response.statusCode < 400) {
          throw const NetworkException('Respuesta no válida del servidor');
        }
      }
    }

    if (response.statusCode >= 400) {
      throw ApiException(
        response.statusCode,
        (json['message'] ?? json['detail'] ?? 'Error ${response.statusCode}')
            .toString(),
        retryAfter: int.tryParse(response.headers['retry-after'] ?? ''),
      );
    }
    return json;
  }

  void close() => _client.close();
}
