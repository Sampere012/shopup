import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// Servicio de persistencia del tema (light/dark/system).
/// Guarda la preferencia del usuario en SharedPreferences.
class ThemeService extends ChangeNotifier {
  ThemeService._();
  static final ThemeService I = ThemeService._();

  static const _key = 'wsm_theme_mode';
  ThemeMode _mode = ThemeMode.system;
  bool _loaded = false;

  ThemeMode get mode => _mode;

  bool get isDark => _mode == ThemeMode.dark ||
      (_mode == ThemeMode.system &&
          WidgetsBinding.instance.platformDispatcher.platformBrightness ==
              Brightness.dark);

  Future<void> load() async {
    if (_loaded) return;
    final sp = await SharedPreferences.getInstance();
    final raw = sp.getString(_key);
    _mode = switch (raw) {
      'dark' => ThemeMode.dark,
      'light' => ThemeMode.light,
      _ => ThemeMode.system,
    };
    _loaded = true;
    notifyListeners();
  }

  Future<void> setMode(ThemeMode mode) async {
    _mode = mode;
    final sp = await SharedPreferences.getInstance();
    await sp.setString(_key, mode.name);
    notifyListeners();
  }

  Future<void> toggleDark() async {
    await setMode(isDark ? ThemeMode.light : ThemeMode.dark);
  }
}
