import 'package:flutter/material.dart';

import '../../../core/theme/app_theme.dart';
import '../../../models/kyc.dart';
import '../../../shared/widgets/status_chip.dart';
import '../../../shared/widgets/z_card.dart';
import 'kyc_status_style.dart';

/// Where the blue tick stands.
///
/// The tick is a separate, manual decision taken after approval, so this
/// spells out which of the three places the user is in rather than letting
/// "approved" read as "ticked".
class KycBlueTickCard extends StatelessWidget {
  const KycBlueTickCard({super.key, required this.profile});

  final KycProfile profile;

  @override
  Widget build(BuildContext context) {
    final granted = profile.hasBlueTick;

    final (title, message, tone) = switch (profile) {
      _ when granted => (
          switch (kycDate(profile.badgeGrantedAt)) {
            final date? => 'Blue tick: granted on $date',
            null => 'Blue tick: granted',
          },
          'Shown next to your name across Zusty, so people know our team '
              'checked this account by hand.',
          StatusTone.success,
        ),
      _ when profile.isBlueTickRevoked => (
          'Blue tick removed',
          profile.badgeRevokeReason ??
              'Our team removed the blue tick from this account. Contact '
                  'support if you think this is a mistake.',
          StatusTone.error,
        ),
      _ when profile.isApproved => (
          'Approved — blue tick under review',
          'Our team reviews the blue tick separately, by hand. We will '
              'notify you when it has been decided.',
          StatusTone.info,
        ),
      _ => (
          'Blue tick: not eligible yet',
          'The blue tick is considered once your verification is approved.',
          StatusTone.neutral,
        ),
    };

    final (tint, _) = StatusChip.colorsFor(tone);

    return ZCard(
      shadow: false,
      child: Semantics(
        container: true,
        label: '$title. $message',
        excludeSemantics: true,
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Container(
              width: 44,
              height: 44,
              decoration: BoxDecoration(
                color: granted ? AppColors.infoContainer : tint,
                borderRadius: AppRadius.mdAll,
              ),
              child: Icon(
                granted ? Icons.verified_rounded : Icons.verified_outlined,
                size: 24,
                color: granted ? AppColors.verified : AppColors.onSurfaceVariant,
              ),
            ),
            const SizedBox(width: AppSpacing.sm),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(title, style: AppTypography.titleSmall),
                  const SizedBox(height: 2),
                  Text(message, style: AppTypography.bodySmall),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// The consent a submission needs, as a box the user ticks themselves.
///
/// Never pre-ticked — not even after an earlier submission — because consent
/// under the DPDP Act has to be a fresh, affirmative act for each use.
class KycConsentCard extends StatelessWidget {
  const KycConsentCard({
    super.key,
    required this.value,
    required this.onChanged,
    this.text,
    this.errorText,
    this.lastConsentAt,
  });

  /// The default wording, used until the server supplies its own.
  static const defaultText =
      'I consent to Zusty processing my identity documents to verify my '
      'account, as described in the Privacy Policy.';

  final bool value;
  final ValueChanged<bool>? onChanged;
  final String? text;
  final String? errorText;
  final String? lastConsentAt;

  @override
  Widget build(BuildContext context) {
    final enabled = onChanged != null;
    final last = kycDate(lastConsentAt);

    return ZCard(
      shadow: false,
      padding: const EdgeInsets.fromLTRB(
        AppSpacing.xs,
        AppSpacing.xs,
        AppSpacing.md,
        AppSpacing.sm,
      ),
      border: Border.all(
        color: errorText != null ? AppColors.error : AppColors.outlineVariant,
        width: errorText != null ? 1.4 : 1,
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          MergeSemantics(
            child: Material(
              type: MaterialType.transparency,
              child: InkWell(
              onTap: enabled ? () => onChanged!(!value) : null,
              borderRadius: AppRadius.mdAll,
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Checkbox(
                    value: value,
                    onChanged: enabled ? (next) => onChanged!(next ?? false) : null,
                  ),
                  const SizedBox(width: 4),
                  Expanded(
                    child: Padding(
                      padding: const EdgeInsets.only(top: 12),
                      child: Text(
                        text ?? defaultText,
                        style: AppTypography.bodyMedium,
                      ),
                    ),
                  ),
                ],
              ),
            ),
            ),
          ),
          if (last != null || errorText != null)
            Padding(
              padding: const EdgeInsets.only(
                left: AppSpacing.xxl,
                top: AppSpacing.xs,
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  if (errorText != null)
                    Text(
                      errorText!,
                      style: AppTypography.bodySmall.copyWith(
                        color: AppColors.error,
                      ),
                    ),
                  if (last != null)
                    Text(
                      'You last gave consent on $last.',
                      style: AppTypography.labelSmall.copyWith(
                        color: AppColors.onSurfaceVariant,
                      ),
                    ),
                ],
              ),
            ),
        ],
      ),
    );
  }
}

/// How identity documents are handled: encrypted storage, who can see them,
/// and how long they are kept.
class KycPrivacyNotice extends StatelessWidget {
  const KycPrivacyNotice({super.key});

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(AppSpacing.md),
      decoration: BoxDecoration(
        color: AppColors.surfaceContainerLowest,
        borderRadius: AppRadius.lgAll,
        border: Border.all(color: AppColors.outlineVariant),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Container(
                width: 36,
                height: 36,
                decoration: BoxDecoration(
                  color: AppColors.surfaceContainerHigh,
                  borderRadius: AppRadius.mdAll,
                ),
                child: Icon(
                  Icons.lock_rounded,
                  size: 18,
                  color: AppColors.secondary,
                ),
              ),
              const SizedBox(width: AppSpacing.sm),
              Expanded(
                child: Text(
                  'Your data stays private',
                  style: AppTypography.titleSmall,
                ),
              ),
            ],
          ),
          const SizedBox(height: AppSpacing.sm),
          const _PrivacyPoint(
            icon: Icons.enhanced_encryption_outlined,
            title: 'Encrypted storage',
            body: 'Your documents and ID numbers are encrypted at rest. We '
                'keep only the last 4 digits of your Aadhaar number, never '
                'the full number, and accept only masked Aadhaar copies.',
          ),
          const _PrivacyPoint(
            icon: Icons.admin_panel_settings_outlined,
            title: 'Restricted access',
            body: 'Only our verification team can open them, and every view '
                'is logged. Brands and creators never see your documents.',
          ),
          const _PrivacyPoint(
            icon: Icons.auto_delete_outlined,
            title: 'Kept only as long as needed',
            body: 'Replaced files and rejected or abandoned submissions are '
                'deleted after a set period, and everything is deleted if '
                'you close your account.',
            last: true,
          ),
        ],
      ),
    );
  }
}

class _PrivacyPoint extends StatelessWidget {
  const _PrivacyPoint({
    required this.icon,
    required this.title,
    required this.body,
    this.last = false,
  });

  final IconData icon;
  final String title;
  final String body;
  final bool last;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.only(bottom: last ? 0 : AppSpacing.sm),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Padding(
            padding: const EdgeInsets.only(top: 1),
            child: Icon(icon, size: 18, color: AppColors.onSurfaceVariant),
          ),
          const SizedBox(width: AppSpacing.sm),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(title, style: AppTypography.labelLarge),
                const SizedBox(height: 2),
                Text(body, style: AppTypography.bodySmall),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
