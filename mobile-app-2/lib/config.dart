class AppConfig {
  /// Servidor del negocio (WordPress con el tema Workshop). Se sobrescribe
  /// desde la pantalla de login y queda guardado en el dispositivo.
  static const String defaultServer = 'https://shopup.site.je';

  /// Autosync en segundo plano (minutos). Cambiable desde Configuración.
  static const int autoSyncMinutes = 25;

  /// Versión de ESTE build (debe ser > 0.4.66 del APK Cordova).
  static const String appVersion = '0.5.0';
}
