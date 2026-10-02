import 'package:flutter/material.dart';

import '../../../core/utils/formatters.dart';
import '../../../models/kyc.dart';
import '../../../models/user.dart' show KycStatus;
import '../../../shared/widgets/status_chip.dart';

/// How each verification state looks, in one place, so the hub, the profile
/// headers and the menu can never disagree about what "Action needed" means.
extension KycStatusStyle on KycStatus {
  StatusTone get tone => switch (this) {
        KycStatus.approved => StatusTone.success,
        KycStatus.pending || KycStatus.underReview => StatusTone.info,
        KycStatus.actionNeeded || KycStatus.expired => StatusTone.warning,
        KycStatus.rejected || KycStatus.suspended => StatusTone.error,
        KycStatus.notSubmitted => StatusTone.neutral,
      };

  IconData get icon => switch (this) {
        KycStatus.approved => Icons.verified_user_rounded,
        KycStatus.pending => Icons.schedule_rounded,
        KycStatus.underReview => Icons.manage_search_rounded,
        KycStatus.actionNeeded => Icons.assignment_late_outlined,
        KycStatus.rejected => Icons.gpp_bad_outlined,
        KycStatus.suspended => Icons.block_rounded,
        KycStatus.expired => Icons.update_rounded,
        KycStatus.notSubmitted => Icons.shield_outlined,
      };

  /// The hub's headline.
  String get headline => switch (this) {
        KycStatus.approved => 'You are verified',
        KycStatus.pending => 'Pending review',
        KycStatus.underReview => 'Under review',
        KycStatus.actionNeeded => 'Action needed',
        KycStatus.rejected => 'Not approved',
        KycStatus.suspended => 'Verification on hold',
        KycStatus.expired => 'Verification expired',
        KycStatus.notSubmitted => 'Get verified',
      };

  /// What the state means for the user, and what happens next.
  String get explanation => switch (this) {
        KycStatus.approved =>
          'Your identity has been checked by our team. Verified accounts can '
              'book, get paid and withdraw.',
        KycStatus.pending =>
          'Submitted and waiting for a reviewer. Most reviews finish within '
              'one business day — we will notify you.',
        KycStatus.underReview =>
          'A reviewer is checking your details and documents now. We will '
              'notify you as soon as there is a decision.',
        KycStatus.actionNeeded =>
          'Our reviewer needs something more before they can finish. Fix the '
              'items below and submit again.',
        KycStatus.rejected =>
          'Your submission was not approved. Fix the issue below and submit '
              'again.',
        KycStatus.suspended =>
          'Your verification is on hold. Contact support to find out why.',
        KycStatus.expired =>
          'Your verification has lapsed. Check your documents are current and '
              'submit again to renew it.',
        KycStatus.notSubmitted =>
          'Add your details and documents, then submit them for a one-time '
              'review. It unlocks bookings and payouts.',
      };
}

extension KycSlotStateStyle on KycSlotState {
  StatusTone get tone => switch (this) {
        KycSlotState.approved => StatusTone.success,
        KycSlotState.uploaded => StatusTone.info,
        KycSlotState.rejected => StatusTone.error,
        KycSlotState.reuploadRequested => StatusTone.warning,
        KycSlotState.missing => StatusTone.neutral,
      };

  IconData get icon => switch (this) {
        KycSlotState.approved => Icons.check_rounded,
        KycSlotState.uploaded => Icons.description_outlined,
        KycSlotState.rejected => Icons.close_rounded,
        KycSlotState.reuploadRequested => Icons.restart_alt_rounded,
        KycSlotState.missing => Icons.upload_file_rounded,
      };
}

/// `01 Oct 2026` from an API timestamp, or null when there is none.
String? kycDate(String? raw) {
  final parsed = Formatters.parseDate(raw);
  return parsed == null ? null : Formatters.date(parsed);
}
