import 'package:flutter/material.dart';

import '../../../core/theme/app_theme.dart';
import '../../../models/kyc.dart';
import '../../../shared/widgets/z_button.dart';
import '../../../shared/widgets/z_dialogs.dart';
import 'kyc_trust_cards.dart';

/// Gets the consent the first upload or details save needs, if it is still
/// needed.
///
/// Under the DPDP Act identity data may only be processed once the person has
/// agreed to it, so the server refuses the first document or detail until
/// that agreement is on record — separately from, and before, the consent
/// each submission asks for.
///
/// Returns `true` when the user has just agreed (send `consent: true` with
/// the request), `false` when nothing needs asking (send nothing), and `null`
/// when they backed out (send nothing at all). [error] is the server's reason
/// when it refused a request for want of consent, which asks again even if
/// the overview said it was on record.
Future<bool?> ensureKycProcessingConsent(
  BuildContext context,
  KycProfile profile, {
  String? error,
}) async {
  if (!profile.processingConsentRequired && error == null) return false;

  final agreed = await showKycProcessingConsent(
    context,
    text: profile.consentText,
    error: error,
  );
  return agreed ? true : null;
}

/// The consent sheet itself. True when the user ticked the box and agreed.
Future<bool> showKycProcessingConsent(
  BuildContext context, {
  String? text,
  String? error,
}) async {
  final agreed = await showModalBottomSheet<bool>(
    context: context,
    isScrollControlled: true,
    useSafeArea: true,
    sheetAnimationStyle: zSheetMotion(context),
    builder: (_) => KycProcessingConsentSheet(text: text, error: error),
  );
  return agreed ?? false;
}

/// Asks, once, before anything is uploaded or saved: the server's consent
/// wording, a box the user ticks themselves, and a button that only works
/// once it is ticked.
class KycProcessingConsentSheet extends StatefulWidget {
  const KycProcessingConsentSheet({super.key, this.text, this.error});

  /// The server's wording; [KycConsentCard.defaultText] until it sends one.
  final String? text;

  /// Why the server refused the last request, when it did.
  final String? error;

  @override
  State<KycProcessingConsentSheet> createState() =>
      _KycProcessingConsentSheetState();
}

class _KycProcessingConsentSheetState extends State<KycProcessingConsentSheet> {
  bool _agreed = false;

  @override
  Widget build(BuildContext context) {
    return SingleChildScrollView(
      padding: const EdgeInsets.fromLTRB(
        AppSpacing.lg,
        AppSpacing.md,
        AppSpacing.lg,
        AppSpacing.lg,
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Icon(
                Icons.privacy_tip_outlined,
                color: AppColors.primary,
                size: 22,
              ),
              const SizedBox(width: AppSpacing.sm),
              Expanded(
                child: Text(
                  'Before you add your documents',
                  style: AppTypography.titleMedium,
                ),
              ),
            ],
          ),
          const SizedBox(height: AppSpacing.xs),
          Text(
            'We need your agreement before we store and check any of your '
            'identity documents or details. You will be asked again when you '
            'submit them for review.',
            style: AppTypography.bodySmall,
          ),
          const SizedBox(height: AppSpacing.md),
          KycConsentCard(
            value: _agreed,
            text: widget.text,
            errorText: _agreed ? null : widget.error,
            onChanged: (value) => setState(() => _agreed = value),
          ),
          const SizedBox(height: AppSpacing.md),
          ZButton(
            label: 'Agree and continue',
            icon: Icons.check_rounded,
            onPressed: _agreed ? () => Navigator.of(context).pop(true) : null,
          ),
          const SizedBox(height: AppSpacing.xs),
          TextButton(
            onPressed: () => Navigator.of(context).pop(false),
            style: TextButton.styleFrom(
              minimumSize: const Size(AppSpacing.minTouch, 44),
            ),
            child: const Text('Not now'),
          ),
        ],
      ),
    );
  }
}
