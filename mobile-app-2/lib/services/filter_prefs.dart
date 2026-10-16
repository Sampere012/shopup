import 'package:shared_preferences/shared_preferences.dart';

/// Filtros de las vistas (ubicación, período…) persistidos en el DISPOSITIVO.
///
/// Las pantallas se destruyen al navegar y además cada sync les obliga a
/// recargar: sin persistencia local, el usuario que registraba algo offline
/// "perdía" la ubicación seleccionada y volvía a «Todas» en cada recarga.
/// Es 100% local (SharedPreferences): funciona sin conexión y no viaja al
/// servidor.
class FilterPrefs {
  FilterPrefs._();

  static const _prefix = 'wsm_filter_';

  // Claves usadas por las vistas.
  static const stockLoc = 'stock_loc';
  static const movementsLoc = 'movements_loc';
  static const countsLoc = 'counts_loc';
  static const posLoc = 'pos_loc';
  static const shiftsLoc = 'shifts_loc';
  static const reportsLoc = 'reports_loc';
  static const reportsPeriod = 'reports_period';

  static String _k(String key) => '$_prefix$key';

  static Future<String> loc(String key, {String def = ''}) async {
    try {
      final sp = await SharedPreferences.getInstance();
      return sp.getString(_k(key)) ?? def;
    } catch (_) {
      return def;
    }
  }

  static Future<void> setLoc(String key, String value) async {
    try {
      final sp = await SharedPreferences.getInstance();
      if (value.isEmpty) {
        await sp.remove(_k(key));
      } else {
        await sp.setString(_k(key), value);
      }
    } catch (_) {}
  }

  static Future<int> intVal(String key, {int def = 0}) async {
    try {
      final sp = await SharedPreferences.getInstance();
      return sp.getInt(_k(key)) ?? def;
    } catch (_) {
      return def;
    }
  }

  static Future<void> setInt(String key, int value) async {
    try {
      final sp = await SharedPreferences.getInstance();
      await sp.setInt(_k(key), value);
    } catch (_) {}
  }

  /// Devuelve [saved] solo si sigue existiendo en el catálogo conocido.
  /// Evita que un Dropdown quede con un valor huérfano (p.ej. una
  /// ubicación borrada) y reviente con la aserción de Flutter.
  static String keepIfKnown(String saved, Iterable<String> known) {
    if (saved.isEmpty) return '';
    return known.contains(saved) ? saved : '';
  }
}
