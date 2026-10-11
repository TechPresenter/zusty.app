import '../core/utils/json.dart';

/// Profile completion and what it unlocks (`GET /me/creator/completion`).
///
/// The sections, their weights and the thresholds are all admin-configured
/// rows, not constants — a section the operator adds appears here with no app
/// release. Nothing in this file names a section or a percentage.
class CreatorCompletion {
  const CreatorCompletion({
    this.overall = 0,
    this.requiredPercent = 0,
    this.status = '',
    this.sections = const [],
    this.gates = const [],
  });

  /// Weighted percentage across every section.
  final int overall;

  /// What the operator requires before a profile counts as complete.
  final int requiredPercent;

  /// The creator's review status (`draft`, `pending_review`, `approved`, …).
  final String status;

  final List<CompletionSection> sections;
  final List<FeatureGate> gates;

  factory CreatorCompletion.fromJson(Map<String, dynamic> json) {
    final completion = Json.map(json['completion']);

    // `sections` arrives keyed by section name rather than as a list, so it
    // stays stable if the server reorders them. Order is restored below.
    final raw = Json.map(completion['sections']);
    final sections = raw.values
        .whereType<Map>()
        .map((value) => CompletionSection.fromJson(Map<String, dynamic>.from(value)))
        .toList()
      // Incomplete first: this list exists to tell a creator what to do next,
      // and burying the gaps under the finished sections defeats it.
      ..sort((a, b) {
        if (a.complete != b.complete) return a.complete ? 1 : -1;
        return b.weight.compareTo(a.weight);
      });

    return CreatorCompletion(
      overall: Json.integer(completion['overall']),
      requiredPercent: Json.integer(completion['required_percent']),
      status: Json.str(completion['status']),
      sections: sections,
      gates: Json.list(json['gates'], FeatureGate.fromJson),
    );
  }

  bool get meetsRequirement => overall >= requiredPercent;

  /// Sections still worth the creator's attention. A section with no rule
  /// that applies has nothing in it to finish, so it is never one of them.
  List<CompletionSection> get incomplete => sections
      .where((section) => section.applicable && !section.complete)
      .toList();

  /// Features the creator has not unlocked yet, most nearly-reachable first —
  /// so the prompt is "you are 6% from being featured", not a wall of locks.
  List<FeatureGate> get locked {
    final items = gates.where((gate) => gate.gated && !gate.allowed).toList()
      ..sort((a, b) => a.required.compareTo(b.required));

    return items;
  }
}

/// One scoring section — basic details, contact, social, portfolio, KYC, bio,
/// gallery, pricing, categories, verification, or anything added later.
class CompletionSection {
  const CompletionSection({
    required this.section,
    required this.label,
    this.description = '',
    this.icon,
    this.color,
    this.percent = 0,
    this.weight = 0,
    this.rulesTotal = 0,
    this.rulesMet = 0,
    this.mandatoryMissing = 0,
    this.missing = const [],
    this.missingKeys = const [],
    this.applicable = true,
    this.complete = false,
  });

  final String section;
  final String label;
  final String description;
  final String? icon;
  final String? color;
  final int percent;

  /// How much this section contributes to the overall figure. Used to order
  /// the gaps by what would move the number most.
  final int weight;

  final int rulesTotal;
  final int rulesMet;

  /// Unmet rules the operator marked mandatory. A section can be at 80% and
  /// still block the profile if one of these is outstanding.
  final int mandatoryMissing;

  /// What is actually missing, in the operator's own wording.
  final List<CompletionRule> missing;

  /// The `rule_key` of every unmet rule, in the operator's order.
  /// `GET /me/creator/completion` sends `missing` as these keys; an endpoint
  /// that sends whole rules (see [missing]) fills this from them.
  final List<String> missingKeys;

  /// False for a section with no rule that applies right now: none set up,
  /// or every one of them needing a text message while SMS is off. Read from
  /// the server's `applicable`, or from `rules_total` where it is not sent.
  final bool applicable;

  final bool complete;

  factory CompletionSection.fromJson(Map<String, dynamic> json) {
    final missing = Json.list(json['missing'], CompletionRule.fromJson);
    final raw = json['missing'];

    return CompletionSection(
      section: Json.str(json['section']),
      label: Json.str(json['label']),
      description: Json.str(json['description']),
      icon: Json.strOrNull(json['icon']),
      color: Json.strOrNull(json['color']),
      percent: Json.integer(json['percent']),
      weight: Json.integer(json['weight']),
      rulesTotal: Json.integer(json['rules_total']),
      rulesMet: Json.integer(json['rules_met']),
      mandatoryMissing: Json.integer(json['mandatory_missing']),
      missing: missing,
      missingKeys: missing.isNotEmpty
          ? missing.map((rule) => rule.ruleKey).toList()
          : raw is List
          ? raw.whereType<String>().where((key) => key.isNotEmpty).toList()
          : const [],
      applicable: Json.boolean(
        json['applicable'],
        Json.integer(json['rules_total'], 1) > 0,
      ),
      complete: Json.boolean(json['complete']),
    );
  }

  bool get blocksProfile => mandatoryMissing > 0;
}

/// One unmet rule inside a section.
class CompletionRule {
  const CompletionRule({
    this.ruleKey = '',
    this.label = '',
    this.hint = '',
    this.isMandatory = false,
  });

  final String ruleKey;
  final String label;
  final String hint;
  final bool isMandatory;

  factory CompletionRule.fromJson(Map<String, dynamic> json) => CompletionRule(
        ruleKey: Json.str(json['rule_key']),
        label: Json.str(json['label']),
        hint: Json.str(json['hint'] ?? json['description']),
        isMandatory: Json.boolean(json['is_mandatory']),
      );
}

/// A platform feature gated behind a completion threshold.
class FeatureGate {
  const FeatureGate({
    required this.featureKey,
    required this.name,
    this.description = '',
    this.message = '',
    this.gated = false,
    this.allowed = true,
    this.percent = 0,
    this.required = 0,
    this.reasons = const [],
    this.hasOverride = false,
  });

  final String featureKey;
  final String name;
  final String description;

  /// The operator's own wording for why this is worth unlocking.
  final String message;

  /// Whether a gate is configured at all. An ungated feature is always allowed.
  final bool gated;

  final bool allowed;
  final int percent;
  final int required;

  /// Exactly what is standing in the way, sentence by sentence, from the
  /// server — so the app never has to compose an explanation of its own.
  final List<String> reasons;

  /// An administrator granted this regardless of the score.
  final bool hasOverride;

  factory FeatureGate.fromJson(Map<String, dynamic> json) => FeatureGate(
        featureKey: Json.str(json['feature_key']),
        name: Json.str(json['name']),
        description: Json.str(json['description']),
        message: Json.str(json['message']),
        gated: Json.boolean(json['gated']),
        allowed: Json.boolean(json['allowed'], true),
        percent: Json.integer(json['percent']),
        required: Json.integer(json['required']),
        reasons: Json.strings(json['reasons']),
        hasOverride: json['override'] != null,
      );

  /// How far off the creator is, never negative.
  int get shortfall => required - percent > 0 ? required - percent : 0;
}
