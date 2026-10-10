import 'package:flutter_test/flutter_test.dart';

import 'package:zuste/models/campaign.dart';
import 'package:zuste/shared/widgets/campaign_card.dart';
import 'package:zuste/shared/widgets/status_chip.dart';

/// The opportunities board must never call a brief "Open" while its date line
/// says "Ended …": the store screenshots showed exactly that pair.
void main() {
  final today = DateTime.now();
  final day = DateTime(today.year, today.month, today.day);

  Campaign brief({required CampaignStatus status, DateTime? endsOn}) => Campaign(
        id: 1,
        code: 'ZC100001',
        title: 'Millet snack taste test',
        description: 'Two reels.',
        status: status,
        endsOn: endsOn,
      );

  group('boardStatus', () {
    test('an open brief with time left is Open', () {
      final campaign = brief(
        status: CampaignStatus.open,
        endsOn: day.add(const Duration(days: 20)),
      );

      expect(campaign.boardStatus.label, 'Open');
      expect(campaign.boardStatus.tone, StatusTone.success);
      expect(campaign.deadline.label, startsWith('Apply by'));
    });

    test('an open brief past its end date says so instead of Open', () {
      final campaign = brief(
        status: CampaignStatus.open,
        endsOn: day.subtract(const Duration(days: 13)),
      );

      expect(campaign.boardStatus.label, 'Past end date');
      expect(campaign.boardStatus.tone, StatusTone.warning);
      expect(campaign.deadline.label, startsWith('Ended'));
      // The brand has not closed it, so the server still takes applications.
      expect(campaign.isAcceptingApplications, isTrue);
    });

    test('an open brief that ends today is still Open', () {
      final campaign = brief(status: CampaignStatus.open, endsOn: day);

      expect(campaign.boardStatus.label, 'Open');
      expect(campaign.deadline.label, 'Ends today');
    });

    test('an open brief with no end date is Open', () {
      final campaign = brief(status: CampaignStatus.open);

      expect(campaign.boardStatus.label, 'Open');
      expect(campaign.deadline.label, 'Flexible timeline');
    });

    test('a running brief keeps its own label whatever the date', () {
      final campaign = brief(
        status: CampaignStatus.inProgress,
        endsOn: day.subtract(const Duration(days: 3)),
      );

      expect(campaign.boardStatus.label, 'In progress');
    });
  });
}
