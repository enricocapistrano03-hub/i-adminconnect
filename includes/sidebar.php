<?php
$current_page = basename($_SERVER['PHP_SELF']);
$role = $_SESSION['role'] ?? 'student';
?>
<aside class="sidebar" id="appSidebar">
  <button type="button" class="sidebar-close-btn" id="sidebarCloseBtn" aria-label="Close menu">&times;</button>
  <div class="sidebar-logo-container">
    <div class="sidebar-logo-circle">
      <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
        <path d="M22 10v6M2 10l10-5 10 5-10 5z"/>
        <path d="M6 12v5c3 3 9 3 12 0v-5"/>
      </svg>
    </div>
    <div class="sidebar-brand-name">i-AdminConnect</div>
    <div class="sidebar-brand-desc">Administrative Services and Record Tracking System</div>
  </div>

  <nav class="sidebar-nav">
    <?php if ($role === 'admin'): ?>
      <a href="dashboard.php" class="nav-item <?= $current_page === 'dashboard.php' ? 'active' : '' ?>">
        <span>Dashboard</span>
      </a>
      <a href="manage_requests.php" class="nav-item <?= in_array($current_page, ['manage_requests.php', 'request_details.php']) ? 'active' : '' ?>">
        <span>Requests</span>
      </a>
      <a href="records.php" class="nav-item <?= $current_page === 'records.php' ? 'active' : '' ?>">
        <span>Records</span>
      </a>
      <a href="users.php" class="nav-item <?= $current_page === 'users.php' ? 'active' : '' ?>">
        <span>Users</span>
      </a>
      <a href="reports.php" class="nav-item <?= $current_page === 'reports.php' ? 'active' : '' ?>">
        <span>Reports</span>
      </a>
      <a href="audit_logs.php" class="nav-item <?= $current_page === 'audit_logs.php' ? 'active' : '' ?>">
        <span>Audit Logs</span>
      </a>
    <?php elseif ($role === 'registrar'): ?>
      <a href="dashboard.php" class="nav-item <?= $current_page === 'dashboard.php' ? 'active' : '' ?>">
        <span>View Dashboard</span>
      </a>
      <a href="assigned_requests.php" class="nav-item <?= in_array($current_page, ['assigned_requests.php', 'request_details.php']) ? 'active' : '' ?>">
        <span>View Assigned Request</span>
      </a>
      <a href="notifications.php" class="nav-item <?= $current_page === 'notifications.php' ? 'active' : '' ?>">
        <span>Notifications</span>
      </a>
    <?php else: ?>
      <a href="dashboard.php" class="nav-item <?= $current_page === 'dashboard.php' ? 'active' : '' ?>">
        <span>Dashboard</span>
      </a>
      <a href="submit_request.php" class="nav-item <?= $current_page === 'submit_request.php' ? 'active' : '' ?>">
        <span>Submit Request</span>
      </a>
      <a href="my_requests.php" class="nav-item <?= $current_page === 'my_requests.php' ? 'active' : '' ?>">
        <span>My Requests</span>
      </a>
      <a href="notifications.php" class="nav-item <?= $current_page === 'notifications.php' ? 'active' : '' ?>">
        <span>Notifications</span>
      </a>
    <?php endif; ?>
  </nav>

  <div class="sidebar-footer">
    <a href="logout.php" class="logout-btn" style="text-decoration: none;">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
        <polyline points="16 17 21 12 16 7"></polyline>
        <line x1="21" y1="12" x2="9" y2="12"></line>
      </svg>
      <span>Logout</span>
    </a>
  </div>
</aside>
