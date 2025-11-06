<?php
// Include the database connection file.
require_once 'db_config.php'; 

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

// --- Helper Functions ---

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
        // FIX: Explicitly cast region_id to (int) to ensure proper matching against the integer column in the database.
        $region_id = (int)sanitize_input($_GET['region_id'] ?? 0); 
        if (!$region_id) {
            echo json_encode(['success' => true, 'data' => []]);
            break;
        }
        try {
            // FIX APPLIED: Using the confirmed singular table name 'branch' from the DDL.
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
        $branch_id = sanitize_input($_GET['branch_id'] ?? 0);
        $start_date = sanitize_input($_GET['start_date'] ?? date('Y-m-01'));
        $end_date = sanitize_input($_GET['end_date'] ?? date('Y-m-t'));

        if (!$branch_id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Missing branch ID.']);
            exit();
        }

        try {
            // A. Fetch Volume Capacity (Q3)
            $stmt_vol = $pdo->prepare("SELECT warehouse_capacity, inventory FROM volume_capacity WHERE branch_id = :branch_id");
            $stmt_vol->bindParam(':branch_id', $branch_id, PDO::PARAM_INT);
            $stmt_vol->execute();
            $vol_data = $stmt_vol->fetch(PDO::FETCH_ASSOC);
            
            $volume_info = [
                'total_capacity' => (float)($vol_data['warehouse_capacity'] ?? 0),
                'inventory' => (float)($vol_data['inventory'] ?? 0),
                'available_volume' => max(0, (float)($vol_data['warehouse_capacity'] ?? 0) - (float)($vol_data['inventory'] ?? 0))
            ];

            // B. Fetch Default Slot Capacity (Used by Q4/Q5 logic)
            $stmt_default_cap = $pdo->prepare("SELECT capacity_am, capacity_pm FROM branch_slot_capacity WHERE branch_id = :branch_id AND date IS NULL");
            $stmt_default_cap->bindParam(':branch_id', $branch_id, PDO::PARAM_INT);
            $stmt_default_cap->execute();
            $default_capacity = $stmt_default_cap->fetch(PDO::FETCH_ASSOC) ?: ['capacity_am' => 0, 'capacity_pm' => 0];

            // C. Fetch Booked Appointments (Q4/Q5)
            $sql_booked = "SELECT date, time_slot, COUNT(appointment_id) as booked_count FROM appointments 
                           WHERE branch_id = :branch_id AND date BETWEEN :start_date AND :end_date 
                           AND status != 'Cancelled' GROUP BY date, time_slot";
            $stmt_booked = $pdo->prepare($sql_booked);
            $stmt_booked->bindParam(':branch_id', $branch_id, PDO::PARAM_INT);
            $stmt_booked->bindParam(':start_date', $start_date, PDO::PARAM_STR);
            $stmt_booked->bindParam(':end_date', $end_date, PDO::PARAM_STR);
            $stmt_booked->execute();
            
            $booked_slots = [];
            foreach ($stmt_booked->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $booked_slots[$row['date']][$row['time_slot']] = (int)$row['booked_count'];
            }

            // D. Fetch Holidays
            $sql_holidays = "SELECT holiday_date FROM holidays WHERE holiday_date BETWEEN :start_date AND :end_date";
            $stmt_holidays = $pdo->prepare($sql_holidays);
            $stmt_holidays->bindParam(':start_date', $start_date, PDO::PARAM_STR);
            $stmt_holidays->bindParam(':end_date', $end_date, PDO::PARAM_STR);
            $stmt_holidays->execute();
            $holidays = array_column($stmt_holidays->fetchAll(PDO::FETCH_ASSOC), 'holiday_date');
            
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
            error_log("Branch info fetch failed: " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Database query failed to fetch branch info.']);
        }
        break;

    case 'getFarmerTypes':
        // 4. Get Farmer Types
        try {
            // FIX: Changed table name to 'farmer_type' (singular, assuming consistency)
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
        $data_raw = json_decode(file_get_contents('php://input'), true);
        
        $data = [
            'branch_id'      => sanitize_input($data_raw['branch_id'] ?? null),
            'date'           => sanitize_input($data_raw['date'] ?? null),
            'time_slot'      => sanitize_input($data_raw['time_slot'] ?? null),
            'first_name'     => sanitize_input($data_raw['firstName'] ?? null),
            'middle_name'    => sanitize_input($data_raw['middleName'] ?? null),
            'last_name'      => sanitize_input($data_raw['lastName'] ?? null),
            'email'          => sanitize_input($data_raw['email'] ?? null),
            'contact_number' => sanitize_input($data_raw['contact'] ?? null),
            'gender'         => sanitize_input($data_raw['gender'] ?? null),
            'volume'         => (float)sanitize_input($data_raw['volume'] ?? 0),
            'farmer_type_id' => sanitize_input($data_raw['farmer_type_id'] ?? null), 
        ];

        if (!$data['branch_id'] || !$data['date'] || !$data['first_name'] || !$data['last_name'] || $data['volume'] <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Missing required appointment data or invalid volume.']);
            exit;
        }
        
        $reference_number = 'NFA' . date('Ymd') . strtoupper(substr(md5(uniqid(rand(), true)), 0, 6));

        try {
            $pdo->beginTransaction();

            // Insert into appointments table
            $sql = "INSERT INTO appointments 
                    (branch_id, date, time_slot, first_name, middle_name, last_name, email, contact_number, gender, volume, farmer_type_id, status, reference_number) 
                    VALUES (:branch_id, :date, :time_slot, :first_name, :middle_name, :last_name, :email, :contact_number, :gender, :volume, :farmer_type_id, 'pending', :reference_number)";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':branch_id' => $data['branch_id'],
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
            ]);

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
            echo json_encode(['success' => false, 'error' => 'Failed to book appointment due to server error.']);
        }
        break;

    default:
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Invalid API action.']);
        break;
}
?>