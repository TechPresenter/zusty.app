-- ---------------------------------------------------------------------
-- 028 — CMS remainder: media, coupons, popups, support
--
-- 026 describes ten tables in its header but only ever emits the `blogs`
-- insert. This file supplies the rest, so the admin media library, coupon
-- engine, popup targeting and support desk all have content to render.
--
-- Ids
--   media                1401 - 1436
--   coupons              1501 - 1512
--   popups               1516 - 1520
--   support_tickets      1601 - 1616
--   coupon_redemptions   derived (INSERT ... SELECT over real bookings)
--
-- Every statement is re-runnable.
-- ---------------------------------------------------------------------

-- ---------------------------------------------------------------------
-- Media library
--
-- folder_id points at media_folders 1-5. Dimensions and byte sizes are
-- plausible for the stated mime so the admin grid's "2.1 MB · 1600x900"
-- labels are not nonsense.
-- ---------------------------------------------------------------------
INSERT INTO `media`
  (`id`, `disk`, `folder`, `folder_id`, `file_name`, `original_name`, `path`, `url`,
   `thumbnail_url`, `mime_type`, `extension`, `size_bytes`, `is_optimized`,
   `width`, `height`, `alt_text`, `uploader_type`, `uploader_id`, `created_at`)
VALUES
  (1401,'local','banners',1,'diwali-gifting-hero.jpg','Diwali Gifting Hero.jpg','uploads/banners/diwali-gifting-hero.jpg','https://picsum.photos/seed/zuste-media-1401/1600/900','https://picsum.photos/seed/zuste-media-1401/320/180','image/jpeg','jpg',412870,1,1600,900,'Diwali gifting campaign hero banner','admin',1,DATE_SUB(NOW(), INTERVAL 96 DAY)),
  (1402,'local','banners',1,'monsoon-skincare-hero.jpg','Monsoon Skincare Hero.jpg','uploads/banners/monsoon-skincare-hero.jpg','https://picsum.photos/seed/zuste-media-1402/1600/900','https://picsum.photos/seed/zuste-media-1402/320/180','image/jpeg','jpg',388214,1,1600,900,'Monsoon skincare campaign banner','admin',1,DATE_SUB(NOW(), INTERVAL 142 DAY)),
  (1403,'local','banners',1,'creator-payout-promo.png','Creator Payout Promo.png','uploads/banners/creator-payout-promo.png','https://picsum.photos/seed/zuste-media-1403/1600/900','https://picsum.photos/seed/zuste-media-1403/320/180','image/png','png',624180,1,1600,900,'Faster creator payouts announcement','admin',1,DATE_SUB(NOW(), INTERVAL 61 DAY)),
  (1404,'local','banners',1,'festive-brief-board.jpg','Festive Brief Board.jpg','uploads/banners/festive-brief-board.jpg','https://picsum.photos/seed/zuste-media-1404/1600/900','https://picsum.photos/seed/zuste-media-1404/320/180','image/jpeg','jpg',401992,1,1600,900,'Festive season brief board banner','admin',1,DATE_SUB(NOW(), INTERVAL 33 DAY)),

  (1405,'local','blog',2,'micro-influencer-guide.jpg','Micro Influencer Guide.jpg','uploads/blog/micro-influencer-guide.jpg','https://picsum.photos/seed/zuste-media-1405/1200/675','https://picsum.photos/seed/zuste-media-1405/320/180','image/jpeg','jpg',276410,1,1200,675,'Cover art for the micro-influencer guide','admin',1,DATE_SUB(NOW(), INTERVAL 210 DAY)),
  (1406,'local','blog',2,'gst-invoices-explained.jpg','GST Invoices Explained.jpg','uploads/blog/gst-invoices-explained.jpg','https://picsum.photos/seed/zuste-media-1406/1200/675','https://picsum.photos/seed/zuste-media-1406/320/180','image/jpeg','jpg',254118,1,1200,675,'Cover art for the GST invoicing explainer','admin',1,DATE_SUB(NOW(), INTERVAL 188 DAY)),
  (1407,'local','blog',2,'reel-rate-card-2026.jpg','Reel Rate Card 2026.jpg','uploads/blog/reel-rate-card-2026.jpg','https://picsum.photos/seed/zuste-media-1407/1200/675','https://picsum.photos/seed/zuste-media-1407/320/180','image/jpeg','jpg',301774,1,1200,675,'Cover art for the 2026 Reel rate card','admin',1,DATE_SUB(NOW(), INTERVAL 121 DAY)),
  (1408,'local','blog',2,'brief-writing-checklist.jpg','Brief Writing Checklist.jpg','uploads/blog/brief-writing-checklist.jpg','https://picsum.photos/seed/zuste-media-1408/1200/675','https://picsum.photos/seed/zuste-media-1408/320/180','image/jpeg','jpg',268930,1,1200,675,'Cover art for the campaign brief checklist','admin',1,DATE_SUB(NOW(), INTERVAL 74 DAY)),
  (1409,'local','blog',2,'escrow-explained.jpg','Escrow Explained.jpg','uploads/blog/escrow-explained.jpg','https://picsum.photos/seed/zuste-media-1409/1200/675','https://picsum.photos/seed/zuste-media-1409/320/180','image/jpeg','jpg',241660,1,1200,675,'Cover art for the escrow explainer','admin',1,DATE_SUB(NOW(), INTERVAL 45 DAY)),

  (1410,'local','categories',3,'cat-lifestyle.jpg','Lifestyle.jpg','uploads/categories/cat-lifestyle.jpg','https://picsum.photos/seed/zuste-media-1410/800/800','https://picsum.photos/seed/zuste-media-1410/200/200','image/jpeg','jpg',162044,1,800,800,'Lifestyle category tile','admin',1,DATE_SUB(NOW(), INTERVAL 300 DAY)),
  (1411,'local','categories',3,'cat-fashion.jpg','Fashion.jpg','uploads/categories/cat-fashion.jpg','https://picsum.photos/seed/zuste-media-1411/800/800','https://picsum.photos/seed/zuste-media-1411/200/200','image/jpeg','jpg',158912,1,800,800,'Fashion category tile','admin',1,DATE_SUB(NOW(), INTERVAL 300 DAY)),
  (1412,'local','categories',3,'cat-food.jpg','Food.jpg','uploads/categories/cat-food.jpg','https://picsum.photos/seed/zuste-media-1412/800/800','https://picsum.photos/seed/zuste-media-1412/200/200','image/jpeg','jpg',171338,1,800,800,'Food category tile','admin',1,DATE_SUB(NOW(), INTERVAL 300 DAY)),
  (1413,'local','categories',3,'cat-fitness.jpg','Fitness.jpg','uploads/categories/cat-fitness.jpg','https://picsum.photos/seed/zuste-media-1413/800/800','https://picsum.photos/seed/zuste-media-1413/200/200','image/jpeg','jpg',166201,1,800,800,'Fitness category tile','admin',1,DATE_SUB(NOW(), INTERVAL 299 DAY)),
  (1414,'local','categories',3,'cat-tech.jpg','Tech.jpg','uploads/categories/cat-tech.jpg','https://picsum.photos/seed/zuste-media-1414/800/800','https://picsum.photos/seed/zuste-media-1414/200/200','image/jpeg','jpg',149877,1,800,800,'Tech category tile','admin',1,DATE_SUB(NOW(), INTERVAL 299 DAY)),
  (1415,'local','categories',3,'cat-beauty.jpg','Beauty.jpg','uploads/categories/cat-beauty.jpg','https://picsum.photos/seed/zuste-media-1415/800/800','https://picsum.photos/seed/zuste-media-1415/200/200','image/jpeg','jpg',174503,1,800,800,'Beauty category tile','admin',1,DATE_SUB(NOW(), INTERVAL 298 DAY)),

  (1416,'local','brand-assets',4,'bloom-skincare-logo.png','Bloom Skincare Logo.png','uploads/brand-assets/bloom-skincare-logo.png','https://picsum.photos/seed/zuste-media-1416/512/512','https://picsum.photos/seed/zuste-media-1416/128/128','image/png','png',48210,1,512,512,'Bloom Skincare logo','user',1001,DATE_SUB(NOW(), INTERVAL 260 DAY)),
  (1417,'local','brand-assets',4,'urban-crumb-logo.png','Urban Crumb Logo.png','uploads/brand-assets/urban-crumb-logo.png','https://picsum.photos/seed/zuste-media-1417/512/512','https://picsum.photos/seed/zuste-media-1417/128/128','image/png','png',51774,1,512,512,'Urban Crumb logo','user',1002,DATE_SUB(NOW(), INTERVAL 244 DAY)),
  (1418,'local','brand-assets',4,'paytrail-logo.png','Paytrail Logo.png','uploads/brand-assets/paytrail-logo.png','https://picsum.photos/seed/zuste-media-1418/512/512','https://picsum.photos/seed/zuste-media-1418/128/128','image/png','png',44092,1,512,512,'Paytrail logo','user',1003,DATE_SUB(NOW(), INTERVAL 231 DAY)),
  (1419,'local','brand-assets',4,'kettle-and-co-logo.png','Kettle & Co Logo.png','uploads/brand-assets/kettle-and-co-logo.png','https://picsum.photos/seed/zuste-media-1419/512/512','https://picsum.photos/seed/zuste-media-1419/128/128','image/png','png',46381,1,512,512,'Kettle and Co logo','user',1004,DATE_SUB(NOW(), INTERVAL 205 DAY)),
  (1420,'local','brand-assets',4,'northbound-travel-logo.png','Northbound Travel Logo.png','uploads/brand-assets/northbound-travel-logo.png','https://picsum.photos/seed/zuste-media-1420/512/512','https://picsum.photos/seed/zuste-media-1420/128/128','image/png','png',49903,1,512,512,'Northbound Travel logo','user',1005,DATE_SUB(NOW(), INTERVAL 190 DAY)),

  (1421,'local','deliverables',5,'reel-cut-01.mp4','Reel Cut 01.mp4','uploads/deliverables/reel-cut-01.mp4','https://picsum.photos/seed/zuste-media-1421/1080/1920','https://picsum.photos/seed/zuste-media-1421/270/480','video/mp4','mp4',18447260,0,1080,1920,'Delivered Instagram Reel cut','user',2001,DATE_SUB(NOW(), INTERVAL 52 DAY)),
  (1422,'local','deliverables',5,'reel-cut-02.mp4','Reel Cut 02.mp4','uploads/deliverables/reel-cut-02.mp4','https://picsum.photos/seed/zuste-media-1422/1080/1920','https://picsum.photos/seed/zuste-media-1422/270/480','video/mp4','mp4',21903118,0,1080,1920,'Delivered Instagram Reel cut','user',2004,DATE_SUB(NOW(), INTERVAL 47 DAY)),
  (1423,'local','deliverables',5,'unboxing-final.mp4','Unboxing Final.mp4','uploads/deliverables/unboxing-final.mp4','https://picsum.photos/seed/zuste-media-1423/1080/1920','https://picsum.photos/seed/zuste-media-1423/270/480','video/mp4','mp4',34112885,0,1080,1920,'Delivered product unboxing video','user',2007,DATE_SUB(NOW(), INTERVAL 39 DAY)),
  (1424,'local','deliverables',5,'carousel-frame-1.jpg','Carousel Frame 1.jpg','uploads/deliverables/carousel-frame-1.jpg','https://picsum.photos/seed/zuste-media-1424/1080/1350','https://picsum.photos/seed/zuste-media-1424/216/270','image/jpeg','jpg',389442,1,1080,1350,'Delivered carousel frame','user',2011,DATE_SUB(NOW(), INTERVAL 30 DAY)),
  (1425,'local','deliverables',5,'carousel-frame-2.jpg','Carousel Frame 2.jpg','uploads/deliverables/carousel-frame-2.jpg','https://picsum.photos/seed/zuste-media-1425/1080/1350','https://picsum.photos/seed/zuste-media-1425/216/270','image/jpeg','jpg',402118,1,1080,1350,'Delivered carousel frame','user',2011,DATE_SUB(NOW(), INTERVAL 30 DAY)),
  (1426,'local','deliverables',5,'story-set.jpg','Story Set.jpg','uploads/deliverables/story-set.jpg','https://picsum.photos/seed/zuste-media-1426/1080/1920','https://picsum.photos/seed/zuste-media-1426/216/384','image/jpeg','jpg',298776,1,1080,1920,'Delivered story frame','user',2015,DATE_SUB(NOW(), INTERVAL 22 DAY)),

  (1427,'local','general',NULL,'press-kit-2026.pdf','Zuste Press Kit 2026.pdf','uploads/general/press-kit-2026.pdf','https://example.com/uploads/general/press-kit-2026.pdf',NULL,'application/pdf','pdf',2884190,0,NULL,NULL,'Zusty press kit','admin',1,DATE_SUB(NOW(), INTERVAL 158 DAY)),
  (1428,'local','general',NULL,'brand-guidelines.pdf','Brand Guidelines.pdf','uploads/general/brand-guidelines.pdf','https://example.com/uploads/general/brand-guidelines.pdf',NULL,'application/pdf','pdf',5120440,0,NULL,NULL,'Zusty brand guidelines','admin',1,DATE_SUB(NOW(), INTERVAL 158 DAY)),
  (1429,'local','general',NULL,'creator-handbook.pdf','Creator Handbook.pdf','uploads/general/creator-handbook.pdf','https://example.com/uploads/general/creator-handbook.pdf',NULL,'application/pdf','pdf',3341082,0,NULL,NULL,'Creator onboarding handbook','admin',1,DATE_SUB(NOW(), INTERVAL 112 DAY)),
  (1430,'local','general',NULL,'rate-benchmarks.csv','Rate Benchmarks.csv','uploads/general/rate-benchmarks.csv','https://example.com/uploads/general/rate-benchmarks.csv',NULL,'text/csv','csv',88104,0,NULL,NULL,'Category rate benchmark export','admin',1,DATE_SUB(NOW(), INTERVAL 26 DAY))
ON DUPLICATE KEY UPDATE `id` = VALUES(`id`);

-- ---------------------------------------------------------------------
-- Coupons
--
-- A spread of active, expired, exhausted and first-booking-only codes so the
-- admin list has every state to render and the redemption engine has real
-- edge cases to work against.
-- ---------------------------------------------------------------------
INSERT INTO `coupons`
  (`id`, `code`, `name`, `description`, `discount_type`, `discount_value`, `max_discount`,
   `min_booking_amount`, `applies_to`, `category_id`, `usage_limit`, `usage_limit_per_user`,
   `used_count`, `starts_at`, `expires_at`, `is_active`, `is_public`, `created_by`, `created_at`)
VALUES
  (1501,'WELCOME200','Welcome credit','Flat Rs 200 off a brand''s first booking on Zusty.','fixed',200.00,NULL,2000.00,'first_booking',NULL,5000,1,0,DATE_SUB(NOW(), INTERVAL 300 DAY),DATE_ADD(NOW(), INTERVAL 180 DAY),1,1,1,DATE_SUB(NOW(), INTERVAL 300 DAY)),
  (1502,'FIRSTBOOK10','First booking 10%','10% off the first booking, capped at Rs 2,500.','percentage',10.00,2500.00,5000.00,'first_booking',NULL,3000,1,0,DATE_SUB(NOW(), INTERVAL 240 DAY),DATE_ADD(NOW(), INTERVAL 120 DAY),1,1,1,DATE_SUB(NOW(), INTERVAL 240 DAY)),
  (1503,'FESTIVE25','Festive season 25%','25% off during the festive window, capped at Rs 10,000.','percentage',25.00,10000.00,15000.00,'brands',NULL,1500,2,0,DATE_SUB(NOW(), INTERVAL 120 DAY),DATE_SUB(NOW(), INTERVAL 30 DAY),0,1,1,DATE_SUB(NOW(), INTERVAL 130 DAY)),
  (1504,'DIWALI15','Diwali 15%','15% off Diwali campaign bookings.','percentage',15.00,7500.00,10000.00,'brands',NULL,2000,3,0,DATE_SUB(NOW(), INTERVAL 95 DAY),DATE_SUB(NOW(), INTERVAL 55 DAY),0,1,1,DATE_SUB(NOW(), INTERVAL 100 DAY)),
  (1505,'BEAUTY500','Beauty category credit','Rs 500 off bookings with beauty creators.','fixed',500.00,NULL,8000.00,'category',7,800,1,0,DATE_SUB(NOW(), INTERVAL 150 DAY),DATE_ADD(NOW(), INTERVAL 60 DAY),1,1,1,DATE_SUB(NOW(), INTERVAL 150 DAY)),
  (1506,'FOODIE300','Food category credit','Rs 300 off bookings with food creators.','fixed',300.00,NULL,6000.00,'category',4,800,1,0,DATE_SUB(NOW(), INTERVAL 140 DAY),DATE_ADD(NOW(), INTERVAL 60 DAY),1,1,1,DATE_SUB(NOW(), INTERVAL 140 DAY)),
  (1507,'SCALE20','Scale-up 20%','20% off for brands booking five or more creators.','percentage',20.00,15000.00,50000.00,'brands',NULL,400,5,0,DATE_SUB(NOW(), INTERVAL 80 DAY),DATE_ADD(NOW(), INTERVAL 100 DAY),1,0,1,DATE_SUB(NOW(), INTERVAL 80 DAY)),
  (1508,'RETURN10','Returning brand 10%','10% off for brands returning after 60 days.','percentage',10.00,5000.00,10000.00,'brands',NULL,1000,2,0,DATE_SUB(NOW(), INTERVAL 60 DAY),DATE_ADD(NOW(), INTERVAL 150 DAY),1,1,1,DATE_SUB(NOW(), INTERVAL 60 DAY)),
  (1509,'MONSOON12','Monsoon 12%','12% off monsoon-season campaigns.','percentage',12.00,4000.00,8000.00,'all',NULL,1200,2,0,DATE_SUB(NOW(), INTERVAL 200 DAY),DATE_SUB(NOW(), INTERVAL 140 DAY),0,1,1,DATE_SUB(NOW(), INTERVAL 205 DAY)),
  (1510,'CREATORBOOST','Creator boost','Rs 250 credit for creators completing their first booking.','fixed',250.00,NULL,0.00,'creators',NULL,2000,1,0,DATE_SUB(NOW(), INTERVAL 170 DAY),DATE_ADD(NOW(), INTERVAL 90 DAY),1,1,1,DATE_SUB(NOW(), INTERVAL 170 DAY)),
  (1511,'FLASH5K','Flash Rs 5,000 off','Rs 5,000 off large bookings. Limited run.','fixed',5000.00,NULL,60000.00,'brands',NULL,50,1,50,DATE_SUB(NOW(), INTERVAL 40 DAY),DATE_ADD(NOW(), INTERVAL 20 DAY),1,1,1,DATE_SUB(NOW(), INTERVAL 40 DAY)),
  (1512,'PILOT2026','2026 pilot programme','Private code for the 2026 pilot cohort.','percentage',30.00,20000.00,25000.00,'brands',NULL,25,1,0,DATE_SUB(NOW(), INTERVAL 15 DAY),DATE_ADD(NOW(), INTERVAL 75 DAY),1,0,1,DATE_SUB(NOW(), INTERVAL 15 DAY))
ON DUPLICATE KEY UPDATE `id` = VALUES(`id`);

-- Redemptions derived from real paid bookings, so the discount never exceeds
-- what was actually charged and used_count below can be recomputed honestly.
INSERT INTO `coupon_redemptions` (`coupon_id`, `user_id`, `booking_id`, `discount_amount`, `created_at`)
SELECT
    1502,
    b.`brand_user_id`,
    b.`id`,
    LEAST(2500.00, ROUND(b.`amount` * 0.10, 2)),
    b.`created_at`
FROM `bookings` b
WHERE b.`payment_status` = 'paid'
  AND b.`deleted_at` IS NULL
  AND b.`id` % 4 = 0
  AND NOT EXISTS (
      SELECT 1 FROM `coupon_redemptions` r
       WHERE r.`booking_id` = b.`id` AND r.`coupon_id` = 1502
  )
LIMIT 20;

INSERT INTO `coupon_redemptions` (`coupon_id`, `user_id`, `booking_id`, `discount_amount`, `created_at`)
SELECT
    1501,
    b.`brand_user_id`,
    b.`id`,
    200.00,
    b.`created_at`
FROM `bookings` b
WHERE b.`payment_status` = 'paid'
  AND b.`deleted_at` IS NULL
  AND b.`id` % 7 = 0
  AND NOT EXISTS (
      SELECT 1 FROM `coupon_redemptions` r
       WHERE r.`booking_id` = b.`id` AND r.`coupon_id` = 1501
  )
LIMIT 12;

-- Keep the denormalised counter honest against what was actually redeemed.
UPDATE `coupons` c
   SET c.`used_count` = (
       SELECT COUNT(*) FROM `coupon_redemptions` r WHERE r.`coupon_id` = c.`id`
   )
 WHERE c.`id` BETWEEN 1501 AND 1510;

-- ---------------------------------------------------------------------
-- Popups
--
-- Audience coverage is deliberate: the API suite asserts that a creator sees
-- creator-targeted popups and a guest does not, so there is at least one of
-- each audience here.
-- ---------------------------------------------------------------------
INSERT INTO `popups`
  (`id`, `name`, `type`, `title`, `content`, `image_url`, `background_color`, `text_color`,
   `cta_label`, `cta_action`, `cta_value`, `placement`, `audience`, `trigger_type`,
   `trigger_value`, `frequency`, `is_dismissible`, `impressions`, `clicks`, `dismissals`,
   `starts_at`, `ends_at`, `is_active`, `priority`, `created_at`)
VALUES
  (1516,'Festive brief board','modal','Festive briefs are live','Over 40 brands are booking creators for the festive season. Browse open briefs and apply before the deadline.','https://picsum.photos/seed/zuste-popup-1516/800/500','#B80049','#FFFFFF','Browse briefs','screen','campaigns','home','creators','delay',3,'once_per_day',1,4820,613,1990,DATE_SUB(NOW(), INTERVAL 20 DAY),DATE_ADD(NOW(), INTERVAL 40 DAY),1,90,DATE_SUB(NOW(), INTERVAL 21 DAY)),
  (1517,'First booking discount','modal','10% off your first booking','New to Zusty? Use FIRSTBOOK10 at checkout for 10% off, up to Rs 2,500.','https://picsum.photos/seed/zuste-popup-1517/800/500','#632CE5','#FFFFFF','Find creators','screen','explore','home','brands','delay',5,'once',1,7310,1204,2871,DATE_SUB(NOW(), INTERVAL 60 DAY),DATE_ADD(NOW(), INTERVAL 120 DAY),1,80,DATE_SUB(NOW(), INTERVAL 61 DAY)),
  (1518,'Complete your verification','announcement_bar','Finish verification to unlock payouts','Verified creators rank higher in search and can withdraw earnings. It takes about five minutes.',NULL,'#FFF4E5','#7A4A00','Verify now','screen','kyc','all','creators','immediate',0,'once_per_session',1,12904,2233,4118,DATE_SUB(NOW(), INTERVAL 90 DAY),NULL,1,70,DATE_SUB(NOW(), INTERVAL 90 DAY)),
  (1519,'Refer and earn','slide_in','Invite a brand, earn Rs 500','Both of you get Rs 500 in wallet credit when they complete their first booking.','https://picsum.photos/seed/zuste-popup-1519/800/500','#0F9D58','#FFFFFF','Share invite','screen','referral','all','all','scroll',60,'once_per_day',1,9642,881,3407,DATE_SUB(NOW(), INTERVAL 45 DAY),DATE_ADD(NOW(), INTERVAL 45 DAY),1,50,DATE_SUB(NOW(), INTERVAL 46 DAY)),
  (1520,'Monsoon sale ended','modal','Monsoon offer has ended','Thanks for a great season. Watch this space for the festive line-up.','https://picsum.photos/seed/zuste-popup-1520/800/500','#333333','#FFFFFF','Dismiss','dismiss',NULL,'home','all','immediate',0,'once',1,3318,204,3114,DATE_SUB(NOW(), INTERVAL 200 DAY),DATE_SUB(NOW(), INTERVAL 140 DAY),0,10,DATE_SUB(NOW(), INTERVAL 201 DAY))
ON DUPLICATE KEY UPDATE `id` = VALUES(`id`);

-- ---------------------------------------------------------------------
-- Support desk
--
-- Covers every value of the status ENUM (open, pending_user, pending_agent,
-- resolved, closed) so the admin queue filters all have something to show.
-- ---------------------------------------------------------------------
INSERT INTO `support_tickets`
  (`id`, `code`, `user_id`, `booking_id`, `subject`, `category`, `priority`, `status`,
   `assigned_to`, `last_reply_at`, `last_reply_by`, `resolved_at`, `closed_at`,
   `satisfaction_rating`, `created_at`)
VALUES
  (1601,'ZT001601',1001,NULL,'Payout for booking ZB000412 has not arrived','payment','high','pending_agent',1,DATE_SUB(NOW(), INTERVAL 2 DAY),'user',NULL,NULL,NULL,DATE_SUB(NOW(), INTERVAL 4 DAY)),
  (1602,'ZT001602',2003,NULL,'KYC rejected but no reason given','kyc','high','open',NULL,DATE_SUB(NOW(), INTERVAL 1 DAY),'user',NULL,NULL,NULL,DATE_SUB(NOW(), INTERVAL 1 DAY)),
  (1603,'ZT001603',1004,NULL,'Creator has not submitted deliverables','booking','normal','pending_user',1,DATE_SUB(NOW(), INTERVAL 3 DAY),'agent',NULL,NULL,NULL,DATE_SUB(NOW(), INTERVAL 6 DAY)),
  (1604,'ZT001604',2008,NULL,'Bank account rejected during withdrawal','wallet','urgent','resolved',1,DATE_SUB(NOW(), INTERVAL 12 DAY),'agent',DATE_SUB(NOW(), INTERVAL 12 DAY),NULL,5,DATE_SUB(NOW(), INTERVAL 15 DAY)),
  (1605,'ZT001605',1007,NULL,'GST number not appearing on my invoice','payment','normal','resolved',1,DATE_SUB(NOW(), INTERVAL 20 DAY),'agent',DATE_SUB(NOW(), INTERVAL 20 DAY),NULL,4,DATE_SUB(NOW(), INTERVAL 24 DAY)),
  (1606,'ZT001606',2012,NULL,'App crashes when uploading a Reel','technical','high','closed',1,DATE_SUB(NOW(), INTERVAL 35 DAY),'agent',DATE_SUB(NOW(), INTERVAL 36 DAY),DATE_SUB(NOW(), INTERVAL 33 DAY),5,DATE_SUB(NOW(), INTERVAL 40 DAY)),
  (1607,'ZT001607',1010,NULL,'Refund still pending after cancellation','payment','high','pending_agent',1,DATE_SUB(NOW(), INTERVAL 1 DAY),'user',NULL,NULL,NULL,DATE_SUB(NOW(), INTERVAL 3 DAY)),
  (1608,'ZT001608',2019,NULL,'Cannot change my payout bank account','wallet','normal','open',NULL,DATE_SUB(NOW(), INTERVAL 2 DAY),'user',NULL,NULL,NULL,DATE_SUB(NOW(), INTERVAL 2 DAY)),
  (1609,'ZT001609',1013,NULL,'A creator is asking for payment outside the platform','abuse','urgent','pending_agent',1,DATE_SUB(NOW(), INTERVAL 1 DAY),'user',NULL,NULL,NULL,DATE_SUB(NOW(), INTERVAL 2 DAY)),
  (1610,'ZT001610',2024,NULL,'Verification badge missing after approval','kyc','normal','resolved',1,DATE_SUB(NOW(), INTERVAL 28 DAY),'agent',DATE_SUB(NOW(), INTERVAL 28 DAY),NULL,4,DATE_SUB(NOW(), INTERVAL 31 DAY)),
  (1611,'ZT001611',1016,NULL,'How do I download invoices in bulk?','account','low','closed',1,DATE_SUB(NOW(), INTERVAL 55 DAY),'agent',DATE_SUB(NOW(), INTERVAL 56 DAY),DATE_SUB(NOW(), INTERVAL 53 DAY),5,DATE_SUB(NOW(), INTERVAL 58 DAY)),
  (1612,'ZT001612',2030,NULL,'Deliverable marked as revision without a note','booking','normal','pending_user',1,DATE_SUB(NOW(), INTERVAL 5 DAY),'agent',NULL,NULL,NULL,DATE_SUB(NOW(), INTERVAL 8 DAY)),
  (1613,'ZT001613',1018,NULL,'Wallet top-up debited but not credited','wallet','urgent','resolved',1,DATE_SUB(NOW(), INTERVAL 44 DAY),'agent',DATE_SUB(NOW(), INTERVAL 44 DAY),NULL,3,DATE_SUB(NOW(), INTERVAL 47 DAY)),
  (1614,'ZT001614',2036,NULL,'Cannot log in after changing my phone number','account','high','closed',1,DATE_SUB(NOW(), INTERVAL 70 DAY),'agent',DATE_SUB(NOW(), INTERVAL 71 DAY),DATE_SUB(NOW(), INTERVAL 68 DAY),4,DATE_SUB(NOW(), INTERVAL 74 DAY)),
  (1615,'ZT001615',1020,NULL,'Request for a dedicated account manager','other','low','open',NULL,DATE_SUB(NOW(), INTERVAL 6 DAY),'user',NULL,NULL,NULL,DATE_SUB(NOW(), INTERVAL 6 DAY)),
  (1616,'ZT001616',2041,NULL,'Escrow released late by two days','payment','normal','resolved',1,DATE_SUB(NOW(), INTERVAL 62 DAY),'agent',DATE_SUB(NOW(), INTERVAL 62 DAY),NULL,4,DATE_SUB(NOW(), INTERVAL 65 DAY))
ON DUPLICATE KEY UPDATE `id` = VALUES(`id`);

INSERT INTO `support_ticket_messages`
  (`ticket_id`, `sender_type`, `sender_id`, `sender_name`, `message`, `is_internal`, `created_at`)
SELECT * FROM (
  SELECT 1601 AS t,'user' AS st,1001 AS si,'Bloom Skincare' AS sn,'The booking was marked complete on the 12th but the payout has not reached my account. Reference ZB000412.' AS m,0 AS i,DATE_SUB(NOW(), INTERVAL 4 DAY) AS c UNION ALL
  SELECT 1601,'agent',1,'Zusty Support','Thanks for flagging this. Escrow release runs hourly and I can see the transfer queued. Let me confirm with our payments team and come back to you today.',0,DATE_SUB(NOW(), INTERVAL 3 DAY) UNION ALL
  SELECT 1601,'agent',1,'Zusty Support','Payments confirm the bank returned it once for a name mismatch. Could you check the account holder name matches your PAN exactly?',0,DATE_SUB(NOW(), INTERVAL 2 DAY) UNION ALL

  SELECT 1602,'user',2003,'Rhea Menon','My KYC was rejected this morning but the app does not show a reason. I uploaded my Aadhaar and PAN as asked.',0,DATE_SUB(NOW(), INTERVAL 1 DAY) UNION ALL

  SELECT 1603,'user',1004,'Kettle & Co','The creator accepted on the 3rd and the delivery date has passed with nothing submitted.',0,DATE_SUB(NOW(), INTERVAL 6 DAY) UNION ALL
  SELECT 1603,'agent',1,'Zusty Support','I have reached out to the creator and extended the window by 48 hours. If nothing arrives you can cancel with a full refund — the free-cancellation window still applies here.',0,DATE_SUB(NOW(), INTERVAL 3 DAY) UNION ALL

  SELECT 1604,'user',2008,'Kabir Sethi','My withdrawal failed and the bank account now shows as rejected. I have used it before without a problem.',0,DATE_SUB(NOW(), INTERVAL 15 DAY) UNION ALL
  SELECT 1604,'agent',1,'Zusty Support','The IFSC on file belonged to a branch that merged in April. I have cleared the rejection so you can add the new IFSC.',0,DATE_SUB(NOW(), INTERVAL 14 DAY) UNION ALL
  SELECT 1604,'user',2008,'Kabir Sethi','Added the new one and the withdrawal has gone through. Thank you.',0,DATE_SUB(NOW(), INTERVAL 12 DAY) UNION ALL

  SELECT 1605,'user',1007,'Northbound Travel','Our GSTIN is on the business profile but the last two invoices do not show it.',0,DATE_SUB(NOW(), INTERVAL 24 DAY) UNION ALL
  SELECT 1605,'agent',1,'Zusty Support','Invoices snapshot the tax details at the time they are issued, so the two raised before you added the GSTIN will not carry it. I have reissued both.',0,DATE_SUB(NOW(), INTERVAL 20 DAY) UNION ALL

  SELECT 1607,'user',1010,'Urban Crumb','We cancelled on the 2nd and the refund still has not arrived.',0,DATE_SUB(NOW(), INTERVAL 3 DAY) UNION ALL
  SELECT 1607,'agent',1,'Zusty Support','Refunds to the original method take five to seven working days at the gateway. I can see it left our side on the 3rd — I will share the ARN so you can trace it with your bank.',0,DATE_SUB(NOW(), INTERVAL 1 DAY) UNION ALL

  SELECT 1609,'user',1013,'Paytrail','A creator has asked us to pay them directly over UPI to skip the platform fee.',0,DATE_SUB(NOW(), INTERVAL 2 DAY) UNION ALL
  SELECT 1609,'agent',1,'Zusty Support','Thank you for reporting it — off-platform payment requests are a policy breach and leave you with no escrow protection. Our trust team is reviewing the account now.',0,DATE_SUB(NOW(), INTERVAL 1 DAY) UNION ALL
  SELECT 1609,'agent',1,'Zusty Support','Trust team: account flagged, creator suspended pending review.',1,DATE_SUB(NOW(), INTERVAL 1 DAY) UNION ALL

  SELECT 1612,'user',2030,'Ishaan Verma','The brand pushed my Reel back for revision but left no note, so I do not know what to change.',0,DATE_SUB(NOW(), INTERVAL 8 DAY) UNION ALL
  SELECT 1612,'agent',1,'Zusty Support','A revision note is optional today, which is not good enough — I have asked the brand for specifics and passed the gap to our product team.',0,DATE_SUB(NOW(), INTERVAL 5 DAY) UNION ALL

  SELECT 1613,'user',1018,'Sunday Loaf','Rs 25,000 left my card but the wallet still shows the old balance.',0,DATE_SUB(NOW(), INTERVAL 47 DAY) UNION ALL
  SELECT 1613,'agent',1,'Zusty Support','The gateway webhook did not reach us. I have reconciled the payment manually and the balance is now correct. Apologies for the delay.',0,DATE_SUB(NOW(), INTERVAL 44 DAY)
) AS seed
WHERE NOT EXISTS (
    SELECT 1 FROM `support_ticket_messages` m WHERE m.`ticket_id` = seed.t AND m.`created_at` = seed.c
);

-- ---------------------------------------------------------------------
-- Contact form and newsletter
-- ---------------------------------------------------------------------
INSERT INTO `contact_messages`
  (`name`, `email`, `phone`, `subject`, `message`, `source`, `status`, `created_at`)
SELECT * FROM (
  SELECT 'Meera Iyer' AS n,'meera@lumehomes.in' AS e,'9845012345' AS p,'Partnership enquiry' AS s,'We run a home decor brand in Bengaluru and would like to understand enterprise pricing for booking twenty creators a quarter.' AS m,'web' AS src,'replied' AS st,DATE_SUB(NOW(), INTERVAL 34 DAY) AS c UNION ALL
  SELECT 'Arjun Rao','arjun.rao@finlypay.com','9900112233','API access','Do you offer an API so we can pull booking status into our internal dashboard?','web','read',DATE_SUB(NOW(), INTERVAL 19 DAY) UNION ALL
  SELECT 'Sana Qureshi','sana@thecraftedit.co','9820044556','Creator onboarding','I manage six creators and would like to onboard them together rather than one by one.','app','new',DATE_SUB(NOW(), INTERVAL 6 DAY) UNION ALL
  SELECT 'Vikram Nair','vikram@nairmedia.in',NULL,'Press enquiry','Writing a piece on India''s creator economy and would like a comment on rate transparency.','web','replied',DATE_SUB(NOW(), INTERVAL 52 DAY) UNION ALL
  SELECT 'Priyanka Das','priyanka.das@zephyrtea.in','9711223344','Invoice query','Can we receive a consolidated monthly invoice instead of one per booking?','web','new',DATE_SUB(NOW(), INTERVAL 3 DAY) UNION ALL
  SELECT 'Growth Team','offers@seo-backlinks-cheap.example','','Boost your rankings','Buy 5000 backlinks today, guaranteed page one placement.','web','spam',DATE_SUB(NOW(), INTERVAL 11 DAY)
) AS seed
WHERE NOT EXISTS (
    SELECT 1 FROM `contact_messages` cm WHERE cm.`email` = seed.e AND cm.`subject` = seed.s
);

INSERT INTO `newsletter_subscribers` (`email`, `name`, `source`, `status`, `unsubscribe_token`, `subscribed_at`)
SELECT * FROM (
  SELECT 'meera@lumehomes.in' AS e,'Meera Iyer' AS n,'web' AS s,'subscribed' AS st,MD5('nl-1') AS t,DATE_SUB(NOW(), INTERVAL 120 DAY) AS c UNION ALL
  SELECT 'arjun.rao@finlypay.com','Arjun Rao','web','subscribed',MD5('nl-2'),DATE_SUB(NOW(), INTERVAL 98 DAY) UNION ALL
  SELECT 'sana@thecraftedit.co','Sana Qureshi','app','subscribed',MD5('nl-3'),DATE_SUB(NOW(), INTERVAL 76 DAY) UNION ALL
  SELECT 'vikram@nairmedia.in','Vikram Nair','web','unsubscribed',MD5('nl-4'),DATE_SUB(NOW(), INTERVAL 210 DAY) UNION ALL
  SELECT 'priyanka.das@zephyrtea.in','Priyanka Das','web','subscribed',MD5('nl-5'),DATE_SUB(NOW(), INTERVAL 41 DAY) UNION ALL
  SELECT 'rohit@dunebrew.in','Rohit Kulkarni','app','subscribed',MD5('nl-6'),DATE_SUB(NOW(), INTERVAL 33 DAY) UNION ALL
  SELECT 'anita@saffronstudio.in','Anita Pillai','import','bounced',MD5('nl-7'),DATE_SUB(NOW(), INTERVAL 165 DAY) UNION ALL
  SELECT 'devang@northloop.co','Devang Shah','web','subscribed',MD5('nl-8'),DATE_SUB(NOW(), INTERVAL 22 DAY)
) AS seed
WHERE NOT EXISTS (
    SELECT 1 FROM `newsletter_subscribers` ns WHERE ns.`email` = seed.e
);
