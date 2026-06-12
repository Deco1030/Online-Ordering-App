<?php
declare(strict_types=1);

require __DIR__ . '/lib/Security.php';
require_once __DIR__ . '/lib/DB.php';
require_once __DIR__ . '/lib/Auth.php';

use Lib\Auth;
use Lib\Security;

Security::startSession();

// Base path for links when the app is hosted in a subfolder.
$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
if ($basePath === '/') {
    $basePath = '';
}
$GLOBALS['app_base_path'] = $basePath;

$page = $_GET['page'] ?? null;

// If no page parameter is given, render the public landing page.
if ($page === null) {
    $isAuthed = Auth::user() !== null;
    $loginHref = $basePath . '/?page=auth/login';
    $registerHref = $basePath . '/?page=auth/register';
    $homeHref = $basePath . '/';

    if ($isAuthed) {
        $u = Auth::user();
        $homeHref = $u && ($u['role'] ?? '') === 'admin' ? $basePath . '/?page=admin_dashboard' : $basePath . '/?page=client_dashboard';
    }

    ob_start();
    ?>
    <!doctype html>
    <html lang="en">
    <head>
      <meta charset="utf-8" />
      <meta name="viewport" content="width=device-width, initial-scale=1" />
      <title>Online Ordering & Shipment Tracking</title>

      <!-- Bootstrap 5 (Icons removed for lighter payload) -->
      <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />

      <link rel="stylesheet" href="<?php echo Security::e($basePath); ?>/style.css" />
      <link rel="stylesheet" href="<?php echo Security::e($basePath); ?>/landing-light-fix.css" />
    </head>
    <body>
      <a class="skip-link" href="#main">Skip to content</a>

    <!-- Navbar (fixed, responsive) -->
    <nav class="navbar navbar-expand-lg fixed-top" aria-label="Main navigation">

      <div class="container">
        <a class="navbar-brand fw-semibold" href="<?php echo Security::e($homeHref); ?>">
          <span class="brand-mark me-2">🚚</span>
          <span>OnTrackX</span>
        </a>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav"
          aria-controls="nav" aria-expanded="false" aria-label="Toggle navigation">
          <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="nav">
          <ul class="navbar-nav ms-auto mb-2 mb-lg-0 gap-lg-2">
            <li class="nav-item"><a class="nav-link active" href="#home">Home</a></li>
            <li class="nav-item"><a class="nav-link" href="#services">Services</a></li>
            <li class="nav-item"><a class="nav-link" href="#pricing">Pricing</a></li>
            <li class="nav-item"><a class="nav-link" href="#tracking">Track Shipment</a></li>
            <li class="nav-item"><a class="nav-link" href="#contact">Contact Us</a></li>
          </ul>

          <div class="d-flex flex-wrap gap-2 mt-3 mt-lg-0 ms-lg-3">
            <?php if ($isAuthed): ?>
              <?php $u = Auth::user(); ?>
              <a class="btn btn-outline-light btn-sm" href="<?php echo Security::e($homeHref); ?>">
                <i class="bi bi-person-check me-1"></i>
                Dashboard
              </a>
            <?php else: ?>
              <a class="btn btn-outline-light btn-sm" href="<?php echo Security::e($loginHref); ?>">
                <i class="bi bi-box-arrow-in-right me-1"></i>Login
              </a>
              <a class="btn btn-warning btn-sm text-dark" href="<?php echo Security::e($registerHref); ?>">
                <i class="bi bi-person-plus me-1"></i>Register
              </a>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </nav>

    <!-- Hero -->
    <header id="home" class="hero d-flex align-items-center">
      <div class="container pt-5 mt-5">
        <div class="row align-items-center g-4">
          <div class="col-lg-7">
            <div class="hero-badge mb-3">
              <i class="bi bi-shield-check me-2"></i> Secure ordering • Real-time tracking • Instant updates
            </div>
            <h1 class="display-6 fw-bold text-white">
              Fast, Reliable Online Ordering & Shipment Tracking
            </h1>
            <p class="lead text-white-50 mt-3">
              Place orders online, track shipments in real-time, and receive instant delivery updates.
            </p>

            <div class="d-flex flex-wrap gap-2 mt-4">
              <a class="btn btn-warning text-dark btn-lg" href="<?php echo Security::e($basePath); ?>/?page=client_dashboard">
                <i class="bi bi-bag-check me-2"></i>Place an Order
              </a>
              <a class="btn btn-outline-light btn-lg" href="#tracking">
                <i class="bi bi-geo-alt me-2"></i>Track Shipment
              </a>
            </div>

            <div class="hero-stats mt-4">
              <div class="row g-3">
                <div class="col-6 col-md-3">
                  <div class="stat-card">
                    <i class="bi bi-truck fs-3 text-warning"></i>
                    <div class="stat-value">10,000+</div>
                    <div class="stat-label">Deliveries Completed</div>
                  </div>
                </div>
                <div class="col-6 col-md-3">
                  <div class="stat-card">
                    <i class="bi bi-emoji-smile fs-3 text-warning"></i>
                    <div class="stat-value">5,000+</div>
                    <div class="stat-label">Happy Customers</div>
                  </div>
                </div>
                <div class="col-6 col-md-3">
                  <div class="stat-card">
                    <i class="bi bi-pin-map fs-3 text-warning"></i>
                    <div class="stat-value">50+</div>
                    <div class="stat-label">Service Locations</div>
                  </div>
                </div>
                <div class="col-6 col-md-3">
                  <div class="stat-card">
                    <i class="bi bi-clock-history fs-3 text-warning"></i>
                    <div class="stat-value">99%</div>
                    <div class="stat-label">On-Time Delivery</div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div class="col-lg-5">
            <div class="hero-image card border-0 shadow-lg">
              <div class="card-body p-3">
                <!-- Placeholder logistics-themed image (SVG illustration) -->
                <img
                  src="<?php echo Security::e($basePath); ?>/assets/logistics-illustration.svg"
                  alt="Logistics illustration"
                  class="img-fluid rounded-3"
                  loading="lazy"
                />
                <div class="mt-3 small text-muted">
                  <i class="bi bi-info-circle"></i> Delivery tracking made simple.
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Quick Tracking -->
        <div id="tracking" class="tracking-strip">
          <div class="row align-items-center g-3">
            <div class="col-lg-8">
              <div class="tracking-title">
                <i class="bi bi-search me-2 text-warning"></i>
                Quick Shipment Tracking
              </div>
              <div class="text-muted">
                Enter your tracking number to view the latest status and timeline.
              </div>
            </div>
            <div class="col-lg-4">
              <form class="tracking-form" onsubmit="return false;">
                <div class="input-group">
                  <span class="input-group-text bg-white"><i class="bi bi-upc-scan"></i></span>
                  <input id="trackingNumber" class="form-control" type="text" placeholder="e.g. TRK-123456" />
                  <button class="btn btn-warning text-dark" type="button" onclick="window.trackNow()">Track Now</button>
                </div>
                <div class="tracking-hint" id="trackingHint" aria-live="polite"></div>
              </form>
            </div>
          </div>
        </div>

      </div>
    </header>

    <main id="main" class="landing-fix">

      <!-- Services -->
      <section id="services" class="section">
        <div class="container">
          <div class="section-heading">
            <h2 class="fw-bold">Our Services</h2>
            <p class="text-muted mb-0">Choose the delivery option that fits your timeline and needs.</p>
          </div>

          <div class="row g-4 mt-2">
            <div class="col-md-6 col-lg-3">
              <div class="feature-card h-100">
                <div class="feature-icon"><i class="bi bi-speedometer2"></i></div>
                <h3 class="h5 mt-3">Standard Delivery</h3>
                <p class="text-muted">Reliable delivery with great value.</p>
                <a class="btn btn-sm btn-outline-primary" href="#pricing">Learn More</a>
              </div>
            </div>
            <div class="col-md-6 col-lg-3">
              <div class="feature-card h-100">
                <div class="feature-icon"><i class="bi bi-lightning-charge"></i></div>
                <h3 class="h5 mt-3">Express Delivery</h3>
                <p class="text-muted">Fast delivery for urgent shipments.</p>
                <a class="btn btn-sm btn-outline-primary" href="#pricing">Learn More</a>
              </div>
            </div>
            <div class="col-md-6 col-lg-3">
              <div class="feature-card h-100">
                <div class="feature-icon"><i class="bi bi-globe"></i></div>
                <h3 class="h5 mt-3">International Shipping</h3>
                <p class="text-muted">Custom quotes for global destinations.</p>
                <a class="btn btn-sm btn-outline-primary" href="#contact">Learn More</a>
              </div>
            </div>
            <div class="col-md-6 col-lg-3">
              <div class="feature-card h-100">
                <div class="feature-icon"><i class="bi bi-stars"></i></div>
                <h3 class="h5 mt-3">Same-Day Delivery</h3>
                <p class="text-muted">Get it delivered today, when possible.</p>
                <a class="btn btn-sm btn-outline-primary" href="#contact">Learn More</a>
              </div>
            </div>
          </div>

        </div>
      </section>

      <!-- How it Works -->
      <section class="section bg-light" aria-label="How it works">
        <div class="container">
          <div class="section-heading">
            <h2 class="fw-bold">How It Works</h2>
            <p class="text-muted mb-0">A simple process from order to delivery.</p>
          </div>

          <div class="stepper mt-4">
            <div class="step">
              <div class="step-icon"><i class="bi bi-bag"></i></div>
              <div class="step-number">1</div>
              <div class="step-title">Place Order</div>
            </div>
            <div class="step">
              <div class="step-icon"><i class="bi bi-gear"></i></div>
              <div class="step-number">2</div>
              <div class="step-title">Order Processing</div>
            </div>
            <div class="step">
              <div class="step-icon"><i class="bi bi-truck"></i></div>
              <div class="step-number">3</div>
              <div class="step-title">Shipment Dispatch</div>
            </div>
            <div class="step">
              <div class="step-icon"><i class="bi bi-box-seam"></i></div>
              <div class="step-number">4</div>
              <div class="step-title">Track & Receive Delivery</div>
            </div>
          </div>
        </div>
      </section>

      <!-- Why Choose Us -->
      <section class="section">
        <div class="container">
          <div class="section-heading">
            <h2 class="fw-bold">Why Choose Us</h2>
            <p class="text-muted mb-0">Everything you need for smooth delivery operations.</p>
          </div>

          <div class="row g-4 mt-2">
            <div class="col-md-6 col-lg-4">
              <div class="why-card h-100">
                <i class="bi bi-alarm text-warning"></i>
                <h3 class="h5 mt-2">Real-Time Tracking</h3>
                <p class="text-muted">See status changes as they happen.</p>
              </div>
            </div>
            <div class="col-md-6 col-lg-4">
              <div class="why-card h-100">
                <i class="bi bi-lock text-warning"></i>
                <h3 class="h5 mt-2">Secure Ordering</h3>
                <p class="text-muted">Protected forms and verified access.</p>
              </div>
            </div>
            <div class="col-md-6 col-lg-4">
              <div class="why-card h-100">
                <i class="bi bi-lightning text-warning"></i>
                <h3 class="h5 mt-2">Fast Delivery</h3>
                <p class="text-muted">Optimized dispatch for quicker delivery.</p>
              </div>
            </div>
            <div class="col-md-6 col-lg-4">
              <div class="why-card h-100">
                <i class="bi bi-currency-dollar text-warning"></i>
                <h3 class="h5 mt-2">Affordable Pricing</h3>
                <p class="text-muted">Transparent pricing based on service level.</p>
              </div>
            </div>
            <div class="col-md-6 col-lg-4">
              <div class="why-card h-100">
                <i class="bi bi-envelope-check text-warning"></i>
                <h3 class="h5 mt-2">Email Notifications</h3>
                <p class="text-muted">Get updates at each step of the journey.</p>
              </div>
            </div>
            <div class="col-md-6 col-lg-4">
              <div class="why-card h-100">
                <i class="bi bi-headset text-warning"></i>
                <h3 class="h5 mt-2">Customer Support</h3>
                <p class="text-muted">Help when you need it, right away.</p>
              </div>
            </div>
          </div>

        </div>
      </section>

      <!-- Pricing -->
      <section id="pricing" class="section bg-light">
        <div class="container">
          <div class="section-heading">
            <h2 class="fw-bold">Pricing</h2>
            <p class="text-muted mb-0">Choose a plan that matches your delivery speed.</p>
          </div>

          <div class="row g-4 mt-2">
            <div class="col-md-6 col-lg-4">
              <div class="price-card">
                <div class="price-top">
                  <i class="bi bi-coin" aria-hidden="true"></i>
                  <h3 class="h5">Standard Delivery</h3>
                </div>
                <div class="price-amount">R80</div>
                <div class="price-desc text-muted">Delivery within 3–5 days</div>
                <ul class="list-unstyled price-list">
                  <li><i class="bi bi-check2 text-success"></i> Tracking included</li>
                  <li><i class="bi bi-check2 text-success"></i> Email updates</li>
                  <li><i class="bi bi-check2 text-success"></i> Standard insurance</li>
                </ul>
                <a class="btn btn-primary w-100" href="<?php echo Security::e($basePath); ?>/?page=client_dashboard">Get Started</a>
              </div>
            </div>

            <div class="col-md-6 col-lg-4">
              <div class="price-card featured">
                <div class="ribbon">Popular</div>
                <div class="price-top">
                  <i class="bi bi-lightning-charge" aria-hidden="true"></i>
                  <h3 class="h5">Express Delivery</h3>
                </div>
                <div class="price-amount">R150</div>
                <div class="price-desc text-muted">Delivery within 1–2 days</div>
                <ul class="list-unstyled price-list">
                  <li><i class="bi bi-check2 text-success"></i> Priority dispatch</li>
                  <li><i class="bi bi-check2 text-success"></i> Real-time alerts</li>
                  <li><i class="bi bi-check2 text-success"></i> Enhanced insurance</li>
                </ul>
                <a class="btn btn-warning text-dark w-100" href="<?php echo Security::e($basePath); ?>/?page=client_dashboard">Get Started</a>
              </div>
            </div>

            <div class="col-md-6 col-lg-4">
              <div class="price-card">
                <div class="price-top">
                  <i class="bi bi-globe" aria-hidden="true"></i>
                  <h3 class="h5">International Shipping</h3>
                </div>
                <div class="price-amount">Custom</div>
                <div class="price-desc text-muted">Quote based on destination</div>
                <ul class="list-unstyled price-list">
                  <li><i class="bi bi-check2 text-success"></i> Documentation support</li>
                  <li><i class="bi bi-check2 text-success"></i> Customs updates</li>
                  <li><i class="bi bi-check2 text-success"></i> Global tracking timeline</li>
                </ul>
                <a class="btn btn-primary w-100" href="#contact">Get Started</a>
              </div>
            </div>
          </div>

        </div>
      </section>

      <!-- Testimonials -->
      <section class="section">
        <div class="container">
          <div class="section-heading">
            <h2 class="fw-bold">Testimonials</h2>
            <p class="text-muted mb-0">What customers say about OnTrackX.</p>
          </div>

          <div class="row g-4 mt-2">
            <div class="col-md-4">
              <div class="testi-card h-100">
                <div class="d-flex align-items-center gap-3">
                  <img src="<?php echo Security::e($basePath); ?>/assets/avatar1.png" alt="Customer" class="avatar" loading="lazy" />
                  <div>
                    <div class="fw-semibold">Tumi M.</div>
                    <div class="text-muted small">Verified Customer</div>
                  </div>
                </div>
                <div class="rating mt-3" aria-label="Rating 5 out of 5">
                  <i class="bi bi-star-fill text-warning"></i>
                  <i class="bi bi-star-fill text-warning"></i>
                  <i class="bi bi-star-fill text-warning"></i>
                  <i class="bi bi-star-fill text-warning"></i>
                  <i class="bi bi-star-fill text-warning"></i>
                </div>
                <p class="text-muted mt-3">Tracking updates are instant. My delivery arrived earlier than expected!</p>
              </div>
            </div>

            <div class="col-md-4">
              <div class="testi-card h-100">
                <div class="d-flex align-items-center gap-3">
                  <img src="<?php echo Security::e($basePath); ?>/assets/avatar2.png" alt="Customer" class="avatar" loading="lazy" />
                  <div>
                    <div class="fw-semibold">Kabelo R.</div>
                    <div class="text-muted small">Verified Customer</div>
                  </div>
                </div>
                <div class="rating mt-3" aria-label="Rating 5 out of 5">
                  <i class="bi bi-star-fill text-warning"></i>
                  <i class="bi bi-star-fill text-warning"></i>
                  <i class="bi bi-star-fill text-warning"></i>
                  <i class="bi bi-star-fill text-warning"></i>
                  <i class="bi bi-star-fill text-warning"></i>
                </div>
                <p class="text-muted mt-3">The ordering flow was smooth, and the timeline helped us stay informed.</p>
              </div>
            </div>

            <div class="col-md-4">
              <div class="testi-card h-100">
                <div class="d-flex align-items-center gap-3">
                  <img src="<?php echo Security::e($basePath); ?>/assets/avatar3.png" alt="Customer" class="avatar" loading="lazy" />
                  <div>
                    <div class="fw-semibold">Anita S.</div>
                    <div class="text-muted small">Verified Customer</div>
                  </div>
                </div>
                <div class="rating mt-3" aria-label="Rating 5 out of 5">
                  <i class="bi bi-star-fill text-warning"></i>
                  <i class="bi bi-star-fill text-warning"></i>
                  <i class="bi bi-star-fill text-warning"></i>
                  <i class="bi bi-star-fill text-warning"></i>
                  <i class="bi bi-star-fill text-warning"></i>
                </div>
                <p class="text-muted mt-3">Fast support and clear pricing. Definitely my go-to courier platform.</p>
              </div>
            </div>
          </div>

        </div>
      </section>

      <!-- CTA -->
      <section class="cta">
        <div class="container">
          <div class="cta-box">
            <div class="row align-items-center g-4">
              <div class="col-lg-7">
                <h2 class="fw-bold text-white">Ready to Ship Your Package?</h2>
                <p class="text-white-50 mb-0">
                  Place your order today and track every step of the delivery journey.
                </p>
              </div>
              <div class="col-lg-5 text-lg-end">
                <a class="btn btn-warning text-dark btn-lg" href="<?php echo Security::e($basePath); ?>/?page=client_dashboard">
                  Get Started
                  <i class="bi bi-arrow-right ms-2"></i>
                </a>
              </div>
            </div>
          </div>
        </div>
      </section>

      <!-- Contact + Footer -->
      <section id="contact" class="section bg-light">
        <div class="container">
          <div class="row g-4">
            <div class="col-lg-6">
              <div class="section-heading">
                <h2 class="fw-bold">Contact Us</h2>
                <p class="text-muted mb-0">Questions about services or shipment tracking? We’re here to help.</p>
              </div>

              <div class="contact-card mt-3">
                <form onsubmit="return false;">
                  <div class="row g-3">
                    <div class="col-md-6">
                      <label class="form-label">Full Name</label>
                      <input class="form-control" type="text" placeholder="Your name" />
                    </div>
                    <div class="col-md-6">
                      <label class="form-label">Email</label>
                      <input class="form-control" type="email" placeholder="you@example.com" />
                    </div>
                    <div class="col-12">
                      <label class="form-label">Message</label>
                      <textarea class="form-control" rows="4" placeholder="How can we help?"></textarea>
                    </div>
                    <div class="col-12">
                      <button class="btn btn-primary w-100">Send Message</button>
                      <div class="text-muted small mt-2">This is placeholder UI (wire to backend later).</div>
                    </div>
                  </div>
                </form>
              </div>
            </div>

            <div class="col-lg-6">
              <div class="section-heading">
                <h2 class="fw-bold">Company Information</h2>
                <p class="text-muted mb-0">A modern courier experience built for speed and clarity.</p>
              </div>

              <div class="company-card mt-3">
                <div class="d-flex gap-3 align-items-start">
                  <i class="bi bi-geo-alt-fill text-warning fs-4"></i>
                  <div>
                    <div class="fw-semibold">Head Office</div>
                    <div class="text-muted">123 Delivery Avenue, Logistics City</div>
                  </div>
                </div>
                <hr />
                <div class="d-flex gap-3 align-items-start">
                  <i class="bi bi-telephone-fill text-warning fs-4"></i>
                  <div>
                    <div class="fw-semibold">Phone</div>
                    <div class="text-muted">+27 (0) 00 000 0000</div>
                  </div>
                </div>
                <hr />
                <div class="d-flex gap-3 align-items-start">
                  <i class="bi bi-envelope-fill text-warning fs-4"></i>
                  <div>
                    <div class="fw-semibold">Email</div>
                    <div class="text-muted">support@ontrackx.example</div>
                  </div>
                </div>
                <hr />

                <div class="d-flex gap-3">
                  <a class="social" href="#" aria-label="Facebook"><i class="bi bi-facebook"></i></a>
                  <a class="social" href="#" aria-label="Twitter"><i class="bi bi-twitter-x"></i></a>
                  <a class="social" href="#" aria-label="Instagram"><i class="bi bi-instagram"></i></a>
                  <a class="social" href="#" aria-label="LinkedIn"><i class="bi bi-linkedin"></i></a>
                </div>
              </div>

              <footer class="site-footer mt-4">
                <div class="row g-3">
                  <div class="col-md-4">
                    <div class="fw-semibold mb-2">Quick Links</div>
                    <ul class="list-unstyled mb-0">
                      <li><a class="footer-link" href="#services">Services</a></li>
                      <li><a class="footer-link" href="#pricing">Pricing</a></li>
                      <li><a class="footer-link" href="#tracking">Tracking</a></li>
                    </ul>
                  </div>
                  <div class="col-md-4">
                    <div class="fw-semibold mb-2">Policy</div>
                    <ul class="list-unstyled mb-0">
                      <li><a class="footer-link" href="#">Privacy Policy</a></li>
                      <li><a class="footer-link" href="#">Terms & Conditions</a></li>
                    </ul>
                  </div>
                  <div class="col-md-4">
                    <div class="fw-semibold mb-2">About</div>
                    <div class="text-muted small">© <?php echo date('Y'); ?> OnTrackX. All rights reserved.</div>
                  </div>
                </div>
              </footer>
            </div>
          </div>
        </div>
      </section>

      <!-- Scripts (defer for lighter/ faster first paint) -->
      <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" defer></script>
      <script src="<?php echo Security::e($basePath); ?>/script.js" defer></script>
    </body>
    </html>
    <?php
    $body = ob_get_clean();
    echo $body;
    exit;
}

// If a page parameter is provided, use the app dispatcher.
// Provide helpers to included pages.
if (!function_exists('e')) {
    function e(mixed $v): string { return Security::e($v); }
}


$allowedPublic = [
    'auth/login',
    'auth/register',
    'auth/logout',
];

// Ensure app_base_path is available for included pages.
$GLOBALS['app_base_path'] = $basePath;

$u = Auth::user();
if (!$u && !in_array($page, $allowedPublic, true)) {
    $page = 'auth/login';
}

$map = [
    'admin_dashboard' => __DIR__ . '/admin_dashboard.php',
    'manage_users' => __DIR__ . '/manage_users.php',


    // Admin dashboard AJAX endpoints
    'dashboard_data' => __DIR__ . '/dashboard_data.php',
    'dashboard_charts' => __DIR__ . '/dashboard_charts.php',

    'client_dashboard' => __DIR__ . '/client_dashboard.php',

    'client_place_order' => __DIR__ . '/client_place_order.php',
    'client_my_orders' => __DIR__ . '/my_orders.php',




    // Optional pages (only if you add corresponding files later)
    'client_track' => __DIR__ . '/client_track.php',

    'client_notifications' => __DIR__ . '/client_notifications.php',
    'client_invoices' => __DIR__ . '/client_invoices.php',
    'client_profile' => __DIR__ . '/client_profile.php',
    'order_details_modal' => __DIR__ . '/order_details_modal.php',
    'filter_orders' => __DIR__ . '/filter_orders.php',

    'auth/login' => __DIR__ . '/pages/auth/login.php',

    'auth/register' => __DIR__ . '/pages/auth/register.php',
    'auth/logout' => __DIR__ . '/pages/auth/logout.php',
];

$file = $map[$page] ?? null;
if (!$file || !file_exists($file)) {
    http_response_code(404);
    echo 'Page not found.';
    exit;
}

include $file;

