-- ---------------------------------------------------------------------
-- 028 — Give menu items timestamps so an edit can invalidate a cache
--
-- `menu_items` carried no created_at or updated_at, alone among the
-- admin-managed content tables. That is invisible while nothing reads the
-- table, and becomes a correctness bug the moment something does: /app/config
-- is served with an ETag built from row counts and MAX(updated_at), so
-- renaming an item or moving it changes neither, every revalidating client
-- gets a 304, and the admin's edit never reaches a single installed app.
--
-- MySQL maintains both columns itself, so the admin controller needs no
-- change and no future write path can forget to stamp them.
-- ---------------------------------------------------------------------

ALTER TABLE `menu_items`
  ADD COLUMN `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ADD COLUMN `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP;

-- Existing rows predate the column; date them from their menu so the first
-- ETag is stable rather than jumping to "now" on every deploy.
UPDATE `menu_items` i
INNER JOIN `menus` m ON m.`id` = i.`menu_id`
   SET i.`created_at` = m.`created_at`;
