-- =====================================================================
-- Zusty Influencer Marketplace — Seed 004
-- AI provider catalogue.
--
-- Every provider ships DISABLED with NO API key. Nothing here can send a
-- request or spend money until an administrator pastes a key in
-- Settings → AI → Providers and enables the row.
--
-- Model pricing is seeded ONLY for Anthropic, whose rates are documented and
-- verifiable. Rows for the other providers are deliberately left without a
-- model list: an invented price would silently produce wrong cost reporting
-- in ai_logs, which is worse than an empty catalogue the operator fills in.
-- The Providers screen has an "Add model" form for exactly that.
-- =====================================================================

SET NAMES utf8mb4;

INSERT INTO `ai_providers`
  (`code`, `name`, `adapter`, `description`, `api_base_url`, `api_version`, `default_model`,
   `is_enabled`, `is_default`, `priority`, `timeout_seconds`, `max_retries`)
VALUES
  ('anthropic', 'Anthropic', 'anthropic',
   'Claude models. Send the x-api-key header and the pinned anthropic-version.',
   'https://api.anthropic.com/v1', '2023-06-01', 'claude-opus-5', 0, 1, 10, 120, 2),

  ('openai', 'OpenAI', 'openai',
   'Chat Completions and Responses APIs.',
   'https://api.openai.com/v1', NULL, 'gpt-6-luna', 0, 0, 20, 60, 2),

  ('google', 'Google Gemini', 'google',
   'Gemini via the Generative Language API.',
   'https://generativelanguage.googleapis.com/v1beta', NULL, NULL, 0, 0, 30, 60, 2),

  ('azure_openai', 'Azure OpenAI', 'azure_openai',
   'Set the base URL to your own resource endpoint and the deployment name as the model.',
   NULL, NULL, NULL, 0, 0, 40, 60, 2),

  ('mistral', 'Mistral', 'openai_compatible',
   'OpenAI-compatible wire format.',
   'https://api.mistral.ai/v1', NULL, NULL, 0, 0, 50, 60, 2),

  ('groq', 'Groq', 'openai_compatible',
   'Low-latency inference, OpenAI-compatible wire format.',
   'https://api.groq.com/openai/v1', NULL, NULL, 0, 0, 60, 60, 2),

  ('openrouter', 'OpenRouter', 'openai_compatible',
   'Routes to many upstream models behind one key.',
   'https://openrouter.ai/api/v1', NULL, NULL, 0, 0, 70, 60, 2)
ON DUPLICATE KEY UPDATE
  `name`         = VALUES(`name`),
  `adapter`      = VALUES(`adapter`),
  `description`  = VALUES(`description`),
  `api_base_url` = COALESCE(`ai_providers`.`api_base_url`, VALUES(`api_base_url`)),
  `api_version`  = COALESCE(`ai_providers`.`api_version`, VALUES(`api_version`));

-- ---------------------------------------------------------------------
-- Anthropic model catalogue.
--
-- Costs are US dollars per million tokens, matching how Anthropic publishes
-- them. Claude Sonnet 5 carries a lower introductory rate ($2.00 / $10.00)
-- through 2026-08-31; the standard rate is seeded instead so cost reporting
-- never understates spend once the introductory period ends.
-- ---------------------------------------------------------------------
INSERT INTO `ai_provider_models`
  (`provider_id`, `model_code`, `label`, `input_cost_per_mtok`, `output_cost_per_mtok`,
   `context_window`, `max_output_tokens`, `supports_vision`, `supports_tools`, `is_active`, `sort_order`)
SELECT p.`id`, m.`model_code`, m.`label`, m.`in_cost`, m.`out_cost`,
       m.`ctx`, m.`max_out`, m.`vision`, m.`tools`, 1, m.`sort_order`
FROM `ai_providers` p
JOIN (
  SELECT 'claude-opus-5'     AS `model_code`, 'Claude Opus 5'     AS `label`,  5.0000 AS `in_cost`, 25.0000 AS `out_cost`, 1000000 AS `ctx`, 128000 AS `max_out`, 1 AS `vision`, 1 AS `tools`, 10 AS `sort_order`
  UNION ALL SELECT 'claude-fable-5',    'Claude Fable 5',    10.0000, 50.0000, 1000000, 128000, 1, 1, 20
  UNION ALL SELECT 'claude-opus-4-8',   'Claude Opus 4.8',    5.0000, 25.0000, 1000000, 128000, 1, 1, 30
  UNION ALL SELECT 'claude-sonnet-5',   'Claude Sonnet 5',    3.0000, 15.0000, 1000000, 128000, 1, 1, 40
  UNION ALL SELECT 'claude-sonnet-4-6', 'Claude Sonnet 4.6',  3.0000, 15.0000, 1000000, 128000, 1, 1, 50
  UNION ALL SELECT 'claude-haiku-4-5',  'Claude Haiku 4.5',   1.0000,  5.0000,  200000,  64000, 1, 1, 60
) m
WHERE p.`code` = 'anthropic'
ON DUPLICATE KEY UPDATE
  `label`                = VALUES(`label`),
  `input_cost_per_mtok`  = VALUES(`input_cost_per_mtok`),
  `output_cost_per_mtok` = VALUES(`output_cost_per_mtok`),
  `context_window`       = VALUES(`context_window`),
  `max_output_tokens`    = VALUES(`max_output_tokens`);

-- ---------------------------------------------------------------------
-- OpenAI model catalogue.
--
-- US dollars per million tokens, from the Standard tier of
-- developers.openai.com/api/docs/pricing (October 2026). Limits OpenAI does
-- not publish on that page stay NULL rather than guessed.
-- ---------------------------------------------------------------------
INSERT INTO `ai_provider_models`
  (`provider_id`, `model_code`, `label`, `input_cost_per_mtok`, `output_cost_per_mtok`,
   `context_window`, `max_output_tokens`, `supports_vision`, `supports_tools`, `is_active`, `sort_order`)
SELECT p.`id`, m.`model_code`, m.`label`, m.`in_cost`, m.`out_cost`,
       m.`ctx`, m.`max_out`, 0, 1, 1, m.`sort_order`
FROM `ai_providers` p
JOIN (
  SELECT 'gpt-6-luna'   AS `model_code`, 'GPT-6 Luna (fastest, lowest cost)' AS `label`, 0.1000 AS `in_cost`, 0.5000 AS `out_cost`, NULL AS `ctx`, NULL AS `max_out`, 10 AS `sort_order`
  UNION ALL SELECT 'gpt-6.1-sol',  'GPT-6.1 Sol (balanced)',       2.0000, 10.0000, NULL,    NULL,  20
  UNION ALL SELECT 'gpt-6-astra',  'GPT-6 Astra (most capable)',  10.0000, 50.0000, NULL,    NULL,  30
  UNION ALL SELECT 'gpt-5-mini',   'GPT-5 mini',                   0.2500,  2.0000, 400000, 128000, 40
  UNION ALL SELECT 'gpt-4.1-mini', 'GPT-4.1 mini',                 0.4000,  1.6000, 1047576, 32768, 50
) m
WHERE p.`code` = 'openai'
ON DUPLICATE KEY UPDATE
  `label`                = VALUES(`label`),
  `input_cost_per_mtok`  = VALUES(`input_cost_per_mtok`),
  `output_cost_per_mtok` = VALUES(`output_cost_per_mtok`),
  `context_window`       = VALUES(`context_window`),
  `max_output_tokens`    = VALUES(`max_output_tokens`);

-- The single-provider settings this used to carry across were retired by
-- migration 040, which moves any stored key to its provider itself.
