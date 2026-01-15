<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once 'db_config.php'; 

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

function sanitize_input($data) {
    if (is_string($data)) {
        return trim($data);
    }
    return $data;
}

// Ensure the PDO connection is available
global $pdo;
if (!isset($pdo)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed during initialization.']);
    exit();
}

// --- Main Request Handler ---
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'generateReferenceNumber':
        // Generate a reference number for preview (final upon submission)
        try {
            $makeRef = function () {
                return 'NFA' . date('Ymd') . strtoupper(substr(md5(uniqid(rand(), true)), 0, 6));
            };

            $reference_number = $makeRef();
            // Ensure it's not already used (best-effort; collisions are extremely unlikely)
            for ($i = 0; $i < 5; $i++) {
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM appointments WHERE reference_number = ?');
                $stmt->execute([$reference_number]);
                $exists = (int)$stmt->fetchColumn() > 0;
                if (!$exists) break;
                $reference_number = $makeRef();
            }

            echo json_encode(['success' => true, 'referenceNumber' => $reference_number]);
        } catch (\PDOException $e) {
            error_log('Reference generation failed: ' . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Failed to generate reference number.']);
        }
        break;

    case 'checkUsername':
        // Check if a username already exists (used by registration page)
        try {
            $username = (string)sanitize_input($_GET['username'] ?? '');
            if ($username === '') {
                echo json_encode(['success' => true, 'exists' => false]);
                break;
            }

            $stmt = $pdo->prepare('SELECT 1 FROM users WHERE username = ? LIMIT 1');
            $stmt->execute([$username]);
            $exists = (bool)$stmt->fetchColumn();

            echo json_encode(['success' => true, 'exists' => $exists]);
        } catch (\PDOException $e) {
            error_log('Username check failed: ' . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Failed to check username.']);
        }
        break;

    case 'checkEmail':
        // Check if an email is already registered (used by registration page)
        try {
            $email = (string)sanitize_input($_GET['email'] ?? '');
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                echo json_encode(['success' => true, 'exists' => false]);
                break;
            }

            $stmt = $pdo->prepare('SELECT 1 FROM users WHERE email_address = ? LIMIT 1');
            $stmt->execute([$email]);
            $exists = (bool)$stmt->fetchColumn();

            echo json_encode(['success' => true, 'exists' => $exists]);
        } catch (\PDOException $e) {
            error_log('Email check failed: ' . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Failed to check email.']);
        }
        break;

    case 'getRegions':
        // 1. Get Regions
        try {
            $stmt = $pdo->query("SELECT region_id, region_name FROM regions ORDER BY region_name");
            $regions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'data' => $regions]);
        } catch (\PDOException $e) {
            error_log("Region fetch failed: " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Failed to retrieve regions.']);
        }
        break;

    case 'getBranches':
        // 2. Get Branches filtered by Region
        $region_id = (int)sanitize_input($_GET['region_id'] ?? 0); 
        if (!$region_id) {
            echo json_encode(['success' => true, 'data' => []]);
            break;
        }
        try {
            $stmt = $pdo->prepare("SELECT branch_id, branch_name FROM branch WHERE region_id = ? ORDER BY branch_name");
            $stmt->execute([$region_id]);
            $branches = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'data' => $branches]);
        } catch (\PDOException $e) {
            error_log("Branch fetch failed: " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Failed to retrieve branches. Please check the `branch` table exists and has data linked to the selected region.']);
        }
        break;

    case 'getBranchInfo':
        // 3. Consolidated fetch for Capacity, Slots, and Availability
    $branch_id = (int)sanitize_input($_GET['branch_id'] ?? 0);
    $start_date = sanitize_input($_GET['start_date'] ?? date('Y-m-01'));
    $end_date = sanitize_input($_GET['end_date'] ?? date('Y-m-t'));

        if (!$branch_id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Missing branch ID.']);
            exit();
        }

        try {
            error_log("DEBUG: getBranchInfo starting for branch_id=$branch_id");
            
            // A. Fetch Volume Capacity (Q3)
            error_log("DEBUG: Fetching volume capacity");
            $stmt_vol = $pdo->prepare("SELECT warehouse_capacity, inventory FROM volume_capacity WHERE branch_id = :branch_id");
            $stmt_vol->bindParam(':branch_id', $branch_id, PDO::PARAM_INT);
            $stmt_vol->execute();
            $vol_data = $stmt_vol->fetch(PDO::FETCH_ASSOC);
            error_log("DEBUG: Volume data: " . json_encode($vol_data));
            
            $volume_info = [
                'total_capacity' => (float)($vol_data['warehouse_capacity'] ?? 0),
                'inventory' => (float)($vol_data['inventory'] ?? 0),
                'available_volume' => max(0, (float)($vol_data['warehouse_capacity'] ?? 0) - (float)($vol_data['inventory'] ?? 0))
            ];

            // B. Fetch Default Slot Capacity (Used by Q4/Q5 logic)
            error_log("DEBUG: Fetching slot capacity");
            // First try with date IS NULL or empty string for defaults (SQL dump uses '' for default rows)
            $stmt_default_cap = $pdo->prepare("SELECT capacity_am, capacity_pm FROM branch_slot_capacity WHERE branch_id = :branch_id AND (`date` IS NULL OR `date` = '')");
            $stmt_default_cap->bindParam(':branch_id', $branch_id, PDO::PARAM_INT);
            $stmt_default_cap->execute();
            $default_capacity = $stmt_default_cap->fetch(PDO::FETCH_ASSOC);
            
            if (!$default_capacity) {
                // If no default found, try getting the latest capacity for this branch
                error_log("DEBUG: No default capacity found, checking for any capacity entry");
                $stmt_any_cap = $pdo->prepare("SELECT capacity_am, capacity_pm FROM branch_slot_capacity WHERE branch_id = :branch_id ORDER BY capacity_id DESC LIMIT 1");
                $stmt_any_cap->bindParam(':branch_id', $branch_id, PDO::PARAM_INT);
                $stmt_any_cap->execute();
                $default_capacity = $stmt_any_cap->fetch(PDO::FETCH_ASSOC);
            }
            
            // If still no capacity found, use safe defaults
            $default_capacity = $default_capacity ?: ['capacity_am' => 5, 'capacity_pm' => 5];
            error_log("DEBUG: Using capacity: " . json_encode($default_capacity));

            // C. Fetch Booked Appointments (Q4/Q5)
            $sql_booked = "SELECT `date`, time_slot, COUNT(appointment_id) as booked_count FROM appointments 
                           WHERE branch_id = :branch_id AND `date` BETWEEN :start_date AND :end_date 
                           AND status != 'cancelled' GROUP BY `date`, time_slot";
            $stmt_booked = $pdo->prepare($sql_booked);
            $stmt_booked->bindParam(':branch_id', $branch_id, PDO::PARAM_INT);
            $stmt_booked->bindParam(':start_date', $start_date, PDO::PARAM_STR);
            $stmt_booked->bindParam(':end_date', $end_date, PDO::PARAM_STR);
            $stmt_booked->execute();
            
            $booked_slots = [];
            foreach ($stmt_booked->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $booked_slots[$row['date']][$row['time_slot']] = (int)$row['booked_count'];
            }

            // D. Fetch Holidays (safely handle missing holidays table)
            error_log("DEBUG: Checking for holidays");
            $holidays = [];
            $holiday_details = [];
            try {
                $sql_holidays = "SELECT holiday_date, holiday_name FROM holidays WHERE holiday_date BETWEEN :start_date AND :end_date";
                $stmt_holidays = $pdo->prepare($sql_holidays);
                $stmt_holidays->bindParam(':start_date', $start_date, PDO::PARAM_STR);
                $stmt_holidays->bindParam(':end_date', $end_date, PDO::PARAM_STR);
                $stmt_holidays->execute();
                $holiday_details = $stmt_holidays->fetchAll(PDO::FETCH_ASSOC);
                $holidays = array_column($holiday_details, 'holiday_date');
            } catch (\PDOException $e) {
                // If holidays table doesn't exist, just continue with empty holidays array
                error_log("Notice: Holidays table may not exist: " . $e->getMessage());
            }
            
            // E. Calculate Daily Availability (Q4 logic)
            $availability_data = [];
            $start = new DateTime($start_date);
            $end = new DateTime($end_date);
            $end->modify('+1 day'); 

            $interval = DateInterval::createFromDateString('1 day');
            $period = new DatePeriod($start, $interval, $end);

            foreach ($period as $dt) {
                $date_str = $dt->format("Y-m-d");
                $day_of_week = $dt->format('w'); // 0 (Sun) to 6 (Sat)
                
                $is_weekend = ($day_of_week == 0 || $day_of_week == 6);
                $is_holiday = in_array($date_str, $holidays);
                
                $am_booked = $booked_slots[$date_str]['AM'] ?? 0;
                $pm_booked = $booked_slots[$date_str]['PM'] ?? 0;

                $am_cap = $default_capacity['capacity_am'];
                $pm_cap = $default_capacity['capacity_pm'];

                $am_available = $am_cap - $am_booked;
                $pm_available = $pm_cap - $pm_booked;

                // Condition for disabling: weekend, holiday, OR (AM is full AND PM is full)
                $is_full = ($am_available <= 0 && $pm_available <= 0);
                $is_disabled = $is_weekend || $is_holiday || $is_full;

                $availability_data[$date_str] = [
                    'am_remaining' => max(0, $am_available),
                    'pm_remaining' => max(0, $pm_available),
                    'am_capacity' => $am_cap,
                    'pm_capacity' => $pm_cap,
                    'is_disabled' => $is_disabled
                ];
            }
            
            // F. Send Consolidated Response
            echo json_encode([
                'success' => true,
                'capacity_info' => $volume_info,
                'default_slot_capacity' => $default_capacity,
                'daily_availability' => $availability_data,
                'holidays' => $holidays,
                'holiday_details' => $holiday_details
            ]);

        } catch (\PDOException $e) {
            // Log full error server-side
            error_log("Branch info fetch failed: " . $e->getMessage());

            // Return a helpful error message for debugging (remove 'debug' in production)
            $clientError = 'Database query failed to fetch branch info.';
            echo json_encode([
                'success' => false,
                'error' => $clientError,
                'debug' => $e->getMessage()
            ]);
        }
        break;
        
    case 'getFarmerTypes':
        // 4. Get Farmer Types
        try {
            $stmt = $pdo->query("SELECT farmer_type_id, type_name FROM farmer_type ORDER BY type_name");
            $types = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'data' => $types]);
        } catch (\PDOException $e) {
            error_log("Farmer Types fetch failed: " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Failed to retrieve farmer types.']);
        }
        break;

    case 'submitAppointment':
        // 5. Submit Appointment (Merged from submit_appointment.php logic)
        error_log("DEBUG: Starting appointment submission");
        $raw_input = file_get_contents('php://input');
        error_log("DEBUG: Raw input: " . $raw_input);
        $data_raw = json_decode($raw_input, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("JSON decode error: " . json_last_error_msg());
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid JSON data received']);
            exit;
        }
        
        $data = [
            'branch_id'      => (int)sanitize_input($data_raw['branch_id'] ?? 0),
            'date'           => sanitize_input($data_raw['date'] ?? null),
            'time_slot'      => sanitize_input($data_raw['time_slot'] ?? null),
            'first_name'     => sanitize_input($data_raw['firstName'] ?? null),
            'middle_name'    => sanitize_input($data_raw['middleName'] ?? null),
            'last_name'      => sanitize_input($data_raw['lastName'] ?? null),
            'farmer_id'      => sanitize_input($data_raw['farmer_id'] ?? null),
            'suffix'         => sanitize_input($data_raw['suffix'] ?? null),
            'g_recaptcha_response' => sanitize_input($data_raw['g_recaptcha_response'] ?? $data_raw['recaptcha'] ?? null),
            'email'          => sanitize_input($data_raw['email'] ?? null),
            'contact_number' => sanitize_input($data_raw['contact'] ?? null),
            'gender'         => sanitize_input($data_raw['gender'] ?? null),
            'volume'         => (float)sanitize_input($data_raw['volume'] ?? 0),
            'farmer_type_id' => (int)sanitize_input($data_raw['farmer_type_id'] ?? 0), 
            'reference_number' => sanitize_input($data_raw['reference_number'] ?? ''),
        ];
        
        error_log("DEBUG: Processed data: " . json_encode($data));

        if (!$data['branch_id'] || !$data['date'] || !$data['first_name'] || !$data['last_name'] || !$data['farmer_id'] || $data['volume'] <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Missing required appointment data or invalid volume.']);
            exit;
        }

        // Verify reCAPTCHA token is present
        if (empty($data['g_recaptcha_response'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Missing reCAPTCHA token.']);
            exit;
        }

        // Verify reCAPTCHA with Google's siteverify API (v2)
        $recaptcha_secret = '6LcdCQwsAAAAAJ3xlp-YTJp_Rgy_EC77jQ02ZnU9'; // provided secret
        $recaptcha_response = $data['g_recaptcha_response'];

        $verify_url = 'https://www.google.com/recaptcha/api/siteverify';
        $ch = curl_init($verify_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'secret' => $recaptcha_secret,
            'response' => $recaptcha_response,
            'remoteip' => $_SERVER['REMOTE_ADDR'] ?? ''
        ]));
        $verify_resp = curl_exec($ch);
        $curl_err = curl_error($ch);
        curl_close($ch);

        if ($verify_resp === false || !$verify_resp) {
            error_log('reCAPTCHA verify HTTP error: ' . $curl_err);
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to verify reCAPTCHA.']);
            exit;
        }

        $verify_data = json_decode($verify_resp, true);
        if (!isset($verify_data['success']) || $verify_data['success'] !== true) {
            error_log('reCAPTCHA verification failed: ' . $verify_resp);
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'reCAPTCHA verification failed.']);
            exit;
        }
        
        $reference_number = null;
        $client_ref = is_string($data['reference_number']) ? trim($data['reference_number']) : '';
        if ($client_ref !== '' && preg_match('/^NFA\d{8}[A-Z0-9]{6}$/', $client_ref)) {
            // Accept client pre-generated ref if not already used
            $stmt_ref = $pdo->prepare('SELECT COUNT(*) FROM appointments WHERE reference_number = ?');
            $stmt_ref->execute([$client_ref]);
            $exists = (int)$stmt_ref->fetchColumn() > 0;
            if (!$exists) {
                $reference_number = $client_ref;
            }
        }

        if (!$reference_number) {
            $reference_number = 'NFA' . date('Ymd') . strtoupper(substr(md5(uniqid(rand(), true)), 0, 6));
            // Best-effort uniqueness
            for ($i = 0; $i < 5; $i++) {
                $stmt_ref = $pdo->prepare('SELECT COUNT(*) FROM appointments WHERE reference_number = ?');
                $stmt_ref->execute([$reference_number]);
                $exists = (int)$stmt_ref->fetchColumn() > 0;
                if (!$exists) break;
                $reference_number = 'NFA' . date('Ymd') . strtoupper(substr(md5(uniqid(rand(), true)), 0, 6));
            }
        }

        try {
            $pdo->beginTransaction();

            error_log("DEBUG: Starting appointment insert");
            // Get region_id from branch (required by appointments table)
            $stmt_region = $pdo->prepare("SELECT region_id FROM branch WHERE branch_id = ?");
            $stmt_region->execute([$data['branch_id']]);
            $region_id = $stmt_region->fetchColumn();
            
            if (!$region_id) {
                throw new \PDOException("Invalid branch_id or region not found");
            }

            // Insert into appointments table (including farmer_id and suffix)
            $sql = "INSERT INTO appointments 
                    (branch_id, region_id, date, time_slot, first_name, middle_name, last_name, 
                     farmer_id, suffix, email, contact_number, gender, volume, farmer_type_id, status, reference_number) 
                    VALUES 
                    (:branch_id, :region_id, :date, :time_slot, :first_name, :middle_name, :last_name,
                     :farmer_id, :suffix, :email, :contact_number, :gender, :volume, :farmer_type_id, 'pending', :reference_number)";

            $stmt = $pdo->prepare($sql);
            $params = [
                ':branch_id' => $data['branch_id'],
                ':region_id' => $region_id,
                ':date' => $data['date'],
                ':time_slot' => $data['time_slot'],
                ':first_name' => $data['first_name'],
                ':middle_name' => $data['middle_name'],
                ':last_name' => $data['last_name'],
                ':farmer_id' => $data['farmer_id'],
                ':suffix' => $data['suffix'],
                ':email' => $data['email'],
                ':contact_number' => $data['contact_number'],
                ':gender' => $data['gender'],
                ':volume' => $data['volume'],
                ':farmer_type_id' => $data['farmer_type_id'],
                ':reference_number' => $reference_number
            ];
            error_log("DEBUG: SQL params: " . json_encode($params));
            $stmt->execute($params);
            
            $pdo->commit();

            // --- EMAIL NOTIFICATION LOGIC ---
            $smtpHost = 'smtp.gmail.com';
            $smtpUser = 'anonymous.00112211@gmail.com';
            $smtpPass = 'xwucrpggtanqrvwp';
            $smtpPort = 587;

            $sessionWindow = ($data['time_slot'] === 'AM') ? '8:00 AM – 12:00 NN' : '1:00 PM – 5:00 PM';
            $farmerFullName = trim($data['first_name'] . ' ' . ($data['middle_name'] ? ($data['middle_name'] . ' ') : '') . $data['last_name'] . ($data['suffix'] ? (' ' . $data['suffix']) : ''));

            $branchName = 'Branch ID ' . $data['branch_id'];
            try {
                $stmt_branch = $pdo->prepare("SELECT branch_name FROM branch WHERE branch_id = ? LIMIT 1");
                $stmt_branch->execute([$data['branch_id']]);
                $branchRow = $stmt_branch->fetch(PDO::FETCH_ASSOC);
                if ($branchRow && !empty($branchRow['branch_name'])) {
                    $branchName = $branchRow['branch_name'];
                }
            } catch (Exception $e) {
                // Best-effort only; keep branchName fallback.
            }

            $referenceSafe = htmlspecialchars($reference_number, ENT_QUOTES, 'UTF-8');
            $farmerFullNameSafe = htmlspecialchars($farmerFullName, ENT_QUOTES, 'UTF-8');
            $branchNameSafe = htmlspecialchars($branchName, ENT_QUOTES, 'UTF-8');
            $dateSafe = htmlspecialchars($data['date'], ENT_QUOTES, 'UTF-8');
            $slotSafe = htmlspecialchars($data['time_slot'], ENT_QUOTES, 'UTF-8');
            $windowSafe = htmlspecialchars($sessionWindow, ENT_QUOTES, 'UTF-8');
            $farmerIdSafe = htmlspecialchars($data['farmer_id'], ENT_QUOTES, 'UTF-8');
            $volumeSafe = htmlspecialchars((string)$data['volume'], ENT_QUOTES, 'UTF-8');

                        $confirmationDocHtml = "<!doctype html>
<html lang=\"en\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">
<title>NFA Appointment Confirmation - {$referenceSafe}</title>
<style>
  body{font-family:Arial,Helvetica,sans-serif;color:#111;line-height:1.4;margin:0;padding:24px;background:#f6f7f9;}
  .doc{max-width:820px;margin:0 auto;background:#fff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;}
  .hdr{padding:18px 22px;border-bottom:1px solid #e5e7eb;display:flex;gap:14px;align-items:center;}
  .hdr h1{font-size:18px;margin:0;}
  .sub{color:#6b7280;font-size:12px;margin-top:4px;}
  .sec{padding:18px 22px;}
  .grid{display:grid;grid-template-columns:1fr 1fr;gap:10px 18px;}
  .row{display:flex;justify-content:space-between;gap:12px;padding:10px 12px;border:1px solid #eef0f3;border-radius:10px;background:#fafafa;}
  .k{color:#374151;font-weight:700;font-size:12px;text-transform:uppercase;letter-spacing:.04em;}
  .v{font-weight:700;color:#0b6a2b;}
  .note{margin-top:14px;color:#374151;font-size:13px;}
  .muted{color:#6b7280;font-size:12px;}
</style></head>
<body>
  <div class=\"doc\">
    <div class=\"hdr\">
      <div>
        <h1>National Food Authority — Appointment Confirmation</h1>
        <div class=\"sub\">System Version 1 • Last Updated: January 2026</div>
      </div>
    </div>
    <div class=\"sec\">
      <div class=\"grid\">
                <div class=\"row\"><div class=\"k\">Reference No.</div><div class=\"v\">{$referenceSafe}</div></div>
        <div class=\"row\"><div class=\"k\">Status</div><div class=\"v\">Pending Approval</div></div>
                <div class=\"row\"><div class=\"k\">Branch</div><div>{$branchNameSafe}</div></div>
                <div class=\"row\"><div class=\"k\">Date</div><div>{$dateSafe}</div></div>
                <div class=\"row\"><div class=\"k\">Session</div><div>{$slotSafe} ({$windowSafe})</div></div>
                <div class=\"row\"><div class=\"k\">Farmer</div><div>{$farmerFullNameSafe}</div></div>
                <div class=\"row\"><div class=\"k\">Farmer ID</div><div>{$farmerIdSafe}</div></div>
                <div class=\"row\"><div class=\"k\">Volume</div><div>{$volumeSafe} bags</div></div>
      </div>
      <p class=\"note\"><strong>Arrival guidance:</strong> You may arrive anytime within your selected session window. Please bring your Farmer ID and this confirmation.</p>
      <p class=\"muted\">If you need corrections or assistance, contact your NFA branch or support@nfa.gov.ph.</p>
    </div>
  </div>
</body></html>";

            // 1) Notify processor (best-effort)
            try {
                $stmt_proc = $pdo->prepare("SELECT email_address FROM users WHERE branch_id = ? AND user_type = 'Processor' LIMIT 1");
                $stmt_proc->execute([$data['branch_id']]);
                $processor = $stmt_proc->fetch(PDO::FETCH_ASSOC);

                if ($processor && !empty($processor['email_address'])) {
                    $mailProc = new PHPMailer(true);
                    $mailProc->isSMTP();
                    $mailProc->Host = $smtpHost;
                    $mailProc->SMTPAuth = true;
                    $mailProc->Username = $smtpUser;
                    $mailProc->Password = $smtpPass;
                    $mailProc->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    $mailProc->Port = $smtpPort;

                    $mailProc->setFrom('no-reply@nfa.gov.ph', 'NFA Appointment System');
                    $mailProc->addAddress($processor['email_address']);
                    $mailProc->isHTML(true);
                    $mailProc->Subject = 'New Appointment: ' . $reference_number;
                    $mailProc->Body = "
                        <h3>New Farmer Appointment Notification</h3>
                        <p>An appointment has been scheduled and requires review.</p>
                        <ul>
                            <li><strong>Farmer:</strong> {$farmerFullName}</li>
                            <li><strong>Reference #:</strong> {$reference_number}</li>
                            <li><strong>Date:</strong> {$data['date']}</li>
                            <li><strong>Slot:</strong> {$data['time_slot']} ({$sessionWindow})</li>
                            <li><strong>Volume:</strong> {$data['volume']} bags</li>
                            <li><strong>Branch:</strong> {$branchName}</li>
                        </ul>
                        <p>Please log in to the portal to manage this appointment.</p>
                    ";

                    $mailProc->send();
                }
            } catch (Exception $e) {
                error_log("Processor email notification failed: " . $e->getMessage());
            }

            // 2) Confirmation email to farmer (best-effort)
            try {
                if (!empty($data['email'])) {
                    $mailFarmer = new PHPMailer(true);
                    $mailFarmer->isSMTP();
                    $mailFarmer->Host = $smtpHost;
                    $mailFarmer->SMTPAuth = true;
                    $mailFarmer->Username = $smtpUser;
                    $mailFarmer->Password = $smtpPass;
                    $mailFarmer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    $mailFarmer->Port = $smtpPort;

                    $mailFarmer->setFrom('no-reply@nfa.gov.ph', 'NFA Appointment System');
                    $mailFarmer->addAddress($data['email']);
                    $mailFarmer->isHTML(true);
                    $mailFarmer->Subject = 'Appointment Request Received: ' . $reference_number;
                    $mailFarmer->Body = "
                        <p>Good day {$farmerFullName},</p>
                        <p>Your appointment request has been received and is currently <strong>Pending Approval</strong>.</p>
                        <ul>
                            <li><strong>Reference No.:</strong> {$reference_number}</li>
                            <li><strong>Date:</strong> {$data['date']}</li>
                            <li><strong>Session:</strong> {$data['time_slot']} ({$sessionWindow})</li>
                            <li><strong>Volume:</strong> {$data['volume']} bags</li>
                            <li><strong>Branch:</strong> {$branchName}</li>
                        </ul>
                        <p>You may arrive anytime within your selected session window. Please bring your Farmer ID and a copy of your confirmation.</p>
                        <p>Attached is your appointment confirmation document for printing or saving.</p>
                        <p>Thank you.</p>
                    ";

                    $mailFarmer->addStringAttachment($confirmationDocHtml, 'Appointment_Confirmation_' . $reference_number . '.html', 'base64', 'text/html');
                    $mailFarmer->send();
                }
            } catch (Exception $e) {
                error_log("Farmer confirmation email failed: " . $e->getMessage());
            }

            echo json_encode(['success' => true, 'referenceNumber' => $reference_number]);

        } catch (\PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Appointment Insert Error: " . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'success' => false, 
                'error' => 'Failed to book appointment due to server error.',
                'debug' => $e->getMessage()
            ]);
        } catch (\Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("General Error in appointment submission: " . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'An unexpected error occurred.',
                'debug' => $e->getMessage()
            ]);
        }
        break;

    // --- Notification Read/Unread Handler (POST) ---
    case 'updateNotification':
    $data = json_decode(file_get_contents('php://input'), true);
    $appointment_id = (int)($data['appointment_id'] ?? 0);
    $is_read = isset($data['is_read']) ? (int)$data['is_read'] : 0; // Capture the status from JS

    if ($appointment_id) {
        try {
            $stmt = $pdo->prepare("UPDATE appointments SET is_read = ? WHERE appointment_id = ?");
            $stmt->execute([$is_read, $appointment_id]);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid ID']);
    }
    break;

    case 'getWeeklyData':
        // 6. Weekly activity data for processor dashboard
        $branch_id = (int)sanitize_input($_GET['branch_id'] ?? 0);
        $days = (int)sanitize_input($_GET['days'] ?? 7);

        if ($branch_id <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Missing branch ID.']);
            break;
        }

        if ($days <= 0) { $days = 7; }
        if ($days > 60) { $days = 60; }

        $start_date = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));

        try {
            $stmt = $pdo->prepare("SELECT DATE(date) AS day, COUNT(*) AS count, SUM(volume) AS total_volume
                                   FROM appointments
                                   WHERE branch_id = ? AND date >= ? AND status != 'cancelled'
                                   GROUP BY DATE(date)
                                   ORDER BY day");
            $stmt->execute([$branch_id, $start_date]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $byDate = [];
            foreach ($rows as $row) {
                $byDate[$row['day']] = [
                    'count' => (int)$row['count'],
                    'volume' => (float)$row['total_volume']
                ];
            }

            $labels = [];
            $counts = [];
            $volumes = [];

            for ($i = $days - 1; $i >= 0; $i--) {
                $date = date('Y-m-d', strtotime('-' . $i . ' days'));
                $labels[] = date('D', strtotime($date));

                if (isset($byDate[$date])) {
                    $counts[] = $byDate[$date]['count'];
                    $volumes[] = (int)$byDate[$date]['volume'];
                } else {
                    $counts[] = 0;
                    $volumes[] = 0;
                }
            }

            echo json_encode([
                'success' => true,
                'labels' => $labels,
                'counts' => $counts,
                'volumes' => $volumes
            ]);
        } catch (\PDOException $e) {
            error_log('getWeeklyData failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to retrieve weekly data.']);
        }
        break;

    case 'confirmAppointment':
        // 7. Confirm an appointment from processor dashboard
        $data = json_decode(file_get_contents('php://input'), true);
        $appointment_id = (int)sanitize_input($data['appointment_id'] ?? 0);

        if ($appointment_id <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid appointment ID.']);
            break;
        }

        try {
            $stmt = $pdo->prepare("UPDATE appointments SET status = 'confirmed' WHERE appointment_id = ? AND status != 'cancelled'");
            $stmt->execute([$appointment_id]);

            echo json_encode(['success' => true]);
        } catch (\PDOException $e) {
            error_log('confirmAppointment failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to confirm appointment.']);
        }
        break;

    case 'completeAppointment':
        // 8. Mark an appointment as completed and adjust inventory
        $data = json_decode(file_get_contents('php://input'), true);
        $appointment_id = (int)sanitize_input($data['appointment_id'] ?? 0);
        $new_volume = (float)sanitize_input($data['volume'] ?? 0);

        if ($appointment_id <= 0 || $new_volume < 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid appointment data.']);
            break;
        }

        try {
            $pdo->beginTransaction();

            // Fetch appointment and branch
            $stmt = $pdo->prepare("SELECT branch_id, region_id, volume, status FROM appointments WHERE appointment_id = ? FOR UPDATE");
            $stmt->execute([$appointment_id]);
            $appt = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$appt) {
                $pdo->rollBack();
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Appointment not found.']);
                break;
            }

            $branch_id = (int)$appt['branch_id'];
            $region_id = (int)$appt['region_id'];
            $status = strtolower((string)($appt['status'] ?? ''));

            if ($status === 'cancelled') {
                $pdo->rollBack();
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Cannot record delivery for a cancelled appointment.']);
                break;
            }
            if ($status === 'completed') {
                $pdo->rollBack();
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'This appointment was already completed.']);
                break;
            }

            // Update appointment volume and status
            $stmtUp = $pdo->prepare("UPDATE appointments SET volume = ?, status = 'completed' WHERE appointment_id = ?");
            $stmtUp->execute([$new_volume, $appointment_id]);

            // Adjust inventory for the branch
            $stmtCap = $pdo->prepare("SELECT volume_id, inventory FROM volume_capacity WHERE branch_id = ? FOR UPDATE");
            $stmtCap->execute([$branch_id]);
            $cap = $stmtCap->fetch(PDO::FETCH_ASSOC);

            if ($cap) {
                // Inventory represents actual received bags: always ADD delivered volume on completion.
                $new_inventory = (float)$cap['inventory'] + $new_volume;
                $stmtInv = $pdo->prepare("UPDATE volume_capacity SET inventory = ? WHERE branch_id = ?");
                $stmtInv->execute([$new_inventory, $branch_id]);
            } else {
                // If capacity row doesn't exist for this branch, create it so inventory can be tracked.
                $stmtIns = $pdo->prepare("INSERT INTO volume_capacity (region_id, branch_id, warehouse_capacity, inventory) VALUES (?, ?, ?, ?)");
                $stmtIns->execute([$region_id, $branch_id, 0, $new_volume]);
                $new_inventory = $new_volume;
            }

            $pdo->commit();
            echo json_encode(['success' => true, 'inventory' => $new_inventory]);
        } catch (\PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('completeAppointment failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to record delivery.']);
        }
        break;

    case 'rescheduleAppointment':
        // 9. Reschedule an appointment to a new date/slot
        $data = json_decode(file_get_contents('php://input'), true);
        $appointment_id = (int)sanitize_input($data['appointment_id'] ?? 0);
        $date = sanitize_input($data['date'] ?? '');
        $time_slot = strtoupper(sanitize_input($data['time_slot'] ?? ''));

        if ($appointment_id <= 0 || !$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !in_array($time_slot, ['AM','PM'], true)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid reschedule data.']);
            break;
        }

        try {
            $pdo->beginTransaction();

            // Fetch appointment and branch
            $stmt = $pdo->prepare("SELECT branch_id FROM appointments WHERE appointment_id = ? FOR UPDATE");
            $stmt->execute([$appointment_id]);
            $appt = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$appt) {
                $pdo->rollBack();
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Appointment not found.']);
                break;
            }
            $branch_id = (int)$appt['branch_id'];

            // Get default slot capacity similar to getBranchInfo (default rows may have NULL or empty string date)
            $stmtCap = $pdo->prepare("SELECT capacity_am, capacity_pm FROM branch_slot_capacity WHERE branch_id = :branch_id AND (`date` IS NULL OR `date` = '')");
            $stmtCap->bindParam(':branch_id', $branch_id, PDO::PARAM_INT);
            $stmtCap->execute();
            $default_capacity = $stmtCap->fetch(PDO::FETCH_ASSOC);
            if (!$default_capacity) {
                $stmtAny = $pdo->prepare("SELECT capacity_am, capacity_pm FROM branch_slot_capacity WHERE branch_id = :branch_id ORDER BY capacity_id DESC LIMIT 1");
                $stmtAny->bindParam(':branch_id', $branch_id, PDO::PARAM_INT);
                $stmtAny->execute();
                $default_capacity = $stmtAny->fetch(PDO::FETCH_ASSOC);
            }
            $default_capacity = $default_capacity ?: ['capacity_am' => 5, 'capacity_pm' => 5];

            $slot_column = $time_slot === 'PM' ? 'capacity_pm' : 'capacity_am';
            $slot_capacity = (int)$default_capacity[$slot_column];

            // Count existing bookings for that date/slot excluding this appointment
            $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE branch_id = ? AND `date` = ? AND time_slot = ? AND status != 'cancelled' AND appointment_id != ?");
            $stmtCount->execute([$branch_id, $date, $time_slot, $appointment_id]);
            $booked = (int)$stmtCount->fetchColumn();

            if ($booked >= $slot_capacity) {
                $pdo->rollBack();
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Selected slot is already full.']);
                break;
            }

            // Apply reschedule and keep status confirmed
            $stmtUp = $pdo->prepare("UPDATE appointments SET `date` = ?, time_slot = ?, status = 'confirmed' WHERE appointment_id = ?");
            $stmtUp->execute([$date, $time_slot, $appointment_id]);

            $pdo->commit();
            echo json_encode(['success' => true]);
        } catch (\PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('rescheduleAppointment failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to reschedule appointment.']);
        }
        break;

    case 'getDashboardData':
        // 10. Lightweight dashboard refresh data
        $branch_id = (int)sanitize_input($_GET['branch_id'] ?? 0);

        if ($branch_id <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Missing branch ID.']);
            break;
        }

        try {
            // Capacity and inventory
            $stmtCap = $pdo->prepare("SELECT warehouse_capacity, inventory FROM volume_capacity WHERE branch_id = ?");
            $stmtCap->execute([$branch_id]);
            $cap = $stmtCap->fetch(PDO::FETCH_ASSOC) ?: ['warehouse_capacity' => 0, 'inventory' => 0];

            $capacity = (float)$cap['warehouse_capacity'];
            $inventory = (float)$cap['inventory'];
            $available = max(0, $capacity - $inventory);

            // Pending appointments count (all future + today)
            $today = date('Y-m-d');
            $stmtPending = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE branch_id = ? AND status = 'pending' AND date >= ?");
            $stmtPending->execute([$branch_id, $today]);
            $pending = (int)$stmtPending->fetchColumn();

            echo json_encode([
                'success' => true,
                'capacity' => $capacity,
                'inventory' => $inventory,
                'available' => $available,
                'pending_count' => $pending
            ]);
        } catch (\PDOException $e) {
            error_log('getDashboardData failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to refresh dashboard data.']);
        }
        break;
}
?>