-- ---------------------------------------------------------------------
-- 017 — De-duplicate homepage_sections
--
-- Renumbered from 015: `015_automation_runner.sql` already held that version in
-- the ledger, which is keyed on the prefix — so as 015 this file was never
-- applied and the duplicate rails were still being served.
--
-- The section seed was applied twice, leaving two identical rows for each
-- rail. Because the API now renders the home screen straight from this table,
-- every rail was appearing twice in the apps.
--
-- Keeps the lowest id of each duplicate group, then adds the unique key that
-- makes a repeat seed idempotent instead of additive.
-- ---------------------------------------------------------------------

DELETE s FROM `homepage_sections` s
  JOIN (
    SELECT MIN(`id`) AS keep_id, `title`, `section_type`
      FROM `homepage_sections`
     GROUP BY `title`, `section_type`
    HAVING COUNT(*) > 1
  ) d
    ON s.`title` = d.`title`
   AND s.`section_type` = d.`section_type`
   AND s.`id` <> d.keep_id;

-- Prevents the same rail being seeded twice again.
--
-- IF NOT EXISTS because while this file sat shadowed under version 015 the key
-- was added to at least one database by hand. A migration must be safe to apply
-- to a database that has already seen its effect, or renumbering it strands the
-- schema it was meant to fix.
ALTER TABLE `homepage_sections`
  ADD UNIQUE KEY IF NOT EXISTS `uq_homepage_section` (`title`, `section_type`);
