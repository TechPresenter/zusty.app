-- ---------------------------------------------------------------------
-- 042 — Supporting photos on an onboarding slide
--
-- The intro screens are designed around a photo collage: one large hero and
-- two smaller creator portraits overlapping it. A slide could only carry one
-- `image_url`, so the other two were always the seeded placeholder pictures,
-- even on a slide whose hero the operator had uploaded. The collage was half
-- admin-managed and half random.
--
-- Two optional columns rather than a JSON list: the admin panel's resource
-- form already has an image field type (upload + preview), and two more of
-- those is the whole editing UI. The API folds all three into one ordered
-- `images` array, so the app never needs to know about the column split.
--
-- NULL means "no supporting photo". Every existing slide keeps rendering
-- exactly as before, so no backfill is needed.
-- ---------------------------------------------------------------------

ALTER TABLE `onboarding_slides`
  ADD COLUMN IF NOT EXISTS `image_url_2` VARCHAR(500) NULL
    COMMENT 'Second collage photo; optional'
    AFTER `image_url`,
  ADD COLUMN IF NOT EXISTS `image_url_3` VARCHAR(500) NULL
    COMMENT 'Third collage photo; optional'
    AFTER `image_url_2`;
