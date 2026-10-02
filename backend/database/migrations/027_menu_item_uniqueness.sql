-- ---------------------------------------------------------------------
-- 027 — Stop the menu seed multiplying its own rows
--
-- `menu_items` had no uniqueness of any kind, and the seed that populates the
-- app drawer was written without an idempotency guard. Every install run
-- inserted the same seven items again: the table held 84 rows for 7 intended
-- entries, so the drawer would have rendered "My Bookings" twelve times over
-- once anything started reading it.
--
-- The unique key is (menu_id, parent_id, label): within one level of one menu,
-- two entries with the same visible label are always a mistake — the user
-- cannot tell them apart. It lets the same label exist under different parents
-- and in different menus, which is legitimate.
--
-- parent_id is nullable, and MySQL allows repeated NULLs in a unique key, so
-- top-level items would not be constrained at all. A generated column that
-- folds NULL to 0 gives them a real value to be unique on.
-- ---------------------------------------------------------------------

-- Keep the earliest of each duplicate group, and reparent any child that
-- pointed at one of the copies so the tree survives the cull.
UPDATE `menu_items` child
INNER JOIN `menu_items` dupe ON dupe.`id` = child.`parent_id`
INNER JOIN (
    SELECT MIN(`id`) AS `keep_id`, `menu_id`, `parent_id`, `label`
      FROM `menu_items`
     GROUP BY `menu_id`, `parent_id`, `label`
) AS survivor
        ON survivor.`menu_id` = dupe.`menu_id`
       AND survivor.`label`   = dupe.`label`
       AND (survivor.`parent_id` <=> dupe.`parent_id`)
   SET child.`parent_id` = survivor.`keep_id`
 WHERE dupe.`id` <> survivor.`keep_id`;

DELETE m FROM `menu_items` m
INNER JOIN `menu_items` keep
        ON keep.`menu_id` = m.`menu_id`
       AND keep.`label`   = m.`label`
       AND (keep.`parent_id` <=> m.`parent_id`)
       AND keep.`id`      < m.`id`;

ALTER TABLE `menu_items`
  ADD COLUMN `parent_key` INT UNSIGNED
      AS (COALESCE(`parent_id`, 0)) STORED AFTER `parent_id`,
  ADD UNIQUE KEY `uq_menu_item_label` (`menu_id`, `parent_key`, `label`);
