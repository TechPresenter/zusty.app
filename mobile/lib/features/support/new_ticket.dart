import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/network/api_failure.dart';
import '../../core/theme/app_theme.dart';
import '../../core/utils/validators.dart';
import '../../models/support.dart';
import '../../models/taxonomy.dart';
import '../../providers/account_provider.dart';
import '../../providers/core_providers.dart';
import '../../providers/taxonomy_provider.dart';
import '../../router/routes.dart';
import '../../shared/widgets/states.dart';
import '../../shared/widgets/status_chip.dart';
import '../../shared/widgets/z_button.dart';
import '../../shared/widgets/z_dialogs.dart';
import '../../shared/widgets/z_scaffold.dart';
import '../../shared/widgets/z_text_field.dart';

/// Raise a new support ticket (`POST /support/tickets`) from a bottom sheet.
///
/// On success the sheet closes and the new ticket's thread opens, which is
/// both the confirmation and the place the reply will arrive.
abstract final class NewTicketSheet {
  static Future<void> show(BuildContext context, {int? bookingId}) async {
    final ticket = await showZSheet<SupportTicket>(
      context,
      title: 'New support ticket',
      subtitle: 'Tell us what happened and we will reply in the ticket.',
      builder: (sheetContext) => NewTicketForm(
        bookingId: bookingId,
        onCreated: (ticket) => Navigator.of(sheetContext).pop(ticket),
      ),
    );

    if (ticket != null && context.mounted) {
      _confirm(context, ticket);
      context.push(Routes.ticket(ticket.id));
    }
  }
}

/// The same form as a full page.
///
/// `/support/tickets/new` is a registered destination an admin menu entry can
/// point at. Until the router has a route of its own for it, the path matches
/// `tickets/:id` and reaches [TicketChatScreen] with no id, which renders this
/// instead of an empty thread.
class NewTicketScreen extends StatelessWidget {
  const NewTicketScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return ZScaffold(
      appBar: const ZAppBar(title: 'New support ticket'),
      padded: false,
      body: SingleChildScrollView(
        keyboardDismissBehavior: ScrollViewKeyboardDismissBehavior.onDrag,
        padding: EdgeInsets.fromLTRB(
          AppBreakpoints.pageMargin(context),
          AppSpacing.md,
          AppBreakpoints.pageMargin(context),
          AppSpacing.xl,
        ),
        child: NewTicketForm(
          onCreated: (ticket) {
            _confirm(context, ticket);
            context.pushReplacement(Routes.ticket(ticket.id));
          },
        ),
      ),
    );
  }
}

void _confirm(BuildContext context, SupportTicket ticket) {
  showZSnack(
    context,
    ticket.reference.isEmpty
        ? 'Ticket opened. We usually reply within a few hours.'
        : 'Ticket ${ticket.reference} opened. We usually reply within a few '
              'hours.',
  );
}

/// Category, subject and description, with every error shown on the field it
/// concerns — the server's included, so an unknown category (`errors.category`)
/// is flagged on the category row rather than in a toast.
class NewTicketForm extends ConsumerStatefulWidget {
  const NewTicketForm({super.key, required this.onCreated, this.bookingId});

  final ValueChanged<SupportTicket> onCreated;

  /// Links the ticket to a booking when it was raised from one.
  final int? bookingId;

  @override
  ConsumerState<NewTicketForm> createState() => _NewTicketFormState();
}

class _NewTicketFormState extends ConsumerState<NewTicketForm> {
  final _formKey = GlobalKey<FormState>();
  final _subject = TextEditingController();
  final _message = TextEditingController();

  String? _category;
  bool _busy = false;
  String? _formError;
  Map<String, List<String>> _fieldErrors = const {};

  @override
  void dispose() {
    _subject.dispose();
    _message.dispose();
    super.dispose();
  }

  Future<void> _submit(String category) async {
    FocusScope.of(context).unfocus();
    setState(() {
      _fieldErrors = const {};
      _formError = null;
    });
    if (!_formKey.currentState!.validate()) return;

    setState(() => _busy = true);
    try {
      final ticket = await ref
          .read(accountRepositoryProvider)
          .createTicket(
            subject: _subject.text.trim(),
            category: category,
            message: _message.text.trim(),
            bookingId: widget.bookingId,
          );
      ref.invalidate(ticketsProvider);

      if (mounted) widget.onCreated(ticket);
    } on ValidationFailure catch (e) {
      if (!mounted) return;
      setState(() {
        _fieldErrors = e.errors;
        const known = {'category', 'subject', 'message'};
        _formError = e.errors.keys.any(known.contains) ? null : e.message;
      });
    } on ApiFailure catch (e) {
      if (mounted) setState(() => _formError = e.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  String? _error(String field) => _fieldErrors[field]?.first;

  void _clear(String field) {
    if (_fieldErrors.containsKey(field)) {
      setState(() => _fieldErrors = {..._fieldErrors}..remove(field));
    }
  }

  @override
  Widget build(BuildContext context) {
    // Admin → Taxonomies → Ticket categories. The server accepts exactly this
    // list, so the picker can never offer something it would refuse.
    final categories = ref.watch(termMapProvider(Tax.ticketCategory));
    final category =
        _category ??
        (categories.containsKey('general')
            ? 'general'
            : (categories.isEmpty ? 'general' : categories.keys.first));
    final categoryError = _error('category');

    return Form(
      key: _formKey,
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(
            'What is it about?',
            style: AppTypography.labelLarge.copyWith(fontSize: 13),
          ),
          const SizedBox(height: AppSpacing.xs),
          Wrap(
            spacing: AppSpacing.xs,
            runSpacing: AppSpacing.xs,
            children: [
              for (final entry in categories.entries)
                ZChip(
                  label: entry.value,
                  selected: category == entry.key,
                  onTap: () {
                    _clear('category');
                    setState(() => _category = entry.key);
                  },
                ),
            ],
          ),
          if (categoryError != null) ...[
            const SizedBox(height: AppSpacing.xs),
            Text(
              categoryError,
              style: AppTypography.bodySmall.copyWith(color: AppColors.error),
            ),
          ],
          const SizedBox(height: AppSpacing.md),
          ZTextField(
            label: 'Subject',
            hint: 'e.g. Payout for ZB2400452 not received',
            controller: _subject,
            textCapitalization: TextCapitalization.sentences,
            textInputAction: TextInputAction.next,
            maxLength: 191,
            validator: (value) =>
                Validators.minLength(value, 5, field: 'Subject'),
            errorText: _error('subject'),
            onChanged: (_) => _clear('subject'),
          ),
          const SizedBox(height: AppSpacing.md),
          ZTextField(
            label: 'Describe the issue',
            hint: 'What happened, and what did you expect?',
            controller: _message,
            maxLines: 6,
            minLines: 4,
            maxLength: 5000,
            textCapitalization: TextCapitalization.sentences,
            helper: 'Booking IDs, amounts and dates help us answer faster.',
            validator: (value) =>
                Validators.minLength(value, 20, field: 'Description'),
            errorText: _error('message'),
            onChanged: (_) => _clear('message'),
          ),
          if (_formError != null) ...[
            const SizedBox(height: AppSpacing.md),
            Text(
              _formError!,
              style: AppTypography.bodySmall.copyWith(color: AppColors.error),
            ),
          ],
          const SizedBox(height: AppSpacing.lg),
          ZButton(
            label: 'Submit ticket',
            icon: Icons.send_rounded,
            isLoading: _busy,
            onPressed: () => _submit(category),
          ),
          const SizedBox(height: AppSpacing.xs),
          Row(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Icon(
                Icons.schedule_rounded,
                size: 14,
                color: AppColors.onSurfaceVariant,
              ),
              const SizedBox(width: 4),
              Flexible(
                child: Text(
                  'Most tickets get a first reply within a few hours.',
                  textAlign: TextAlign.center,
                  style: AppTypography.bodySmall,
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }
}
