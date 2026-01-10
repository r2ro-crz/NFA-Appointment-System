// Enhanced Processor Dashboard JavaScript
document.addEventListener('DOMContentLoaded', function() {
    // Register Chart.js plugins
    Chart.register(ChartDataLabels);
    
    // Get data from PHP
    const chartDataEl = document.getElementById('chart-data-store');
    const warehouseCapacity = parseFloat(chartDataEl.dataset.capacity);
    const inventory = parseFloat(chartDataEl.dataset.inventory);
    const available = parseFloat(chartDataEl.dataset.available);
    const capacityPercentage = parseFloat(chartDataEl.dataset.percentage);
    
    const weekDays = JSON.parse(chartDataEl.dataset.weekDays);
    const weekCounts = JSON.parse(chartDataEl.dataset.weekCounts);
    const weekVolumes = JSON.parse(chartDataEl.dataset.weekVolumes);
    
    // Initialize UI Components
    initNavigation();
    initNotifications();
    initCharts(warehouseCapacity, inventory, available, capacityPercentage, weekDays, weekCounts, weekVolumes);
    initDashboardInteractions();
    
    // Auto-refresh data every 5 minutes
    setInterval(refreshDashboardData, 5 * 60 * 1000);
});

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
    const markReadButtons = document.querySelectorAll('.mark-read-btn');
    const notifItems = document.querySelectorAll('.notif-item');
    
    // Mark all as read
    if (markAllReadBtn) {
        markAllReadBtn.addEventListener('click', function() {
            const unreadItems = document.querySelectorAll('.notif-item.unread');
            
            unreadItems.forEach(item => {
                const appointmentId = item.getAttribute('data-appointment-id');
                const btn = item.querySelector('.mark-read-btn');
                
                // Update UI
                item.classList.remove('unread');
                if (btn) {
                    btn.innerHTML = '<i class="fas fa-circle"></i>';
                    btn.title = 'Mark as Unread';
                }
                
                // Update server
                updateNotificationStatus(appointmentId, 1);
            });
            
            // Update badge count
            updateNotifCount(-unreadItems.length);
            
            // Show success message
            showToast('All notifications marked as read', 'success');
        });
    }
    
    // Individual mark as read
    markReadButtons.forEach(btn => {
        btn.addEventListener('click', function() {
            const item = this.closest('.notif-item');
            const appointmentId = item.getAttribute('data-appointment-id');
            const isUnread = item.classList.contains('unread');
            
            // Toggle state
            if (isUnread) {
                item.classList.remove('unread');
                this.innerHTML = '<i class="fas fa-circle"></i>';
                this.title = 'Mark as Unread';
                updateNotificationStatus(appointmentId, 1);
                updateNotifCount(-1);
            } else {
                item.classList.add('unread');
                this.innerHTML = '<i class="fas fa-check-circle"></i>';
                this.title = 'Mark as Read';
                updateNotificationStatus(appointmentId, 0);
                updateNotifCount(1);
            }
        });
    });
    
    // Notification item click - view appointment
    notifItems.forEach(item => {
        item.addEventListener('click', function(e) {
            if (!e.target.closest('.mark-read-btn') && !e.target.closest('.action-btn')) {
                const appointmentId = this.getAttribute('data-appointment-id');
                viewAppointment(appointmentId);
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
    
    // Update summary elements
    const summaryElements = {
        'Total Appointments': totalAppointments,
        'Total Volume': totalVolume.toLocaleString() + ' bags',
        'Avg. per Day': avgPerDay.toFixed(1)
    };
    
    // You would update your DOM elements here
    console.log('Updated activity summary:', summaryElements);
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
    window.location.href = `operator.php?view=${appointmentId}`;
}

function confirmAppointment(appointmentId) {
    if (confirm('Are you sure you want to confirm this appointment?')) {
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
    }
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