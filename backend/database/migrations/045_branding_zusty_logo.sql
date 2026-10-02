-- =====================================================================
-- 045 — Branding: point the platform logo settings at the Zusty artwork
--
-- The branding settings pointed at files that were never shipped
-- (/uploads/branding/zuste-logo.png, zuste-logo-dark.png, favicon.png), so
-- the logo URL returned 404 everywhere it was read: GET /api/v1/app/config
-- (the app's force-update screen) and the Settings → Branding preview.
--
-- The new files live in backend/public/uploads/branding/ under NEW names.
-- /uploads/ is served with a 30-day cache, so reusing an old URL for new
-- artwork would keep showing the old file to anyone who had it cached.
--
-- Guarded: a value an operator has already changed (an uploaded logo of
-- their own) is left alone. Only the old shipped defaults, or an empty
-- value, are replaced.
-- =====================================================================

UPDATE `settings`
   SET `value` = '/uploads/branding/zusty-logo.png'
 WHERE `group_key` = 'branding' AND `setting_key` = 'logo_url'
   AND (`value` IS NULL OR `value` IN ('', '/uploads/branding/zuste-logo.png'));

UPDATE `settings`
   SET `value` = '/uploads/branding/zusty-logo-dark.png'
 WHERE `group_key` = 'branding' AND `setting_key` = 'logo_dark_url'
   AND (`value` IS NULL OR `value` IN ('', '/uploads/branding/zuste-logo-dark.png'));

UPDATE `settings`
   SET `value` = '/uploads/branding/zusty-favicon.png'
 WHERE `group_key` = 'branding' AND `setting_key` = 'favicon_url'
   AND (`value` IS NULL OR `value` IN ('', '/uploads/branding/favicon.png'));
