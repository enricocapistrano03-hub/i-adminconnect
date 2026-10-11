<?php
// ==============================================================================
// i-AdminConnect • Official School Portal & Landing Page
// Public interface featuring School Info, Interactive Map, Resources, and Announcements
// ==============================================================================
if (session_status() === PHP_SESSION_NONE) session_start();

// If user is already authenticated, redirect straight to their dashboard
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}

// Load school announcements dynamically from JSON (zero database changes required)
$announcementsFile = __DIR__ . '/config/announcements.json';
$allAnnouncements = [];
if (file_exists($announcementsFile)) {
    $allAnnouncements = json_decode(file_get_contents($announcementsFile), true) ?: [];
}
$featuredAnnouncement = null;
$regularAnnouncements = [];
foreach ($allAnnouncements as $ann) {
    if (!empty($ann['is_featured']) && $featuredAnnouncement === null) {
        $featuredAnnouncement = $ann;
    } else {
        $regularAnnouncements[] = $ann;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>i-AdminConnect &bull; Official School Portal &amp; Document Tracking System</title>
  
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/style.css?v=<?= time() ?>">
  <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><circle cx=%2250%22 cy=%2250%22 r=%2245%22 fill=%22%232A7C13%22/></svg>">
</head>
<body class="landing-page-body" style="display: block !important; width: 100% !important; margin: 0; padding: 0; overflow-x: hidden;">

  <!-- ============================================================================ -->
  <!-- 1. FROSTED GLASS TOP NAVIGATION BAR                                         -->
  <!-- ============================================================================ -->
  <header class="landing-nav-header">
    <div class="landing-nav-container">
      
      <!-- School Portal Logo -->
      <a href="#home" class="landing-brand">
        <div class="landing-brand-logo">
          <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
            <path d="M22 10v6M2 10l10-5 10 5-10 5z"/>
            <path d="M6 12v5c3 3 9 3 12 0v-5"/>
          </svg>
        </div>
        <div>
          <div class="landing-brand-title">i-AdminConnect</div>
          <div class="landing-brand-subtitle">School Services &amp; Document Tracking <br> For Taguig Science High-School</div>
        </div>
      </a>

      <!-- Desktop Navigation Menu -->
      <nav>
        <ul class="landing-nav-links" id="landingNavLinks">
          <li><a href="#home" class="active">Home</a></li>
          <li><a href="#school-info">School Info</a></li>
          <li><a href="#resources">Resources</a></li>
          <li><a href="#announcements">Announcements</a></li>
          <li><a href="#about-us">About Us</a></li>
        </ul>
      </nav>

      <!-- Portal Login CTA & Mobile Hamburger -->
      <div style="display: flex; align-items: center; gap: 12px;">
        <a href="login.php" class="btn-landing-login">
          <span>🔐</span>
          <span>Sign In / Register</span>
          <span style="font-size: 1.1rem; line-height: 1;">&rarr;</span>
        </a>

        <button type="button" class="landing-mobile-menu-btn" id="landingMobileToggle" aria-label="Toggle navigation">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <line x1="3" y1="12" x2="21" y2="12"></line>
            <line x1="3" y1="6" x2="21" y2="6"></line>
            <line x1="3" y1="18" x2="21" y2="18"></line>
          </svg>
        </button>
      </div>

    </div>
  </header>

  <!-- ============================================================================ -->
  <!-- 2. HERO SECTION                                                              -->
  <!-- ============================================================================ -->
  <section class="landing-hero" id="home">
    <div class="landing-hero-content fade-up">
      <div class="hero-pill-badge">
        <span>🏛️</span>
        <span>Official School Portal &bull; Academic Year 2026&ndash;2027</span>
      </div>

      <h1>Empowering Academic Services with Real-Time Tracking</h1>
      
      <p>
        A centralized, fast, and paperless administrative gateway. Students and faculty can request official certifications, transcripts, and records online with 24/7 stage tracking and direct registrar verification.
      </p>

      <div class="hero-cta-group">
        <a href="login.php" class="btn-hero-primary">
          <span>🚀</span>
          <span>Access Portal &amp; Submit Request</span>
        </a>
        <a href="#announcements" class="btn-hero-secondary">
          <span>📢</span>
          <span>View School Advisories</span>
        </a>
      </div>

      <!-- Hero Glass Stats Row -->
      <div class="hero-stats-row stagger-parent">
        <div class="hero-stat-card">
          <div class="hero-stat-number">4-Stage</div>
          <div class="hero-stat-label">Live Status Tracking</div>
        </div>
        <div class="hero-stat-card">
          <div class="hero-stat-number">100% Free</div>
          <div class="hero-stat-label">Online Application</div>
        </div>
        <div class="hero-stat-card">
          <div class="hero-stat-number">Direct</div>
          <div class="hero-stat-label">Registrar Processing</div>
        </div>
        <div class="hero-stat-card">
          <div class="hero-stat-number">Real-Time</div>
          <div class="hero-stat-label">Philippine Standard Time</div>
        </div>
      </div>
    </div>
  </section>

  <!-- ============================================================================ -->
  <!-- 3. SECTION: SCHOOL INFO & CAMPUS DIRECTORY                                   -->
  <!-- ============================================================================ -->
  <section class="landing-section" id="school-info">
    <div class="section-header fade-up">
      <span class="section-tag">Campus Guide</span>
      <h2 class="section-title">School Information &amp; Campus Directory</h2>
      <p class="section-subtitle">
        Find your way around our campus grounds, locate administrative service windows, and review official operating hours.
      </p>
    </div>

    <div class="school-info-grid">
      
      <!-- Interactive Visual Campus Map Panel (Real Aerial Campus Picture) -->
      <div class="interactive-map-panel">
        <div class="campus-map-art" id="campusMapContainer">
          
          <!-- Aerial 3D Campus Photographic Map (Optimized for 60fps smooth scroll) -->
          <img src="assets/campus_optimized.jpg" alt="Official Campus Map" class="campus-map-img" loading="eager" decoding="async">
          
          <!-- Subtle depth overlay -->
          <div style="position: absolute; inset: 0; background: radial-gradient(circle at center, transparent 45%, rgba(15, 23, 42, 0.4) 100%); pointer-events: none;"></div>


        </div>
      </div>

      <!-- School Vision, Mission & Operating Hours Column -->
      <div style="display: flex; flex-direction: column; justify-content: space-between;" class="stagger-parent">
        
        <!-- Mission & Vision Glass Cards -->
  <div class="glass-card mv-card" style="border-left-color: #35f5f2;">
          <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 8px;">
            <span style="font-size: 1.3rem;">🎯</span>
            <h3 style="margin: 0; font-size: 1.15rem; color: #0f172a; font-weight: 800;">Core Values</h3>
          </div>
          <p style="margin: 0; font-size: 0.92rem; color: #475569; line-height: 1.6;">
            <li>Maka-Diyos</li>
            <li>Maka-Tao</li>
            <li>Makakalikasan</li>
            <li>Makabansa</li>
          </ul>
         </div>


                <!-- Mission & Vision Glass Cards -->
        <div class="glass-card mv-card">
          <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 8px;">
            <span style="font-size: 1.3rem;">🎯</span>
            <h3 style="margin: 0; font-size: 1.15rem; color: #0f172a; font-weight: 800;">Our Mission</h3>
          </div>
          <p style="margin: 0; font-size: 0.92rem; color: #475569; line-height: 1.6;">
            The department aims to protect and promote every Filipino's right to quality, equitable, culture-based, and complete basic education through a collaborative environment involving students, teachers, administrators, and the community.
          </p>
        </div>

        <div class="glass-card mv-card" style="border-left-color: #0284c7;">
          <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 8px;">
            <span style="font-size: 1.3rem;">🌟</span>
            <h3 style="margin: 0; font-size: 1.15rem; color: #0f172a; font-weight: 800;">Our Vision</h3>
          </div>
          <p style="margin: 0; font-size: 0.92rem; color: #475569; line-height: 1.6;">
            DepEd dreams of patriotic Filipinos with the values and competencies to reach their full potential and contribute to nation-building, supported by a continuously improving learner-centered institution.
          </p>
        </div>
        

        

        <!-- Official Operating Hours Banner -->
        <div class="operating-hours-box">
          <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 6px;">
            <span style="font-size: 1.25rem;">⏰</span>
            <h4 style="margin: 0; font-size: 1rem; color: #166534; font-weight: 800;">Official Office Hours</h4>
          </div>
          <div style="font-size: 0.9rem; color: #1e293b; font-weight: 600;">
            Monday to Friday &bull; 8:00 AM &ndash; 5:00 PM
          </div>
          <div style="font-size: 0.8rem; color: #4b5563; margin-top: 4px;">
            *Online request submissions in i-AdminConnect are accepted 24/7. Physical claiming windows operate during official campus hours.
          </div>
        </div>

      </div>

    </div>

    <!-- ======================================================================== -->
    <!-- Executive School Leadership Messages                                     -->
    <!-- ======================================================================== -->
    <div class="leadership-section-wrapper fade-up">
      <div style="text-align: center; max-width: 650px; margin: 0 auto 28px;">
        <span class="section-tag" style="background: #ecfdf5; color: #166534;">Executive Leadership</span>
        <h3 style="font-size: 1.45rem; font-weight: 800; color: #0f172a; margin: 8px 0 0 0;">School Leadership &amp; Directives</h3>
        <p style="font-size: 0.9rem; color: #64748b; margin-top: 6px;">
          Inspiring messages and official guidance from our campus administrators. Click a card to read their full welcome message.
        </p>
      </div>

      <div class="leadership-grid stagger-parent">
        
        <!-- Leadership Card 1: School Principal -->
        <div class="glass-card leadership-card" onclick="openLeadershipModal('principal')">
          <div class="leadership-card-badge">School Prinsipal</div>
          <div class="leadership-avatar-wrapper">
            <img src="assets/Donald.PNG" alt="School Principal" class="leadership-avatar-img" onerror="this.src='assets/image1.jpeg'">
            <div class="leadership-deped-seal">🏛️</div>
          </div>
          <div class="leadership-card-body">
            <h4 class="leadership-name">Donald S. Bruno</h4>
            <div class="leadership-role-title">School Principal &bull; TagSci</div>
            <p class="leadership-quote">
              &ldquo; INSERT MESSAGE HERE &rdquo;
            </p>
            <button type="button" class="btn-read-message">
              <span>📜 Read Welcome Message</span>
              <span>&rarr;</span>
            </button>
          </div>
        </div>

        <!-- Leadership Card 2: Assistant School Principal -->
        <div class="glass-card leadership-card" onclick="openLeadershipModal('asst_principal')">
          <div class="leadership-card-badge" style="background: #eff6ff; color: #1d4ed8; border-color: #bfdbfe;">Assitant School Principal</div>
          <div class="leadership-avatar-wrapper" style="border-color: #38bdf8;">
            <img src="assets/Assistant.jpg" alt="Assistant School Principal" class="leadership-avatar-img" onerror="this.src='assets/image1.jpeg'">
            
          </div>
          <div class="leadership-card-body">
            <h4 class="leadership-name">Janeth G. Mamasigan</h4>
            <div class="leadership-role-title">Assistant School Principal  &bull; TagSci</div>
            <p class="leadership-quote">
              &ldquo;INSERT MESSAGE HERE.&rdquo;
            </p>
            <button type="button" class="btn-read-message" style="background: linear-gradient(135deg, #0284c7 0%, #0d3b66 100%);">
              <span>📜 Read Welcome Message</span>
              <span>&rarr;</span>
            </button>
          </div>
        </div>

      </div>
    </div>

  </section>

  <!-- ============================================================================ -->
  <!-- 4. SECTION: RESOURCES & STEP-BY-STEP GUIDELINES                              -->
  <!-- ============================================================================ -->
  <section class="landing-section" id="resources">
    <div class="section-header fade-up">
      <span class="section-tag" style="background: #e0f2fe; color: #0284c7;">Student &amp; Faculty Hub</span>
      <h2 class="section-title">Document Resources &amp; Guidelines</h2>
      <p class="section-subtitle">
        Everything you need to know before submitting a credential request, from required documents to claiming protocols.
      </p>
    </div>

    <!-- 4-Step Infographic Flowchart Banner -->
    <div class="flowchart-wrapper fade-up">
      <div style="text-align: center; max-width: 650px; margin: 0 auto;">
        <h3 style="margin: 0; font-size: 1.45rem; font-weight: 800; color: #ffffff;">How to Request &amp; Track Documents</h3>
        <p style="margin: 6px 0 0 0; font-size: 0.9rem; color: #94a3b8;">
          Four simple steps to complete your document applications without the hassle.
        </p>
      </div>

      <div class="flowchart-grid stagger-parent">
        <div class="flowchart-step">
          <div class="flowchart-number">1</div>
          <h4 style="margin: 0 0 8px 0; font-size: 1.05rem; font-weight: 700;">Submit Online</h4>
          <p style="margin: 0; font-size: 0.82rem; color: #cbd5e1; line-height: 1.5;">
            Log into your portal account, choose the document type, and attach your supporting file (PDF or Image).
          </p>
        </div>

        <div class="flowchart-step">
          <div class="flowchart-number">2</div>
          <h4 style="margin: 0 0 8px 0; font-size: 1.05rem; font-weight: 700;">Staff Verification</h4>
          <p style="margin: 0; font-size: 0.82rem; color: #cbd5e1; line-height: 1.5;">
            The Registrar (for Students) or Administrator (for Teachers) verifies requirements and reviews request validity.
          </p>
        </div>

        <div class="flowchart-step">
          <div class="flowchart-number">3</div>
          <h4 style="margin: 0 0 8px 0; font-size: 1.05rem; font-weight: 700;">Processing &amp; Sealing</h4>
          <p style="margin: 0; font-size: 0.82rem; color: #cbd5e1; line-height: 1.5;">
            Your academic credential is prepared, authenticated, and signed. Track live stage updates on your phone or PC.
          </p>
        </div>

        <div class="flowchart-step">
          <div class="flowchart-number">4</div>
          <h4 style="margin: 0 0 8px 0; font-size: 1.05rem; font-weight: 700;">Release &amp; Acknowledge</h4>
          <p style="margin: 0; font-size: 0.82rem; color: #cbd5e1; line-height: 1.5;">
            Claim the physical hard copy at the office or download the digital release, then mark the transaction as received.
          </p>
        </div>
      </div>
    </div>

    <!-- Frequently Asked Questions (FAQ Accordion) -->
    <div style="margin-top: 60px;" class="fade-up">
      <div style="text-align: center; margin-bottom: 30px;">
        <h3 style="font-size: 1.45rem; font-weight: 800; color: #0f172a; margin: 0;">Frequently Asked Questions (FAQ)</h3>
        <p style="font-size: 0.92rem; color: #64748b; margin-top: 6px;">Common inquiries regarding request processing and claiming procedures.</p>
      </div>

      <div class="faq-container">
        <div class="faq-item active">
          <div class="faq-question" onclick="toggleFaq(this)">
            <span>Are there any processing fees for requesting academic records?</span>
            <span class="faq-toggle-icon">&plus;</span>
          </div>
          <div class="faq-answer">
            No. All document requests submitted through the i-AdminConnect portal (Form 137, Good Moral Certificate, Certificate of Enrollment, Certificate of Employment, etc.) are <strong>100% FREE of charge</strong> with no hidden processing fees.
          </div>
        </div>

        <div class="faq-item">
          <div class="faq-question" onclick="toggleFaq(this)">
            <span>How long does it take for a document to be processed and ready?</span>
            <span class="faq-toggle-icon">&plus;</span>
          </div>
          <div class="faq-answer">
            Typical processing takes <strong>1 to 3 business days</strong> depending on document evaluation and registrar signing. You can monitor the real-time progress on your portal dashboard with live stage timers.
          </div>
        </div>

        <div class="faq-item">
          <div class="faq-question" onclick="toggleFaq(this)">
            <span>How do I track my submitted request in real-time?</span>
            <span class="faq-toggle-icon">&plus;</span>
          </div>
          <div class="faq-answer">
            Simply sign in to your student or faculty account, click the <strong>"Track"</strong> button on your dashboard or <em>My Requests</em> tab, and a live progress timeline in Philippine Standard Time will display every step of your application.
          </div>
        </div>
      </div>
    </div>

  </section>

  <!-- ============================================================================ -->
  <!-- 5. SECTION: LATEST ANNOUNCEMENTS & BULLETIN BOARD                           -->
  <!-- ============================================================================ -->
  <section class="landing-section" id="announcements" style="background: #f1f5f9; max-width: 100%; padding-left: 24px; padding-right: 24px;">
    <div style="max-width: 1240px; margin: 0 auto;">
      
      <div class="section-header fade-up">
        <span class="section-tag" style="background: #fef3c7; color: #b45309;">Campus Bulletin</span>
        <h2 class="section-title">Latest School Announcements &amp; Circulars</h2>
        <p class="section-subtitle">
          Important circulars, registrar office advisories, document release schedules, and institutional dates.
        </p>
      </div>

      <!-- Category Filter Pills -->
      <div class="announcement-tabs fade-up">
        <button class="ann-tab-btn active" onclick="filterAnnouncements('all', this)">All Updates</button>
        <button class="ann-tab-btn" onclick="filterAnnouncements('registrar', this)">Registrar Advisories</button>
        <button class="ann-tab-btn" onclick="filterAnnouncements('academic', this)">Academic Schedules</button>
        <button class="ann-tab-btn" onclick="filterAnnouncements('clearance', this)">Clearance Notices</button>
      </div>

      <!-- Featured Urgent Announcement Banner (Dynamic) -->
      <?php if ($featuredAnnouncement): ?>
      <div class="announcement-featured-banner fade-up">
        <div style="max-width: 780px;">
          <div style="font-size: 0.82rem; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; color: #86efac; margin-bottom: 6px;">
            📢 <?= htmlspecialchars($featuredAnnouncement['category_label'] ?? 'Official Notice') ?> &bull; <?= htmlspecialchars($featuredAnnouncement['date'] ?? '') ?>
          </div>
          <h3 style="margin: 0; font-size: 1.45rem; font-weight: 800; color: #ffffff;">
            <?= htmlspecialchars($featuredAnnouncement['title'] ?? '') ?>
          </h3>
          <p style="margin: 8px 0 0 0; font-size: 0.9rem; color: #e2e8f0; line-height: 1.5;">
            <?= htmlspecialchars($featuredAnnouncement['summary'] ?? '') ?>
          </p>
        </div>
        <div>
          <a href="login.php" class="btn-hero-primary" style="padding: 10px 22px; font-size: 0.88rem; box-shadow: none;">
            Check My Request &rarr;
          </a>
        </div>
      </div>
      <?php endif; ?>

      <!-- Announcements Cards Grid (Dynamic from config/announcements.json) -->
      <div class="announcements-grid stagger-parent">
        <?php if (!empty($regularAnnouncements)): ?>
          <?php foreach ($regularAnnouncements as $ann): ?>
          <div class="glass-card announcement-card" data-category="<?= htmlspecialchars($ann['category'] ?? 'all') ?>">
            <div>
              <div class="ann-date-badge">
                <span>📅</span> <span><?= htmlspecialchars($ann['date'] ?? '') ?></span>
              </div>
              <span class="badge-status <?= htmlspecialchars($ann['category_class'] ?? 'processing') ?>" style="margin-bottom: 12px; display: inline-block;">
                <?= htmlspecialchars($ann['category_label'] ?? 'Announcement') ?>
              </span>
              <h4 style="margin: 0 0 8px 0; font-size: 1.08rem; color: #0f172a; font-weight: 800;">
                <?= htmlspecialchars($ann['title'] ?? '') ?>
              </h4>
              <p style="margin: 0; font-size: 0.85rem; color: #64748b; line-height: 1.6;">
                <?= htmlspecialchars($ann['summary'] ?? '') ?>
              </p>
            </div>
            <div style="margin-top: 16px; font-size: 0.82rem; color: #2A7C13; font-weight: 700;">
              <?= htmlspecialchars($ann['category_label'] ?? 'School Notice') ?> &bull; Read More &rarr;
            </div>
          </div>
          <?php endforeach; ?>
        <?php else: ?>
          <div class="glass-card" style="grid-column: 1 / -1; text-align: center; padding: 40px;">
            <p style="color: #64748b; margin: 0; font-size: 0.95rem;">No current advisories posted. Please check back later.</p>
          </div>
        <?php endif; ?>
      </div>

    </div>
  </section>

  <!-- ============================================================================ -->
  <!-- 6. SECTION: ABOUT US & PROJECT DEVELOPMENT TEAM                              -->
  <!-- ============================================================================ -->
  <section class="landing-section" id="about-us">
    <div class="section-header fade-up">
      <span class="section-tag" style="background: #dcfce7; color: #166534;">System Overview</span>
      <h2 class="section-title">About i-AdminConnect</h2>
      <p class="section-subtitle">
        A Web-Based Administrative Services and Records Tracking System designed to innovate institutional workflow, document archiving, and student services.
      </p>
    </div>

    <!-- System Highlights Grid -->
    <div class="about-stats-grid stagger-parent">
      <div class="glass-card" style="text-align: center;">
        <div style="font-size: 2.2rem; margin-bottom: 10px;">⚡</div>
        <h4 style="margin: 0 0 8px 0; font-size: 1.1rem; color: #0f172a; font-weight: 800;">Fast &amp; Queue-Free</h4>
        <p style="margin: 0; font-size: 0.85rem; color: #64748b; line-height: 1.6;">
          Eliminates paper forms and crowded windows by digitalizing submissions and instant notifications.
        </p>
      </div>

      <div class="glass-card" style="text-align: center;">
        <div style="font-size: 2.2rem; margin-bottom: 10px;">🛡️</div>
        <h4 style="margin: 0 0 8px 0; font-size: 1.1rem; color: #0f172a; font-weight: 800;">Strict Role Separation</h4>
        <p style="margin: 0; font-size: 0.85rem; color: #64748b; line-height: 1.6;">
          Student requests route solely to the Registrar; Teacher requests route solely to the Administrator.
        </p>
      </div>

      <div class="glass-card" style="text-align: center;">
        <div style="font-size: 2.2rem; margin-bottom: 10px;">📊</div>
        <h4 style="margin: 0 0 8px 0; font-size: 1.1rem; color: #0f172a; font-weight: 800;">Transparent Audit Logs</h4>
        <p style="margin: 0; font-size: 0.85rem; color: #64748b; line-height: 1.6;">
          Every transaction and administrative action is permanently logged with accurate timestamps and user tracking.
        </p>
      </div>
    </div>

    <!-- Development Team Showcase -->
    <div style="margin-top: 60px;" class="fade-up">
      <div style="text-align: center; margin-bottom: 30px;">
        <h3 style="font-size: 1.45rem; font-weight: 800; color: #0f172a; margin: 0;">Project Development Team</h3>
        <p style="font-size: 0.92rem; color: #64748b; margin-top: 6px;">
          Meet the research authors and software development team behind i-AdminConnect.
        </p>
      </div>

      <div class="team-grid stagger-parent">
        <div class="glass-card team-card">
          <div class="team-avatar">
            <img src="assets/capistrano.PNG" alt="Enrico C. Capistrano" class="team-avatar-img" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
            <span class="team-avatar-fallback" style="display:none;">EC</span>
          </div>
          <h4 style="margin: 0 0 4px 0; font-size: 0.98rem; font-weight: 800; color: #0f172a;">Enrico C. Capistrano</h4>
          <span style="font-size: 0.76rem; color: #2A7C13; font-weight: 700;">Research Author</span>
        </div>

        <div class="glass-card team-card">
          <div class="team-avatar">
            <img src="assets/edwin_cruz.JPG" alt="Edwin E. Cruz" class="team-avatar-img" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
            <span class="team-avatar-fallback" style="display:none;">EC</span>
          </div>
          <h4 style="margin: 0 0 4px 0; font-size: 0.98rem; font-weight: 800; color: #0f172a;">Edwin E. Cruz</h4>
          <span style="font-size: 0.76rem; color: #2A7C13; font-weight: 700;">Research Author</span>
        </div>

        <div class="glass-card team-card">
          <div class="team-avatar">
            <img src="assets/Fatima.JPEG" alt="Erginia Fatima G. Diansuy" class="team-avatar-img" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
            <span class="team-avatar-fallback" style="display:none;">ED</span>
          </div>
          <h4 style="margin: 0 0 4px 0; font-size: 0.98rem; font-weight: 800; color: #0f172a;">Erginia Fatima G. Diansuy</h4>
          <span style="font-size: 0.76rem; color: #2A7C13; font-weight: 700;">Research Author</span>
        </div>
      </div>
    </div>

    <!-- Campus Helpdesk & Contact Information -->
    <div style="margin-top: 50px;" class="fade-up">
      <div class="glass-card helpdesk-grid">
        <div>
          <span class="section-tag" style="background: #e0f2fe; color: #0284c7; margin-bottom: 10px;">Contact Helpdesk</span>
          <h3 style="margin: 0 0 12px 0; font-size: 1.35rem; font-weight: 800; color: #0f172a;">Campus Support &amp; Information</h3>
          <p style="margin: 0 0 20px 0; font-size: 0.9rem; color: #64748b; line-height: 1.6;">
            Have questions regarding your credentials, school records, or portal account access? Get in touch with our administrative staff.
          </p>

          <div style="display: flex; flex-direction: column; gap: 14px; font-size: 0.92rem;">
            <div style="display: flex; align-items: center; gap: 12px;">
              <span style="font-size: 1.2rem;">📍</span>
              <div>
                <strong>Campus Location:</strong>
                <div style="color: #64748b; font-size: 0.85rem;">M.L. Quezon Ave., Brgy. San Miguel, Taguig City</div>
              </div>
            </div>

            <div style="display: flex; align-items: center; gap: 12px;">
              <span style="font-size: 1.2rem;">📞</span>
              <div>
                <strong>Registrar Office Hotline:</strong>
                <div style="color: #64748b; font-size: 0.85rem;">(02) 8244-5346</div>
              </div>
            </div>

            <div style="display: flex; align-items: center; gap: 12px;">
              <span style="font-size: 1.2rem;">📧</span>
              <div>
                <strong>Official Email:</strong>
                <div style="color: #64748b; font-size: 0.85rem;">registrar@adminconnect.edu.ph  / tagscihs2004@deped.gov.ph</div>
              </div>
            </div>
          </div>
        </div>

        <!-- Quick Portal CTA Card -->
        <div style="background: linear-gradient(135deg, #0d3b66 0%, #2A7C13 100%); color: #ffffff; border-radius: 16px; padding: 30px; display: flex; flex-direction: column; justify-content: space-between;">
          <div>
            <div style="font-size: 0.8rem; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; color: #00bfa5; margin-bottom: 6px;">Ready to Begin?</div>
            <h3 style="margin: 0; font-size: 1.4rem; font-weight: 800; color: #ffffff;">Log in to the i-AdminConnect Portal</h3>
            <p style="margin: 10px 0 0 0; font-size: 0.88rem; color: #e2e8f0; line-height: 1.6;">
              Sign in with your registered Student, Teacher, Registrar, or Administrator account to submit or process document requests.
            </p>
          </div>

          <div style="margin-top: 24px;">
            <a href="login.php" class="btn-hero-primary" style="background: #ffffff; color: #0d3b66; width: 100%; justify-content: center; box-shadow: none;">
              Go to Portal Login &rarr;
            </a>
          </div>
        </div>

      </div>
    </div>

  </section>

  <!-- ============================================================================ -->
  <!-- 7. INSTITUTIONAL FOOTER                                                      -->
  <!-- ============================================================================ -->
  <footer class="landing-footer">
    <div class="footer-container">
      
      <div>
        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 14px;">
          <div class="landing-brand-logo" style="width: 36px; height: 36px;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
              <path d="M22 10v6M2 10l10-5 10 5-10 5z"/>
              <path d="M6 12v5c3 3 9 3 12 0v-5"/>
            </svg>
          </div>
          <span style="font-size: 1.2rem; font-weight: 800; color: #ffffff;">i-AdminConnect</span>
        </div>
        <p style="margin: 0; font-size: 0.84rem; line-height: 1.6; color: #94a3b8;">
          A Web-Based Administrative Services and Records Tracking System. Modernizing student records and faculty document processing with speed, security, and integrity.
        </p>
      </div>

      <div>
        <h4 style="color: #ffffff; font-size: 0.95rem; font-weight: 700; margin: 0 0 16px 0;">Quick Links</h4>
        <ul style="list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 10px; font-size: 0.85rem;">
          <li><a href="#home" style="color: #94a3b8; text-decoration: none;">Home</a></li>
          <li><a href="#school-info" style="color: #94a3b8; text-decoration: none;">School Info &amp; Map</a></li>
          <li><a href="#resources" style="color: #94a3b8; text-decoration: none;">Downloadable Resources</a></li>
          <li><a href="#announcements" style="color: #94a3b8; text-decoration: none;">Announcements</a></li>
        </ul>
      </div>

      <div>
        <h4 style="color: #ffffff; font-size: 0.95rem; font-weight: 700; margin: 0 0 16px 0;">Portal Services</h4>
        <ul style="list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 10px; font-size: 0.85rem;">
          <li><a href="login.php" style="color: #94a3b8; text-decoration: none;">Student Registration</a></li>
          <li><a href="login.php" style="color: #94a3b8; text-decoration: none;">Teacher Sign In</a></li>
          <li><a href="login.php" style="color: #94a3b8; text-decoration: none;">Registrar Portal</a></li>
          <li><a href="login.php" style="color: #94a3b8; text-decoration: none;">Administrator Access</a></li>
        </ul>
      </div>

      <div>
        <h4 style="color: #ffffff; font-size: 0.95rem; font-weight: 700; margin: 0 0 16px 0;">Operating Hours</h4>
        <p style="margin: 0 0 8px 0; font-size: 0.85rem; color: #cbd5e1;">
          <strong>Monday &ndash; Friday:</strong> 8:00 AM &ndash; 5:00 PM
        </p>
        <p style="margin: 0; font-size: 0.82rem; color: #64748b;">
          Online requests are open 24/7 through the portal.
        </p>
      </div>

    </div>

    <div class="footer-bottom">
      <div>
        &copy; <?= date('Y') ?> i-AdminConnect Portal &bull; Office of the Registrar and Administration. All rights reserved.
      </div>
      <div>
        <a href="#home" style="color: #38bdf8; text-decoration: none; font-weight: 700;">&uarr; Back to Top</a>
      </div>
    </div>
  </footer>

  <!-- ============================================================================ -->
  <!-- JAVASCRIPT CONTROLLERS FOR LANDING PAGE INTERACTIVITY                       -->
  <!-- ============================================================================ -->
  <script>
    // 1. Mobile Navigation Menu Toggle
    const mobileBtn = document.getElementById('landingMobileToggle');
    const navLinks = document.getElementById('landingNavLinks');
    if (mobileBtn && navLinks) {
      mobileBtn.addEventListener('click', () => {
        navLinks.classList.toggle('mobile-active');
      });
      // Close menu when link is clicked
      navLinks.querySelectorAll('a').forEach(link => {
        link.addEventListener('click', () => navLinks.classList.remove('mobile-active'));
      });
    }

    // 2. Interactive Campus Map Details
    const buildingData = {
      registrar: {
        tag: 'Academic Records Hub',
        title: "Office of the Registrar",
        desc: "Window 1: Inquiries & Verification • Window 2: Document Releasing (Ground Floor, Admin Wing)"
      },
      admin: {
        tag: 'Executive & Faculty Offices',
        title: "Administration Building",
        desc: "Principal & President's Office, HR, Faculty Lounge, and Teacher Evaluation Records (2nd Floor)"
      },
      library: {
        tag: 'Learning & Resource Center',
        title: "Campus Library & Learning Commons",
        desc: "Research archives, study halls, book borrowing, and digital catalog terminals (Central Wing)"
      },
      guidance: {
        tag: 'Student Welfare & Services',
        title: "Guidance & Counseling Center",
        desc: "Issuance of Good Moral Clearances, Student Advisory, and Counseling Services (Building B)"
      },
      gate: {
        tag: 'Campus Security & Entry',
        title: "Main Campus Gate & Security Desk",
        desc: "Visitor Logbook, ID Scanning, and Student Verification Entrance Gate"
      }
    };

    function showBuildingInfo(key) {
      const info = buildingData[key];
      if (!info) return;
      document.getElementById('mapPopupTag').textContent = info.tag;
      document.getElementById('mapPopupTitle').textContent = info.title;
      document.getElementById('mapPopupDesc').textContent = info.desc;
    }

    // 3. FAQ Accordion Toggle
    function toggleFaq(element) {
      const item = element.parentElement;
      const isActive = item.classList.contains('active');
      // Close all FAQs
      document.querySelectorAll('.faq-item').forEach(f => f.classList.remove('active'));
      // Toggle clicked item
      if (!isActive) {
        item.classList.add('active');
      }
    }

    // 4. Announcements Category Filter
    function filterAnnouncements(category, btn) {
      // Toggle active tab style
      document.querySelectorAll('.ann-tab-btn').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');

      const cards = document.querySelectorAll('.announcement-card');
      cards.forEach(card => {
        if (category === 'all' || card.getAttribute('data-category') === category) {
          card.style.display = 'flex';
        } else {
          card.style.display = 'none';
        }
      });
    }

    // 5. Active Nav Link on Scroll (Throttled with requestAnimationFrame for 60fps butter-smooth scroll)
    let isTicking = false;
    const pageSections = document.querySelectorAll('section[id]');
    const navAnchors = document.querySelectorAll('.landing-nav-links a');

    function updateActiveNav() {
      let current = '';
      const scrollPos = (window.pageYOffset || document.documentElement.scrollTop) + 140;

      pageSections.forEach(section => {
        if (scrollPos >= section.offsetTop) {
          current = section.getAttribute('id');
        }
      });

      navAnchors.forEach(link => {
        link.classList.remove('active');
        if (link.getAttribute('href') === '#' + current) {
          link.classList.add('active');
        }
      });
    }

    window.addEventListener('scroll', () => {
      if (!isTicking) {
        window.requestAnimationFrame(() => {
          updateActiveNav();
          isTicking = false;
        });
        isTicking = true;
      }
    }, { passive: true });

    // 6. Smooth In-Page Anchor Navigation Click Handler
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
      anchor.addEventListener('click', function (e) {
        const targetId = this.getAttribute('href');
        if (targetId && targetId !== '#') {
          const targetEl = document.querySelector(targetId);
          if (targetEl) {
            e.preventDefault();
            const headerOffset = 76;
            const targetPos = targetEl.getBoundingClientRect().top + window.pageYOffset - headerOffset;
            window.scrollTo({
              top: targetPos,
              behavior: 'smooth'
            });
          }
        }
      });
    });

    // 7. Snappy Scroll Fade-In Observer
    const observerOptions = {
      root: null,
      rootMargin: '0px 0px -40px 0px',
      threshold: 0.08
    };

    const fadeObserver = new IntersectionObserver((entries, observer) => {
      entries.forEach(entry => {
        if (entry.isIntersecting) {
          entry.target.classList.add('visible');
          observer.unobserve(entry.target);
        }
      });
    }, observerOptions);

    document.querySelectorAll('.fade-up, .stagger-parent').forEach(el => {
      fadeObserver.observe(el);
    });

    // 8. Leadership Message Modal Controller (Option A)
    const leadershipData = {
      principal: {
        pill: "Office of the School Principal",
        name: "Donald S. Bruno",
        title: "School Principal• Taguig Science High School",
        avatar: "assets/Donald.PNG",
        greeting: "To Our Dear TagSci Learners, Respected Faculty, Supportive Parents, and Alumni,",
        paragraphs: [
          "1st sentence sample message here",
          "2nd sentence sample message here.",
          "3rd sentence sample message here"
        ],
        sigName: "Donald S. Bruno",
        sigTitle: "School Principal • Division of Taguig City and Pateros"
      },
      asst_principal: {
        pill: "Assistant School Principal",
        name: "Janeth G. Mamansag",
        title: "Assistant School Principal  • Taguig Science High School",
        avatar: "assets/Assistant.JPG",
        greeting: "Warm Greetings to the Entire TagSci Academic Community,",
        paragraphs: [
          "1st sentence sample message here.",
          "2nd sentence sample message here.",
          "3rd sentence sample message here"
        ],
        sigName: "Janeth G. Mamansag",
        sigTitle: "Assistant School Principal  • Taguig Science High School"
      }
    };

    function openLeadershipModal(key) {
      const data = leadershipData[key];
      if (!data) return;

      document.getElementById('leadModalImg').src = data.avatar;
      document.getElementById('leadModalPill').textContent = data.pill;
      document.getElementById('leadModalName').textContent = data.name;
      document.getElementById('leadModalTitle').textContent = data.title;
      document.getElementById('leadModalGreeting').textContent = data.greeting;
      
      const textContainer = document.getElementById('leadModalText');
      textContainer.innerHTML = '';
      data.paragraphs.forEach(p => {
        const pElem = document.createElement('p');
        pElem.textContent = p;
        textContainer.appendChild(pElem);
      });

      document.getElementById('leadModalSigName').textContent = data.sigName;
      document.getElementById('leadModalSigSub').textContent = data.sigTitle;

      const modal = document.getElementById('leadershipModal');
      modal.classList.add('active');
      document.body.style.overflow = 'hidden';
    }

    function closeLeadershipModal(e) {
      if (e && e.target && e.target !== document.getElementById('leadershipModal') && !e.target.classList.contains('leadership-modal-close') && !e.target.classList.contains('btn-close-modal')) {
        return;
      }
      const modal = document.getElementById('leadershipModal');
      modal.classList.remove('active');
      document.body.style.overflow = '';
    }

    // Close on Escape key
    window.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') {
        const modal = document.getElementById('leadershipModal');
        if (modal && modal.classList.contains('active')) {
          closeLeadershipModal();
        }
      }
    });
  </script>

  <!-- ============================================================================ -->
  <!-- LEADERSHIP MESSAGE MODAL (OPTION A)                                          -->
  <!-- ============================================================================ -->
  <div class="leadership-modal-overlay" id="leadershipModal" onclick="closeLeadershipModal(event)">
    <div class="leadership-modal-card" onclick="event.stopPropagation()">
      <button type="button" class="leadership-modal-close" onclick="closeLeadershipModal()">&times;</button>
      
      <div class="leadership-modal-header">
        <div class="leadership-modal-avatar-box">
          <img src="assets/Donald.PNG" id="leadModalImg" alt="Leader Portrait" class="leadership-modal-avatar">
        </div>
        <div>
          <div class="leadership-modal-pill" id="leadModalPill">OFFICE OF THE PRINCIPAL</div>
          <h3 class="leadership-modal-name" id="leadModalName">Donald S. Bruno</h3>
          <div class="leadership-modal-title" id="leadModalTitle">School Principal IV &bull; Taguig Science High School</div>
        </div>
      </div>

      <div class="leadership-modal-body">
        <div class="leadership-modal-greeting" id="leadModalGreeting">Dear Students, Faculty, and Parents,</div>
        <div class="leadership-modal-text" id="leadModalText"></div>
      </div>

      <div class="leadership-modal-footer">
        <div class="leadership-modal-signature">
          <div class="lead-sig-line"></div>
          <div class="lead-sig-name" id="leadModalSigName">Dr. Maria Elena S. Santos</div>
          <div class="lead-sig-sub" id="leadModalSigSub">Official Academic Directives &bull; DepEd Division of Taguig City and Pateros</div>
        </div>
        <button type="button" class="btn-close-modal" onclick="closeLeadershipModal()">Close Message</button>
      </div>
    </div>
  </div>

</body>
</html>
