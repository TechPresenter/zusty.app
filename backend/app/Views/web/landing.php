<?php
/**
 * The public landing page at "/" (Web\LandingController::index).
 *
 * Every variable is a plain value from settings; it is escaped here, where it
 * is printed, with $e(). The one exception is $jsonLd, which the controller
 * encodes with JSON_HEX_TAG so it cannot close its <script> data block.
 *
 * No script runs on this page (Response::html's CSP allows none), no external
 * font, stylesheet or tracker is loaded, and the only images are the page's
 * own under /assets/landing. The stylesheet is landing/landing.css, printed
 * inline because that CSP allows inline styles only. The feature tabs are
 * radio inputs with labels and the FAQ is <details>, so both work without
 * script and from the keyboard.
 *
 * The copy states only what the product does: no ratings, user counts,
 * testimonials or rankings. Prices, fees and timings come from settings.
 *
 * @var callable(mixed):string                            $e
 * @var string                                            $appName
 * @var string                                            $company
 * @var string                                            $tagline
 * @var string                                            $title
 * @var string                                            $description
 * @var string                                            $canonical
 * @var string                                            $support
 * @var string                                            $twitter
 * @var list<array{key:string,label:string,url:string}>   $social
 * @var string                                            $storeUrl
 * @var string                                            $storeHref
 * @var string                                            $invoiceTax
 * @var array<string,string>                              $companyLinks
 * @var array<string,string>                              $legalLinks
 * @var string                                            $year
 * @var string                                            $assets
 * @var string                                            $logo
 * @var string                                            $logoDark
 * @var string                                            $favicon
 * @var string                                            $ogImage
 * @var int|null                                          $ogWidth
 * @var int|null                                          $ogHeight
 * @var string                                            $fee
 * @var string                                            $commission
 * @var string                                            $taxLabel
 * @var int                                               $autoComplete
 * @var bool                                              $counterOffers
 * @var string                                            $jsonLd
 */

$icons = [
    'check'    => '<path d="M5 12.5l4.2 4.2L19 7"/>',
    'arrow'    => '<path d="M5 12h14M13 6l6 6-6 6"/>',
    'shield'   => '<path d="M12 3l7 3v5.2c0 4.4-2.9 8.2-7 9.8-4.1-1.6-7-5.4-7-9.8V6z"/><path d="M8.6 12.2l2.4 2.4 4.4-4.8"/>',
    'lock'     => '<rect x="4.5" y="10.5" width="15" height="10" rx="2.5"/><path d="M8 10.5V7.8a4 4 0 0 1 8 0v2.7M12 14.5v2"/>',
    'receipt'  => '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2z"/><path d="M9 8h6M9 12h6M9 16h3"/>',
    'search'   => '<circle cx="11" cy="11" r="6.5"/><path d="M20 20l-4.2-4.2"/>',
    'calendar' => '<rect x="3.5" y="5" width="17" height="15.5" rx="2.5"/><path d="M3.5 10h17M8 3v4M16 3v4M8.8 14.8l2 2 4.2-4.2"/>',
    'wallet'   => '<path d="M4.5 8V7a2.5 2.5 0 0 1 2.5-2.5h10V8"/><rect x="3.5" y="8" width="17" height="12" rx="2.5"/><path d="M16 14h.01"/>',
    'id'       => '<rect x="3" y="5" width="18" height="14" rx="2.5"/><circle cx="9" cy="11" r="2.2"/><path d="M5.8 16c.6-1.5 1.8-2.3 3.2-2.3s2.6.8 3.2 2.3M14.5 10h3.5M14.5 13.5h2.5"/>',
    'privacy'  => '<path d="M13 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h3.5"/><path d="M13 3l5 5h-5z"/><rect x="13" y="14.5" width="8" height="6.5" rx="1.6"/><path d="M15 14.5V13a2 2 0 0 1 4 0v1.5"/>',
];

$icon = static fn (string $name, string $class = 'ico'): string =>
    '<svg class="' . $class . '" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . $icons[$name] . '</svg>';

$tick = '<svg class="tick" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="10"/><path d="M7.6 12.4l3 3 5.8-6.2"/></svg>';

$spark = static fn (string $class): string =>
    '<svg class="spark ' . $class . '" viewBox="-14 -14 28 28" aria-hidden="true" focusable="false">'
    . '<path d="M0-14C1.5-5 5-1.5 14 0 5 1.5 1.5 5 0 14-1.5 5-5 1.5-14 0-5-1.5-1.5-5 0-14Z"/></svg>';

// Social profiles from settings, as outline icons in the footer.
$socialIcons = [
    'instagram' => '<rect x="3.5" y="3.5" width="17" height="17" rx="5"/><circle cx="12" cy="12" r="3.8"/><path d="M17.2 6.8h.01"/>',
    'youtube'   => '<rect x="2.5" y="5.5" width="19" height="13" rx="4"/><path d="M10.2 9.4v5.2l4.4-2.6z"/>',
    'linkedin'  => '<rect x="3.5" y="3.5" width="17" height="17" rx="3"/><path d="M8 10.5v6M8 7.6v.01M11.6 16.5v-6M11.6 13.3c0-1.7 1-2.8 2.4-2.8s2.4 1 2.4 2.8v3.2"/>',
    'twitter'   => '<path d="M4.5 4h4.2l10.8 16h-4.2z"/><path d="M19 4l-6.1 6.9M5 20l6.1-6.9"/>',
    'facebook'  => '<path d="M15 3.5h-2.1A3.6 3.6 0 0 0 9.3 7.1V10H7v3.4h2.3v7.1h3.4v-7.1h2.6l.5-3.4h-3.1V7.6c0-.6.4-1 1-1H15z"/>',
];

// The Google Play mark for the badge, in its own colours.
$playLogo = '<svg class="store-logo" viewBox="0 0 24 24" aria-hidden="true" focusable="false">'
    . '<path fill="#00C3FF" d="M3.6 2.3c-.3.3-.5.8-.5 1.4v16.6c0 .6.2 1.1.5 1.4l.1.1 9.3-9.3v-.2L3.7 2.2z"/>'
    . '<path fill="#FFC400" d="M16.1 15.6L13 12.5v-.2l3.1-3.1.1.1 3.7 2.1c1 .6 1 1.6 0 2.2l-3.7 2.1z"/>'
    . '<path fill="#FF3A44" d="M16.2 15.5L13 12.4l-9.4 9.4c.3.4.9.4 1.6.1l11-6.4"/>'
    . '<path fill="#00E06B" d="M16.2 9.3L5.2 3c-.7-.4-1.3-.3-1.6.1l9.4 9.3z"/></svg>';

// The Google Play call to action: a link while the listing is live, a plain
// "Coming soon" label (never a dead link) while it is not.
$store = static function () use ($storeUrl, $e, $playLogo): string {
    if ($storeUrl === '') {
        return '<p class="store store--soon">' . $playLogo
            . '<span class="store-text"><small>Coming soon on</small> <strong>Google Play</strong></span></p>';
    }

    return '<a class="store" href="' . $e($storeUrl) . '">' . $playLogo
        . '<span class="store-text"><small>Get it on</small> <strong>Google Play</strong></span></a>';
};

// A real app screenshot (720x1600 WebP) inside a CSS phone frame. Only the
// hero's loads straight away; the rest wait until they are near the viewport.
$shot = static function (string $name, string $alt, string $class = '', bool $eager = false) use ($assets, $e): string {
    return '<img' . ($class !== '' ? ' class="' . $class . '"' : '')
        . ' src="' . $assets . 'screens/' . $name . '.webp" width="720" height="1600" alt="' . $e($alt) . '"'
        . ($eager ? ' fetchpriority="high"' : ' loading="lazy" decoding="async"') . '>';
};
$phone = static fn (string $screen): string => '<div class="phone"><div class="screen">' . $screen . '</div></div>';

$charges = [];
if ($fee !== '') {
    $charges[] = 'brands pay a ' . $e($fee) . '% platform fee' . ($taxLabel !== '' ? ' (plus ' . $e($taxLabel) . ' on that fee)' : '');
}
if ($commission !== '') {
    $charges[] = 'creators pay a standard ' . $e($commission) . '% commission on their price';
}
$chargesLine = $charges !== [] ? ' When a booking is made, ' . implode(' and ', $charges) . '.' : '';

$days = $autoComplete . ' ' . ($autoComplete === 1 ? 'day' : 'days');

$footTagline = $tagline !== '' ? $tagline : 'Brands book verified creators. Creators get paid securely.';

// Section 3: each feature, the screenshot that shows it, and what that shows.
// Only screenshots that match the current app and demo data are used. The
// old search, profile, opportunities, chat, creator-home and wallet shots
// showed retired demo names, an ended brief marked Open, a review-for-reward
// message, the old banner and the wallet's Add money; their files are gone
// from assets/landing/screens, and 23_landing keeps them off the page until
// they are retaken.
$features = [
    [
        'title' => 'Discover creators',
        'text'  => 'Search by city, category, platform, followers and price, and see who is verified at a glance.',
        'shot'  => 'home',
        'alt'   => 'The brand home screen: a prompt to post a brief, a search bar for creators and categories with a filter button, top categories and a collaboration in progress.',
    ],
    [
        'title' => 'See real profiles',
        'text'  => 'Check followers, packages and past work before you reach out. The blue tick appears only after our team approves a creator.',
        'shot'  => 'packages',
        'alt'   => 'A creator’s packages as a brand sees them: a reel, a story series, a feed post and reel combo, a YouTube integration and a campaign bundle, each with its price, delivery days and revisions, and a Send a custom request button.',
    ],
    [
        'title' => 'Book with payment protection',
        'text'  => 'Pick a package or post a brief and choose who applies. You pay once the creator accepts, and ' . $appName . ' holds the payment until you approve the work.',
        'shot'  => 'request',
        'alt'   => 'A new booking request: fixed-price packages with delivery days and revisions, one of them selected, a campaign brief, and a note that nothing is charged until the creator accepts.',
    ],
    [
        'title' => 'Approve the work',
        'text'  => 'Review what the creator delivers, ask for changes, or approve it to release the payment. If you don’t respond within ' . $days . ' of delivery, the booking completes automatically.',
        'shot'  => 'approve',
        'alt'   => 'Booking details for a delivered campaign marked Ready for your review, with an Approve and release payment button.',
    ],
    [
        'title' => 'Chat in one place',
        'text'  => 'Talk to creators and share feedback in the app, with each conversation linked to its booking.',
        'shot'  => 'inbox',
        'alt'   => 'The Messages screen: conversations with creators, each showing its booking reference and status, with search and Unread and Bookings filters.',
    ],
];

// The logo for the current colour scheme: the admin's branding image when one
// is set, else the page's own resized copies. The footer copy waits until it
// is near the viewport.
$logoImg = static function (bool $lazy = false) use ($logo, $logoDark, $assets, $appName, $e): string {
    $load  = $lazy ? ' loading="lazy" decoding="async"' : '';
    $dark  = $logoDark !== ''
        ? '<source srcset="' . $e($logoDark) . '" media="(prefers-color-scheme: dark)">'
        : '<source srcset="' . $assets . 'logo-dark.webp" type="image/webp" media="(prefers-color-scheme: dark)">'
          . '<source srcset="' . $assets . 'logo-dark.png" media="(prefers-color-scheme: dark)">';
    $light = $logo !== ''
        ? '<img class="logo" src="' . $e($logo) . '" alt="' . $e($appName) . '"' . $load . '>'
        : '<source srcset="' . $assets . 'logo.webp" type="image/webp">'
          . '<img class="logo" src="' . $assets . 'logo.png" width="166" height="36" alt="' . $e($appName) . '"' . $load . '>';

    return '<picture>' . $dark . $light . '</picture>';
};

$sections = [
    '#brands'   => 'For brands',
    '#creators' => 'For creators',
    '#how'      => 'How it works',
    '#safety'   => 'Safety',
    '#faq'      => 'FAQ',
];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $e($title) ?></title>
  <meta name="description" content="<?= $e($description) ?>">
  <link rel="canonical" href="<?= $e($canonical) ?>">
  <meta name="robots" content="index, follow, max-image-preview:large">
  <meta name="color-scheme" content="light dark">
  <meta name="theme-color" content="#FFFFFF" media="(prefers-color-scheme: light)">
  <meta name="theme-color" content="#140E11" media="(prefers-color-scheme: dark)">
  <link rel="icon" href="<?= $e($favicon) ?>">
  <link rel="apple-touch-icon" href="<?= $assets ?>apple-touch-icon.png">

  <meta property="og:type" content="website">
  <meta property="og:site_name" content="<?= $e($appName) ?>">
  <meta property="og:locale" content="en_IN">
  <meta property="og:url" content="<?= $e($canonical) ?>">
  <meta property="og:title" content="<?= $e($title) ?>">
  <meta property="og:description" content="<?= $e($description) ?>">
  <meta property="og:image" content="<?= $e($ogImage) ?>">
<?php if ($ogWidth !== null && $ogHeight !== null): ?>
  <meta property="og:image:width" content="<?= (int) $ogWidth ?>">
  <meta property="og:image:height" content="<?= (int) $ogHeight ?>">
<?php endif; ?>
  <meta property="og:image:alt" content="<?= $e($appName) ?> — Influencer Marketplace">
  <meta name="twitter:card" content="summary_large_image">
<?php if ($twitter !== ''): ?>
  <meta name="twitter:site" content="<?= $e($twitter) ?>">
<?php endif; ?>
  <meta name="twitter:title" content="<?= $e($title) ?>">
  <meta name="twitter:description" content="<?= $e($description) ?>">
  <meta name="twitter:image" content="<?= $e($ogImage) ?>">
  <meta name="twitter:image:alt" content="<?= $e($appName) ?> — Influencer Marketplace">

  <script type="application/ld+json"><?= $jsonLd ?></script>

  <style>
<?php readfile(__DIR__ . '/landing/landing.css'); ?>
  </style>
</head>
<body>
  <a class="skip" href="#main">Skip to content</a>

  <header class="site-header">
    <div class="wrap bar">
      <a class="brand" href="/"><?= $logoImg() ?></a>
      <nav class="nav" aria-label="Sections">
<?php foreach ($sections as $href => $label): ?>
        <a href="<?= $href ?>"><?= $label ?></a>
<?php endforeach; ?>
      </nav>
      <a class="btn btn-primary btn-sm" href="<?= $e($storeHref) ?>">Get the app</a>
    </div>
  </header>
  <nav class="mnav" aria-label="Sections">
    <ul role="list">
<?php foreach ($sections as $href => $label): ?>
      <li><a href="<?= $href ?>"><?= $label ?></a></li>
<?php endforeach; ?>
    </ul>
  </nav>

  <main id="main" tabindex="-1">
    <section class="hero" aria-labelledby="hero-title">
      <div class="wrap hero-grid">
        <div class="hero-copy">
          <ul class="trust-row" role="list">
            <li><?= $icon('check') ?>KYC-verified creators</li>
            <li><?= $icon('check') ?>Payment held until you approve</li>
            <li><?= $icon('check') ?><?= $e($invoiceTax) ?> invoices</li>
          </ul>
          <h1 id="hero-title">Brands book <span class="grad">verified creators</span>. Creators get paid securely.</h1>
          <p class="lead"><?= $e($appName) ?> is where brands discover and book Instagram and YouTube creators, choosing one of a creator’s fixed-price packages or naming their own budget. Creators get paid when the work is approved, and until then <?= $e($appName) ?> holds the brand’s payment.</p>
          <div class="cta-row">
            <?= $store() ?>
            <a class="btn btn-ghost" href="#how">See how it works <?= $icon('arrow') ?></a>
          </div>
          <p class="note">
            <svg viewBox="0 0 46 40" aria-hidden="true" focusable="false"><path d="M42 34C27 35 13 27 9 8"/><path d="M3.5 13.5L9 7l6.5 5.2"/></svg>
            <span>Free to join for brands and creators</span>
          </p>
        </div>

        <div class="hero-art">
          <span class="ring" aria-hidden="true"></span>
          <?= $spark('spark-1') ?><?= $spark('spark-2') ?><?= $spark('spark-3') ?>
          <?= $phone($shot('home', 'The ' . $appName . ' home screen for brands: a prompt to post a brief, creator search, a banner reading “Find the Right Creator for Your Next Campaign”, top categories and an active campaign.', '', true)) ?>
          <div class="float f1" aria-hidden="true"><span class="chip-ico ci-blue"><?= $tick ?></span><span><b>KYC verified</b><small>Checked by our team</small></span></div>
          <div class="float f2" aria-hidden="true"><span class="chip-ico ci-pink"><?= $icon('lock') ?></span><span><b>₹8,000 held by <?= $e($appName) ?></b><small>Released when you approve</small></span></div>
          <div class="float f3" aria-hidden="true"><span class="chip-ico ci-green"><?= $icon('check') ?></span><span><b>Booking confirmed</b><small>The creator can start</small></span></div>
        </div>
      </div>
    </section>

    <section class="section" id="features" aria-labelledby="features-title">
      <div class="wrap">
        <div class="head">
          <h2 class="h2" id="features-title">What can you do on <?= $e($appName) ?>?</h2>
          <p class="sub">From the first search to the final payment, every step happens in one app.</p>
        </div>
        <div class="tabs">
<?php foreach ($features as $i => $feature): $n = $i + 1; ?>
          <input class="tab-input" type="radio" name="feature" id="ft-<?= $n ?>" aria-labelledby="ft-<?= $n ?>-t" aria-describedby="ft-<?= $n ?>-d"<?= $n === 1 ? ' checked' : '' ?>>
<?php endforeach; ?>
          <div class="tab-media">
            <div class="phone"><div class="screen">
<?php foreach ($features as $i => $feature): ?>
              <?= $shot($feature['shot'], $feature['alt'], 'shot shot-' . ($i + 1)) ?>

<?php endforeach; ?>
            </div></div>
            <div class="dots" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i></div>
          </div>
          <div class="tab-list">
<?php foreach ($features as $i => $feature): $n = $i + 1; ?>
            <label class="tab" for="ft-<?= $n ?>"><span class="tab-title" id="ft-<?= $n ?>-t"><?= $e($feature['title']) ?></span><span class="tab-text"><?= $e($feature['text']) ?></span></label>
<?php endforeach; ?>
          </div>
          <div class="tab-caps">
<?php foreach ($features as $i => $feature): $n = $i + 1; ?>
            <p class="cap cap-<?= $n ?>"><b aria-hidden="true"><?= $e($feature['title']) ?></b><span id="ft-<?= $n ?>-d"><?= $e($feature['text']) ?></span></p>
<?php endforeach; ?>
          </div>
        </div>
      </div>
    </section>

    <section class="section" id="why" aria-labelledby="why-title">
      <div class="wrap">
        <div class="head">
          <h2 class="h2" id="why-title">Why brands and creators choose <?= $e($appName) ?></h2>
        </div>
        <ul class="why" role="list">
          <li class="why-item">
            <span class="why-icon"><?= $icon('shield') ?></span>
            <h3>Verified, not guessed</h3>
            <p>Creators verify with Aadhaar and PAN, and a person on our team reviews every application. The blue tick appears only after that approval.</p>
            <a class="more" href="#safety">How verification works <?= $icon('arrow') ?></a>
          </li>
          <li class="why-item">
            <span class="why-icon"><?= $icon('lock') ?></span>
            <h3>Payment protection</h3>
            <p>When a booking is confirmed, the brand’s payment is held by <?= $e($appName) ?>. It is released to the creator when the brand approves the work, or automatically if the brand does not respond within <?= $e($days) ?> of delivery.</p>
            <a class="more" href="#safety">How payment protection works <?= $icon('arrow') ?></a>
          </li>
          <li class="why-item">
            <span class="why-icon"><?= $icon('receipt') ?></span>
            <h3>Clear prices, proper invoices</h3>
            <p>Creators publish fixed packages, you see every fee before you pay, and every booking comes with a <?= $e($invoiceTax) ?> invoice.</p>
            <a class="more" href="/terms">Read our terms <?= $icon('arrow') ?></a>
          </li>
        </ul>
      </div>
    </section>

    <section class="banner-band" aria-labelledby="banner-title">
      <div class="wrap">
        <div class="banner">
          <div class="banner-copy">
            <h2 class="h2" id="banner-title">One app for every collaboration</h2>
            <p>Search, book, chat, approve and pay, all in one place.</p>
          </div>
          <div class="banner-phones">
            <div class="bp bp-l"><?= $phone($shot('packages', 'A creator’s fixed-price packages with delivery days and revisions.')) ?></div>
            <div class="bp bp-c"><?= $phone($shot('approve', 'A delivered booking ready for review, with an Approve and release payment button.')) ?></div>
            <div class="bp bp-r"><?= $phone($shot('inbox', 'Messages with creators, each linked to its booking.')) ?></div>
          </div>
        </div>
      </div>
    </section>

    <section class="section" aria-labelledby="sides-title">
      <div class="wrap">
        <div class="head">
          <p class="kicker">One app, two sides</p>
          <h2 class="h2" id="sides-title">Built for both sides of the deal</h2>
          <p class="sub">Whether you are launching a product or growing an audience, <?= $e($appName) ?> handles discovery, bookings and payments for you.</p>
        </div>
        <div class="sides">
          <article class="side side--brands" id="brands" aria-labelledby="brands-title">
            <div class="side-media">
              <?= $phone($shot('request', 'A new booking request: fixed-price packages with delivery days and revisions, one of them selected, a campaign brief, and a note that nothing is charged until the creator accepts.')) ?>

            </div>
            <div class="side-body">
              <p class="kicker">For brands</p>
              <h3 id="brands-title">Find the right voices. Pay with protection.</h3>
              <p>Everything you need to run creator collaborations, from the shortlist to the invoice.</p>
              <ul class="checks" role="list">
                <li><?= $icon('check') ?><span>Search verified creators by niche, city, audience and budget.</span></li>
                <li><?= $icon('check') ?><span>Book a fixed package, or post a brief and choose who applies.</span></li>
                <li><?= $icon('check') ?><span><?= $e($appName) ?> holds your payment until you approve the work, or until <?= $e($days) ?> after delivery if you don’t respond.</span></li>
                <li><?= $icon('check') ?><span>A <?= $e($invoiceTax) ?> invoice for every booking.</span></li>
              </ul>
              <a class="btn btn-primary" href="<?= $e($storeHref) ?>">Start as a brand</a>
            </div>
          </article>
          <article class="side side--creators" id="creators" aria-labelledby="creators-title">
            <div class="side-media">
              <?= $phone($shot('verification', 'The creator’s verification screen: add Aadhaar, PAN and payout details for a one-time review that unlocks bookings and payouts; the blue tick is considered after approval.')) ?>

            </div>
            <div class="side-body">
              <p class="kicker">For creators</p>
              <h3 id="creators-title">Turn your audience into paid work.</h3>
              <p>Set your own packages, get booked by brands that fit, and get paid when the work is approved.</p>
              <ul class="checks" role="list">
                <li><?= $icon('check') ?><span>Verify once with Aadhaar and PAN. Our team then decides on the blue tick.</span></li>
                <li><?= $icon('check') ?><span>Set your own packages and rates, then <?= $counterOffers ? 'accept, decline or counter each offer' : 'accept or decline each request' ?>.</span></li>
                <li><?= $icon('check') ?><span>Apply to paid briefs from brands in your niche.</span></li>
                <li><?= $icon('check') ?><span>Withdraw your earnings to your bank account or UPI.</span></li>
              </ul>
              <a class="btn btn-primary" href="<?= $e($storeHref) ?>">Join as a creator</a>
            </div>
          </article>
        </div>
      </div>
    </section>

    <section class="section" id="how" aria-labelledby="how-title">
      <div class="wrap">
        <div class="head">
          <p class="kicker">How it works</p>
          <h2 class="h2" id="how-title">From first search to final payment</h2>
          <p class="sub">Three steps, with the money protected at every one of them.</p>
        </div>
        <ol class="steps" role="list">
          <li class="step">
            <div class="step-top"><span class="step-num grad" aria-hidden="true">01</span><span class="step-icon"><?= $icon('search') ?></span></div>
            <h3>Find</h3>
            <p>Search verified creators by city, category and price, or post a brief and let creators apply.</p>
          </li>
          <li class="step">
            <div class="step-top"><span class="step-num grad" aria-hidden="true">02</span><span class="step-icon"><?= $icon('calendar') ?></span></div>
            <h3>Book</h3>
            <p>Choose a package and send a request. Nothing is charged until the creator accepts; then <?= $e($appName) ?> holds your payment.</p>
          </li>
          <li class="step">
            <div class="step-top"><span class="step-num grad" aria-hidden="true">03</span><span class="step-icon"><?= $icon('wallet') ?></span></div>
            <h3>Approve &amp; pay</h3>
            <p>The creator delivers in the app. Approve the work to release the payment, or ask for changes first.</p>
          </li>
        </ol>
      </div>
    </section>

    <section class="section band-soft" id="safety" aria-labelledby="safety-title">
      <div class="wrap safety">
        <div class="safety-copy">
          <div class="head">
            <p class="kicker">Safety</p>
            <h2 class="h2" id="safety-title">Trust is built in</h2>
            <p class="sub">Every booking is designed so brands and creators know exactly where they stand.</p>
          </div>
          <ul class="safety-list" role="list">
            <li>
              <span class="si"><?= $icon('id') ?></span>
              <div>
                <h3>People check every verification</h3>
                <p>Creators verify with Aadhaar and PAN, and brands with business details such as GSTIN, PAN or CIN. A person on our team reviews each document, and only a masked Aadhaar copy is accepted.</p>
              </div>
            </li>
            <li>
              <span class="si"><?= $icon('lock') ?></span>
              <div>
                <h3>Payment protection on every booking</h3>
                <p>When a booking is confirmed, the brand’s payment is held by <?= $e($appName) ?>. It is released to the creator when the brand approves the work, or automatically if the brand does not respond within <?= $e($days) ?> of delivery. If there is a dispute, our team reviews it before any money is released.</p>
              </div>
            </li>
            <li>
              <span class="si"><?= $icon('privacy') ?></span>
              <div>
                <h3>Your data, your call</h3>
                <p>Our <a href="/privacy">Privacy Policy</a> explains what we collect and why. You can download your data from the app, or <a href="/account-deletion">delete your account</a> at any time.</p>
              </div>
            </li>
          </ul>
        </div>
        <div class="safety-art">
          <?= $phone($shot('verification', 'The verification centre: Get verified, blue tick not eligible yet, Aadhaar and PAN details, and documents that are each checked by a person.')) ?>

          <div class="float f4" aria-hidden="true"><span class="chip-ico ci-green"><?= $icon('check') ?></span><span><b>Checked by a person</b><small>not a machine</small></span></div>
          <div class="float f5" aria-hidden="true"><span class="chip-ico ci-pink"><?= $icon('id') ?></span><span><b>Masked Aadhaar only</b><small>Last 4 digits visible</small></span></div>
        </div>
      </div>
    </section>

    <section class="section" id="download" aria-labelledby="download-title">
      <div class="wrap">
        <div class="dl">
          <div class="dl-copy">
            <div class="head">
              <p class="kicker">Download</p>
              <h2 class="h2" id="download-title">Get <?= $e($appName) ?> on your phone</h2>
              <p class="sub"><?= $tagline !== '' ? $e($tagline) . ' ' : '' ?>Join as a brand or a creator. It’s free.</p>
            </div>
            <?= $store() ?>

            <ul class="facts" role="list">
              <li><?= $icon('check') ?>Android app</li>
              <li><?= $icon('check') ?>Free to join</li>
              <li><?= $icon('check') ?>For brands and creators</li>
            </ul>
<?php if ($support !== ''): ?>
            <p class="dl-note">Questions? Write to <a href="mailto:<?= $e($support) ?>"><?= $e($support) ?></a></p>
<?php endif; ?>
          </div>
          <div class="dl-art">
            <?= $phone($shot('packages', 'A creator’s packages: a reel, a story series, a feed post and reel combo, a YouTube integration and a campaign bundle, each with its price, delivery days and revisions, and a Send a custom request button.')) ?>

          </div>
        </div>
      </div>
    </section>

    <section class="section fair" id="fair-pay" aria-labelledby="fair-title">
      <div class="wrap fair-grid">
        <div class="fair-art">
          <svg viewBox="0 0 440 400" aria-hidden="true" focusable="false">
            <defs>
              <linearGradient id="fp-ring" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#6A00F4"/><stop offset=".55" stop-color="#C9004F"/><stop offset="1" stop-color="#FF7A45"/></linearGradient>
              <linearGradient id="fp-coin" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#FFC27A"/><stop offset="1" stop-color="#FF7A45"/></linearGradient>
            </defs>
            <circle class="fp-disc" cx="220" cy="190" r="150"/>
            <circle cx="220" cy="190" r="178" fill="none" stroke="url(#fp-ring)" stroke-width="2.5" stroke-linecap="round" stroke-dasharray="640 480" transform="rotate(-150 220 190)"/>
            <circle class="fp-dash" cx="220" cy="190" r="118"/>
            <g transform="rotate(-8 108 96)">
              <rect class="fp-card" x="30" y="62" width="156" height="66" rx="18"/>
              <circle cx="60" cy="95" r="15" fill="#168A5B"/>
              <path d="M53.5 95.5l4.5 4.5 8.5-9" fill="none" stroke="#fff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
              <rect class="fp-line" x="86" y="84" width="80" height="9" rx="4.5"/>
              <rect class="fp-line2" x="86" y="100" width="54" height="8" rx="4"/>
            </g>
            <g transform="rotate(7 340 302)">
              <rect class="fp-card" x="266" y="262" width="146" height="80" rx="18"/>
              <rect x="284" y="280" width="32" height="32" rx="10" fill="#FCE6EE"/>
              <path d="M293 287h14v18l-3.5-2-3.5 2-3.5-2-3.5 2zM296.5 293h7M296.5 298h4.5" fill="none" stroke="#C9004F" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
              <rect class="fp-line" x="326" y="284" width="66" height="8" rx="4"/>
              <rect class="fp-line2" x="326" y="300" width="44" height="8" rx="4"/>
              <rect x="284" y="322" width="108" height="7" rx="3.5" fill="url(#fp-ring)" opacity=".55"/>
            </g>
            <g>
              <circle cx="360" cy="92" r="28" fill="url(#fp-coin)"/>
              <circle cx="360" cy="92" r="21" fill="none" stroke="#fff" stroke-opacity=".6" stroke-width="2"/>
              <text x="360" y="101" text-anchor="middle" font-size="25" font-weight="800" fill="#fff" font-family="system-ui, sans-serif">₹</text>
            </g>
            <g>
              <circle cx="78" cy="304" r="20" fill="url(#fp-coin)"/>
              <circle cx="78" cy="304" r="14.5" fill="none" stroke="#fff" stroke-opacity=".6" stroke-width="1.6"/>
              <text x="78" y="310.5" text-anchor="middle" font-size="18" font-weight="800" fill="#fff" font-family="system-ui, sans-serif">₹</text>
            </g>
            <path fill="#C9004F" d="M300 22c1.5 9 5 12.5 14 14-9 1.5-12.5 5-14 14-1.5-9-5-12.5-14-14 9-1.5 12.5-5 14-14Z"/>
            <path fill="#6A00F4" opacity=".75" d="M40 196c1 6 3.4 8.4 9.4 9.4-6 1-8.4 3.4-9.4 9.4-1-6-3.4-8.4-9.4-9.4 6-1 8.4-3.4 9.4-9.4Z"/>
            <path fill="#FF7A45" d="M412 214c1 6 3.4 8.4 9.4 9.4-6 1-8.4 3.4-9.4 9.4-1-6-3.4-8.4-9.4-9.4 6-1 8.4-3.4 9.4-9.4Z"/>
            <circle cx="160" cy="362" r="5" fill="#6A00F4" opacity=".45"/>
            <circle cx="404" cy="150" r="4" fill="#C9004F" opacity=".5"/>
            <circle cx="120" cy="30" r="4" fill="#FF7A45" opacity=".6"/>
          </svg>
          <img src="<?= $assets ?>mark.webp" width="256" height="256" loading="lazy" decoding="async" alt="<?= $e($appName) ?> logo">
        </div>
        <div class="fair-copy">
          <div class="head">
            <p class="kicker">For creators</p>
            <h2 class="h2" id="fair-title">Every creator deserves fair pay</h2>
            <p class="sub">Whether you post from a metro or a small town, to a big following or a growing one, you set your own packages and prices. Brands pay before the work begins, and the money is released to you when they approve it, or automatically <?= $e($days) ?> after delivery if they don’t respond, so there is no invoice to chase.</p>
          </div>
          <a class="more" href="#creators">See what creators get <?= $icon('arrow') ?></a>
        </div>
      </div>
    </section>

    <section class="section" id="faq" aria-labelledby="faq-title">
      <div class="wrap">
        <div class="head">
          <p class="kicker">FAQ</p>
          <h2 class="h2" id="faq-title">Questions, answered</h2>
<?php if ($support !== ''): ?>
          <p class="sub">Something else on your mind? Write to <a href="mailto:<?= $e($support) ?>"><?= $e($support) ?></a>.</p>
<?php endif; ?>
        </div>
        <div class="faq">
          <details>
            <summary>What is <?= $e($appName) ?>?</summary>
            <div class="answer"><p><?= $e($appName) ?> is an influencer marketplace for India. Brands find and book Instagram and YouTube creators at clear prices, and creators get paid when the work is approved, all in one Android app.</p></div>
          </details>
          <details>
            <summary>How much does it cost?</summary>
            <div class="answer"><p>Signing up is free for brands and creators.<?= $chargesLine ?> You see the full breakdown before you confirm anything.</p></div>
          </details>
          <details>
            <summary>How does payment protection work?</summary>
            <div class="answer">
              <p>When a creator accepts your request, you pay and <?= $e($appName) ?> holds the payment. The creator delivers in the app, and when you approve the work the payment is released to them. If you don’t respond within <?= $e($days) ?> of delivery, the booking completes automatically and the payment is released.</p>
              <p>You can ask for changes first. If you still can’t agree, raise a dispute: our team reviews it before any money is released.</p>
            </div>
          </details>
          <details>
            <summary>How are creators verified?</summary>
            <div class="answer"><p>Creators submit their Aadhaar and PAN in the app. Aadhaar is accepted only as a masked copy, with just the last four digits visible. A person on the <?= $e($appName) ?> team reviews every application, and only an approved review earns the blue tick.</p></div>
          </details>
          <details>
            <summary>When and how do creators get paid?</summary>
            <div class="answer"><p>When the brand approves the work, or automatically <?= $e($days) ?> after delivery if the brand doesn’t respond, the payment is credited to the creator’s <?= $e($appName) ?> wallet. After a short hold, and once their verification is approved, creators can withdraw it to a bank account or UPI.</p></div>
          </details>
          <details>
            <summary>How do I delete my account?</summary>
            <div class="answer"><p>In the app, go to Settings → Privacy &amp; data → Delete my account. You can download a copy of your data there first. If you can’t use the app, follow the steps on our <a href="/account-deletion">account deletion page</a>.</p></div>
          </details>
        </div>
      </div>
    </section>
  </main>

  <footer class="site-footer">
    <div class="wrap">
      <div class="foot-grid">
        <div class="foot-brand">
          <a class="brand" href="/"><?= $logoImg(true) ?></a>
          <p><?= $e($footTagline) ?></p>
          <?= $store() ?>

<?php if ($support !== ''): ?>
          <p>Support: <a href="mailto:<?= $e($support) ?>"><?= $e($support) ?></a></p>
<?php endif; ?>
        </div>
        <nav aria-labelledby="foot-app">
          <h2 id="foot-app"><?= $e($appName) ?></h2>
          <ul class="foot-links" role="list">
            <li><a href="#brands">For brands</a></li>
            <li><a href="#creators">For creators</a></li>
            <li><a href="#how">How it works</a></li>
            <li><a href="#download">Get the app</a></li>
          </ul>
        </nav>
        <nav aria-labelledby="foot-company">
          <h2 id="foot-company">Company</h2>
          <ul class="foot-links" role="list">
<?php foreach ($companyLinks as $href => $label): ?>
            <li><a href="<?= $e($href) ?>"><?= $e($label) ?></a></li>
<?php endforeach; ?>
          </ul>
        </nav>
        <nav aria-labelledby="foot-legal">
          <h2 id="foot-legal">Legal</h2>
          <ul class="foot-links" role="list">
<?php foreach ($legalLinks as $href => $label): ?>
            <li><a href="<?= $e($href) ?>"><?= $e($label) ?></a></li>
<?php endforeach; ?>
          </ul>
        </nav>
      </div>
      <div class="foot-base">
        <p>&copy; <?= $e($year) ?> <?= $e($company) ?>. All rights reserved.</p>
<?php if ($social !== []): ?>
        <ul class="social" role="list" aria-label="<?= $e($appName) ?> on social media">
<?php foreach ($social as $link): ?>
          <li><a href="<?= $e($link['url']) ?>" rel="me noopener" aria-label="<?= $e($appName) ?> on <?= $e($link['label']) ?>"><svg class="ico" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><?= $socialIcons[$link['key']] ?? $icons['arrow'] ?></svg></a></li>
<?php endforeach; ?>
        </ul>
<?php endif; ?>
      </div>
    </div>
  </footer>
</body>
</html>
