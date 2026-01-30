// Enhanced Processor Dashboard JavaScript
document.addEventListener('DOMContentLoaded', function() {
    applyUserSettings();

    // Always initialize top navigation + notifications (shared across pages)
    initNavigation();
    initNotifications();
    initWalkInQuickAction();
    initAutoCancelExpiredAppointments();

    const isDashboardPage = !!document.getElementById('chart-data-store');
    if (isDashboardPage) {
        if (getUserSettings().autoRefresh) {
            initDashboardAutoRefresh({ intervalMs: 60 * 1000, idleGraceMs: 8000 });
        }
    }

    // Charts only exist on the dashboard page
    const chartDataEl = document.getElementById('chart-data-store');
    if (!chartDataEl || !window.Chart || !window.ChartDataLabels) {
        return;
    }

    // Register Chart.js plugins
    Chart.register(ChartDataLabels);

    // Get data from PHP
    const warehouseCapacity = parseFloat(chartDataEl.dataset.capacity);
    const inventory = parseFloat(chartDataEl.dataset.inventory);
    const available = parseFloat(chartDataEl.dataset.available);
    const capacityPercentage = parseFloat(chartDataEl.dataset.percentage);

    const weekDays = JSON.parse(chartDataEl.dataset.weekDays);
    const weekCounts = JSON.parse(chartDataEl.dataset.weekCounts);
    const weekVolumes = JSON.parse(chartDataEl.dataset.weekVolumes);

    initCharts(warehouseCapacity, inventory, available, capacityPercentage, weekDays, weekCounts, weekVolumes);
    initDashboardInteractions();
});

function initAutoCancelExpiredAppointments() {
    // Best-effort: keep statuses consistent without requiring a cron job.
    // Cancels past appointments (per schedule window rules) and emails farmers.
    // Runs silently unless it actually cancels something.
    try {
        fetch('php_helper/api.php?action=autoCancelExpiredAppointments', { cache: 'no-store' })
            .then(r => r.json())
            .then(data => {
                if (!data || !data.success) return;
                const count = parseInt(data.cancelled_count || 0, 10) || 0;
                if (count > 0) {
                    if (typeof showToast === 'function') {
                        showToast(`${count} past appointment(s) were auto-cancelled by the system.`, 'info');
                    }

                    // Nudge other tabs/pages (if supported) then refresh this view.
                    try {
                        if (typeof window.publishAppointmentsRefresh === 'function') {
                            window.publishAppointmentsRefresh({ reason: 'auto-cancelled' });
                        }
                    } catch (_) {}

                    setTimeout(() => {
                        try { window.location.reload(); } catch (_) {}
                    }, 900);
                }
            })
            .catch(() => {
                // ignore
            });
    } catch (e) {
        // ignore
    }
}

function getUserSettings() {
    const defaults = { autoRefresh: true, compact: false, reduceMotion: false, toasts: true };
    try {
        const raw = localStorage.getItem('nfa_settings_v1');
        const obj = raw ? JSON.parse(raw) : null;
        return {
            autoRefresh: obj?.autoRefresh ?? defaults.autoRefresh,
            compact: obj?.compact ?? defaults.compact,
            reduceMotion: obj?.reduceMotion ?? defaults.reduceMotion,
            toasts: obj?.toasts ?? defaults.toasts
        };
    } catch (_) {
        return defaults;
    }
}

function applyUserSettings() {
    const s = getUserSettings();
    window.__nfaSettings = s;
    document.body.classList.toggle('pref-compact', !!s.compact);
    document.body.classList.toggle('pref-reduce-motion', !!s.reduceMotion);
}

function initWalkInQuickAction() {
    const btn = document.getElementById('walkInQuickAction');
    if (!btn) return;

    btn.addEventListener('click', function (e) {
        // Open as a normal new tab (not a sized popup), but still script-opened so
        // walk_in.php can close itself and return focus to this tab.
        e.preventDefault();

        const url = this.getAttribute('href') || 'walk_in.php';

        const win = window.open(url, '_blank');
        if (!win) {
            // Blocked: fallback to same-tab navigation
            window.location.href = url;
            return;
        }
        try {
            win.focus();
        } catch (_) {
            // ignore
        }
    });
}

function initDashboardAutoRefresh({ intervalMs, idleGraceMs }) {
    let lastUserActivity = Date.now();
    let timerId = null;

    const markActivity = () => {
        lastUserActivity = Date.now();
    };

    ['click', 'keydown', 'mousemove', 'scroll', 'touchstart'].forEach(evt => {
        window.addEventListener(evt, markActivity, { passive: true });
    });

    const shouldDeferRefresh = () => {
        if (document.visibilityState !== 'visible') return true;
        if (Date.now() - lastUserActivity < idleGraceMs) return true;

        const notifDropdown = document.getElementById('notifDropdown');
        if (notifDropdown && notifDropdown.classList.contains('show')) return true;

        const userDropdown = document.getElementById('userDropdown');
        if (userDropdown && userDropdown.classList.contains('show')) return true;

        const loadingOverlay = document.getElementById('loadingOverlay');
        if (loadingOverlay && loadingOverlay.classList.contains('active')) return true;

        return false;
    };

    const tick = () => {
        timerId = null;

        if (shouldDeferRefresh()) {
            schedule(Math.min(15 * 1000, intervalMs));
            return;
        }

        window.location.reload();
    };

    const schedule = (delay) => {
        if (timerId) clearTimeout(timerId);
        timerId = setTimeout(tick, delay);
    };

    schedule(intervalMs);
}

// Navigation and UI Interactions
function initNavigation() {
    // Notification dropdown
    const notifWrapper = document.getElementById('notifWrapper');
    const notifDropdown = document.getElementById('notifDropdown');
    
    if (notifWrapper && notifDropdown) {
        notifWrapper.addEventListener('click', function(e) {
            if (e.target.closest('.notif-icon')) {
                notifDropdown.classList.toggle('show');
                e.stopPropagation();
            }
        });
        
        // Close dropdown when clicking outside
        document.addEventListener('click', function(e) {
            if (!notifWrapper.contains(e.target) && !notifDropdown.contains(e.target)) {
                notifDropdown.classList.remove('show');
            }
        });
    }
    
    // User profile dropdown
    const userProfile = document.querySelector('.user-profile');
    const userDropdown = document.getElementById('userDropdown');
    
    if (userProfile && userDropdown) {
        userProfile.addEventListener('click', function(e) {
            userDropdown.classList.toggle('show');
            e.stopPropagation();
        });
        
        document.addEventListener('click', function(e) {
            if (!userProfile.contains(e.target) && !userDropdown.contains(e.target)) {
                userDropdown.classList.remove('show');
            }
        });
    }
    
    // Active navigation highlighting
    const currentPath = window.location.pathname;
    const navLinks = document.querySelectorAll('.nav-link');
    
    navLinks.forEach(link => {
        if (link.getAttribute('href') === currentPath.split('/').pop()) {
            link.classList.add('active');
        }
    });
}

// Notification Management
function initNotifications() {
    const markAllReadBtn = document.getElementById('markAllRead');
    const notifItems = document.querySelectorAll('.notif-item');
    const notifCheckboxes = document.querySelectorAll('.notif-checkbox');
    const notifDeleteButtons = document.querySelectorAll('.notif-delete');
    
    // Mark all as read
    if (markAllReadBtn) {
        markAllReadBtn.addEventListener('click', function(e) {
            e.preventDefault();
            const unreadItems = document.querySelectorAll('.notif-item.unread');

            unreadItems.forEach(item => {
                const appointmentId = item.getAttribute('data-appointment-id');
                const cb = item.querySelector('.notif-checkbox');

                item.classList.remove('unread');
                item.dataset.isRead = '1';
                if (cb) {
                    cb.checked = true;
                }

                updateNotificationStatus(appointmentId, 1);
            });

            updateNotifCount(-unreadItems.length);
            if (typeof showToast === 'function') {
                showToast('All notifications marked as read', 'success');
            }
        });
    }

    // Checkbox read/unread toggle (must NOT navigate)
    notifCheckboxes.forEach(cb => {
        cb.addEventListener('click', function(e) {
            // Let the checkbox toggle normally, but prevent the parent <a> from navigating
            e.stopPropagation();
        });
        cb.addEventListener('change', function(e) {
            e.stopPropagation();

            const item = this.closest('.notif-item');
            if (!item) return;

            const appointmentId = item.getAttribute('data-appointment-id');
            const isRead = this.checked ? 1 : 0;
            const wasUnread = item.classList.contains('unread');

            item.classList.toggle('unread', isRead === 0);
            item.dataset.isRead = String(isRead);

            updateNotificationStatus(appointmentId, isRead);

            // Update badge count
            if (wasUnread && isRead === 1) {
                updateNotifCount(-1);
            } else if (!wasUnread && isRead === 0) {
                updateNotifCount(1);
            }
        });
    });
    
    // Notification item click - open appointment page; if unread, auto-mark read
    notifItems.forEach(item => {
        item.addEventListener('click', function(e) {
            // If clicking checkbox area, do nothing (checkbox handler handles it)
            if (e.target.closest('.notif-check') || e.target.closest('.notif-checkbox') || e.target.closest('.notif-delete')) {
                return;
            }

            e.preventDefault();

            const appointmentId = this.getAttribute('data-appointment-id');
            const isUnread = this.classList.contains('unread');
            const href = this.getAttribute('href');

            if (isUnread) {
                // Update UI immediately
                this.classList.remove('unread');
                this.dataset.isRead = '1';
                const cb = this.querySelector('.notif-checkbox');
                if (cb) {
                    cb.checked = true;
                }
                updateNotificationStatus(appointmentId, 1);
                updateNotifCount(-1);
            }

            // Navigate to the target page
            if (href) {
                window.location.href = href;
            } else if (typeof viewAppointment === 'function') {
                viewAppointment(appointmentId);
            }
        });
    });

    // Delete notification (must NOT navigate)
    notifDeleteButtons.forEach(btn => {
        const activateDelete = (e) => {
            e.preventDefault();
            e.stopPropagation();

            const item = btn.closest('.notif-item');
            if (!item) return;

            const appointmentId = item.getAttribute('data-appointment-id');
            const wasUnread = item.classList.contains('unread');

            // Optimistic UI update
            item.remove();
            if (wasUnread) {
                updateNotifCount(-1);
            }

            deleteNotification(appointmentId);

            // If list is empty, show placeholder
            const list = document.getElementById('notifList');
            if (list && list.querySelectorAll('.notif-item').length === 0) {
                list.innerHTML = `
                    <div class="no-notifications">
                        <i class="fas fa-check-circle"></i>
                        <p>No new notifications</p>
                    </div>
                `;
                const markAll = document.getElementById('markAllRead');
                if (markAll) markAll.remove();
            }

            if (typeof showToast === 'function') {
                showToast('Notification deleted', 'success');
            }
        };

        btn.addEventListener('click', activateDelete);
        btn.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' || e.key === ' ') {
                activateDelete(e);
            }
        });
    });
}

function updateNotificationStatus(appointmentId, isRead) {
    fetch('php_helper/api.php?action=updateNotification', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ 
            appointment_id: appointmentId, 
            is_read: isRead,
            user_id: getUserId()
        })
    })
    .then(response => response.json())
    .then(data => {
        if (!data.success) {
            console.error('Failed to update notification:', data.error);
            showToast('Failed to update notification status', 'error');
        }
    })
    .catch(error => {
        console.error('Error updating notification:', error);
        showToast('Network error updating notification', 'error');
    });
}

function deleteNotification(appointmentId) {
    fetch('php_helper/api.php?action=deleteNotification', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            appointment_id: appointmentId,
            user_id: getUserId()
        })
    })
    .then(response => response.json())
    .then(data => {
        if (!data.success) {
            console.error('Failed to delete notification:', data.error);
            if (typeof showToast === 'function') {
                showToast('Failed to delete notification', 'error');
            }
        }
    })
    .catch(error => {
        console.error('Error deleting notification:', error);
        if (typeof showToast === 'function') {
            showToast('Network error deleting notification', 'error');
        }
    });
}

function updateNotifCount(delta) {
    let badge = document.querySelector('.notif-badge');
    const wrapper = document.querySelector('.notif-wrapper');
    
    if (badge) {
        let currentCount = parseInt(badge.textContent) || 0;
        let newCount = currentCount + delta;
        
        if (newCount <= 0) {
            badge.remove();
            badge = null;
        } else {
            badge.textContent = newCount;
        }
    } else if (delta > 0) {
        // Create new badge
        const newBadge = document.createElement('span');
        newBadge.className = 'notif-badge pulse';
        newBadge.textContent = delta;
        
        const notifIcon = wrapper.querySelector('.notif-icon');
        if (notifIcon) {
            notifIcon.appendChild(newBadge);
        }
    }
}

// Chart Initialization
function initCharts(warehouseCapacity, inventory, available, capacityPercentage, weekDays, weekCounts, weekVolumes) {
    // Capacity Chart
    initCapacityChart(warehouseCapacity, inventory, available, capacityPercentage);
    
    // Activity Chart
    initActivityChart(weekDays, weekCounts, weekVolumes);
}

function initCapacityChart(total, inventory, available, percentage) {
    const ctx = document.getElementById('capacityChart').getContext('2d');
    const container = document.getElementById('capacity-chart-container');
    
    let currentChart = null;
    
    // Pie Chart Configuration
    const pieConfig = {
        type: 'pie',
        data: {
            labels: ['Current Inventory', 'Available Capacity'],
            datasets: [{
                data: [inventory, available],
                backgroundColor: ['#3b82f6', '#10b981'],
                borderColor: '#fff',
                borderWidth: 2,
                hoverOffset: 15
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: {
                duration: 1500,
                easing: 'easeOutQuart'
            },
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const value = context.raw;
                            const percentage = ((value / total) * 100).toFixed(1);
                            return `${value.toLocaleString()} bags (${percentage}%)`;
                        }
                    }
                },
                datalabels: {
                    color: '#fff',
                    font: {
                        weight: 'bold',
                        size: 14
                    },
                    formatter: (value, ctx) => {
                        const percentage = ((value / total) * 100).toFixed(1);
                        return `${value.toLocaleString()}\n(${percentage}%)`;
                    },
                    textAlign: 'center'
                }
            }
        }
    };
    
    // Donut Chart Configuration
    const donutConfig = {
        type: 'doughnut',
        data: {
            labels: ['Current Inventory', 'Available Capacity'],
            datasets: [{
                data: [inventory, available],
                backgroundColor: ['#3b82f6', '#10b981'],
                borderColor: '#fff',
                borderWidth: 2,
                cutout: '60%'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: {
                duration: 1500,
                easing: 'easeOutQuart'
            },
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const value = context.raw;
                            const percentage = ((value / total) * 100).toFixed(1);
                            return `${value.toLocaleString()} bags (${percentage}%)`;
                        }
                    }
                }
            }
        }
    };
    
    // Gauge Chart Configuration
    const gaugeConfig = {
        type: 'doughnut',
        data: {
            labels: ['Used', 'Remaining'],
            datasets: [{
                data: [inventory, available],
                backgroundColor: ['#3b82f6', '#e5e7eb'],
                borderWidth: 0,
                circumference: 180,
                rotation: 270,
                cutout: '80%'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: {
                duration: 1500,
                easing: 'easeOutQuart'
            },
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    enabled: false
                }
            }
        },
        plugins: [{
            id: 'gaugeCenterText',
            afterDraw: (chart) => {
                const { ctx, chartArea: { width, height } } = chart;
                ctx.save();
                
                // Center text
                ctx.font = 'bold 24px Segoe UI';
                ctx.fillStyle = '#1f2937';
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                ctx.fillText(`${percentage.toFixed(1)}%`, width / 2, height / 2);
                
                // Subtext
                ctx.font = '14px Segoe UI';
                ctx.fillStyle = '#6b7280';
                ctx.fillText('Capacity Used', width / 2, height / 2 + 30);
                
                ctx.restore();
            }
        }]
    };
    
    // Create initial chart
    currentChart = new Chart(ctx, pieConfig);
    
    // Chart type toggle buttons
    const chartToggles = document.querySelectorAll('.chart-toggle');
    
    chartToggles.forEach(toggle => {
        toggle.addEventListener('click', function() {
            // Update active state
            chartToggles.forEach(t => t.classList.remove('active'));
            this.classList.add('active');
            
            // Get chart type
            const chartType = this.dataset.chart;
            
            // Destroy current chart
            currentChart.destroy();
            
            // Create new chart based on type
            switch(chartType) {
                case 'donut':
                    currentChart = new Chart(ctx, donutConfig);
                    break;
                case 'gauge':
                    currentChart = new Chart(ctx, gaugeConfig);
                    break;
                default:
                    currentChart = new Chart(ctx, pieConfig);
            }
        });
    });
}

function initActivityChart(days, counts, volumes) {
    const ctx = document.getElementById('activityChart').getContext('2d');
    
    // Create gradient for volume bars
    const volumeGradient = ctx.createLinearGradient(0, 0, 0, 200);
    volumeGradient.addColorStop(0, 'rgba(59, 130, 246, 0.8)');
    volumeGradient.addColorStop(1, 'rgba(59, 130, 246, 0.2)');
    
    // Create gradient for count line
    const countGradient = ctx.createLinearGradient(0, 0, 0, 200);
    countGradient.addColorStop(0, 'rgba(16, 185, 129, 0.8)');
    countGradient.addColorStop(1, 'rgba(16, 185, 129, 0.2)');
    
    const chart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: days,
            datasets: [
                {
                    label: 'Volume (bags)',
                    data: volumes,
                    backgroundColor: volumeGradient,
                    borderColor: '#3b82f6',
                    borderWidth: 1,
                    borderRadius: 4,
                    order: 2
                },
                {
                    label: 'Appointments',
                    data: counts,
                    type: 'line',
                    borderColor: '#10b981',
                    borderWidth: 3,
                    backgroundColor: 'transparent',
                    pointBackgroundColor: '#10b981',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointRadius: 5,
                    pointHoverRadius: 7,
                    tension: 0.3,
                    order: 1,
                    yAxisID: 'y1'
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                mode: 'index',
                intersect: false,
            },
            scales: {
                x: {
                    grid: {
                        display: false
                    }
                },
                y: {
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: 'Volume (bags)'
                    },
                    grid: {
                        color: 'rgba(0, 0, 0, 0.05)'
                    }
                },
                y1: {
                    beginAtZero: true,
                    position: 'right',
                    title: {
                        display: true,
                        text: 'Appointments'
                    },
                    grid: {
                        drawOnChartArea: false
                    }
                }
            },
            plugins: {
                legend: {
                    position: 'top',
                    align: 'end',
                    labels: {
                        usePointStyle: true,
                        pointStyle: 'circle'
                    }
                },
                tooltip: {
                    backgroundColor: 'rgba(0, 0, 0, 0.8)',
                    padding: 12,
                    cornerRadius: 6,
                    displayColors: false,
                    callbacks: {
                        label: function(context) {
                            const label = context.dataset.label || '';
                            const value = context.parsed.y;
                            
                            if (label.includes('Volume')) {
                                return `${label}: ${value.toLocaleString()} bags`;
                            } else {
                                return `${label}: ${value}`;
                            }
                        }
                    }
                }
            }
        }
    });
    
    // Period selector
    const periodSelect = document.getElementById('periodSelect');
    if (periodSelect) {
        periodSelect.addEventListener('change', function() {
            const days = parseInt(this.value);
            updateActivityChart(chart, days);
        });
    }
}

async function updateActivityChart(chart, days) {
    try {
        showLoading(true);
        
        const response = await fetch(`php_helper/api.php?action=getWeeklyData&days=${days}&branch_id=${getBranchId()}`);
        const data = await response.json();
        
        if (data.success) {
            chart.data.labels = data.labels;
            chart.data.datasets[0].data = data.volumes;
            chart.data.datasets[1].data = data.counts;
            chart.update();
            
            // Update summary
            updateActivitySummary(data);
        } else {
            throw new Error(data.error || 'Failed to load data');
        }
    } catch (error) {
        console.error('Error updating activity chart:', error);
        showToast('Failed to update activity data', 'error');
    } finally {
        showLoading(false);
    }
}

function updateActivitySummary(data) {
    const totalAppointments = data.counts.reduce((a, b) => a + b, 0);
    const totalVolume = data.volumes.reduce((a, b) => a + b, 0);
    const avgPerDay = totalAppointments / data.counts.length;

    const elTotalAppointments = document.getElementById('weeklyTotalAppointments');
    if (elTotalAppointments) elTotalAppointments.textContent = String(totalAppointments);

    const elTotalVolume = document.getElementById('weeklyTotalVolume');
    if (elTotalVolume) elTotalVolume.textContent = `${totalVolume.toLocaleString()} bags`;

    const elAvgPerDay = document.getElementById('weeklyAvgPerDay');
    if (elAvgPerDay) elAvgPerDay.textContent = avgPerDay.toFixed(1);
}

// Dashboard Interactions
function initDashboardInteractions() {
    // Appointment actions
    document.querySelectorAll('.schedule-actions .action-btn').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.stopPropagation();
        });
    });
    
    // Quick action animations
    document.querySelectorAll('.quick-action').forEach(action => {
        action.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-4px)';
        });
        
        action.addEventListener('mouseleave', function() {
            this.style.transform = 'translateY(0)';
        });
    });
    
    // Card hover effects
    document.querySelectorAll('.stat-card').forEach(card => {
        card.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-4px)';
        });
        
        card.addEventListener('mouseleave', function() {
            this.style.transform = 'translateY(0)';
        });
    });
}

// Utility Functions
function viewAppointment(appointmentId) {
    // Redirect to appointment details page
    window.location.href = `appointments.php?view=${appointmentId}`;
}

function confirmDialog({
    title = 'Confirm',
    message = 'Are you sure?',
    confirmText = 'Confirm',
    cancelText = 'Cancel',
    tone = 'primary' // primary | danger
} = {}) {
    return new Promise((resolve) => {
        let overlay = document.getElementById('confirmModalOverlay');
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.id = 'confirmModalOverlay';
            overlay.className = 'modal-overlay confirm-overlay';
            overlay.style.display = 'none';
            overlay.innerHTML = `
                <div class="modal-dialog confirm-dialog" role="dialog" aria-modal="true" aria-labelledby="confirmTitle" aria-describedby="confirmMessage">
                    <button class="modal-close" type="button" id="confirmClose" aria-label="Close"><i class="fas fa-times"></i></button>
                    <div class="confirm-header">
                        <div class="confirm-icon" aria-hidden="true"></div>
                        <div class="confirm-headings">
                            <h2 id="confirmTitle"></h2>
                            <p id="confirmMessage" class="confirm-message"></p>
                        </div>
                    </div>
                    <div class="confirm-actions">
                        <button type="button" class="btn-view-details btn-inline-secondary" id="confirmCancel"></button>
                        <button type="button" class="btn-view-details btn-inline-primary" id="confirmOk"></button>
                    </div>
                </div>
            `;
            document.body.appendChild(overlay);
        }

        const titleEl = overlay.querySelector('#confirmTitle');
        const messageEl = overlay.querySelector('#confirmMessage');
        const btnCancel = overlay.querySelector('#confirmCancel');
        const btnOk = overlay.querySelector('#confirmOk');
        const btnClose = overlay.querySelector('#confirmClose');
        const iconEl = overlay.querySelector('.confirm-icon');

        if (titleEl) titleEl.textContent = title;
        if (messageEl) messageEl.textContent = message;

        if (btnCancel) btnCancel.textContent = cancelText;
        if (btnOk) btnOk.textContent = confirmText;

        // Tone
        if (btnOk) {
            btnOk.classList.toggle('btn-inline-danger', tone === 'danger');
            btnOk.classList.toggle('btn-inline-primary', tone !== 'danger');
        }
        if (iconEl) {
            iconEl.classList.toggle('danger', tone === 'danger');
        }

        const previouslyFocused = document.activeElement;
        const hadModalOpen = document.body.classList.contains('modal-open');

        const cleanup = () => {
            overlay.style.display = 'none';
            if (!hadModalOpen) {
                document.body.classList.remove('modal-open');
            }

            overlay.removeEventListener('click', onOverlayClick);
            document.removeEventListener('keydown', onKeyDown);
            btnCancel && btnCancel.removeEventListener('click', onCancel);
            btnOk && btnOk.removeEventListener('click', onOk);
            btnClose && btnClose.removeEventListener('click', onCancel);

            if (previouslyFocused && typeof previouslyFocused.focus === 'function') {
                previouslyFocused.focus();
            }
        };

        const onCancel = () => {
            cleanup();
            resolve(false);
        };

        const onOk = () => {
            cleanup();
            resolve(true);
        };

        const onOverlayClick = (e) => {
            if (e.target === overlay) {
                onCancel();
            }
        };

        const onKeyDown = (e) => {
            if (e.key === 'Escape') {
                e.preventDefault();
                onCancel();
            }
        };

        overlay.addEventListener('click', onOverlayClick);
        document.addEventListener('keydown', onKeyDown);
        btnCancel && btnCancel.addEventListener('click', onCancel);
        btnOk && btnOk.addEventListener('click', onOk);
        btnClose && btnClose.addEventListener('click', onCancel);

        overlay.style.display = 'flex';
        document.body.classList.add('modal-open');

        // Focus the primary action
        setTimeout(() => {
            if (btnOk && typeof btnOk.focus === 'function') btnOk.focus();
        }, 0);
    });
}

function confirmAppointment(appointmentId) {
    Promise.resolve(confirmDialog({
        title: 'Confirm Appointment',
        message: 'Are you sure you want to confirm this appointment? This will mark it as confirmed.',
        confirmText: 'Yes, Confirm',
        cancelText: 'Cancel',
        tone: 'primary'
    }))
    .then((ok) => {
        if (!ok) return;
        showLoading(true);
        
        fetch('php_helper/api.php?action=confirmAppointment', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ 
                appointment_id: appointmentId,
                confirmed_by: getUserId()
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showToast('Appointment confirmed successfully!', 'success');
                try {
                    if (typeof window.publishAppointmentsRefresh === 'function') {
                        window.publishAppointmentsRefresh({ reason: 'confirmed' });
                    }
                } catch (e) {
                    // ignore
                }
                // Refresh the schedule list
                setTimeout(() => location.reload(), 1000);
            } else {
                throw new Error(data.error || 'Failed to confirm appointment');
            }
        })
        .catch(error => {
            console.error('Error confirming appointment:', error);
            showToast('Failed to confirm appointment', 'error');
        })
        .finally(() => {
            showLoading(false);
        });
    });
}

function refreshDashboardData() {
    fetch('php_helper/api.php?action=getDashboardData&branch_id=' + getBranchId())
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Update stats
                updateDashboardStats(data);
                // Update charts if needed
                // You would implement specific update functions here
                showToast('Dashboard data updated', 'info');
            }
        })
        .catch(error => {
            console.error('Error refreshing dashboard:', error);
        });
}

function updateDashboardStats(data) {
    // Update stats cards with new data
    const stats = {
        'Max Capacity': data.capacity,
        'Current Stock': data.inventory,
        'Available Capacity': data.available,
        'Pending Appts': data.pending_count
    };
    
    // Update DOM elements
    Object.keys(stats).forEach(key => {
        const value = stats[key];
        // Find and update corresponding stat card
        // Implementation depends on your DOM structure
    });
}

function showToast(message, type = 'info') {
    const s = window.__nfaSettings || getUserSettings();
    if (s && s.toasts === false) return;

    // Remove existing toasts
    const existingToasts = document.querySelectorAll('.toast');
    existingToasts.forEach(toast => toast.remove());
    
    // Create toast element
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    
    const icon = type === 'success' ? '✓' : 
                 type === 'error' ? '✗' : 
                 type === 'warning' ? '⚠' : 'ℹ';
    
    toast.innerHTML = `
        <span class="toast-icon">${icon}</span>
        <span class="toast-message">${message}</span>
    `;
    
    // Add to page
    document.body.appendChild(toast);
    
    // Animate in
    setTimeout(() => toast.classList.add('show'), 10);
    
    // Remove after 3 seconds
    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

function showLoading(show) {
    const overlay = document.getElementById('loadingOverlay');
    if (!overlay) {
        if (show) {
            // Create loading overlay
            const overlay = document.createElement('div');
            overlay.id = 'loadingOverlay';
            overlay.className = 'loading-overlay';
            overlay.innerHTML = `
                <div class="loading-content">
                    <div class="spinner"></div>
                    <p>Loading...</p>
                </div>
            `;
            document.body.appendChild(overlay);
        }
    } else {
        if (show) {
            overlay.classList.add('active');
        } else {
            overlay.classList.remove('active');
        }
    }
}

function getUserId() {
    // Get user ID from session or global variable
    return window.userId || null;
}

function getBranchId() {
    // Get branch ID from session or global variable
    return window.branchId || null;
}

// Initialize the dashboard
// NOTE: main initialization is handled at the top of this file.