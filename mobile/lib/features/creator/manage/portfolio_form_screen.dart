import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:image_picker/image_picker.dart';

import '../../../core/theme/app_theme.dart';
import '../../../models/creator_tools.dart';
import '../../../models/taxonomy.dart';
import '../../../providers/creator_tools_provider.dart';
import '../../../providers/opportunity_provider.dart';
import '../../../providers/taxonomy_provider.dart';
import '../../../router/routes.dart';
import '../../../shared/widgets/states.dart';
import '../../../shared/widgets/z_button.dart';
import '../../../shared/widgets/z_card.dart';
import '../../../shared/widgets/z_scaffold.dart';
import '../../../shared/widgets/z_text_field.dart';
import '../../campaigns/widgets/campaign_form_parts.dart';
import '../widgets/portfolio_tile.dart';
import 'creator_tools_rules.dart';
import 'widgets/creator_tools_common.dart';

/// A picture chosen on the device: its bytes, and the name to upload it as.
typedef PickedImage = ({Uint8List bytes, String name});

/// Opens the photo library. A provider so a test can hand the form a picture
/// without a platform channel.
///
/// Bytes rather than a path, as the chat composer does, so the same upload
/// works on the web build. Scaled and recompressed on the way out: the API
/// keeps images to a few megabytes and 2048 pixels anyway.
final portfolioImagePickerProvider = Provider<Future<PickedImage?> Function()>(
  (ref) => () async {
    final file = await ImagePicker().pickImage(
      source: ImageSource.gallery,
      imageQuality: 85,
      maxWidth: 2048,
      maxHeight: 2048,
    );
    if (file == null) return null;

    // The server decides how to store a file by its extension, so a name
    // without one (some pickers hand back a bare id) would be refused.
    final name = file.name.contains('.') ? file.name : '${file.name}.jpg';
    return (bytes: await file.readAsBytes(), name: name);
  },
);

/// Kinds the profile plays from their link; for these the picture is the
/// cover. Anything else is shown as the picture itself.
const _playedKinds = {'reel', 'video', 'short', 'live', 'audio'};

/// Add one piece of work to the portfolio.
///
/// What `POST /me/creator/portfolio` accepts from the app: a picture, the tab
/// it belongs under, and optionally a title, a caption and a link to the
/// original. The picture is required — every tile on the profile is one —
/// and for a reel or a video it is the cover, with the link being what plays.
class PortfolioFormScreen extends ConsumerStatefulWidget {
  const PortfolioFormScreen({super.key});

  @override
  ConsumerState<PortfolioFormScreen> createState() =>
      _PortfolioFormScreenState();
}

class _PortfolioFormScreenState extends ConsumerState<PortfolioFormScreen> {
  final _formKey = GlobalKey<FormState>();
  final _title = TextEditingController();
  final _caption = TextEditingController();
  final _link = TextEditingController();

  String? _kind;
  PickedImage? _image;

  bool _submitted = false;
  bool _saving = false;
  Map<String, String> _serverErrors = const {};
  String? _formError;

  static const _fields = [
    'media_kind',
    'title',
    'caption',
    'external_url',
    'media',
    'media_url',
  ];

  @override
  void dispose() {
    _title.dispose();
    _caption.dispose();
    _link.dispose();
    super.dispose();
  }

  void _edited(String field) {
    if (_serverErrors.containsKey(field)) {
      setState(() => _serverErrors = {..._serverErrors}..remove(field));
    }
  }

  Future<void> _pick() async {
    try {
      final picked = await ref.read(portfolioImagePickerProvider)();
      if (picked == null || !mounted) return;

      setState(() => _image = picked);
      _edited('media');
    } catch (_) {
      if (mounted) {
        showZSnack(
          context,
          'We could not open your photos. Check that Zusty may access them.',
          isError: true,
        );
      }
    }
  }

  Future<void> _save() async {
    // Read once, before anything is awaited: the default kind is the
    // taxonomy's, shown as selected until the creator picks another.
    final kind = _kind ?? ref.read(taxonomiesProvider).defaultValue(Tax.portfolioKind);

    setState(() {
      _submitted = true;
      _serverErrors = const {};
      _formError = null;
    });

    final fieldsOk = _formKey.currentState?.validate() ?? false;
    final image = _image;

    if (!fieldsOk || image == null || kind == null) {
      showZSnack(context, 'Check the highlighted fields.', isError: true);
      return;
    }

    setState(() => _saving = true);

    try {
      final added = await ref
          .read(myPortfolioProvider.notifier)
          .add(
            PortfolioDraft(
              mediaKind: kind,
              title: _title.text,
              caption: _caption.text,
              externalUrl: _link.text,
            ),
            bytes: image.bytes,
            fileName: image.name,
          );

      if (!mounted) return;
      showZSnack(
        context,
        added.review == PortfolioReview.pending
            ? 'Added. It appears on your profile once our team has reviewed it.'
            : 'Added to your portfolio.',
      );
      context.canPop() ? context.pop() : context.go(Routes.myPortfolio);
    } catch (error) {
      if (!mounted) return;

      final byField = fieldErrors(error, _fields);
      setState(() {
        _serverErrors = byField;
        _formError = byField.isEmpty ? failureMessage(error) : null;
      });
      showZSnack(
        context,
        byField.isEmpty ? failureMessage(error) : 'Check the highlighted fields.',
        isError: true,
      );
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (!ref.watch(isCreatorSessionProvider)) {
      return const ZScaffold(
        appBar: ZAppBar(title: 'Add to portfolio'),
        body: CreatorsOnlyNote(),
      );
    }

    final margin = AppBreakpoints.pageMargin(context);
    final taxonomy = ref.watch(taxonomiesProvider);
    final kinds = taxonomy.map(Tax.portfolioKind);
    final kind = _kind ?? taxonomy.defaultValue(Tax.portfolioKind);
    final played = _playedKinds.contains(kind);
    final locked = ref.watch(myPortfolioProvider).valueOrNull?.locked ?? false;

    final imageError = _serverErrors['media'] ??
        _serverErrors['media_url'] ??
        (_submitted && _image == null
            ? (played ? 'Choose a cover picture.' : 'Choose a picture.')
            : null);

    return ZScaffold(
      padded: false,
      appBar: const ZAppBar(title: 'Add to portfolio'),
      bottomBar: ZButton(
        label: 'Add to portfolio',
        isLoading: _saving,
        onPressed: _save,
      ),
      body: Form(
        key: _formKey,
        autovalidateMode: _submitted
            ? AutovalidateMode.onUserInteraction
            : AutovalidateMode.disabled,
        child: ListView(
          padding: EdgeInsets.fromLTRB(margin, AppSpacing.md, margin, AppSpacing.xl),
          children: [
            if (locked) const LockedSectionBanner(what: 'Your portfolio'),
            if (_formError != null) FormErrorBanner(message: _formError!),

            FormSectionCard(
              title: 'What is it?',
              subtitle: 'Decides which tab of your profile it appears under.',
              children: [
                ChoiceChips(
                  options: kinds,
                  value: kind,
                  onChanged: (value) {
                    setState(() => _kind = value);
                    _edited('media_kind');
                  },
                ),
                if (_serverErrors['media_kind'] != null) ...[
                  const SizedBox(height: AppSpacing.xs),
                  Text(
                    _serverErrors['media_kind']!,
                    style: AppTypography.bodySmall.copyWith(color: AppColors.error),
                  ),
                ],
              ],
            ),

            FormSectionCard(
              title: played ? 'Cover picture' : 'Picture',
              subtitle: played
                  ? 'Brands see this in your grid. The link below is what plays.'
                  : 'JPG, PNG or WebP.',
              children: [
                _ImagePickerField(
                  image: _image,
                  kind: kind ?? '',
                  error: imageError,
                  onPick: _pick,
                ),
              ],
            ),

            FormSectionCard(
              title: 'Details',
              children: [
                ZTextField(
                  label: played
                      ? 'Link to the original (recommended)'
                      : 'Link to the original (optional)',
                  hint: 'https://www.instagram.com/reel/…',
                  helper: played
                      ? 'Without it, brands see the cover but cannot watch it.'
                      : null,
                  controller: _link,
                  keyboardType: TextInputType.url,
                  prefixIcon: Icons.link_rounded,
                  maxLength: CreatorToolsRules.urlMax,
                  textInputAction: TextInputAction.next,
                  errorText: _serverErrors['external_url'],
                  onChanged: (_) => _edited('external_url'),
                  validator: CreatorToolsRules.optionalLink,
                ),
                const SizedBox(height: AppSpacing.md),
                ZTextField(
                  label: 'Title (optional)',
                  hint: 'e.g. Diwali launch for a skincare brand',
                  controller: _title,
                  maxLength: CreatorToolsRules.portfolioTitleMax,
                  textCapitalization: TextCapitalization.sentences,
                  textInputAction: TextInputAction.next,
                  errorText: _serverErrors['title'],
                  onChanged: (_) => _edited('title'),
                  validator: CreatorToolsRules.portfolioTitle,
                ),
                const SizedBox(height: AppSpacing.md),
                ZTextField(
                  label: 'Caption (optional)',
                  hint: 'What it was, and how it did',
                  controller: _caption,
                  maxLines: 4,
                  minLines: 2,
                  maxLength: CreatorToolsRules.captionMax,
                  textCapitalization: TextCapitalization.sentences,
                  errorText: _serverErrors['caption'],
                  onChanged: (_) => _edited('caption'),
                  validator: CreatorToolsRules.caption,
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

/// The picture slot: a preview once one is chosen, a large target before.
class _ImagePickerField extends StatelessWidget {
  const _ImagePickerField({
    required this.image,
    required this.kind,
    required this.error,
    required this.onPick,
  });

  final PickedImage? image;
  final String kind;
  final String? error;
  final VoidCallback onPick;

  @override
  Widget build(BuildContext context) {
    final picked = image;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Semantics(
          // Its own node, so a screen reader announces the slot by itself
          // rather than folded into the card's heading.
          container: true,
          button: true,
          label: picked == null ? 'Choose a picture' : 'Change the picture',
          onTap: onPick,
          excludeSemantics: true,
          child: ZCard(
            onTap: onPick,
            shadow: false,
            padding: EdgeInsets.zero,
            clip: true,
            color: AppColors.surfaceContainerLow,
            border: Border.all(
              color: error != null ? AppColors.error : AppColors.outlineVariant,
            ),
            child: AspectRatio(
              aspectRatio: 4 / 5,
              child: picked == null
                  ? Column(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        Icon(
                          portfolioKindIcon(kind),
                          size: 40,
                          color: AppColors.primary,
                        ),
                        const SizedBox(height: AppSpacing.sm),
                        Text('Choose a picture', style: AppTypography.titleSmall),
                        const SizedBox(height: 2),
                        Text(
                          'From your photo library',
                          style: AppTypography.bodySmall,
                        ),
                      ],
                    )
                  : Image.memory(
                      picked.bytes,
                      fit: BoxFit.cover,
                      gaplessPlayback: true,
                      errorBuilder: (_, _, _) => Center(
                        child: Icon(
                          Icons.image_not_supported_outlined,
                          color: AppColors.onSurfaceVariant,
                        ),
                      ),
                    ),
            ),
          ),
        ),
        if (picked != null) ...[
          const SizedBox(height: AppSpacing.xs),
          Align(
            alignment: Alignment.centerLeft,
            child: ZButton(
              label: 'Choose another',
              variant: ZButtonVariant.text,
              size: ZButtonSize.medium,
              icon: Icons.swap_horiz_rounded,
              expand: false,
              onPressed: onPick,
            ),
          ),
        ],
        if (error != null) ...[
          const SizedBox(height: AppSpacing.xs),
          Semantics(
            liveRegion: true,
            child: Text(
              error!,
              style: AppTypography.bodySmall.copyWith(color: AppColors.error),
            ),
          ),
        ],
      ],
    );
  }
}
