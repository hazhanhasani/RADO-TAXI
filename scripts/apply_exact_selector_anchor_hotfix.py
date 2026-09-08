from pathlib import Path

path = Path('apps/passenger/lib/runtime_passenger.dart')
text = path.read_text()
if "import 'dart:ui' as ui;" not in text:
    text = text.replace("import 'dart:math';\n", "import 'dart:math';\nimport 'dart:ui' as ui;\n", 1)
old = '''class _SelectionPin extends StatelessWidget {
  const _SelectionPin({required this.destination});
  final bool destination;

  @override
  Widget build(BuildContext context) {
    final color = destination ? Colors.red : Colors.green;
    final icon = destination
        ? Icons.location_on_rounded
        : Icons.radio_button_checked_rounded;
    final label = destination ? 'مقصد' : 'مبدا';
    // The selector is about 108 px tall. Shifting by half its height
    // places the very bottom anchor dot exactly on the map camera centre.
    return Transform.translate(
      offset: const Offset(0, -54),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 11, vertical: 5),
            decoration: BoxDecoration(
              color: _black,
              borderRadius: BorderRadius.circular(14),
            ),
            child: Text(
              label,
              style: const TextStyle(
                color: Colors.white,
                fontSize: 10,
                fontWeight: FontWeight.w900,
              ),
            ),
          ),
          const SizedBox(height: 4),
          Container(
            width: 54,
            height: 54,
            decoration: BoxDecoration(
              color: Colors.white,
              shape: BoxShape.circle,
              border: Border.all(color: color, width: 5),
              boxShadow: const [
                BoxShadow(
                  color: Colors.black26,
                  blurRadius: 9,
                  offset: Offset(0, 4),
                ),
              ],
            ),
            child: Icon(icon, color: color, size: destination ? 30 : 27),
          ),
          Container(width: 4, height: 18, color: color),
          Container(
            width: 10,
            height: 10,
            decoration: BoxDecoration(color: color, shape: BoxShape.circle),
          ),
        ],
      ),
    );
  }
}
'''
new = '''class _SelectionPin extends StatelessWidget {
  const _SelectionPin({required this.destination});
  final bool destination;

  @override
  Widget build(BuildContext context) {
    final color = destination ? Colors.red : Colors.green;
    final icon = destination
        ? Icons.location_on_rounded
        : Icons.radio_button_checked_rounded;
    final label = destination ? 'مقصد' : 'مبدا';

    // This widget itself is 0x0 and is aligned exactly to the map camera center.
    // Only the visual pin is painted above that zero anchor. Therefore the
    // triangle apex and the saved map coordinate are the exact same point,
    // with no visual correction offset and no DPI/font-size dependency.
    return SizedBox(
      width: 0,
      height: 0,
      child: Stack(
        clipBehavior: Clip.none,
        alignment: Alignment.bottomCenter,
        children: [
          Positioned(
            bottom: 0,
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                Container(
                  padding: const EdgeInsets.symmetric(
                    horizontal: 11,
                    vertical: 5,
                  ),
                  decoration: BoxDecoration(
                    color: _black,
                    borderRadius: BorderRadius.circular(14),
                  ),
                  child: Text(
                    label,
                    style: const TextStyle(
                      color: Colors.white,
                      fontSize: 10,
                      fontWeight: FontWeight.w900,
                    ),
                  ),
                ),
                const SizedBox(height: 4),
                Container(
                  width: 54,
                  height: 54,
                  decoration: BoxDecoration(
                    color: Colors.white,
                    shape: BoxShape.circle,
                    border: Border.all(color: color, width: 5),
                    boxShadow: const [
                      BoxShadow(
                        color: Colors.black26,
                        blurRadius: 9,
                        offset: Offset(0, 4),
                      ),
                    ],
                  ),
                  child: Icon(
                    icon,
                    color: color,
                    size: destination ? 30 : 27,
                  ),
                ),
                CustomPaint(
                  size: const Size(18, 22),
                  painter: _SelectionPinNeedlePainter(color),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _SelectionPinNeedlePainter extends CustomPainter {
  const _SelectionPinNeedlePainter(this.color);

  final Color color;

  @override
  void paint(Canvas canvas, Size size) {
    final path = ui.Path()
      ..moveTo(2, 0)
      ..lineTo(size.width - 2, 0)
      ..lineTo(size.width / 2, size.height)
      ..close();
    canvas.drawPath(path, Paint()..color = color);
  }

  @override
  bool shouldRepaint(covariant _SelectionPinNeedlePainter oldDelegate) =>
      oldDelegate.color != color;
}
'''
if old not in text:
    raise SystemExit('expected current selection pin block not found')
text = text.replace(old, new, 1)
path.write_text(text)
print('exact zero-anchor selector applied')
