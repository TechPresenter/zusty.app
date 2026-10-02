import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:image_picker/image_picker.dart' show XFile;

import '../core/config/app_config.dart';
import '../models/message.dart';
import 'auth_provider.dart';
import 'core_providers.dart';

/// Search term on the Messages list.
final conversationQueryProvider = StateProvider.autoDispose<String>((_) => '');

final conversationsProvider = FutureProvider.autoDispose<List<Conversation>>((
  ref,
) {
  final query = ref.watch(conversationQueryProvider);
  return ref
      .watch(messageRepositoryProvider)
      .conversations(query: query.isEmpty ? null : query);
});

/// A live message thread.
///
/// The backend exposes polling endpoints rather than sockets (see the TRD), so
/// this notifier re-reads the thread on a timer while the screen is mounted and
/// appends optimistically on send.
class ThreadController
    extends AutoDisposeFamilyAsyncNotifier<List<Message>, int> {
  Timer? _poll;

  @override
  Future<List<Message>> build(int conversationId) async {
    ref.onDispose(() => _poll?.cancel());

    _poll = Timer.periodic(AppConfig.chatPollInterval, (_) => _refresh());

    final messages = await _load();
    unawaited(ref.read(messageRepositoryProvider).markRead(conversationId));
    return messages;
  }

  Future<List<Message>> _load() async {
    final viewerId = ref.read(currentUserProvider)?.id;
    final snapshot = await ref
        .read(messageRepositoryProvider)
        .thread(arg, viewerId: viewerId);

    // The other party's typing state rides on the same poll as the messages.
    ref.read(typingProvider(arg).notifier).state = snapshot.othersTyping;

    // The server pages history newest-first (so "load older" can walk back
    // from a cursor); a thread reads oldest-first. Without this the newest
    // message sat at the top of the screen and day dividers ran backwards.
    // Ids are assigned in send order, so they sort chronologically even when
    // two messages share a timestamp.
    return [...snapshot.messages]..sort((a, b) => a.id.compareTo(b.id));
  }

  Future<void> _refresh() async {
    // Never clobber a message that is still in flight.
    final current = state.valueOrNull ?? const [];
    if (current.any((m) => m.isSending)) return;

    try {
      // A message that failed to send exists only here. Keeping it at the
      // end means a poll cannot quietly delete what the person typed before
      // they have had the chance to retry it.
      final fresh = [...await _load(), ...current.where((m) => m.failed)];
      if (_changed(current, fresh)) state = AsyncData(fresh);
    } catch (_) {
      // A failed poll is not worth surfacing; the next tick retries.
    }
  }

  /// A new message, or a read receipt arriving on one already shown.
  ///
  /// Comparing lengths alone meant "seen" ticks never turned over while the
  /// thread was open: the other side reading a message changes no count.
  static bool _changed(List<Message> current, List<Message> fresh) {
    if (fresh.length != current.length) return true;
    for (var i = 0; i < fresh.length; i++) {
      if (fresh[i].id != current[i].id ||
          fresh[i].isRead != current[i].isRead) {
        return true;
      }
    }
    return false;
  }

  /// Appends the message locally, then reconciles with the server response.
  Future<void> send(
    String body, {
    String type = 'text',
    String? mediaUrl,
  }) async {
    final trimmed = body.trim();
    if (trimmed.isEmpty && mediaUrl == null) return;

    final pending = Message(
      id: -DateTime.now().millisecondsSinceEpoch,
      type: MessageType.fromString(type),
      body: trimmed,
      mediaUrl: mediaUrl,
      isMine: true,
      isSending: true,
      createdAt: DateTime.now(),
    );

    final current = state.valueOrNull ?? const <Message>[];
    state = AsyncData([...current, pending]);

    try {
      final repository = ref.read(messageRepositoryProvider);

      // A picked photo is still a file on this device (a blob URL on web), so
      // it is uploaded and the server answers with the hosted URL. Checked here
      // rather than at the picker so a retried photo uploads too.
      final Message sent;

      if (mediaUrl != null && !_isHosted(mediaUrl)) {
        final file = XFile(mediaUrl);
        final name = file.name.contains('.') ? file.name : 'photo.jpg';

        sent = await repository.sendImage(
          arg,
          bytes: await file.readAsBytes(),
          fileName: name,
          body: trimmed,
        );
      } else {
        sent = await repository.send(
          arg,
          body: trimmed,
          type: type,
          mediaUrl: mediaUrl,
        );
      }
      state = AsyncData([...current, sent.copyWith(isSending: false)]);
      ref.invalidate(conversationsProvider);
    } catch (_) {
      state = AsyncData([
        ...current,
        pending.copyWith(isSending: false, failed: true),
      ]);
    }
  }

  static bool _isHosted(String url) {
    final scheme = Uri.tryParse(url)?.scheme;
    return scheme == 'http' || scheme == 'https';
  }

  Future<void> retry(Message failed) async {
    final current = (state.valueOrNull ?? const <Message>[])
        .where((m) => m.id != failed.id)
        .toList();
    state = AsyncData(current);
    await send(failed.body, type: failed.type.name, mediaUrl: failed.mediaUrl);
  }

  Future<void> reload() async {
    state = const AsyncLoading();
    state = await AsyncValue.guard(_load);
  }
}

final threadProvider = AsyncNotifierProvider.autoDispose
    .family<ThreadController, List<Message>, int>(ThreadController.new);

/// Whether the other party is typing — polled alongside the thread.
final typingProvider = StateProvider.autoDispose.family<bool, int>(
  (_, _) => false,
);
