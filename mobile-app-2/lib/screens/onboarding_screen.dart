import 'package:flutter/material.dart';
import '../theme/app_theme.dart';
import '../services/tutorial_service.dart';

/// Bienvenida + recorrido guiado de la app, réplica del tutorial web:
/// • Bienvenida de primer uso tras registrarse (felicitación + botón de tour).
/// • Guía por secciones con pasos y recorrido guiado (spotlight centrado).
class OnboardingScreen extends StatefulWidget {
  final bool autoWelcome;
  const OnboardingScreen({super.key, this.autoWelcome = false});

  /// Abre la guía en modo lista (desde Mi cuenta).
  static Future<void> open(BuildContext context) {
    return Navigator.of(context).push(MaterialPageRoute(
      fullscreenDialog: true,
      builder: (_) => const OnboardingScreen(),
    ));
  }

  @override
  State<OnboardingScreen> createState() => _OnboardingScreenState();
}

class _OnboardingScreenState extends State<OnboardingScreen> {
  bool _welcome = false;
  TutorialSection? _section;
  List<TutorialStep> _tour = [];
  int _tourIndex = -1;

  @override
  void initState() {
    super.initState();
    _welcome = widget.autoWelcome;
    if (widget.autoWelcome) {
      TutorialService.I.consumeWelcome();
    }
  }

  void _close() => Navigator.of(context).pop();

  void _openSection(TutorialSection s) {
    setState(() {
      _section = s;
      _tour = [];
      _tourIndex = -1;
    });
  }

  void _startTour() {
    final sec = _section;
    if (sec == null || sec.steps.isEmpty) return;
    setState(() {
      _tour = List.from(sec.steps);
      _tourIndex = 0;
    });
  }

  void _tourNext() {
    if (_tourIndex + 1 >= _tour.length) {
      setState(() {
        _tour = [];
        _tourIndex = -1;
      });
      return;
    }
    setState(() => _tourIndex++);
  }

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;

    if (_welcome) return _buildWelcome(isDark);
    if (_section == null) return _buildList(isDark);
    if (_tourIndex >= 0) return _buildTour(isDark);
    return _buildSteps(isDark);
  }

  // ---------------- Bienvenida (primer uso tras registrarse) ----------------

  Widget _buildWelcome(bool isDark) {
    return Scaffold(
      backgroundColor: isDark ? AppTheme.darkBg : const Color(0xFF171B3A),
      body: Container(
        decoration: const BoxDecoration(
          gradient: LinearGradient(
            colors: [Color(0xFF171B3A), Color(0xFF242A58)],
            begin: Alignment.topLeft,
            end: Alignment.bottomRight,
          ),
        ),
        child: SafeArea(
          child: Center(
            child: SingleChildScrollView(
              padding: const EdgeInsets.all(28),
              child: Column(mainAxisSize: MainAxisSize.min, children: [
                Container(
                  padding: const EdgeInsets.all(22),
                  decoration: BoxDecoration(
                    color: Colors.white.withAlpha(25),
                    shape: BoxShape.circle,
                  ),
                  child: const Icon(Icons.celebration_outlined,
                      size: 56, color: Colors.white),
                ),
                const SizedBox(height: 22),
                const Text('¡Tu negocio está listo!',
                    textAlign: TextAlign.center,
                    style: TextStyle(
                        color: Colors.white,
                        fontSize: 24,
                        fontWeight: FontWeight.w800)),
                const SizedBox(height: 10),
                Text(
                    'Registraste tu negocio y tus primeros días de prueba van en marcha. Te mostramos lo esencial de la app en un recorrido rápido.',
                    textAlign: TextAlign.center,
                    style: TextStyle(
                        color: Colors.white.withAlpha(200), fontSize: 14, height: 1.4)),
                const SizedBox(height: 28),
                FilledButton.icon(
                  style: FilledButton.styleFrom(
                    backgroundColor: Colors.white,
                    foregroundColor: const Color(0xFF171B3A),
                    minimumSize: const Size(200, 48),
                  ),
                  onPressed: () => setState(() {
                    _welcome = false;
                    _section = tutorialSections.first;
                  }),
                  icon: const Icon(Icons.map_outlined, size: 18),
                  label: const Text('Hacer el tour'),
                ),
                const SizedBox(height: 10),
                TextButton(
                  onPressed: _close,
                  child: Text('Entrar a mi panel',
                      style: TextStyle(color: Colors.white.withAlpha(180))),
                ),
              ]),
            ),
          ),
        ),
      ),
    );
  }

  // ---------------- Lista de secciones ----------------

  Widget _buildList(bool isDark) {
    return Scaffold(
      backgroundColor: isDark ? AppTheme.darkBg : const Color(0xFFF6F7FB),
      appBar: AppBar(title: const Text('Bienvenida y tour')),
      body: ListView.builder(
        padding: const EdgeInsets.fromLTRB(14, 8, 14, 24),
        itemCount: tutorialSections.length,
        itemBuilder: (context, i) {
          final s = tutorialSections[i];
          return Card(
            margin: const EdgeInsets.only(bottom: 10),
            child: ListTile(
              leading: Container(
                padding: const EdgeInsets.all(8),
                decoration: BoxDecoration(
                  color: AppTheme.primary.withAlpha(20),
                  borderRadius: BorderRadius.circular(10),
                ),
                child: Icon(s.icon, color: AppTheme.primary, size: 20),
              ),
              title: Text(s.title,
                  style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 14)),
              subtitle: Text(s.description,
                  style: TextStyle(color: Colors.grey[600], fontSize: 12)),
              trailing: const Icon(Icons.chevron_right),
              onTap: () => _openSection(s),
            ),
          );
        },
      ),
    );
  }

  // ---------------- Pasos de una sección ----------------

  Widget _buildSteps(bool isDark) {
    final sec = _section!;
    return Scaffold(
      backgroundColor: isDark ? AppTheme.darkBg : const Color(0xFFF6F7FB),
      appBar: AppBar(title: Text(sec.title)),
      body: ListView(
        padding: const EdgeInsets.all(14),
        children: [
          Row(children: [
            Icon(sec.icon, color: AppTheme.primary, size: 22),
            const SizedBox(width: 10),
            Expanded(
              child: Text(sec.description,
                  style: TextStyle(color: Colors.grey[600], fontSize: 13, height: 1.35)),
            ),
          ]),
          const SizedBox(height: 16),
          for (var i = 0; i < sec.steps.length; i++)
            _stepCard(i + 1, sec.steps[i], isDark),
          const SizedBox(height: 8),
          FilledButton.icon(
            onPressed: _startTour,
            icon: const Icon(Icons.tour_outlined, size: 18),
            label: const Text('Iniciar recorrido guiado'),
          ),
        ],
      ),
    );
  }

  Widget _stepCard(int n, TutorialStep s, bool isDark) {
    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: isDark ? AppTheme.darkCard : Colors.white,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: isDark ? AppTheme.darkBorder : AppTheme.lightBorder),
      ),
      child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
        CircleAvatar(
          radius: 13,
          backgroundColor: AppTheme.primary.withAlpha(25),
          child: Text('$n',
              style: const TextStyle(
                  fontSize: 12, fontWeight: FontWeight.w800, color: AppTheme.primary)),
        ),
        const SizedBox(width: 12),
        Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(s.title,
              style: const TextStyle(fontSize: 13.5, fontWeight: FontWeight.w700)),
          const SizedBox(height: 3),
          Text(s.text,
              style: TextStyle(fontSize: 12.5, color: Colors.grey[600], height: 1.35)),
        ])),
      ]),
    );
  }

  // ---------------- Recorrido guiado (spotlight) ----------------

  Widget _buildTour(bool isDark) {
    final sec = _section!;
    final step = _tour[_tourIndex];
    final last = _tourIndex == _tour.length - 1;
    return Scaffold(
      backgroundColor: isDark ? AppTheme.darkBg : const Color(0xFF171B3A),
      appBar: AppBar(
        title: Text('Tour · ${sec.title}'),
        backgroundColor: Colors.transparent,
      ),
      body: Padding(
        padding: const EdgeInsets.all(20),
        child: Column(children: [
          const SizedBox(height: 8),
          Text('Paso ${_tourIndex + 1} de ${_tour.length}',
              style: TextStyle(color: Colors.white.withAlpha(150), fontSize: 12)),
          const SizedBox(height: 18),
          Container(
            width: double.infinity,
            padding: const EdgeInsets.all(22),
            decoration: BoxDecoration(
              color: isDark ? AppTheme.darkCard : Colors.white,
              borderRadius: BorderRadius.circular(16),
              boxShadow: [
                BoxShadow(
                    color: Colors.black.withAlpha(60),
                    blurRadius: 28,
                    offset: const Offset(0, 10)),
              ],
            ),
            child: Column(children: [
              Icon(sec.icon, size: 34, color: AppTheme.primary),
              const SizedBox(height: 12),
              Text(step.title,
                  textAlign: TextAlign.center,
                  style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w800)),
              const SizedBox(height: 8),
              Text(step.text,
                  textAlign: TextAlign.center,
                  style: TextStyle(fontSize: 13.5, color: Colors.grey[600], height: 1.4)),
            ]),
          ),
          const Spacer(),
          Row(children: [
            TextButton(
              onPressed: () => setState(() {
                _tour = [];
                _tourIndex = -1;
              }),
              child: const Text('Salir'),
            ),
            const Spacer(),
            TextButton(
              onPressed: last ? null : _tourNext,
              child: const Text('Omitir'),
            ),
            const SizedBox(width: 6),
            FilledButton(
              onPressed: _tourNext,
              child: Text(last ? 'Terminar' : 'Siguiente'),
            ),
          ]),
        ]),
      ),
    );
  }
}
