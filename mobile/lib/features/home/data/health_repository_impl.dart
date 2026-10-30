import '../../../core/network/api_client.dart';
import '../domain/health_repository.dart';
import '../domain/health_status.dart';

class HealthRepositoryImpl implements HealthRepository {
  HealthRepositoryImpl(this._api);

  final ApiClient _api;

  @override
  Future<HealthStatus> check() async {
    final json = await _api.get('/health');
    return HealthStatus(ok: json['status'] == 'ok');
  }
}
