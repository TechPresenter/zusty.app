import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../../../core/utils/formatters.dart';
import '../../../models/campaign.dart';
import '../../../shared/widgets/status_chip.dart';

/// A label, the tone to draw it in, and an icon.
typedef BrandStatusLabel = ({String label, StatusTone tone, IconData icon});

/// How a campaign reads to the BRAND that owns it.
///
/// The creator-side wording lives in `campaign_card.dart` (`boardStatus`,
/// `deadline`); a brand needs different emphasis — "Draft" is a to-do, not a
/// hidden state, and an open brief is good news rather than a warning — so the
/// brand screens share these instead of the model's generic [CampaignStatus.tone].
extension BrandCampaignView on Campaign {
  BrandStatusLabel get brandStatus => switch (status) {
        CampaignStatus.draft => (
            label: 'Draft',
            tone: StatusTone.neutral,
            icon: Icons.edit_note_rounded,
          ),
        CampaignStatus.open => (
            label: 'Open',
            tone: StatusTone.success,
            icon: Icons.campaign_rounded,
          ),
        CampaignStatus.inProgress => (
            label: 'In progress',
            tone: StatusTone.info,
            icon: Icons.autorenew_rounded,
          ),
        CampaignStatus.completed => (
            label: 'Completed',
            tone: StatusTone.neutral,
            icon: Icons.task_alt_rounded,
          ),
        CampaignStatus.cancelled => (
            label: 'Cancelled',
            tone: StatusTone.error,
            icon: Icons.block_rounded,
          ),
        CampaignStatus.archived => (
            label: 'Archived',
            tone: StatusTone.neutral,
            icon: Icons.inventory_2_outlined,
          ),
      };

  /// The window in a card's width: "5 Aug – 28 Aug", and for a live brief
  /// how long is left once that matters ("Ends in 3 days").
  ({String label, StatusTone tone}) get scheduleLabel {
    final start = startsOn;
    final end = endsOn;
    if (start == null && end == null) {
      return (label: 'Flexible dates', tone: StatusTone.neutral);
    }

    if (status.isLive) {
      final days = daysLeft;
      if (days != null) {
        if (days < 0) {
          return (label: 'Ended ${_day(end!)}', tone: StatusTone.neutral);
        }
        if (days == 0) return (label: 'Ends today', tone: StatusTone.warning);
        if (days == 1) return (label: 'Ends tomorrow', tone: StatusTone.warning);
        if (days <= 7) {
          return (label: 'Ends in $days days', tone: StatusTone.warning);
        }
      }
    }

    if (end == null) return (label: 'From ${_day(start!)}', tone: StatusTone.neutral);
    if (start == null) return (label: 'Until ${_day(end)}', tone: StatusTone.neutral);
    return (label: '${_day(start)} – ${_day(end)}', tone: StatusTone.neutral);
  }

  /// "₹25K–₹60K": the budget at a size that fits a metric cell.
  String get compactBudget {
    if (budgetMin <= 0 && budgetMax <= 0) return 'Not set';
    if (budgetMin <= 0 || budgetMax <= 0 || budgetMin == budgetMax) {
      return Formatters.currencyCompact(budgetMax > 0 ? budgetMax : budgetMin);
    }
    return '${Formatters.currencyCompact(budgetMin)}–'
        '${Formatters.currencyCompact(budgetMax)}';
  }

  /// Applicants still waiting on a decision from the brand.
  int get awaitingReview =>
      recipients.where((r) => r.status == RecipientStatus.applied).length;

  int countIn(RecipientStatus stage) =>
      recipients.where((r) => r.status == stage).length;

  static String _day(DateTime value) => DateFormat('d MMM').format(value);
}
