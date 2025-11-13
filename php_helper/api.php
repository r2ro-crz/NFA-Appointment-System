<?php
require_once 'db_config.php'; 

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
            // First try with date IS NULL for defaults
            $stmt_default_cap = $pdo->prepare("SELECT capacity_am, capacity_pm FROM branch_slot_capacity WHERE branch_id = :branch_id AND `date` IS NULL");
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
                           AND status != 'Cancelled' GROUP BY `date`, time_slot";
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
            try {
                $sql_holidays = "SELECT holiday_date FROM holidays WHERE holiday_date BETWEEN :start_date AND :end_date";
                $stmt_holidays = $pdo->prepare($sql_holidays);
                $stmt_holidays->bindParam(':start_date', $start_date, PDO::PARAM_STR);
                $stmt_holidays->bindParam(':end_date', $end_date, PDO::PARAM_STR);
                $stmt_holidays->execute();
                $holidays = array_column($stmt_holidays->fetchAll(PDO::FETCH_ASSOC), 'holiday_date');
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
                'daily_availability' => $availability_data
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
            'email'          => sanitize_input($data_raw['email'] ?? null),
            'contact_number' => sanitize_input($data_raw['contact'] ?? null),
            'gender'         => sanitize_input($data_raw['gender'] ?? null),
            'volume'         => (float)sanitize_input($data_raw['volume'] ?? 0),
            'farmer_type_id' => (int)sanitize_input($data_raw['farmer_type_id'] ?? 0), 
        ];
        
        error_log("DEBUG: Processed data: " . json_encode($data));

        if (!$data['branch_id'] || !$data['date'] || !$data['first_name'] || !$data['last_name'] || $data['volume'] <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Missing required appointment data or invalid volume.']);
            exit;
        }
        
        $reference_number = 'NFA' . date('Ymd') . strtoupper(substr(md5(uniqid(rand(), true)), 0, 6));

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

            // Insert into appointments table
            $sql = "INSERT INTO appointments 
                    (branch_id, region_id, date, time_slot, first_name, middle_name, last_name, 
                     email, contact_number, gender, volume, farmer_type_id, status, reference_number) 
                    VALUES 
                    (:branch_id, :region_id, :date, :time_slot, :first_name, :middle_name, :last_name,
                     :email, :contact_number, :gender, :volume, :farmer_type_id, 'pending', :reference_number)";
            
            $stmt = $pdo->prepare($sql);
            $params = [
                ':branch_id' => $data['branch_id'],
                ':region_id' => $region_id,
                ':date' => $data['date'],
                ':time_slot' => $data['time_slot'],
                ':first_name' => $data['first_name'],
                ':middle_name' => $data['middle_name'],
                ':last_name' => $data['last_name'],
                ':email' => $data['email'],
                ':contact_number' => $data['contact_number'],
                ':gender' => $data['gender'],
                ':volume' => $data['volume'],
                ':farmer_type_id' => $data['farmer_type_id'],
                ':reference_number' => $reference_number
            ];
            error_log("DEBUG: SQL params: " . json_encode($params));
            $stmt->execute($params);

            // Update inventory (Crucial for Q3 capacity)
            $update_inventory_sql = "
                UPDATE volume_capacity 
                SET inventory = inventory + :volume 
                WHERE branch_id = :branch_id
            ";
            $pdo->prepare($update_inventory_sql)->execute([
                ':volume' => $data['volume'], 
                ':branch_id' => $data['branch_id']
            ]);
            
            $pdo->commit();

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

    default:
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Invalid API action.']);
        break;
}
?>