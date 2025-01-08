<?php
//book.php
// Initialize database connection
function initDatabase() {
    try {
        $mysqli = new mysqli('localhost', 'root', '', 'bookps');
        if ($mysqli->connect_error) {
            throw new Exception("Connection failed: " . $mysqli->connect_error);
        }
        return $mysqli;
    } catch (Exception $e) {
        die("Database connection failed: " . $e->getMessage());
    }
}

// PDF Upload Function
function uploadPDF($file) {
    $upload_dir = 'uploads/'; // Ensure this directory exists 
    
    // Create upload directory if it doesn't exist
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    // Validate file
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception("File upload error");
    }

    // Check file type
    $allowed_types = ['application/pdf'];
    $file_type = mime_content_type($file['tmp_name']);
    if (!in_array($file_type, $allowed_types)) {
        throw new Exception("Invalid file type. Only PDFs are allowed.");
    }

    // Check file size (10MB max)
    if ($file['size'] > 10 * 1024 * 1024) {
        throw new Exception("File too large. Maximum 10MB.");
    }

    // Generate unique filename
    $filename = uniqid() . '_' . basename($file['name']);
    $destination = $upload_dir . $filename;

    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        throw new Exception("Failed to save uploaded file");
    }

    return $filename;
}

// Send Booking Confirmation Email
function sendBookingConfirmationEmail($bookingDetails, $stationDetails) {
    // Secretary's email address
    $to = 'aminduruvishan@gmail.com';
    $subject = 'Power Station Visit Booking Confirmation';

    // Prepare email content
    $message = "Dear Secretary,\n\n";
    $message .= "A new booking for a power station visit has been successfully registered.\n\n";
    $message .= "Booking Details:\n";
    $message .= "-------------------\n";
    $message .= "Station: " . htmlspecialchars($stationDetails['station']['name']) . "\n";
    $message .= "Date: " . htmlspecialchars($bookingDetails['date']) . "\n";
    $message .= "Time Slot: " . htmlspecialchars($bookingDetails['time_slot']) . "\n";
    $message .= "Number of Visitors: " . htmlspecialchars($bookingDetails['visitors_count']) . "\n";
    $message .= "Organization: " . htmlspecialchars($bookingDetails['Organization']) . "\n";
    $message .= "Booked By: " . htmlspecialchars($bookingDetails['Name']) . "\n\n";
    $message .= "You can review the booking details in the admin portal using the link below:\n";
    $message .= "Best regards,\n";
    $message .= "Power Station Booking Team"; 
    $message .= "You can review the booking details in the admin portal using the link below:\n";
    $message .= "Admin Portal: http://localhost/php-calendar/admin.php\n\n";

    // Additional headers
    $headers = [
        'From: www.mahawalicomplex.lk', // Set sender domain
        'Reply-To: www.@mahawalicomplex.lk',
        'X-Mailer: PHP/' . phpversion()
    ];

    // Send email
    return mail($to, $subject, $message, implode("\r\n", $headers));
}


// Validate and sanitize input parameters
function validateInputs($date, $station_id, $time_slot = null) {
    if (!$date || !$station_id) {
        throw new Exception("Missing required parameters"); 
    }
    
    // Validate date format
    if (!preg_match("/^\d{4}-\d{2}-\d{2}$/", $date)) {
        throw new Exception("Invalid date format");
    }
    
    // Validate that date is not in past
    if (strtotime($date) < strtotime(date('Y-m-d'))) {
        throw new Exception("Cannot book for past dates");
    }
    
    // Validate time slot if provided
    if ($time_slot && !preg_match("/^([0-1][0-9]|2[0-3]):00$/", $time_slot)) {
        throw new Exception("Invalid time slot format");
    }
    
    return true;
}

// Get power station details and available slots
function getStationDetails($mysqli, $station_id, $date, $time_slot = null) {
    // Get power station information
    $stmt = $mysqli->prepare("SELECT id, name, daily_limit FROM power_stations WHERE id = ?");
    $stmt->bind_param('i', $station_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $station = $result->fetch_assoc();
    
    if (!$station) {
        throw new Exception("Power station not found");
    }
    
    // Get all bookings for the day
    $query = "SELECT time_slot, SUM(visitors_count) as total_visitors 
              FROM bookings 
              WHERE power_station_id = ? AND date = ?
              GROUP BY time_slot";
    $stmt = $mysqli->prepare($query);
    $stmt->bind_param('is', $station_id, $date);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $bookings = [];
    $total_daily_bookings = 0;
    while ($row = $result->fetch_assoc()) {
        $bookings[$row['time_slot']] = $row['total_visitors'];
        $total_daily_bookings += $row['total_visitors'];
    }
    
    // Calculate available slots 
    $remaining_daily_slots = $station['daily_limit'] - $total_daily_bookings;
    
    if ($time_slot) {
        $current_slot_bookings = isset($bookings[$time_slot]) ? $bookings[$time_slot] : 0;
        $slot_limit = 50; // Per hour limit
        $available_slot_spaces = min($slot_limit - $current_slot_bookings, $remaining_daily_slots);
        
        return [
            'station' => $station,
            'current_bookings' => $current_slot_bookings,
            'available_slots' => $available_slot_spaces,
            'remaining_daily_slots' => $remaining_daily_slots,
            'total_daily_bookings' => $total_daily_bookings
        ];
    }
    
    return [
        'station' => $station,
        'current_bookings' => $total_daily_bookings,
        'available_slots' => $remaining_daily_slots
    ];
}

// Process booking
function processBooking($mysqli, $station_id, $date, $time_slot, $data) {
    try {
        // Start transaction
        $mysqli->begin_transaction();
        
        // Validate available capacity again (in case of concurrent bookings)
        $station_details = getStationDetails($mysqli, $station_id, $date, $time_slot);
        
        if ($data['visitors_count'] > $station_details['available_slots']) {
            throw new Exception("Not enough available slots"); 
        }
        
        // Insert booking
        $stmt = $mysqli->prepare("
            INSERT INTO bookings (
                power_station_id, 
                Name, 
                Organization,
                address,
                nic,
                email,
                ceb_employee,
                epf_number,
                ContactNumber,
                date, 
                time_slot,
                visitors_count,
                pdf_attachment
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
             
        $data['Name'] = $data['title'] . ' ' . $data['Name'];
        $ceb_employee = ($data['ceb_employee'] == '1') ? 'Yes' : 'No';
        
        // Set epf_number to empty string if not a CEB employee
        $epf_number = ($ceb_employee == 'Yes' && !empty($data['epf_number'])) ? $data['epf_number'] : '';
        $stmt->bind_param(
            'issssssssssis',
            $station_id,
            $data['Name'],
            $data['Organization'],
            $data['address'],
            $data['nic'],
            $data['email'],
            $ceb_employee,
            $epf_number,
            $data['contactNumber'],
            $date,
            $time_slot,
            $data['visitors_count'],
            $data['pdf_attachment']
        );
        
        if (!$stmt->execute()) {
            throw new Exception("Failed to save booking");
        }
        
        // Send confirmation email
        $booking_details = $data;
        $booking_details['date'] = $date;
        $booking_details['time_slot'] = $time_slot;
        
        // Email sending (optional, can fail without breaking booking)
        $email_sent = sendBookingConfirmationEmail($booking_details, $station_details);
        
        // Commit transaction
        $mysqli->commit();
        return [
            'booking_success' => true, 
            'email_sent' => $email_sent
        ];
        
    } catch (Exception $e) {
        $mysqli->rollback();
        throw $e;
    }
}

// Main execution
try {
    $mysqli = initDatabase();
    $msg = '';
    
    // Validate required parameters
    if (!isset($_GET['date']) || !isset($_GET['station'])) {
        throw new Exception("Missing required parameters");
    }
    
    $date = $_GET['date'];
    $station_id = $_GET['station'];
    $time_slot = $_GET['time'] ?? null;
    
    validateInputs($date, $station_id, $time_slot);
    $station_details = getStationDetails($mysqli, $station_id, $date, $time_slot);
    

    // Process booking submission


    if (isset($_POST['submit'])) {
        // Validate terms agreement
        if (!isset($_POST['agree_terms']) || $_POST['agree_terms'] != '1') {
            throw new Exception("You must agree to the terms and conditions");
        }
        // Validate input data
        $required_fields = ['Name', 'Organization', 'address', 'nic', 'email', 'contactNumber', 'visitors_count'];
        $data = [];
        foreach ($required_fields as $field) {
            if (!isset($_POST[$field]) || empty($_POST[$field])) {
                throw new Exception("Missing required field: $field");
            }
            $data[$field] = $_POST[$field];
        }
        


        // Handle CEB employee status and EPF number
        if (!isset($_POST['ceb_employee'])) {
            throw new Exception("Please select whether you are a CEB employee");
        }
        
        $data['ceb_employee'] = $_POST['ceb_employee'];
        
        // Validate EPF number only if user is a CEB employee
        if ($data['ceb_employee'] == '1') {
            if (!isset($_POST['epf_number']) || empty($_POST['epf_number'])) {
                throw new Exception("EPF number is required for CEB employees");
            }
            $data['epf_number'] = $_POST['epf_number']; 
        } else {
            $data['epf_number'] = ''; // Set to empty string instead of null
        }
        


        // Validate visitors count
        $visitors_count = (int)$data['visitors_count'];
        if ($visitors_count < 1 || $visitors_count > $station_details['available_slots']) {
            throw new Exception("Invalid number of visitors");
        }

        // Handle PDF upload
        $pdf_filename = '';
        if (isset($_FILES['pdf_attachment']) && $_FILES['pdf_attachment']['error'] == UPLOAD_ERR_OK) {
            $pdf_filename = uploadPDF($_FILES['pdf_attachment']);
        } else {
            throw new Exception("PDF attachment is required");
        }

        $data['pdf_attachment'] = $pdf_filename;
        $data['title'] = $_POST['title'];
        
        // Process the booking
        $booking_result = processBooking($mysqli, $station_id, $date, $time_slot, $data);
        
        if ($booking_result['booking_success']) {
            // Prepare success message
            $success_msg = "Booking confirmed successfully!";
            if (!$booking_result['email_sent']) {
                $success_msg .= " Note: Confirmation email could not be sent."; 
                
            }
            
            // Store message in session to display on success page
            session_start();
            $_SESSION['booking_success_msg'] = $success_msg;
            
            header('Location: success.php');
            exit();
        } else {
            throw new Exception("Failed to save booking");
        }
    }
    
} catch (Exception $e) {
    $msg = "<div class='alert alert-danger'>" . htmlspecialchars($e->getMessage()) . "</div>";
}

?>

<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Power Station Booking</title>
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css">
    <style>
    body {
        font-family: 'Arial', sans-serif;
        background-color: #f0f4f8;
        margin: 0;
        padding: 0;
    }

    .container {
        margin-top: 40px;
        margin-bottom: 40px;
        background-color: #ffffff;
        box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        padding: 30px;
        border-radius: 10px;
        display: flex;
        flex-direction: column;
    }

    h2, h3 {
        color: #007bff;
        font-weight: 600;
        margin-bottom: 20px;
    }

    .form-group label {
        font-weight: 500;
        color: #343a40;
        margin-bottom: 8px; 
    }

    .form-control {
        border: 1px solid #ced4da;
        border-radius: 5px;
        padding: 10px;
        font-size: 14px;
        transition: all 0.3s ease;
    }

    .form-control:focus {
        border-color: #007bff;
        box-shadow: 0 0 8px rgba(0, 123, 255, 0.25);
    }

    .btn-primary {
        background-color: #007bff;
        border-color: #007bff;
        padding: 10px 20px;
        font-weight: 600;
        transition: background-color 0.3s ease, transform 0.2s ease;
    }

    .btn-primary:hover {
        background-color: #0056b3;
        transform: translateY(-2px);
    }

    .btn-default {
        background-color: #6c757d;
        border-color: #6c757d;
        color: #fff;
        padding: 10px 10px;
    }

    .btn-default:hover {
        background-color: #5a6268;
    }

    .help-block {
        color: #6c757d;
        font-size: 12px;
    }

    .booking-stats {
        background: linear-gradient(to right, #f8f9fa, #e9ecef); 
        padding: 20px;
        border-radius: 10px;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        margin-bottom: 25px;
    }

    .stat-label {
        font-weight: bold;
        color: #495057;
    }

    .stat-value {
        font-weight: 600;
        font-size: 16px;
    }

    .available {
        color: #28a745;
    }

    .booked {
        color: #dc3545;
    }

    @media (max-width: 768px) {
        .container {
            padding: 20px;
        }

        .form-group label {
            font-size: 14px;
        }

        .form-control {
            font-size: 13px;
            padding: 8px;
        }

        .btn-primary, .btn-default { 
            font-size: 14px;
            padding: 8px 16px;
        }
    }
    .ceb-employee-section {
        padding: 15px;
        margin: 15px 0;
        border: 1px solid #ddd;
        border-radius: 5px;
        background-color: #f9f9f9;
    }

    .radio-group {
        margin-bottom: 15px;
    }

    .radio-group label {
        margin-right: 20px;
    }
    .terms-container {
    max-height: 200px;
    overflow-y: auto;
    padding: 15px;
    border: 1px solid #ddd;
    border-radius: 5px;
    background-color: #f9f9f9;
    margin-bottom: 15px;
}

.terms-check {
    margin: 15px 0;
    padding: 10px;
    background-color: #f8f9fa;
    border-radius: 5px;
}

.terms-check label {
    font-weight: normal;
    display: inline-block;
    margin-left: 10px;
}
    
</style>

</head>
<body>
    <div class="container">
        <h2 class="text-center" style="color: #1e90ff;">Book for <?php echo htmlspecialchars($station_details['station']['name']); ?></h2>
        <h2 class="text-center" style="font-size: 20px;">Date: <?php echo date('m/d/Y', strtotime($date)); ?></h2>
        <?php if ($time_slot): ?>
            <h3 class="text-center">Time: <?php echo htmlspecialchars($time_slot); ?></h3>
        <?php endif; ?>
        
        <div class="row">
            <div class="col-md-6 col-md-offset-3">
                <div class="booking-stats">
                    <?php if ($time_slot): ?>
                        <p>
                            <span class="stat-label">Available in Time Slot:</span>
                            <span class="stat-value available"><?php echo $station_details['available_slots']; ?></span>
                        </p>
                        <p>
                            <span class="stat-label">Total Daily Bookings:</span>
                            <span class="stat-value booked"><?php echo $station_details['total_daily_bookings']; ?></span>
                        </p>
                        <p>
                            <span class="stat-label">Remaining Daily Capacity:</span>
                            <span class="stat-value available"><?php echo $station_details['remaining_daily_slots']; ?></span>
                        </p>
                    <?php else: ?>
                        <p>
                            <span class="stat-label">Current Bookings:</span>
                            <span class="stat-value booked"><?php echo $station_details['current_bookings']; ?></span>
                        </p>
                        <p>
                            <span class="stat-label">Available Slots:</span>
                            <span class="stat-value available"><?php echo $station_details['available_slots']; ?></span>
                        </p>
                    <?php endif; ?>
                </div>
                
                <?php echo $msg; ?>
                
                <form action="" method="post" enctype="multipart/form-data" autocomplete="off">
                    <div class="form-group">
                    <label for="Name"> Full Name</label>
            <div class="">
            <select name="title" class="form-control" style="margin-right: 10px;" required>
                <option value="Mr.">Mr.</option>
                <option value="Mrs.">Mrs.</option>
            </select><br>
            <input type="text" class="form-control" name="Name" placeholder="Full Name" required>
        </div><br>
 
                    <div class="form-group">
                        <label for="Organization">Organization  </label>
                        <input type="text" class="form-control" name="Organization" required>
                    </div>
                    <div class="form-group">
                        <label for="address">Address </label>
                        <input type="text" class="form-control" name="address" required>
                    </div>
                    <div class="form-group">
                        <label for="nic">NIC Number</label>
                        <input type="text" class="form-control" name="nic" required>
                    </div>

                    <div class="form-group">
                        <label for="email">Email</label>
                        <input type="email" class="form-control" name="email" required>
                    </div>
                    <div class="form-group ceb-employee-section">
    <label>Are you a CEB Employee?</label>
    <div class="radio-group">
        <label>
            <input type="radio" name="ceb_employee" value="1" onclick="toggleEPFField(true)"> Yes
        </label>
        <label>
            <input type="radio" name="ceb_employee" value="0" onclick="toggleEPFField(false)" checked> No
        </label>
    </div>
    <div id="epf_field" style="display: none;">
        <label for="epf_number">EPF Number</label>
        <input type="text" class="form-control" name="epf_number" id="epf_number">
    </div>
</div>
                    <div class="form-group">
                        <label for="contactNumber">Contact Number</label>
                        <input type="tel" class="form-control" name="contactNumber" required>
                    </div>
                    <div class="form-group">
                        <label for="visitors_count">Number of Visitors</label>
                        <input type="number" class="form-control" name="visitors_count" 
                               min="1" max="<?php echo $station_details['available_slots']; ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="pdf_attachment">PDF Attachment (Organization Letter)</label>
                        <input type="file" class="form-control" name="pdf_attachment" 
                               accept=".pdf" required>
                        <small class="help-block">Maximum file size: 10MB. Only PDF files are allowed.</small>
                    </div>
                    <div class="terms-container">
                <h4>Terms and Conditions</h4>
                <ol>
                    <li>Visitors must carry valid identification documents during the visit.</li>
                    <li>Photography and video recording are strictly prohibited within the power station premises.</li>
                    <li>All visitors must follow safety guidelines and wear appropriate safety gear provided.</li>
                    <li>Children under 12 years are not permitted inside the power station.</li>
                    <li>The management reserves the right to cancel or reschedule visits due to operational requirements.</li>
                    <li>Visitors must arrive 15 minutes before their scheduled time slot.</li>
                    <li>Cancellations must be made at least 24 hours before the scheduled visit.</li>
                    <li>The organization letter submitted must be on official letterhead and signed by authorized personnel.</li>
                    <li>Any violation of power station rules may result in immediate termination of the visit.</li>
                    <li>The power station management is not liable for any personal injuries during the visit.</li>
                    <li style="font-weight: bold;">Advance Booking Required: All bookings must be made minimum 2 days before the visit date</li>
                    <li>Operating Hours: 8:30 AM to 4:00 PM (last entry at 3:00 PM)</li>
                    <li>Maximum 50 visitors per time slot</li>
                    <li>Bookings are availability 50 visitors per time slot 8:00 AM to 4:00 PM </li>
                </ol>
            </div>

            <div class="terms-check">
                        <input type="checkbox" id="agree_terms" name="agree_terms" value="1" required>
                        <label for="agree_terms">I have read and agree to the terms and conditions</label>
                    </div>
                    <button class="btn btn-primary" type="submit" name="submit">Submit</button>
                    <a href="index.php" class="btn btn-default">Back to Calendar</a>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/1.12.4/jquery.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>
    <script>
        
    function toggleEPFField(show) {
        const epfField = document.getElementById('epf_field');
        const epfInput = document.getElementById('epf_number');
        
        if (show) {
            epfField.style.display = 'block';
            epfInput.required = true;
        } else {
            epfField.style.display = 'none';
            epfInput.required = false;
            epfInput.value = '';
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
    const form = document.querySelector('form');
    const termsCheckbox = document.getElementById('agree_terms');

    // Set custom validation message
    termsCheckbox.setCustomValidity('You must agree to the terms and conditions to proceed');

    termsCheckbox.addEventListener('change', function() {
        if (this.checked) {
            this.setCustomValidity('');
        } else {
            this.setCustomValidity('You must agree to the terms and conditions to proceed');
        }
    });

    form.addEventListener('submit', function(event) {
        if (!termsCheckbox.checked) {
            event.preventDefault();
            termsCheckbox.setCustomValidity('You must agree to the terms and conditions to proceed');
            termsCheckbox.reportValidity();
        }
    });
});
    </script>
</body>
</html>