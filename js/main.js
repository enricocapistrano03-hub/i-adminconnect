// ==============================================================================
// i-AdminConnect Main JavaScript Controller (PHP Stack)
// Handles real-time polling (AJAX Fetch), modal controls, and user interactions
// ==============================================================================

let trackerPollTimer = null;
let currentTrackingId = null;

function openTrackerModal(trackingNumber) {
  if (!trackingNumber) {
    console.warn('openTrackerModal called with empty trackingNumber');
    return;
  }
  currentTrackingId = trackingNumber;

  const modal = document.getElementById('tracking-modal');
  const modalBody = document.getElementById('tracking-modal-content');

  // Show immediate loading placeholder
  if (modalBody) {
    modalBody.innerHTML = `
      <div style="text-align: center; padding: 48px 24px;">
        <div style="display: inline-block; width: 44px; height: 44px; border: 4px solid #e2e8f0; border-top-color: #0284c7; border-radius: 50%; animation: tracker-spin 0.8s linear infinite;"></div>
        <p style="margin-top: 18px; color: #1e293b; font-weight: 700; font-size: 1.05rem;">Loading Request Status...</p>
        <p style="color: #64748b; font-size: 0.85rem; margin-top: 4px;">Fetching live tracking details for ${trackingNumber}</p>
      </div>
      <style>@keyframes tracker-spin { to { transform: rotate(360deg); } }</style>
    `;
  }

  // Ensure modal is shown and fully opaque
  if (modal) {
    modal.classList.add('active');
    modal.style.display = 'flex';
    modal.style.opacity = '1';
    modal.style.pointerEvents = 'auto';
  }

  // Initial fetch
  fetchAndRenderTracker(trackingNumber);

  // Live polling every 4 seconds as specified in Architecture Slide 5
  if (trackerPollTimer) clearInterval(trackerPollTimer);
  trackerPollTimer = setInterval(() => {
    if (currentTrackingId) {
      fetchAndRenderTracker(currentTrackingId);
    }
  }, 4000);
}

function closeTrackerModal() {
  const modal = document.getElementById('tracking-modal');
  if (modal) {
    modal.classList.remove('active');
    modal.style.display = 'none';
    modal.style.opacity = '0';
    modal.style.pointerEvents = 'none';
  }

  if (trackerPollTimer) {
    clearInterval(trackerPollTimer);
    trackerPollTimer = null;
  }
  currentTrackingId = null;
}

function fetchAndRenderTracker(trackingNumber) {
  fetch('track_api.php?tracking_number=' + encodeURIComponent(trackingNumber))
    .then(res => res.json())
    .then(data => {
      if (data.error) {
        const modalBody = document.getElementById('tracking-modal-content');
        if (modalBody) {
          modalBody.innerHTML = `
            <div style="text-align: center; padding: 36px 20px;">
              <div style="font-size: 2.5rem; margin-bottom: 12px;">⚠️</div>
              <h3 style="font-size: 1.15rem; color: #0f172a; margin-bottom: 6px;">Request Not Found</h3>
              <p style="color: #64748b; font-size: 0.88rem; margin-bottom: 20px;">Could not retrieve tracking details for <strong>${trackingNumber}</strong>.</p>
              <button class="btn-primary-cta" onclick="closeTrackerModal()" style="padding: 8px 22px;">Close</button>
            </div>
          `;
        }
        return;
      }
      renderTrackerContent(data);
    })
    .catch(err => {
      console.error('Tracker fetch error:', err);
      const modalBody = document.getElementById('tracking-modal-content');
      if (modalBody) {
        modalBody.innerHTML = `
          <div style="text-align: center; padding: 36px 20px;">
            <div style="font-size: 2.5rem; margin-bottom: 12px;">⚠️</div>
            <h3 style="font-size: 1.15rem; color: #0f172a; margin-bottom: 6px;">Connection Error</h3>
            <p style="color: #64748b; font-size: 0.88rem; margin-bottom: 20px;">Unable to connect to the tracking server. Please check your connection and try again.</p>
            <button class="btn-primary-cta" onclick="closeTrackerModal()" style="padding: 8px 22px;">Close</button>
          </div>
        `;
      }
    });
}

function renderTrackerContent(req) {
  const modalBody = document.getElementById('tracking-modal-content');
  if (!modalBody) return;

  const statusClass = req.status.toLowerCase().replace(/\s+/g, '-');

  const steps = [
    { num: 1, title: 'Submitted', time: req.stage1 || '—' },
    { num: 2, title: 'Under Review', time: req.stage2 || '—' },
    { num: 3, title: 'Processing', time: req.stage3 || '—' },
    { num: 4, title: 'Ready for Release', time: req.stage4 || '—' }
  ];

  let stepperHtml = '';
  steps.forEach(step => {
    let stateClass = '';
    let iconOrNum = step.num;
    if (step.num < req.currentStage || (step.num === 4 && req.currentStage === 4)) {
      stateClass = 'completed';
      iconOrNum = `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>`;
    } else if (step.num === req.currentStage) {
      stateClass = 'current';
    }

    stepperHtml += `
      <div class="step-node ${stateClass}">
        <div class="step-circle">${iconOrNum}</div>
        <div class="step-title">${step.title}</div>
        <div class="step-date">${step.time !== '—' ? step.time : '—'}</div>
      </div>
    `;
  });

  modalBody.innerHTML = `
    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px;">
      <div>
        <h2 style="font-size: 1.35rem; font-weight: 800; color: #0f172a; display: flex; align-items: center; gap: 8px; margin: 0;">
          <span style="color: #0284c7;">📊</span> Request Tracking
        </h2>
        <p style="font-size: 0.85rem; color: #64748b; margin-top: 4px; margin-bottom: 0;">Monitor the real-time status of your administrative request.</p>
      </div>
      <button class="modal-close-btn" onclick="closeTrackerModal()">&times;</button>
    </div>

    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; padding: 12px 16px; background: #f8fafc; border-radius: 12px;">
      <span style="font-weight: 700; font-size: 1.05rem; color: #1e293b;">
        ${req.id} <span style="font-weight: 500; color: #475569;">${req.requestType}</span>
      </span>
      <span class="badge-status ${statusClass}">${req.status}</span>
    </div>

    <div class="stepper-container">
      <div class="stepper-track-bg"></div>
      <div class="stepper-track-fill" style="width: ${Math.max(0, (req.percent - 25) * 1.33)}%;"></div>
      ${stepperHtml}
    </div>

    <div class="progress-container">
      <div style="font-size: 1rem;">📦</div>
      <div class="progress-bar-bg">
        <div class="progress-bar-fill" style="width: ${req.percent}%;"></div>
      </div>
      <div class="progress-percentage">${req.percent}%</div>
    </div>

    <div class="tracking-info-box">
      <div style="display: flex; align-items: center; gap: 6px; font-size: 0.82rem; color: #64748b; margin-bottom: 6px;">
        <span>🕒</span> <strong>Last update:</strong> ${req.stage4 || req.stage3 || req.stage2 || req.stage1 || 'Recent'}
      </div>
      <div style="font-size: 0.92rem; color: #0f172a; line-height: 1.45;">
        <strong>🚩 Status:</strong> <span class="badge-status ${statusClass}" style="padding: 2px 10px; font-size: 0.75rem;">${req.status}</span> — ${req.remarks}
      </div>
      ${req.attachedFile ? `
        <div style="margin-top: 10px; padding-top: 8px; border-top: 1px dashed #e2e8f0; font-size: 0.82rem; color: #475569; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
          <div>📎 <strong>Attached:</strong> ${req.attachedFile} (${req.fileSize})</div>
          <a href="view_file.php?ref=${encodeURIComponent(req.id)}" target="_blank" class="attachment-preview-btn" style="text-decoration: none;">
            View Document ↗
          </a>
        </div>
      ` : ''}
    </div>

    ${req.canAcknowledge ? `
      <div id="ack-container" style="margin-top: 15px; padding: 14px 16px; background: #ecfdf5; border: 1px solid #6ee7b7; border-radius: 12px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
        <div>
          <div style="font-weight: 700; color: #065f46; font-size: 0.95rem;">🎉 Document Ready for Release!</div>
          <div style="font-size: 0.8rem; color: #047857; margin-top: 2px;">Have you received this document? Acknowledge receipt to complete this request.</div>
        </div>
        <button type="button" class="btn-primary-cta" style="background: #059669; padding: 8px 18px; font-size: 0.88rem; font-weight: 700; cursor: pointer; border: none; border-radius: 8px; color: white;" onclick="acknowledgeReceipt('${req.id}')">
          ✔ Mark as Received
        </button>
      </div>
    ` : ''}

    ${req.status === 'Released' ? `
      <div style="margin-top: 15px; padding: 10px 14px; background: #f0fdf4; border: 1px solid #86efac; border-radius: 10px; color: #166534; font-size: 0.85rem; font-weight: 700; display: flex; align-items: center; gap: 8px;">
        <span>✔</span> <span>Document Successfully Acknowledged & Received</span>
      </div>
    ` : ''}
  `;
}

function acknowledgeReceipt(trackingId) {
  if (!confirm('Natanggap mo na ba ang iyong dokumento? I-click ang OK para tapusin at i-mark as received ang request na ito.')) {
    return;
  }

  fetch('acknowledge_api.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'tracking_number=' + encodeURIComponent(trackingId)
  })
  .then(res => res.json())
  .then(data => {
    if (data.error) {
      alert(data.error);
    } else {
      fetchAndRenderTracker(trackingId);
      setTimeout(() => {
        window.location.reload();
      }, 1500);
    }
  })
  .catch(err => {
    console.error('Acknowledge error:', err);
    alert('An error occurred while acknowledging receipt. Please try again.');
  });
}

// Close modal on escape or background click
window.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') closeTrackerModal();
});

document.addEventListener('DOMContentLoaded', () => {
  const modal = document.getElementById('tracking-modal');
  if (modal) {
    modal.addEventListener('click', (e) => {
      if (e.target === modal) closeTrackerModal();
    });
  }

  // Mobile Sidebar Drawer Navigation
  const menuBtn = document.getElementById('mobileMenuBtn');
  const closeBtn = document.getElementById('sidebarCloseBtn');
  const backdrop = document.getElementById('sidebarBackdrop');
  const sidebar = document.getElementById('appSidebar');

  function openSidebar() {
    if (sidebar) sidebar.classList.add('mobile-open');
    if (backdrop) backdrop.classList.add('active');
    document.body.style.overflow = 'hidden';
  }

  function closeSidebar() {
    if (sidebar) sidebar.classList.remove('mobile-open');
    if (backdrop) backdrop.classList.remove('active');
    document.body.style.overflow = '';
  }

  if (menuBtn) menuBtn.addEventListener('click', openSidebar);
  if (closeBtn) closeBtn.addEventListener('click', closeSidebar);
  if (backdrop) backdrop.addEventListener('click', closeSidebar);
});
