    </div><!-- /#view-body -->
  </main>
</div><!-- /#app-container -->

<!-- Reusable Request Tracking Modal (Slide 14) -->
<div id="tracking-modal" class="modal-overlay" style="display: none;">
  <div id="tracking-modal-content" class="modal-dialog">
    <!-- Populated dynamically via AJAX in js/main.js -->
  </div>
</div>

<!-- Session Timeout Inactivity Warning Modal (15-Minute Security Guard) -->
<div id="session-timeout-modal" class="modal-overlay" style="display: none; z-index: 99999;">
  <div class="modal-dialog" style="max-width: 440px; text-align: center; padding: 32px 28px; border-radius: 16px; box-shadow: 0 20px 40px rgba(0,0,0,0.25); background: #ffffff;">
    <div style="width: 64px; height: 64px; background: #fef2f2; border: 2px solid #fecaca; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 2rem; margin: 0 auto 16px auto;">
      🛡️
    </div>
    <h3 style="font-size: 1.25rem; font-weight: 800; color: #0f172a; margin-bottom: 8px;">Session Inactivity Warning</h3>
    <p style="font-size: 0.88rem; color: #64748b; line-height: 1.5; margin-bottom: 20px;">
      You have been inactive for over 13 minutes. For your security and to prevent unauthorized access, your session will automatically log out in:
    </p>
    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 14px; margin-bottom: 24px;">
      <span id="session-countdown-timer" style="font-size: 2.2rem; font-weight: 800; color: #dc2626; font-family: monospace; letter-spacing: 2px;">02:00</span>
      <div style="font-size: 0.75rem; color: #94a3b8; font-weight: 600; text-transform: uppercase; margin-top: 4px;">Minutes : Seconds remaining</div>
    </div>
    <div style="display: flex; gap: 12px; justify-content: center; flex-wrap: wrap;">
      <button type="button" id="btn-stay-logged-in" class="btn-primary-cta" style="padding: 10px 22px; font-size: 0.9rem; font-weight: 700; border-radius: 10px;">
        Stay Logged In
      </button>
      <a href="logout.php?timeout=1" class="btn-primary-cta" style="background: #e2e8f0; color: #475569; padding: 10px 20px; font-size: 0.9rem; font-weight: 700; text-decoration: none; border-radius: 10px; box-shadow: none;">
        Log Out Now
      </a>
    </div>
  </div>
</div>

<script src="js/main.js?v=<?= filemtime(__DIR__ . '/../js/main.js') ?>"></script>
</body>
</html>
