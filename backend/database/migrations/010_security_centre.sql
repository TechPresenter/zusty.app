-- =====================================================================
-- Zuste+ Influencer Marketplace — Migration 010
-- Security centre: widen the stored two-factor secret.
--
-- `two_factor_secret` was sized for a raw base32 secret (32 characters).
-- The secret is stored encrypted with AES-256-GCM and base64-encoded, which
-- takes ~80 characters, so enrolment failed with a truncation error. The
-- column now holds the ciphertext with room for a longer secret or a future
-- envelope format.
--
-- Purely additive: widening a VARCHAR preserves every existing value.
-- =====================================================================

SET NAMES utf8mb4;

ALTER TABLE `admins`
  MODIFY COLUMN `two_factor_secret` VARCHAR(255) NULL
    COMMENT 'AES-256-GCM ciphertext of the base32 TOTP secret';
