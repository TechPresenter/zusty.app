import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/intl.dart';

import '../../core/network/api_failure.dart';
import '../../core/theme/app_theme.dart';
import '../../models/creator_fields.dart';
import '../../providers/core_providers.dart';
import '../../shared/motion/motion.dart';
import '../../shared/widgets/menu_tile.dart';
import '../../shared/widgets/states.dart';
import '../../shared/widgets/status_chip.dart';
import '../../shared/widgets/z_button.dart';
import '../../shared/widgets/z_card.dart';
import '../../shared/widgets/z_dialogs.dart';
import '../../shared/widgets/z_feedback.dart';
import '../../shared/widgets/z_scaffold.dart';
import '../../shared/widgets/z_text_field.dart';

/// Where a creator edits the parts of their profile that are lists: education,
/// certifications, awards, work history, brand collaborations, working hours
/// and FAQs.
///
/// These render on the public profile and have been fully served by the API
/// since the extended-profile work; until now the only way to change them was
/// to call the endpoints directly.
///
/// A section an administrator has locked is refused by the server with its own
/// explanation, and that message is what the screen shows — it does not
/// pre-emptively hide controls, because a lock can be applied while the screen
/// is open and a hidden button teaches nothing.
class CreatorFieldsScreen extends ConsumerWidget {
  const CreatorFieldsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final fields = ref.watch(myCreatorFieldsProvider);
    final margin = AppBreakpoints.pageMargin(context);

    return ZScaffold(
      padded: false,
      appBar: const ZAppBar(title: 'Profile details'),
      body: fields.when(
        loading: () => Padding(
          padding: EdgeInsets.all(margin),
          child: const ListSkeleton(count: 4, height: 110),
        ),
        error: (error, _) => ErrorState(
          error: error,
          onRetry: () => ref.invalidate(myCreatorFieldsProvider),
        ),
        data: (data) => ZRefresh(
          onRefresh: () => ref.refresh(myCreatorFieldsProvider.future),
          child: ListView(
            padding: EdgeInsets.fromLTRB(
              margin,
              AppSpacing.md,
              margin,
              AppSpacing.xxl,
            ),
            children: [
              const ZInfoBanner(
                message:
                    'Everything here shows on your public profile, so '
                    'brands can see your experience before they book.',
                icon: Icons.visibility_outlined,
              ),
              const SizedBox(height: AppSpacing.lg),
              _CredentialsSection(fields: data),
              const SizedBox(height: AppSpacing.lg),
              _ExperienceSection(fields: data),
              const SizedBox(height: AppSpacing.lg),
              _WorkingHoursSection(fields: data),
              const SizedBox(height: AppSpacing.lg),
              _FaqsSection(fields: data),
            ],
          ),
        ),
      ),
    );
  }
}

// =====================================================================
// Education, certifications and awards
// =====================================================================

class _CredentialsSection extends ConsumerWidget {
  const _CredentialsSection({required this.fields});

  final CreatorFields fields;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return _Section(
      title: 'Education & awards',
      icon: Icons.school_outlined,
      emptyHint: 'Add a degree, course, certificate or award.',
      onAdd: () => _add(context, ref),
      children: fields.credentials
          .map(
            (item) => _Row(
              title: item.title,
              subtitle: [
                item.subtitle,
                item.period,
              ].where((part) => part.isNotEmpty).join(' · '),
              badge: item.kindLabel,
              verified: item.isVerified,
              onDelete: () => _confirmDelete(
                context,
                ref,
                what: item.title,
                action: () => ref
                    .read(creatorFieldsRepositoryProvider)
                    .deleteCredential(item.id),
              ),
            ),
          )
          .toList(),
    );
  }

  Future<void> _add(BuildContext context, WidgetRef ref) async {
    final result = await _showForm(
      context,
      title: 'Add a qualification',
      fields: const [
        _FormField(key: 'title', label: 'Title', required: true),
        _FormField(key: 'issuer', label: 'Institution or issuer'),
        _FormField(key: 'field_of_study', label: 'Field of study'),
        _FormField(key: 'started_on', label: 'Started', kind: _FieldKind.date),
        _FormField(
          key: 'ended_on',
          label: 'Ended',
          kind: _FieldKind.date,
          after: 'started_on',
        ),
      ],
    );

    if (result == null || !context.mounted) return;

    await _mutate(
      context,
      ref,
      () => ref
          .read(creatorFieldsRepositoryProvider)
          .addCredential(
            title: result['title']!,
            issuer: result['issuer'],
            fieldOfStudy: result['field_of_study'],
            startedOn: result['started_on'],
            endedOn: result['ended_on'],
          ),
    );
  }
}

// =====================================================================
// Work history and brand collaborations
// =====================================================================

class _ExperienceSection extends ConsumerWidget {
  const _ExperienceSection({required this.fields});

  final CreatorFields fields;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return _Section(
      title: 'Experience',
      icon: Icons.work_outline_rounded,
      emptyHint: 'Add brands you have worked with and roles you have held.',
      onAdd: () => _add(context, ref),
      children: fields.experience
          .map(
            (item) => _Row(
              title: item.role?.isNotEmpty == true
                  ? '${item.role} · ${item.organisation}'
                  : item.organisation,
              subtitle: [
                item.period,
                item.location ?? '',
              ].where((part) => part.isNotEmpty).join(' · '),
              badge: item.kindLabel,
              // Backed by a completed booking here, not merely claimed.
              verified: item.isPlatformVerified,
              onDelete: () => _confirmDelete(
                context,
                ref,
                what: item.organisation,
                action: () => ref
                    .read(creatorFieldsRepositoryProvider)
                    .deleteExperience(item.id),
              ),
            ),
          )
          .toList(),
    );
  }

  Future<void> _add(BuildContext context, WidgetRef ref) async {
    final result = await _showForm(
      context,
      title: 'Add experience',
      fields: const [
        _FormField(
          key: 'organisation',
          label: 'Brand or company',
          required: true,
        ),
        _FormField(key: 'role', label: 'Your role'),
        _FormField(key: 'location', label: 'Location'),
        _FormField(key: 'started_on', label: 'Started', kind: _FieldKind.date),
        _FormField(
          key: 'ended_on',
          label: 'Ended',
          kind: _FieldKind.date,
          after: 'started_on',
          helper: 'Leave empty if this is ongoing.',
        ),
      ],
    );

    if (result == null || !context.mounted) return;

    await _mutate(
      context,
      ref,
      () => ref
          .read(creatorFieldsRepositoryProvider)
          .addExperience(
            organisation: result['organisation']!,
            role: result['role'],
            location: result['location'],
            startedOn: result['started_on'],
            endedOn: result['ended_on'],
          ),
    );
  }
}

// =====================================================================
// Working hours
// =====================================================================

class _WorkingHoursSection extends ConsumerWidget {
  const _WorkingHoursSection({required this.fields});

  final CreatorFields fields;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final week = fields.workingHours;

    return _Section(
      title: 'Working hours',
      icon: Icons.schedule_rounded,
      emptyHint: 'Tell brands when you usually reply and shoot.',
      onAdd: () => _add(context, ref, week),
      children: week.days
          .where((day) => day.isSet)
          .map(
            (day) => _Row(
              title: day.label,
              subtitle: day.summary,
              badge: day.isWorking ? '' : 'Day off',
            ),
          )
          .toList(),
    );
  }

  Future<void> _add(
    BuildContext context,
    WidgetRef ref,
    WorkingWeek week,
  ) async {
    // The weekday list comes from the server's own week, so the labels match
    // what the profile renders and nothing here declares day names.
    final day = await showZSheet<WorkingDay>(
      context,
      title: 'Which day?',
      builder: (sheetContext) => ZMenuSection(
        children: [
          for (final candidate in week.days)
            ZMenuTile(
              icon: Icons.calendar_today_outlined,
              title: candidate.label,
              subtitle: candidate.isSet ? candidate.summary : null,
              onTap: () => Navigator.of(sheetContext).pop(candidate),
            ),
        ],
      ),
    );

    if (day == null || !context.mounted) return;

    final result = await _showForm(
      context,
      title: 'Hours on ${day.label}',
      fields: const [
        _FormField(
          key: 'starts_at',
          label: 'From',
          required: true,
          kind: _FieldKind.time,
        ),
        _FormField(
          key: 'ends_at',
          label: 'To',
          required: true,
          kind: _FieldKind.time,
          after: 'starts_at',
        ),
      ],
    );

    if (result == null || !context.mounted) return;

    await _mutate(
      context,
      ref,
      () => ref
          .read(creatorFieldsRepositoryProvider)
          .addWorkingHour(
            weekday: day.weekday,
            startsAt: result['starts_at'],
            endsAt: result['ends_at'],
            isWorking: true,
          ),
    );
  }
}

// =====================================================================
// FAQs
// =====================================================================

class _FaqsSection extends ConsumerWidget {
  const _FaqsSection({required this.fields});

  final CreatorFields fields;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return _Section(
      title: 'Frequently asked',
      icon: Icons.quiz_outlined,
      emptyHint:
          'Answer what brands always ask: turnaround, usage rights, '
          'revisions.',
      onAdd: () => _add(context, ref),
      children: fields.faqs
          .map(
            (faq) => _Row(
              title: faq.question,
              subtitle: faq.answer,
              onDelete: () => _confirmDelete(
                context,
                ref,
                what: 'this question',
                action: () =>
                    ref.read(creatorFieldsRepositoryProvider).deleteFaq(faq.id),
              ),
            ),
          )
          .toList(),
    );
  }

  Future<void> _add(BuildContext context, WidgetRef ref) async {
    final result = await _showForm(
      context,
      title: 'Add a question',
      fields: const [
        _FormField(
          key: 'question',
          label: 'Question',
          required: true,
          minLength: 5,
        ),
        _FormField(key: 'answer', label: 'Answer', required: true, maxLines: 4),
      ],
    );

    if (result == null || !context.mounted) return;

    await _mutate(
      context,
      ref,
      () => ref
          .read(creatorFieldsRepositoryProvider)
          .addFaq(question: result['question']!, answer: result['answer']!),
    );
  }
}

// =====================================================================
// Shared pieces
// =====================================================================

class _Section extends StatelessWidget {
  const _Section({
    required this.title,
    required this.icon,
    required this.children,
    required this.onAdd,
    required this.emptyHint,
  });

  final String title;
  final IconData icon;
  final List<Widget> children;
  final VoidCallback onAdd;
  final String emptyHint;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Row(
          children: [
            Expanded(
              child: Semantics(
                header: true,
                child: Text(
                  children.isEmpty ? title : '$title · ${children.length}',
                  style: AppTypography.titleMedium,
                ),
              ),
            ),
            ZButton.tonal(
              label: 'Add',
              icon: Icons.add_rounded,
              size: ZButtonSize.small,
              expand: false,
              onPressed: onAdd,
            ),
          ],
        ),
        const SizedBox(height: AppSpacing.xs),
        if (children.isEmpty)
          ZCard(
            shadow: false,
            onTap: onAdd,
            color: AppColors.surfaceContainerLow,
            border: Border.all(color: AppColors.outlineVariant),
            child: Row(
              children: [
                Icon(icon, size: 22, color: AppColors.primary),
                const SizedBox(width: AppSpacing.sm),
                Expanded(
                  child: Text(emptyHint, style: AppTypography.bodySmall),
                ),
              ],
            ),
          )
        else
          ZCard(
            shadow: false,
            padding: EdgeInsets.zero,
            child: Column(
              children: [
                for (var i = 0; i < children.length; i++) ...[
                  if (i > 0)
                    Divider(height: 1, color: AppColors.outlineVariant),
                  children[i],
                ],
              ],
            ),
          ),
      ],
    );
  }
}

class _Row extends StatelessWidget {
  const _Row({
    required this.title,
    this.subtitle = '',
    this.badge = '',
    this.verified = false,
    this.onDelete,
  });

  final String title;
  final String subtitle;
  final String badge;
  final bool verified;
  final VoidCallback? onDelete;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.fromLTRB(
        AppSpacing.md,
        AppSpacing.sm,
        onDelete == null ? AppSpacing.md : AppSpacing.base,
        AppSpacing.sm,
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Flexible(
                      child: Text(
                        title,
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                        style: AppTypography.titleSmall,
                      ),
                    ),
                    if (verified) ...[
                      const SizedBox(width: 4),
                      Tooltip(
                        message: 'Verified',
                        child: Icon(
                          Icons.verified_rounded,
                          size: 16,
                          color: AppColors.success,
                        ),
                      ),
                    ],
                  ],
                ),
                if (subtitle.isNotEmpty) ...[
                  const SizedBox(height: 2),
                  Text(
                    subtitle,
                    maxLines: 3,
                    overflow: TextOverflow.ellipsis,
                    style: AppTypography.bodySmall,
                  ),
                ],
                if (badge.isNotEmpty) ...[
                  const SizedBox(height: 6),
                  StatusChip(
                    label: badge,
                    tone: StatusTone.neutral,
                    dense: true,
                  ),
                ],
              ],
            ),
          ),
          if (onDelete != null)
            IconButton(
              icon: const Icon(Icons.delete_outline_rounded, size: 20),
              tooltip: 'Remove',
              color: AppColors.onSurfaceVariant,
              onPressed: onDelete,
            ),
        ],
      ),
    );
  }
}

enum _FieldKind { text, date, time }

/// One field in the generic add form.
class _FormField {
  const _FormField({
    required this.key,
    required this.label,
    this.required = false,
    this.minLength = 0,
    this.maxLines = 1,
    this.kind = _FieldKind.text,
    this.after,
    this.helper,
  });

  final String key;
  final String label;
  final bool required;
  final int minLength;
  final int maxLines;
  final _FieldKind kind;

  /// Another field's key this one must not come before (an end date after
  /// its start, a closing time after the opening one).
  final String? after;
  final String? helper;
}

/// A small add form built from a field list, in a sheet.
///
/// Deliberately generic. These four sections differ only in which fields they
/// collect, and four near-identical forms would be four places to fix the
/// next time one of them needs a change.
///
/// Returns null when cancelled, otherwise a map of the non-empty values.
Future<Map<String, String>?> _showForm(
  BuildContext context, {
  required String title,
  required List<_FormField> fields,
}) {
  return showZSheet<Map<String, String>>(
    context,
    title: title,
    builder: (_) => _FieldsForm(fields: fields),
  );
}

class _FieldsForm extends StatefulWidget {
  const _FieldsForm({required this.fields});

  final List<_FormField> fields;

  @override
  State<_FieldsForm> createState() => _FieldsFormState();
}

class _FieldsFormState extends State<_FieldsForm> {
  final _formKey = GlobalKey<FormState>();
  late final Map<String, TextEditingController> _controllers = {
    for (final field in widget.fields) field.key: TextEditingController(),
  };
  bool _submitted = false;

  /// The server's formats: `2024-06-01` and `09:30`.
  static final _dateFormat = DateFormat('yyyy-MM-dd');

  @override
  void dispose() {
    for (final controller in _controllers.values) {
      controller.dispose();
    }
    super.dispose();
  }

  Future<void> _pick(_FormField field) async {
    final controller = _controllers[field.key]!;

    if (field.kind == _FieldKind.date) {
      final now = DateTime.now();
      final current = DateTime.tryParse(controller.text);
      final picked = await showDatePicker(
        context: context,
        initialDate: current ?? now,
        firstDate: DateTime(1950),
        lastDate: DateTime(now.year + 5, 12, 31),
        helpText: field.label,
      );
      if (picked != null) controller.text = _dateFormat.format(picked);
    } else {
      final parts = controller.text.split(':');
      final current = parts.length == 2
          ? TimeOfDay(
              hour: int.tryParse(parts[0]) ?? 9,
              minute: int.tryParse(parts[1]) ?? 0,
            )
          : const TimeOfDay(hour: 9, minute: 0);
      final picked = await showTimePicker(
        context: context,
        initialTime: current,
        helpText: field.label,
      );
      if (picked != null) {
        controller.text =
            '${picked.hour.toString().padLeft(2, '0')}:'
            '${picked.minute.toString().padLeft(2, '0')}';
      }
    }

    if (_submitted) _formKey.currentState?.validate();
  }

  String? _validate(_FormField field, String? value) {
    final text = (value ?? '').trim();

    if (field.required && text.isEmpty) return '${field.label} is required.';
    if (text.isEmpty) return null;
    if (text.length < field.minLength) {
      return 'At least ${field.minLength} characters.';
    }

    final earlierKey = field.after;
    if (earlierKey != null) {
      final earlier = _controllers[earlierKey]!.text.trim();
      // Both formats sort correctly as plain strings.
      if (earlier.isNotEmpty && text.compareTo(earlier) < 0) {
        final other = widget.fields.firstWhere((f) => f.key == earlierKey);
        return '${field.label} cannot be before ${other.label.toLowerCase()}.';
      }
    }
    return null;
  }

  void _submit() {
    setState(() => _submitted = true);
    if (!(_formKey.currentState?.validate() ?? false)) return;

    // Empty optional fields are dropped rather than sent as "", which the
    // server would store as a present-but-blank value.
    final values = <String, String>{};
    _controllers.forEach((key, controller) {
      final text = controller.text.trim();
      if (text.isNotEmpty) values[key] = text;
    });

    Navigator.of(context).pop(values);
  }

  @override
  Widget build(BuildContext context) {
    return Form(
      key: _formKey,
      autovalidateMode: _submitted
          ? AutovalidateMode.onUserInteraction
          : AutovalidateMode.disabled,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: [
          for (final field in widget.fields) ...[
            ZTextField(
              label: field.required ? field.label : '${field.label} (optional)',
              controller: _controllers[field.key],
              maxLines: field.maxLines,
              minLines: field.maxLines > 1 ? 2 : null,
              readOnly: field.kind != _FieldKind.text,
              onTap: field.kind == _FieldKind.text ? null : () => _pick(field),
              prefixIcon: switch (field.kind) {
                _FieldKind.date => Icons.event_outlined,
                _FieldKind.time => Icons.schedule_rounded,
                _FieldKind.text => null,
              },
              hint: switch (field.kind) {
                _FieldKind.date => 'Choose a date',
                _FieldKind.time => 'Choose a time',
                _FieldKind.text => null,
              },
              helper: field.helper,
              textCapitalization: field.kind == _FieldKind.text
                  ? TextCapitalization.sentences
                  : TextCapitalization.none,
              textInputAction: field.maxLines > 1
                  ? TextInputAction.newline
                  : TextInputAction.next,
              validator: (value) => _validate(field, value),
            ),
            const SizedBox(height: AppSpacing.md),
          ],
          const SizedBox(height: AppSpacing.xs),
          ZButton(label: 'Save', onPressed: _submit),
        ],
      ),
    );
  }
}

/// Asks before removing a row; the server has no undo.
Future<void> _confirmDelete(
  BuildContext context,
  WidgetRef ref, {
  required String what,
  required Future<void> Function() action,
}) async {
  final confirmed = await showZConfirm(
    context,
    title: 'Remove $what?',
    message: 'It will disappear from your public profile.',
    confirmLabel: 'Remove',
    destructive: true,
    icon: Icons.delete_outline_rounded,
  );
  if (!confirmed || !context.mounted) return;

  await _mutate(context, ref, action, success: 'Removed.');
}

/// Run a write and reload, surfacing whatever the server said.
///
/// The server's message is shown verbatim: for a locked section it names the
/// section and the administrator's reason, which is the only thing that tells
/// the creator what to do next.
Future<void> _mutate(
  BuildContext context,
  WidgetRef ref,
  Future<void> Function() action, {
  String success = 'Saved.',
}) async {
  try {
    await ref.read(myCreatorFieldsProvider.notifier).mutate(action);
    if (context.mounted) showZSnack(context, success);
  } catch (error) {
    if (context.mounted) {
      showZSnack(
        context,
        error is ApiFailure ? error.message : '$error',
        isError: true,
      );
    }
  }
}
