import 'dart:convert';
import 'dart:io';
import 'package:crypto/crypto.dart';
import 'package:http/http.dart' as http;
import 'package:path_provider/path_provider.dart';

/// Caché de imágenes en disco persistente: la primera descarga se guarda en
/// un directorio propio de la app y a partir de ahí la imagen se muestra
/// incluso SIN conexión. En memoria se guarda el mapeo url→archivo para no
/// golpear el disco en cada rebuild.
class ImageDisk {
  ImageDisk._();
  static final Map<String, File> _mem = {};
  static final Set<String> _warming = {};
  static Directory? _dir;

  static Future<Directory> _ensureDir() async {
    final cached = _dir;
    if (cached != null) return cached;
    final base = await getApplicationSupportDirectory();
    final d = Directory('${base.path}${Platform.pathSeparator}imgcache');
    if (!await d.exists()) await d.create(recursive: true);
    _dir = d;
    return d;
  }

  /// Nombre estable por URL (sha1) dentro del directorio de caché.
  static Future<File> _fileFor(String url) async {
    final d = await _ensureDir();
    final name = sha1.convert(utf8.encode(url)).toString();
    return File('${d.path}${Platform.pathSeparator}$name.img');
  }

  /// Devuelve el archivo local de la imagen [url]:
  /// 1) memoria → 2) disco (offline OK) → 3) descarga y guarda.
  /// Si todo falla devuelve null (mostrar fallback).
  static Future<File?> get(String url) async {
    if (url.isEmpty) return null;
    final memHit = _mem[url];
    if (memHit != null && await memHit.exists()) return memHit;
    final f = await _fileFor(url);
    if (await f.exists()) {
      _mem[url] = f;
      return f;
    }
    try {
      final res =
          await http.get(Uri.parse(url)).timeout(const Duration(seconds: 15));
      if (res.statusCode == 200 && res.bodyBytes.isNotEmpty) {
        await f.writeAsBytes(res.bodyBytes, flush: true);
        _mem[url] = f;
        return f;
      }
    } catch (_) {
      // Sin red o URL inválida → null.
    }
    return null;
  }

  /// Precalienta la caché en segundo plano (fire-and-forget): descarga la
  /// imagen aunque su widget no esté visible. Deduplicado para no repetir.
  static Future<void> warm(String url) async {
    if (url.isEmpty || _mem.containsKey(url)) return;
    if (!_warming.add(url)) return; // ya en curso
    try {
      await get(url);
    } finally {
      _warming.remove(url);
    }
  }

  /// Borra toda la caché (p.ej. al cambiar de cuenta).
  static Future<void> clear() async {
    _mem.clear();
    final d = await _ensureDir();
    if (await d.exists()) await d.delete(recursive: true);
  }
}
