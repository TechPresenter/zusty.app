/* ============================================================================
 * 026_demo_cms_support.sql — Zusty demo data: CMS, marketing and support
 * ----------------------------------------------------------------------------
 * Seeds the editorial, promotional and helpdesk surfaces that the public site,
 * the Flutter app and the admin dashboards read from.
 *
 * Tables and id ranges owned by this file
 *   blogs                    1301 - 1330
 *   media                    1401 - 1480
 *   coupons                  1501 - 1515
 *   popups                   1516 - 1520
 *   support_tickets          1601 - 1620
 *   support_ticket_messages  1621 - 1700   (2-6 message thread per ticket)
 *   coupon_redemptions       1701 - 1760   (derived, INSERT ... SELECT)
 *   contact_messages         1801 - 1818
 *   newsletter_subscribers   1851 - 1890
 *   reports                  1901 - 1912
 *
 * Depends on (seeded by earlier files, referenced never re-inserted)
 *   admins            1                     author / agent / handler
 *   blog_categories   1 - 4                 For Brands, For Creators,
 *                                           Product Updates, Case Studies
 *   media_folders     1 - 5                 general, banners, blogs,
 *                                           branding, marketing
 *   categories        1 - 16                coupon category targeting
 *   users             brands 1001-1020, creators 2001-2045
 *   bookings          401 - 480
 *
 * Design notes
 *   - coupon_redemptions is an INSERT ... SELECT over real bookings so the
 *     discount is always recomputed from the booking amount and the coupon
 *     rule. Nothing is hard-coded and nothing can drift out of sync.
 *   - coupons.used_count and media_folders.file_count / size_bytes are
 *     recomputed at the end of the file from the rows actually present.
 *   - popups cover every audience. The creator-audience row (1517) is a
 *     retired verification nudge: a popup cannot target unverified creators
 *     only, so it would also show to verified ones. The all-audience modal
 *     (1516) is retired too, so no modal covers the app screens used for
 *     store screenshots. The API suite asserts audience targeting with its
 *     own fixture popups.
 *   - 028 used to insert its own media, coupons, popups and tickets in these
 *     same id ranges. Whichever file ran first won, so a fresh install and
 *     an older database disagreed. This file is now the only source of them.
 *   - Contact-form IP addresses come from the RFC 5737 documentation ranges.
 *   - support_tickets covers every member of the status ENUM
 *     (open, pending_user, pending_agent, resolved, closed).
 *   - Money is INR. GST is 18 percent, platform commission is 15 percent.
 *   - Every statement is INSERT IGNORE / ON DUPLICATE KEY UPDATE and every
 *     UPDATE is idempotent, so the whole file is safely re-runnable.
 * ========================================================================== */


/* ==========================================================================
 * 1. BLOGS  (1301 - 1330)
 *    26 published, 3 draft, 1 scheduled (published_at in the future).
 * ========================================================================== */

INSERT INTO blogs
    (id, category_id, slug, title, excerpt, content, cover_image,
     author_id, author_name, tags, reading_minutes, views_count,
     status, is_featured, published_at, meta_title, meta_description,
     meta_keywords, og_image, created_at, updated_at)
VALUES
(1301, 1, 'how-d2c-brands-pick-micro-influencers', 'How D2C brands pick micro-influencers',
 'Follower count is the worst filter to start with. Here is the shortlist process the sharpest Indian D2C teams actually run: category fit, comment quality, and the one signal that predicts a sellout.',
 'Most brand teams open a discovery tool, sort by followers, and start sending DMs. That is why so many campaigns land flat.\n\n## Start with the audience, not the creator\n\nA skincare brand in Bengaluru does not need a beauty creator. It needs a creator whose audience is 22 to 34, urban, and already buying serums. Ask for the audience breakdown: top cities, age split, gender split. If a creator cannot produce it within a day, they are not running this as a business yet.\n\n## The three filters that matter\n\n- **Category density.** What share of the last 20 posts sit in your category? Below 30 percent and your product reads as an interruption.\n- **Comment quality.** Count the real questions on the last three posts. Ten questions on a 40k account beats four hundred fire emojis on a 400k account.\n- **Recent competitor work.** A creator who has posted for a direct competitor in the last 60 days will cost you credibility, not buy it.\n\n## Price after fit, never before\n\nOnce the shortlist is real, rate talk becomes a negotiation about deliverables and usage rights rather than about reach. A 45k creator in Pune quoting Rs 18,000 for one Reel plus 30 days of paid usage is routinely cheaper per qualified click than a 500k name at Rs 1,20,000.\n\n## What good looks like\n\nRun 10 to 15 micro creators in a single wave instead of one macro name. You get creative variance, you learn which hook works, and you can push the winner into paid media inside a week.',
 'https://picsum.photos/seed/zuste-blog-micro-influencers/1200/630',
 1, 'Zusty Editorial', '["d2c","micro-influencers","shortlisting","brand-playbook"]', 7, 12480,
 'published', 1, DATE_SUB(NOW(), INTERVAL 412 DAY),
 'How D2C brands pick micro-influencers | Zusty',
 'The shortlist process Indian D2C teams use to pick micro-influencers: category density, comment quality and competitor overlap, before any rate is discussed.',
 'micro influencers india, d2c influencer marketing, creator shortlisting',
 'https://picsum.photos/seed/zuste-blog-micro-influencers/1200/630',
 DATE_SUB(NOW(), INTERVAL 415 DAY), DATE_SUB(NOW(), INTERVAL 402 DAY)),

(1302, 2, 'gst-on-influencer-invoices-explained', 'GST on influencer invoices, explained',
 'Do you need to register? Is 18 percent always right? What happens when the brand is in another state? A plain-language guide to invoicing brands as an Indian creator.',
 'Nothing scares a first-time creator more than the moment a brand asks for a GST invoice. It is simpler than it looks.\n\n## Do you even need to register\n\nInfluencer services fall under advertising and brand promotion services. Registration becomes mandatory once your turnover from services crosses Rs 20 lakh in a financial year, or Rs 10 lakh in the special category states. Below that you can invoice without GST, but you must not charge it either.\n\n## The rate is 18 percent\n\nBrand promotion is taxed at 18 percent. There is no lower slab for small creators and no exemption for barter. If you are registered and you post for a brand, the invoice carries 18 percent GST on the fee.\n\n## CGST and SGST, or IGST\n\nThis is where most invoices go wrong. If you and the brand are in the same state, split the 18 percent into 9 percent CGST and 9 percent SGST. If the brand is registered in another state, charge 18 percent IGST as a single line. A Mumbai creator invoicing a Bengaluru brand raises IGST.\n\n## What the invoice must carry\n\n- Your legal name, address and GSTIN\n- The brand legal name, address, GSTIN and state code\n- A unique sequential invoice number and date\n- SAC code 998361 for advertising services\n- Taxable value, tax split, and the total in words\n\n## Barter is still a supply\n\nIf a brand sends you a Rs 40,000 product in exchange for a Reel, both sides have made a supply. You invoice for the fair value of your service and the brand invoices for the goods. Ignoring barter is the single most common notice trigger we see.\n\nNone of this is tax advice. Run the first three invoices past a chartered accountant and then reuse the template forever.',
 'https://picsum.photos/seed/zuste-blog-gst-invoices/1200/630',
 1, 'Zusty Editorial', '["gst","invoicing","creator-finance","compliance"]', 8, 21940,
 'published', 1, DATE_SUB(NOW(), INTERVAL 398 DAY),
 'GST on influencer invoices, explained | Zusty',
 'When Indian creators must register for GST, why the rate is 18 percent, when to raise IGST instead of CGST plus SGST, and what a compliant influencer invoice must contain.',
 'gst influencer india, creator invoice gst, sac code 998361',
 'https://picsum.photos/seed/zuste-blog-gst-invoices/1200/630',
 DATE_SUB(NOW(), INTERVAL 401 DAY), DATE_SUB(NOW(), INTERVAL 88 DAY)),

(1303, 2, 'what-a-fair-reel-rate-looks-like-in-2026', 'What a fair Reel rate looks like in 2026',
 'Real ranges by follower tier, why CPM is the only honest way to price, and the four add-ons that should never be free. Built from the rate cards moving on Zusty this year.',
 'Creator pricing in India finally has enough public data to stop guessing. Here is where the market actually sits.\n\n## Base ranges for one Instagram Reel\n\n- 5k to 25k followers: Rs 3,000 to Rs 12,000\n- 25k to 75k followers: Rs 12,000 to Rs 35,000\n- 75k to 250k followers: Rs 35,000 to Rs 90,000\n- 250k to 1M followers: Rs 90,000 to Rs 3,50,000\n\nThese are organic-only, single-deliverable, no-usage-rights numbers. Every add-on is priced on top.\n\n## Price on delivered views, not followers\n\nThe cleaner mental model is cost per thousand delivered views. Most Indian lifestyle creators land between Rs 150 and Rs 400 CPM. If a 60k creator averages 90,000 Reel views and quotes Rs 22,000, that is Rs 244 CPM, squarely fair. A 400k creator averaging 120,000 views and quoting Rs 1,10,000 is Rs 916 CPM, and you should walk.\n\n## The four add-ons that are never free\n\n- **Paid usage rights.** Whitelisting the asset for ads is worth 30 to 60 percent of the base fee per 30 days.\n- **Exclusivity.** A 60-day category lockout costs the creator every competing deal in that window. Price it at 25 to 50 percent.\n- **Raw files.** Handing over unedited footage is a production deliverable, not a courtesy.\n- **Extra revisions.** Two rounds included, everything after that billed.\n\n## Regional creators are underpriced\n\nTamil, Marathi and Bhojpuri creators with equivalent engagement still quote 30 to 40 percent below their Mumbai and Delhi peers. That gap is closing fast, and brands that lock annual rates now will look clever in twelve months.',
 'https://picsum.photos/seed/zuste-blog-reel-rates-2026/1200/630',
 1, 'Zusty Editorial', '["pricing","rate-card","reels","creator-economy"]', 9, 34210,
 'published', 1, DATE_SUB(NOW(), INTERVAL 372 DAY),
 'What a fair Reel rate looks like in 2026 | Zusty',
 'Instagram Reel rate ranges by follower tier in India, why CPM beats follower pricing, and the four add-ons creators should always charge for.',
 'reel rate card india, influencer pricing 2026, creator cpm india',
 'https://picsum.photos/seed/zuste-blog-reel-rates-2026/1200/630',
 DATE_SUB(NOW(), INTERVAL 375 DAY), DATE_SUB(NOW(), INTERVAL 40 DAY)),

(1304, 2, 'the-barter-trap-when-free-product-is-not-payment', 'The barter trap: when free product is not payment',
 'Barter is not always a bad deal, but it is almost always a badly negotiated one. How to decide, how to price it, and the three conditions that make it worth saying yes.',
 'Every creator gets the message. Free product, great exposure, we will repost you. Sometimes that is genuinely worth it. Usually it is not.\n\n## Barter costs you more than you think\n\nA single Reel is a location, two hours of shooting, an hour of editing, and a slot on your grid you cannot resell for at least a week. If your rate is Rs 15,000, a Rs 2,500 hamper is not payment, it is a 83 percent discount you did not agree to.\n\n## When barter genuinely works\n\n- The product retail value is at least 60 percent of your cash rate\n- You would have bought it anyway, so it replaces real spend\n- The brand is a name that upgrades your media kit for the next twelve months\n\nIf two of those three are true, take it. If only one is, counter.\n\n## The hybrid counter that usually lands\n\nAsk for product plus a reduced cash fee. Product worth Rs 6,000 plus Rs 8,000 in cash against a Rs 15,000 card is a deal both sides can sign, and it keeps a paid rate on record so the brand cannot anchor you at zero next quarter.\n\n## Put it in writing anyway\n\nBarter deals fail on scope, not on money. Write down the deliverable count, the posting window, how long the post stays up, and whether the brand can run it as an ad. A one-page scope note has saved more creator relationships than any contract template.\n\n## Remember the tax\n\nBarter is a taxable supply on both sides. If you are GST registered, you still raise an invoice for the fair value of the service. Treating a hamper as invisible income is how a fun collab becomes a notice.',
 'https://picsum.photos/seed/zuste-blog-barter-trap/1200/630',
 1, 'Zusty Editorial', '["barter","negotiation","creator-business"]', 6, 9870,
 'published', 0, DATE_SUB(NOW(), INTERVAL 358 DAY),
 'The barter trap: when free product is not payment | Zusty',
 'How Indian creators should evaluate barter collaborations, when product-only deals are worth taking, and the hybrid counter that keeps a paid rate on record.',
 'barter collaboration india, free product influencer, creator negotiation',
 'https://picsum.photos/seed/zuste-blog-barter-trap/1200/630',
 DATE_SUB(NOW(), INTERVAL 361 DAY), DATE_SUB(NOW(), INTERVAL 340 DAY)),

(1305, 1, 'reading-a-creator-media-kit-without-being-fooled', 'Reading a creator media kit without being fooled',
 'Six numbers in a media kit are easy to inflate and four are hard to fake. A brand-side guide to spotting a padded deck before you send the advance.',
 'A media kit is a sales document. Read it like one.\n\n## The easy numbers to inflate\n\nReach, impressions and total audience are screenshot-croppable and rarely dated. Any kit that shows a reach figure without the date range it covers is showing you the best week of the year.\n\n## The hard numbers to fake\n\n- **Saves and shares** on a specific post, shown in a live screen recording\n- **Audience top cities**, because a bought audience always leaks in the city list\n- **Story reply counts**, which bots almost never generate\n- **Link clicks** from a real campaign, verifiable against your own analytics\n\n## Ask for the boring artefacts\n\nRequest the last three brand campaign recaps with dates and results, and one live screen recording of the insights tab scrolling through the last 30 days. Creators running a real business have these ready. It takes them ten minutes.\n\n## Sanity-check the ratios\n\nOn Indian lifestyle accounts, a healthy Reel does 2.5x to 6x the follower count in views. Saves usually run 1 to 4 percent of reach. A kit claiming 20x views with a 0.2 percent save rate is describing bought distribution, not an audience.\n\n## The one question that ends the conversation\n\nAsk which of their last five brand posts underperformed and why. Creators who can answer specifically are the ones who will tell you the truth mid-campaign too.',
 'https://picsum.photos/seed/zuste-blog-media-kit/1200/630',
 1, 'Zusty Editorial', '["media-kit","vetting","brand-playbook","fraud"]', 6, 14320,
 'published', 0, DATE_SUB(NOW(), INTERVAL 341 DAY),
 'Reading a creator media kit without being fooled | Zusty',
 'Which media kit metrics are trivially inflated, which are hard to fake, and the artefacts brands should request before paying a creator advance.',
 'creator media kit, influencer vetting india, fake followers check',
 'https://picsum.photos/seed/zuste-blog-media-kit/1200/630',
 DATE_SUB(NOW(), INTERVAL 344 DAY), DATE_SUB(NOW(), INTERVAL 330 DAY)),

(1306, 1, 'engagement-rate-benchmarks-by-follower-tier-in-india', 'Engagement rate benchmarks by follower tier in India',
 'What counts as good in 2026, why the number falls as accounts grow, and the two categories that break every benchmark. Numbers drawn from campaigns run on Zusty.',
 'Engagement rate is a blunt instrument, but it is the fastest way to disqualify a bad shortlist.\n\n## The working benchmarks\n\nMeasured as likes plus comments plus saves over reach, on Reels, for Indian accounts:\n\n- 1k to 10k followers: 6.0 to 11.0 percent is healthy\n- 10k to 50k followers: 4.0 to 7.5 percent\n- 50k to 200k followers: 2.5 to 5.0 percent\n- 200k to 1M followers: 1.8 to 3.5 percent\n- Above 1M: 1.0 to 2.5 percent\n\n## Why it falls with size\n\nBigger accounts get distributed to colder audiences. A 900k creator is being served to people who have never seen them before, and strangers do not comment. This is normal and is not a quality signal on its own.\n\n## The categories that break the rules\n\nFinance and education creators post lower like rates but far higher save rates, often 6 to 9 percent of reach, because their content is reference material. Judging a UPSC or personal-finance creator on likes will make you skip the best performers on the list. Comedy runs the opposite way: enormous like rates, almost no saves, and weak purchase intent.\n\n## What to do with the number\n\nUse it to cut the bottom third of a list, then stop. Beyond that, a campaign is won on brief quality, creative fit and offer strength, and no engagement rate will rescue a weak product page.',
 'https://picsum.photos/seed/zuste-blog-engagement-benchmarks/1200/630',
 1, 'Zusty Editorial', '["benchmarks","engagement-rate","analytics"]', 5, 18760,
 'published', 0, DATE_SUB(NOW(), INTERVAL 327 DAY),
 'Engagement rate benchmarks by follower tier in India | Zusty',
 'Healthy Instagram engagement rate ranges for Indian creators by follower tier, why the number drops with scale, and the categories where the benchmark misleads.',
 'engagement rate india, instagram benchmarks 2026, influencer analytics',
 'https://picsum.photos/seed/zuste-blog-engagement-benchmarks/1200/630',
 DATE_SUB(NOW(), INTERVAL 329 DAY), DATE_SUB(NOW(), INTERVAL 300 DAY)),

(1307, 2, 'asci-disclosure-rules-every-creator-gets-wrong', 'ASCI disclosure rules every creator gets wrong',
 'Ad, sponsored, collab or nothing? Where the label must sit, how long it must be visible, and the five formats where creators routinely get it wrong.',
 'The ASCI influencer guidelines are short, readable and widely ignored. Getting them right costs you nothing and protects both sides.\n\n## Disclosure is required whenever there is a material connection\n\nCash, free product, discounts, trips, family ties or equity all count. If you received anything of value, the post is an advertisement and must say so.\n\n## The label must be upfront and unmissable\n\nUse a plain word: Advertisement, Ad, Sponsored, or Paid Promotion. Terms like collab, thanks, partner or a bare brand tag are not sufficient. The disclosure belongs in the first two lines of the caption, before the more button truncates it, and never buried in a hashtag block.\n\n## Format by format\n\n- **Reels and videos:** an overlay label held for the first few seconds, not only in the caption\n- **Stories:** a text label on every frame of the sponsored sequence, because viewers tap in mid-sequence\n- **Live:** state it verbally at the start and periodically through the stream\n- **Carousels:** caption disclosure is fine, but the first card should carry it too\n- **Blogs and newsletters:** above the fold, before the first product mention\n\n## Two rules people forget\n\nThe disclosure must be in the same language as the post. A Tamil Reel with an English hashtag disclosure fails. And filters or effects that exaggerate a product result, such as beautification on a skincare before-and-after, are not permitted at all.\n\n## Who carries the risk\n\nBoth sides. The brand is responsible for the claims and the creator for the disclosure. Put a one-line disclosure requirement into every brief and it stops being an argument.',
 'https://picsum.photos/seed/zuste-blog-asci-disclosure/1200/630',
 1, 'Zusty Editorial', '["asci","compliance","disclosure","regulation"]', 7, 11240,
 'published', 0, DATE_SUB(NOW(), INTERVAL 311 DAY),
 'ASCI disclosure rules every creator gets wrong | Zusty',
 'How Indian creators must disclose paid partnerships under the ASCI guidelines: acceptable labels, placement, per-format rules and the language requirement.',
 'asci guidelines influencer, sponsored post disclosure india, ad label rules',
 'https://picsum.photos/seed/zuste-blog-asci-disclosure/1200/630',
 DATE_SUB(NOW(), INTERVAL 314 DAY), DATE_SUB(NOW(), INTERVAL 290 DAY)),

(1308, 1, 'nano-vs-micro-which-tier-converts-for-d2c', 'Nano vs micro: which tier actually converts for D2C',
 'We compared 240 D2C bookings across two follower tiers. The cheaper tier won on cost per order, lost on volume, and the gap was not where anyone expected.',
 'Nano creators, roughly 1k to 10k followers, are cheap enough that brands treat them as a rounding error. That is a mistake worth quantifying.\n\n## The comparison\n\nAcross 240 completed D2C bookings on Zusty in beauty, food and home categories, we grouped creators into nano (under 10k) and micro (10k to 100k) and normalised for offer strength.\n\n## What nano won\n\nCost per attributed order was 34 percent lower for nano creators. Comment-to-view ratio was almost triple. Story reply volume, the best early signal of purchase intent we track, was five times higher per thousand views.\n\n## What micro won\n\nAbsolute order volume per booking was 4.2x higher, and creative quality was materially more consistent. Micro creators shot to brief on the first attempt 71 percent of the time against 38 percent for nano.\n\n## The real trade is operations, not performance\n\nGetting the same total volume from nano creators means running 12 bookings instead of 3. That is 12 briefs, 12 shipments, 12 approval threads and 12 payouts. Brands that build a repeatable operating rhythm win here. Brands that run campaigns manually in a spreadsheet quietly give up by week three.\n\n## The blend that works\n\nTwo micro creators for hero creative you can whitelist into paid, plus eight to ten nano creators for coverage and social proof. Reuse the micro creative in ads, and let the nano wave do the reviews, comments and search-result density that make a new brand look established.',
 'https://picsum.photos/seed/zuste-blog-nano-vs-micro/1200/630',
 1, 'Zusty Editorial', '["nano-influencers","micro-influencers","d2c","conversion"]', 7, 16590,
 'published', 1, DATE_SUB(NOW(), INTERVAL 298 DAY),
 'Nano vs micro: which tier actually converts for D2C | Zusty',
 'A comparison of 240 Indian D2C bookings showing where nano creators beat micro on cost per order, where they lose, and the blended structure that works.',
 'nano influencers india, micro influencer roi, d2c creator strategy',
 'https://picsum.photos/seed/zuste-blog-nano-vs-micro/1200/630',
 DATE_SUB(NOW(), INTERVAL 300 DAY), DATE_SUB(NOW(), INTERVAL 270 DAY)),

(1309, 1, 'how-to-brief-a-creator-so-you-get-one-revision', 'How to brief a creator so you get one revision, not five',
 'Most revision spirals are briefing failures, not creator failures. The one-page structure that cuts approval cycles, plus the four things you must never put in a brief.',
 'If you are on revision four, the brief was wrong. Almost always.\n\n## The one-page structure\n\n1. **The one thing.** A single sentence a viewer should remember. Not three.\n2. **Mandatories.** Product visible, claim wording, disclosure label, handle tag. Keep this under six lines.\n3. **Hard nos.** Competitor products in frame, specific banned claims, music you cannot license.\n4. **Reference, not template.** Two links to work you liked, with one line on why.\n5. **Delivery.** Format, duration, aspect ratio, caption length, due date, revision count.\n\n## The four things that ruin briefs\n\n- **A script.** Hand a creator your words and you get an ad that their audience scrolls past. Give the message, let them find the language.\n- **Vague adjectives.** Premium, youthful, aspirational and clutter-free mean nothing on set.\n- **Late-arriving stakeholders.** Anyone who can reject the video must sign the brief before it goes out.\n- **Open-ended revisions.** Two rounds, named approver, written into the booking.\n\n## Approve the hook before the edit\n\nAsk for the first five seconds and the on-screen text as a still, before the creator edits. Ninety percent of disagreements live in the hook and fixing it there costs the creator ten minutes instead of a reshoot.\n\n## Say what the post is for\n\nA creator who knows the asset will be whitelisted into paid ads will shoot a cleaner hook, hold the product longer and leave safe framing at the edges. Withholding that context is how brands end up with an asset they legally own and cannot use.',
 'https://picsum.photos/seed/zuste-blog-creator-brief/1200/630',
 1, 'Zusty Editorial', '["briefing","workflow","brand-playbook","creative"]', 6, 13070,
 'published', 0, DATE_SUB(NOW(), INTERVAL 283 DAY),
 'How to brief a creator so you get one revision, not five | Zusty',
 'A one-page creator brief structure that cuts approval cycles, the four briefing habits that cause revision spirals, and why you should approve the hook first.',
 'creator brief template, influencer campaign brief, revision cycles',
 'https://picsum.photos/seed/zuste-blog-creator-brief/1200/630',
 DATE_SUB(NOW(), INTERVAL 285 DAY), DATE_SUB(NOW(), INTERVAL 255 DAY)),

(1310, 1, 'tds-194r-and-free-products-what-brands-must-deduct', 'TDS 194R and free products: what brands must deduct',
 'Section 194R put a 10 percent obligation on benefits given in kind. Here is how it lands on gifting campaigns, PR boxes and creator trips, and how to handle it without ruining the relationship.',
 'Section 194R of the Income Tax Act requires 10 percent tax to be deducted on the value of any benefit or perquisite given in the course of business. Influencer gifting is squarely inside it.\n\n## The threshold\n\nThe obligation applies once the aggregate value of benefits to one recipient crosses Rs 20,000 in a financial year. Individuals and HUFs with turnover below the audit thresholds are exempt as deductors, but that exemption rarely covers a funded D2C brand.\n\n## Where it bites\n\n- **PR boxes** the creator keeps after the post\n- **Devices and appliances** sent for review and not returned\n- **Trips, stays and event access** provided free\n- **Product plus cash** deals, where the in-kind portion is valued separately\n\n## Returned product is a clean exemption\n\nCBDT guidance is explicit that if the product is used for the review and returned to the brand, no benefit has been conferred and 194R does not apply. Build a return workflow with a courier label in the box and a large share of your compliance problem disappears.\n\n## How to handle it commercially\n\nTwo workable approaches. Either gross up: send the product and pay the 10 percent to the government yourself, treating the tax as campaign cost. Or, for hybrid deals, deduct the in-kind tax from the cash component and issue Form 16A so the creator can claim credit.\n\n## Tell the creator before you ship\n\nThe worst version of this is a creator discovering a Rs 4,000 deduction against a Rs 40,000 hamper they thought was free. Put the treatment in the booking terms and it becomes routine.\n\nThis is general information, not tax advice. Confirm the treatment with your finance team.',
 'https://picsum.photos/seed/zuste-blog-tds-194r/1200/630',
 1, 'Zusty Editorial', '["tds","194r","compliance","gifting","brand-finance"]', 8, 8420,
 'published', 0, DATE_SUB(NOW(), INTERVAL 269 DAY),
 'TDS 194R and free products: what brands must deduct | Zusty',
 'How Section 194R applies to influencer gifting, PR boxes and creator trips in India, the Rs 20,000 threshold, the returned-product exemption and how to handle deduction.',
 'tds 194r influencer, gifting tax india, brand compliance creators',
 'https://picsum.photos/seed/zuste-blog-tds-194r/1200/630',
 DATE_SUB(NOW(), INTERVAL 272 DAY), DATE_SUB(NOW(), INTERVAL 240 DAY)),

(1311, 1, 'building-a-diwali-creator-calendar-that-starts-in-august', 'Building a Diwali creator calendar that starts in August',
 'Festive inventory sells out by mid-September and rates climb 40 percent in October. A twelve-week plan for locking creators, shipping product and staging content before the rush.',
 'Every year the same thing happens. Brands start Diwali outreach in the first week of October, discover their shortlist is booked, and pay a premium for whoever is left.\n\n## Week 1 to 2: shortlist and lock\n\nBuild a list three times larger than you need. Send offers with a clear festive window and a small advance to hold the slot. Creators honour a booked date far more reliably than a verbal yes.\n\n## Week 3 to 4: product and logistics\n\nShip everything by the end of August. Festive courier volumes make September deliveries genuinely unpredictable, and a creator who receives product two days before their shoot date will deliver something rushed.\n\n## Week 5 to 7: shoot the evergreen half\n\nSplit content into evergreen and festive. Unboxing, texture shots, how-to-use and testimonial content can be shot in September with no festive dressing and used all quarter. Only the gifting, decor and family-moment content genuinely needs to be shot late.\n\n## Week 8 to 10: staged posting\n\nStart posting 18 to 21 days before the festival. Purchase intent for gifting begins about three weeks out and the last 96 hours are dominated by marketplace ads you cannot outbid. Creators who post on Diwali day get lovely engagement and almost no orders.\n\n## Week 11 to 12: whitelist and scale\n\nBy now you know which two assets are working. Push those into paid with usage rights you negotiated in week one, not rights you are begging for in week eleven.\n\n## Budget for the premium anyway\n\nEven with perfect planning, festive rates run 20 to 40 percent above baseline. Locking in August gets you the low end of that band instead of the high end.',
 'https://picsum.photos/seed/zuste-blog-diwali-calendar/1200/630',
 1, 'Zusty Editorial', '["festive","diwali","campaign-planning","seasonality"]', 7, 19830,
 'published', 1, DATE_SUB(NOW(), INTERVAL 255 DAY),
 'Building a Diwali creator calendar that starts in August | Zusty',
 'A twelve-week festive influencer plan for Indian brands: when to lock creators, when to ship product, how to split evergreen from festive content and when to start posting.',
 'diwali influencer campaign, festive marketing india, creator calendar',
 'https://picsum.photos/seed/zuste-blog-diwali-calendar/1200/630',
 DATE_SUB(NOW(), INTERVAL 258 DAY), DATE_SUB(NOW(), INTERVAL 210 DAY)),

(1312, 2, 'why-your-reel-died-in-the-first-three-seconds', 'Why your Reel died in the first three seconds',
 'Retention data from thousands of branded Reels points to the same five openings failing. What to do instead, and why the fix is usually a caption, not a camera.',
 'You can see it in the retention graph. A cliff between second one and second three, then a flat line of nobody.\n\n## The five openings that kill a Reel\n\n- **The logo.** Nobody stops for a brand mark. Put it at second four.\n- **The greeting.** Hi guys, so today I wanted to talk about is three seconds of nothing.\n- **The slow pan.** Establishing shots are for films. Reels open in the middle of the action.\n- **The unlabelled face.** A talking head with no on-screen text gives a muted scroller zero reason to stay.\n- **The vague promise.** This changed my skin means nothing. In 11 days my forehead texture went means something.\n\n## Text is the hook, not the voice\n\nA large share of Indian Reel views start muted. If your first frame does not carry a legible line of text stating the payoff, you are hooking a fraction of your audience. Keep it under seven words, high contrast, and clear of the interface at the top and bottom.\n\n## Open on the result\n\nShow the finished dish, the transformed room, the reaction, then rewind into how. Curiosity about a visible outcome holds attention far better than a chronological build.\n\n## Look at the right number\n\nOptimise for retention at three seconds and completion rate, not for views. Views are downstream of both. A Reel holding 60 percent at three seconds will get distributed. One holding 25 percent will not, no matter how good the second half is.\n\n## Reshoot the first three seconds, not the video\n\nIf a branded Reel underperforms, offering the creator a paid hook reshoot is far cheaper than a new production, and turns a dead asset into a usable one more often than most brands expect.',
 'https://picsum.photos/seed/zuste-blog-reel-hooks/1200/630',
 1, 'Zusty Editorial', '["reels","hooks","retention","creative"]', 6, 27410,
 'published', 0, DATE_SUB(NOW(), INTERVAL 241 DAY),
 'Why your Reel died in the first three seconds | Zusty',
 'The five Reel openings that lose Indian audiences, why on-screen text matters more than voiceover, and the retention metrics worth optimising for.',
 'reel hooks india, instagram retention, branded content creative',
 'https://picsum.photos/seed/zuste-blog-reel-hooks/1200/630',
 DATE_SUB(NOW(), INTERVAL 243 DAY), DATE_SUB(NOW(), INTERVAL 200 DAY)),

(1313, 1, 'whitelisting-creator-content-for-paid-ads-in-india', 'Whitelisting creator content for paid ads in India',
 'Running ads from a creator handle beats running them from your brand page, often by a wide margin. How partnership ads work, what to negotiate, and what it should cost.',
 'The single highest-leverage thing most Indian D2C brands are still not doing is running paid media from creator handles.\n\n## What whitelisting actually means\n\nThe creator grants your ad account permission to run their post, or new dark posts, from their handle. The ad carries their name, their profile picture and their social proof, and lands in feed looking like content rather than advertising.\n\n## Why it works\n\nSame creative, different sender. In our campaign data the creator-handle version typically delivers a 20 to 45 percent lower cost per click than the identical asset run from the brand page, and holds that advantage for longer before fatiguing.\n\n## Negotiate it upfront\n\nUsage rights are cheap before the shoot and expensive after a post goes viral. Standard terms worth asking for:\n\n- 30 to 90 days of paid usage from first post\n- Permission to create dark posts, so you can test hooks without cluttering the creator grid\n- Whether the creator comments section stays open, and who moderates it\n- A named rate for extension, so renewal is not a fresh negotiation\n\n## What it costs\n\nExpect 30 to 60 percent of the base content fee per 30-day window. A Rs 25,000 Reel with 60 days of paid usage lands around Rs 40,000 to Rs 50,000 all in.\n\n## The operational detail everyone forgets\n\nThe creator must grant access through their professional dashboard, and the permission expires. Diarise the expiry. Nothing is more annoying than a winning ad set switching off mid-sale because a 30-day grant lapsed on a Saturday.',
 'https://picsum.photos/seed/zuste-blog-whitelisting/1200/630',
 1, 'Zusty Editorial', '["whitelisting","paid-media","usage-rights","performance"]', 7, 15380,
 'published', 0, DATE_SUB(NOW(), INTERVAL 228 DAY),
 'Whitelisting creator content for paid ads in India | Zusty',
 'How creator whitelisting and partnership ads work, why they outperform brand-handle ads, what usage rights to negotiate and what a fair extension rate looks like.',
 'creator whitelisting india, partnership ads, usage rights influencer',
 'https://picsum.photos/seed/zuste-blog-whitelisting/1200/630',
 DATE_SUB(NOW(), INTERVAL 230 DAY), DATE_SUB(NOW(), INTERVAL 180 DAY)),

(1314, 4, 'what-we-learned-from-480-bookings-on-zusty', 'What we learned from 480 bookings on Zusty',
 'Acceptance rates, delivery times, dispute causes and the day of the week that quietly predicts whether a booking completes. An honest read of our own marketplace data.',
 'We looked at every booking on the platform, from first request to final payout, and pulled out the patterns that surprised us.\n\n## Acceptance is decided in the first message\n\nBookings where the brand wrote more than 40 words in the initial request were accepted 2.3 times more often than one-line offers. Creators read effort as a proxy for whether the campaign will be painful.\n\n## Speed matters more than money\n\nOffers answered within four hours completed at 88 percent. Offers that sat for more than 48 hours completed at 51 percent, even at higher rates. Momentum, not price, is the strongest completion signal we have.\n\n## Where disputes come from\n\nOf the bookings that reached a dispute, the causes clustered tightly:\n\n- Deliverable scope disagreement, 41 percent\n- Late delivery past the agreed window, 27 percent\n- Content edited or taken down early, 18 percent\n- Payment and invoice mismatches, 14 percent\n\nAlmost all of it is scope written vaguely at the start.\n\n## Tuesday and Wednesday\n\nBookings created on a Tuesday or Wednesday completed noticeably more often than those created on a Friday. Our best guess is that a Friday offer sits unread over the weekend and loses the momentum described above.\n\n## Payment protection changed creator behaviour\n\nAfter brand payments started being held by Zusty at confirmation, average time to first deliverable dropped by just under three days. Creators shoot faster when they can see the money is already committed.\n\n## What we changed because of this\n\nWe now nudge brands whose first message is under 40 words, surface a response-time badge on creator profiles, and require a structured deliverable list before a booking can be confirmed.',
 'https://picsum.photos/seed/zuste-blog-480-bookings/1200/630',
 1, 'Zusty Editorial', '["data","marketplace","case-study","platform"]', 8, 22140,
 'published', 1, DATE_SUB(NOW(), INTERVAL 214 DAY),
 'What we learned from 480 bookings on Zusty | Zusty',
 'Marketplace data on influencer booking acceptance rates, response times, dispute causes and completion patterns across 480 Indian brand-creator collaborations.',
 'influencer marketplace data, booking completion, creator disputes',
 'https://picsum.photos/seed/zuste-blog-480-bookings/1200/630',
 DATE_SUB(NOW(), INTERVAL 216 DAY), DATE_SUB(NOW(), INTERVAL 150 DAY)),

(1315, 4, 'case-study-velvetfern-nano-creator-wave', 'Case study: Velvetfern got 4.1x ROAS from 12 nano creators',
 'A Bengaluru skincare brand skipped the macro shortlist entirely, booked twelve creators under 10k followers for one serum launch, and beat their own paid benchmark.',
 'Velvetfern Skincare had a launch budget of Rs 3,20,000 and a new niacinamide serum with no reviews and no social proof.\n\n## The decision\n\nThe obvious plan was two 300k beauty creators. Instead the team booked twelve creators between 4k and 9k followers, all in Bengaluru, Hyderabad and Chennai, at an average of Rs 9,500 per Reel plus product.\n\n## The structure\n\nEach creator got the same brief with one variable: the specific skin concern they personally deal with. Twelve creators, twelve genuinely different opening lines, one product claim. Posting was staged across nine days so the product appeared repeatedly in the same city feeds rather than all at once.\n\n## The numbers\n\n- Spend on creators: Rs 1,14,000\n- Product and shipping: Rs 26,000\n- Paid amplification on the three best assets: Rs 1,60,000\n- Attributed revenue in 30 days: Rs 12,30,000\n- Blended ROAS: 4.1x\n\n## What actually drove it\n\nThe three assets that were whitelisted into paid did 78 percent of the revenue. The other nine creators were not wasted spend, they were the audition round. Without twelve variations the team would never have found the hook that worked, which turned out to be a plain unfiltered close-up with no music.\n\n## The uncomfortable finding\n\nThe two highest-follower creators in the wave, both around 9k, performed worst. The best performer had 4,200 followers and a comment section that read like a group chat.\n\n## What they did next\n\nVelvetfern now runs a standing wave of eight to ten nano creators per quarter and treats it as a creative testing line item rather than an awareness one.',
 'https://picsum.photos/seed/zuste-case-velvetfern/1200/630',
 1, 'Zusty Editorial', '["case-study","skincare","nano-influencers","roas"]', 6, 17920,
 'published', 1, DATE_SUB(NOW(), INTERVAL 198 DAY),
 'Case study: Velvetfern got 4.1x ROAS from 12 nano creators | Zusty',
 'How a Bengaluru skincare brand launched a serum with twelve nano creators instead of two macro names, and turned three of the assets into its best paid ads.',
 'skincare influencer case study, nano creator roas, d2c launch india',
 'https://picsum.photos/seed/zuste-case-velvetfern/1200/630',
 DATE_SUB(NOW(), INTERVAL 200 DAY), DATE_SUB(NOW(), INTERVAL 160 DAY)),

(1316, 4, 'case-study-chutneyverse-sold-out-a-spice-box', 'Case study: Chutneyverse sold out a spice box with food vloggers',
 'Seven regional food creators, one festive gifting box, 1,800 units gone in eleven days. The unglamorous logistics decision that made the campaign work.',
 'Chutneyverse Foods makes small-batch regional spice blends. Their festive gifting box had sold 340 units the previous year, mostly to existing customers.\n\n## The plan\n\nSeven food creators, each in a different language and region: Marathi in Pune, Tamil in Coimbatore, Bengali in Kolkata, Malayalam in Kochi, Hindi in Lucknow, Telugu in Hyderabad and English in Mumbai. Each cooked one dish using one blend from the box, in their own kitchen, in their own language.\n\n## The logistics decision\n\nThe team shipped every creator three boxes six weeks early: one to cook with, one to keep, one to give away to their audience. That third box turned every post into a comment-driven giveaway thread and roughly doubled comment volume without any paid spend.\n\n## The numbers\n\n- Creator fees: Rs 2,45,000 across seven bookings\n- Product and courier: Rs 18,400\n- Units sold in 11 days: 1,806\n- Revenue: Rs 21,67,200\n- Cost per acquired customer: Rs 146\n\n## What worked\n\nRegional language content carried the campaign. The Malayalam and Marathi Reels each outperformed the English one by more than 3x on saves, and saves were the metric that tracked closest to orders.\n\n## What did not\n\nA planned Instagram Live cook-along drew 210 concurrent viewers and produced 14 orders. It was pleasant and it was not worth the four hours of coordination.\n\n## The takeaway\n\nRegional food creators are still dramatically underpriced relative to the intent they produce, and a giveaway unit costs less than any comparable engagement you could buy.',
 'https://picsum.photos/seed/zuste-case-chutneyverse/1200/630',
 1, 'Zusty Editorial', '["case-study","food","regional-creators","festive"]', 6, 13650,
 'published', 0, DATE_SUB(NOW(), INTERVAL 184 DAY),
 'Case study: Chutneyverse sold out a spice box with food vloggers | Zusty',
 'How seven regional Indian food creators sold 1,800 festive spice boxes in eleven days, and why the giveaway unit was the highest-leverage line in the budget.',
 'food influencer case study, regional creators india, festive gifting campaign',
 'https://picsum.photos/seed/zuste-case-chutneyverse/1200/630',
 DATE_SUB(NOW(), INTERVAL 186 DAY), DATE_SUB(NOW(), INTERVAL 150 DAY)),

(1317, 4, 'case-study-kiranapay-and-the-finance-creator-trust-problem', 'Case study: Kiranapay and the finance creator trust problem',
 'Fintech has the hardest compliance surface in Indian influencer marketing. How Kiranapay ran 9 finance creators without a single takedown, and what it cost in process.',
 'Kiranapay Labs needed awareness for a UPI-linked savings feature. Finance content in India carries real regulatory weight, and a careless creator line becomes a company problem.\n\n## The constraint\n\nNo returns language, no promised outcomes, no comparison against named competitors, no implied advisory. Every claim had to be traceable to approved product copy.\n\n## The process that made it work\n\n- A two-page claims sheet with approved and banned phrasing, sent before the brief\n- Script-level review of the spoken claims only, with full creative freedom on everything else\n- A 24-hour compliance review window written into the booking, with a fee for delays caused by the brand\n- A named contact who could approve, not a committee\n\n## The numbers\n\n- Nine creators, average 68k followers, personal finance and markets\n- Total fees: Rs 8,40,000\n- App installs attributed in 45 days: 41,300\n- Cost per install: Rs 20.34, against a paid social benchmark of Rs 63\n- Compliance takedowns: zero\n\n## The surprise\n\nCreators did not resist review. They resisted slow review. When the team committed to a 24-hour turnaround and honoured it, the friction disappeared entirely. The one booking that ran late did so because a brand-side approver was on leave.\n\n## What transferred\n\nThe claims sheet is now used for every campaign the company runs, including its own paid social. Writing down what may and may not be said turned out to be useful well beyond influencer work.',
 'https://picsum.photos/seed/zuste-case-kiranapay/1200/630',
 1, 'Zusty Editorial', '["case-study","fintech","compliance","finance-creators"]', 7, 10480,
 'published', 0, DATE_SUB(NOW(), INTERVAL 171 DAY),
 'Case study: Kiranapay and the finance creator trust problem | Zusty',
 'How an Indian fintech ran nine finance creators with zero compliance takedowns, using a claims sheet, script-level review and a committed 24-hour approval window.',
 'fintech influencer marketing india, finance creators compliance, cost per install',
 'https://picsum.photos/seed/zuste-case-kiranapay/1200/630',
 DATE_SUB(NOW(), INTERVAL 173 DAY), DATE_SUB(NOW(), INTERVAL 140 DAY)),

(1318, 4, 'case-study-homestay-hopper-cut-cac-with-travel-micro-creators', 'Case study: Homestay Hopper cut CAC 38% with travel micro-creators',
 'Travel has long lead times and terrible attribution. A Goa-based tour operator fixed both with six micro creators, a dedicated landing page each, and a lot of patience.',
 'Homestay Hopper Trips sells small-group trips in the Western Ghats and Konkan. Average ticket Rs 24,000, decision cycle six to ten weeks.\n\n## The attribution problem\n\nTravel purchases almost never happen in the same session as discovery. Standard link tracking made every creator look useless. The fix was to give each creator a named landing page and a distinct discount code, then measure over a 90-day window rather than 7 days.\n\n## The campaign\n\nSix travel creators between 30k and 90k followers, each hosted on a real trip rather than a staged shoot. Deliverables were one Reel, one carousel and a five-frame story sequence, spread over three weeks so the content followed the arc of the trip.\n\n## The numbers\n\n- Creator fees and trip costs: Rs 6,90,000\n- Bookings attributed in 90 days: 84\n- Revenue: Rs 20,16,000\n- CAC: Rs 8,214 against a previous blended CAC of Rs 13,250, a 38 percent reduction\n\n## The long tail\n\nThirty-one of the 84 bookings landed between day 45 and day 90. On a 7-day attribution window this campaign would have been declared a failure and cancelled.\n\n## The unexpected asset\n\nThe carousels outperformed the Reels for bookings, though not for reach. Prospective travellers screenshot itineraries, prices and packing lists. Static, information-dense content converts in travel in a way it does not in beauty or food.\n\n## What they changed\n\nHomestay Hopper now requires an itinerary carousel in every travel creator brief and never reports on a window shorter than 60 days.',
 'https://picsum.photos/seed/zuste-case-homestay-hopper/1200/630',
 1, 'Zusty Editorial', '["case-study","travel","attribution","cac"]', 7, 11930,
 'published', 0, DATE_SUB(NOW(), INTERVAL 158 DAY),
 'Case study: Homestay Hopper cut CAC 38% with travel micro-creators | Zusty',
 'How an Indian travel operator used six micro creators, per-creator landing pages and a 90-day attribution window to cut customer acquisition cost by 38 percent.',
 'travel influencer marketing india, creator attribution, cac reduction',
 'https://picsum.photos/seed/zuste-case-homestay-hopper/1200/630',
 DATE_SUB(NOW(), INTERVAL 160 DAY), DATE_SUB(NOW(), INTERVAL 130 DAY)),

(1319, 3, 'payment-protection-and-creator-payments', 'Payment protection and why creators stopped chasing payments',
 'Payment terms are the most common reason Indian creators refuse repeat work. Here is how payment protection on Zusty works, when a payment is released, and what happens in a dispute.',
 'Ask any working creator about their worst brand experience and you will hear about a payment, not a brief.\n\n## The old default was 60 to 90 days\n\nA creator delivers, the brand posts, and the invoice enters a procurement queue. Two months later the creator is sending polite follow-ups to a marketing manager who has changed jobs. That dynamic broke trust across the whole category.\n\n## How payment protection changes it\n\nWhen a booking is confirmed, the brand''s payment is held by Zusty. The creator can see that the booking is paid for before shooting anything, and the brand knows the money is released only when the work is approved.\n\n## The release rules\n\n- The payment is held by Zusty from confirmation, and the booking shows it as held to both sides\n- On delivery, the brand approves the work or asks for changes within the revision rounds in the booking\n- If the brand does not respond within 7 days of delivery, the payment is released to the creator automatically\n- When the work is approved, the creator''s earning, after the platform commission, moves to their Zusty wallet\n- Earnings are held in the wallet for three days. After that the creator can request a withdrawal to a verified payout account, and our team checks every withdrawal before it is paid\n\n## Disputes\n\nEither side can raise a dispute before the work is approved. The payment stays held while our team reviews the brief, the delivered work and the chat, and no money is released until that review is done.\n\n## Why it matters\n\nA creator who can see that a booking is paid for can plan the shoot without chasing anyone, and a brand that pays once knows exactly when the money moves.',
 'https://picsum.photos/seed/zuste-blog-payment-protection/1200/630',
 1, 'Zusty Product', '["payment-protection","payments","product-update","trust"]', 6, 9240,
 'published', 0, DATE_SUB(NOW(), INTERVAL 145 DAY),
 'Payment protection and why creators stopped chasing payments | Zusty',
 'How payment protection works on Zusty: when a brand''s payment is held, how approval releases it and what happens during a dispute.',
 'creator payment protection india, creator payments, held payments',
 'https://picsum.photos/seed/zuste-blog-payment-protection/1200/630',
 DATE_SUB(NOW(), INTERVAL 147 DAY), DATE_SUB(NOW(), INTERVAL 120 DAY)),

(1320, 3, 'product-update-payouts-to-bank-or-upi', 'Product update: payouts to your bank account or UPI ID',
 'Creators can now add a UPI ID to their payout account, see each held earning with the date it becomes available, and follow every withdrawal from request to payment.',
 'Payouts were the topic creators asked about most last year, so here is exactly how they work today.\n\n## From approval to your wallet\n\nWhen a brand approves the work, or the booking completes automatically 7 days after delivery, your earning after the platform commission is credited to your Zusty wallet. It is held there for three days and then becomes available to withdraw. The wallet shows each held amount with the date it becomes available.\n\n## Withdrawing to a bank account or UPI ID\n\nYou request a withdrawal from the wallet screen, from Rs 1,000 upwards, to a payout account you have added. A payout account is your bank account, and it can carry a UPI ID as well. Withdrawals need your verification to be approved, and our team checks every request before it is paid, so a payout is not instant. The wallet shows each request until it is paid.\n\n## If a withdrawal cannot be paid\n\nIf the account details do not match, our team rejects the request with a reason and the amount returns to your available balance. Fix the detail and request the withdrawal again.\n\n## A history you can reconcile\n\nEvery earning, hold, release and withdrawal is its own line in the wallet history, with the booking it came from, so you can match each payout to the work behind it.',
 'https://picsum.photos/seed/zuste-blog-payouts-upi/1200/630',
 1, 'Zusty Product', '["product-update","payouts","upi","wallet"]', 4, 7810,
 'published', 0, DATE_SUB(NOW(), INTERVAL 132 DAY),
 'Product update: payouts to your bank account or UPI ID | Zusty',
 'How creator payouts work on Zusty: earnings reach the wallet on approval, are held for three days, and are withdrawn to a bank account or UPI ID after a check by our team.',
 'creator payouts india, upi payout, zusty product update',
 'https://picsum.photos/seed/zuste-blog-payouts-upi/1200/630',
 DATE_SUB(NOW(), INTERVAL 134 DAY), DATE_SUB(NOW(), INTERVAL 110 DAY)),

(1321, 3, 'new-in-zusty-campaign-briefs-applicants-and-shortlists', 'New in Zusty: campaign briefs, applicants and shortlists',
 'Post a brief once, let creators apply, and compare them side by side before you send a single offer. The campaign workflow is out of beta for every brand account.',
 'Until now, booking creators on Zusty meant finding them one at a time. Campaigns change that.\n\n## Post a brief\n\nCreate a campaign with your deliverables, budget band, timeline, category and city preferences. Publish it and matching creators see it in their opportunities feed. You can keep the budget hidden if you would rather negotiate, though briefs showing a band get roughly twice the applications.\n\n## Review applicants in one place\n\nEvery application arrives with the creator profile, their rate for your exact deliverable, their delivery history on the platform and a short pitch. Shortlist, decline, or ask a question without leaving the screen.\n\n## Compare side by side\n\nSelect up to four shortlisted creators and see them in a comparison view: audience cities, engagement rate, average delivery time, completed bookings and quoted price. It replaces the spreadsheet every brand team was building by hand.\n\n## Convert to bookings in bulk\n\nOnce you have picked, send offers to the whole shortlist at once. Each becomes a normal booking with its own thread, payment protection and delivery tracking, so nothing about the rest of the workflow changes.\n\n## For creators\n\nThe opportunities feed is filtered by your categories, city and rate band, so you should not see briefs you would never take. Applying is free and does not count against anything.\n\n## Availability\n\nLive for all brand accounts now. Campaign analytics, including per-applicant response times, arrives next month.',
 'https://picsum.photos/seed/zuste-blog-campaigns-launch/1200/630',
 1, 'Zusty Product', '["product-update","campaigns","shortlist","workflow"]', 5, 6420,
 'published', 0, DATE_SUB(NOW(), INTERVAL 119 DAY),
 'New in Zusty: campaign briefs, applicants and shortlists | Zusty',
 'Brands can now post a campaign brief on Zusty, collect creator applications, compare shortlisted creators side by side and send offers in bulk.',
 'campaign briefs, creator applications, zusty product update',
 'https://picsum.photos/seed/zuste-blog-campaigns-launch/1200/630',
 DATE_SUB(NOW(), INTERVAL 121 DAY), DATE_SUB(NOW(), INTERVAL 100 DAY)),

(1322, 3, 'what-changed-in-our-search-ranking-this-quarter', 'What changed in our search ranking this quarter',
 'Response time and delivery reliability now count for more than follower count in creator search. A plain explanation of what we rank on and why we changed it.',
 'Search is the most important screen on the platform, and it was rewarding the wrong things.\n\n## The old behaviour\n\nRanking leaned heavily on profile completeness and follower count. That surfaced large accounts who replied slowly and buried excellent 20k creators who answered in an hour and always delivered on time.\n\n## What we rank on now\n\n- **Relevance** to the query, category and city, which still dominates\n- **Response time** over the last 30 days\n- **Completion rate** across the last 20 bookings\n- **On-time delivery** against the agreed date\n- **Review quality**, weighted towards recent reviews\n- **Profile completeness and verification status**\n\nFollower count is now a filter you apply, not a ranking signal we apply for you.\n\n## What this means for creators\n\nAnswering offers quickly is the single highest-leverage thing you can do for your visibility. A creator with 18k followers who replies within two hours and has never missed a delivery date will now consistently outrank a 200k account with a 40 percent completion rate.\n\n## Fairness for new creators\n\nA new profile with no booking history is not penalised into invisibility. New creators get a neutral starting position and a visible new badge for their first 60 days, so brands know they are seeing an unproven but not a poor track record.\n\n## What we are watching\n\nWe are monitoring whether this concentrates bookings among a small group of hyper-responsive creators. If it does, we will add rotation so the top of a result set is not identical for every brand every day.',
 'https://picsum.photos/seed/zuste-blog-search-ranking/1200/630',
 1, 'Zusty Product', '["product-update","search","ranking","discovery"]', 5, 0,
 'draft', 0, NULL,
 'What changed in our search ranking this quarter | Zusty',
 'Creator search on Zusty now ranks on response time, completion rate and on-time delivery rather than follower count. Here is the full signal list.',
 'creator search ranking, discovery algorithm, zusty product update',
 'https://picsum.photos/seed/zuste-blog-search-ranking/1200/630',
 DATE_SUB(NOW(), INTERVAL 106 DAY), DATE_SUB(NOW(), INTERVAL 94 DAY)),

(1323, 2, 'pricing-your-first-brand-deal-without-underselling', 'Pricing your first brand deal without underselling',
 'The first quote sets your floor for years. How to arrive at a number you can defend, what to say when a brand asks for your rate card, and the reply to use when they call it expensive.',
 'The first paid deal is where most creators accidentally set a ceiling on the next twenty.\n\n## Build the number from cost, then check it against the market\n\nAdd up what the work genuinely takes: travel, props, shooting hours, editing hours, and the grid slot you cannot resell for a week. Put a real hourly value on your time. That gives you a floor. Then check it against tier ranges for your follower count and pick the higher of the two.\n\n## Quote a package, never an hourly rate\n\nBrands buy outcomes. One Reel, two story frames, 30 days of paid usage, two revisions, delivered by a named date, for Rs X. A package is easy to approve and hard to salami-slice.\n\n## When they ask for a rate card\n\nSend one. A simple PDF with three or four packages and a line saying rates vary with usage rights and exclusivity. Having a document makes you a vendor rather than a favour, and it stops you inventing a number under pressure in a DM.\n\n## When they say it is too expensive\n\nDo not drop the price. Drop the scope. "I can do it at Rs 12,000 if we move to one Reel with no paid usage and one revision." This teaches the brand that price maps to deliverables, and it protects your floor for the next negotiation.\n\n## The things that should always cost extra\n\nPaid usage rights, category exclusivity, raw footage, appearing in the brand grid, extra revisions beyond two, and rush turnaround under 72 hours.\n\n## Write it down\n\nEven a WhatsApp message confirming deliverables, dates, revisions and fee is enough to prevent almost every dispute that lands in our support queue.',
 'https://picsum.photos/seed/zuste-blog-first-brand-deal/1200/630',
 1, 'Zusty Editorial', '["pricing","negotiation","creator-business","rate-card"]', 6, 24380,
 'published', 1, DATE_SUB(NOW(), INTERVAL 98 DAY),
 'Pricing your first brand deal without underselling | Zusty',
 'How new Indian creators should price a first brand collaboration: building the number from cost, quoting packages, handling rate card requests and cutting scope instead of price.',
 'first brand deal pricing, creator rate card india, influencer negotiation',
 'https://picsum.photos/seed/zuste-blog-first-brand-deal/1200/630',
 DATE_SUB(NOW(), INTERVAL 100 DAY), DATE_SUB(NOW(), INTERVAL 60 DAY)),

(1324, 2, 'the-creator-kyc-checklist-pan-gst-bank', 'The creator KYC checklist: PAN, GST, bank and why we ask',
 'Every document we ask for, what it is used for, how long verification takes and what to do when a submission is rejected. Nothing here is optional theatre.',
 'KYC is the least popular screen on any marketplace. Here is exactly why each field exists.\n\n## What we ask for\n\n- **PAN card.** Required for any payout, and for TDS reporting against your PAN. Name must match your bank account holder name.\n- **Aadhaar or another government ID.** Identity verification and address proof.\n- **Bank account or UPI handle.** Where money lands. We run a penny-drop verification to confirm the name matches.\n- **GSTIN, if registered.** Lets us generate compliant invoices and apply the correct tax treatment automatically.\n- **A selfie or liveness check.** Prevents someone else opening a payout account against your documents.\n\n## Why the name matching is strict\n\nIf your PAN says Priyanka and your bank account says Priya, the payout will fail at the bank, not at us. Fixing it after a failed transfer takes days. Fixing it before takes minutes.\n\n## How long it takes\n\nMost submissions are verified within a few hours. Anything requiring manual review is usually resolved within one working day. You will get a notification either way, and the status is always visible on your KYC screen.\n\n## When something is rejected\n\nThe most common reasons are a blurred document edge, a screenshot instead of a scan, a mismatch between the name on PAN and the bank account, and an expired ID. Rejection is never final. Fix the specific issue listed and resubmit from the same screen.\n\n## What we do not do\n\nWe do not share your documents with brands. Brands see a verification badge, not your PAN. Documents are stored encrypted and are accessible only to the compliance reviewers who need them.\n\n## Do it before you need it\n\nComplete verification before your first booking, not after. A creator with pending KYC cannot receive a payout even when the brand has already paid for the booking.',
 'https://picsum.photos/seed/zuste-blog-creator-kyc/1200/630',
 1, 'Zusty Editorial', '["kyc","verification","payouts","creator-onboarding"]', 6, 15720,
 'published', 0, DATE_SUB(NOW(), INTERVAL 84 DAY),
 'The creator KYC checklist: PAN, GST, bank and why we ask | Zusty',
 'Every KYC document Indian creators need for marketplace payouts, why name matching matters, how long verification takes and how to fix a rejected submission.',
 'creator kyc india, pan verification payout, gst creator onboarding',
 'https://picsum.photos/seed/zuste-blog-creator-kyc/1200/630',
 DATE_SUB(NOW(), INTERVAL 86 DAY), DATE_SUB(NOW(), INTERVAL 50 DAY)),

(1325, 1, 'regional-creators-are-the-real-growth-story-of-2026', 'Regional creators are the real growth story of 2026',
 'Tamil, Marathi, Bengali and Bhojpuri creators are delivering better cost per acquisition than English-language peers, and most brand shortlists still do not include them.',
 'The most reliable arbitrage in Indian influencer marketing right now is language.\n\n## The gap\n\nRegional-language creators with comparable engagement still quote 30 to 40 percent below their English-language counterparts in metro cities. Meanwhile their audiences are less saturated with brand content, so the same message lands with far less resistance.\n\n## Where the demand actually is\n\nMost D2C growth is now coming from tier two and tier three cities. A brand whose entire creator roster posts in English is speaking to the smallest and most competitive slice of its addressable market.\n\n## What we see in the data\n\nAcross food, home and personal care campaigns on the platform, regional-language content consistently produces higher save rates and higher comment-to-view ratios than English content from creators of similar size. Saves have been the closest leading indicator of orders in every category we have measured.\n\n## Do not translate, commission\n\nSubtitling an English Reel into Marathi does not work. The rhythm, references and humour are wrong. Brief a Marathi creator on the message and let them build the content in their own idiom, including their own product name pronunciation.\n\n## The operational catch\n\nYou need someone who can actually review the content. Approving a Malayalam script you cannot read is not approval. Either hire a reviewer per language or accept genuine creative delegation and review only the claims, which is what the best teams do.\n\n## Where to start\n\nPick the two languages that match your top non-metro order clusters and run four creators in each. It costs less than one metro macro creator and will tell you more.',
 'https://picsum.photos/seed/zuste-blog-regional-creators/1200/630',
 1, 'Zusty Editorial', '["regional-creators","vernacular","growth","tier-2"]', 6, 20140,
 'published', 1, DATE_SUB(NOW(), INTERVAL 71 DAY),
 'Regional creators are the real growth story of 2026 | Zusty',
 'Why Tamil, Marathi, Bengali and Bhojpuri creators outperform on cost per acquisition for Indian D2C brands, and how to brief regional content without translating it.',
 'regional influencers india, vernacular creators, tier 2 marketing',
 'https://picsum.photos/seed/zuste-blog-regional-creators/1200/630',
 DATE_SUB(NOW(), INTERVAL 73 DAY), DATE_SUB(NOW(), INTERVAL 40 DAY)),

(1326, 1, 'how-to-run-a-ugc-only-campaign-with-no-posting-rights', 'How to run a UGC-only campaign with no posting rights',
 'Sometimes you do not want the creator to post at all. You want the footage. How UGC-only deals are priced, what the contract must say, and why they are cheaper than you think.',
 'A growing share of creator bookings on the platform never appear on the creator page at all.\n\n## What UGC-only means\n\nThe creator shoots content to your brief and delivers the files. You run it on your own channels and in paid media. Nothing is posted from their handle, and often their face is the only thing of theirs that appears.\n\n## Why brands want it\n\nAgency-produced ad creative is expensive and looks like advertising. A creator delivering six vertical variations for the price of one studio day gives you enough material to test hooks properly. For performance teams this is a production line, not an awareness play.\n\n## How it is priced\n\nUGC-only rates are decoupled from follower count, because you are not buying distribution. Expect Rs 4,000 to Rs 15,000 per finished vertical video from a competent creator, with volume discounts at six or more assets. Someone with 3k followers and excellent lighting is a genuinely great buy here.\n\n## What the contract must cover\n\n- Perpetual or fixed-term usage across paid and owned channels\n- Whether the creator face and likeness can appear in paid ads, and for how long\n- Whether the creator may show the work in their own portfolio\n- Raw file delivery, resolution and aspect ratios\n- Exclusivity, if you do not want them shooting the same for a competitor\n\n## The brief is different\n\nGive shot lists, not stories. Specify the hook variations you want to test, the safe zones for text overlays, and the aspect ratios. You are commissioning components, so be specific in a way you would never be for an organic post.\n\n## The mistake to avoid\n\nDo not book a large creator for UGC-only work and pay their influencer rate. You are buying production, and their audience is not part of the deal.',
 'https://picsum.photos/seed/zuste-blog-ugc-only/1200/630',
 1, 'Zusty Editorial', '["ugc","content-production","paid-media","usage-rights"]', 6, 12660,
 'published', 0, DATE_SUB(NOW(), INTERVAL 58 DAY),
 'How to run a UGC-only campaign with no posting rights | Zusty',
 'What UGC-only creator bookings are, how they are priced in India, the contract terms that matter and how a UGC brief differs from an influencer brief.',
 'ugc creators india, ugc rates, content production influencer',
 'https://picsum.photos/seed/zuste-blog-ugc-only/1200/630',
 DATE_SUB(NOW(), INTERVAL 60 DAY), DATE_SUB(NOW(), INTERVAL 30 DAY)),

(1327, 2, 'contract-clauses-creators-should-never-sign', 'Contract clauses creators should never sign',
 'Perpetual worldwide rights, unlimited revisions, indefinite exclusivity and morality clauses with no definition. The eight lines worth pushing back on, and the language to counter with.',
 'Most brand contracts are drafted for suppliers of steel, then lightly edited for creators. Read them anyway.\n\n## Perpetual, worldwide, irrevocable rights\n\nThis is the most common overreach. You are being asked to hand over the asset forever, for every medium, for a single Reel fee. Counter with a term: 12 months, digital media, India, with a named renewal rate.\n\n## Unlimited revisions until satisfaction\n\nSatisfaction is undefined and unbounded. Counter with two rounds, a named approver and a fee for further rounds.\n\n## Indefinite category exclusivity\n\nAgreeing not to work with any competing brand, with no end date, can quietly remove an entire category from your business. Counter with a defined window, 30 to 90 days, a named list of competitors rather than a whole category, and a fee attached.\n\n## Payment on the brand posting\n\nIf the trigger for payment is the brand publishing the content, an internal delay becomes your cash flow problem. Counter with payment on acceptance of delivery.\n\n## Uncapped indemnity\n\nYou should stand behind your own conduct and your own disclosure. You should not be underwriting the brand product claims. Ask for indemnity limited to the fee paid, and carved out for claims supplied by the brand.\n\n## Undefined morality clauses\n\nA clause allowing termination for anything bringing the brand into disrepute, with no definition, is a clause allowing termination for anything. Ask for specific, objective triggers.\n\n## Content takedown on demand with no fee\n\nIf the brand can require removal at any time, agree a minimum live period, typically 30 to 90 days, after which removal is fine.\n\n## Assignment to any third party\n\nYour deal with a brand should not become a deal with whoever they sell to. Ask for consent on assignment.\n\nNone of this is legal advice. For anything above a few lakh, have a lawyer read it once and reuse their edits forever.',
 'https://picsum.photos/seed/zuste-blog-contract-clauses/1200/630',
 1, 'Zusty Editorial', '["contracts","legal","creator-business","negotiation"]', 8, 0,
 'draft', 0, NULL,
 'Contract clauses creators should never sign | Zusty',
 'Eight clauses Indian creators should push back on in brand contracts, from perpetual usage rights to undefined morality clauses, with counter language for each.',
 'influencer contract india, creator legal, usage rights clause',
 'https://picsum.photos/seed/zuste-blog-contract-clauses/1200/630',
 DATE_SUB(NOW(), INTERVAL 44 DAY), DATE_SUB(NOW(), INTERVAL 22 DAY)),

(1328, 1, 'measuring-influencer-campaigns-beyond-likes', 'Measuring influencer campaigns beyond likes',
 'A measurement stack that survives contact with a CFO: the four metrics that predict revenue, how to run a clean holdout in a small market, and what to stop reporting entirely.',
 'If your campaign report leads with reach and impressions, your finance team is right to be sceptical.\n\n## Stop reporting these\n\nReach, impressions and follower growth are inputs, not outcomes. They belong in an appendix. Equivalent media value should be deleted from your templates entirely; it is a number with no defensible basis.\n\n## The four that predict revenue\n\n- **Saves per thousand reach.** The strongest leading indicator of purchase intent we track across categories.\n- **Profile and link taps.** Actual navigation towards buying, not passive consumption.\n- **Branded search lift.** Search volume for your brand name during and after the campaign window.\n- **New-customer order rate.** Orders from first-time buyers, which is what an influencer campaign is actually for.\n\n## Run a geographic holdout\n\nYou do not need a data science team. Pick two comparable cities, run creators in one and nothing in the other, and compare new-customer orders across a matched period. In a small market this is more honest than any attribution model you can buy.\n\n## Give attribution a real window\n\nSeven days is far too short outside impulse categories. Beauty and food can be read at 14 to 30 days. Travel, furniture and education need 60 to 90. Reporting on the wrong window kills campaigns that were working.\n\n## Codes and links both, always\n\nUnique discount codes capture the people who remember, per-creator landing pages capture the people who click, and the overlap tells you something useful about how the audience behaves.\n\n## Report the failures\n\nA report showing which three of ten creators did not work, and your hypothesis about why, is worth more internally than a deck of only good numbers. It is also the only way the next campaign gets better.',
 'https://picsum.photos/seed/zuste-blog-measurement/1200/630',
 1, 'Zusty Editorial', '["measurement","analytics","attribution","reporting"]', 7, 8930,
 'published', 0, DATE_SUB(NOW(), INTERVAL 33 DAY),
 'Measuring influencer campaigns beyond likes | Zusty',
 'The influencer metrics that actually predict revenue, how to run a geographic holdout test in India, and the attribution windows that suit each category.',
 'influencer measurement india, campaign attribution, holdout test',
 'https://picsum.photos/seed/zuste-blog-measurement/1200/630',
 DATE_SUB(NOW(), INTERVAL 35 DAY), DATE_SUB(NOW(), INTERVAL 20 DAY)),

(1329, 1, 'youtube-shorts-vs-instagram-reels-for-indian-d2c', 'YouTube Shorts vs Instagram Reels for Indian D2C',
 'Same vertical video, very different economics. Where each platform wins on cost, intent and shelf life, and why the answer changes with your price point.',
 'Brands keep asking which one to buy. The honest answer is that they do different jobs.\n\n## Shelf life\n\nA Reel does most of its work in 72 hours. A Short keeps accumulating views for weeks and sometimes months, because YouTube keeps recommending it against search and related content. For evergreen product education, that difference compounds.\n\n## Intent\n\nYouTube viewers arrive with more intent, particularly for anything considered: electronics, education, finance, appliances. Instagram is stronger for impulse and aesthetic categories, where the purchase decision is emotional and fast.\n\n## Cost\n\nCreator rates for a Short are typically 15 to 30 percent below the same creator rate for a Reel, largely because Instagram is where brand budgets have historically gone. On a cost-per-delivered-view basis, Shorts frequently win.\n\n## The link problem\n\nInstagram keeps traffic inside the app and link taps are constrained. YouTube descriptions and pinned comments allow real links that persist. For anything requiring a landing page visit before purchase, that matters.\n\n## Where the decision flips\n\nBelow roughly Rs 1,500 average order value, Reels usually win: fast, emotional, high volume. Above Rs 5,000, Shorts and longer YouTube integrations tend to win, because buyers want more information before committing.\n\n## What most brands should do\n\nCommission both from the same creator in the same session. The incremental cost is small, the shooting overhead is already paid for, and you learn which platform your category actually belongs on rather than guessing.',
 'https://picsum.photos/seed/zuste-blog-shorts-vs-reels/1200/630',
 1, 'Zusty Editorial', '["youtube-shorts","reels","platform-strategy","d2c"]', 6, 0,
 'draft', 0, NULL,
 'YouTube Shorts vs Instagram Reels for Indian D2C | Zusty',
 'How YouTube Shorts and Instagram Reels compare for Indian D2C brands on shelf life, buyer intent, creator cost and link handling, and when to choose each.',
 'youtube shorts vs reels, d2c platform strategy india, creator content',
 'https://picsum.photos/seed/zuste-blog-shorts-vs-reels/1200/630',
 DATE_SUB(NOW(), INTERVAL 21 DAY), DATE_SUB(NOW(), INTERVAL 9 DAY)),

(1330, 4, 'the-2026-indian-creator-economy-report', 'The 2026 Indian creator economy report',
 'Our annual read on rates, category growth, regional expansion and payment behaviour, built from every booking, offer and payout that moved through Zusty this year.',
 'This is the full-year report. It publishes with the underlying rate tables so you can check our working.\n\n## Headline findings\n\n- Median creator rate rose 14 percent year on year, with the sharpest increase in the 25k to 75k follower band\n- Regional-language bookings grew 61 percent, the fastest-growing segment on the platform\n- Average time from offer to acceptance fell from 31 hours to 9 hours\n- Barter-only deals fell to under 8 percent of all agreed collaborations\n\n## Category movement\n\nFinance, education and home decor grew fastest by booking volume. Fashion remains the largest category by spend but grew slowest, and saw the most competitive rate pressure as supply expanded.\n\n## Rates by tier\n\nThe full tier table covers Instagram Reels, stories, YouTube Shorts, long-form integrations and UGC-only production, split by category and by metro against non-metro. The gap between metro and non-metro rates for equivalent engagement narrowed from 38 percent to 29 percent.\n\n## Payment behaviour\n\nBookings paid for upfront completed at a materially higher rate than unpaid offers, and the median time from delivery acceptance to money in a creator account is now under an hour.\n\n## What we expect next year\n\nContinued rate convergence between metro and regional creators, more UGC-only production budgets moving from agencies to creators, and further consolidation of brand spend into repeat relationships rather than one-off bookings.\n\n## Method\n\nEvery figure is computed from completed transactions on the platform over the last twelve months. Offers that never converted are excluded from rate medians. Nothing here is survey data.',
 'https://picsum.photos/seed/zuste-blog-creator-economy-2026/1200/630',
 1, 'Zusty Editorial', '["report","creator-economy","rates","annual"]', 11, 0,
 'scheduled', 1, DATE_ADD(NOW(), INTERVAL 12 DAY),
 'The 2026 Indian creator economy report | Zusty',
 'Zusty annual report on Indian creator rates, category growth, regional expansion and payment behaviour, computed from a full year of marketplace transactions.',
 'indian creator economy 2026, influencer rates report, creator marketplace data',
 'https://picsum.photos/seed/zuste-blog-creator-economy-2026/1200/630',
 DATE_SUB(NOW(), INTERVAL 10 DAY), DATE_SUB(NOW(), INTERVAL 2 DAY))
ON DUPLICATE KEY UPDATE id = VALUES(id);


/* ==========================================================================
 * 2. MEDIA  (1401 - 1480)
 *    80 assets across media_folders 1-5:
 *      blogs 1401-1430, banners 1431-1442, marketing 1443-1458,
 *      branding 1459-1468, general 1469-1480.
 * ========================================================================== */

/* -- 2a. Blog covers, folder 3 (blogs), one per post 1301-1330 ------------- */
INSERT INTO media
    (id, disk, folder, folder_id, file_name, original_name, path, url, thumbnail_url,
     mime_type, extension, size_bytes, original_size_bytes, is_optimized,
     width, height, duration_secs, alt_text, uploader_type, uploader_id, checksum, created_at)
VALUES
(1401,'local','blogs',3,'how-d2c-brands-pick-micro-influencers-cover.jpg','micro influencer cover final.jpg','blogs/2025/how-d2c-brands-pick-micro-influencers-cover.jpg','https://picsum.photos/seed/zuste-blog-micro-influencers/1200/630','https://picsum.photos/seed/zuste-blog-micro-influencers/320/180','image/jpeg','jpg',248310,612440,1,1200,630,NULL,'Marketing team reviewing a micro-influencer shortlist on a laptop','admin',1,MD5('media-1401'),DATE_SUB(NOW(), INTERVAL 415 DAY)),
(1402,'local','blogs',3,'gst-on-influencer-invoices-explained-cover.jpg','gst invoice explainer cover.jpg','blogs/2025/gst-on-influencer-invoices-explained-cover.jpg','https://picsum.photos/seed/zuste-blog-gst-invoices/1200/630','https://picsum.photos/seed/zuste-blog-gst-invoices/320/180','image/jpeg','jpg',231870,588120,1,1200,630,NULL,'Creator preparing a GST invoice for a brand collaboration','admin',1,MD5('media-1402'),DATE_SUB(NOW(), INTERVAL 401 DAY)),
(1403,'local','blogs',3,'fair-reel-rate-2026-cover.jpg','reel rate card 2026 cover.jpg','blogs/2025/fair-reel-rate-2026-cover.jpg','https://picsum.photos/seed/zuste-blog-reel-rates-2026/1200/630','https://picsum.photos/seed/zuste-blog-reel-rates-2026/320/180','image/jpeg','jpg',267540,701330,1,1200,630,NULL,'Rate card comparison for Instagram Reels across follower tiers','admin',1,MD5('media-1403'),DATE_SUB(NOW(), INTERVAL 375 DAY)),
(1404,'local','blogs',3,'the-barter-trap-cover.jpg','barter trap cover.jpg','blogs/2025/the-barter-trap-cover.jpg','https://picsum.photos/seed/zuste-blog-barter-trap/1200/630','https://picsum.photos/seed/zuste-blog-barter-trap/320/180','image/jpeg','jpg',212450,545900,1,1200,630,NULL,'Unopened PR hamper on a creator desk','admin',1,MD5('media-1404'),DATE_SUB(NOW(), INTERVAL 361 DAY)),
(1405,'local','blogs',3,'reading-a-creator-media-kit-cover.jpg','media kit vetting cover.jpg','blogs/2025/reading-a-creator-media-kit-cover.jpg','https://picsum.photos/seed/zuste-blog-media-kit/1200/630','https://picsum.photos/seed/zuste-blog-media-kit/320/180','image/jpeg','jpg',239120,604810,1,1200,630,NULL,'Brand manager reviewing a creator media kit on a tablet','admin',1,MD5('media-1405'),DATE_SUB(NOW(), INTERVAL 344 DAY)),
(1406,'local','blogs',3,'engagement-rate-benchmarks-india-cover.jpg','engagement benchmark chart cover.jpg','blogs/2025/engagement-rate-benchmarks-india-cover.jpg','https://picsum.photos/seed/zuste-blog-engagement-benchmarks/1200/630','https://picsum.photos/seed/zuste-blog-engagement-benchmarks/320/180','image/jpeg','jpg',221680,571240,1,1200,630,NULL,'Chart of Instagram engagement rates by follower tier in India','admin',1,MD5('media-1406'),DATE_SUB(NOW(), INTERVAL 329 DAY)),
(1407,'local','blogs',3,'asci-disclosure-rules-cover.jpg','asci disclosure cover.jpg','blogs/2025/asci-disclosure-rules-cover.jpg','https://picsum.photos/seed/zuste-blog-asci-disclosure/1200/630','https://picsum.photos/seed/zuste-blog-asci-disclosure/320/180','image/jpeg','jpg',204330,528760,1,1200,630,NULL,'Sponsored post label shown at the top of an Instagram caption','admin',1,MD5('media-1407'),DATE_SUB(NOW(), INTERVAL 314 DAY)),
(1408,'local','blogs',3,'nano-vs-micro-d2c-cover.jpg','nano vs micro cover.jpg','blogs/2025/nano-vs-micro-d2c-cover.jpg','https://picsum.photos/seed/zuste-blog-nano-vs-micro/1200/630','https://picsum.photos/seed/zuste-blog-nano-vs-micro/320/180','image/jpeg','jpg',255210,658400,1,1200,630,NULL,'Side by side comparison of nano and micro creator performance','admin',1,MD5('media-1408'),DATE_SUB(NOW(), INTERVAL 300 DAY)),
(1409,'local','blogs',3,'how-to-brief-a-creator-cover.jpg','creator brief cover.jpg','blogs/2025/how-to-brief-a-creator-cover.jpg','https://picsum.photos/seed/zuste-blog-creator-brief/1200/630','https://picsum.photos/seed/zuste-blog-creator-brief/320/180','image/jpeg','jpg',218940,563170,1,1200,630,NULL,'One page campaign brief printed on a studio table','admin',1,MD5('media-1409'),DATE_SUB(NOW(), INTERVAL 285 DAY)),
(1410,'local','blogs',3,'tds-194r-free-products-cover.jpg','tds 194r cover.jpg','blogs/2025/tds-194r-free-products-cover.jpg','https://picsum.photos/seed/zuste-blog-tds-194r/1200/630','https://picsum.photos/seed/zuste-blog-tds-194r/320/180','image/jpeg','jpg',209870,540220,1,1200,630,NULL,'PR boxes stacked in a brand warehouse ready for dispatch','admin',1,MD5('media-1410'),DATE_SUB(NOW(), INTERVAL 272 DAY)),
(1411,'local','blogs',3,'diwali-creator-calendar-cover.jpg','diwali planning cover.jpg','blogs/2025/diwali-creator-calendar-cover.jpg','https://picsum.photos/seed/zuste-blog-diwali-calendar/1200/630','https://picsum.photos/seed/zuste-blog-diwali-calendar/320/180','image/jpeg','jpg',288420,742610,1,1200,630,NULL,'Festive campaign calendar with creator posting dates marked','admin',1,MD5('media-1411'),DATE_SUB(NOW(), INTERVAL 258 DAY)),
(1412,'local','blogs',3,'why-your-reel-died-cover.jpg','reel hook retention cover.jpg','blogs/2025/why-your-reel-died-cover.jpg','https://picsum.photos/seed/zuste-blog-reel-hooks/1200/630','https://picsum.photos/seed/zuste-blog-reel-hooks/320/180','image/jpeg','jpg',243760,627480,1,1200,630,NULL,'Retention graph showing a sharp drop in the first three seconds','admin',1,MD5('media-1412'),DATE_SUB(NOW(), INTERVAL 243 DAY)),
(1413,'local','blogs',3,'whitelisting-creator-content-cover.jpg','whitelisting cover.jpg','blogs/2025/whitelisting-creator-content-cover.jpg','https://picsum.photos/seed/zuste-blog-whitelisting/1200/630','https://picsum.photos/seed/zuste-blog-whitelisting/320/180','image/jpeg','jpg',236190,608930,1,1200,630,NULL,'Partnership ad running from a creator handle on a phone screen','admin',1,MD5('media-1413'),DATE_SUB(NOW(), INTERVAL 230 DAY)),
(1414,'local','blogs',3,'480-bookings-data-cover.jpg','marketplace data cover.jpg','blogs/2025/480-bookings-data-cover.jpg','https://picsum.photos/seed/zuste-blog-480-bookings/1200/630','https://picsum.photos/seed/zuste-blog-480-bookings/320/180','image/jpeg','jpg',262340,679110,1,1200,630,NULL,'Dashboard showing booking completion rates across a marketplace','admin',1,MD5('media-1414'),DATE_SUB(NOW(), INTERVAL 216 DAY)),
(1415,'local','blogs',3,'case-velvetfern-cover.jpg','velvetfern case study cover.jpg','blogs/2026/case-velvetfern-cover.jpg','https://picsum.photos/seed/zuste-case-velvetfern/1200/630','https://picsum.photos/seed/zuste-case-velvetfern/320/180','image/jpeg','jpg',274580,713920,1,1200,630,NULL,'Niacinamide serum bottle styled for a nano creator shoot','admin',1,MD5('media-1415'),DATE_SUB(NOW(), INTERVAL 200 DAY)),
(1416,'local','blogs',3,'case-chutneyverse-cover.jpg','chutneyverse case study cover.jpg','blogs/2026/case-chutneyverse-cover.jpg','https://picsum.photos/seed/zuste-case-chutneyverse/1200/630','https://picsum.photos/seed/zuste-case-chutneyverse/320/180','image/jpeg','jpg',291630,758410,1,1200,630,NULL,'Festive spice gifting box photographed on a wooden surface','admin',1,MD5('media-1416'),DATE_SUB(NOW(), INTERVAL 186 DAY)),
(1417,'local','blogs',3,'case-kiranapay-cover.jpg','kiranapay case study cover.jpg','blogs/2026/case-kiranapay-cover.jpg','https://picsum.photos/seed/zuste-case-kiranapay/1200/630','https://picsum.photos/seed/zuste-case-kiranapay/320/180','image/jpeg','jpg',228740,592330,1,1200,630,NULL,'Finance creator recording a UPI savings explainer','admin',1,MD5('media-1417'),DATE_SUB(NOW(), INTERVAL 173 DAY)),
(1418,'local','blogs',3,'case-homestay-hopper-cover.jpg','homestay hopper case study cover.jpg','blogs/2026/case-homestay-hopper-cover.jpg','https://picsum.photos/seed/zuste-case-homestay-hopper/1200/630','https://picsum.photos/seed/zuste-case-homestay-hopper/320/180','image/jpeg','jpg',303210,791560,1,1200,630,NULL,'Western Ghats trail photographed during a creator hosted trip','admin',1,MD5('media-1418'),DATE_SUB(NOW(), INTERVAL 160 DAY)),
(1419,'local','blogs',3,'payment-protection-cover.jpg','payment protection explainer cover.jpg','blogs/2026/payment-protection-cover.jpg','https://picsum.photos/seed/zuste-blog-payment-protection/1200/630','https://picsum.photos/seed/zuste-blog-payment-protection/320/180','image/jpeg','jpg',214380,552700,1,1200,630,NULL,'Payment status shown on a booking screen in the Zusty app','admin',1,MD5('media-1419'),DATE_SUB(NOW(), INTERVAL 147 DAY)),
(1420,'local','blogs',3,'payouts-upi-cover.jpg','payouts cover.jpg','blogs/2026/payouts-upi-cover.jpg','https://picsum.photos/seed/zuste-blog-payouts-upi/1200/630','https://picsum.photos/seed/zuste-blog-payouts-upi/320/180','image/jpeg','jpg',207940,536480,1,1200,630,NULL,'Creator wallet screen showing a withdrawal to a UPI ID','admin',1,MD5('media-1420'),DATE_SUB(NOW(), INTERVAL 134 DAY)),
(1421,'local','blogs',3,'campaigns-launch-cover.jpg','campaign briefs launch cover.jpg','blogs/2026/campaigns-launch-cover.jpg','https://picsum.photos/seed/zuste-blog-campaigns-launch/1200/630','https://picsum.photos/seed/zuste-blog-campaigns-launch/320/180','image/jpeg','jpg',225610,583140,1,1200,630,NULL,'Campaign applicants list with shortlisted creators highlighted','admin',1,MD5('media-1421'),DATE_SUB(NOW(), INTERVAL 121 DAY)),
(1422,'local','blogs',3,'search-ranking-update-cover.jpg','search ranking cover.jpg','blogs/2026/search-ranking-update-cover.jpg','https://picsum.photos/seed/zuste-blog-search-ranking/1200/630','https://picsum.photos/seed/zuste-blog-search-ranking/320/180','image/jpeg','jpg',219450,566020,1,1200,630,NULL,'Creator search results ranked by response time and reliability','admin',1,MD5('media-1422'),DATE_SUB(NOW(), INTERVAL 106 DAY)),
(1423,'local','blogs',3,'pricing-first-brand-deal-cover.jpg','first brand deal cover.jpg','blogs/2026/pricing-first-brand-deal-cover.jpg','https://picsum.photos/seed/zuste-blog-first-brand-deal/1200/630','https://picsum.photos/seed/zuste-blog-first-brand-deal/320/180','image/jpeg','jpg',241870,624310,1,1200,630,NULL,'Creator drafting a rate card on a laptop at home','admin',1,MD5('media-1423'),DATE_SUB(NOW(), INTERVAL 100 DAY)),
(1424,'local','blogs',3,'creator-kyc-checklist-cover.jpg','kyc checklist cover.jpg','blogs/2026/creator-kyc-checklist-cover.jpg','https://picsum.photos/seed/zuste-blog-creator-kyc/1200/630','https://picsum.photos/seed/zuste-blog-creator-kyc/320/180','image/jpeg','jpg',211230,545660,1,1200,630,NULL,'Identity documents laid out for a creator KYC submission','admin',1,MD5('media-1424'),DATE_SUB(NOW(), INTERVAL 86 DAY)),
(1425,'local','blogs',3,'regional-creators-growth-cover.jpg','regional creators cover.jpg','blogs/2026/regional-creators-growth-cover.jpg','https://picsum.photos/seed/zuste-blog-regional-creators/1200/630','https://picsum.photos/seed/zuste-blog-regional-creators/320/180','image/jpeg','jpg',279340,724880,1,1200,630,NULL,'Regional language creator filming in a home kitchen','admin',1,MD5('media-1425'),DATE_SUB(NOW(), INTERVAL 73 DAY)),
(1426,'local','blogs',3,'ugc-only-campaign-cover.jpg','ugc only cover.jpg','blogs/2026/ugc-only-campaign-cover.jpg','https://picsum.photos/seed/zuste-blog-ugc-only/1200/630','https://picsum.photos/seed/zuste-blog-ugc-only/320/180','image/jpeg','jpg',233520,602140,1,1200,630,NULL,'Vertical video variations laid out in an editing timeline','admin',1,MD5('media-1426'),DATE_SUB(NOW(), INTERVAL 60 DAY)),
(1427,'local','blogs',3,'contract-clauses-cover.jpg','contract clauses cover.jpg','blogs/2026/contract-clauses-cover.jpg','https://picsum.photos/seed/zuste-blog-contract-clauses/1200/630','https://picsum.photos/seed/zuste-blog-contract-clauses/320/180','image/jpeg','jpg',216780,559930,1,1200,630,NULL,'Marked up influencer agreement with clauses highlighted','admin',1,MD5('media-1427'),DATE_SUB(NOW(), INTERVAL 44 DAY)),
(1428,'local','blogs',3,'measuring-beyond-likes-cover.jpg','measurement cover.jpg','blogs/2026/measuring-beyond-likes-cover.jpg','https://picsum.photos/seed/zuste-blog-measurement/1200/630','https://picsum.photos/seed/zuste-blog-measurement/320/180','image/jpeg','jpg',228960,591470,1,1200,630,NULL,'Campaign measurement dashboard showing saves and link taps','admin',1,MD5('media-1428'),DATE_SUB(NOW(), INTERVAL 35 DAY)),
(1429,'local','blogs',3,'shorts-vs-reels-cover.jpg','shorts vs reels cover.jpg','blogs/2026/shorts-vs-reels-cover.jpg','https://picsum.photos/seed/zuste-blog-shorts-vs-reels/1200/630','https://picsum.photos/seed/zuste-blog-shorts-vs-reels/320/180','image/jpeg','jpg',251340,649820,1,1200,630,NULL,'Same vertical video shown on YouTube Shorts and Instagram Reels','admin',1,MD5('media-1429'),DATE_SUB(NOW(), INTERVAL 21 DAY)),
(1430,'local','blogs',3,'creator-economy-report-2026-cover.jpg','annual report cover.jpg','blogs/2026/creator-economy-report-2026-cover.jpg','https://picsum.photos/seed/zuste-blog-creator-economy-2026/1200/630','https://picsum.photos/seed/zuste-blog-creator-economy-2026/320/180','image/jpeg','jpg',312470,818350,1,1200,630,NULL,'Cover artwork for the 2026 Indian creator economy report','admin',1,MD5('media-1430'),DATE_SUB(NOW(), INTERVAL 10 DAY))
ON DUPLICATE KEY UPDATE id = VALUES(id);

/* -- 2b. App and web banners, folder 2 (banners) --------------------------- */
INSERT INTO media
    (id, disk, folder, folder_id, file_name, original_name, path, url, thumbnail_url,
     mime_type, extension, size_bytes, original_size_bytes, is_optimized,
     width, height, duration_secs, alt_text, uploader_type, uploader_id, checksum, created_at)
VALUES
(1431,'local','banners',2,'home-hero-find-your-creator.webp','home hero v4.png','banners/home/home-hero-find-your-creator.webp','https://picsum.photos/seed/zuste-banner-home-hero/1440/720','https://picsum.photos/seed/zuste-banner-home-hero/320/160','image/webp','webp',184320,946210,1,1440,720,NULL,'Home screen hero banner inviting brands to find a creator','admin',1,MD5('media-1431'),DATE_SUB(NOW(), INTERVAL 380 DAY)),
(1432,'local','banners',2,'diwali-festive-banner.webp','diwali banner final.png','banners/festive/diwali-festive-banner.webp','https://picsum.photos/seed/zuste-banner-diwali/1440/720','https://picsum.photos/seed/zuste-banner-diwali/320/160','image/webp','webp',212840,1084730,1,1440,720,NULL,'Diwali festive campaign banner with diya and marigold styling','admin',1,MD5('media-1432'),DATE_SUB(NOW(), INTERVAL 262 DAY)),
(1433,'local','banners',2,'creator-signup-banner.webp','creator signup banner.png','banners/creators/creator-signup-banner.webp','https://picsum.photos/seed/zuste-banner-creator-signup/1440/720','https://picsum.photos/seed/zuste-banner-creator-signup/320/160','image/webp','webp',176450,892340,1,1440,720,NULL,'Banner encouraging creators to complete their Zusty profile','admin',1,MD5('media-1433'),DATE_SUB(NOW(), INTERVAL 341 DAY)),
(1434,'local','banners',2,'brand-first-campaign-banner.webp','brand onboarding banner.png','banners/brands/brand-first-campaign-banner.webp','https://picsum.photos/seed/zuste-banner-brand-first-campaign/1440/720','https://picsum.photos/seed/zuste-banner-brand-first-campaign/320/160','image/webp','webp',181920,918660,1,1440,720,NULL,'Banner prompting brands to post their first campaign brief','admin',1,MD5('media-1434'),DATE_SUB(NOW(), INTERVAL 318 DAY)),
(1435,'local','banners',2,'upi-payout-banner.webp','payout banner.png','banners/product/upi-payout-banner.webp','https://picsum.photos/seed/zuste-banner-upi-payout/1440/720','https://picsum.photos/seed/zuste-banner-upi-payout/320/160','image/webp','webp',169730,861420,1,1440,720,NULL,'Banner announcing creator payouts to a UPI ID','admin',1,MD5('media-1435'),DATE_SUB(NOW(), INTERVAL 133 DAY)),
(1436,'local','banners',2,'republic-day-sale-banner.webp','republic day banner.png','banners/festive/republic-day-sale-banner.webp','https://picsum.photos/seed/zuste-banner-republic-day/1440/720','https://picsum.photos/seed/zuste-banner-republic-day/320/160','image/webp','webp',194180,983570,1,1440,720,NULL,'Republic Day offer banner for brand subscriptions','admin',1,MD5('media-1436'),DATE_SUB(NOW(), INTERVAL 191 DAY)),
(1437,'local','banners',2,'search-empty-state-banner.webp','search empty state.png','banners/app/search-empty-state-banner.webp','https://picsum.photos/seed/zuste-banner-search-empty/960/540','https://picsum.photos/seed/zuste-banner-search-empty/320/180','image/webp','webp',98240,412870,1,960,540,NULL,'Illustration shown when a creator search returns no results','admin',1,MD5('media-1437'),DATE_SUB(NOW(), INTERVAL 296 DAY)),
(1438,'local','banners',2,'kyc-reminder-banner.webp','kyc reminder banner.png','banners/app/kyc-reminder-banner.webp','https://picsum.photos/seed/zuste-banner-kyc-reminder/1200/600','https://picsum.photos/seed/zuste-banner-kyc-reminder/320/160','image/webp','webp',142360,678930,1,1200,600,NULL,'In-app banner reminding creators to finish KYC verification','admin',1,MD5('media-1438'),DATE_SUB(NOW(), INTERVAL 209 DAY)),
(1439,'local','banners',2,'referral-program-banner.webp','referral banner.png','banners/marketing/referral-program-banner.webp','https://picsum.photos/seed/zuste-banner-referral/1200/600','https://picsum.photos/seed/zuste-banner-referral/320/160','image/webp','webp',151740,714250,1,1200,600,NULL,'Referral programme banner offering wallet credit for invites','admin',1,MD5('media-1439'),DATE_SUB(NOW(), INTERVAL 158 DAY)),
(1440,'local','banners',2,'regional-creators-banner.webp','regional creators banner.png','banners/marketing/regional-creators-banner.webp','https://picsum.photos/seed/zuste-banner-regional/1200/600','https://picsum.photos/seed/zuste-banner-regional/320/160','image/webp','webp',163280,779410,1,1200,600,NULL,'Banner showcasing regional language creators across India','admin',1,MD5('media-1440'),DATE_SUB(NOW(), INTERVAL 74 DAY)),
(1441,'local','banners',2,'ugc-studio-banner.webp','ugc studio banner.png','banners/product/ugc-studio-banner.webp','https://picsum.photos/seed/zuste-banner-ugc-studio/1200/600','https://picsum.photos/seed/zuste-banner-ugc-studio/320/160','image/webp','webp',157920,742180,1,1200,600,NULL,'Banner promoting UGC only bookings for performance teams','admin',1,MD5('media-1441'),DATE_SUB(NOW(), INTERVAL 57 DAY)),
(1442,'local','banners',2,'onboarding-slide-verified-creators.webp','onboarding slide 2.png','banners/app/onboarding-slide-verified-creators.webp','https://picsum.photos/seed/zuste-banner-onboarding-verified/1080/1080','https://picsum.photos/seed/zuste-banner-onboarding-verified/320/320','image/webp','webp',134510,623890,1,1080,1080,NULL,'Onboarding slide explaining verified creator badges','admin',1,MD5('media-1442'),DATE_SUB(NOW(), INTERVAL 355 DAY))
ON DUPLICATE KEY UPDATE id = VALUES(id);

/* -- 2c. Marketing collateral, folder 5 (marketing) ------------------------ */
INSERT INTO media
    (id, disk, folder, folder_id, file_name, original_name, path, url, thumbnail_url,
     mime_type, extension, size_bytes, original_size_bytes, is_optimized,
     width, height, duration_secs, alt_text, uploader_type, uploader_id, checksum, created_at)
VALUES
(1443,'local','marketing',5,'zusty-brand-deck-2026.pdf','Zusty Brand Deck 2026 v7.pdf','marketing/decks/zusty-brand-deck-2026.pdf','https://picsum.photos/seed/zuste-marketing-brand-deck/1200/675','https://picsum.photos/seed/zuste-marketing-brand-deck/320/180','application/pdf','pdf',4821340,4821340,0,NULL,NULL,NULL,'Zusty brand sales deck for 2026','admin',1,MD5('media-1443'),DATE_SUB(NOW(), INTERVAL 214 DAY)),
(1444,'local','marketing',5,'creator-rate-card-template.pdf','Creator rate card template.pdf','marketing/templates/creator-rate-card-template.pdf','https://picsum.photos/seed/zuste-marketing-rate-card/1200/675','https://picsum.photos/seed/zuste-marketing-rate-card/320/180','application/pdf','pdf',1284730,1284730,0,NULL,NULL,NULL,'Downloadable rate card template for Indian creators','admin',1,MD5('media-1444'),DATE_SUB(NOW(), INTERVAL 189 DAY)),
(1445,'local','marketing',5,'campaign-brief-template.pdf','Campaign brief one pager.pdf','marketing/templates/campaign-brief-template.pdf','https://picsum.photos/seed/zuste-marketing-brief-template/1200/675','https://picsum.photos/seed/zuste-marketing-brief-template/320/180','application/pdf','pdf',987420,987420,0,NULL,NULL,NULL,'One page campaign brief template for brand teams','admin',1,MD5('media-1445'),DATE_SUB(NOW(), INTERVAL 176 DAY)),
(1446,'local','marketing',5,'gst-invoice-checklist.pdf','GST invoice checklist for creators.pdf','marketing/guides/gst-invoice-checklist.pdf','https://picsum.photos/seed/zuste-marketing-gst-checklist/1200/675','https://picsum.photos/seed/zuste-marketing-gst-checklist/320/180','application/pdf','pdf',742160,742160,0,NULL,NULL,NULL,'Printable GST invoice checklist for creators','admin',1,MD5('media-1446'),DATE_SUB(NOW(), INTERVAL 302 DAY)),
(1447,'local','marketing',5,'zusty-explainer-60s.mp4','Zusty explainer final 60s.mp4','marketing/video/zusty-explainer-60s.mp4','https://picsum.photos/seed/zuste-marketing-explainer/1920/1080','https://picsum.photos/seed/zuste-marketing-explainer/320/180','video/mp4','mp4',28471920,64328400,1,1920,1080,61,'Sixty second product explainer for the Zusty marketplace','admin',1,MD5('media-1447'),DATE_SUB(NOW(), INTERVAL 245 DAY)),
(1448,'local','marketing',5,'creator-testimonial-aanya.mp4','Aanya testimonial cut.mp4','marketing/video/creator-testimonial-aanya.mp4','https://picsum.photos/seed/zuste-marketing-testimonial-aanya/1080/1920','https://picsum.photos/seed/zuste-marketing-testimonial-aanya/320/180','video/mp4','mp4',19238470,41208630,1,1080,1920,38,'Creator video about payment protection','admin',1,MD5('media-1448'),DATE_SUB(NOW(), INTERVAL 138 DAY)),
(1449,'local','marketing',5,'brand-testimonial-velvetfern.mp4','Velvetfern testimonial.mp4','marketing/video/brand-testimonial-velvetfern.mp4','https://picsum.photos/seed/zuste-marketing-testimonial-velvetfern/1080/1920','https://picsum.photos/seed/zuste-marketing-testimonial-velvetfern/320/180','video/mp4','mp4',22104580,47831260,1,1080,1920,44,'Skincare brand founder describing a nano creator launch','admin',1,MD5('media-1449'),DATE_SUB(NOW(), INTERVAL 122 DAY)),
(1450,'local','marketing',5,'festive-campaign-social-1.jpg','festive social 1.jpg','marketing/social/festive-campaign-social-1.jpg','https://picsum.photos/seed/zuste-marketing-festive-social-1/1080/1080','https://picsum.photos/seed/zuste-marketing-festive-social-1/320/320','image/jpeg','jpg',287340,731920,1,1080,1080,NULL,'Festive social post announcing the Diwali creator wave','admin',1,MD5('media-1450'),DATE_SUB(NOW(), INTERVAL 256 DAY)),
(1451,'local','marketing',5,'festive-campaign-social-2.jpg','festive social 2.jpg','marketing/social/festive-campaign-social-2.jpg','https://picsum.photos/seed/zuste-marketing-festive-social-2/1080/1080','https://picsum.photos/seed/zuste-marketing-festive-social-2/320/320','image/jpeg','jpg',274180,708440,1,1080,1080,NULL,'Second festive social post with creator rate highlights','admin',1,MD5('media-1451'),DATE_SUB(NOW(), INTERVAL 254 DAY)),
(1452,'local','marketing',5,'rate-report-infographic.png','rate report infographic.png','marketing/social/rate-report-infographic.png','https://picsum.photos/seed/zuste-marketing-rate-infographic/1080/1350','https://picsum.photos/seed/zuste-marketing-rate-infographic/320/400','image/png','png',612830,1284730,1,1080,1350,NULL,'Infographic summarising Indian creator rate ranges by tier','admin',1,MD5('media-1452'),DATE_SUB(NOW(), INTERVAL 371 DAY)),
(1453,'local','marketing',5,'newsletter-header-monthly.png','newsletter header.png','marketing/email/newsletter-header-monthly.png','https://picsum.photos/seed/zuste-marketing-newsletter-header/1200/400','https://picsum.photos/seed/zuste-marketing-newsletter-header/320/160','image/png','png',324670,712840,1,1200,400,NULL,'Header image for the monthly Zusty creator newsletter','admin',1,MD5('media-1453'),DATE_SUB(NOW(), INTERVAL 328 DAY)),
(1454,'local','marketing',5,'email-welcome-brand.png','welcome email brand.png','marketing/email/email-welcome-brand.png','https://picsum.photos/seed/zuste-marketing-email-brand/1200/600','https://picsum.photos/seed/zuste-marketing-email-brand/320/160','image/png','png',286410,634920,1,1200,600,NULL,'Welcome email illustration for new brand accounts','admin',1,MD5('media-1454'),DATE_SUB(NOW(), INTERVAL 316 DAY)),
(1455,'local','marketing',5,'email-welcome-creator.png','welcome email creator.png','marketing/email/email-welcome-creator.png','https://picsum.photos/seed/zuste-marketing-email-creator/1200/600','https://picsum.photos/seed/zuste-marketing-email-creator/320/160','image/png','png',291840,648310,1,1200,600,NULL,'Welcome email illustration for new creator accounts','admin',1,MD5('media-1455'),DATE_SUB(NOW(), INTERVAL 316 DAY)),
(1456,'local','marketing',5,'webinar-pricing-masterclass.jpg','webinar pricing.jpg','marketing/events/webinar-pricing-masterclass.jpg','https://picsum.photos/seed/zuste-marketing-webinar-pricing/1280/720','https://picsum.photos/seed/zuste-marketing-webinar-pricing/320/180','image/jpeg','jpg',238940,614370,1,1280,720,NULL,'Promotional image for the creator pricing masterclass webinar','admin',1,MD5('media-1456'),DATE_SUB(NOW(), INTERVAL 96 DAY)),
(1457,'local','marketing',5,'creator-meetup-mumbai.jpg','mumbai meetup photo.jpg','marketing/events/creator-meetup-mumbai.jpg','https://picsum.photos/seed/zuste-marketing-meetup-mumbai/1600/900','https://picsum.photos/seed/zuste-marketing-meetup-mumbai/320/180','image/jpeg','jpg',418720,1092460,1,1600,900,NULL,'Photograph from the Zusty creator meetup in Mumbai','admin',1,MD5('media-1457'),DATE_SUB(NOW(), INTERVAL 68 DAY)),
(1458,'local','marketing',5,'creator-meetup-bengaluru.jpg','bengaluru meetup photo.jpg','marketing/events/creator-meetup-bengaluru.jpg','https://picsum.photos/seed/zuste-marketing-meetup-bengaluru/1600/900','https://picsum.photos/seed/zuste-marketing-meetup-bengaluru/320/180','image/jpeg','jpg',402380,1047230,1,1600,900,NULL,'Photograph from the Zusty creator meetup in Bengaluru','admin',1,MD5('media-1458'),DATE_SUB(NOW(), INTERVAL 41 DAY))
ON DUPLICATE KEY UPDATE id = VALUES(id);

/* -- 2d. Brand identity assets, folder 4 (branding) ------------------------ */
INSERT INTO media
    (id, disk, folder, folder_id, file_name, original_name, path, url, thumbnail_url,
     mime_type, extension, size_bytes, original_size_bytes, is_optimized,
     width, height, duration_secs, alt_text, uploader_type, uploader_id, checksum, created_at)
VALUES
(1459,'local','branding',4,'zusty-logo-primary.png','Zusty logo primary.png','branding/logo/zusty-logo-primary.png','https://picsum.photos/seed/zuste-branding-logo-primary/800/240','https://picsum.photos/seed/zuste-branding-logo-primary/320/96','image/png','png',48730,124860,1,800,240,NULL,'Zusty primary wordmark on a light background','admin',1,MD5('media-1459'),DATE_SUB(NOW(), INTERVAL 421 DAY)),
(1460,'local','branding',4,'zusty-logo-reverse.png','Zusty logo reverse.png','branding/logo/zusty-logo-reverse.png','https://picsum.photos/seed/zuste-branding-logo-reverse/800/240','https://picsum.photos/seed/zuste-branding-logo-reverse/320/96','image/png','png',46210,119340,1,800,240,NULL,'Zusty wordmark in reverse for dark backgrounds','admin',1,MD5('media-1460'),DATE_SUB(NOW(), INTERVAL 421 DAY)),
(1461,'local','branding',4,'zusty-app-icon.png','app icon 1024.png','branding/logo/zusty-app-icon.png','https://picsum.photos/seed/zuste-branding-app-icon/1024/1024','https://picsum.photos/seed/zuste-branding-app-icon/320/320','image/png','png',134280,318420,1,1024,1024,NULL,'Zusty application icon at 1024 pixels','admin',1,MD5('media-1461'),DATE_SUB(NOW(), INTERVAL 420 DAY)),
(1462,'local','branding',4,'zusty-favicon.png','favicon 512.png','branding/logo/zusty-favicon.png','https://picsum.photos/seed/zuste-branding-favicon/512/512','https://picsum.photos/seed/zuste-branding-favicon/128/128','image/png','png',41870,96240,1,512,512,NULL,'Favicon used across the Zusty web properties','admin',1,MD5('media-1462'),DATE_SUB(NOW(), INTERVAL 420 DAY)),
(1463,'local','branding',4,'zusty-brand-guidelines.pdf','Zusty brand guidelines v3.pdf','branding/guidelines/zusty-brand-guidelines.pdf','https://picsum.photos/seed/zuste-branding-guidelines/1200/675','https://picsum.photos/seed/zuste-branding-guidelines/320/180','application/pdf','pdf',6284310,6284310,0,NULL,NULL,NULL,'Zusty brand guidelines covering logo, colour and type','admin',1,MD5('media-1463'),DATE_SUB(NOW(), INTERVAL 398 DAY)),
(1464,'local','branding',4,'zusty-colour-palette.png','colour palette sheet.png','branding/guidelines/zusty-colour-palette.png','https://picsum.photos/seed/zuste-branding-palette/1600/900','https://picsum.photos/seed/zuste-branding-palette/320/180','image/png','png',218460,486730,1,1600,900,NULL,'Zusty colour palette reference sheet','admin',1,MD5('media-1464'),DATE_SUB(NOW(), INTERVAL 398 DAY)),
(1465,'local','branding',4,'og-default-share-card.png','og default card.png','branding/social/og-default-share-card.png','https://picsum.photos/seed/zuste-branding-og-default/1200/630','https://picsum.photos/seed/zuste-branding-og-default/320/168','image/png','png',196340,442180,1,1200,630,NULL,'Default Open Graph share card for Zusty pages','admin',1,MD5('media-1465'),DATE_SUB(NOW(), INTERVAL 386 DAY)),
(1466,'local','branding',4,'email-signature-strip.png','email signature strip.png','branding/email/email-signature-strip.png','https://picsum.photos/seed/zuste-branding-signature/600/120','https://picsum.photos/seed/zuste-branding-signature/300/60','image/png','png',28940,64230,1,600,120,NULL,'Email signature strip used by the Zusty team','admin',1,MD5('media-1466'),DATE_SUB(NOW(), INTERVAL 372 DAY)),
(1467,'local','branding',4,'invoice-letterhead.png','invoice letterhead.png','branding/documents/invoice-letterhead.png','https://picsum.photos/seed/zuste-branding-letterhead/1240/1754','https://picsum.photos/seed/zuste-branding-letterhead/320/452','image/png','png',312840,689420,1,1240,1754,NULL,'Letterhead artwork applied to platform generated invoices','admin',1,MD5('media-1467'),DATE_SUB(NOW(), INTERVAL 344 DAY)),
(1468,'local','branding',4,'verified-badge-set.png','verified badge set.png','branding/icons/verified-badge-set.png','https://picsum.photos/seed/zuste-branding-badges/900/300','https://picsum.photos/seed/zuste-branding-badges/300/100','image/png','png',72180,163940,1,900,300,NULL,'Identity, business and premium verification badge artwork','admin',1,MD5('media-1468'),DATE_SUB(NOW(), INTERVAL 291 DAY))
ON DUPLICATE KEY UPDATE id = VALUES(id);

/* -- 2e. General uploads, folder 1 (general) ------------------------------- */
INSERT INTO media
    (id, disk, folder, folder_id, file_name, original_name, path, url, thumbnail_url,
     mime_type, extension, size_bytes, original_size_bytes, is_optimized,
     width, height, duration_secs, alt_text, uploader_type, uploader_id, checksum, created_at)
VALUES
(1469,'local','general',1,'category-icon-beauty.png','beauty icon.png','general/icons/category-icon-beauty.png','https://picsum.photos/seed/zuste-general-icon-beauty/256/256','https://picsum.photos/seed/zuste-general-icon-beauty/128/128','image/png','png',18420,42130,1,256,256,NULL,'Category icon for beauty creators','admin',1,MD5('media-1469'),DATE_SUB(NOW(), INTERVAL 409 DAY)),
(1470,'local','general',1,'category-icon-food.png','food icon.png','general/icons/category-icon-food.png','https://picsum.photos/seed/zuste-general-icon-food/256/256','https://picsum.photos/seed/zuste-general-icon-food/128/128','image/png','png',17980,41240,1,256,256,NULL,'Category icon for food creators','admin',1,MD5('media-1470'),DATE_SUB(NOW(), INTERVAL 409 DAY)),
(1471,'local','general',1,'category-icon-travel.png','travel icon.png','general/icons/category-icon-travel.png','https://picsum.photos/seed/zuste-general-icon-travel/256/256','https://picsum.photos/seed/zuste-general-icon-travel/128/128','image/png','png',19240,44870,1,256,256,NULL,'Category icon for travel creators','admin',1,MD5('media-1471'),DATE_SUB(NOW(), INTERVAL 409 DAY)),
(1472,'local','general',1,'category-icon-fitness.png','fitness icon.png','general/icons/category-icon-fitness.png','https://picsum.photos/seed/zuste-general-icon-fitness/256/256','https://picsum.photos/seed/zuste-general-icon-fitness/128/128','image/png','png',18760,43520,1,256,256,NULL,'Category icon for fitness creators','admin',1,MD5('media-1472'),DATE_SUB(NOW(), INTERVAL 409 DAY)),
(1473,'local','general',1,'city-cover-mumbai.jpg','mumbai skyline.jpg','general/cities/city-cover-mumbai.jpg','https://picsum.photos/seed/zuste-general-city-mumbai/1200/800','https://picsum.photos/seed/zuste-general-city-mumbai/320/213','image/jpeg','jpg',348920,872140,1,1200,800,NULL,'Mumbai skyline used on the city selector screen','admin',1,MD5('media-1473'),DATE_SUB(NOW(), INTERVAL 352 DAY)),
(1474,'local','general',1,'city-cover-bengaluru.jpg','bengaluru cover.jpg','general/cities/city-cover-bengaluru.jpg','https://picsum.photos/seed/zuste-general-city-bengaluru/1200/800','https://picsum.photos/seed/zuste-general-city-bengaluru/320/213','image/jpeg','jpg',336480,841760,1,1200,800,NULL,'Bengaluru cover image used on the city selector screen','admin',1,MD5('media-1474'),DATE_SUB(NOW(), INTERVAL 352 DAY)),
(1475,'local','general',1,'city-cover-delhi.jpg','delhi cover.jpg','general/cities/city-cover-delhi.jpg','https://picsum.photos/seed/zuste-general-city-delhi/1200/800','https://picsum.photos/seed/zuste-general-city-delhi/320/213','image/jpeg','jpg',341270,858930,1,1200,800,NULL,'Delhi cover image used on the city selector screen','admin',1,MD5('media-1475'),DATE_SUB(NOW(), INTERVAL 352 DAY)),
(1476,'local','general',1,'empty-state-no-bookings.png','empty bookings.png','general/illustrations/empty-state-no-bookings.png','https://picsum.photos/seed/zuste-general-empty-bookings/800/600','https://picsum.photos/seed/zuste-general-empty-bookings/320/240','image/png','png',86420,198470,1,800,600,NULL,'Illustration shown when a user has no bookings yet','admin',1,MD5('media-1476'),DATE_SUB(NOW(), INTERVAL 287 DAY)),
(1477,'local','general',1,'empty-state-no-messages.png','empty messages.png','general/illustrations/empty-state-no-messages.png','https://picsum.photos/seed/zuste-general-empty-messages/800/600','https://picsum.photos/seed/zuste-general-empty-messages/320/240','image/png','png',82190,189320,1,800,600,NULL,'Illustration shown when an inbox has no conversations','admin',1,MD5('media-1477'),DATE_SUB(NOW(), INTERVAL 287 DAY)),
(1478,'local','general',1,'payout-failed-illustration.png','payout failed.png','general/illustrations/payout-failed-illustration.png','https://picsum.photos/seed/zuste-general-payout-failed/800/600','https://picsum.photos/seed/zuste-general-payout-failed/320/240','image/png','png',79840,182640,1,800,600,NULL,'Illustration shown when a creator payout fails at the bank','admin',1,MD5('media-1478'),DATE_SUB(NOW(), INTERVAL 129 DAY)),
(1479,'local','general',1,'support-hero-help-centre.jpg','help centre hero.jpg','general/support/support-hero-help-centre.jpg','https://picsum.photos/seed/zuste-general-help-centre/1440/600','https://picsum.photos/seed/zuste-general-help-centre/320/133','image/jpeg','jpg',264730,681940,1,1440,600,NULL,'Hero image for the Zusty help centre','admin',1,MD5('media-1479'),DATE_SUB(NOW(), INTERVAL 233 DAY)),
(1480,'local','general',1,'kyc-document-sample.png','kyc sample doc.png','general/support/kyc-document-sample.png','https://picsum.photos/seed/zuste-general-kyc-sample/1000/700','https://picsum.photos/seed/zuste-general-kyc-sample/320/224','image/png','png',147320,342180,1,1000,700,NULL,'Sample showing how to photograph a document for KYC review','admin',1,MD5('media-1480'),DATE_SUB(NOW(), INTERVAL 88 DAY))
ON DUPLICATE KEY UPDATE id = VALUES(id);


/* ==========================================================================
 * 3. COUPONS  (1501 - 1515)
 *    Percentage and fixed codes, a mix of live and expired windows.
 *    category_id points at `categories` 1-16 (3 Travel, 4 Food, 7 Beauty).
 * ========================================================================== */

INSERT INTO coupons
    (id, code, name, description, discount_type, discount_value, max_discount,
     min_booking_amount, applies_to, category_id, usage_limit, usage_limit_per_user,
     used_count, starts_at, expires_at, is_active, is_public, created_by,
     created_at, updated_at)
VALUES
(1501, 'WELCOME200', 'Welcome credit',
 'Rs 200 off your first creator booking on Zusty. Applied automatically at checkout for new brand accounts.',
 'fixed', 200.00, NULL, 2000.00, 'all', NULL, 5000, 1, 0,
 DATE_SUB(NOW(), INTERVAL 420 DAY), DATE_ADD(NOW(), INTERVAL 180 DAY), 1, 1, 1,
 DATE_SUB(NOW(), INTERVAL 421 DAY), DATE_SUB(NOW(), INTERVAL 30 DAY)),

(1502, 'FIRSTBOOK10', 'First booking, 10 percent off',
 '10 percent off the first confirmed booking for a brand, capped at Rs 2,500. Minimum booking value Rs 5,000.',
 'percentage', 10.00, 2500.00, 5000.00, 'first_booking', NULL, 2000, 1, 0,
 DATE_SUB(NOW(), INTERVAL 400 DAY), DATE_ADD(NOW(), INTERVAL 150 DAY), 1, 1, 1,
 DATE_SUB(NOW(), INTERVAL 402 DAY), DATE_SUB(NOW(), INTERVAL 45 DAY)),

(1503, 'FESTIVE25', 'Festive season, 25 percent off',
 'Diwali campaign offer. 25 percent off creator bookings above Rs 20,000, capped at Rs 10,000. Window closed.',
 'percentage', 25.00, 10000.00, 20000.00, 'all', NULL, 800, 2, 0,
 DATE_SUB(NOW(), INTERVAL 290 DAY), DATE_SUB(NOW(), INTERVAL 248 DAY), 0, 1, 1,
 DATE_SUB(NOW(), INTERVAL 296 DAY), DATE_SUB(NOW(), INTERVAL 248 DAY)),

(1504, 'BRANDBOOST15', 'Brand boost, 15 percent off',
 '15 percent off for brand accounts running a campaign above Rs 25,000, capped at Rs 7,500.',
 'percentage', 15.00, 7500.00, 25000.00, 'brands', NULL, 1200, 3, 0,
 DATE_SUB(NOW(), INTERVAL 330 DAY), DATE_ADD(NOW(), INTERVAL 90 DAY), 1, 1, 1,
 DATE_SUB(NOW(), INTERVAL 332 DAY), DATE_SUB(NOW(), INTERVAL 60 DAY)),

(1505, 'CREATORPRO500', 'Creator Pro, Rs 500 off',
 'Rs 500 off the first quarter of a Creator Pro subscription. Creator accounts only, one redemption per creator.',
 'fixed', 500.00, NULL, 0.00, 'creators', NULL, 1500, 1, 0,
 DATE_SUB(NOW(), INTERVAL 240 DAY), DATE_ADD(NOW(), INTERVAL 120 DAY), 1, 1, 1,
 DATE_SUB(NOW(), INTERVAL 242 DAY), DATE_SUB(NOW(), INTERVAL 70 DAY)),

(1506, 'BEAUTY10', 'Beauty category, 10 percent off',
 '10 percent off bookings with beauty creators, capped at Rs 5,000. Minimum booking value Rs 15,000.',
 'percentage', 10.00, 5000.00, 15000.00, 'category', 7, 600, 3, 0,
 DATE_SUB(NOW(), INTERVAL 310 DAY), DATE_ADD(NOW(), INTERVAL 60 DAY), 1, 1, 1,
 DATE_SUB(NOW(), INTERVAL 312 DAY), DATE_SUB(NOW(), INTERVAL 55 DAY)),

(1507, 'FOODIE12', 'Food creators, 12 percent off',
 '12 percent off bookings with food and restaurant creators, capped at Rs 6,000. Minimum booking value Rs 15,000.',
 'percentage', 12.00, 6000.00, 15000.00, 'category', 4, 600, 3, 0,
 DATE_SUB(NOW(), INTERVAL 300 DAY), DATE_ADD(NOW(), INTERVAL 45 DAY), 1, 1, 1,
 DATE_SUB(NOW(), INTERVAL 302 DAY), DATE_SUB(NOW(), INTERVAL 50 DAY)),

(1508, 'REPUBLIC26', 'Republic Day, 26 percent off',
 'Republic Day offer. 26 percent off bookings above Rs 30,000, capped at Rs 12,000. Window closed.',
 'percentage', 26.00, 12000.00, 30000.00, 'all', NULL, 500, 1, 0,
 DATE_SUB(NOW(), INTERVAL 196 DAY), DATE_SUB(NOW(), INTERVAL 186 DAY), 0, 1, 1,
 DATE_SUB(NOW(), INTERVAL 201 DAY), DATE_SUB(NOW(), INTERVAL 186 DAY)),

(1509, 'NEWYEAR2026', 'New year, Rs 2,500 off',
 'Flat Rs 2,500 off any booking above Rs 25,000 through the first week of January. Window closed.',
 'fixed', 2500.00, NULL, 25000.00, 'all', NULL, 750, 1, 0,
 DATE_SUB(NOW(), INTERVAL 220 DAY), DATE_SUB(NOW(), INTERVAL 206 DAY), 0, 1, 1,
 DATE_SUB(NOW(), INTERVAL 226 DAY), DATE_SUB(NOW(), INTERVAL 206 DAY)),

(1510, 'HOLI20', 'Holi campaign, 20 percent off',
 'Holi campaign offer. 20 percent off bookings above Rs 20,000, capped at Rs 8,000. Window closed.',
 'percentage', 20.00, 8000.00, 20000.00, 'all', NULL, 600, 2, 0,
 DATE_SUB(NOW(), INTERVAL 155 DAY), DATE_SUB(NOW(), INTERVAL 141 DAY), 0, 1, 1,
 DATE_SUB(NOW(), INTERVAL 160 DAY), DATE_SUB(NOW(), INTERVAL 141 DAY)),

(1511, 'SUMMER15', 'Summer creator wave, 15 percent off',
 '15 percent off summer campaign bookings above Rs 18,000, capped at Rs 6,000.',
 'percentage', 15.00, 6000.00, 18000.00, 'all', NULL, 900, 2, 0,
 DATE_SUB(NOW(), INTERVAL 70 DAY), DATE_ADD(NOW(), INTERVAL 40 DAY), 1, 1, 1,
 DATE_SUB(NOW(), INTERVAL 74 DAY), DATE_SUB(NOW(), INTERVAL 20 DAY)),

(1512, 'REFER1000', 'Referral reward, Rs 1,000 off',
 'Rs 1,000 off for brands who joined through a referral link. Code only, not listed in the app.',
 'fixed', 1000.00, NULL, 10000.00, 'all', NULL, NULL, 1, 0,
 DATE_SUB(NOW(), INTERVAL 260 DAY), DATE_ADD(NOW(), INTERVAL 200 DAY), 1, 0, 1,
 DATE_SUB(NOW(), INTERVAL 262 DAY), DATE_SUB(NOW(), INTERVAL 90 DAY)),

(1513, 'AGENCY20', 'Agency partner, 20 percent off',
 'Negotiated agency rate. 20 percent off bookings above Rs 1,00,000, capped at Rs 25,000. Shared privately with partner agencies.',
 'percentage', 20.00, 25000.00, 100000.00, 'brands', NULL, 300, 10, 0,
 DATE_SUB(NOW(), INTERVAL 180 DAY), DATE_ADD(NOW(), INTERVAL 185 DAY), 1, 0, 1,
 DATE_SUB(NOW(), INTERVAL 182 DAY), DATE_SUB(NOW(), INTERVAL 35 DAY)),

(1514, 'TRAVELDEAL10', 'Travel creators, 10 percent off',
 '10 percent off bookings with travel creators, capped at Rs 5,000. Paused while the travel category pricing is reviewed.',
 'percentage', 10.00, 5000.00, 20000.00, 'category', 3, 400, 2, 0,
 DATE_SUB(NOW(), INTERVAL 130 DAY), DATE_ADD(NOW(), INTERVAL 30 DAY), 0, 1, 1,
 DATE_SUB(NOW(), INTERVAL 133 DAY), DATE_SUB(NOW(), INTERVAL 25 DAY)),

(1515, 'MONSOON500', 'Monsoon offer, Rs 500 off',
 'Flat Rs 500 off bookings above Rs 5,000 during the monsoon campaign window. Window closed.',
 'fixed', 500.00, NULL, 5000.00, 'all', NULL, 1000, 1, 0,
 DATE_SUB(NOW(), INTERVAL 380 DAY), DATE_SUB(NOW(), INTERVAL 330 DAY), 0, 1, 1,
 DATE_SUB(NOW(), INTERVAL 384 DAY), DATE_SUB(NOW(), INTERVAL 330 DAY))
ON DUPLICATE KEY UPDATE id = VALUES(id);


/* ==========================================================================
 * 4. COUPON REDEMPTIONS  (1701 - 1760, 36 rows produced)
 * --------------------------------------------------------------------------
 * Derived from real bookings 401-480 so the redeeming user, the booking and
 * the discount are always internally consistent. The discount is recomputed
 * from the coupon rule rather than stored by hand:
 *   percentage -> LEAST(amount * value / 100, max_discount)
 *   fixed      -> LEAST(value, amount)
 * Bookings below a coupon min_booking_amount are skipped, and only bookings
 * that actually reached payment (confirmed onwards) can carry a redemption.
 * The coupon pool excludes creator-only and private codes.
 * ========================================================================== */

INSERT INTO coupon_redemptions
    (id, coupon_id, user_id, booking_id, discount_amount, created_at)
SELECT
    1700 + t.rn,
    t.coupon_id,
    t.user_id,
    t.booking_id,
    t.discount_amount,
    t.created_at
FROM (
    SELECT
        ROW_NUMBER() OVER (ORDER BY b.id)                AS rn,
        c.id                                             AS coupon_id,
        b.brand_user_id                                  AS user_id,
        b.id                                             AS booking_id,
        CASE
            WHEN c.discount_type = 'percentage'
                THEN LEAST(ROUND(b.amount * c.discount_value / 100, 2),
                           COALESCE(c.max_discount, b.amount))
            ELSE LEAST(c.discount_value, b.amount)
        END                                              AS discount_amount,
        b.created_at                                     AS created_at
    FROM bookings b
    JOIN (
                    SELECT 0 AS k, 1501 AS cid
        UNION ALL   SELECT 1,      1502
        UNION ALL   SELECT 2,      1503
        UNION ALL   SELECT 3,      1504
        UNION ALL   SELECT 4,      1506
        UNION ALL   SELECT 5,      1507
        UNION ALL   SELECT 6,      1508
        UNION ALL   SELECT 7,      1510
    ) m ON m.k = b.id % 8
    JOIN coupons c ON c.id = m.cid
    WHERE b.id BETWEEN 401 AND 480
      AND b.deleted_at IS NULL
      AND b.status IN ('confirmed', 'in_progress', 'delivered', 'completed', 'disputed')
      AND b.amount >= c.min_booking_amount
) t
-- Booking 452 is the store-screenshot booking and is priced without a
-- discount, so it carries no redemption (filtered after numbering, so the
-- other rows keep their ids).
WHERE t.booking_id <> 452
ON DUPLICATE KEY UPDATE
    coupon_id       = VALUES(coupon_id),
    user_id         = VALUES(user_id),
    booking_id      = VALUES(booking_id),
    discount_amount = VALUES(discount_amount);

-- Keep coupons.used_count in step with the redemptions actually present.
UPDATE coupons c
   SET c.used_count = (
        SELECT COUNT(*) FROM coupon_redemptions r WHERE r.coupon_id = c.id
   )
 WHERE c.id BETWEEN 1501 AND 1515;


/* ==========================================================================
 * 5. POPUPS  (1516 - 1520)
 * --------------------------------------------------------------------------
 * One row per audience: all (1516), creators (1517), brands (1518), guests
 * (1519). 1517, the creator verification nudge, is retired (inactive, its
 * window over): a popup cannot target unverified creators only, so it also
 * reached verified creators beside their delivered bookings. 1516, the
 * report modal for everyone, is retired as well, so the only live popups are
 * the brand Home banner (1518) and the guest bar (1519). The API suite
 * asserts audience targeting with its own fixture popups.
 * ========================================================================== */

INSERT INTO popups
    (id, name, type, title, content, image_url, background_color, text_color,
     cta_label, cta_action, cta_value, placement, audience, trigger_type,
     trigger_value, frequency, is_dismissible, impressions, clicks, dismissals,
     starts_at, ends_at, is_active, priority, created_at, updated_at)
VALUES
(1516, 'Creator economy report announcement', 'modal',
 'The 2026 creator economy report is almost here',
 'A year of Indian creator rates and category trends, written up by the Zusty team. Join the list and we will send it the day it publishes.',
 'https://picsum.photos/seed/zuste-popup-report-2026/900/600',
 '#0F172A', '#FFFFFF',
 'Notify me', 'screen', '/blog/the-2026-indian-creator-economy-report',
 'all', 'all', 'delay', 4, 'once_per_day', 1, 48920, 3714, 12480,
 DATE_SUB(NOW(), INTERVAL 40 DAY), DATE_SUB(NOW(), INTERVAL 10 DAY), 0, 90,
 DATE_SUB(NOW(), INTERVAL 42 DAY), DATE_SUB(NOW(), INTERVAL 10 DAY)),

(1517, 'Creator KYC completion nudge', 'slide_in',
 'Finish verification to unlock payouts',
 'Verified creators can accept bookings and withdraw earnings. A person on our team reviews every submission.',
 'https://picsum.photos/seed/zuste-popup-kyc-nudge/900/600',
 '#065F46', '#FFFFFF',
 'Complete KYC', 'screen', '/kyc',
 'all', 'creators', 'delay', 8, 'once_per_day', 1, 31480, 6120, 7940,
 DATE_SUB(NOW(), INTERVAL 64 DAY), DATE_SUB(NOW(), INTERVAL 12 DAY), 0, 80,
 DATE_SUB(NOW(), INTERVAL 66 DAY), DATE_SUB(NOW(), INTERVAL 12 DAY)),

(1518, 'Brand first campaign prompt', 'banner',
 'Post a brief, let creators come to you',
 'Publish one campaign brief and matching creators apply with their rate for your exact deliverable. Shortlist, compare and send offers without leaving the screen.',
 'https://picsum.photos/seed/zuste-popup-first-campaign/900/600',
 '#1D4ED8', '#FFFFFF',
 'Create a campaign', 'screen', '/campaigns/create',
 'home', 'brands', 'immediate', 0, 'once_per_session', 1, 27310, 4482, 5106,
 DATE_SUB(NOW(), INTERVAL 118 DAY), DATE_ADD(NOW(), INTERVAL 60 DAY), 1, 70,
 DATE_SUB(NOW(), INTERVAL 120 DAY), DATE_SUB(NOW(), INTERVAL 18 DAY)),

(1519, 'Guest sign up prompt', 'announcement_bar',
 'Create a free account to save creators',
 'Shortlists, saved searches and rate history stay with your account. Signing up takes an email address and about thirty seconds.',
 NULL,
 '#111827', '#F9FAFB',
 'Sign up free', 'screen', '/auth/register',
 'all', 'guests', 'scroll', 40, 'once_per_session', 1, 64180, 5290, 21740,
 DATE_SUB(NOW(), INTERVAL 210 DAY), NULL, 1, 40,
 DATE_SUB(NOW(), INTERVAL 212 DAY), DATE_SUB(NOW(), INTERVAL 30 DAY)),

(1520, 'Diwali festive offer', 'full_screen',
 'FESTIVE25 is live until Diwali',
 'Twenty five percent off creator bookings above Rs 20,000, capped at Rs 10,000. Lock your festive wave before rates climb in October.',
 'https://picsum.photos/seed/zuste-popup-festive25/900/600',
 '#B45309', '#FFFFFF',
 'Browse creators', 'screen', '/search?coupon=FESTIVE25',
 'home', 'all', 'immediate', 0, 'once_per_day', 1, 88240, 14930, 29610,
 DATE_SUB(NOW(), INTERVAL 290 DAY), DATE_SUB(NOW(), INTERVAL 248 DAY), 0, 60,
 DATE_SUB(NOW(), INTERVAL 293 DAY), DATE_SUB(NOW(), INTERVAL 248 DAY))
ON DUPLICATE KEY UPDATE id = VALUES(id);


/* ==========================================================================
 * 6. SUPPORT TICKETS  (1601 - 1620)
 * --------------------------------------------------------------------------
 * Twenty tickets raised by real brands and creators against real bookings.
 * Every member of the status ENUM is represented:
 *   open           1601, 1602, 1608, 1614, 1620
 *   pending_agent  1603, 1610, 1616
 *   pending_user   1606, 1612, 1618
 *   resolved       1604, 1607, 1611, 1615, 1619
 *   closed         1605, 1609, 1613, 1617
 * Every member of the category ENUM is used at least once. assigned_to is
 * admin 1, the only agent seeded on this environment.
 * ========================================================================== */

INSERT INTO support_tickets
    (id, code, user_id, booking_id, subject, category, priority, status,
     assigned_to, first_response_at, last_reply_at, last_reply_by,
     resolved_at, closed_at, satisfaction_rating, satisfaction_comment,
     created_at, updated_at)
VALUES
(1601, 'ZT100301', 2011, 429,
 'Payout still held after the brand raised a dispute on ZB2400429',
 'payment', 'high', 'open', 1,
 DATE_SUB(NOW(), INTERVAL 138 HOUR), DATE_SUB(NOW(), INTERVAL 132 HOUR), 'user',
 NULL, NULL, NULL, NULL,
 DATE_SUB(NOW(), INTERVAL 144 HOUR), DATE_SUB(NOW(), INTERVAL 132 HOUR)),

(1602, 'ZT100302', 1017, 429,
 'Deliverable on ZB2400429 does not match the agreed brief',
 'booking', 'urgent', 'open', 1,
 DATE_SUB(NOW(), INTERVAL 116 HOUR), DATE_SUB(NOW(), INTERVAL 116 HOUR), 'agent',
 NULL, NULL, NULL, NULL,
 DATE_SUB(NOW(), INTERVAL 120 HOUR), DATE_SUB(NOW(), INTERVAL 116 HOUR)),

(1603, 'ZT100303', 2015, 434,
 'Dispute on ZB2400434 - brand asking for a fourth revision after delivery',
 'payment', 'high', 'pending_agent', 1,
 DATE_SUB(NOW(), INTERVAL 210 HOUR), DATE_SUB(NOW(), INTERVAL 204 HOUR), 'user',
 NULL, NULL, NULL, NULL,
 DATE_SUB(NOW(), INTERVAL 216 HOUR), DATE_SUB(NOW(), INTERVAL 198 HOUR)),

(1604, 'ZT100304', 2027, 447,
 'KYC rejected twice, the PAN name matches my bank account',
 'kyc', 'normal', 'resolved', 1,
 DATE_SUB(NOW(), INTERVAL 570 HOUR), DATE_SUB(NOW(), INTERVAL 552 HOUR), 'agent',
 DATE_SUB(NOW(), INTERVAL 540 HOUR), NULL, 5,
 'Sorted in a day and the agent explained exactly what was wrong with the scan.',
 DATE_SUB(NOW(), INTERVAL 576 HOUR), DATE_SUB(NOW(), INTERVAL 540 HOUR)),

(1605, 'ZT100305', 1005, 456,
 'GST invoice for ZB2400456 shows the wrong state code',
 'payment', 'high', 'closed', 1,
 DATE_SUB(NOW(), INTERVAL 978 HOUR), DATE_SUB(NOW(), INTERVAL 948 HOUR), 'user',
 DATE_SUB(NOW(), INTERVAL 948 HOUR), DATE_SUB(NOW(), INTERVAL 936 HOUR), 5,
 'Corrected invoice arrived the same day. Finance team was happy.',
 DATE_SUB(NOW(), INTERVAL 984 HOUR), DATE_SUB(NOW(), INTERVAL 936 HOUR)),

(1606, 'ZT100306', 2045, 455,
 'Bank account verification keeps failing on the penny drop',
 'kyc', 'normal', 'pending_user', 1,
 DATE_SUB(NOW(), INTERVAL 282 HOUR), DATE_SUB(NOW(), INTERVAL 270 HOUR), 'agent',
 NULL, NULL, NULL, NULL,
 DATE_SUB(NOW(), INTERVAL 288 HOUR), DATE_SUB(NOW(), INTERVAL 270 HOUR)),

(1607, 'ZT100307', 1011, 442,
 'Creator delivered a Reel instead of the carousel in the brief',
 'booking', 'normal', 'resolved', 1,
 DATE_SUB(NOW(), INTERVAL 786 HOUR), DATE_SUB(NOW(), INTERVAL 768 HOUR), 'agent',
 DATE_SUB(NOW(), INTERVAL 768 HOUR), NULL, 4,
 'Fair outcome. Would have preferred it not to take four days.',
 DATE_SUB(NOW(), INTERVAL 792 HOUR), DATE_SUB(NOW(), INTERVAL 768 HOUR)),

(1608, 'ZT100308', 2031, 453,
 'Wallet balance shows zero after a successful booking payout',
 'wallet', 'normal', 'open', NULL,
 NULL, DATE_SUB(NOW(), INTERVAL 72 HOUR), 'user',
 NULL, NULL, NULL, NULL,
 DATE_SUB(NOW(), INTERVAL 72 HOUR), DATE_SUB(NOW(), INTERVAL 68 HOUR)),

(1609, 'ZT100309', 1006, 421,
 'Refund for cancelled booking ZB2400421 has not reached our account',
 'payment', 'urgent', 'closed', 1,
 DATE_SUB(NOW(), INTERVAL 2298 HOUR), DATE_SUB(NOW(), INTERVAL 2268 HOUR), 'agent',
 DATE_SUB(NOW(), INTERVAL 2268 HOUR), DATE_SUB(NOW(), INTERVAL 2256 HOUR), 4,
 'Took longer than we would like but the agent chased the bank properly.',
 DATE_SUB(NOW(), INTERVAL 2304 HOUR), DATE_SUB(NOW(), INTERVAL 2256 HOUR)),

(1610, 'ZT100310', 2018, 443,
 'Cannot upload the delivered video, the app fails at 80 percent every time',
 'technical', 'normal', 'pending_agent', 1,
 DATE_SUB(NOW(), INTERVAL 162 HOUR), DATE_SUB(NOW(), INTERVAL 156 HOUR), 'user',
 NULL, NULL, NULL, NULL,
 DATE_SUB(NOW(), INTERVAL 168 HOUR), DATE_SUB(NOW(), INTERVAL 156 HOUR)),

(1611, 'ZT100311', 1013, 444,
 'Need paid usage rights extended on ZB2400444 before the ad set expires',
 'booking', 'high', 'resolved', 1,
 DATE_SUB(NOW(), INTERVAL 1122 HOUR), DATE_SUB(NOW(), INTERVAL 1104 HOUR), 'agent',
 DATE_SUB(NOW(), INTERVAL 1104 HOUR), NULL, 5,
 'Extension was agreed and reflected on the booking within hours.',
 DATE_SUB(NOW(), INTERVAL 1128 HOUR), DATE_SUB(NOW(), INTERVAL 1104 HOUR)),

(1612, 'ZT100312', 2006, 441,
 'TDS deducted on my payout for ZB2400441, where do I get Form 16A',
 'payment', 'normal', 'pending_user', 1,
 DATE_SUB(NOW(), INTERVAL 348 HOUR), DATE_SUB(NOW(), INTERVAL 348 HOUR), 'agent',
 NULL, NULL, NULL, NULL,
 DATE_SUB(NOW(), INTERVAL 360 HOUR), DATE_SUB(NOW(), INTERVAL 348 HOUR)),

(1613, 'ZT100313', 1019, 450,
 'Creator took the post down after eleven days on ZB2400450',
 'booking', 'high', 'closed', 1,
 DATE_SUB(NOW(), INTERVAL 1482 HOUR), DATE_SUB(NOW(), INTERVAL 1464 HOUR), 'agent',
 DATE_SUB(NOW(), INTERVAL 1464 HOUR), DATE_SUB(NOW(), INTERVAL 1440 HOUR), 4,
 'Content went back up. The minimum live period is now in our standard brief.',
 DATE_SUB(NOW(), INTERVAL 1488 HOUR), DATE_SUB(NOW(), INTERVAL 1440 HOUR)),

(1614, 'ZT100314', 2022, 451,
 'Cannot change the phone number on my creator account',
 'account', 'low', 'open', 1,
 DATE_SUB(NOW(), INTERVAL 92 HOUR), DATE_SUB(NOW(), INTERVAL 88 HOUR), 'user',
 NULL, NULL, NULL, NULL,
 DATE_SUB(NOW(), INTERVAL 96 HOUR), DATE_SUB(NOW(), INTERVAL 88 HOUR)),

(1615, 'ZT100315', 1003, 454,
 'Can we pay ZB2400454 in two parts before the shoot',
 'booking', 'normal', 'resolved', 1,
 DATE_SUB(NOW(), INTERVAL 666 HOUR), DATE_SUB(NOW(), INTERVAL 648 HOUR), 'agent',
 DATE_SUB(NOW(), INTERVAL 636 HOUR), NULL, 5,
 'Clear answer, and two bookings suited us better anyway.',
 DATE_SUB(NOW(), INTERVAL 672 HOUR), DATE_SUB(NOW(), INTERVAL 636 HOUR)),

(1616, 'ZT100316', 2004, 459,
 'GST number rejected during KYC even though it is active on the portal',
 'kyc', 'high', 'pending_agent', 1,
 DATE_SUB(NOW(), INTERVAL 258 HOUR), DATE_SUB(NOW(), INTERVAL 246 HOUR), 'user',
 NULL, NULL, NULL, NULL,
 DATE_SUB(NOW(), INTERVAL 264 HOUR), DATE_SUB(NOW(), INTERVAL 246 HOUR)),

(1617, 'ZT100317', 1001, 432,
 'GST on ZB2400432 does not match our purchase order',
 'payment', 'normal', 'closed', 1,
 DATE_SUB(NOW(), INTERVAL 1770 HOUR), DATE_SUB(NOW(), INTERVAL 1728 HOUR), 'agent',
 DATE_SUB(NOW(), INTERVAL 1728 HOUR), DATE_SUB(NOW(), INTERVAL 1704 HOUR), 5,
 'Explained the GST on the booking. Query closed.',
 DATE_SUB(NOW(), INTERVAL 1776 HOUR), DATE_SUB(NOW(), INTERVAL 1704 HOUR)),

(1618, 'ZT100318', 2037, 449,
 'Withdrawal to UPI failed and the money is back in my wallet',
 'wallet', 'normal', 'pending_user', 1,
 DATE_SUB(NOW(), INTERVAL 426 HOUR), DATE_SUB(NOW(), INTERVAL 426 HOUR), 'agent',
 NULL, NULL, NULL, NULL,
 DATE_SUB(NOW(), INTERVAL 432 HOUR), DATE_SUB(NOW(), INTERVAL 420 HOUR)),

(1619, 'ZT100319', 1014, NULL,
 'Someone is running our logo on a fake creator profile',
 'abuse', 'high', 'resolved', 1,
 DATE_SUB(NOW(), INTERVAL 1314 HOUR), DATE_SUB(NOW(), INTERVAL 1296 HOUR), 'agent',
 DATE_SUB(NOW(), INTERVAL 1296 HOUR), NULL, 5,
 'Profile was taken down within a day of us reporting it.',
 DATE_SUB(NOW(), INTERVAL 1320 HOUR), DATE_SUB(NOW(), INTERVAL 1296 HOUR)),

(1620, 'ZT100320', 2043, 445,
 'How do I add a Punjabi language tag to my creator profile',
 'other', 'low', 'open', NULL,
 NULL, DATE_SUB(NOW(), INTERVAL 48 HOUR), 'user',
 NULL, NULL, NULL, NULL,
 DATE_SUB(NOW(), INTERVAL 48 HOUR), DATE_SUB(NOW(), INTERVAL 44 HOUR))
ON DUPLICATE KEY UPDATE id = VALUES(id);


/* ==========================================================================
 * 7. SUPPORT TICKET MESSAGES  (1621 - 1696)
 * --------------------------------------------------------------------------
 * A 2 to 6 message thread per ticket. sender_type 'user' carries the raising
 * user id, 'agent' carries admin 1, and 'system' rows are automated notes.
 * Internal agent notes are flagged is_internal = 1 and are never counted as
 * the last reply on the parent ticket.
 * ========================================================================== */

INSERT INTO support_ticket_messages
    (id, ticket_id, sender_type, sender_id, sender_name, message, attachments,
     is_internal, created_at)
VALUES
-- 1601 open, creator chasing a held payout
(1621, 1601, 'user', 2011, 'Sourav Dutta',
 'I delivered the baby skincare Reel on ZB2400429 eleven days ago and the brand accepted it in the chat. The booking now says disputed and my payout of Rs 80,750 after commission is still held. Nobody has told me what the dispute is about.',
 NULL, 0, DATE_SUB(NOW(), INTERVAL 144 HOUR)),
(1622, 1601, 'agent', 1, 'Zusty Support',
 'Thanks for flagging this Sourav. The brand raised a scope dispute on 4 November, which is why the payment is held rather than released. I have pulled both the brief and your delivery thread and I am reviewing them now. The payment stays held by Zusty while our team reviews it, and nothing is released until the dispute is resolved.',
 NULL, 0, DATE_SUB(NOW(), INTERVAL 138 HOUR)),
(1623, 1601, 'user', 2011, 'Sourav Dutta',
 'The brief asked for one Reel plus two story frames and that is exactly what I sent. I have the approval message from their marketing manager on 2 November if that helps.',
 '["https://picsum.photos/seed/zuste-ticket-1601-approval/900/1600"]', 0, DATE_SUB(NOW(), INTERVAL 132 HOUR)),

-- 1602 open, the brand side of the same dispute
(1624, 1602, 'user', 1017, 'Kashayam Kendra Wellness',
 'The delivered Reel on ZB2400429 does not include the ingredient callout we specified as a mandatory in the brief, and the product label is out of focus for most of the video. We have asked for a fix twice in the booking chat.',
 NULL, 0, DATE_SUB(NOW(), INTERVAL 120 HOUR)),
(1625, 1602, 'agent', 1, 'Zusty Support',
 'Understood. I have this linked with the creator side of the same dispute and I am comparing the delivered asset against the mandatories in your brief. The payment stays held until we close this. I will come back to both of you with a single decision rather than two separate threads.',
 NULL, 0, DATE_SUB(NOW(), INTERVAL 116 HOUR)),

-- 1603 pending_agent, revision scope dispute
(1626, 1603, 'user', 2015, 'Harmeet Singh',
 'ZB2400434 was booked with two revision rounds written into the deliverables. I have done three. The brand is now asking for a fourth reshoot with a different location and my payment is still on hold.',
 NULL, 0, DATE_SUB(NOW(), INTERVAL 216 HOUR)),
(1627, 1603, 'agent', 1, 'Zusty Support',
 'The booking record does show two included revisions. A fourth round is out of scope and the brand would need to agree a variation and fee for it. Could you send me the last two change requests so I can date them against your deliveries?',
 NULL, 0, DATE_SUB(NOW(), INTERVAL 210 HOUR)),
(1628, 1603, 'user', 2015, 'Harmeet Singh',
 'Attaching both requests. The second one came in nine days after I delivered the third cut, well past the review window in the booking.',
 '["https://picsum.photos/seed/zuste-ticket-1603-request-a/900/1600","https://picsum.photos/seed/zuste-ticket-1603-request-b/900/1600"]',
 0, DATE_SUB(NOW(), INTERVAL 204 HOUR)),
(1629, 1603, 'agent', 1, 'Zusty Support',
 'Internal note: creator is correct on scope. Review window lapsed on the third delivery, so auto-release should have fired. Escalating to finance to confirm why the release job did not run on this booking before I reply to the creator.',
 NULL, 1, DATE_SUB(NOW(), INTERVAL 198 HOUR)),

-- 1604 resolved, KYC rejection
(1630, 1604, 'user', 2027, 'Tara Fernandes',
 'My KYC has been rejected twice with a name mismatch reason. The name on my PAN and the name on my bank account are identical, I have checked character by character. What am I missing?',
 NULL, 0, DATE_SUB(NOW(), INTERVAL 576 HOUR)),
(1631, 1604, 'agent', 1, 'Zusty Support',
 'The mismatch flag is not on your name, it is on the document itself. Both uploads are photographs of a screen rather than flat scans, and the reviewer could not read the PAN number cleanly on either. Could you upload a flat photo taken in daylight with all four corners visible?',
 NULL, 0, DATE_SUB(NOW(), INTERVAL 570 HOUR)),
(1632, 1604, 'user', 2027, 'Tara Fernandes',
 'That makes sense, both were screenshots from my DigiLocker app. Uploaded a proper scan just now.',
 '["https://picsum.photos/seed/zuste-ticket-1604-pan-scan/1200/800"]', 0, DATE_SUB(NOW(), INTERVAL 564 HOUR)),
(1633, 1604, 'agent', 1, 'Zusty Support',
 'Verified and approved. Your identity badge is live and the penny drop on your bank account cleared as well, so payouts on ZB2400447 will settle normally once the brand accepts delivery.',
 NULL, 0, DATE_SUB(NOW(), INTERVAL 552 HOUR)),
(1634, 1604, 'system', NULL, 'Zusty System',
 'Ticket marked resolved. KYC status for this account moved to verified.',
 NULL, 0, DATE_SUB(NOW(), INTERVAL 540 HOUR)),

-- 1605 closed, wrong GST state code on an invoice
(1635, 1605, 'user', 1005, 'Kiranapay Labs',
 'The invoice generated for ZB2400456 shows IGST but both our GSTIN and the platform GSTIN are registered in Karnataka. Our finance team cannot take input credit against it as issued.',
 NULL, 0, DATE_SUB(NOW(), INTERVAL 984 HOUR)),
(1636, 1605, 'agent', 1, 'Zusty Support',
 'You are right, that should be a CGST and SGST split at 9 percent each on the platform fee, not 18 percent IGST. The state code was picked from your older billing address. I am correcting the billing profile and regenerating the invoice.',
 NULL, 0, DATE_SUB(NOW(), INTERVAL 978 HOUR)),
(1637, 1605, 'user', 1005, 'Kiranapay Labs',
 'Please also confirm the taxable value is only the platform fee and not the full Rs 6,00,000 creator amount. That is what tripped our reviewer.',
 NULL, 0, DATE_SUB(NOW(), INTERVAL 972 HOUR)),
(1638, 1605, 'agent', 1, 'Zusty Support',
 'Confirmed. GST applies only to the Rs 90,000 platform fee at 15 percent commission, giving Rs 16,200 of tax split as Rs 8,100 CGST and Rs 8,100 SGST. The creator fee itself is invoiced separately by the creator. Corrected invoice is on the booking now.',
 '["https://picsum.photos/seed/zuste-ticket-1605-invoice/1240/1754"]', 0, DATE_SUB(NOW(), INTERVAL 960 HOUR)),
(1639, 1605, 'user', 1005, 'Kiranapay Labs',
 'Received and passed. Thank you for turning that around quickly.',
 NULL, 0, DATE_SUB(NOW(), INTERVAL 948 HOUR)),
(1640, 1605, 'system', NULL, 'Zusty System',
 'Ticket closed after resolution with no further reply for 12 hours.',
 NULL, 0, DATE_SUB(NOW(), INTERVAL 936 HOUR)),

-- 1606 pending_user, penny drop failing
(1641, 1606, 'user', 2045, 'Sameer Qadri',
 'Bank verification has failed three times. I am entering the account number from my passbook and the IFSC from the bank website. Is there a limit on how many times I can retry?',
 NULL, 0, DATE_SUB(NOW(), INTERVAL 288 HOUR)),
(1642, 1606, 'agent', 1, 'Zusty Support',
 'No retry limit, but three failures usually means the account holder name at the bank differs from the name on your Zusty profile. Banks return the registered name, not the one you type. Could you confirm the exact name printed on your cheque leaf?',
 NULL, 0, DATE_SUB(NOW(), INTERVAL 282 HOUR)),
(1643, 1606, 'user', 2045, 'Sameer Qadri',
 'The cheque says SAMEER AHMAD QADRI. My profile says Sameer Qadri.',
 NULL, 0, DATE_SUB(NOW(), INTERVAL 276 HOUR)),
(1644, 1606, 'agent', 1, 'Zusty Support',
 'That is the cause. Please update your legal name on the KYC screen to Sameer Ahmad Qadri so it matches the bank record exactly. Your display name on your public creator profile can stay as Sameer Qadri. Once you save that, retry the verification and it should pass on the first attempt.',
 NULL, 0, DATE_SUB(NOW(), INTERVAL 270 HOUR)),

-- 1607 resolved, wrong deliverable format
(1645, 1607, 'user', 1011, 'Homestay Hopper Trips',
 'ZB2400442 was booked for an itinerary carousel. The creator has delivered a Reel. The Reel is good but a carousel is what our audience screenshots, which is the whole reason we specified it.',
 NULL, 0, DATE_SUB(NOW(), INTERVAL 792 HOUR)),
(1646, 1607, 'agent', 1, 'Zusty Support',
 'The booking deliverable type does read carousel, so this is a genuine mismatch and not a preference. I have opened a change request with the creator asking for the carousel as originally scoped, with the Reel kept as a bonus asset at no extra cost. The payment stays held meanwhile.',
 NULL, 0, DATE_SUB(NOW(), INTERVAL 786 HOUR)),
(1647, 1607, 'user', 1011, 'Homestay Hopper Trips',
 'That works for us. We are happy to keep the Reel.',
 NULL, 0, DATE_SUB(NOW(), INTERVAL 780 HOUR)),
(1648, 1607, 'agent', 1, 'Zusty Support',
 'The creator has delivered the five-slide itinerary carousel and it is on the booking for your review. Both assets are now covered by the same usage rights window. Marking this resolved, reopen it if the carousel needs changes.',
 NULL, 0, DATE_SUB(NOW(), INTERVAL 768 HOUR)),

-- 1608 open, untriaged wallet query
(1649, 1608, 'user', 2031, 'Nandini Rao',
 'My wallet shows a zero balance but the booking screen for ZB2400453 says the payment was released two days ago. The transaction list is empty as well. Has something failed on your side?',
 NULL, 0, DATE_SUB(NOW(), INTERVAL 72 HOUR)),
(1650, 1608, 'system', NULL, 'Zusty System',
 'Ticket received and queued for a support agent. Typical first response time on wallet queries is under 6 hours.',
 NULL, 0, DATE_SUB(NOW(), INTERVAL 68 HOUR)),

-- 1609 closed, delayed refund
(1651, 1609, 'user', 1006, 'Paisaprism Finserv',
 'ZB2400421 was cancelled and marked refunded on your side eight days ago, for Rs 2,40,000 plus the platform fee. Nothing has hit our current account and our bank says they have no incoming reference.',
 NULL, 0, DATE_SUB(NOW(), INTERVAL 2304 HOUR)),
(1652, 1609, 'agent', 1, 'Zusty Support',
 'I can see the refund was initiated to the original payment method with reference RFND24211884. Bank-side reversals on a netbanking payment can take five to seven working days, and this one has crossed that. I am raising it with the gateway and will chase daily until it lands.',
 NULL, 0, DATE_SUB(NOW(), INTERVAL 2298 HOUR)),
(1653, 1609, 'agent', 1, 'Zusty Support',
 'Internal note: gateway confirms the reversal was stuck in the acquirer queue after a weekend outage. Manual push requested, ETA 48 hours. Customer is a repeat brand with four completed bookings, keep the priority urgent.',
 NULL, 1, DATE_SUB(NOW(), INTERVAL 2292 HOUR)),
(1654, 1609, 'user', 1006, 'Paisaprism Finserv',
 'Credit has arrived this morning with the reference you shared. Thank you for staying on it.',
 NULL, 0, DATE_SUB(NOW(), INTERVAL 2280 HOUR)),
(1655, 1609, 'agent', 1, 'Zusty Support',
 'Glad it landed. The full Rs 2,83,200 including the platform fee and GST has been reversed, and the booking now shows fully refunded. Closing this out.',
 NULL, 0, DATE_SUB(NOW(), INTERVAL 2268 HOUR)),

-- 1610 pending_agent, upload failure
(1656, 1610, 'user', 2018, 'Siddharth Nair',
 'The delivery upload on ZB2400443 fails at around 80 percent every time and then says network error. The file is a 740 MB ProRes export. I am on fibre broadband and other uploads work fine.',
 NULL, 0, DATE_SUB(NOW(), INTERVAL 168 HOUR)),
(1657, 1610, 'agent', 1, 'Zusty Support',
 'The delivery uploader caps individual files at 512 MB, which is why it dies partway. Could you export an H.264 version at 1080 by 1920, which will land well under the cap, and keep the ProRes master for the brand to request separately if they need it?',
 NULL, 0, DATE_SUB(NOW(), INTERVAL 162 HOUR)),
(1658, 1610, 'user', 2018, 'Siddharth Nair',
 'Tried that. The H.264 file is 190 MB and it still fails at the same point. Screen recording of the failure attached.',
 '["https://picsum.photos/seed/zuste-ticket-1610-upload-error/1080/1920"]', 0, DATE_SUB(NOW(), INTERVAL 156 HOUR)),

-- 1611 resolved, usage rights extension
(1659, 1611, 'user', 1013, 'Tiffinbox Games',
 'The 30 day paid usage window on ZB2400444 expires on Friday and the asset is our best performing ad. We want to extend by another 60 days. What is the process and what does it cost?',
 NULL, 0, DATE_SUB(NOW(), INTERVAL 1128 HOUR)),
(1660, 1611, 'agent', 1, 'Zusty Support',
 'Extensions are agreed directly with the creator and recorded as a variation on the booking so the permission does not lapse. The named renewal rate on this booking is 40 percent of the base fee per 30 days, so 60 days would be Rs 4,16,000 on a Rs 5,20,000 base. I can raise the variation for you to approve.',
 NULL, 0, DATE_SUB(NOW(), INTERVAL 1122 HOUR)),
(1661, 1611, 'user', 1013, 'Tiffinbox Games',
 'Please raise it. Also make sure the partnership ad permission on the creator handle is re-granted, last time it expired silently and our ad set switched off.',
 NULL, 0, DATE_SUB(NOW(), INTERVAL 1116 HOUR)),
(1662, 1611, 'agent', 1, 'Zusty Support',
 'Variation approved by the creator and the booking now shows usage rights to 60 days beyond the original window. I have also asked her to re-grant the partnership permission from her professional dashboard, and set a reminder on our side seven days before the new expiry so it does not lapse quietly again.',
 NULL, 0, DATE_SUB(NOW(), INTERVAL 1104 HOUR)),

-- 1612 pending_user, TDS certificate
(1663, 1612, 'user', 2006, 'Arjun Malhotra',
 'My payout statement for ZB2400441 shows a TDS deduction. Where do I download Form 16A so I can claim credit when I file?',
 NULL, 0, DATE_SUB(NOW(), INTERVAL 360 HOUR)),
(1664, 1612, 'system', NULL, 'Zusty System',
 'Ticket routed to the payments queue based on category.',
 NULL, 0, DATE_SUB(NOW(), INTERVAL 354 HOUR)),
(1665, 1612, 'agent', 1, 'Zusty Support',
 'Form 16A is issued quarterly after the TDS return is filed, so the certificate covering this booking will be available about 15 days after the quarter closes. It appears under Wallet and then Tax documents. In the meantime the deduction is already visible against your PAN in Form 26AS. Could you confirm the PAN on your KYC is the one you file under, so the credit lands in the right place?',
 NULL, 0, DATE_SUB(NOW(), INTERVAL 348 HOUR)),

-- 1613 closed, content taken down early
(1666, 1613, 'user', 1019, 'Dhokra Den Home',
 'The creator on ZB2400450 removed the post after eleven days. Our brief and the booking both specify a 30 day minimum live period. We were still running paid behind it.',
 NULL, 0, DATE_SUB(NOW(), INTERVAL 1488 HOUR)),
(1667, 1613, 'agent', 1, 'Zusty Support',
 'The 30 day minimum is on the booking record, so this is a scope breach rather than a disagreement. I have contacted the creator to understand what happened before we decide on any adjustment.',
 NULL, 0, DATE_SUB(NOW(), INTERVAL 1482 HOUR)),
(1668, 1613, 'user', 1019, 'Dhokra Den Home',
 'We would rather have the post back up than a refund. The ad account is already tied to that permalink.',
 NULL, 0, DATE_SUB(NOW(), INTERVAL 1476 HOUR)),
(1669, 1613, 'agent', 1, 'Zusty Support',
 'The creator had archived it during a profile cleanup and has restored the original post, so the permalink and your ad set are intact. The live period has been reset to run 30 days from today. She has acknowledged the term in writing on the booking.',
 NULL, 0, DATE_SUB(NOW(), INTERVAL 1464 HOUR)),

-- 1614 open, account change
(1670, 1614, 'user', 2022, 'Rohan Verma',
 'I have changed my number and the old one still shows on my profile. How do I update it?',
 NULL, 0, DATE_SUB(NOW(), INTERVAL 96 HOUR)),
(1671, 1614, 'agent', 1, 'Zusty Support',
 'You can change it under Profile > Email & mobile. We never send codes to a phone number. If the screen does not let you save it, reply here and I will update it for you once I have checked it is you.',
 NULL, 0, DATE_SUB(NOW(), INTERVAL 92 HOUR)),
(1672, 1614, 'user', 2022, 'Rohan Verma',
 'Thanks. It still will not save the new number, so please update it for me.',
 NULL, 0, DATE_SUB(NOW(), INTERVAL 88 HOUR)),

-- 1615 resolved, request to pay in two parts
(1673, 1615, 'user', 1003, 'Chutneyverse Foods',
 'ZB2400454 is Rs 82,500 and involves a two day shoot. We would like to release part of it on concept approval and the rest on final delivery. Is that possible after the booking is already confirmed?',
 NULL, 0, DATE_SUB(NOW(), INTERVAL 672 HOUR)),
(1674, 1615, 'agent', 1, 'Zusty Support',
 'A booking is paid once and the whole payment is held by Zusty until you approve the delivered work, so it cannot be released in parts. If you want to sign off the concept separately, the usual route is two bookings with the same creator: one for the concept and script, one for the final shoot.',
 NULL, 0, DATE_SUB(NOW(), INTERVAL 666 HOUR)),
(1675, 1615, 'user', 1003, 'Chutneyverse Foods',
 'Understood. We will keep this booking for the shoot and send a separate one for the script.',
 NULL, 0, DATE_SUB(NOW(), INTERVAL 660 HOUR)),
(1676, 1615, 'agent', 1, 'Zusty Support',
 'That works. Nothing changes on ZB2400454: the payment stays held until you approve the final delivery. The creator has confirmed the shoot dates are unaffected.',
 NULL, 0, DATE_SUB(NOW(), INTERVAL 648 HOUR)),
(1677, 1615, 'system', NULL, 'Zusty System',
 'Ticket marked resolved. No change to booking ZB2400454.',
 NULL, 0, DATE_SUB(NOW(), INTERVAL 636 HOUR)),

-- 1616 pending_agent, GSTIN validation
(1678, 1616, 'user', 2004, 'Meher Nagpal',
 'My GSTIN is rejected during KYC with an invalid or inactive message. I have just checked on the GST portal and the registration is active with a filing history through last quarter.',
 NULL, 0, DATE_SUB(NOW(), INTERVAL 264 HOUR)),
(1679, 1616, 'agent', 1, 'Zusty Support',
 'Thanks Meher. Our validation calls the GSTN service directly, so an active registration should pass. Could you send a screenshot of the search result on the portal showing the GSTIN, the legal name and the status, so I can compare it against what our call is returning?',
 NULL, 0, DATE_SUB(NOW(), INTERVAL 258 HOUR)),
(1680, 1616, 'user', 2004, 'Meher Nagpal',
 'Attached. The legal name on the portal is my proprietorship name, which is different from my display name here. Could that be the problem?',
 '["https://picsum.photos/seed/zuste-ticket-1616-gst-portal/1200/900"]', 0, DATE_SUB(NOW(), INTERVAL 252 HOUR)),
(1681, 1616, 'user', 2004, 'Meher Nagpal',
 'Following up on this. I have a booking worth Rs 4,50,000 confirmed and I would rather not hold up the invoice at the end of it.',
 NULL, 0, DATE_SUB(NOW(), INTERVAL 246 HOUR)),

-- 1617 closed, GST breakdown query
(1682, 1617, 'user', 1001, 'Velvetfern Skincare',
 'Our PO for ZB2400432 covered the Rs 1,25,000 creator fee only, so the Rs 1,47,500 charged was higher than finance expected. Can you break down the difference?',
 NULL, 0, DATE_SUB(NOW(), INTERVAL 1776 HOUR)),
(1683, 1617, 'agent', 1, 'Zusty Support',
 'Your PO covered the creator fee. The difference is GST at 18 percent on that fee, Rs 22,500, split on the invoice as Rs 11,250 CGST and Rs 11,250 SGST, and shown on the booking before you pay. I will attach the itemised invoice so finance can match it line by line.',
 NULL, 0, DATE_SUB(NOW(), INTERVAL 1770 HOUR)),
(1684, 1617, 'user', 1001, 'Velvetfern Skincare',
 'Understood. Could the summary screen show those lines before checkout as well?',
 NULL, 0, DATE_SUB(NOW(), INTERVAL 1764 HOUR)),
(1685, 1617, 'agent', 1, 'Zusty Support',
 'It does, under the price breakdown on the booking, but it is easy to miss on a small screen, which is a fair complaint.',
 NULL, 0, DATE_SUB(NOW(), INTERVAL 1752 HOUR)),
(1686, 1617, 'user', 1001, 'Velvetfern Skincare',
 'That reconciles. Please make the breakdown easier to spot, it would have saved this thread.',
 NULL, 0, DATE_SUB(NOW(), INTERVAL 1740 HOUR)),
(1687, 1617, 'agent', 1, 'Zusty Support',
 'Agreed, and I have logged it as a product request. The itemised invoice is attached to the booking. Closing this, thanks for the clear write up.',
 '["https://picsum.photos/seed/zuste-ticket-1617-invoice/1240/1754"]', 0, DATE_SUB(NOW(), INTERVAL 1728 HOUR)),

-- 1618 pending_user, failed UPI withdrawal
(1688, 1618, 'user', 2037, 'Ria Bhattacharya',
 'I tried to withdraw Rs 6,500 to my UPI handle and it failed. The money is back in my wallet but I do not know what went wrong.',
 NULL, 0, DATE_SUB(NOW(), INTERVAL 432 HOUR)),
(1689, 1618, 'agent', 1, 'Zusty Support',
 'The gateway returned an invalid VPA response, which usually means the handle has been deactivated by the bank or the app it was created in. Nothing is lost, the amount returned to your wallet as designed. Could you add the UPI handle again from your wallet screen, or switch to a bank account payout, and tell me which one you would prefer?',
 NULL, 0, DATE_SUB(NOW(), INTERVAL 426 HOUR)),
(1690, 1618, 'system', NULL, 'Zusty System',
 'Reminder sent to the user. Ticket will auto close if there is no reply within 14 days.',
 NULL, 0, DATE_SUB(NOW(), INTERVAL 420 HOUR)),

-- 1619 resolved, impersonation report
(1691, 1619, 'user', 1014, 'Clutchkart Esports',
 'There is a profile on your platform using our logo, our team name and our brand colours, offering paid shoutouts. It is not us and it is not anyone we work with.',
 '["https://picsum.photos/seed/zuste-ticket-1619-fake-profile/1080/1920"]', 0, DATE_SUB(NOW(), INTERVAL 1320 HOUR)),
(1692, 1619, 'agent', 1, 'Zusty Support',
 'Thank you for reporting this with a screenshot. I have suspended the profile pending review so it cannot receive bookings while we investigate, and I have flagged it to our trust and safety queue as impersonation.',
 NULL, 0, DATE_SUB(NOW(), INTERVAL 1314 HOUR)),
(1693, 1619, 'agent', 1, 'Zusty Support',
 'Internal note: profile had no completed bookings and two pending offers, both cancelled with the brands notified. Account KYC was never submitted. Recommending permanent removal rather than a warning.',
 NULL, 1, DATE_SUB(NOW(), INTERVAL 1308 HOUR)),
(1694, 1619, 'agent', 1, 'Zusty Support',
 'The profile has been permanently removed and the two brands who had sent offers have been notified. If you see anything similar, reporting it from the profile menu routes it to the same queue and is faster than opening a ticket.',
 NULL, 0, DATE_SUB(NOW(), INTERVAL 1296 HOUR)),

-- 1620 open, simple profile question
(1695, 1620, 'user', 2043, 'Gurmeet Brar',
 'Most of my content is in Punjabi but I can only pick Hindi and English as content languages on my profile. Brands searching for Punjabi creators are not finding me. Can this be added?',
 NULL, 0, DATE_SUB(NOW(), INTERVAL 48 HOUR)),
(1696, 1620, 'system', NULL, 'Zusty System',
 'Ticket received and queued for a support agent. Typical first response time on profile queries is under 12 hours.',
 NULL, 0, DATE_SUB(NOW(), INTERVAL 44 HOUR))
ON DUPLICATE KEY UPDATE id = VALUES(id);


/* ==========================================================================
 * 8. CONTACT MESSAGES  (1801 - 1818)
 * ========================================================================== */

INSERT INTO contact_messages
    (id, name, email, phone, subject, message, source, status,
     replied_by, replied_at, reply_body, ip_address, created_at)
VALUES
(1801, 'Ritika Sharma', 'ritika.sharma@example.com', '+919000001801',
 'Do you have creators in Nagpur',
 'We run a small cafe chain in Nagpur and want to work with local food creators for our new outlet. Most platforms only show Mumbai and Pune. Do you have anyone in tier two cities in Maharashtra?',
 'web', 'replied', 1, DATE_SUB(NOW(), INTERVAL 386 DAY),
 'We do. There are food and lifestyle creators listed in Nagpur, Nashik and Aurangabad. Filter by city on the search screen and set the category to Food. Happy to shortlist a few for you if you share your budget band.',
 '198.51.100.11', DATE_SUB(NOW(), INTERVAL 388 DAY)),

(1802, 'Aniruddh Menon', 'aniruddh.menon@example.com', '+919000001802',
 'Agency partnership enquiry',
 'We are a 14 person influencer agency in Kochi managing about 60 creators. Is there an agency account type where we can manage multiple creator profiles and consolidated invoicing from one login?',
 'web', 'replied', 1, DATE_SUB(NOW(), INTERVAL 352 DAY),
 'Agency accounts are on the roadmap. Today the cleanest route is a brand account for booking plus individual creator accounts for your roster. I have added you to the early access list for agency billing.',
 '203.0.113.12', DATE_SUB(NOW(), INTERVAL 354 DAY)),

(1803, 'Farhan Sheikh', 'farhan.sheikh@example.com', '+919000001803',
 'Payment failed but money debited',
 'I tried to fund a booking of Rs 34,000 twice and both times the payment page said failed, but my bank shows two debits. Please help, this is urgent.',
 'app', 'replied', 1, DATE_SUB(NOW(), INTERVAL 296 DAY),
 'Both debits were authorisation holds that the gateway did not capture, and they auto-reverse within five working days. I have raised a manual reversal so it should be quicker. Your booking was funded on the third attempt only, so you have been charged once.',
 '192.0.2.13', DATE_SUB(NOW(), INTERVAL 297 DAY)),

(1804, 'Devika Nair', 'devika.nair@example.com', NULL,
 'How do I become a verified creator',
 'I have 31,000 followers on Instagram and post about Kerala home cooking. What do I need to do to get the verified badge on my profile so brands take me seriously?',
 'app', 'replied', 1, DATE_SUB(NOW(), INTERVAL 268 DAY),
 'Complete verification in the app with your PAN and a masked copy of your Aadhaar. A person on our team reviews every submission, and the blue tick appears only after that approval.',
 '198.51.100.14', DATE_SUB(NOW(), INTERVAL 270 DAY)),

(1805, 'Karan Bhatia', 'karan.bhatia@example.com', '+919000001805',
 'Bulk booking for a 20 creator campaign',
 'Planning a Diwali wave with roughly 20 nano creators across Delhi NCR. Is there a way to send the same brief to all of them at once instead of messaging each profile?',
 'web', 'replied', 1, DATE_SUB(NOW(), INTERVAL 244 DAY),
 'Yes. Post a campaign brief with your deliverables, budget band and city preference and matching creators apply to it. You can then shortlist, compare side by side and send offers to the whole shortlist in one action.',
 '203.0.113.15', DATE_SUB(NOW(), INTERVAL 246 DAY)),

(1806, 'Sneha Iyengar', 'sneha.iyengar@example.com', '+919000001806',
 'Creator rates seem inconsistent',
 'I looked at four creators with similar follower counts in Bengaluru and their Reel rates ranged from Rs 8,000 to Rs 41,000. How am I supposed to judge what is fair?',
 'app', 'replied', 1, DATE_SUB(NOW(), INTERVAL 208 DAY),
 'Follower count is a weak predictor of rate. Compare on average delivered views instead, which is on each profile, and check what usage rights are included. Our rate benchmark article on the blog has the tier ranges we see across the platform.',
 '192.0.2.16', DATE_SUB(NOW(), INTERVAL 210 DAY)),

(1807, 'Rahul Deshmukh', 'rahul.deshmukh@example.com', '+919000001807',
 'Invoice needed for a completed booking',
 'We completed a booking last month and our accounts team needs a tax invoice with our GSTIN on it. I cannot find where to download it.',
 'app', 'replied', 1, DATE_SUB(NOW(), INTERVAL 176 DAY),
 'The platform fee invoice sits on the booking detail screen under Billing. If your GSTIN was added after the booking was created you will need to add it in Business profile and then regenerate. I have regenerated this one for you.',
 '198.51.100.17', DATE_SUB(NOW(), INTERVAL 177 DAY)),

(1808, 'Meenakshi Rao', 'meenakshi.rao@example.com', '+919000001808',
 'Partnership for a creator workshop in Hyderabad',
 'We run creator workshops and would like to co-host one on pricing and contracts in Hyderabad. Is there someone on your team who handles community partnerships?',
 'web', 'read', NULL, NULL, NULL,
 '203.0.113.18', DATE_SUB(NOW(), INTERVAL 132 DAY)),

(1809, 'Vikas Chandra', 'vikas.chandra@example.com', '+919000001809',
 'Press enquiry about your creator economy report',
 'I write for a business daily and would like to cover the rate data in your annual report. Can someone share the methodology and an embargoed copy?',
 'web', 'read', NULL, NULL, NULL,
 '192.0.2.19', DATE_SUB(NOW(), INTERVAL 96 DAY)),

(1810, 'Pooja Kulkarni', 'pooja.kulkarni@example.com', NULL,
 'Cannot log in after changing my password',
 'I changed my password last week and now the app says it is wrong. My email is on the account. Please help me get back in.',
 'app', 'replied', 1, DATE_SUB(NOW(), INTERVAL 88 DAY),
 'Choose Forgot password on the login screen, or sign in with a code sent to the email on your account, and then set a new password in Settings.',
 '198.51.100.20', DATE_SUB(NOW(), INTERVAL 89 DAY)),

(1811, 'Imran Qureshi', 'imran.qureshi@example.com', '+919000001811',
 'Do you support YouTube long form bookings',
 'Most of our spend is on 8 to 12 minute YouTube integrations rather than Reels. Can creators list a long form integration package on your platform?',
 'web', 'replied', 1, DATE_SUB(NOW(), INTERVAL 64 DAY),
 'Yes. Creators can list a YouTube integration package with their own duration and pricing, and the deliverable type on a booking supports video and bundle. Filter by platform YouTube on search to see who offers it.',
 '203.0.113.21', DATE_SUB(NOW(), INTERVAL 65 DAY)),

(1812, 'Anjali Verma', 'anjali.verma@example.com', '+919000001812',
 'Creator stopped responding after we paid',
 'A creator accepted our paid booking and has not responded in nine days. What is the process here?',
 'app', 'replied', 1, DATE_SUB(NOW(), INTERVAL 51 DAY),
 'Please raise this from the booking itself so it opens a dispute. The payment stays held by Zusty while our team reviews it, and nothing is released to the creator until the review is done. I have opened one on your behalf.',
 '192.0.2.22', DATE_SUB(NOW(), INTERVAL 52 DAY)),

(1813, 'Tanmay Ghosh', 'tanmay.ghosh@example.com', '+919000001813',
 'Bengali creators for a sweets brand',
 'We are launching a packaged mishti brand in Kolkata and want Bengali language food creators. How many are listed and can we filter by language?',
 'web', 'replied', 1, DATE_SUB(NOW(), INTERVAL 38 DAY),
 'Language is a filter on the search screen alongside city and category. There are Bengali food and lifestyle creators listed across Kolkata, Howrah and Siliguri. Regional language content has been our fastest growing segment this year.',
 '198.51.100.23', DATE_SUB(NOW(), INTERVAL 39 DAY)),

(1814, 'Nishant Kalra', 'nishant.kalra@example.com', NULL,
 'Suggestion for the app',
 'It would be genuinely useful if the search results could be sorted by average delivery time. Right now I have to open each profile to see it.',
 'app', 'read', NULL, NULL, NULL,
 '203.0.113.24', DATE_SUB(NOW(), INTERVAL 27 DAY)),

(1815, 'Growth Partners Team', 'spam.followers@example.com', NULL,
 'Get 100k followers in 30 days',
 'Hello sir we provide fast follower growth and engagement packages at lowest price in market. Reply for rate list. We also do YouTube subscribers and Instagram verification.',
 'web', 'spam', NULL, NULL, NULL,
 '192.0.2.25', DATE_SUB(NOW(), INTERVAL 21 DAY)),

(1816, 'SEO Outreach', 'spam.backlinks@example.com', NULL,
 'Guest post opportunity on your blog',
 'We noticed your blog has good domain authority. We would like to publish a sponsored guest post with two dofollow links. Please share your pricing.',
 'web', 'spam', NULL, NULL, NULL,
 '198.51.100.26', DATE_SUB(NOW(), INTERVAL 16 DAY)),

(1817, 'Shruti Balan', 'shruti.balan@example.com', '+919000001817',
 'Is there a minimum spend to start',
 'We are a very small brand with a monthly influencer budget of about Rs 25,000. Is that too small to be useful on Zusty?',
 'app', 'new', NULL, NULL, NULL,
 '203.0.113.27', DATE_SUB(NOW(), INTERVAL 9 DAY)),

(1818, 'Abhinav Rastogi', 'abhinav.rastogi@example.com', '+919000001818',
 'Whitelisting and dark posts',
 'Can we run dark posts from a creator handle through your platform, or does that have to be arranged privately with each creator?',
 'web', 'new', NULL, NULL, NULL,
 '192.0.2.28', DATE_SUB(NOW(), INTERVAL 4 DAY))
ON DUPLICATE KEY UPDATE id = VALUES(id);


/* ==========================================================================
 * 9. NEWSLETTER SUBSCRIBERS  (1851 - 1890)
 * --------------------------------------------------------------------------
 * A mix of platform users (linked with user_id) and standalone web signups.
 * unsubscribe_token is derived with MD5 so it stays stable across re-runs
 * and satisfies the UNIQUE constraint on the column.
 * ========================================================================== */

INSERT INTO newsletter_subscribers
    (id, email, name, user_id, source, status, unsubscribe_token,
     subscribed_at, unsubscribed_at)
VALUES
(1851, 'velvetfern@example.com',            'Velvetfern Skincare',  1001, 'app',    'subscribed',   MD5('ns-1851'), DATE_SUB(NOW(), INTERVAL 418 DAY), NULL),
(1852, 'chutneyverse@example.com',        'Chutneyverse Foods',     1003, 'app',    'subscribed',   MD5('ns-1852'), DATE_SUB(NOW(), INTERVAL 410 DAY), NULL),
(1853, 'kiranapay@example.com',              'Kiranapay Labs',  1005, 'app',    'subscribed',   MD5('ns-1853'), DATE_SUB(NOW(), INTERVAL 402 DAY), NULL),
(1854, 'sanganera@example.com',          'Sanganera Looms',          1007, 'app',    'subscribed',   MD5('ns-1854'), DATE_SUB(NOW(), INTERVAL 396 DAY), NULL),
(1855, 'doubtbuster@example.com',          'Doubtbuster Academy',       1009, 'app',    'unsubscribed', MD5('ns-1855'), DATE_SUB(NOW(), INTERVAL 388 DAY), DATE_SUB(NOW(), INTERVAL 122 DAY)),
(1856, 'homestayhopper@example.com',            'Homestay Hopper Trips',     1011, 'app',    'subscribed',   MD5('ns-1856'), DATE_SUB(NOW(), INTERVAL 379 DAY), NULL),
(1857, 'tiffinboxgames@example.com',       'Tiffinbox Games',      1013, 'app',    'subscribed',   MD5('ns-1857'), DATE_SUB(NOW(), INTERVAL 371 DAY), NULL),
(1858, 'detailbay@example.com',            'Detailbay Motors',       1015, 'app',    'subscribed',   MD5('ns-1858'), DATE_SUB(NOW(), INTERVAL 362 DAY), NULL),
(1859, 'kashayamkendra@example.com',              'Kashayam Kendra Wellness',   1017, 'app',    'subscribed',   MD5('ns-1859'), DATE_SUB(NOW(), INTERVAL 354 DAY), NULL),
(1860, 'dhokraden@example.com',       'Dhokra Den Home',       1019, 'app',    'subscribed',   MD5('ns-1860'), DATE_SUB(NOW(), INTERVAL 347 DAY), NULL),
(1861, 'aanya.sample.life@example.com',         'Aanya Kapoor',          2001, 'app',    'subscribed',   MD5('ns-1861'), DATE_SUB(NOW(), INTERVAL 340 DAY), NULL),
(1862, 'kabir.sample.city@example.com',         'Kabir Sethi',           2003, 'app',    'subscribed',   MD5('ns-1862'), DATE_SUB(NOW(), INTERVAL 332 DAY), NULL),
(1863, 'ishita.sample.drapes@example.com',     'Ishita Rane',           2005, 'app',    'subscribed',   MD5('ns-1863'), DATE_SUB(NOW(), INTERVAL 324 DAY), NULL),
(1864, 'tanvi.sample.trails@example.com',          'Tanvi Deshpande',       2007, 'app',    'subscribed',   MD5('ns-1864'), DATE_SUB(NOW(), INTERVAL 316 DAY), NULL),
(1865, 'aditi.sample.eats@example.com',       'Aditi Ranganathan',     2010, 'app',    'subscribed',   MD5('ns-1865'), DATE_SUB(NOW(), INTERVAL 308 DAY), NULL),
(1866, 'preeti.sample.masala@example.com',        'Preeti Ahuja',          2012, 'app',    'unsubscribed', MD5('ns-1866'), DATE_SUB(NOW(), INTERVAL 300 DAY), DATE_SUB(NOW(), INTERVAL 74 DAY)),
(1867, 'sanaya.sample.fit@example.com',        'Sanaya Kotak',          2014, 'app',    'subscribed',   MD5('ns-1867'), DATE_SUB(NOW(), INTERVAL 292 DAY), NULL),
(1868, 'neha.sample.gadgets@example.com',       'Neha Kulkarni',         2017, 'app',    'subscribed',   MD5('ns-1868'), DATE_SUB(NOW(), INTERVAL 284 DAY), NULL),
(1869, 'kavya.sample.skin@example.com',        'Kavya Reddy',           2020, 'app',    'subscribed',   MD5('ns-1869'), DATE_SUB(NOW(), INTERVAL 276 DAY), NULL),
(1870, 'meera.sample.yoga@example.com',        'Meera Subramanian',     2025, 'app',    'subscribed',   MD5('ns-1870'), DATE_SUB(NOW(), INTERVAL 268 DAY), NULL),
(1871, 'priya.sample.paisa@example.com',       'Priya Jain',            2033, 'app',    'subscribed',   MD5('ns-1871'), DATE_SUB(NOW(), INTERVAL 260 DAY), NULL),
(1872, 'krishna.sample.ev@example.com',        'Krishna Menon',         2039, 'app',    'bounced',      MD5('ns-1872'), DATE_SUB(NOW(), INTERVAL 252 DAY), NULL),
(1873, 'anushka.sample.ink@example.com',         'Anushka Pathak',        2044, 'app',    'subscribed',   MD5('ns-1873'), DATE_SUB(NOW(), INTERVAL 244 DAY), NULL),
(1874, 'ritika.sharma@example.com',       'Ritika Sharma',         NULL, 'web',    'subscribed',   MD5('ns-1874'), DATE_SUB(NOW(), INTERVAL 388 DAY), NULL),
(1875, 'aniruddh.menon@example.com', 'Aniruddh Menon',     NULL, 'web',    'subscribed',   MD5('ns-1875'), DATE_SUB(NOW(), INTERVAL 354 DAY), NULL),
(1876, 'karan.bhatia@example.com',    'Karan Bhatia',          NULL, 'web',    'subscribed',   MD5('ns-1876'), DATE_SUB(NOW(), INTERVAL 246 DAY), NULL),
(1877, 'sneha.iyengar@example.com',        'Sneha Iyengar',         NULL, 'web',    'subscribed',   MD5('ns-1877'), DATE_SUB(NOW(), INTERVAL 210 DAY), NULL),
(1878, 'meenakshi.rao@example.com',      'Meenakshi Rao',         NULL, 'web',    'subscribed',   MD5('ns-1878'), DATE_SUB(NOW(), INTERVAL 132 DAY), NULL),
(1879, 'vikas.chandra@example.com',        'Vikas Chandra',         NULL, 'web',    'subscribed',   MD5('ns-1879'), DATE_SUB(NOW(), INTERVAL 96 DAY),  NULL),
(1880, 'tanmay.ghosh@example.com',        'Tanmay Ghosh',          NULL, 'web',    'subscribed',   MD5('ns-1880'), DATE_SUB(NOW(), INTERVAL 39 DAY),  NULL),
(1881, 'abhinav.rastogi@example.com',      'Abhinav Rastogi',       NULL, 'web',    'subscribed',   MD5('ns-1881'), DATE_SUB(NOW(), INTERVAL 4 DAY),   NULL),
(1882, 'nandita.pillai@example.com',    'Nandita Pillai',        NULL, 'web',    'subscribed',   MD5('ns-1882'), DATE_SUB(NOW(), INTERVAL 318 DAY), NULL),
(1883, 'sameer.joshi@example.com', 'Sameer Joshi',          NULL, 'web',    'unsubscribed', MD5('ns-1883'), DATE_SUB(NOW(), INTERVAL 296 DAY), DATE_SUB(NOW(), INTERVAL 188 DAY)),
(1884, 'reena.mathew@example.com',    'Reena Mathew',          NULL, 'web',    'subscribed',   MD5('ns-1884'), DATE_SUB(NOW(), INTERVAL 271 DAY), NULL),
(1885, 'harsh.vardhan@example.com',    'Harsh Vardhan',         NULL, 'web',    'subscribed',   MD5('ns-1885'), DATE_SUB(NOW(), INTERVAL 233 DAY), NULL),
(1886, 'lavanya.s@example.com',   'Lavanya Srinivasan',    NULL, 'import', 'subscribed',   MD5('ns-1886'), DATE_SUB(NOW(), INTERVAL 214 DAY), NULL),
(1887, 'gaurav.sethi@example.com',   'Gaurav Sethi',          NULL, 'import', 'subscribed',   MD5('ns-1887'), DATE_SUB(NOW(), INTERVAL 214 DAY), NULL),
(1888, 'ayesha.rehman@example.com',    'Ayesha Rehman',         NULL, 'import', 'bounced',      MD5('ns-1888'), DATE_SUB(NOW(), INTERVAL 214 DAY), NULL),
(1889, 'inkpot.digest@example.com',    'Inkpot Creator Digest',    NULL, 'admin',  'subscribed',   MD5('ns-1889'), DATE_SUB(NOW(), INTERVAL 143 DAY), NULL),
(1890, 'shelfline.letter@example.com',    'Shelfline D2C Letter',      NULL, 'admin',  'subscribed',   MD5('ns-1890'), DATE_SUB(NOW(), INTERVAL 78 DAY),  NULL)
ON DUPLICATE KEY UPDATE id = VALUES(id);


/* ==========================================================================
 * 10. REPORTS  (1901 - 1912)
 * --------------------------------------------------------------------------
 * Polymorphic abuse and fraud reports. reportable_id points at the seeded
 * ranges for each type: creators 101-145, users 1001-2045, bookings 401-480,
 * reviews 701-760, messages 901-1300.
 * ========================================================================== */

INSERT INTO reports
    (id, reporter_id, reportable_type, reportable_id, reason, description,
     evidence_urls, status, handled_by, handled_at, resolution_note,
     created_at, updated_at)
VALUES
(1901, 1014, 'creator', 128, 'impersonation',
 'This profile is using our esports team logo, name and colours and is offering paid shoutouts as if it is an official account. It is not connected to us in any way.',
 '["https://picsum.photos/seed/zuste-report-1901-a/1080/1920","https://picsum.photos/seed/zuste-report-1901-b/1080/1920"]',
 'resolved', 1, DATE_SUB(NOW(), INTERVAL 54 DAY),
 'Impersonation confirmed. Profile permanently removed, two pending offers cancelled and both brands notified.',
 DATE_SUB(NOW(), INTERVAL 55 DAY), DATE_SUB(NOW(), INTERVAL 54 DAY)),

(1902, 1003, 'creator', 115, 'fraud',
 'The follower count and engagement screenshots on this profile do not match what we saw after booking. Comment sections are almost entirely generic emoji from accounts with no posts.',
 '["https://picsum.photos/seed/zuste-report-1902/1080/1920"]',
 'investigating', 1, NULL, NULL,
 DATE_SUB(NOW(), INTERVAL 12 DAY), DATE_SUB(NOW(), INTERVAL 9 DAY)),

(1903, 2011, 'user', 1017, 'payment_issue',
 'The brand accepted my delivery in the booking chat and then raised a dispute four days later to avoid releasing the payout. The delivery matched every mandatory in the brief.',
 NULL,
 'investigating', 1, NULL, NULL,
 DATE_SUB(NOW(), INTERVAL 6 DAY), DATE_SUB(NOW(), INTERVAL 5 DAY)),

(1904, 1019, 'booking', 450, 'other',
 'Creator removed the post eleven days into a contracted thirty day live period while we were running paid media against the permalink.',
 NULL,
 'resolved', 1, DATE_SUB(NOW(), INTERVAL 61 DAY),
 'Post restored by the creator and the live period reset to run thirty days from restoration. Term acknowledged in writing on the booking.',
 DATE_SUB(NOW(), INTERVAL 62 DAY), DATE_SUB(NOW(), INTERVAL 61 DAY)),

(1905, 2015, 'user', 1003, 'abuse',
 'The brand contact has been abusive in the booking chat after I declined a fourth unpaid revision, including threats to leave a one star review across my profiles.',
 '["https://picsum.photos/seed/zuste-report-1905/1080/1920"]',
 'open', NULL, NULL, NULL,
 DATE_SUB(NOW(), INTERVAL 8 DAY), DATE_SUB(NOW(), INTERVAL 8 DAY)),

(1906, 1001, 'review', 733, 'spam',
 'This review is not about our campaign at all. It appears to be copy pasted text about a different brand and product category.',
 NULL,
 'resolved', 1, DATE_SUB(NOW(), INTERVAL 118 DAY),
 'Review confirmed as misposted content and unpublished. Reviewer notified and asked to repost against the correct booking.',
 DATE_SUB(NOW(), INTERVAL 120 DAY), DATE_SUB(NOW(), INTERVAL 118 DAY)),

(1907, 2020, 'message', 1142, 'inappropriate',
 'A brand contact sent a personal message in the booking thread that had nothing to do with the campaign and made me uncomfortable.',
 NULL,
 'resolved', 1, DATE_SUB(NOW(), INTERVAL 174 DAY),
 'Message reviewed and removed. Brand account issued a formal warning and the contact removed from the booking thread.',
 DATE_SUB(NOW(), INTERVAL 176 DAY), DATE_SUB(NOW(), INTERVAL 174 DAY)),

(1908, 1013, 'portfolio', 214, 'copyright',
 'The portfolio piece at this position is footage we commissioned and paid for under a UGC only agreement with no portfolio rights. It should not be published publicly.',
 '["https://picsum.photos/seed/zuste-report-1908/1200/675"]',
 'resolved', 1, DATE_SUB(NOW(), INTERVAL 88 DAY),
 'Portfolio item removed after confirming the UGC only terms on the original booking excluded portfolio use.',
 DATE_SUB(NOW(), INTERVAL 90 DAY), DATE_SUB(NOW(), INTERVAL 88 DAY)),

(1909, 2033, 'user', 1006, 'payment_issue',
 'Booking was cancelled by the brand after I had already shot and edited the content. No cancellation fee has been offered despite the terms on the booking.',
 NULL,
 'resolved', 1, DATE_SUB(NOW(), INTERVAL 206 DAY),
 'Cancellation terms applied. Fifty percent kill fee released to the creator from the held payment and the balance refunded to the brand.',
 DATE_SUB(NOW(), INTERVAL 209 DAY), DATE_SUB(NOW(), INTERVAL 206 DAY)),

(1910, 1011, 'creator', 137, 'spam',
 'This creator sends the same copy pasted pitch to every campaign brief we post, including briefs in categories they have never worked in.',
 NULL,
 'dismissed', 1, DATE_SUB(NOW(), INTERVAL 44 DAY),
 'Reviewed. Applications were within platform limits and the pitches, while templated, are not policy violations. Creator advised to tailor applications.',
 DATE_SUB(NOW(), INTERVAL 46 DAY), DATE_SUB(NOW(), INTERVAL 44 DAY)),

(1911, 2027, 'user', 1017, 'other',
 'The brand added a competitor exclusivity clause to the brief after the booking was confirmed and is treating it as binding.',
 NULL,
 'open', NULL, NULL, NULL,
 DATE_SUB(NOW(), INTERVAL 3 DAY), DATE_SUB(NOW(), INTERVAL 3 DAY)),

(1912, 1009, 'creator', 142, 'fraud',
 'The creator asked us to settle outside the platform via UPI to avoid the platform fee, and then stopped responding once we declined.',
 '["https://picsum.photos/seed/zuste-report-1912/1080/1920"]',
 'investigating', 1, NULL, NULL,
 DATE_SUB(NOW(), INTERVAL 19 DAY), DATE_SUB(NOW(), INTERVAL 16 DAY))
ON DUPLICATE KEY UPDATE id = VALUES(id);

/* The account behind each report (reports.reported_user_id, migration 054),
 * which the moderation queue and the admin user page count by. Seeds run
 * after the migrations, so 054's own backfill never reaches these rows on a
 * fresh install: the same statements are repeated here, limited to this
 * file's ids, followed by a copy of what was reported where it is content
 * (subject_excerpt), as ReportService::file() stores it. Every statement
 * only fills a column that is still empty, so re-running changes nothing. */

UPDATE reports r
  JOIN users u ON u.id = r.reportable_id
   SET r.reported_user_id = u.id
 WHERE r.id BETWEEN 1901 AND 1912 AND r.reported_user_id IS NULL
   AND r.reportable_type = 'user';

UPDATE reports r
  JOIN creators c ON c.id = r.reportable_id
  JOIN users u ON u.id = c.user_id
   SET r.reported_user_id = u.id
 WHERE r.id BETWEEN 1901 AND 1912 AND r.reported_user_id IS NULL
   AND r.reportable_type = 'creator';

UPDATE reports r
  JOIN creator_portfolio p ON p.id = r.reportable_id
  JOIN creators c ON c.id = p.creator_id
  JOIN users u ON u.id = c.user_id
   SET r.reported_user_id = u.id,
       r.subject_excerpt  = COALESCE(r.subject_excerpt, NULLIF(TRIM(p.title), ''))
 WHERE r.id BETWEEN 1901 AND 1912 AND r.reported_user_id IS NULL
   AND r.reportable_type = 'portfolio';

UPDATE reports r
  JOIN reviews v ON v.id = r.reportable_id
  JOIN users u ON u.id = v.reviewer_id
   SET r.reported_user_id = u.id,
       r.subject_excerpt  = COALESCE(r.subject_excerpt,
                                     LEFT(CONCAT_WS(' - ', NULLIF(TRIM(v.title), ''), NULLIF(TRIM(v.comment), '')), 500))
 WHERE r.id BETWEEN 1901 AND 1912 AND r.reported_user_id IS NULL
   AND r.reportable_type = 'review';

UPDATE reports r
  JOIN messages m ON m.id = r.reportable_id AND m.sender_type = 'user'
  JOIN users u ON u.id = m.sender_id
   SET r.reported_user_id = u.id,
       r.subject_excerpt  = COALESCE(r.subject_excerpt, LEFT(NULLIF(TRIM(m.body), ''), 500))
 WHERE r.id BETWEEN 1901 AND 1912 AND r.reported_user_id IS NULL
   AND r.reportable_type = 'message';

-- A booking report is about the other party: whichever side did not file it.
UPDATE reports r
  JOIN bookings b ON b.id = r.reportable_id
  JOIN users u ON u.id = IF(b.brand_user_id = r.reporter_id, b.creator_user_id, b.brand_user_id)
   SET r.reported_user_id = u.id
 WHERE r.id BETWEEN 1901 AND 1912 AND r.reported_user_id IS NULL
   AND r.reportable_type = 'booking';


/* ==========================================================================
 * 11. DERIVED COUNTERS
 *     Recomputed from the rows actually present, so re-running is safe.
 * ========================================================================== */

UPDATE media_folders f
   SET f.file_count = (
        SELECT COUNT(*) FROM media m
         WHERE m.folder_id = f.id AND m.deleted_at IS NULL
   ),
       f.size_bytes = (
        SELECT COALESCE(SUM(m.size_bytes), 0) FROM media m
         WHERE m.folder_id = f.id AND m.deleted_at IS NULL
   )
 WHERE f.id BETWEEN 1 AND 5;

/* ========================= end 026_demo_cms_support.sql ================== */
