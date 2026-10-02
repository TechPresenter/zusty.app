-- ---------------------------------------------------------------------
-- 040: AI features for the app, configured from Settings -> AI.
--
-- The provider catalogue (`ai_providers`) becomes the only place an AI key
-- lives. The single-provider `ai.provider` / `ai.api_key` / `ai.default_model`
-- settings it replaced are carried across to their provider first and then
-- removed, so Settings never shows a second, disconnected key field.
-- ---------------------------------------------------------------------

-- A key or model saved through the old form moves to its provider. A key the
-- provider already holds is never overwritten. Both columns hold the same
-- Crypto::encrypt() ciphertext, so the value is copied as-is.
UPDATE `ai_providers` p
  JOIN `settings` sp ON sp.`group_key` = 'ai' AND sp.`setting_key` = 'provider'
  JOIN `settings` sk ON sk.`group_key` = 'ai' AND sk.`setting_key` = 'api_key'
   SET p.`api_key` = sk.`value`
 WHERE p.`code` = sp.`value`
   AND (p.`api_key` IS NULL OR p.`api_key` = '')
   AND sk.`value` IS NOT NULL AND sk.`value` <> '';

UPDATE `ai_providers` p
  JOIN `settings` sp ON sp.`group_key` = 'ai' AND sp.`setting_key` = 'provider'
  JOIN `settings` sm ON sm.`group_key` = 'ai' AND sm.`setting_key` = 'default_model'
   SET p.`default_model` = sm.`value`
 WHERE p.`code` = sp.`value`
   AND (p.`default_model` IS NULL OR p.`default_model` = '')
   AND sm.`value` IS NOT NULL AND sm.`value` <> '';

DELETE FROM `settings`
 WHERE `group_key` = 'ai' AND `setting_key` IN ('provider', 'api_key', 'default_model');

-- Per-feature switches for the app and the per-account allowance. Existing
-- values (enabled, monthly_budget) are kept; only their wording changes.
INSERT INTO `settings` (`group_key`, `setting_key`, `value`, `type`, `label`, `description`, `is_public`, `is_encrypted`, `sort_order`) VALUES
  ('ai','enabled','0','boolean','AI features enabled','Master switch. While it is off the app hides every AI button and the API refuses AI requests.',0,0,1),
  ('ai','feature_bio_writer','1','boolean','Bio writer for creators','Creators can draft their profile bio with AI.',0,0,10),
  ('ai','feature_campaign_brief','1','boolean','Campaign brief writer for brands','Brands can turn a one-line idea into a full campaign brief.',0,0,11),
  ('ai','feature_creator_match','1','boolean','Creator matching for brands','Brands describe a campaign and get the best-fitting creators, each with a reason.',0,0,12),
  ('ai','daily_limit_per_user','20','integer','Requests per account per day','Across all AI features. Stops one account running up the bill.',0,0,20),
  ('ai','monthly_budget','50','decimal','Monthly budget (USD)','AI requests stop for the rest of the month once spend reaches this. 0 means no limit.',0,0,21)
ON DUPLICATE KEY UPDATE
  `type` = VALUES(`type`), `label` = VALUES(`label`),
  `description` = VALUES(`description`), `sort_order` = VALUES(`sort_order`);

-- An unused feature flag for the same thing would be a second switch that
-- does nothing. Settings -> AI is now the only control.
DELETE FROM `feature_flags` WHERE `flag_key` = 'ai_creator_match';

-- OpenAI's current models, priced per million tokens from the Standard tier of
-- developers.openai.com/api/docs/pricing (October 2026). Unknown limits stay
-- NULL rather than guessed.
INSERT INTO `ai_provider_models`
  (`provider_id`, `model_code`, `label`, `input_cost_per_mtok`, `output_cost_per_mtok`,
   `context_window`, `max_output_tokens`, `supports_vision`, `supports_tools`, `is_active`, `sort_order`)
SELECT p.`id`, m.`model_code`, m.`label`, m.`input_cost`, m.`output_cost`,
       m.`context_window`, m.`max_output`, 0, 1, 1, m.`sort_order`
  FROM `ai_providers` p
  JOIN (
          SELECT 'gpt-6-luna'   AS `model_code`, 'GPT-6 Luna (fastest, lowest cost)' AS `label`,
                 0.1000 AS `input_cost`, 0.5000 AS `output_cost`, NULL AS `context_window`, NULL AS `max_output`, 10 AS `sort_order`
UNION ALL SELECT 'gpt-6.1-sol',  'GPT-6.1 Sol (balanced)',            2.0000, 10.0000, NULL,    NULL,  20
UNION ALL SELECT 'gpt-6-astra',  'GPT-6 Astra (most capable)',       10.0000, 50.0000, NULL,    NULL,  30
UNION ALL SELECT 'gpt-5-mini',   'GPT-5 mini',                        0.2500,  2.0000, 400000, 128000, 40
UNION ALL SELECT 'gpt-4.1-mini', 'GPT-4.1 mini',                      0.4000,  1.6000, 1047576, 32768, 50
       ) m
 WHERE p.`code` = 'openai'
ON DUPLICATE KEY UPDATE
  `label`                = VALUES(`label`),
  `input_cost_per_mtok`  = VALUES(`input_cost_per_mtok`),
  `output_cost_per_mtok` = VALUES(`output_cost_per_mtok`),
  `context_window`       = VALUES(`context_window`),
  `max_output_tokens`    = VALUES(`max_output_tokens`);

UPDATE `ai_providers`
   SET `default_model` = 'gpt-6-luna'
 WHERE `code` = 'openai' AND (`default_model` IS NULL OR `default_model` = '');

-- Creator matching now returns structured results the app can render. The
-- output format is appended by AiService, so the template only describes the
-- judgement. Only the seeded wording is replaced; an edited prompt is left be.
UPDATE `ai_prompts`
   SET `user_prompt` = 'Brand brief:\n{{brief}}\n\nBudget: {{currency}}{{budget}}\nPlatform: {{platform}}\nCategory: {{category}}\n\nCandidate creators, one per line, each starting with its id:\n{{candidates}}\n\nPick the creators who fit this brief best and give each a fit score out of 100 and one short reason a brand manager would find useful.',
       `version`     = `version` + 1,
       `updated_at`  = NOW()
 WHERE `code` = 'creator_match'
   AND `user_prompt` LIKE '%Rank the five best matches%';

-- The per-account daily allowance counts today's calls for one requester.
ALTER TABLE `ai_logs`
  ADD INDEX IF NOT EXISTS `idx_ail_requester` (`requested_by_type`, `requested_by_id`, `created_at`);
