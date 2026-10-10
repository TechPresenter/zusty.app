import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/network/api_failure.dart';
import '../../providers/safety_provider.dart';
import '../../shared/widgets/states.dart';
import '../../shared/widgets/z_dialogs.dart';

/// What blocking does, said before anyone commits to it. Kept to what the
/// server enforces (BlockService): nothing new can start between the two,
/// and a booking that is already paid for can still finish.
String blockExplanation(String name) =>
    '$name won\'t be able to message you or send you a booking request, an '
    'offer, a campaign invitation or an application, and you won\'t see each '
    'other in search. Your chat with them closes. A booking that is already '
    'paid for can still be completed or disputed. We don\'t tell them, and '
    'you can unblock them any time in Settings.';

/// Asks before blocking [name], then blocks. Resolves true once the account
/// is blocked; false when the user changed their mind or the server refused,
/// which is said in a snackbar.
Future<bool> confirmBlock(
  BuildContext context,
  WidgetRef ref, {
  required int userId,
  required String name,
}) async {
  final confirmed = await showZConfirm(
    context,
    title: 'Block $name?',
    message: blockExplanation(name),
    confirmLabel: 'Block',
    cancelLabel: 'Cancel',
    destructive: true,
    icon: Icons.block_rounded,
  );

  if (!confirmed || !context.mounted) return false;

  try {
    final message = await ref.read(safetyActionsProvider).block(userId);
    if (context.mounted) showZSnack(context, message);
    return true;
  } on ApiFailure catch (e) {
    if (context.mounted) showZSnack(context, e.message, isError: true);
    return false;
  }
}

/// Asks before unblocking [name], then unblocks. Resolves true once done.
Future<bool> confirmUnblock(
  BuildContext context,
  WidgetRef ref, {
  required int userId,
  required String name,
}) async {
  final confirmed = await showZConfirm(
    context,
    title: 'Unblock $name?',
    message:
        'You will be able to message each other and work together again. '
        'Follows and saved creators the block removed do not come back.',
    confirmLabel: 'Unblock',
    cancelLabel: 'Cancel',
    icon: Icons.lock_open_rounded,
  );

  if (!confirmed || !context.mounted) return false;

  try {
    final message = await ref.read(safetyActionsProvider).unblock(userId);
    if (context.mounted) showZSnack(context, message);
    return true;
  } on ApiFailure catch (e) {
    if (context.mounted) showZSnack(context, e.message, isError: true);
    return false;
  }
}
