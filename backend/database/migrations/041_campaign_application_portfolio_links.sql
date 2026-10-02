-- ---------------------------------------------------------------------
-- 041 — Portfolio links on a campaign application
--
-- A creator applying to a brief could send a rate and a pitch, and nothing
-- else. Brands asked "show me something like this you've made", and the only
-- place a creator could answer was inside the 2,000-character pitch, as bare
-- URLs the brand had to copy out by hand.
--
-- `campaign_applications` has no JSON or metadata column to borrow (checked
-- against the live schema: the only free-text columns are `pitch` and
-- `decline_reason`, both with a fixed meaning), so this adds one.
--
-- Stored as a JSON array of http(s) URL strings, at most five, validated and
-- de-duplicated by BrandCampaignService before it is written. LONGTEXT rather
-- than JSON to match `campaigns`.`reference_urls` and `requirements` — on
-- MariaDB the two are the same type, and one convention is easier to read.
--
-- NULL means "none supplied", which is every application made before this
-- migration, so no backfill is needed.
-- ---------------------------------------------------------------------

ALTER TABLE `campaign_applications`
  ADD COLUMN IF NOT EXISTS `portfolio_links` LONGTEXT NULL
    COMMENT 'JSON array of up to 5 http(s) URLs the creator attached to the application'
    AFTER `pitch`;
