import 'dart:async';
import 'dart:convert';
import 'dart:io';

class RadoRealtimeEvent {
  const RadoRealtimeEvent({
    required this.name,
    required this.data,
    this.id,
  });

  final String name;
  final Map<String, dynamic> data;
  final int? id;
}

class RadoRealtimeStream {
  RadoRealtimeStream(String baseUrl)
      : _baseUrl = baseUrl.endsWith('/')
            ? baseUrl.substring(0, baseUrl.length - 1)
            : baseUrl;

  final String _baseUrl;
  HttpClient? _client;
  bool _closed = false;
  int _lastEventId = 0;

  bool get closed => _closed;
  int get lastEventId => _lastEventId;

  Future<void> listen({
    required Map<String, String> query,
    required FutureOr<void> Function(RadoRealtimeEvent event) onEvent,
  }) async {
    var backoffSeconds = 1;
    while (!_closed) {
      try {
        final client = _client ??= HttpClient()
          ..connectionTimeout = const Duration(seconds: 8);
        final params = <String, String>{
          ...query,
          'stream': '1',
          if (_lastEventId > 0) 'since_event_id': '$_lastEventId',
        };
        final uri = Uri.parse('$_baseUrl/api/v1/realtime/').replace(
          queryParameters: params,
        );
        final request = await client.getUrl(uri);
        request.headers.set(HttpHeaders.acceptHeader, 'text/event-stream');
        request.headers.set(HttpHeaders.cacheControlHeader, 'no-cache');
        if (_lastEventId > 0) {
          request.headers.set('Last-Event-ID', '$_lastEventId');
        }
        final response = await request.close();
        if (response.statusCode != HttpStatus.ok) {
          await response.drain<void>();
          throw HttpException(
            'RADO realtime HTTP ${response.statusCode}',
            uri: uri,
          );
        }

        backoffSeconds = 1;
        var eventName = 'message';
        int? eventId;
        final data = StringBuffer();

        Future<void> dispatch() async {
          final raw = data.toString().trim();
          if (eventId != null && eventId! > _lastEventId) {
            _lastEventId = eventId!;
          }
          if (raw.isNotEmpty) {
            try {
              final decoded = jsonDecode(raw);
              await onEvent(
                RadoRealtimeEvent(
                  name: eventName,
                  id: eventId,
                  data: decoded is Map
                      ? decoded.cast<String, dynamic>()
                      : <String, dynamic>{'value': decoded},
                ),
              );
            } catch (_) {
              // A malformed event must not terminate the live connection.
            }
          }
          eventName = 'message';
          eventId = null;
          data.clear();
        }

        await for (final line in response
            .transform(utf8.decoder)
            .transform(const LineSplitter())) {
          if (_closed) break;
          if (line.isEmpty) {
            await dispatch();
            continue;
          }
          if (line.startsWith(':')) continue;
          if (line.startsWith('event:')) {
            eventName = line.substring(6).trim();
          } else if (line.startsWith('id:')) {
            eventId = int.tryParse(line.substring(3).trim());
          } else if (line.startsWith('data:')) {
            if (data.isNotEmpty) data.write('\n');
            data.write(line.substring(5).trimLeft());
          }
        }
      } catch (_) {
        // Reconnect below. Slow polling remains the safety fallback.
      }

      if (_closed) break;
      await Future<void>.delayed(Duration(seconds: backoffSeconds));
      backoffSeconds = (backoffSeconds * 2).clamp(1, 8);
    }
  }

  void close() {
    _closed = true;
    _client?.close(force: true);
    _client = null;
  }
}
