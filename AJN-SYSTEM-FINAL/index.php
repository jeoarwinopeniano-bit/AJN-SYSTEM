<?php
session_start();

$isSignedIn = isset($_SESSION['user_id']);
$startUrl = 'register.php';
$startLabel = 'Create an account';

if ($isSignedIn) {
    $isAdmin = ($_SESSION['role'] ?? '') === 'admin';
    $startUrl = $isAdmin ? 'admin.php' : 'printing.php';
    $startLabel = $isAdmin ? 'Open dashboard' : 'Place an order';
}

$startHref = htmlspecialchars($startUrl, ENT_QUOTES, 'UTF-8');
$startText = htmlspecialchars($startLabel, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#17374a">
    <meta name="description" content="AJN T-Shirts and Prints makes custom shirts for teams, events, businesses, and personal orders.">
    <title>AJN T-Shirts and Prints</title>
    <link rel="stylesheet" href="css/landing.css">
</head>
<body class="landing">
    <header class="landing-header">
        <a class="landing-brand" href="index.php" aria-label="AJN T-Shirts and Prints home">
            <img src="images/ajn-logo.jpg" alt="">
            <span>AJN T-SHIRTS &amp; PRINTS</span>
        </a>
        <nav class="landing-nav" aria-label="Main navigation">
            <a href="#services">Services</a>
            <?php if (!$isSignedIn): ?><a href="login.php">Sign in</a><?php endif; ?>
            <a class="landing-button" href="<?= $startHref ?>"><?= $startText ?></a>
        </nav>
    </header>

    <main class="landing-main">
        <section class="landing-hero">
            <div class="landing-hero-copy">
                <p class="landing-kicker">Custom printing · Est. 2015</p>
                <h1>Custom shirts for your team, event, or business.</h1>
                <p class="landing-intro">Choose a shirt style and color, set the sizes, and upload your design. Sign in to follow your request and order updates.</p>
                <div class="landing-actions">
                    <a class="landing-button landing-button-primary" href="<?= $startHref ?>"><?= $startText ?></a>
                </div>
            </div>
            <figure class="landing-hero-logo">
                <img src="images/ajn-logo.jpg" alt="AJN T-Shirts and Prints logo">
            </figure>
        </section>

        <section class="landing-services" id="services" aria-labelledby="services-title">
            <div class="landing-section-heading">
                <p class="landing-kicker">Services</p>
                <h2 id="services-title">What we print</h2>
            </div>
            <div class="landing-service-list">
                <article class="landing-service">
                    <h3>Custom shirts</h3>
                    <p>Choose your shirt style, color, quantity, and size mix.</p>
                </article>
                <article class="landing-service">
                    <h3>Artwork printing</h3>
                    <p>Upload your design with your request for review.</p>
                </article>
                <article class="landing-service">
                    <h3>Group orders</h3>
                    <p>Order matching shirts for teams, events, and organizations.</p>
                </article>
            </div>
        </section>

        <section class="landing-examples" aria-labelledby="examples-title">
            <div class="landing-section-heading">
                <p class="landing-kicker">Shirt examples</p>
                <h2 id="examples-title">A few ideas to get started</h2>
            </div>
            <div class="landing-example-list">
                <figure class="landing-example-card">
                    <img src="images/shirt.png" alt="Custom shirt example one">
                    <figcaption>Standard Shirt Print</figcaption>
                </figure>
                <figure class="landing-example-card">
                    <img src="images/Long.png" alt="Custom shirt example two">
                    <figcaption>Longsleeves Shirt Print</figcaption>
                </figure>
                <figure class="landing-example-card">
                    <img src="images/Short.png" alt="Custom shirt example three">
                    <figcaption>Sleeveless Shirt Print</figcaption>
                </figure>
            </div>
        </section>

        <section class="landing-process" aria-labelledby="process-title">
            <h2 id="process-title">How ordering works</h2>
            <ol>
                <li><span>1.</span> Choose a shirt and enter the size quantities.</li>
                <li><span>2.</span> Upload your design and submit your request.</li>
                <li><span>3.</span> Sign in to check your order status.</li>
            </ol>
        </section>
    </main>

    <footer class="landing-footer">
        <a class="landing-footer-brand" href="index.php">AJN T-Shirts and Prints</a>
        <span>Custom printing since 2015</span>
    </footer>
</body>
</html>
