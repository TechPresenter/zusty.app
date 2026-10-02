-- =====================================================================
-- Zuste+ Influencer Marketplace — Migration 014
-- Index `deleted_at` on every table the recycle bin sweeps.
--
-- The recycle bin asks three questions of each recyclable table, and all
-- three are `deleted_at IS NOT NULL`:
--
--   1. The per-type counters (ProductivityController::binTypes) run one
--      `SELECT COUNT(*) ... WHERE deleted_at IS NOT NULL` per type — sixteen
--      of them per cache miss.
--   2. The list query adds `ORDER BY deleted_at DESC`, which without an index
--      is a full scan plus a filesort.
--   3. The retention purge issues a `DELETE ... WHERE deleted_at IS NOT NULL
--      AND deleted_at < DATE_SUB(NOW(), INTERVAL ? DAY)` per table, taking row
--      locks across bookings, messages, media and reviews in one request.
--
-- Without an index every one of those is `type=ALL, key=NULL`. Four of the
-- tables (admins, bank_accounts, creators, users) carry `deleted_at` only as
-- a trailing column of a composite built for status/owner filtering, which
-- buys a covering full-index scan on the counter and nothing at all on the
-- list. With a leading `deleted_at` index each becomes a range scan bounded
-- by the number of deleted rows, and the filesort disappears.
--
-- Scope is deliberately the sixteen types in ProductivityController::RECYCLABLE.
-- `ai_providers`, `kyc_documents` and `kyc_profiles` also carry `deleted_at`
-- but are not recyclable, so nothing scans them this way.
--
-- The existing composites are left in place — they answer a different
-- predicate. The indexes are single-column on purpose: InnoDB already appends
-- the primary key to every secondary index, so `(deleted_at, id)` would be
-- redundant.
--
-- Purely additive, and re-runnable: `ADD INDEX IF NOT EXISTS` is a no-op when
-- the index is already there.
-- =====================================================================

SET NAMES utf8mb4;

ALTER TABLE `users`               ADD INDEX IF NOT EXISTS `idx_users_deleted` (`deleted_at`);
ALTER TABLE `creators`            ADD INDEX IF NOT EXISTS `idx_creators_deleted` (`deleted_at`);
ALTER TABLE `bookings`            ADD INDEX IF NOT EXISTS `idx_bookings_deleted` (`deleted_at`);
ALTER TABLE `campaigns`           ADD INDEX IF NOT EXISTS `idx_campaigns_deleted` (`deleted_at`);
ALTER TABLE `categories`          ADD INDEX IF NOT EXISTS `idx_categories_deleted` (`deleted_at`);
ALTER TABLE `blogs`               ADD INDEX IF NOT EXISTS `idx_blogs_deleted` (`deleted_at`);
ALTER TABLE `coupons`             ADD INDEX IF NOT EXISTS `idx_coupons_deleted` (`deleted_at`);
ALTER TABLE `popups`              ADD INDEX IF NOT EXISTS `idx_popups_deleted` (`deleted_at`);
ALTER TABLE `reviews`             ADD INDEX IF NOT EXISTS `idx_reviews_deleted` (`deleted_at`);
ALTER TABLE `media`               ADD INDEX IF NOT EXISTS `idx_media_deleted` (`deleted_at`);
ALTER TABLE `messages`            ADD INDEX IF NOT EXISTS `idx_messages_deleted` (`deleted_at`);
ALTER TABLE `marketing_campaigns` ADD INDEX IF NOT EXISTS `idx_marketing_campaigns_deleted` (`deleted_at`);
ALTER TABLE `creator_packages`    ADD INDEX IF NOT EXISTS `idx_creator_packages_deleted` (`deleted_at`);
ALTER TABLE `creator_portfolio`   ADD INDEX IF NOT EXISTS `idx_creator_portfolio_deleted` (`deleted_at`);
ALTER TABLE `bank_accounts`       ADD INDEX IF NOT EXISTS `idx_bank_accounts_deleted` (`deleted_at`);
ALTER TABLE `admins`              ADD INDEX IF NOT EXISTS `idx_admins_deleted` (`deleted_at`);
