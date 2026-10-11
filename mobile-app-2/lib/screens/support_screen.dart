import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';
import '../services/api_service.dart';
import '../services/auth_service.dart';
import '../services/db_service.dart';
import '../services/sync_service.dart';
import '../theme/app_theme.dart';
import '../widgets/common.dart';

class SupportScreen extends StatefulWidget {
  const SupportScreen({super.key});

  @override
  State<SupportScreen> createState() => _SupportScreenState();
}

class _SupportScreenState extends State<SupportScreen> {
  Map<String, dynamic> _links = {};
  bool _loading = true;
  String _error = '';

  static const _labels = <String, String>{
    'whatsapp': 'WhatsApp',
    'telegram': 'Telegram',
    'facebook': 'Facebook',
    'other': 'Otro canal',
  };

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = '';
    });
    try {
      final result = await ApiService.I.req('ws_app_version', {});
      final settings = result is Map ? result['support_links'] : null;
      _links = settings is Map
          ? Map<String, dynamic>.from(settings)
          : <String, dynamic>{};
      await DbService.I.cacheSet('ws_support_links', _links);
    } on ApiException catch (e) {
      final cached = await DbService.I.cacheGet('ws_support_links');
      _links = cached is Map
          ? Map<String, dynamic>.from(cached)
          : <String, dynamic>{};
      _error = e.message;
    } catch (_) {
      final cached = await DbService.I.cacheGet('ws_support_links');
      _links = cached is Map
          ? Map<String, dynamic>.from(cached)
          : <String, dynamic>{};
      _error = 'No se pudieron cargar los contactos de soporte.';
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _openLink(String label, String value) async {
    final uri = Uri.tryParse(value);
    if (uri == null ||
        !const {'http', 'https'}.contains(uri.scheme.toLowerCase())) {
      U.toast(context, 'El enlace de $label no es válido.', kind: 'err');
      return;
    }
    final launched = await launchUrl(uri, mode: LaunchMode.externalApplication);
    if (!launched && mounted) {
      U.toast(context, 'No se pudo abrir $label.', kind: 'err');
    }
  }

  Future<void> _sendRequest() async {
    final controller = TextEditingController();
    var busy = false;
    var error = '';
    final ownerName = '${AuthService.I.me?['name'] ?? ''}';

    await showDialog<void>(
      context: context,
      builder: (dialogContext) => StatefulBuilder(
        builder: (dialogContext, setDialogState) => AlertDialog(
          title: const Text('Escribir a soporte'),
          content: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Align(
                alignment: Alignment.centerLeft,
                child: Text(
                  'Solicitud de $ownerName',
                  style: TextStyle(color: Colors.grey[600], fontSize: 12),
                ),
              ),
              const SizedBox(height: 8),
              TextField(
                controller: controller,
                minLines: 3,
                maxLines: 6,
                maxLength: 4000,
                decoration: const InputDecoration(
                  hintText: 'Cuéntanos cómo podemos ayudarte',
                ),
              ),
              if (error.isNotEmpty)
                Align(
                  alignment: Alignment.centerLeft,
                  child: Text(
                    error,
                    style: const TextStyle(
                      color: AppTheme.danger,
                      fontSize: 12,
                    ),
                  ),
                ),
            ],
          ),
          actions: [
            TextButton(
              onPressed: busy ? null : () => Navigator.pop(dialogContext),
              child: const Text('Cancelar'),
            ),
            FilledButton(
              onPressed: busy
                  ? null
                  : () async {
                      final message = controller.text.trim();
                      if (message.isEmpty) {
                        setDialogState(
                          () => error = 'Escribe el mensaje de tu solicitud.',
                        );
                        return;
                      }
                      setDialogState(() {
                        busy = true;
                        error = '';
                      });
                      final requestId = DateTime.now().microsecondsSinceEpoch
                          .toRadixString(16)
                          .padLeft(32, '0');
                      try {
                        final ok = await U.handlePush(
                          context,
                          SyncService.I.push('ws_support_submit', {
                            'request_id': requestId,
                            'message': message,
                          }),
                          'Solicitud enviada a soporte',
                        );
                        if (ok && dialogContext.mounted) {
                          Navigator.pop(dialogContext);
                        } else if (dialogContext.mounted) {
                          setDialogState(() => busy = false);
                        }
                      } catch (e) {
                        if (dialogContext.mounted) {
                          setDialogState(() {
                            busy = false;
                            error = '$e';
                          });
                        }
                      }
                    },
              child: busy
                  ? const SizedBox(
                      width: 18,
                      height: 18,
                      child: CircularProgressIndicator(
                        strokeWidth: 2,
                        color: Colors.white,
                      ),
                    )
                  : const Text('Enviar'),
            ),
          ],
        ),
      ),
    );
    controller.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final isOwner = '${AuthService.I.me?['role'] ?? ''}' == 'owner';
    final contacts = _labels.entries
        .where((e) => '${_links[e.key] ?? ''}'.trim().isNotEmpty)
        .toList();

    if (!isOwner) {
      return const Center(
        child: Text('Esta sección está disponible para el dueño del negocio.'),
      );
    }
    // Se incrusta en el ShellScreen (sin Scaffold propio): así mantiene el
    // navbar común, el título "Ayuda y soporte" y el estilo del resto.
    return _loading
        ? const Center(child: CircularProgressIndicator())
        : RefreshIndicator(
            onRefresh: _load,
            child: ListView(
              padding: const EdgeInsets.fromLTRB(16, 14, 16, 28),
              children: [
                  if (_error.isNotEmpty)
                    Card(
                      child: ListTile(
                        leading: const Icon(
                          Icons.cloud_off,
                          color: AppTheme.amber,
                        ),
                        title: const Text('Contactos no actualizados'),
                        subtitle: Text(_error),
                        trailing: IconButton(
                          onPressed: _load,
                          icon: const Icon(Icons.refresh),
                        ),
                      ),
                    ),
                  Card(
                    child: Padding(
                      padding: const EdgeInsets.all(16),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          const Text(
                            'Canales de contacto',
                            style: TextStyle(
                              fontSize: 16,
                              fontWeight: FontWeight.w700,
                            ),
                          ),
                          const SizedBox(height: 4),
                          Text(
                            'Escríbenos por el canal que prefieras; respondemos en horario de atención.',
                            style: TextStyle(
                              color: Colors.grey[600],
                              fontSize: 13,
                            ),
                          ),
                          const SizedBox(height: 8),
                          if (contacts.isEmpty)
                            const Text('No hay canales configurados.')
                          else
                            ...contacts.map(
                              (contact) => ListTile(
                                contentPadding: EdgeInsets.zero,
                                leading: const Icon(
                                  Icons.open_in_new,
                                  color: AppTheme.primary,
                                ),
                                title: Text(_labels[contact.key]!),
                                subtitle: Text(
                                  '${_links[contact.key] ?? ''}'.trim(),
                                  maxLines: 1,
                                  overflow: TextOverflow.ellipsis,
                                ),
                                trailing: const Icon(Icons.chevron_right),
                                onTap: () => _openLink(
                                  contact.value,
                                  '${_links[contact.key] ?? ''}'.trim(),
                                ),
                              ),
                            ),
                        ],
                      ),
                    ),
                  ),
                  const SizedBox(height: 10),
                  Card(
                    child: Padding(
                      padding: const EdgeInsets.all(16),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          const Text(
                            'Enviar una inquietud',
                            style: TextStyle(
                              fontSize: 16,
                              fontWeight: FontWeight.w700,
                            ),
                          ),
                          const SizedBox(height: 4),
                          Text(
                            'Cuéntanos tu inquietud y te responderemos por email. Si estás sin conexión, la solicitud se guardará en el dispositivo y se enviará al reconectarse.',
                            style: TextStyle(
                              color: Colors.grey[600],
                              fontSize: 13,
                            ),
                          ),
                          const SizedBox(height: 10),
                          SizedBox(
                            width: double.infinity,
                            child: FilledButton.icon(
                              onPressed: _sendRequest,
                              icon: const Icon(Icons.send_outlined),
                              label: const Text('Escribir a soporte'),
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                ],
              ),
    );
  }
}
