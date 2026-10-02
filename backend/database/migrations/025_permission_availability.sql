-- ---------------------------------------------------------------------
-- 025 — Mark permissions that gate nothing
--
-- Four permissions exist, are granted to roles, and are checked NOWHERE in
-- app/ or routes/: creators.delete, creators.edit, users.create and
-- users.impersonate. There is no admin route that deletes a creator, edits a
-- creator profile, creates a user or impersonates one — routes/admin.php
-- exposes only users.view/edit/delete-account and
-- creators.view/approve/verify.
--
-- The role editor lists all 84 permissions unfiltered, so an operator can tick
-- "creators.edit" for the Manager role, save it, and get nothing — with no way
-- to tell that the capability does not exist.
--
-- Rather than delete them (they read as planned work), they are flagged
-- unavailable. PermissionService::grouped() hides unavailable rows from the
-- role editor, so nobody can grant a capability that has no effect. Setting
-- `is_available = 1` is all that is needed once the feature ships.
-- ---------------------------------------------------------------------

ALTER TABLE `permissions`
  ADD COLUMN `is_available` TINYINT(1) NOT NULL DEFAULT 1
    COMMENT '0 = defined but nothing checks it; hidden from the role editor'
    AFTER `sort_order`;

UPDATE `permissions`
   SET `is_available` = 0
 WHERE `slug` IN (
     'creators.delete',
     'creators.edit',
     'users.create',
     'users.impersonate'
 );

-- The existing grants are left in place deliberately. Revoking them would be a
-- silent permission change for roles that already show them as granted, and
-- they confer nothing while `is_available = 0` keeps them out of the editor.
