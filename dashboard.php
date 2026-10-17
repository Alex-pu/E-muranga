<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
startAppSession();
$user = requireLogin();

$services = [
    ['icon' => '⌂', 'category' => 'LAND & PROPERTY', 'name' => 'Land rates payment', 'description' => 'Request a land-rates statement or ask about a payment.', 'tag' => 'Payments'],
    ['icon' => '▤', 'category' => 'BUSINESS', 'name' => 'Business permit', 'description' => 'Ask about applying for or renewing a business permit.', 'tag' => 'Applications'],
    ['icon' => '♧', 'category' => 'HEALTH', 'name' => 'Health services', 'description' => 'Request help finding county health facilities.', 'tag' => 'Health'],
    ['icon' => '▧', 'category' => 'CIVIL SERVICES', 'name' => 'Birth notification', 'description' => 'Start a request about a birth notification record.', 'tag' => 'Records'],
    ['icon' => '♧', 'category' => 'AGRICULTURE', 'name' => 'Agriculture support', 'description' => 'Ask about farmer support and extension services.', 'tag' => 'Support'],
    ['icon' => '◉', 'category' => 'PUBLIC WORKS', 'name' => 'Report an issue', 'description' => 'Report a local road or public-space issue.', 'tag' => 'Community'],
];

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$csrfToken = csrfToken();
$requests = [];
$stats = ['total' => 0, 'pending' => 0, 'completed' => 0];
$databaseError = null;
$selectedService = (string) ($_GET['service'] ?? '');
if (!in_array($selectedService, array_column($services, 'name'), true)) {
    $selectedService = '';
}

try {
    $pdo = database();
    ensureRequestTable($pdo);
    $statement = $pdo->prepare(
        "SELECT COUNT(*) AS total,
                COUNT(*) FILTER (WHERE status IN ('Received', 'In review')) AS pending,
                COUNT(*) FILTER (WHERE status = 'Completed') AS completed
         FROM service_requests WHERE user_id = :user_id"
    );
    $statement->execute(['user_id' => $user['id']]);
    $stats = $statement->fetch() ?: $stats;

    $statement = $pdo->prepare(
        'SELECT reference_code, service_name, status, created_at
         FROM service_requests WHERE user_id = :user_id
         ORDER BY created_at DESC LIMIT 10'
    );
    $statement->execute(['user_id' => $user['id']]);
    $requests = $statement->fetchAll();
} catch (PDOException $exception) {
    error_log('eMuranga dashboard database error: ' . $exception->getMessage());
    $databaseError = 'We could not load your dashboard. Check the Neon database connection and refresh.';
} catch (RuntimeException $exception) {
    error_log('eMuranga dashboard database error: ' . $exception->getMessage());
    $databaseError = 'We could not load your dashboard. Check the Neon database connection and refresh.';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#123d31">
    <title>My dashboard | eMuranga</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body>
<header class="topbar">
    <a class="brand" href="dashboard.php" aria-label="eMuranga dashboard">
        <span class="brand-mark"><span></span><span></span><span></span></span>
        <span>e<span class="brand-light">Muranga</span><small>COUNTY SERVICES</small></span>
    </a>
    <nav class="desktop-nav" aria-label="Main navigation">
        <a class="active" href="#services">Services</a>
        <a href="#requests">My requests</a>
        <a href="#about">About</a>
    </nav>
    <div class="account-nav">
        <span class="account-greeting">Hi, <?= escape(explode(' ', $user['full_name'])[0]) ?></span>
        <form action="auth_action.php" method="post">
            <input type="hidden" name="csrf_token" value="<?= escape($csrfToken) ?>">
            <input type="hidden" name="action" value="logout">
            <button class="logout-button" type="submit">Sign out</button>
        </form>
    </div>
</header>

<main>
    <section class="dashboard-hero">
        <div>
            <div class="eyebrow"><span class="live-dot"></span> YOUR MURANG'A COUNTY SERVICE DESK</div>
            <h1>Hello, <span><?= escape(explode(' ', $user['full_name'])[0]) ?>.</span></h1>
            <p>What can we help you with today?</p>
        </div>
        <a class="primary-button" href="#services">Explore services <span>→</span></a>
    </section>

    <?php if ($flash): ?>
        <div class="dashboard-notice notice <?= ($flash['type'] ?? '') === 'success' ? 'notice-success' : 'notice-error' ?>" role="status"><?= escape($flash['message']) ?></div>
    <?php endif; ?>
    <?php if ($databaseError): ?>
        <div class="dashboard-notice notice notice-error" role="alert"><?= escape($databaseError) ?></div>
    <?php endif; ?>

    <section class="stats-grid" aria-label="Request summary">
        <article class="stat-card"><span class="stat-icon">▤</span><div><span class="stat-label">MY REQUESTS</span><strong><?= number_format((int) $stats['total']) ?></strong><small>All time</small></div></article>
        <article class="stat-card"><span class="stat-icon stat-icon-gold">◷</span><div><span class="stat-label">IN PROGRESS</span><strong><?= number_format((int) $stats['pending']) ?></strong><small>Awaiting follow-up</small></div></article>
        <article class="stat-card"><span class="stat-icon stat-icon-blue">✓</span><div><span class="stat-label">COMPLETED</span><strong><?= number_format((int) $stats['completed']) ?></strong><small>Requests completed</small></div></article>
    </section>

    <section class="content-section dashboard-services" id="services">
        <div class="section-heading">
            <div><div class="section-kicker">COUNTY SERVICES</div><h2>How can we help?</h2></div>
            <span class="section-aside">Choose a service to start a request</span>
        </div>
        <div class="service-grid">
            <?php foreach ($services as $service): ?>
                <a class="service-card" href="dashboard.php?service=<?= rawurlencode($service['name']) ?>#new-request">
                    <div class="card-top"><span class="service-icon"><?= escape($service['icon']) ?></span><span class="card-arrow">↗</span></div>
                    <div class="service-category"><?= escape($service['category']) ?></div>
                    <h3><?= escape($service['name']) ?></h3>
                    <p><?= escape($service['description']) ?></p>
                    <span class="service-tag"><?= escape($service['tag']) ?></span>
                    <span class="card-action">Start request →</span>
                </a>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="action-grid dashboard-actions">
        <div class="request-panel" id="new-request">
            <div class="section-kicker">SEND TO THE RIGHT TEAM</div>
            <h2>New service request</h2>
            <p class="form-intro">Your name and contact number are added from your account.</p>
            <form action="submit_request.php" method="post">
                <input type="hidden" name="csrf_token" value="<?= escape($csrfToken) ?>">
                <label>Service<select name="service_name" required>
                    <option value="">Choose a service</option>
                    <?php foreach ($services as $service): ?>
                        <option value="<?= escape($service['name']) ?>" <?= $selectedService === $service['name'] ? 'selected' : '' ?>><?= escape($service['name']) ?></option>
                    <?php endforeach; ?>
                </select></label>
                <label>Contact phone<input name="phone" type="tel" autocomplete="tel" maxlength="30" value="<?= escape($user['phone']) ?>" required></label>
                <label>Request details <span class="optional">(optional)</span><textarea name="notes" rows="4" maxlength="2000" placeholder="Tell us what you need help with..." ></textarea></label>
                <button class="primary-button submit-button" type="submit">Send request <span>→</span></button>
                <p class="privacy-note"><span>♧</span> Use sample details only; this is an internship demo.</p>
            </form>
        </div>
        <aside class="requests-panel" id="requests">
            <div class="section-kicker">YOUR ACCOUNT</div>
            <h2>My recent requests</h2>
            <p class="form-intro">Only you can see requests submitted from your account.</p>
            <?php if ($requests): ?>
                <div class="request-list">
                    <?php foreach ($requests as $request): ?>
                        <div class="request-item">
                            <span class="request-symbol">↗</span>
                            <div class="request-info"><strong><?= escape($request['service_name']) ?></strong><small><?= escape($request['reference_code']) ?> · <?= date('M j, Y', strtotime($request['created_at'])) ?></small></div>
                            <span class="status"><?= escape($request['status']) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state"><span>▤</span><strong>No requests yet</strong><p>Choose a service to send your first request.</p></div>
            <?php endif; ?>
            <div class="support-card"><span class="support-icon">i</span><div><strong>Need a hand?</strong><p>Start a service request and the relevant team can follow up.</p></div></div>
        </aside>
    </section>
</main>

<footer id="about">
    <a class="brand footer-brand" href="dashboard.php"><span class="brand-mark"><span></span><span></span><span></span></span><span>e<span class="brand-light">Muranga</span><small>COUNTY SERVICES</small></span></a>
    <p>Independent demo concept; not an official county or eCitizen website.</p>
    <span class="footer-credit">Made by <strong>Alex Kamau</strong> <span>·</span> Internship project</span>
</footer>
</body>
</html>
