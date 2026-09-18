class AppConfig {
  /// Servidor del negocio (WordPress con el tema Workshop). Se sobrescribe
  /// desde la pantalla de login y queda guardado en el dispositivo.
  static const String defaultServer = 'https://shopup.site.je';

  /// Autosync en segundo plano (minutos). Cambiable desde Configuración.
  static const int autoSyncMinutes = 25;

  /// Frecuencia del worker local de licencia: consulta la nube cada pocos
  /// minutos para verificar que la suscripción sigue activa.
  static const int licenseCheckMinutes = 5;

  /// Período de gracia offline (horas): si la nube no responde y pasó este
  /// tiempo desde la última verificación correcta, el negocio se bloquea
  /// igualmente (fail-closed: no se trabaja sin verificación reciente).
  static const int licenseGraceHours = 48;

  /// Versión de ESTE build (debe coincidir con la de pubspec.yaml).
  static const String appVersion = '0.5.8';
}
