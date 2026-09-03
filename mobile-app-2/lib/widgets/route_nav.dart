import 'package:flutter/material.dart';

/// Bus de navegación global: permite abrir una pestaña y pasarle parámetros
/// de filtro (deep link). ShellScreen limpia [params] al cambiar de ruta y
/// las pantallas los leen en initState para aplicar el filtro inicial.
class NavBus {
  static Map<String, dynamic> params = const {};
  static void clear() => params = const {};
  static void to(String route, [Map<String, dynamic>? p]) {
    params = p ?? const {};
  }
}

/// Simple InheritedWidget to expose a navigate callback from ShellScreen.
class NavCallback extends InheritedWidget {
  final void Function(String route) navigate;
  const NavCallback({super.key, required this.navigate, required super.child});
  @override
  bool updateShouldNotify(NavCallback oldWidget) => navigate != oldWidget.navigate;
  static NavCallback? of(BuildContext context) => context.dependOnInheritedWidgetOfExactType<NavCallback>();
}
