<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

try {
    $content = PublicContentService::load();
} catch (Throwable $exception) {
    error_log('Amenities page unavailable: ' . $exception->getMessage());
    http_response_code(503);
    exit('The amenities page is temporarily unavailable.');
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="Explore project amenities from EK Aram Developers.">
  <title>Amenities | <?= Security::escape($content['project']['name'] ?: 'EK Aram Developers') ?></title>
  <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
  <header class="site-header amenities-header">
    <div class="container nav-wrap">
      <a class="brand" href="/" aria-label="EK Aram Developers home"><span class="brand-mark">EA</span><span><strong>EK ARAM</strong><small>DEVELOPERS</small></span></a>
      <a class="nav-cta" href="/#enquiry">Enquire now</a>
    </div>
  </header>
  <main>
    <section class="amenities section amenities-page">
      <div class="container">
        <p class="section-kicker">Project amenities</p>
        <h1><?= Security::escape($content['project']['name'] ?: 'Amenities') ?></h1>
        <p class="amenities-intro">Spaces and features listed here are managed by the project team.</p>
        <div class="amenity-grid">
          <?php foreach ($content['amenities'] as $index => $amenity): ?>
            <article class="amenity-card">
              <?php if (!empty($amenity['image_path'])): ?><img src="/media.php?file=<?= rawurlencode($amenity['image_path']) ?>" alt="<?= Security::escape($amenity['name']) ?>" loading="lazy"><?php endif; ?>
              <div><span><?= str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) ?></span><h2><?= Security::escape($amenity['name']) ?></h2><?php if ($amenity['description']): ?><p><?= Security::escape($amenity['description']) ?></p><?php endif; ?></div>
            </article>
          <?php endforeach; ?>
          <?php if (!$content['amenities']): ?><p class="empty-state">Amenities will be published here soon.</p><?php endif; ?>
        </div>
      </div>
    </section>
  </main>
  <footer class="footer"><div class="container footer-bottom"><a href="/">Back to EK Aram Developers</a><span>&copy; <?= date('Y') ?> EK Aram Developers</span></div></footer>
</body>
</html>
