import 'dart:convert';
import 'package:flutter/foundation.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// Servicio de cuentas guardadas (estilo Facebook).
/// Permite al usuario guardar múltiples cuentas y cambiar entre ellas
/// rápido desde el drawer, sin tener que escribir credenciales cada vez.
class SavedAccountsService extends ChangeNotifier {
  SavedAccountsService._();
  static final SavedAccountsService I = SavedAccountsService._();

  static const _key = 'wsm_saved_accounts';
  List<SavedAccount> _accounts = [];
  bool _loaded = false;

  List<SavedAccount> get accounts => List.unmodifiable(_accounts);
  bool get hasMultiple => _accounts.length > 1;

  Future<void> load() async {
    if (_loaded) return;
    final sp = await SharedPreferences.getInstance();
    final raw = sp.getString(_key);
    if (raw != null) {
      try {
        final list = jsonDecode(raw) as List;
        _accounts = list
            .map((e) => SavedAccount.fromJson(e as Map<String, dynamic>))
            .toList();
      } catch (_) {}
    }
    _loaded = true;
    notifyListeners();
  }

  /// Guarda o actualiza una cuenta tras login exitoso.
  Future<void> saveAccount({
    required String user,
    required String server,
    required String name,
    required String role,
    required String businessName,
    int? userId,
    String pass = '',
  }) async {
    // Evitar duplicados por user+server
    _accounts.removeWhere(
        (a) => a.user == user && a.server == server);
    _accounts.insert(0, SavedAccount(
      user: user,
      pass: pass,
      server: server,
      name: name,
      role: role,
      businessName: businessName,
      userId: userId,
      lastUsed: DateTime.now().millisecondsSinceEpoch,
    ));
    // Mantener solo las 5 cuentas más recientes
    if (_accounts.length > 5) {
      _accounts = _accounts.sublist(0, 5);
    }
    await _persist();
    notifyListeners();
  }

  /// Elimina una cuenta guardada.
  Future<void> removeAccount(String user, String server) async {
    _accounts.removeWhere(
        (a) => a.user == user && a.server == server);
    await _persist();
    notifyListeners();
  }

  /// Elimina todas las cuentas guardadas.
  Future<void> clearAll() async {
    _accounts.clear();
    await _persist();
    notifyListeners();
  }

  Future<void> _persist() async {
    final sp = await SharedPreferences.getInstance();
    await sp.setString(_key,
        jsonEncode(_accounts.map((a) => a.toJson()).toList()));
  }
}

class SavedAccount {
  final String user;
  final String pass; // recordada SOLO si el usuario marca "Recordar credenciales"
  final String server;
  final String name;
  final String role;
  final String businessName;
  final int? userId;
  final int lastUsed;

  const SavedAccount({
    required this.user,
    this.pass = '',
    required this.server,
    required this.name,
    required this.role,
    required this.businessName,
    this.userId,
    required this.lastUsed,
  });

  String get initials {
    final n = name.trim();
    if (n.isEmpty) return '?';
    final parts = n.split(RegExp(r'\s+'));
    if (parts.length >= 2) {
      return '${parts.first[0]}${parts.last[0]}'.toUpperCase();
    }
    return n[0].toUpperCase();
  }

  factory SavedAccount.fromJson(Map<String, dynamic> j) => SavedAccount(
        user: '${j['user'] ?? ''}',
        pass: '${j['pass'] ?? ''}',
        server: '${j['server'] ?? ''}',
        name: '${j['name'] ?? ''}',
        role: '${j['role'] ?? ''}',
        businessName: '${j['businessName'] ?? ''}',
        userId: j['userId'] as int?,
        lastUsed: (j['lastUsed'] as num?)?.toInt() ?? 0,
      );

  Map<String, dynamic> toJson() => {
        'user': user,
        'pass': pass,
        'server': server,
        'name': name,
        'role': role,
        'businessName': businessName,
        'userId': userId,
        'lastUsed': lastUsed,
      };
}
