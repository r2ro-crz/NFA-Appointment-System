<?php
session_start();
require_once 'php_helper/db_config.php';

// Ensure only Processors can access
if (!isset($_SESSION["loggedin"]) || $_SESSION["user_type"] !== 'Processor') {
    header("location: login.php");
    exit;
}


$branch_id = $_SESSION["branch_id"];
// Fetch processor's first_name

// Try to get user id from session (commonly 'id' or 'user_id')
$user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : (isset($_SESSION['id']) ? $_SESSION['id'] : null);
if ($user_id) {
    $user_stmt = $pdo->prepare("SELECT first_name FROM users WHERE user_id = ?");
    $user_stmt->execute([$user_id]);
    $user = $user_stmt->fetch(PDO::FETCH_ASSOC);
    $first_name = $user ? $user['first_name'] : $_SESSION['username'];
} else {
    $first_name = $_SESSION['username'];
}

// Fetch branch name
$branch_stmt = $pdo->prepare("SELECT branch_name FROM branch WHERE branch_id = ?");
$branch_stmt->execute([$branch_id]);
$branch = $branch_stmt->fetch(PDO::FETCH_ASSOC);
$branch_name = $branch ? $branch['branch_name'] : $branch_id;

// 1. Fetch Capacity Data for Cards
$stmt = $pdo->prepare("SELECT warehouse_capacity, inventory FROM volume_capacity WHERE branch_id = ?");
$stmt->execute([$branch_id]);
$cap = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['warehouse_capacity' => 0, 'inventory' => 0];

// 2. Fetch Notifications (Pending & Cancelled)
$notif_stmt = $pdo->prepare("SELECT appointment_id, first_name, last_name, status, date, is_read FROM appointments 
                             WHERE branch_id = ? AND status IN ('pending', 'cancelled') 
                             ORDER BY appointment_id DESC LIMIT 10");
$notif_stmt->execute([$branch_id]);
$notifications = $notif_stmt->fetchAll(PDO::FETCH_ASSOC);
// Count unread pending notifications for badge
$new_count = count(array_filter($notifications, fn($n) => $n['status'] == 'pending' && (empty($n['is_read']) || $n['is_read'] == 0)));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>NFA Processor Dashboard</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* Modern Top Navigation */
        .top-nav {
            background: #2c3e50;
            color: white;
            padding: 0.8rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 1000;
        }
        .logo {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        .nfa-logo {
            height: 64px;
            width: auto;
            display: block;
        }
        .nfa-title {
            font-size: 20px;
            font-weight: 700;
            color: white;
            letter-spacing: 1px;
        }
        .nav-links { display: flex; gap: 2rem; }
        .nav-links a { color: white; text-decoration: none; font-weight: 500; }
        .nav-links a.active { border-bottom: 2px solid #2ecc71; }

        /* Notification Styling */
        .notif-wrapper { position: relative; cursor: pointer; }
        .notif-badge {
            position: absolute; top: -5px; right: -10px;
            background: #e74c3c; color: white;
            border-radius: 50%; padding: 2px 6px; font-size: 0.7rem;
        }
        .notif-dropdown {
            display: none; position: absolute; right: 0; top: 35px;
            background: white; border: 1px solid #ddd; width: 300px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15); border-radius: 8px; color: #333;
        }
        .notif-item { padding: 10px; border-bottom: 1px solid #eee; font-size: 0.85rem; }
        .notif-item.unread { background: #f0f7ff; border-left: 4px solid #3498db; }
        
        /* Dashboard Grid from Image */
        .stats-grid {
            display: grid; grid-template-columns: repeat(4, 1fr);
            gap: 1.5rem; margin: 2rem 0;
        }
        .stat-card {
            background: white; padding: 1.5rem; border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05); text-align: left;
        }
        .stat-card h4 { color: #7f8c8d; font-size: 0.9rem; margin-bottom: 0.5rem; }
        .stat-card .value { font-size: 1.8rem; font-weight: bold; color: #2c3e50; }
        
        .chart-container {
            background: white; padding: 2rem; border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05); margin-top: 2rem;
        }
    </style>
</head>
<body class="dashboard">

    <nav class="top-nav">
        <div class="logo">
            <img src="img/nfa-logo.png" alt="NFA" class="nfa-logo">
            <span class="nfa-title">NFA Processor Portal</span>
        </div>
        <div class="nav-links">
            <a href="processor_dashboard.php" class="active"><i class="fas fa-chart-line"></i> Dashboard</a>
            <a href="operator.html"><i class="fas fa-list"></i> Appointment List</a>
        </div>
        <div class="user-actions" style="display:flex; gap: 20px; align-items:center;">
            <div class="notif-wrapper" id="notifWrapper">
                <i class="fas fa-bell fa-lg"></i>
                <?php if ($new_count > 0): ?>
                    <span class="notif-badge"><?php echo $new_count; ?></span>
                <?php endif; ?>
                <div class="notif-dropdown" id="notifDropdown">
                    <div style="padding:10px; border-bottom:1px solid #ddd;"><strong>Notifications</strong></div>
                    <div id="notifList" style="max-height:260px; overflow-y:auto;">
                    <?php foreach ($notifications as $i => $n): 
                        $is_read = isset($n['is_read']) ? $n['is_read'] : 0;
                        $unread = (empty($is_read) || $is_read == 0);
                        $display = ($i < 5) ? '' : 'display:none;';
                    ?>
                        <div class="notif-item <?php echo $unread ? 'unread' : ''; ?>" 
                            data-appointment-id="<?php echo $n['appointment_id']; ?>" style="<?php echo $display; ?>">
                            New appointment from <strong><?php echo htmlspecialchars($n['first_name']); ?></strong> for <?php echo $n['date']; ?>.
                            <br><small>Status: <?php echo ucfirst($n['status']); ?></small>
                            <button class="action-btn" style="padding:2px 5px; font-size:0.7rem; margin-top:5px;">
                                <?php echo ($unread ? 'Mark as Read' : 'Mark as Unread'); ?>
                            </button>
                        </div>
                    <?php endforeach; ?>
                    </div>
                    <?php if (count($notifications) > 5): ?>
                        <button id="seeAllBtn" onclick="toggleNotifView(true)" style="width:100%;padding:6px 0;background:#f4f4f4;border:none;cursor:pointer;">See All</button>
                        <button id="seeLessBtn" onclick="toggleNotifView(false)" style="width:100%;padding:6px 0;background:#f4f4f4;border:none;cursor:pointer;display:none;">See Less</button>
                    <?php endif; ?>
                </div>
            </div>
            <a href="login.php" class="logout-btn" style="text-decoration:none;">Logout</a>
        </div>
    </nav>

    <div class="container">
        <header style="margin-top:2rem;">
            <h2>Welcome, <?php echo htmlspecialchars($first_name); ?></h2>
            <p>Monitoring Branch: <strong style="color:#2ecc71;"><?php echo htmlspecialchars($branch_name); ?></strong></p>
        </header>

        <div class="stats-grid">
            <div class="stat-card" style="border-top: 4px solid #e74c3c;">
                <h4>Maximum Warehouse Capacity</h4>
                <div class="value"><?php echo number_format($cap['warehouse_capacity']); ?></div>
            </div>
            <div class="stat-card" style="border-top: 4px solid #3498db;">
                <h4>Current Stock</h4>
                <div class="value"><?php echo number_format($cap['inventory']); ?></div>
            </div>
            <div class="stat-card" style="border-top: 4px solid #2ecc71;">
                <h4>Available Stock Capacity</h4>
                <div class="value"><?php echo number_format($cap['warehouse_capacity'] - $cap['inventory']); ?></div>
            </div>
            <div class="stat-card" style="border-top: 4px solid #f39c12;">
                <h4>Pending Appointments</h4>
                <div class="value"><?php echo $new_count; ?></div>
            </div>
        </div>

        <!-- Tab Navigation for Graphs -->
        <div class="graph-tabs" style="display:flex; gap:2rem; margin-top:1.5rem;">
            <button id="tab-graph1" class="graph-tab active" style="padding:10px 24px; border:none; background:#3498db; color:white; border-radius:6px 6px 0 0; font-weight:600; cursor:pointer;">Warehouse Status</button>
            <button id="tab-graph2" class="graph-tab" style="padding:10px 24px; border:none; background:#ecf0f1; color:#2c3e50; border-radius:6px 6px 0 0; font-weight:600; cursor:pointer;">Yearly Graph</button>
        </div>

        <!-- Graph 1: Donut Chart -->
        <div class="chart-container" id="graph1-container">
            <h3 style="text-align:center;">Warehouse Status</h3>
            <canvas id="donutChart" height="220"></canvas>
            <div style="display:flex; justify-content:center; margin-top:1.5rem; gap:2rem;">
                <div style="display:flex; align-items:center;"><span style="display:inline-block;width:18px;height:18px;background:#3b82f6;border-radius:4px;margin-right:8px;"></span> <span style="font-weight:600; color:#3b82f6;">Current Stock</span></div>
                <div style="display:flex; align-items:center;"><span style="display:inline-block;width:18px;height:18px;background:#fbbc04;border-radius:4px;margin-right:8px;"></span> <span style="font-weight:600; color:#fbbc04;">Stock Expected to Arrive</span></div>
                <div style="display:flex; align-items:center;"><span style="display:inline-block;width:18px;height:18px;background:#10b981;border-radius:4px;margin-right:8px;"></span> <span style="font-weight:600; color:#10b981;">Available Stock Capacity</span></div>
            </div>
            <div id="donut-center-label" style="position:absolute;left:0;right:0;top:50%;transform:translateY(-50%);text-align:center;font-size:2.2rem;font-weight:700;color:#e74c3c;"></div>
        </div>

        <!-- Graph 2: Yearly Bar Chart (existing) -->
        <div class="chart-container" id="graph2-container" style="display:none;">
            <h3>Current Stock vs Available Capacity (2025)</h3>
            <canvas id="stockChart" height="100"></canvas>
        </div>
    </div>

    <script>
        // Toggle notification panel only when clicking the bell icon
        function toggleNotifs() {
            const dropdown = document.getElementById('notifDropdown');
            dropdown.style.display = dropdown.style.display === 'block' ? 'none' : 'block';
        }

        document.addEventListener('DOMContentLoaded', function() {
            const notifDropdown = document.getElementById('notifDropdown');
            const notifWrapper = document.getElementById('notifWrapper');
            // Open/close panel only when clicking the bell icon
            notifWrapper.addEventListener('click', function(e) {
                if (e.target.closest('.fa-bell')) {
                    toggleNotifs();
                }
            });
            // Only close when clicking outside the bell and dropdown
            document.addEventListener('click', function(e) {
                if (
                    notifDropdown.style.display === 'block' &&
                    !notifDropdown.contains(e.target) &&
                    !notifWrapper.contains(e.target)
                ) {
                    notifDropdown.style.display = 'none';
                }
            });
            // Prevent notification panel from closing when clicking Mark as Read/Unread or See All/See Less
            notifDropdown.addEventListener('click', function(e) {
                if (e.target.classList.contains('action-btn') || e.target.id === 'seeAllBtn' || e.target.id === 'seeLessBtn') {
                    e.stopPropagation();
                }
            });
            // Use event delegation for Mark as Read/Unread buttons
            const notifList = document.getElementById('notifList');
            if (notifList) {
                notifList.addEventListener('click', function(e) {
                    if (e.target && e.target.classList.contains('action-btn')) {
                        toggleRead(e.target);
                        e.stopPropagation();
                    }
                });
            }

            // --- Graph Tab Switching ---
            const tab1 = document.getElementById('tab-graph1');
            const tab2 = document.getElementById('tab-graph2');
            const graph1 = document.getElementById('graph1-container');
            const graph2 = document.getElementById('graph2-container');
            tab1.addEventListener('click', function() {
                tab1.classList.add('active');
                tab1.style.background = '#3498db';
                tab1.style.color = 'white';
                tab2.classList.remove('active');
                tab2.style.background = '#ecf0f1';
                tab2.style.color = '#2c3e50';
                graph1.style.display = '';
                graph2.style.display = 'none';
            });
            tab2.addEventListener('click', function() {
                tab2.classList.add('active');
                tab2.style.background = '#3498db';
                tab2.style.color = 'white';
                tab1.classList.remove('active');
                tab1.style.background = '#ecf0f1';
                tab1.style.color = '#2c3e50';
                graph1.style.display = 'none';
                graph2.style.display = '';
            });

            // --- Donut Chart (Graph 1) ---
            const donutCtx = document.getElementById('donutChart').getContext('2d');
            // PHP values for chart
            const warehouseCapacity = <?php echo (float)$cap['warehouse_capacity']; ?>;
            const inventory = <?php echo (float)$cap['inventory']; ?>;
            // For demo, expectedArrive is 20% of capacity (customize as needed)
            const expectedArrive = Math.round(warehouseCapacity * 0.2);
            const available = Math.max(warehouseCapacity - inventory - expectedArrive, 0);
            const currentStockPercent = warehouseCapacity > 0 ? (inventory / warehouseCapacity) * 100 : 0;
            const expectedArrivePercent = warehouseCapacity > 0 ? (expectedArrive / warehouseCapacity) * 100 : 0;
            const availablePercent = warehouseCapacity > 0 ? (available / warehouseCapacity) * 100 : 0;
            const donutData = [inventory, expectedArrive, available];
            const donutColors = ['#3b82f6', '#fbbc04', '#10b981'];
            const donutChart = new Chart(donutCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Current Stock', 'Stock Expected to Arrive', 'Available Stock Capacity'],
                    datasets: [{
                        data: donutData,
                        backgroundColor: donutColors,
                        borderWidth: 3,
                        borderColor: '#fff',
                        hoverOffset: 8
                    }]
                },
                options: {
                    cutout: '70%',
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    let label = context.label || '';
                                    let value = context.raw;
                                    let percent = 0;
                                    if (context.dataset && context.dataset.data) {
                                        let total = context.dataset.data.reduce((a, b) => a + b, 0);
                                        percent = total > 0 ? (value / total * 100) : 0;
                                    }
                                    return `${label}: ${value} (${percent.toFixed(1)}%)`;
                                }
                            }
                        }
                    }
                }
            });
            // Center label for donut chart
            function updateDonutCenter() {
                const center = document.getElementById('donut-center-label');
                center.innerHTML = `<div style='font-size:1.2rem;color:#222;'>Available Capacity:</div><div style='font-size:2.5rem;font-weight:700;color:#e74c3c;'>${availablePercent.toFixed(0)}%</div>`;
            }
            updateDonutCenter();

            // --- Yearly Bar Chart (Graph 2, existing) ---
            const ctx = document.getElementById('stockChart').getContext('2d');
            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
                    datasets: [{
                        label: 'Current Stock',
                        data: [2000, 2500, 3000, 4500, 2800, 3200, 3500, 4000, 4200, 4800, <?php echo $cap['inventory']; ?>, 0],
                        backgroundColor: '#3498db'
                    }, {
                        label: 'Available Capacity',
                        data: [8000, 7500, 7000, 5500, 7200, 6800, 6500, 6000, 5800, 5200, <?php echo ($cap['warehouse_capacity'] - $cap['inventory']); ?>, 10000],
                        backgroundColor: '#2ecc71'
                    }]
                },
                options: {
                    responsive: true,
                    plugins: { legend: { position: 'top' } },
                    scales: { y: { beginAtZero: true, title: { display: true, text: 'Volume (Bags)' } } }
                }
            });
        });

        function toggleRead(btn) {
            // 1. Find the parent notification container
            const item = btn.closest('.notif-item');
            if (!item) return; // Safety check

            const appointmentId = item.getAttribute('data-appointment-id');

            // Check current UI state: if it has 'unread' class, it is currently unread (is_read = 0)
            const isCurrentlyUnread = item.classList.contains('unread');

            // Determine the NEW state to send to DB
            // If it WAS unread, we want to mark it as read (1).
            const newStateIsRead = isCurrentlyUnread ? 1 : 0;

            // 2. REAL-TIME UI UPDATE (Immediate)
            if (newStateIsRead === 1) {
                item.classList.remove('unread'); // Remove the blue/highlight background
                btn.textContent = 'Mark as Unread';
                updateNotifCount(-1); // Decrease the red badge number
            } else {
                item.classList.add('unread'); // Re-add the highlight
                btn.textContent = 'Mark as Read';
                updateNotifCount(1); // Increase the red badge number
            }

            // 3. DATABASE SYNC
            fetch('php_helper/api.php?action=updateNotification', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    appointment_id: appointmentId,
                    is_read: newStateIsRead
                })
            })
            .then(r => r.json())
            .then(data => {
                if (!data.success) {
                    // Revert UI if the server failed
                    // Revert the UI changes
                    if (newStateIsRead === 1) {
                        item.classList.add('unread');
                        btn.textContent = 'Mark as Read';
                        updateNotifCount(1);
                    } else {
                        item.classList.remove('unread');
                        btn.textContent = 'Mark as Unread';
                        updateNotifCount(-1);
                    }
                    alert('Failed to update database. Reverting UI.');
                }
            })
            .catch(err => {
                // Revert UI on error
                if (newStateIsRead === 1) {
                    item.classList.add('unread');
                    btn.textContent = 'Mark as Read';
                    updateNotifCount(1);
                } else {
                    item.classList.remove('unread');
                    btn.textContent = 'Mark as Unread';
                    updateNotifCount(-1);
                }
                console.error('Network Error:', err);
                alert('Connection error. Could not reach the server.');
            });
        }

        function updateNotifCount(delta) {
            let badge = document.querySelector('.notif-badge');
            const wrapper = document.querySelector('.notif-wrapper');
            
            if (badge) {
                let currentCount = parseInt(badge.textContent) || 0;
                let newCount = currentCount + delta;
                
                if (newCount <= 0) {
                    badge.remove(); // Remove badge if 0
                } else {
                    badge.textContent = newCount;
                }
            } else if (delta > 0) {
                // Create badge if it was at 0
                const newBadge = document.createElement('span');
                newBadge.className = 'notif-badge';
                newBadge.textContent = delta;
                wrapper.appendChild(newBadge);
            }
        }

        function toggleNotifView(showAll) {
            const notifItems = document.querySelectorAll('#notifList .notif-item');
            const seeAllBtn = document.getElementById('seeAllBtn');
            const seeLessBtn = document.getElementById('seeLessBtn');
            notifItems.forEach((item, idx) => {
                item.style.display = (showAll || idx < 5) ? '' : 'none';
            });
            if (showAll) {
                seeAllBtn.style.display = 'none';
                seeLessBtn.style.display = '';
            } else {
                seeAllBtn.style.display = '';
                seeLessBtn.style.display = 'none';
            }
        }
    </script>
</body>
</html>