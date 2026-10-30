import 'package:flutter/material.dart';

import '../../../core/errors/app_exception.dart';
import '../../../core/network/api_client.dart';
import '../data/health_repository_impl.dart';
import '../domain/health_repository.dart';

class HomePage extends StatefulWidget {
  const HomePage({super.key, this.repository});

  /// Permite inyectar un repositorio en pruebas.
  final HealthRepository? repository;

  @override
  State<HomePage> createState() => _HomePageState();
}

class _HomePageState extends State<HomePage> {
  late final HealthRepository _repository =
      widget.repository ?? HealthRepositoryImpl(ApiClient());
  String? _result;
  bool _loading = false;

  Future<void> _checkConnection() async {
    setState(() {
      _loading = true;
      _result = null;
    });
    try {
      final status = await _repository.check();
      _result = status.ok ? 'Servidor disponible' : 'Servidor con problemas';
    } on AppException catch (e) {
      _result = e.message;
    }
    if (mounted) setState(() => _loading = false);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Kermesse')),
      body: Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(
                'Operador móvil',
                style: Theme.of(context).textTheme.headlineSmall,
              ),
              const SizedBox(height: 24),
              FilledButton(
                onPressed: _loading ? null : _checkConnection,
                child: const Text('Probar conexión'),
              ),
              const SizedBox(height: 16),
              if (_loading) const CircularProgressIndicator(),
              if (_result != null) Text(_result!),
            ],
          ),
        ),
      ),
    );
  }
}
