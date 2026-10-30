/// Generador de claves de idempotencia (RN-VEN-003).
///
/// Utilidad vacía en el Hito 0: el formato de la clave y el momento en que se
/// genera y persiste se definirán con el contrato `API-IDEMP-01`.
class IdempotencyKey {
  const IdempotencyKey._();

  static String generate() {
    throw UnimplementedError('Pendiente del contrato API-IDEMP-01');
  }
}
