import 'dart:typed_data';

import '../../core/network/api_endpoints.dart';
import '../../models/message.dart';
import 'base_repository.dart';

/// One read of a thread: its messages, whether anyone else in it is typing,
/// whether the caller may still post in it, and who it is with (the same
/// shape as the inbox row, null from a server that predates it).
typedef ThreadSnapshot = ({
  List<Message> messages,
  bool othersTyping,
  bool canMessage,
  Conversation? conversation,
});

/// Real-time chat between a brand and a creator (`/conversations/*`).
class MessageRepository extends BaseRepository {
  MessageRepository(super.client);

  Future<List<Conversation>> conversations({String? query}) => readThrough(
    () async {
      final response = await client.get(
        ApiEndpoints.conversations,
        query: {'q': query},
      );
      final rows = response.listAt('conversations').isNotEmpty
          ? response.listAt('conversations')
          : response.dataList;
      return rows.map(Conversation.fromJson).toList();
    },
    'conversations',
    (json) {
      var rows = asRows(json).map(Conversation.fromJson).toList();
      if (query != null && query.trim().isNotEmpty) {
        final lower = query.toLowerCase();
        rows = rows
            .where(
              (c) =>
                  c.otherName.toLowerCase().contains(lower) ||
                  c.lastMessage.toLowerCase().contains(lower),
            )
            .toList();
      }
      return rows;
    },
  );

  Future<List<Message>> messages(int conversationId, {int? viewerId}) async =>
      (await thread(conversationId, viewerId: viewerId)).messages;

  /// The thread, whether anyone else in it is typing, whether the caller may
  /// still post in it, and who it is with. All of it arrives on the same
  /// response, so the typing indicator, the blocked notice and the chat
  /// header cost no extra request.
  ///
  /// `canMessage` is false once a block stands between the two people in
  /// the thread; a server that predates blocking sends nothing, read as true.
  /// `conversation` keeps the chat's header and menu for a thread the inbox
  /// leaves out, such as one with someone the user blocked.
  Future<ThreadSnapshot> thread(int conversationId, {int? viewerId}) =>
      readThrough(
        () async {
          final response = await client.get(
            ApiEndpoints.messages(conversationId),
          );
          final rows = response.listAt('messages').isNotEmpty
              ? response.listAt('messages')
              : response.dataList;
          final conversation = response.maybeMapAt('conversation');
          return (
            messages: rows
                .map((row) => Message.fromJson(row, viewerId: viewerId))
                .toList(),
            othersTyping: response.listAt('typing').isNotEmpty,
            canMessage: response.dataMap['can_message'] != false,
            conversation: conversation == null
                ? null
                : Conversation.fromJson(conversation),
          );
        },
        'messages:$conversationId',
        (json) => (
          messages: asRows(
            json,
          ).map((row) => Message.fromJson(row, viewerId: viewerId)).toList(),
          othersTyping: false,
          canMessage: true,
          conversation: null,
        ),
      );

  Future<Message> send(
    int conversationId, {
    required String body,
    String type = 'text',
    String? mediaUrl,
    String? fileName,
  }) => writeThrough(
    () async {
      final response = await client.post(
        ApiEndpoints.messages(conversationId),
        body: {
          'type': type,
          'body': body,
          'media_url': ?mediaUrl,
          'file_name': ?fileName,
        },
      );
      final data = response.maybeMapAt('message') ?? response.dataMap;
      return Message.fromJson(data);
    },
    // Echoed straight back from what was typed — a fixture would only
    // overwrite the user's own words.
    'noop',
    (_) => Message(
      id: DateTime.now().millisecondsSinceEpoch % 100000,
      type: MessageType.fromString(type),
      body: body,
      mediaUrl: mediaUrl,
      fileName: fileName,
      isMine: true,
      isRead: false,
      createdAt: DateTime.now(),
    ),
  );

  /// Sends a photo as a multipart `image`, which the server stores and answers
  /// with the hosted URL. A picked file's path must never go out as
  /// `media_url`: the other participant cannot open a file on this device.
  Future<Message> sendImage(
    int conversationId, {
    required Uint8List bytes,
    required String fileName,
    String body = '',
  }) async {
    final response = await client.uploadBytes(
      ApiEndpoints.messages(conversationId),
      field: 'image',
      bytes: bytes,
      fileName: fileName,
      fields: {'type': 'image', 'body': body},
    );
    final data = response.maybeMapAt('message') ?? response.dataMap;
    return Message.fromJson(data);
  }

  Future<void> markRead(int conversationId) => writeThrough(
    () async {
      await client.post(ApiEndpoints.markConversationRead(conversationId));
    },
    'noop',
    (_) {},
  );

  Future<void> setTyping(int conversationId) => writeThrough(
    () async {
      await client.post(ApiEndpoints.typing(conversationId));
    },
    'noop',
    (_) {},
  );

  Future<void> mute(int conversationId, {required bool muted}) => writeThrough(
    () async {
      // The endpoint reads `is_muted`. Sending `muted` left the field
      // absent, and the server's own default muted the thread — so an
      // unmute muted it again and nothing could ever be unmuted.
      await client.post(
        ApiEndpoints.muteConversation(conversationId),
        body: {'is_muted': muted},
      );
    },
    'noop',
    (_) {},
  );

  /// Opens (or reuses) a thread with a creator, optionally scoped to a booking.
  Future<int> start({required int creatorId, int? bookingId}) => writeThrough(
    () async {
      final response = await client.post(
        ApiEndpoints.startConversation,
        body: {'creator_id': creatorId, 'booking_id': ?bookingId},
      );
      final data = response.maybeMapAt('conversation') ?? response.dataMap;
      // The endpoint answers {"conversation_id": N}; `id` is only there
      // when the reply is wrapped as {"conversation": {...}}.
      final id = int.tryParse('${data['conversation_id'] ?? data['id']}');

      // A creator id is not a conversation id. Falling back to one sent
      // the chat screen to /conversations/<creatorId>/messages, which is
      // someone else's thread or none at all — a 403 either way. If the
      // server did not return an id, that is a real failure and the caller
      // needs to know.
      if (id == null || id <= 0) {
        throw StateError('conversations/start returned no conversation id');
      }

      return id;
    },
    'noop',
    (_) => 0,
  );
}
