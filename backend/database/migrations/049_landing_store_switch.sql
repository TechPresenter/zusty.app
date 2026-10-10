-- ---------------------------------------------------------------------
-- 049 - Landing page: a switch for the Google Play link, and sitemap
--       sources the website does not serve.
--
--   1. The website's landing page (GET /) links to app.android_store_url.
--      That setting is also the app's update link, so it is filled in long
--      before the Play listing is public, and Google Play answers it with
--      404 until then. The page links to Google Play only while this switch
--      is on; while it is off it shows "Coming soon on Google Play". Turn it
--      on in Admin -> Settings -> App when the listing is live.
--
--   2. The website serves the landing page and the legal pages only. Blog
--      posts, blog and marketplace categories and creator profiles live in
--      the app, so a generated sitemap.xml must not list them (every such
--      URL would 404). Admin -> SEO -> Sitemap can turn a source back on
--      once the website has those pages.
--
-- Re-runnable: INSERT IGNORE never overwrites an operator's choice.
-- ---------------------------------------------------------------------

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

INSERT IGNORE INTO `settings` (`group_key`, `setting_key`, `value`, `type`, `label`, `description`, `is_public`, `is_encrypted`, `sort_order`) VALUES
  ('app', 'android_store_live', '0', 'boolean', 'Play listing is live',
   'The website links to Google Play only when this is on; until then it shows Coming soon.', 1, 0, 10);

INSERT IGNORE INTO `settings` (`group_key`, `setting_key`, `value`, `type`, `label`, `description`, `is_public`, `is_encrypted`, `sort_order`) VALUES
  ('seo', 'sitemap_include_blogs', '0', 'boolean', 'Sitemap: include blog posts',
   'Publish /blog/{slug} URLs in sitemap.xml. Off while the website has no blog pages.', 0, 0, 100),
  ('seo', 'sitemap_include_blog_categories', '0', 'boolean', 'Sitemap: include blog categories',
   'Publish /blog/category/{slug} URLs in sitemap.xml. Off while the website has no blog pages.', 0, 0, 100),
  ('seo', 'sitemap_include_categories', '0', 'boolean', 'Sitemap: include marketplace categories',
   'Publish /categories/{slug} URLs in sitemap.xml. Off while the website has no category pages.', 0, 0, 100),
  ('seo', 'sitemap_include_creators', '0', 'boolean', 'Sitemap: include creator profiles',
   'Publish /creators/{handle} URLs in sitemap.xml. Off while the website has no creator pages.', 0, 0, 100);
