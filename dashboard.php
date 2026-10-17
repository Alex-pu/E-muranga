<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
startAppSession();
$user = requireLogin();
if (($user['role'] ?? 'citizen') === 'admin') {
    header('Location: admin.php');
    exit;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$requests = [];
$stats = ['total' => 0, 'open' => 0, 'resolved' => 0];
$databaseError = null;

try {
    $pdo = database();
    $statement = $pdo->prepare(
        "SELECT COUNT(*) AS total,
                COUNT(*) FILTER (WHERE status IN ('Pending', 'In Progress')) AS open,
                COUNT(*) FILTER (WHERE status = 'Resolved') AS resolved
         FROM service_requests WHERE user_id = :user_id"
    );
    $statement->execute(['user_id' => $user['id']]);
    $stats = $statement->fetch() ?: $stats;

    $statement = $pdo->prepare(
        'SELECT id, reference_code, category, location, description, image_path, status, created_at, updated_at
         FROM service_requests WHERE user_id = :user_id
         ORDER BY created_at DESC LIMIT 20'
    );
    $statement->execute(['user_id' => $user['id']]);
    $requests = $statement->fetchAll();
} catch (RuntimeException $exception) {
    error_log('eMuranga citizen dashboard error: ' . $exception->getMessage());
    $databaseError = 'We could not load your requests. Check the database connection and refresh.';
}

$csrfToken = csrfToken();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#123d31">
    <title>My requests | eMuranga</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body>
<header class="topbar">
    <a class="brand" href="dashboard.php" aria-label="eMuranga dashboard">
        <span class="brand-mark"><span></span><span></span><span></span></span>
        <span>e<span class="brand-light">Muranga</span><small>PUBLIC SERVICE DESK</small></span>
    </a>
    <nav class="desktop-nav" aria-label="Main navigation">
        <a class="active" href="#new-request">Report an issue</a>
        <a href="#requests">My requests</a>
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
            <div class="eyebrow"><span class="live-dot"></span> MURANG'A COUNTY · COMMUNITY SERVICE DESK</div>
            <h1>Let’s make things <span>better.</span></h1>
            <p>Report a local issue. Track it from submission to resolution.</p>
            <div class="emergency-call-mockup" role="note" aria-label="Emergency call mockup: proposed free internet call without airtime">
                <span class="emergency-call-icon" aria-hidden="true">!</span>
                <span class="emergency-call-copy"><strong>Emergency? Get help</strong><small>Proposed free internet call · no airtime</small></span>
                <span class="emergency-call-badge">MOCKUP</span>
            </div>
            <p class="emergency-note">Concept only — no call is placed and no emergency service is connected.</p>
        </div>
        <a class="primary-button" href="#new-request">Report an issue <span>→</span></a>
    </section>
    <?php if ($flash): ?>
        <div class="dashboard-notice notice <?= ($flash['type'] ?? '') === 'success' ? 'notice-success' : 'notice-error' ?>" role="status"><?= escape($flash['message']) ?></div>
    <?php endif; ?>
    <?php if ($databaseError): ?>
        <div class="dashboard-notice notice notice-error" role="alert"><?= escape($databaseError) ?></div>
    <?php endif; ?>
    <section class="stats-grid" aria-label="My request summary">
        <article class="stat-card"><span class="stat-icon">▤</span><div><span class="stat-label">MY REPORTS</span><strong><?= number_format((int) $stats['total']) ?></strong><small>All reports</small></div></article>
        <article class="stat-card"><span class="stat-icon stat-icon-gold">◷</span><div><span class="stat-label">OPEN</span><strong><?= number_format((int) $stats['open']) ?></strong><small>Being handled</small></div></article>
        <article class="stat-card"><span class="stat-icon stat-icon-blue">✓</span><div><span class="stat-label">RESOLVED</span><strong><?= number_format((int) $stats['resolved']) ?></strong><small>Issues closed</small></div></article>
    </section>

    <section class="content-section dashboard-services" id="services">
        <div class="section-heading">
            <div><div class="section-kicker">WHAT NEEDS ATTENTION?</div><h2>Common report categories</h2></div>
            <span class="section-aside">Select a category to start</span>
        </div>
        <div class="service-grid category-grid">
            <?php foreach (requestCategoryDetails() as $category => $details): ?>
                <a class="service-card category-card" href="#new-request" data-category="<?= escape($category) ?>">
                    <span class="service-icon"><?= escape($details['icon']) ?></span>
                    <span class="service-title"><?= escape($category) ?></span>
                    <span class="service-description"><?= escape($details['description']) ?></span>
                    <span class="card-action">Report an issue →</span>
                </a>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="action-grid dashboard-actions">
        <div class="request-panel" id="new-request">
            <div class="section-kicker">HELP US GET IT TO THE RIGHT TEAM</div>
            <h2>Report a community issue</h2>
            <p class="form-intro">Tell us what happened and where. A photo helps, but is optional.</p>
            <form action="submit_request.php" method="post" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= escape($csrfToken) ?>">
                <label>Issue category
                    <select name="category" required>
                        <option value="">Choose a category</option>
                        <?php foreach (requestCategories() as $category): ?>
                            <option value="<?= escape($category) ?>"><?= escape($category) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Location<input name="location" maxlength="200" placeholder="Estate, road, landmark or ward" required></label>
                <label>Describe the issue<textarea name="description" rows="4" maxlength="3000" placeholder="What happened? When did you notice it?" required></textarea></label>
                <label>Photo <span class="optional">(optional · JPG, PNG or WebP · max 5 MB)</span><input class="file-input" type="file" name="image" accept="image/jpeg,image/png,image/webp"></label>
                <label>Contact phone<input name="phone" type="tel" autocomplete="tel" maxlength="30" value="<?= escape($user['phone']) ?>" required></label>
                <button class="primary-button submit-button" type="submit">Submit report <span>→</span></button>
                <p class="privacy-note">Use sample information for this internship demonstration.</p>
            </form>
        </div>
        <aside class="requests-panel" id="requests">
            <div class="section-kicker">YOUR ACTIVITY</div>
            <h2>My reports</h2>
            <p class="form-intro">Follow every issue you have reported.</p>
            <?php if ($requests): ?>
                <div class="request-list">
                    <?php foreach ($requests as $request): ?>
                        <article class="case-item">
                            <div class="case-heading">
                                <div><strong><?= escape($request['category']) ?></strong><small><?= escape($request['reference_code']) ?> · <?= date('M j, Y', strtotime($request['created_at'])) ?></small></div>
                                <span class="status status-<?= strtolower(str_replace(' ', '-', requestStatusLabel($request['status']))) ?>"><?= escape(requestStatusLabel($request['status'])) ?></span>
                            </div>
                            <p class="case-location">⌖ <?= escape($request['location']) ?></p>
                            <p class="case-description"><?= nl2br(escape($request['description'])) ?></p>
                            <?php if ($request['image_path']): ?>
                                <a class="case-image-link" href="media.php?id=<?= (int) $request['id'] ?>" target="_blank" rel="noopener">View attached photo ↗</a>
                            <?php endif; ?>
                            <small class="updated-date">Updated <?= date('M j, Y', strtotime($request['updated_at'])) ?></small>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state"><span>▤</span><strong>No reports yet</strong><p>Submit an issue and track its progress here.</p></div>
            <?php endif; ?>
            <div class="support-card"><span class="support-icon">i</span><div><strong>What happens next?</strong><p>Your report is reviewed by the county desk and tracked through resolution.</p></div></div>
        </aside>
    </section>
</main>
<footer id="about">
    <a class="brand footer-brand" href="dashboard.php"><span class="brand-mark"><span></span><span></span><span></span></span><span>e<span class="brand-light">Muranga</span><small>PUBLIC SERVICE DESK</small></span></a>
    <p>Independent community service demo; not an official county website.</p>
    <span class="footer-credit">Made by <strong>Alex Kamau</strong> <span>·</span> Internship project</span>
</footer>
<script>
document.querySelectorAll('[data-category]').forEach((card) => {
    card.addEventListener('click', () => {
        document.querySelector('select[name="category"]').value = card.dataset.category;
    });
});
document.querySelector('input[type="file"]').addEventListener('change', (event) => {
    const file = event.currentTarget.files[0];
    if (file && file.size > 5 * 1024 * 1024) {
        event.currentTarget.value = '';
        alert('Choose an image that is 5 MB or smaller.');
    }
});
</script>
</body>
</html>
