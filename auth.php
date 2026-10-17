<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
startAppSession();

if (isset($_SESSION['user'])) {
    header('Location: dashboard.php');
    exit;
}

$mode = $_GET['mode'] ?? 'login';
$isSignup = $mode === 'signup';
$error = null;
$name = '';
$email = '';
$phone = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mode = (string) ($_POST['mode'] ?? $mode);
    $isSignup = $mode === 'signup';
    $submittedToken = (string) ($_POST['csrf_token'] ?? '');
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $submittedToken)) {
        $error = 'Your form session expired. Refresh the page and try again.';
    } else {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');

        if ($isSignup) {
            $name = trim((string) ($_POST['full_name'] ?? ''));
            $phone = trim((string) ($_POST['phone'] ?? ''));
            if (
                $name === ''
                || strlen($name) > 120
                || !filter_var($email, FILTER_VALIDATE_EMAIL)
                || strlen($email) > 254
                || $phone === ''
                || strlen($phone) > 30
                || strlen($password) < 8
                || strlen($password) > 4096
            ) {
                $error = 'Enter a name, valid email, phone number and password with at least 8 characters.';
            }
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '' || strlen($password) > 4096) {
            $error = 'Enter a valid email and your password.';
        }

        if ($error === null) {
            try {
                $pdo = database();
                ensureRequestTable($pdo);

                if ($isSignup) {
                    $statement = $pdo->prepare(
                        'INSERT INTO app_users (full_name, email, phone, password_hash)
                         VALUES (:full_name, :email, :phone, :password_hash)
                         RETURNING id, full_name, email, phone'
                    );
                    $statement->execute([
                        'full_name' => $name,
                        'email' => $email,
                        'phone' => $phone,
                        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    ]);
                    $user = $statement->fetch();
                    session_regenerate_id(true);
                    $_SESSION['user'] = $user;
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                    redirectWithMessage('success', 'Your account is ready. Welcome to eMuranga!', 'dashboard.php');
                }

                $statement = $pdo->prepare(
                    'SELECT id, full_name, email, phone, password_hash
                     FROM app_users WHERE email = :email'
                );
                $statement->execute(['email' => $email]);
                $user = $statement->fetch();

                if (!$user || !password_verify($password, $user['password_hash'])) {
                    $error = 'We could not sign you in with those details.';
                } else {
                    session_regenerate_id(true);
                    unset($user['password_hash']);
                    $_SESSION['user'] = $user;
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                    header('Location: dashboard.php');
                    exit;
                }
            } catch (PDOException $exception) {
                error_log('eMuranga account error: ' . $exception->getMessage());
                $error = $isSignup && $exception->getCode() === '23505'
                    ? 'An account with that email already exists. Try signing in instead.'
                    : 'We could not connect to the service desk. Check DATABASE_URL and try again.';
            } catch (RuntimeException $exception) {
                error_log('eMuranga account error: ' . $exception->getMessage());
                $error = 'We could not connect to the service desk. Check DATABASE_URL and try again.';
            }
        }
    }
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#123d31">
    <title><?= $isSignup ? 'Create account' : 'Sign in' ?> | eMuranga</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body class="auth-page">
<header class="topbar">
    <a class="brand" href="auth.php" aria-label="eMuranga home">
        <span class="brand-mark"><span></span><span></span><span></span></span>
        <span>e<span class="brand-light">Muranga</span><small>COUNTY SERVICES</small></span>
    </a>
    <a class="help-link" href="auth.php?mode=<?= $isSignup ? 'login' : 'signup' ?>">
        <?= $isSignup ? 'Already registered? Sign in' : 'New here? Create account' ?>
    </a>
</header>
<main class="auth-layout">
    <section class="auth-welcome">
        <div class="eyebrow"><span class="live-dot"></span> MURANG'A COUNTY · YOUR SERVICE DESK</div>
        <h1>Your county,<br>closer to <span>you.</span></h1>
        <p>One account to request services and keep track of what you've sent.</p>
        <div class="auth-benefits">
            <div><span>✓</span> Request county services in one place</div>
            <div><span>✓</span> Keep your submissions together</div>
            <div><span>✓</span> Check your request status any time</div>
        </div>
    </section>
    <section class="auth-card">
        <div class="section-kicker">YOUR SERVICE DESK ACCOUNT</div>
        <h2><?= $isSignup ? 'Create your account' : 'Welcome back' ?></h2>
        <p class="form-intro"><?= $isSignup ? 'A few details to get you started.' : 'Sign in to see your services and requests.' ?></p>
        <?php if ($flash): ?>
            <div class="notice notice-success" role="status"><?= escape($flash['message']) ?></div>
        <?php endif; ?>
        <?php if ($error !== null): ?>
            <div class="notice notice-error" role="alert"><?= escape($error) ?></div>
        <?php endif; ?>
        <form method="post" action="auth.php">
            <input type="hidden" name="csrf_token" value="<?= escape(csrfToken()) ?>">
            <input type="hidden" name="mode" value="<?= $isSignup ? 'signup' : 'login' ?>">
            <?php if ($isSignup): ?>
                <label>Full name<input name="full_name" autocomplete="name" maxlength="120" value="<?= escape($name) ?>" placeholder="Your name" required></label>
            <?php endif; ?>
            <label>Email address<input name="email" type="email" autocomplete="email" maxlength="254" value="<?= escape($email) ?>" placeholder="you@example.com" required></label>
            <?php if ($isSignup): ?>
                <label>Phone number<input name="phone" type="tel" autocomplete="tel" maxlength="30" value="<?= escape($phone) ?>" placeholder="+254 7XX XXX XXX" required></label>
            <?php endif; ?>
            <label>Password<input name="password" type="password" autocomplete="<?= $isSignup ? 'new-password' : 'current-password' ?>" minlength="<?= $isSignup ? '8' : '1' ?>" required></label>
            <button class="primary-button submit-button auth-submit" type="submit"><?= $isSignup ? 'Create account' : 'Sign in' ?><span>→</span></button>
        </form>
        <p class="auth-switch">
            <?= $isSignup ? 'Already have an account?' : "Don't have an account?" ?>
            <a href="auth.php?mode=<?= $isSignup ? 'login' : 'signup' ?>"><?= $isSignup ? 'Sign in' : 'Sign up' ?></a>
        </p>
        <p class="demo-disclaimer">Independent demo concept; not an official county or eCitizen website. Use sample details only.</p>
    </section>
</main>
<footer id="about">
    <a class="brand footer-brand" href="auth.php"><span class="brand-mark"><span></span><span></span><span></span></span><span>e<span class="brand-light">Muranga</span><small>COUNTY SERVICES</small></span></a>
    <p>Independent demo concept; not an official county or eCitizen website.</p>
    <span class="footer-credit">Made by <strong>Alex Kamau</strong> <span>·</span> Internship project</span>
</footer>
</body>
</html>
