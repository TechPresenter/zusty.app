-- ---------------------------------------------------------------------
-- 030 — Let a soft-deleted account release its email, phone and Firebase UID
--
-- Every identifier lookup in the application already ignores soft-deleted
-- rows:
--
--   AuthService::findByIdentifier          (WHERE `email`/`phone` … AND `deleted_at` IS NULL)
--   AuthService::login / passwordReset     (same predicate)
--   AuthService::findByFirebaseUid         (WHERE `firebase_uid` … AND `deleted_at` IS NULL)
--   AuthService::assertIdentifiersAvailable(the registration guard, same predicate)
--
-- The unique indexes did not: `uq_users_email`, `uq_users_phone` and
-- `uq_users_firebase` were global. So the guard cleared a registration the
-- index then refused, and POST /api/v1/auth/register answered
--
--     500  SQLSTATE[23000] … 1062 Duplicate entry '…' for key 'uq_users_email'
--
-- to anyone re-registering after deleting their account — an unhandled
-- PDOException, not a validation error.
--
-- This aligns the indexes with the predicate the code has always used instead
-- of the reverse, because the reverse would strand a user who deletes their
-- account: their address would stay reserved by a row nothing can log into,
-- with no self-service way to get it back.
--
-- MariaDB has no partial indexes, so the constraint is expressed as a STORED
-- generated column that is NULL once the row is soft-deleted — and a unique
-- index ignores rows whose key contains NULL. Active rows are therefore still
-- globally unique; deleted rows drop out of the constraint entirely and any
-- number of them may share an identifier.
--
-- The columns are INVISIBLE (MariaDB 10.3+): `SELECT *` does not return them,
-- so AuthService::publicUser — which strips by blacklist — cannot leak them
-- into an API response, and no existing payload shape changes. Writes are
-- unaffected: Database::withoutGeneratedColumns() (app/Core/Database.php:392)
-- already drops generated columns from every insert and update.
--
-- The index names are reused so the 1062 message operators already know
-- ("… for key 'uq_users_email'") stays the same, and the raw columns keep a
-- plain index each so identifier lookups still resolve by index rather than
-- by table scan.
--
-- Concatenating the phone with its country code reproduces the old composite
-- key exactly: CONCAT() with a NULL phone yields NULL, so accounts without a
-- phone number remain unconstrained, as they were under
-- `uq_users_phone` (`phone_country_code`, `phone`).
--
-- This only ever loosens the constraints, so it cannot fail on existing data:
-- the indexes being replaced already guarantee there are no duplicates to
-- collide.
-- ---------------------------------------------------------------------

ALTER TABLE `users`
  ADD COLUMN `email_active` VARCHAR(191)
      AS (IF(`deleted_at` IS NULL, `email`, NULL)) STORED INVISIBLE
      COMMENT 'NULL once soft-deleted, so uq_users_email only binds live accounts',
  ADD COLUMN `phone_active` VARCHAR(27)
      AS (IF(`deleted_at` IS NULL, CONCAT(`phone_country_code`, `phone`), NULL)) STORED INVISIBLE
      COMMENT 'NULL once soft-deleted, so uq_users_phone only binds live accounts',
  ADD COLUMN `firebase_uid_active` VARCHAR(128)
      AS (IF(`deleted_at` IS NULL, `firebase_uid`, NULL)) STORED INVISIBLE
      COMMENT 'NULL once soft-deleted, so uq_users_firebase only binds live accounts';

ALTER TABLE `users`
  DROP INDEX `uq_users_email`,
  DROP INDEX `uq_users_phone`,
  DROP INDEX `uq_users_firebase`,
  ADD UNIQUE KEY `uq_users_email` (`email_active`),
  ADD UNIQUE KEY `uq_users_phone` (`phone_active`),
  ADD UNIQUE KEY `uq_users_firebase` (`firebase_uid_active`),
  ADD KEY `idx_users_email` (`email`),
  ADD KEY `idx_users_phone` (`phone_country_code`, `phone`),
  ADD KEY `idx_users_firebase` (`firebase_uid`);
