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
 * own under /assets/landing.
 *
 * @var callable(mixed):string                  $e
 * @var string                                  $appName
 * @var string                                  $company
 * @var string                                  $tagline
 * @var string                                  $title
 * @var string                                  $description
 * @var string                                  $canonical
 * @var string                                  $support
 * @var string                                  $twitter
 * @var list<array{label:string,url:string}>    $social
 * @var string                                  $storeUrl
 * @var array<string,string>                    $legalLinks
 * @var string                                  $year
 * @var string                                  $assets
 * @var string                                  $logo
 * @var string                                  $logoDark
 * @var string                                  $favicon
 * @var string                                  $ogImage
 * @var int|null                                $ogWidth
 * @var int|null                                $ogHeight
 * @var string                                  $fee
 * @var string                                  $commission
 * @var string                                  $taxLabel
 * @var int                                     $autoComplete
 * @var bool                                    $counterOffers
 * @var string                                  $jsonLd
 */

$icons = [
    'check'   => '<path d="M5 12.5l4.2 4.2L19 7"/>',
    'brand'   => '<rect x="3" y="7" width="18" height="13" rx="2.5"/><path d="M9 7V5.5A2.5 2.5 0 0 1 11.5 3h1A2.5 2.5 0 0 1 15 5.5V7M3 13h18"/>',
    'creator' => '<rect x="3" y="6" width="13" height="12" rx="2.5"/><path d="M16 10.5l5-3v9l-5-3z"/>',
    'shield'  => '<path d="M12 3l7 3v5.2c0 4.4-2.9 8.2-7 9.8-4.1-1.6-7-5.4-7-9.8V6z"/><path d="M8.6 12.2l2.4 2.4 4.4-4.8"/>',
    'lock'    => '<rect x="4.5" y="10.5" width="15" height="10" rx="2.5"/><path d="M8 10.5V7.8a4 4 0 0 1 8 0v2.7"/>',
    'receipt' => '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2z"/><path d="M9 8h6M9 12h6M9 16h3"/>',
    'search'  => '<circle cx="11" cy="11" r="6.5"/><path d="M20 20l-4.2-4.2"/>',
];

$icon = static fn (string $name, string $class = 'ico'): string =>
    '<svg class="' . $class . '" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . $icons[$name] . '</svg>';

$tick = '<svg class="tick" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="10"/><path d="M7.6 12.4l3 3 5.8-6.2"/></svg>';

$playIcon = '<svg class="store-ico" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M5 3.6v16.8a1.1 1.1 0 0 0 1.66.95l14.1-8.4a1.1 1.1 0 0 0 0-1.9L6.66 2.65A1.1 1.1 0 0 0 5 3.6z"/></svg>';

// The Google Play call to action: a link while the listing is live, a plain
// "Coming soon" label (never a dead link) while it is not.
$store = static function (string $variant = '') use ($storeUrl, $e, $playIcon): string {
    $class = 'store' . ($variant !== '' ? ' store--' . $variant : '');

    if ($storeUrl === '') {
        return '<p class="' . $class . ' store--soon">' . $playIcon
            . '<span class="store-text"><small>Coming soon on</small> <strong>Google Play</strong></span></p>';
    }

    return '<a class="' . $class . '" href="' . $e($storeUrl) . '">' . $playIcon
        . '<span class="store-text"><small>Get it on</small> <strong>Google Play</strong></span></a>';
};

$feeLine = $fee !== ''
    ? ', the ' . $e($fee) . '% platform fee' . ($taxLabel !== '' ? ' and the ' . $e($taxLabel) . ' on that fee' : '')
    : ' with every fee and tax itemised';

$charges = [];
if ($fee !== '') {
    $charges[] = 'brands pay a ' . $e($fee) . '% platform fee' . ($taxLabel !== '' ? ' (plus ' . $e($taxLabel) . ' on that fee)' : '');
}
if ($commission !== '') {
    $charges[] = 'creators pay a standard ' . $e($commission) . '% commission on their price';
}
$chargesLine = $charges !== [] ? ' When a booking is made, ' . implode(' and ', $charges) . '.' : '';

$days = $autoComplete . ' ' . ($autoComplete === 1 ? 'day' : 'days');

// The logo for the current colour scheme: the admin's branding image when one
// is set, else the page's own resized copies. The header copy loads first;
// the footer copy waits until it is near the viewport.
$logoImg = static function (bool $lazy = false) use ($logo, $logoDark, $assets, $appName, $e): string {
    $load  = $lazy ? ' loading="lazy" decoding="async"' : ' fetchpriority="high"';
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
  <meta name="theme-color" content="#FFF8FA" media="(prefers-color-scheme: light)">
  <meta name="theme-color" content="#161013" media="(prefers-color-scheme: dark)">
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
    :root {
      --bg: #FFF8FA; --surface: #FFFFFF; --soft: #FFF1F5; --soft-2: #FCEAF0;
      --text: #211A1D; --muted: #746B70; --border: #F0DDE3;
      --primary: #C9004F; --primary-dark: #8F0038; --link: #B80049;
      --success: #168A5B; --verified: #1D8CF0; --focus: #C9004F;
      --header: rgba(255, 248, 250, .88);
      --grad-text: linear-gradient(92deg, #5B21B6 0%, #A3127F 45%, #C9004F 100%);
      --grad-brand: linear-gradient(120deg, #6A1BDB 0%, #C9004F 55%, #FF7A45 100%);
      --grad-band: linear-gradient(120deg, #4C1A9E 0%, #8F0038 55%, #B4361A 100%);
      --shadow-sm: 0 2px 10px rgba(92, 26, 63, .06);
      --shadow: 0 18px 50px rgba(92, 26, 63, .12);
      --radius: 22px;
      --wrap: 1120px;
      --gutter: 20px;
      color-scheme: light dark;
    }
    @media (prefers-color-scheme: dark) {
      :root {
        --bg: #161013; --surface: #1F171B; --soft: #241B20; --soft-2: #2F252A;
        --text: #F3E5EA; --muted: #CDBEC4; --border: #3F3338;
        --link: #FF8DB5; --success: #6FD8A6; --verified: #5AB0FF; --focus: #FF8DB5;
        --header: rgba(22, 16, 19, .88);
        --grad-text: linear-gradient(92deg, #C9A8FF 0%, #F59AD0 50%, #FF8DB5 100%);
        --shadow-sm: 0 2px 10px rgba(0, 0, 0, .3);
        --shadow: 0 18px 50px rgba(0, 0, 0, .45);
      }
    }
    *, *::before, *::after { box-sizing: border-box; }
    html { -webkit-text-size-adjust: 100%; text-size-adjust: 100%; }
    body {
      margin: 0; background: var(--bg); color: var(--text);
      font: 400 1rem/1.6 ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Noto Sans", "Helvetica Neue", Arial, sans-serif;
      -webkit-font-smoothing: antialiased; overflow-wrap: break-word;
    }
    img, svg { display: block; max-width: 100%; }
    img { height: auto; }
    a { color: var(--link); text-underline-offset: .18em; }
    a:hover { color: var(--primary-dark); }
    @media (prefers-color-scheme: dark) { a:hover { color: #FFB3CB; } }
    :focus-visible { outline: 3px solid var(--focus); outline-offset: 3px; border-radius: 6px; }
    h1, h2, h3 { margin: 0; line-height: 1.15; letter-spacing: -.02em; text-wrap: balance; }
    p { margin: 0; }
    ul, ol { margin: 0; padding: 0; list-style: none; }
    .wrap { width: 100%; max-width: calc(var(--wrap) + 2 * var(--gutter)); margin: 0 auto; padding: 0 var(--gutter); }
    .skip {
      position: absolute; left: 12px; top: -60px; z-index: 50; padding: 10px 16px; border-radius: 10px;
      background: var(--primary); color: #fff; font-weight: 700; text-decoration: none;
    }
    .skip:focus { top: 12px; color: #fff; }
    main:focus { outline: none; }
    .ico { width: 22px; height: 22px; fill: none; stroke: currentColor; stroke-width: 1.9; stroke-linecap: round; stroke-linejoin: round; flex: none; }
    .tick { width: 1em; height: 1em; flex: none; }
    .tick circle { fill: var(--verified); }
    .tick path { fill: none; stroke: #fff; stroke-width: 2.6; stroke-linecap: round; stroke-linejoin: round; }

    /* Header */
    .site-header {
      position: sticky; top: 0; z-index: 20; background: var(--header);
      border-bottom: 1px solid var(--border);
      -webkit-backdrop-filter: saturate(1.6) blur(14px); backdrop-filter: saturate(1.6) blur(14px);
    }
    .bar { display: flex; align-items: center; gap: 16px; min-height: 68px; }
    .brand { display: inline-flex; border-radius: 8px; }
    .logo { height: 36px; width: auto; }
    .nav { display: none; gap: 28px; margin-left: auto; }
    .nav a { color: var(--text); text-decoration: none; font-weight: 600; font-size: .95rem; }
    .nav a:hover { color: var(--link); }
    .bar .btn { margin-left: auto; }
    @media (min-width: 860px) {
      .nav { display: flex; }
      .bar .btn { margin-left: 12px; }
    }

    /* Buttons and the store badge */
    .btn {
      display: inline-flex; align-items: center; justify-content: center; gap: 8px;
      min-height: 44px; padding: 10px 20px; border-radius: 999px; border: 1.5px solid transparent;
      font-weight: 700; font-size: .95rem; line-height: 1.2; text-decoration: none; white-space: nowrap;
      transition: background-color .2s, color .2s, border-color .2s, transform .2s;
    }
    .btn-primary { background: var(--primary); color: #fff; }
    .btn-primary:hover { background: var(--primary-dark); color: #fff; }
    .btn-ghost { color: var(--text); border-color: var(--border); background: var(--surface); }
    .btn-ghost:hover { color: var(--link); border-color: currentColor; }
    .store {
      display: inline-flex; align-items: center; gap: 12px; min-height: 56px; margin: 0;
      padding: 9px 22px 9px 18px; border-radius: 14px; border: 1px solid #A6A6A6;
      background: #000; color: #fff; text-decoration: none; transition: transform .2s, box-shadow .2s;
    }
    a.store:hover { color: #fff; box-shadow: var(--shadow); }
    .store-ico { width: 26px; height: 26px; fill: currentColor; }
    .store-text { display: flex; flex-direction: column; line-height: 1.1; }
    .store-text small { font-size: .7rem; letter-spacing: .06em; text-transform: uppercase; }
    .store-text strong { font-size: 1.3rem; font-weight: 600; letter-spacing: -.01em; }
    .store--soon { background: transparent; color: var(--text); border: 1.5px dashed var(--muted); }
    .store--band.store--soon { color: #fff; border-color: rgba(255, 255, 255, .75); }

    /* Hero */
    .hero {
      position: relative; overflow: hidden; overflow: clip;
      background:
        radial-gradient(52% 60% at 92% 8%, rgba(229, 36, 110, .14), transparent 70%),
        radial-gradient(44% 50% at 4% 0%, rgba(106, 27, 219, .12), transparent 70%),
        radial-gradient(40% 40% at 70% 100%, rgba(255, 122, 69, .10), transparent 70%);
    }
    .hero-grid { display: grid; gap: 48px; align-items: center; padding-top: 56px; padding-bottom: 72px; }
    .eyebrow {
      display: inline-flex; align-items: center; gap: 10px; padding: 6px 14px 6px 10px; border-radius: 999px;
      background: var(--surface); border: 1px solid var(--border); box-shadow: var(--shadow-sm);
      font-size: .85rem; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: var(--text);
    }
    .eyebrow::before { content: ""; width: 10px; height: 10px; border-radius: 50%; background: var(--grad-brand); }
    .hero h1 { margin-top: 20px; font-size: clamp(2.15rem, 1.25rem + 3.6vw, 3.75rem); font-weight: 800; line-height: 1.06; }
    .grad { background: var(--grad-text); -webkit-background-clip: text; background-clip: text; color: transparent; }
    .lead { margin-top: 20px; font-size: clamp(1.05rem, .98rem + .35vw, 1.25rem); color: var(--muted); max-width: 36em; }
    .cta-row { display: flex; flex-wrap: wrap; align-items: center; gap: 14px 16px; margin-top: 32px; }
    .points { display: flex; flex-wrap: wrap; gap: 10px 22px; margin-top: 32px; color: var(--text); font-weight: 600; font-size: .95rem; }
    .points li { display: inline-flex; align-items: center; gap: 8px; }
    .points .ico { width: 20px; height: 20px; color: var(--primary); }
    @media (prefers-color-scheme: dark) { .points .ico { color: var(--link); } }
    @media (min-width: 940px) {
      .hero-grid { grid-template-columns: minmax(0, 1.15fr) minmax(0, .85fr); padding-top: 80px; padding-bottom: 104px; }
    }

    /* Hero illustration: a booking in the app, drawn in CSS */
    .art { position: relative; width: 100%; max-width: 400px; margin: 0 auto; padding: 8px 18px; }
    .phone {
      width: min(100%, 272px); margin: 0 auto; padding: 11px; border-radius: 44px;
      background: #211A1D; box-shadow: var(--shadow), inset 0 0 0 2px #3A2F35;
    }
    .screen { border-radius: 34px; background: var(--bg); padding: 16px 14px 18px; overflow: hidden; }
    .notch { width: 84px; height: 22px; margin: -4px auto 14px; border-radius: 999px; background: #211A1D; }
    @media (prefers-color-scheme: dark) {
      .phone { background: #3A2F35; box-shadow: var(--shadow), inset 0 0 0 2px #4D3F45; }
      .notch { background: #3A2F35; }
    }
    .s-title { font-weight: 800; font-size: 1.05rem; }
    .s-search {
      display: flex; align-items: center; gap: 8px; margin-top: 10px; padding: 9px 12px; border-radius: 14px;
      background: var(--surface); border: 1px solid var(--border); color: var(--muted); font-size: .8rem;
    }
    .s-search .ico { width: 16px; height: 16px; }
    .s-card { margin-top: 14px; border-radius: 20px; background: var(--surface); border: 1px solid var(--border); overflow: hidden; }
    .s-cover { height: 64px; background: var(--grad-brand); }
    .s-who { position: relative; display: flex; align-items: flex-start; gap: 10px; padding: 0 12px; }
    .s-who > span:last-child { padding-top: 6px; }
    .s-avatar {
      flex: none; margin-top: -24px;
      display: grid; place-items: center; width: 54px; height: 54px; border-radius: 50%;
      border: 3px solid var(--surface); background: #FFE0EA; color: #8F0038; font-weight: 800; font-size: 1.05rem;
    }
    .s-name { display: flex; align-items: center; gap: 5px; font-weight: 800; font-size: .95rem; }
    .s-meta { display: block; color: var(--muted); font-size: .75rem; }
    .s-stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px; margin: 12px 12px 0; text-align: center; }
    .s-stats span { padding: 7px 4px; border-radius: 12px; background: var(--soft); font-size: .66rem; color: var(--muted); }
    .s-stats b { display: block; color: var(--text); font-size: .85rem; }
    .s-pack {
      display: flex; justify-content: space-between; gap: 8px; margin: 10px 12px 0; padding: 10px 12px;
      border-radius: 12px; border: 1px dashed var(--border); font-size: .78rem;
    }
    .s-btn { display: block; margin: 12px; padding: 10px; border-radius: 999px; background: var(--primary); color: #fff; text-align: center; font-weight: 700; font-size: .85rem; }
    .chip {
      position: absolute; display: flex; align-items: center; gap: 8px; padding: 10px 14px; border-radius: 16px;
      background: var(--surface); border: 1px solid var(--border); box-shadow: var(--shadow);
      font-size: .85rem; font-weight: 700; white-space: nowrap;
    }
    .chip .ico { width: 18px; height: 18px; color: var(--success); }
    .chip-escrow { left: 0; top: 30%; }
    .chip-kyc { right: 0; top: 13%; }
    @media (prefers-reduced-motion: no-preference) {
      html { scroll-behavior: smooth; }
      .chip { animation: drift 6s ease-in-out infinite alternate; }
      .chip-kyc { animation-delay: -3s; }
      @keyframes drift { from { transform: translateY(-5px); } to { transform: translateY(5px); } }
      .btn:hover, a.store:hover { transform: translateY(-1px); }
    }

    /* Sections */
    .section { padding: clamp(56px, 7vw, 96px) 0; }
    .section.flush { padding-top: 0; }
    [id] { scroll-margin-top: 84px; }
    .section-head { max-width: 680px; margin: 0 auto 48px; text-align: center; }
    .kicker { color: var(--link); font-weight: 800; font-size: .85rem; letter-spacing: .08em; text-transform: uppercase; }
    .section h2 { margin-top: 10px; font-size: clamp(1.75rem, 1.3rem + 1.9vw, 2.6rem); font-weight: 800; }
    .section-head p:last-child { margin-top: 14px; color: var(--muted); font-size: 1.08rem; }

    .sides { display: grid; gap: 24px; }
    @media (min-width: 820px) { .sides { grid-template-columns: 1fr 1fr; } }
    .card {
      background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius);
      padding: clamp(24px, 3vw, 36px); box-shadow: var(--shadow-sm);
    }
    .card-icon {
      display: grid; place-items: center; width: 52px; height: 52px; border-radius: 16px;
      background: var(--soft-2); color: var(--primary);
    }
    @media (prefers-color-scheme: dark) { .card-icon { color: var(--link); } }
    .card-icon .ico { width: 26px; height: 26px; }
    .card h3 { margin-top: 20px; font-size: 1.5rem; font-weight: 800; }
    .card > p { margin-top: 8px; color: var(--muted); }
    .checks { display: grid; gap: 12px; margin-top: 22px; }
    .checks li { display: flex; gap: 12px; align-items: flex-start; }
    .checks .ico { margin-top: 1px; width: 22px; height: 22px; padding: 3px; border-radius: 50%; color: #fff; background: var(--success); stroke-width: 3; }
    @media (prefers-color-scheme: dark) { .checks .ico { color: #003822; } }

    .steps { display: grid; gap: 20px; counter-reset: step; }
    @media (min-width: 860px) { .steps { grid-template-columns: repeat(3, 1fr); } }
    .step { position: relative; padding: 28px; border-radius: var(--radius); background: var(--surface); border: 1px solid var(--border); }
    .step::before {
      counter-increment: step; content: counter(step);
      display: grid; place-items: center; width: 44px; height: 44px; border-radius: 14px;
      background: linear-gradient(135deg, #6A1BDB, #C9004F); color: #fff; font-weight: 800; font-size: 1.15rem;
    }
    .step h3 { margin-top: 18px; font-size: 1.2rem; font-weight: 800; }
    .step p { margin-top: 8px; color: var(--muted); }

    .trust { background: var(--soft); border-top: 1px solid var(--border); border-bottom: 1px solid var(--border); }
    .trust-grid { display: grid; gap: 20px; }
    @media (min-width: 860px) { .trust-grid { grid-template-columns: repeat(3, 1fr); } }
    .trust .card-icon { background: var(--soft-2); }
    .trust .card h3 { font-size: 1.2rem; display: flex; align-items: center; gap: 8px; }
    .card-icon.icon-verified { color: var(--verified); }

    .faq { max-width: 780px; margin: 0 auto; display: grid; gap: 12px; }
    .faq details { border: 1px solid var(--border); border-radius: 18px; background: var(--surface); }
    .faq summary {
      display: flex; justify-content: space-between; align-items: center; gap: 16px;
      padding: 18px 22px; cursor: pointer; list-style: none; font-weight: 700; font-size: 1.05rem; border-radius: 18px;
    }
    .faq summary::-webkit-details-marker { display: none; }
    .faq summary::after {
      content: ""; flex: none; width: 10px; height: 10px; margin-right: 4px;
      border-right: 2.5px solid var(--link); border-bottom: 2.5px solid var(--link); transform: rotate(45deg) translateY(-3px);
      transition: transform .2s;
    }
    .faq details[open] summary::after { transform: rotate(225deg) translateY(-3px); }
    .faq details p { padding: 0 22px 20px; color: var(--muted); }

    .band { background: var(--grad-band); color: #fff; text-align: center; }
    .band h2 { color: #fff; }
    .band p { color: rgba(255, 255, 255, .92); }
    .band .section-head { margin-bottom: 32px; }
    .band .section-head p:last-child { color: rgba(255, 255, 255, .92); }
    .band a:not(.store) { color: #fff; }
    .band :focus-visible { outline-color: #fff; }
    .band-cta { display: flex; justify-content: center; }
    .band-note { margin-top: 24px; font-size: .95rem; }

    /* Footer */
    .site-footer { background: var(--surface); border-top: 1px solid var(--border); padding: 56px 0 32px; font-size: .95rem; }
    .foot-grid { display: grid; gap: 32px; }
    @media (min-width: 760px) { .foot-grid { grid-template-columns: 1.4fr 1fr 1fr; } }
    .foot-brand p { margin-top: 12px; color: var(--muted); }
    .site-footer h2 { font-size: .8rem; letter-spacing: .08em; text-transform: uppercase; color: var(--muted); }
    .foot-links { display: grid; gap: 8px; margin-top: 14px; }
    .foot-links a { color: var(--text); text-decoration: none; }
    .foot-links a:hover { color: var(--link); text-decoration: underline; }
    .foot-base { margin-top: 40px; padding-top: 24px; border-top: 1px solid var(--border); color: var(--muted); font-size: .9rem; }

    @media (max-width: 420px) {
      :root { --gutter: 16px; }
      .logo { height: 30px; }
      .bar { min-height: 60px; gap: 10px; }
      .bar .btn { padding: 8px 14px; font-size: .88rem; }
      .chip { font-size: .78rem; padding: 8px 11px; }
    }
    @media (forced-colors: active) {
      .grad { color: CanvasText; background: none; }
      .store, .btn, .chip, .card, .step { border: 1px solid CanvasText; }
    }
  </style>
</head>
<body>
  <a class="skip" href="#main">Skip to content</a>

  <header class="site-header">
    <div class="wrap bar">
      <a class="brand" href="/"><?= $logoImg() ?></a>
      <nav class="nav" aria-label="Sections">
        <a href="#brands">For brands</a>
        <a href="#creators">For creators</a>
        <a href="#how">How it works</a>
        <a href="#faq">FAQ</a>
      </nav>
      <a class="btn btn-primary" href="<?= $storeUrl !== '' ? $e($storeUrl) : '#download' ?>">Download the app</a>
    </div>
  </header>

  <main id="main" tabindex="-1">
    <section class="hero" aria-labelledby="hero-title">
      <div class="wrap hero-grid">
        <div>
          <p class="eyebrow">Influencer Marketplace</p>
          <h1 id="hero-title">Brands book <span class="grad">verified creators</span>. Creators get paid securely.</h1>
          <p class="lead"><?= $e($description) ?></p>
          <div class="cta-row">
            <?= $store() ?>
            <a class="btn btn-ghost" href="#how">See how it works</a>
          </div>
          <ul class="points" role="list">
            <li><?= $icon('shield') ?>KYC-verified creators</li>
            <li><?= $icon('lock') ?>Payments held in escrow</li>
            <li><?= $icon('receipt') ?>Prices shown up front</li>
          </ul>
        </div>

        <div class="art" aria-hidden="true">
          <div class="phone">
            <div class="screen">
              <div class="notch"></div>
              <p class="s-title">Find creators</p>
              <div class="s-search"><?= $icon('search') ?>Beauty creators in Mumbai</div>
              <div class="s-card">
                <div class="s-cover"></div>
                <div class="s-who">
                  <span class="s-avatar">AR</span>
                  <span>
                    <span class="s-name">Ananya R. <?= $tick ?></span>
                    <span class="s-meta">Beauty · Mumbai</span>
                  </span>
                </div>
                <div class="s-stats">
                  <span><b>48K</b>followers</span>
                  <span><b>6.1%</b>engagement</span>
                  <span><b>3 days</b>delivery</span>
                </div>
                <div class="s-pack"><span>1 Reel + 2 Stories</span><b>₹8,000</b></div>
                <span class="s-btn">Book now</span>
              </div>
            </div>
          </div>
          <div class="chip chip-kyc"><?= $tick ?>KYC verified</div>
          <div class="chip chip-escrow"><?= $icon('lock') ?>₹8,000 held in escrow</div>
        </div>
      </div>
    </section>

    <section class="section" aria-labelledby="sides-title">
      <div class="wrap">
        <div class="section-head">
          <p class="kicker">One app, both sides</p>
          <h2 id="sides-title">Built for both sides of the deal</h2>
          <p>Whether you are launching a product or growing an audience, <?= $e($appName) ?> handles discovery, bookings and payments in one place.</p>
        </div>
        <div class="sides">
          <article class="card" id="brands" aria-labelledby="brands-title">
            <span class="card-icon"><?= $icon('brand') ?></span>
            <h3 id="brands-title">For brands</h3>
            <p>Find the right voices for your product, and pay only for work you approve.</p>
            <ul class="checks" role="list">
              <li><?= $icon('check') ?><span>Discover creators by niche, city, audience size and budget.</span></li>
              <li><?= $icon('check') ?><span>Book a creator directly, or post a campaign brief and shortlist who applies.</span></li>
              <li><?= $icon('check') ?><span>Chat with creators and review deliverables in one place.</span></li>
              <li><?= $icon('check') ?><span>Your payment stays in escrow until you approve the work.</span></li>
              <li><?= $icon('check') ?><span>Download an invoice for every booking.</span></li>
            </ul>
          </article>
          <article class="card" id="creators" aria-labelledby="creators-title">
            <span class="card-icon"><?= $icon('creator') ?></span>
            <h3 id="creators-title">For creators</h3>
            <p>Turn your audience into paid work with brands that fit, on your terms.</p>
            <ul class="checks" role="list">
              <li><?= $icon('check') ?><span>Verify once and earn the blue tick brands look for.</span></li>
              <li><?= $icon('check') ?><span>Set your own packages and rates, then <?= $counterOffers ? 'accept, decline or counter each offer' : 'accept or decline each request' ?>.</span></li>
              <li><?= $icon('check') ?><span>Apply to campaign briefs from brands in your niche.</span></li>
              <li><?= $icon('check') ?><span>A booking is confirmed only once the brand's payment is held in escrow, so you never chase an invoice.</span></li>
              <li><?= $icon('check') ?><span>Withdraw your earnings to your bank account or UPI.</span></li>
            </ul>
          </article>
        </div>
      </div>
    </section>

    <section class="section flush" id="how" aria-labelledby="how-title">
      <div class="wrap">
        <div class="section-head">
          <p class="kicker">How it works</p>
          <h2 id="how-title">From first search to final payment</h2>
          <p>Three steps, with your money protected at every one of them.</p>
        </div>
        <ol class="steps" role="list">
          <li class="step">
            <h3>Find your creator</h3>
            <p>Browse verified profiles with rates, past work and reviews up front, or post a brief and let creators come to you.</p>
          </li>
          <li class="step">
            <h3>Book and pay into escrow</h3>
            <p>Agree the deliverables and pay securely. The money is held in escrow: the creator knows it is there, and you know it is safe.</p>
          </li>
          <li class="step">
            <h3>Approve and release</h3>
            <p>The creator delivers. Approve the work and the payment is released to them, or ask for a revision first.</p>
          </li>
        </ol>
      </div>
    </section>

    <section class="section trust" id="trust" aria-labelledby="trust-title">
      <div class="wrap">
        <div class="section-head">
          <p class="kicker">Why <?= $e($appName) ?></p>
          <h2 id="trust-title">Trust is built in</h2>
          <p>Every booking is designed so both sides know exactly where they stand.</p>
        </div>
        <div class="trust-grid">
          <article class="card">
            <span class="card-icon icon-verified"><?= $icon('shield') ?></span>
            <h3>KYC-verified creators <?= $tick ?></h3>
            <p>Creators verify their identity with official documents, and our team reviews every application by hand. The blue tick appears only once that review is approved. Brands verify their business too.</p>
          </article>
          <article class="card">
            <span class="card-icon"><?= $icon('lock') ?></span>
            <h3>Escrow-backed payments</h3>
            <p>Brands pay to confirm a booking, and the money is held until the work is approved. If something goes wrong, it stays put until our team has reviewed the dispute.</p>
          </article>
          <article class="card">
            <span class="card-icon"><?= $icon('receipt') ?></span>
            <h3>Transparent pricing</h3>
            <p>Creators publish their rates. Before paying, brands see the creator's price<?= $feeLine ?>. No hidden charges.</p>
          </article>
        </div>
      </div>
    </section>

    <section class="section" id="faq" aria-labelledby="faq-title">
      <div class="wrap">
        <div class="section-head">
          <p class="kicker">FAQ</p>
          <h2 id="faq-title">Questions, answered</h2>
        </div>
        <div class="faq">
          <details>
            <summary>Is it free to join <?= $e($appName) ?>?</summary>
            <p>Yes. Signing up is free for brands and creators.<?= $chargesLine ?> You see the full breakdown before you confirm anything.</p>
          </details>
          <details>
            <summary>How are creators verified?</summary>
            <p>Creators submit identity documents through the app, and the <?= $e($appName) ?> team reviews each one by hand. Only an approved review earns the blue tick, and the tick can be withdrawn if a creator's verification lapses.</p>
          </details>
          <details>
            <summary>When do creators get paid?</summary>
            <p>As soon as the brand approves the delivered work, the payment is released from escrow to the creator's <?= $e($appName) ?> wallet, ready to withdraw to a bank account or UPI. If the brand does not respond within <?= $e($days) ?> of delivery, the booking completes automatically.</p>
          </details>
          <details>
            <summary>What if the work isn't what we agreed?</summary>
            <p>Ask the creator for a revision from the booking. If you still cannot agree, raise a dispute: the payment stays in escrow until our team has reviewed it and decided.</p>
          </details>
        </div>
      </div>
    </section>

    <section class="section band" id="download" aria-labelledby="download-title">
      <div class="wrap">
        <div class="section-head">
          <h2 id="download-title">Get <?= $e($appName) ?> on your phone</h2>
          <p><?= $tagline !== '' ? $e($tagline) . ' ' : '' ?>Join as a brand or a creator. It's free.</p>
        </div>
        <div class="band-cta"><?= $store('band') ?></div>
<?php if ($support !== ''): ?>
        <p class="band-note">Questions? Write to <a href="mailto:<?= $e($support) ?>"><?= $e($support) ?></a></p>
<?php endif; ?>
      </div>
    </section>
  </main>

  <footer class="site-footer">
    <div class="wrap">
      <div class="foot-grid">
        <div class="foot-brand">
          <a class="brand" href="/"><?= $logoImg(true) ?></a>
<?php if ($tagline !== ''): ?>
          <p><?= $e($tagline) ?></p>
<?php endif; ?>
<?php if ($support !== ''): ?>
          <p>Support: <a href="mailto:<?= $e($support) ?>"><?= $e($support) ?></a></p>
<?php endif; ?>
        </div>
        <nav aria-labelledby="foot-legal">
          <h2 id="foot-legal">Company &amp; legal</h2>
          <ul class="foot-links" role="list">
<?php foreach ($legalLinks as $href => $label): ?>
            <li><a href="<?= $e($href) ?>"><?= $e($label) ?></a></li>
<?php endforeach; ?>
          </ul>
        </nav>
<?php if ($social !== []): ?>
        <nav aria-labelledby="foot-social">
          <h2 id="foot-social">Follow us</h2>
          <ul class="foot-links" role="list">
<?php foreach ($social as $link): ?>
            <li><a href="<?= $e($link['url']) ?>" rel="me noopener"><?= $e($link['label']) ?></a></li>
<?php endforeach; ?>
          </ul>
        </nav>
<?php endif; ?>
      </div>
      <p class="foot-base">&copy; <?= $e($year) ?> <?= $e($company) ?></p>
    </div>
  </footer>
</body>
</html>
