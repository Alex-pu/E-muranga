<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
startAppSession();
$user = requireAdmin();
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$csrfToken = csrfToken();
$stats = ['total' => 0, 'open' => 0, 'resolved' => 0];
$categoryStats = [];
$requests = [];
$history = [];
$categoryStatsByName = [];
$databaseError = null;
$filterStatus = (string) ($_GET['status'] ?? '');
$filterCategory = (string) ($_GET['category'] ?? '');
$statuses = ['Pending', 'In Progress', 'Resolved'];

if (!in_array($filterStatus, $statuses, true)) {
    $filterStatus = '';
}
if (!in_array($filterCategory, requestCategories(), true)) {
    $filterCategory = '';
}

try {
    $pdo = database();
    $stats = $pdo->query(
        "SELECT COUNT(*) AS total,
                COUNT(*) FILTER (WHERE status IN ('Pending', 'In Progress')) AS open,
                COUNT(*) FILTER (WHERE status = 'Resolved') AS resolved
         FROM service_requests"
    )->fetch() ?: $stats;
    $categoryStats = $pdo->query(
        "SELECT category, COUNT(*) AS total,
                COUNT(*) FILTER (WHERE status IN ('Pending', 'In Progress')) AS open
         FROM service_requests GROUP BY category ORDER BY total DESC, category ASC"
    )->fetchAll();
    $categoryStatsByName = array_column($categoryStats, null, 'category');

    $conditions = [];
    $parameters = [];
    if ($filterStatus !== '') {
        $conditions[] = 'r.status = :status';
        $parameters['status'] = $filterStatus;
    }
    if ($filterCategory !== '') {
        $conditions[] = 'r.category = :category';
        $parameters['category'] = $filterCategory;
    }
    $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
    $statement = $pdo->prepare(
        "SELECT r.id, r.reference_code, r.full_name, r.phone, r.category, r.location,
                r.description, r.image_path, r.status, r.created_at, r.updated_at, u.email
         FROM service_requests r LEFT JOIN app_users u ON u.id = r.user_id
         $where ORDER BY r.created_at DESC LIMIT 100"
    );
    $statement->execute($parameters);
    $requests = $statement->fetchAll();
    $history = $pdo->query(
        "SELECT h.old_status, h.new_status, h.created_at, r.reference_code, r.category, u.full_name AS admin_name
         FROM request_status_history h
         JOIN service_requests r ON r.id = h.request_id
         LEFT JOIN app_users u ON u.id = h.changed_by
         ORDER BY h.created_at DESC LIMIT 8"
    )->fetchAll();
} catch (RuntimeException $exception) {
    error_log('eMuranga admin dashboard error: ' . $exception->getMessage());
    $databaseError = 'We could not load the service-desk reports. Check the local PostgreSQL connection and refresh.';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#123d31">
    <title>Staff dashboard | eMuranga</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css?v=20261017-2">
</head>
<body>
<header class="topbar">
    <a class="brand" href="admin.php" aria-label="eMuranga staff dashboard">
        <img class="brand-logo" src="assets/logo.png" alt="Murang'a County Government">
        <span>eMuranga<small>STAFF SERVICE DESK</small></span>
    </a>
    <nav class="desktop-nav" aria-label="Staff navigation"><a class="active" href="#cases">All reports</a><a href="#activity">Activity</a></nav>
    <div class="account-nav">
        <span class="account-greeting">Staff · <?= escape(explode(' ', $user['full_name'])[0]) ?></span>
        <form action="auth_action.php" method="post">
            <input type="hidden" name="csrf_token" value="<?= escape($csrfToken) ?>">
            <input type="hidden" name="action" value="logout">
            <button class="logout-button" type="submit">Sign out</button>
        </form>
    </div>
</header>
<main>
    <section class="dashboard-hero admin-hero">
        <div>
            <div class="eyebrow"><span class="live-dot"></span> MURANG'A COUNTY · STAFF VIEW</div>
            <h1>Community <span>reports.</span></h1>
            <p>Review new issues, update case progress, and see what needs attention.</p>
        </div>
        <a class="primary-button" href="#cases">Review reports <span>→</span></a>
    </section>
    <?php if ($flash): ?>
        <div class="dashboard-notice notice <?= ($flash['type'] ?? '') === 'success' ? 'notice-success' : 'notice-error' ?>" role="status"><?= escape($flash['message']) ?></div>
    <?php endif; ?>
    <?php if ($databaseError): ?>
        <div class="dashboard-notice notice notice-error" role="alert"><?= escape($databaseError) ?></div>
    <?php endif; ?>
    <section class="stats-grid" aria-label="Service request analytics">
        <article class="stat-card"><span class="stat-icon">▤</span><div><span class="stat-label">TOTAL REPORTS</span><strong><?= number_format((int) $stats['total']) ?></strong><small>All submissions</small></div></article>
        <article class="stat-card"><span class="stat-icon stat-icon-gold">◷</span><div><span class="stat-label">OPEN</span><strong><?= number_format((int) $stats['open']) ?></strong><small>Pending + in progress</small></div></article>
        <article class="stat-card"><span class="stat-icon stat-icon-blue">✓</span><div><span class="stat-label">RESOLVED</span><strong><?= number_format((int) $stats['resolved']) ?></strong><small>Completed cases</small></div></article>
    </section>
    <section class="content-section dashboard-services">
        <div class="section-heading"><div><div class="section-kicker">AT A GLANCE</div><h2>Reports by category</h2></div><span class="section-aside">Open cases need follow-up</span></div>
        <div class="analytics-grid">
            <?php foreach (requestCategories() as $category): ?>
                <?php
                $categoryRow = $categoryStatsByName[$category] ?? ['total' => 0, 'open' => 0];
                $count = (int) $categoryRow['total'];
                $percent = (int) $stats['total'] > 0 ? (int) round($count / (int) $stats['total'] * 100) : 0;
                ?>
                <article class="analytics-card"><div><strong><?= escape($category) ?></strong><span><?= $count ?> reports · <?= (int) $categoryRow['open'] ?> open</span></div><div class="progress-track"><span style="width: <?= $percent ?>%"></span></div></article>
            <?php endforeach; ?>
        </div>
    </section>
    <section class="admin-content action-grid">
        <div class="requests-panel admin-reports" id="cases">
            <div class="section-kicker">CASE MANAGEMENT</div>
            <h2>All reports</h2>
            <p class="form-intro">Move each case through Pending → In Progress → Resolved.</p>
            <form class="filter-form" method="get" action="admin.php">
                <label>Status<select name="status"><option value="">All statuses</option><?php foreach ($statuses as $status): ?><option value="<?= escape($status) ?>" <?= $filterStatus === $status ? 'selected' : '' ?>><?= escape($status) ?></option><?php endforeach; ?></select></label>
                <label>Category<select name="category"><option value="">All categories</option><?php foreach (requestCategories() as $category): ?><option value="<?= escape($category) ?>" <?= $filterCategory === $category ? 'selected' : '' ?>><?= escape($category) ?></option><?php endforeach; ?></select></label>
                <button class="filter-button" type="submit">Filter</button>
                <a class="clear-filter" href="admin.php">Clear</a>
            </form>
            <?php if ($requests): ?>
                <div class="admin-case-list">
                    <?php foreach ($requests as $request): ?>
                        <?php $status = requestStatusLabel($request['status']); ?>
                        <article class="admin-case">
                            <div class="case-heading">
                                <div><strong><?= escape($request['category']) ?></strong><small><?= escape($request['reference_code']) ?> · <?= date('M j, Y', strtotime($request['created_at'])) ?></small></div>
                                <span class="status status-<?= strtolower(str_replace(' ', '-', $status)) ?>"><?= escape($status) ?></span>
                            </div>
                            <p class="case-location">⌖ <?= escape($request['location']) ?></p>
                            <p class="case-description"><?= nl2br(escape($request['description'])) ?></p>
                            <div class="case-citizen">Reported by <?= escape($request['full_name']) ?> · <?= escape($request['phone']) ?> · <?= escape($request['email'] ?? 'Account unavailable') ?></div>
                            <?php if ($request['image_path']): ?>
                                <a class="case-image-link" href="media.php?id=<?= (int) $request['id'] ?>" target="_blank" rel="noopener">View attached photo ↗</a>
                            <?php endif; ?>
                            <?php if ($status !== 'Resolved'): ?>
                                <form class="status-form" action="admin_action.php" method="post">
                                    <input type="hidden" name="csrf_token" value="<?= escape($csrfToken) ?>">
                                    <input type="hidden" name="request_id" value="<?= (int) $request['id'] ?>">
                                    <input type="hidden" name="status" value="<?= $status === 'Pending' ? 'In Progress' : 'Resolved' ?>">
                                    <button class="status-button" type="submit"><?= $status === 'Pending' ? 'Start work →' : 'Mark resolved ✓' ?></button>
                                </form>
                            <?php else: ?>
                                <small class="updated-date">Resolved <?= date('M j, Y', strtotime($request['updated_at'])) ?></small>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state"><span>▤</span><strong>No reports match</strong><p>Try clearing your filters.</p></div>
            <?php endif; ?>
        </div>
        <aside class="requests-panel" id="activity">
            <div class="section-kicker">AUDIT TRAIL</div>
            <h2>Recent updates</h2>
            <p class="form-intro">A record of staff status changes.</p>
            <?php if ($history): ?>
                <div class="request-list">
                    <?php foreach ($history as $event): ?>
                        <div class="history-item"><span class="request-symbol">↗</span><div class="request-info"><strong><?= escape($event['category']) ?> · <?= escape($event['new_status']) ?></strong><small><?= escape($event['reference_code']) ?> · <?= escape($event['admin_name'] ?? 'Staff') ?> · <?= date('M j, g:i a', strtotime($event['created_at'])) ?></small></div></div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state"><span>◷</span><strong>No updates yet</strong><p>Status changes will appear here.</p></div>
            <?php endif; ?>
        </aside>
    </section>
</main>
<footer id="about">
    <a class="brand footer-brand" href="admin.php"><img class="brand-logo" src="assets/logo.png" alt=""><span>eMuranga<small>STAFF SERVICE DESK</small></span></a>
    <p>Staff dashboard · independent internship demo.</p>
    <span class="footer-credit">Made by <strong>Alex Kamau</strong></span>
</footer>
</body>
</html>
