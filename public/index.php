<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

$whatsappPhone = preg_replace('/\D+/', '', (string) (getenv('WHATSAPP_PHONE') ?: ''));
if (!preg_match('/^\d{8,15}$/', $whatsappPhone)) {
    $whatsappPhone = '';
}
$contactPhone = trim((string) (getenv('CONTACT_PHONE') ?: ''));
if (!preg_match('/^\+?[0-9()\-\s]{6,25}$/', $contactPhone)) {
    $contactPhone = '';
}
$contactPhoneHref = preg_replace('/[^0-9+]/', '', $contactPhone);
$contactEmail = trim((string) (getenv('CONTACT_EMAIL') ?: 'ekaram.developers@gmail.com'));
if (!filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
    $contactEmail = 'ekaram.developers@gmail.com';
}
$contactAddress = trim((string) (getenv('CONTACT_ADDRESS') ?: ''));
if (mb_strlen($contactAddress) > 500) {
    $contactAddress = '';
}

if (!$app['debug']) {
    ini_set('display_errors', '0');
}
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: SAMEORIGIN');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob:; style-src 'self' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; frame-src 'self' https://www.google.com; script-src 'self'; form-action 'self'; base-uri 'self'; frame-ancestors 'self'");

try {
    $content = PublicContentService::load();
} catch (Throwable $exception) {
    error_log('Public website unavailable: ' . $exception->getMessage());
    http_response_code(503);
    if ($app['debug']) {
        throw $exception;
    }
    exit('The website is temporarily unavailable. Please try again later.');
}

$project = $content['project'];
$projectName = $project['name'] ?: 'Quantum City @ Lam';
$projectLocation = $project['location'] ?: 'Lam Village, Behind Delhi Public School, Tadikonda (M) - 522034';
$brochurePath = (string) ($project['brochure_path'] ?? '');
$brochureUrl = $brochurePath !== ''
    ? '/media.php?file=' . rawurlencode($brochurePath)
    : '/assets/project-brochure.pdf';
$mapUrl = $project['map_embed_url'] !== ''
    ? $project['map_embed_url']
    : 'https://www.google.com/maps?q=Behind+Delhi+Public+School%2C+Lam+Village%2C+Tadikonda%2C+Andhra+Pradesh+522034&output=embed';
$mapDirectionsUrl = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($projectLocation);

$defaultLocations = [
    ['Delhi Public School', '1 KM'],
    ['Anantapur - Amaravathi Express Highway', '600 M'],
    ['Inner Ring Road (IRR)', '3 KM / 5 Min'],
    ['Outer Ring Road (ORR)', '15 Min Drive'],
    ['Tadikonda Railway Station', '4 KM / 5 Min'],
    ['Ravela International Airport', '7 KM / 5 Min'],
    ['AP Secretariat', '10 KM / 10 Min'],
    ['CM New House', '13.5 KM / 15 Min'],
    ['Guntur Bus Stand', '20 Min'],
    ['Guntur Railway Station', '15 Min'],
    ['VIT-AP University', '25 Min'],
    ['SRM University', '25 Min'],
    ['TTD Temple, Venkatayapalem', '30 Min'],
];
$defaultHighlights = [
    ['80 Ft, 40 Ft and 30 Ft Roads', 'Road widths listed in the supplied project brochure.'],
    ['Grand Entrance Arch', 'Entrance feature shown in the supplied project brochure.'],
    ['Avenue Plantation', 'Plantation feature listed in the supplied project brochure.'],
    ['Beautiful Parks', 'Park feature listed in the supplied project brochure.'],
    ["Children's Play Area", 'Play-area feature listed in the supplied project brochure.'],
    ['Walking Tracks', 'Walking-track feature listed in the supplied project brochure.'],
];
$defaultAmenities = [
    ['Wide internal roads', 'Road widths listed in the supplied brochure: 80 Ft, 40 Ft and 30 Ft.'],
    ['Grand entrance arch', 'Entrance feature shown in the supplied project brochure.'],
    ['Avenue plantation', 'Plantation feature listed in the supplied project brochure.'],
    ['Landscaped parks', 'Park feature listed in the supplied project brochure.'],
    ["Children's play area", 'Play-area feature listed in the supplied project brochure.'],
    ['Walking tracks', 'Walking-track feature listed in the supplied project brochure.'],
    ['Family-friendly open spaces', 'Open-space illustration shown in the supplied project brochure.'],
];

$storedLocations = [];
$storedHighlights = [];
$otherSpecifications = [];
foreach ($content['specifications'] as $specification) {
    $label = (string) $specification['label'];
    if (stripos($label, 'Location: ') === 0) {
        $storedLocations[] = [
            'name' => trim(substr($label, strlen('Location: '))),
            'distance' => (string) $specification['spec_value'],
        ];
    } elseif (stripos($label, 'Highlight: ') === 0) {
        $storedHighlights[] = [
            'name' => trim(substr($label, strlen('Highlight: '))),
            'description' => (string) $specification['spec_value'],
        ];
    } else {
        $otherSpecifications[] = [
            'name' => $label,
            'description' => (string) $specification['spec_value'],
        ];
    }
}
$locations = $storedLocations ?: array_map(
    static fn(array $item): array => ['name' => $item[0], 'distance' => $item[1]],
    $defaultLocations
);
$locationSortKey = static function (array $location): array {
    $value = (string) $location['distance'];
    if (preg_match('/(\d+(?:\.\d+)?)\s*(KM|M)\b/i', $value, $match)) {
        $distanceKm = (float) $match[1] * (strtoupper($match[2]) === 'M' ? 0.001 : 1);
        return [0, $distanceKm];
    }
    if (preg_match('/(\d+(?:\.\d+)?)\s*MIN\b/i', $value, $match)) {
        return [1, (float) $match[1]];
    }
    return [2, PHP_FLOAT_MAX];
};
usort($locations, static function (array $left, array $right) use ($locationSortKey): int {
    return $locationSortKey($left) <=> $locationSortKey($right);
});
$highlights = $storedHighlights ?: ($otherSpecifications ?: array_map(
    static fn(array $item): array => ['name' => $item[0], 'description' => $item[1]],
    $defaultHighlights
));
$amenities = $content['amenities'] ?: array_map(
    static fn(array $item): array => ['name' => $item[0], 'description' => $item[1], 'image_path' => null],
    $defaultAmenities
);

$amenityIcons = ['↔', '⌂', '♧', '✳', '▱', '↗', '◇'];
$highlightIcons = [
    '<svg viewBox="0 0 48 48" aria-hidden="true"><path d="M7 39 15 9h6l-4 30m11 0-4-30h6l11 30M10 28h28M13 18h22"/></svg>',
    '<svg viewBox="0 0 48 48" aria-hidden="true"><path d="M6 39V23a18 18 0 0 1 36 0v16M12 39V23a12 12 0 0 1 24 0v16M3 39h42"/></svg>',
    '<svg viewBox="0 0 48 48" aria-hidden="true"><path d="M24 42V20m0 11L12 20m12 5 12-13M8 16l8-8 8 8m8-1 8-8 8 8M18 42h12"/></svg>',
    '<svg viewBox="0 0 48 48" aria-hidden="true"><path d="M24 42V24m0 9-9-9m9 3 10-10M7 42h34M8 20h8v8H8zm20-8h8v8h-8zm-8 15h8v8h-8z"/></svg>',
    '<svg viewBox="0 0 48 48" aria-hidden="true"><path d="M8 40V18l16-10 16 10v22M18 40V28h12v12M6 20l18-12 18 12"/></svg>',
    '<svg viewBox="0 0 48 48" aria-hidden="true"><circle cx="27" cy="9" r="4"/><path d="m23 18-7 8 8 4 5-7 7 5m-16-2-5 12m9-6 8 11M11 42h27"/></svg>',
];
$brochureGallery = [
    ['src' => '/assets/images/entrance-concept.jpg', 'alt' => 'Illustrative entrance rendering from the supplied brochure; not a photograph of completed facilities.', 'caption' => 'Entrance concept shown in the brochure'],
    ['src' => '/assets/images/family-lifestyle-reference.jpg', 'alt' => 'Family lifestyle reference image used in the supplied brochure.', 'caption' => 'Family lifestyle reference from the brochure'],
    ['src' => '/assets/images/brochure-page-3.jpg', 'alt' => 'Brochure route map labelled not to scale.', 'caption' => 'Brochure route map · not to scale'],
    ['src' => '/assets/images/brochure-page-4.jpg', 'alt' => 'Illustrative residential lifestyle artwork from the supplied brochure.', 'caption' => 'Residential lifestyle artwork from the brochure'],
];
$gallery = $content['gallery'];
$heroImage = !empty($gallery[0]['image_path'])
    ? '/media.php?file=' . rawurlencode($gallery[0]['image_path'])
    : '/assets/images/entrance-concept.jpg';
$heroImageAlt = !empty($gallery[0]['image_path'])
    ? (string) $gallery[0]['alt_text']
    : 'Illustrative entrance rendering shown in the supplied brochure; not a photograph of a completed project.';
$siteDescription = 'A premium residential plot development at Lam Village, Tadikonda, proudly presented by Merit Real Solutions and EK Aram Developers.';
$canonicalUrl = $app['url'] !== '' ? $app['url'] . '/' : '';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="<?= Security::escape($siteDescription) ?>">
  <meta name="keywords" content="residential plots Tadikonda, plots near Guntur, Lam Village plots, EK Aram Developers, Merit Real Solutions, Amaravati">
  <meta property="og:type" content="website">
  <meta property="og:title" content="<?= Security::escape($projectName) ?> | Merit Real Solutions &amp; EK Aram Developers">
  <meta property="og:description" content="<?= Security::escape($siteDescription) ?>">
  <?php if ($canonicalUrl !== ''): ?><link rel="canonical" href="<?= Security::escape($canonicalUrl) ?>"><meta property="og:url" content="<?= Security::escape($canonicalUrl) ?>"><meta property="og:image" content="<?= Security::escape($app['url'] . '/assets/images/entrance-concept.jpg') ?>"><?php endif; ?>
  <title><?= Security::escape($projectName) ?> | Merit Real Solutions &amp; EK Aram Developers</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
  <header class="site-header" id="top">
    <div class="container nav-wrap">
      <a class="brand-pair" href="#top" aria-label="Merit Real Solutions and EK Aram Developers home">
        <span class="brand-wordmark">MERIT<small>REAL SOLUTIONS</small></span>
        <span class="brand-ampersand" aria-hidden="true">&amp;</span>
        <span class="brand-wordmark">EK ARAM<small>DEVELOPERS</small></span>
      </a>
      <button class="nav-toggle" type="button" aria-expanded="false" aria-controls="primary-nav" aria-label="Open navigation">
        <span></span><span></span><span></span>
      </button>
      <nav class="primary-nav" id="primary-nav" aria-label="Primary navigation">
        <a href="#top">Home</a>
        <a href="#about">About Project</a>
        <a href="#location">Location</a>
        <a href="#amenities">Amenities</a>
        <a href="#plots">Plot Layout</a>
        <a href="#gallery">Gallery</a>
        <a href="#contact">Contact</a>
        <a class="mobile-associate-signup" href="/marketing-associate/signup.php">Marketing Associate Sign Up</a>
      </nav>
      <details class="associate-menu">
        <summary>Marketing Associate</summary>
        <div class="associate-menu-options">
          <a href="/marketing-associate/login.php">Login</a>
          <a href="/marketing-associate/signup.php">Sign Up</a>
        </div>
      </details>
      <a class="associate-login" href="/marketing-associate/login.php">Login</a>
      <a class="nav-cta" href="#site-visit">Book Site Visit</a>
    </div>
  </header>

  <main>
    <section class="hero brochure-hero" aria-labelledby="hero-title">
      <div class="hero-media">
        <img src="<?= Security::escape($heroImage) ?>" alt="<?= Security::escape($heroImageAlt) ?>" fetchpriority="high" width="800" height="760">
      </div>
      <div class="hero-shade"></div>
      <div class="container hero-content">
        <p class="eyebrow">A new destination for a better tomorrow</p>
        <p class="hero-collaboration">Proudly presented by <strong>Merit Real Solutions</strong> &amp; <strong>EK Aram Developers</strong></p>
        <h1 id="hero-title">LOCATION CONNECTED TO TOMORROW</h1>
        <p class="hero-subtitle">A Premium Residential Plot Development</p>
        <p class="hero-description">Experience a better lifestyle with excellent connectivity, thoughtfully planned infrastructure and family-friendly amenities.</p>
        <div class="hero-actions">
          <a class="button button-light" href="#about">Explore Project</a>
          <a class="button button-outline" href="#plots">View Plot Layout</a>
          <a class="button button-gold" href="#site-visit">Book a Site Visit</a>
          <a class="button button-outline" href="<?= Security::escape($brochureUrl) ?>" <?= $brochurePath !== '' ? '' : 'download' ?>>Download Brochure</a>
        </div>
      </div>
      <div class="hero-note">Illustrative project artwork from the supplied brochure</div>
    </section>

    <section class="intro section" id="about">
      <div class="container intro-grid">
        <div>
          <p class="section-kicker">Merit Real Solutions &amp; EK Aram Developers</p>
          <h2><?= Security::escape($projectName) ?></h2>
        </div>
        <div class="intro-copy">
          <p><?= Security::escape($project['description'] ?: 'A Premium Residential Plot Development, proudly presented by Merit Real Solutions & Ek aram Developers.') ?></p>
          <dl class="spec-list">
            <div><dt>Venture address</dt><dd><?= Security::escape($projectLocation) ?></dd></div>
            <div><dt>Development</dt><dd>Residential plots</dd></div>
            <div><dt>Inventory</dt><dd><?= (int) $project['plot_count'] > 0 ? (int) $project['plot_count'] . ' plots listed' : 'Plot details will be published when confirmed' ?></dd></div>
          </dl>
          <p class="brochure-disclaimer">Distances and features on this page are brochure-provided information. Confirm current project status and details with the project team.</p>
        </div>
      </div>
    </section>

    <section class="location section" id="location">
      <div class="container">
        <div class="section-heading">
          <div><p class="section-kicker">Connectivity from the supplied brochure</p><h2>WORLD-CLASS LOCATION &amp; CONNECTIVITY</h2></div>
        </div>
        <p class="road-order-note">Brochure distances are ordered nearest to farthest. Destinations with travel-time-only details follow in ascending time order. Scroll along the road on desktop; spacing is illustrative, not to scale.</p>
        <div class="road-scroll" tabindex="0" role="region" aria-label="Connectivity road milestones, ordered outward from the project">
          <div class="road-journey">
            <div class="road-surface" aria-hidden="true"><span></span></div>
            <ol class="road-stops">
              <li class="road-stop road-stop--project">
                <article class="road-sign">
                  <span class="road-sign-label">Project start</span>
                  <h3><?= Security::escape($projectName) ?></h3>
                  <p><?= Security::escape($projectLocation) ?></p>
                </article>
                <span class="road-marker" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 21s7-6.1 7-12a7 7 0 1 0-14 0c0 5.9 7 12 7 12Z"/><circle cx="12" cy="9" r="2.4"/></svg></span>
              </li>
              <?php foreach ($locations as $index => $location): ?>
                <li class="road-stop <?= $index % 2 === 0 ? 'road-stop--top' : 'road-stop--bottom' ?>">
                  <article class="road-sign">
                    <span class="road-sign-label">Milestone <?= str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) ?></span>
                    <h3><?= Security::escape($location['name']) ?></h3>
                    <p class="road-distance"><?= Security::escape($location['distance']) ?></p>
                  </article>
                  <span class="road-marker" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 21s7-6.1 7-12a7 7 0 1 0-14 0c0 5.9 7 12 7 12Z"/><circle cx="12" cy="9" r="2.4"/></svg></span>
                </li>
              <?php endforeach; ?>
            </ol>
          </div>
        </div>
        <div class="map-section">
          <div class="map-card">
            <iframe title="Google Maps search for the brochure-listed project address" loading="lazy" referrerpolicy="no-referrer-when-downgrade" src="<?= Security::escape($mapUrl) ?>"></iframe>
          </div>
          <div class="map-caption">
            <p class="section-kicker">Venture address in brochure</p>
            <h3><?= Security::escape($projectLocation) ?></h3>
            <p>The map is based on the brochure-listed address. The brochure route map is marked “Not to Scale”; no precise plot pin was supplied.</p>
            <a class="text-link" href="<?= Security::escape($mapDirectionsUrl) ?>" target="_blank" rel="noopener noreferrer">Open address in Google Maps</a>
          </div>
        </div>
      </div>
    </section>

    <section class="highlights section" id="highlights">
      <div class="container">
        <div class="section-heading">
          <div><p class="section-kicker">Brochure-listed features</p><h2>PREMIUM PROJECT HIGHLIGHTS</h2></div>
        </div>
        <div class="highlight-grid premium-highlight-grid">
          <?php foreach ($highlights as $index => $highlight): ?>
            <article class="highlight-card">
              <span class="feature-icon"><?= $highlightIcons[$index % count($highlightIcons)] ?></span>
              <h3><?= Security::escape($highlight['name']) ?></h3>
              <p><?= Security::escape($highlight['description']) ?></p>
            </article>
          <?php endforeach; ?>
          <article class="highlight-card family-highlight">
            <span class="feature-icon" aria-hidden="true">♡</span>
            <h3>A Healthy Happy Future for Your Family</h3>
            <p>A family-oriented aspiration featured in the supplied brochure.</p>
          </article>
        </div>
      </div>
    </section>

    <section class="amenities section" id="amenities">
      <div class="container">
        <div class="section-heading">
          <div><p class="section-kicker">Features shown or listed in the brochure</p><h2>DESIGNED FOR A BETTER LIFESTYLE</h2></div>
          <a class="button button-light" href="/amenities.php">View all amenities</a>
        </div>
        <div class="lifestyle-grid">
          <?php foreach ($amenities as $index => $amenity): ?>
            <article class="lifestyle-card">
              <?php if (!empty($amenity['image_path'])): ?>
                <img src="/media.php?file=<?= rawurlencode($amenity['image_path']) ?>" alt="<?= Security::escape($amenity['name']) ?>" loading="lazy">
              <?php else: ?>
                <span class="lifestyle-symbol" aria-hidden="true"><?= Security::escape($amenityIcons[$index % count($amenityIcons)]) ?></span>
              <?php endif; ?>
              <div><span class="card-index"><?= str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) ?></span><h3><?= Security::escape($amenity['name']) ?></h3><p><?= Security::escape((string) ($amenity['description'] ?? '')) ?></p></div>
            </article>
          <?php endforeach; ?>
        </div>
        <p class="brochure-disclaimer">Brochure-listed features are not represented as completed facilities. Please confirm execution status with the project team.</p>
      </div>
    </section>

    <section class="family-section section">
      <div class="container family-layout">
        <div class="family-image">
          <img src="/assets/images/family-lifestyle-reference.jpg" alt="Family lifestyle reference image in the supplied project brochure; illustrative, not a photograph of project residents." width="640" height="500" loading="lazy">
          <span>Family lifestyle reference · supplied brochure</span>
        </div>
        <div class="family-copy">
          <p class="section-kicker">Space to imagine your next chapter</p>
          <h2>A HEALTHY AND HAPPY FUTURE FOR YOUR FAMILY</h2>
          <p>A thoughtfully planned residential community where open spaces, greenery, convenient connectivity and family-friendly surroundings come together to create a better living experience.</p>
          <p class="brochure-disclaimer">Lifestyle image is illustrative and does not depict completed project facilities.</p>
          <a class="button button-dark" href="#site-visit">Experience the project</a>
        </div>
      </div>
    </section>

    <section class="plots section" id="plots">
      <div class="container">
        <div class="section-heading">
          <div><p class="section-kicker">Live plot inventory</p><h2>EXPLORE YOUR FUTURE PLOT</h2><p class="section-intro">Plot prices are calculated from area and the current rate of <?= $project['price_per_sqyd'] !== null ? '₹' . Security::escape(Money::formatIndian((string) $project['price_per_sqyd'])) : 'an admin-set rate' ?> per square yard. Select a plot for complete details.</p></div>
          <a class="button button-dark" href="#contact">Enquire about a plot</a>
        </div>
        <?php if ($content['plots']): ?>
          <div class="plot-map-actions">
            <div class="plot-statuses plot-statuses-expanded" aria-label="Plot status legend">
              <?php foreach (PlotStatus::LABELS as $statusClass => $statusLabel): ?>
                <span><i class="status-<?= Security::escape($statusClass) ?>"></i><?= Security::escape($statusLabel) ?></span>
              <?php endforeach; ?>
            </div>
            <div class="plot-zoom-controls" role="group" aria-label="Plot map zoom controls">
              <span>Zoom</span>
              <button type="button" data-plot-zoom="out" aria-label="Zoom out">−</button>
              <output data-plot-zoom-level aria-live="polite">100%</output>
              <button type="button" data-plot-zoom="in" aria-label="Zoom in">+</button>
              <button type="button" data-plot-zoom="reset">Reset</button>
            </div>
          </div>
          <p class="plot-map-instructions">View the supplied site layout below. The interactive plot cards are a separate numbered inventory; choose one to view its full area and price.</p>
        <?php endif; ?>
        <?php if ($content['plots']): ?>
          <div class="plot-site-plan">
            <div>
              <p class="section-kicker">Supplied project layout</p>
              <h3>Quantum City @ Lam Site Plan</h3>
              <p>Agreement plots are marked in the interactive inventory below.</p>
            </div>
            <a class="button button-outline-dark" href="/assets/project-layout-plan.pdf" target="_blank" rel="noopener">Open full layout</a>
          </div>
          <div class="plot-site-plan-view">
            <iframe src="/assets/project-layout-plan.pdf#toolbar=0&amp;navpanes=0&amp;view=FitH" title="Quantum City @ Lam supplied layout plan" loading="lazy">
              <p>Your browser cannot display the layout inline. <a href="/assets/project-layout-plan.pdf">Open the layout plan</a>.</p>
            </iframe>
          </div>
          <div class="plot-filter-controls" role="search" aria-label="Search plot inventory">
            <label for="plot-search">Search by plot, area, price or facing
              <input id="plot-search" type="search" data-plot-search placeholder="Example: 84, 183.33 or 40,33,260">
            </label>
            <label for="plot-facing-filter">Facing direction
              <select id="plot-facing-filter" data-facing-filter>
                <option value="">All directions</option>
                <?php foreach (PlotFacing::DIRECTIONS as $direction): ?>
                  <option value="<?= Security::escape($direction) ?>"><?= Security::escape($direction) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label for="plot-status-filter">Plot status
              <select id="plot-status-filter" data-status-filter>
                <option value="">All statuses</option>
                <?php foreach (PlotStatus::LABELS as $status => $label): ?>
                  <option value="<?= Security::escape($status) ?>"><?= Security::escape($label) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <output class="plot-filter-count" data-plot-filter-count aria-live="polite"><?= count($content['plots']) ?> of <?= count($content['plots']) ?> plots</output>
            <button type="button" class="plot-filter-reset" data-plot-filter-reset>Clear filters</button>
          </div>
          <p class="plot-filter-empty" data-plot-filter-empty hidden>No plots match these search filters.</p>
          <div class="plot-map-wrap">
            <div class="plot-board" aria-label="Interactive database-backed plot layout" data-plot-board>
              <?php foreach ($content['plots'] as $plot): ?>
                <?php
                  $plotStatus = PlotStatus::isValid($plot['status']) ? $plot['status'] : 'blocked';
                  $plotStatusLabel = PlotStatus::LABELS[$plotStatus];
                  $plotArea = $plot['area_sqyd'] !== null ? number_format((float) $plot['area_sqyd'], 2, '.', ',') . ' Sq. Yds.' : 'Area Not Specified';
                  $plotFacing = (string) ($plot['facing_direction'] ?? '');
                  $plotFacingLabel = $plotFacing !== '' ? $plotFacing . ' Facing' : 'Facing Not Specified';
                  $plotPrice = $plot['total_price'] !== null ? '₹' . Money::formatIndian((string) $plot['total_price']) : 'Price Not Available';
                  $plotRate = $plot['price_per_sqyd'] !== null ? '₹' . Money::formatIndian((string) $plot['price_per_sqyd']) : 'Rate Not Set';
                  $priceSearch = $plot['total_price'] !== null
                      ? rtrim(rtrim(number_format((float) $plot['total_price'], 2, '.', ''), '0'), '.')
                      : '';
                  $plotSearch = implode(' ', array_filter([
                      (string) $plot['plot_number'],
                      $plotArea,
                      $plot['area_sqyd'] !== null ? number_format((float) $plot['area_sqyd'], 2, '.', '') : '',
                      $plotPrice,
                      $priceSearch,
                      $plotFacing,
                      $plotStatusLabel,
                  ], static fn(string $value): bool => $value !== ''));
                  $plotLabel = 'Plot ' . $plot['plot_number'] . ', ' . $plotStatusLabel . ', ' . $plotArea . ', ' . $plotFacingLabel . ', ' . $plotPrice;
                ?>
                <button type="button" class="plot plot-<?= Security::escape($plotStatus) ?>" data-plot="<?= Security::escape((string) $plot['plot_number']) ?>" data-search="<?= Security::escape($plotSearch) ?>" data-facing="<?= Security::escape($plotFacing) ?>" data-status="<?= Security::escape($plotStatus) ?>" data-area="<?= Security::escape($plotArea) ?>" data-facing-label="<?= Security::escape($plotFacingLabel) ?>" data-rate="<?= Security::escape($plotRate) ?>" data-price="<?= Security::escape($plotPrice) ?>" data-status-label="<?= Security::escape($plotStatusLabel) ?>" aria-label="<?= Security::escape($plotLabel) ?>">
                  <strong>Plot <?= Security::escape((string) $plot['plot_number']) ?></strong>
                  <small class="plot-status"><?= Security::escape($plotStatusLabel) ?></small>
                  <span class="plot-area"><?= Security::escape($plotArea) ?></span>
                  <span class="plot-facing"><?= Security::escape($plotFacingLabel) ?></span>
                  <span class="plot-price"><?= Security::escape($plotPrice) ?></span>
                </button>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="available-plots">
            <h3>Available plots</h3>
            <?php $availablePlots = array_filter($content['plots'], static fn(array $plot): bool => $plot['status'] === 'available'); ?>
            <?php if ($availablePlots): ?>
              <ul>
                <?php foreach ($availablePlots as $plot): ?>
                  <li><span>Plot <?= Security::escape((string) $plot['plot_number']) ?></span><span><?= $plot['area_sqyd'] !== null ? Security::escape(number_format((float) $plot['area_sqyd'], 2, '.', ',')) . ' Sq. Yds.' : 'Area Not Specified' ?></span><span><?= $plot['facing_direction'] !== null ? Security::escape($plot['facing_direction']) . ' Facing' : 'Facing Not Specified' ?></span><strong><?= $plot['total_price'] !== null ? '₹' . Security::escape(Money::formatIndian((string) $plot['total_price'])) : 'Price Not Available' ?></strong><a href="#contact" class="plot-enquiry" data-enquire-plot="<?= Security::escape((string) $plot['plot_number']) ?>">Enquire</a></li>
                <?php endforeach; ?>
              </ul>
            <?php else: ?>
              <p>No plots are currently marked available. Contact the project team for confirmed inventory.</p>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <div class="plot-empty"><strong>No plot inventory has been published yet.</strong><p>No sample plots are displayed. Please contact the project team for verified inventory and layout details.</p></div>
        <?php endif; ?>
        <?php if ($content['plots']): ?>
          <p class="plot-download"><a class="button button-outline-dark" href="/plot-prices.csv.php">Download all <?= count($content['plots']) ?> plot prices (CSV)</a></p>
          <dialog class="plot-detail-dialog" data-plot-dialog aria-labelledby="plot-dialog-title">
            <form method="dialog"><button class="plot-dialog-close" aria-label="Close plot details">×</button></form>
            <p class="section-kicker">Plot details</p>
            <h3 id="plot-dialog-title" data-plot-dialog-title></h3>
            <p class="plot-dialog-status" data-plot-dialog-status></p>
            <dl>
              <div><dt>Area</dt><dd data-plot-dialog-area></dd></div>
              <div><dt>Facing</dt><dd data-plot-dialog-facing></dd></div>
              <div><dt>Rate</dt><dd data-plot-dialog-rate></dd></div>
              <div><dt>Total price</dt><dd data-plot-dialog-price></dd></div>
            </dl>
            <a class="button button-dark" href="#contact" data-enquire-plot="">Enquire about this plot</a>
          </dialog>
        <?php endif; ?>
      </div>
    </section>

    <section class="gallery section" id="gallery">
      <div class="container">
        <div class="section-heading">
          <div><p class="section-kicker">Brochure and project imagery</p><h2>PROJECT GALLERY</h2></div>
        </div>
        <div class="gallery-grid brochure-gallery-grid">
          <?php if ($gallery): ?>
            <?php foreach ($gallery as $index => $image): ?>
              <figure class="<?= $index === 0 ? 'gallery-wide' : '' ?>">
                <img src="/media.php?file=<?= rawurlencode($image['image_path']) ?>" alt="<?= Security::escape($image['alt_text']) ?>" loading="lazy">
                <figcaption><?= Security::escape($image['alt_text']) ?></figcaption>
              </figure>
            <?php endforeach; ?>
          <?php else: ?>
            <?php foreach ($brochureGallery as $index => $image): ?>
              <figure class="<?= $index === 0 ? 'gallery-wide' : '' ?>">
                <img src="<?= Security::escape($image['src']) ?>" alt="<?= Security::escape($image['alt']) ?>" loading="lazy" <?= $index === 0 ? 'width="800" height="760"' : '' ?>>
                <figcaption><?= Security::escape($image['caption']) ?></figcaption>
              </figure>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
        <p class="brochure-disclaimer">Brochure illustrations and reference imagery are not photographs of completed facilities. Project photographs can be added by the administrator.</p>
      </div>
    </section>

    <section class="brochure-section section" id="brochure">
      <div class="container brochure-layout">
        <div class="brochure-preview">
          <img src="/assets/images/brochure-page-1.jpg" alt="Cover page of the supplied EK Aram Developers project brochure." width="1300" height="1654" loading="lazy">
          <span>Supplied project brochure</span>
        </div>
        <div class="brochure-copy">
          <p class="section-kicker">Project information</p>
          <h2>DISCOVER THE COMPLETE PROJECT</h2>
          <p>Review the supplied project brochure for the original location map and project information. The route map is identified in the brochure as not to scale.</p>
          <div class="hero-actions brochure-actions">
            <a class="button button-dark" href="<?= Security::escape($brochureUrl) ?>" <?= $brochurePath !== '' ? '' : 'download' ?>>Download Brochure</a>
            <a class="button button-outline-dark" href="#contact">Request More Information</a>
            <?php if ($whatsappPhone !== ''): ?>
              <a class="button button-whatsapp" href="https://wa.me/<?= Security::escape($whatsappPhone) ?>?text=<?= rawurlencode('Hello, I would like more information about the residential plot development at Tadikonda.') ?>" target="_blank" rel="noopener noreferrer">WhatsApp Enquiry</a>
            <?php else: ?>
              <a class="button button-whatsapp" href="#contact">Enquire with the project team</a>
            <?php endif; ?>
          </div>
          <p class="brochure-disclaimer">This is the original brochure supplied for this project; no replacement brochure has been generated.</p>
        </div>
      </div>
    </section>

    <?php if (!empty($project['id'])): ?>
    <section class="site-visit-section section" id="site-visit">
      <div class="container site-visit-layout">
        <div>
          <p class="section-kicker">Plan a visit</p>
          <h2>EXPERIENCE THE PROJECT IN PERSON</h2>
          <p>Send a visit request to the project team. Your preferred time is a request and will be confirmed by the team.</p>
          <p class="visit-address"><?= Security::escape($projectLocation) ?></p>
        </div>
        <form class="lead-form site-visit-form" data-lead-form method="post" action="/api/leads.php" id="site-visit">
          <input type="hidden" name="csrf_token" value="<?= Security::escape($content['csrf_token']) ?>">
          <input type="hidden" name="form_type" value="site_visit">
          <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
          <input type="text" name="website" class="honeypot" tabindex="-1" autocomplete="off" aria-hidden="true">
          <div class="field"><label for="visit-name">Customer name</label><input id="visit-name" name="name" autocomplete="name" maxlength="150" required></div>
          <div class="field"><label for="visit-phone">Contact number</label><input id="visit-phone" name="phone" autocomplete="tel" inputmode="tel" maxlength="25" required></div>
          <div class="field full"><label for="visit-address">Full address</label><textarea id="visit-address" name="address" autocomplete="street-address" maxlength="500" rows="2" required></textarea></div>
          <div class="field"><label for="visit-date">Preferred visit date</label><input id="visit-date" name="preferred_date" type="date" required></div>
          <div class="field"><label for="visit-time">Preferred visit time</label><input id="visit-time" name="preferred_time" type="time" required></div>
          <div class="field"><label for="visitor-count">Number of visitors</label><input id="visitor-count" name="visitor_count" type="number" min="1" max="50" value="1" required></div>
          <div class="field"><label for="reference-name">Reference name <span>(optional)</span></label><input id="reference-name" name="reference_name" maxlength="150"></div>
          <div class="field"><label for="reference-contact">Reference contact number <span>(optional)</span></label><input id="reference-contact" name="reference_contact" inputmode="tel" maxlength="25"></div>
          <label class="consent full"><input name="consent" type="checkbox" value="1" required> I agree to be contacted about this site visit request.</label>
          <button class="button button-dark full" type="submit">Request a Site Visit</button>
          <p class="form-status" role="status" aria-live="polite"></p>
        </form>
      </div>
    </section>
    <?php else: ?>
    <section class="site-visit-section section" id="site-visit">
      <div class="container site-visit-cta">
        <p class="section-kicker">Plan a visit</p><h2>EXPERIENCE THE PROJECT IN PERSON</h2>
        <p>Site visit requests will be available when an administrator publishes the project record.</p>
        <a class="button button-light" href="#contact">Request More Information</a>
      </div>
    </section>
    <?php endif; ?>

    <section class="enquiry section" id="contact">
      <div class="container enquiry-grid">
        <div class="enquiry-copy">
          <p class="section-kicker">Merit Real Solutions &amp; EK Aram Developers</p>
          <h2>Ask the project team.</h2>
          <p>Request verified plot availability, project details, or a call back.</p>
          <p><a href="mailto:<?= Security::escape($contactEmail) ?>"><?= Security::escape($contactEmail) ?></a></p>
          <?php if ($contactPhone !== ''): ?><p><a href="tel:<?= Security::escape($contactPhoneHref) ?>"><?= Security::escape($contactPhone) ?></a></p><?php endif; ?>
        </div>
        <div class="lead-extra-forms">
          <form class="lead-form" data-lead-form method="post" action="/api/leads.php">
            <h3 class="full">Request information</h3>
            <input type="hidden" name="csrf_token" value="<?= Security::escape($content['csrf_token']) ?>">
            <input type="hidden" name="form_type" value="enquiry">
            <input type="text" name="website" class="honeypot" tabindex="-1" autocomplete="off" aria-hidden="true">
            <div class="field"><label for="enquiry-name">Full name</label><input id="enquiry-name" name="name" autocomplete="name" maxlength="150" required></div>
            <div class="field"><label for="enquiry-phone">Mobile number</label><input id="enquiry-phone" name="phone" autocomplete="tel" inputmode="tel" maxlength="25" required></div>
            <div class="field"><label for="enquiry-email">Email address</label><input id="enquiry-email" name="email" type="email" autocomplete="email" maxlength="190"></div>
            <div class="field"><label for="interest">Interested in</label><select id="interest" name="interest"><option value="general">Project information</option><option value="plot">Plot details</option><option value="site_visit">Site visit</option></select></div>
            <div class="field full"><label for="message">Message</label><textarea id="message" name="message" rows="3" maxlength="2000"></textarea></div>
            <label class="consent full"><input name="consent" type="checkbox" value="1" required> I agree to be contacted about this enquiry.</label>
            <button class="button button-dark full" type="submit">Send Enquiry</button>
            <p class="form-status" role="status" aria-live="polite"></p>
          </form>
          <form class="lead-form callback-form" data-lead-form method="post" action="/api/leads.php">
            <h3 class="full">Request a callback</h3>
            <input type="hidden" name="csrf_token" value="<?= Security::escape($content['csrf_token']) ?>">
            <input type="hidden" name="form_type" value="callback">
            <input type="text" name="website" class="honeypot" tabindex="-1" autocomplete="off" aria-hidden="true">
            <div class="field full"><label for="callback-name">Full name</label><input id="callback-name" name="name" autocomplete="name" maxlength="150" required></div>
            <div class="field full"><label for="callback-phone">Mobile number</label><input id="callback-phone" name="phone" autocomplete="tel" inputmode="tel" maxlength="25" required></div>
            <label class="consent full"><input name="consent" type="checkbox" value="1" required> I agree to be contacted about this request.</label>
            <button class="button button-dark full" type="submit">Request Callback</button>
            <p class="form-status" role="status" aria-live="polite"></p>
          </form>
        </div>
      </div>
    </section>
  </main>

  <footer class="footer">
    <div class="container footer-grid">
      <div class="footer-brand-pair"><strong>MERIT REAL SOLUTIONS</strong><span>&amp;</span><strong>EK ARAM DEVELOPERS</strong></div>
      <p>A Premium Residential Plot Development · <?= Security::escape($projectLocation) ?></p>
      <div class="footer-links"><a href="#about">About Project</a><a href="#location">Location</a><a href="#amenities">Amenities</a><a href="#plots">Plot Layout</a><a href="#gallery">Gallery</a><a href="#contact">Contact</a></div>
      <div class="footer-contact"><strong>Contact</strong><a href="mailto:<?= Security::escape($contactEmail) ?>"><?= Security::escape($contactEmail) ?></a><?php if ($contactPhone !== ''): ?><a href="tel:<?= Security::escape($contactPhoneHref) ?>"><?= Security::escape($contactPhone) ?></a><?php endif; ?><?php if ($contactAddress !== ''): ?><span><?= Security::escape($contactAddress) ?></span><?php endif; ?></div>
    </div>
    <div class="container footer-bottom"><span>&copy; <?= date('Y') ?> Merit Real Solutions &amp; EK Aram Developers</span><a href="<?= Security::escape($brochureUrl) ?>" <?= $brochurePath !== '' ? '' : 'download' ?>>Project brochure</a></div>
  </footer>

  <?php if ($whatsappPhone !== ''): ?>
    <a class="whatsapp" href="https://wa.me/<?= Security::escape($whatsappPhone) ?>?text=<?= rawurlencode('Hello, I would like more information about the residential plot development at Tadikonda.') ?>" target="_blank" rel="noopener noreferrer" aria-label="Enquire on WhatsApp">WA</a>
  <?php else: ?>
    <a class="whatsapp" href="#contact" aria-label="Contact the project team">Enquire</a>
  <?php endif; ?>
  <script src="/assets/js/main.js" defer></script>
</body>
</html>
