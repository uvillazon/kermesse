import 'health_status.dart';

abstract interface class HealthRepository {
  Future<HealthStatus> check();
}
