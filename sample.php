<?php
// Send Booking Confirmation Email to User
function sendUserConfirmationEmail($email, $bookingDetails) {
    // Email configuration
    $to = $email;
    $subject = 'Booking Registration Successful - Approval Pending';
    
    // Construct email message
    $message = "Dear " . htmlspecialchars($bookingDetails['name']) . ",\n\n";
    $message .= "Your booking at " . htmlspecialchars($bookingDetails['station']) . " has been successfully registered.\n\n";
    $message .= "Booking Details:\n";
    $message .= "Date: " . htmlspecialchars($bookingDetails['date']) . "\n";
    $message .= "Time Slot: " . htmlspecialchars($bookingDetails['time_slot']) . "\n";
    $message .= "Number of Visitors: " . htmlspecialchars($bookingDetails['visitors_count']) . "\n\n";
    $message .= "Status: Approval Pending\n\n";
    $message .= "We will notify you once your booking is approved.\n\n";
    $message .= "Thank you for your booking!\n";
    
    // Additional headers
    $headers = "From: bookings@powerstation.com\r\n";
    $headers .= "Reply-To: bookings@powerstation.com\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion();

    // Send email
    return mail($to, $subject, $message, $headers);
}

// Send Notification Email to Secretary
function sendSecretaryNotificationEmail($bookingDetails) {
    // Email configuration
    $to = 'aminduruvishan@gmail.com';
    $subject = 'ACTION REQUIRED: New Booking Request';
    
    // Construct email message
    $message = "Dear Secretary,\n\n";
    $message .= "A new booking request has been received and requires your approval.\n\n";
    $message .= "Booking Details:\n";
    $message .= "Name: " . htmlspecialchars($bookingDetails['name']) . "\n";
    $message .= "Organization: " . htmlspecialchars($bookingDetails['organization']) . "\n";
    $message .= "Power Station: " . htmlspecialchars($bookingDetails['station']) . "\n";
    $message .= "Date: " . htmlspecialchars($bookingDetails['date']) . "\n";
    $message .= "Time Slot: " . htmlspecialchars($bookingDetails['time_slot']) . "\n";
    $message .= "Number of Visitors: " . htmlspecialchars($bookingDetails['visitors_count']) . "\n";
    $message .= "Contact Number: " . htmlspecialchars($bookingDetails['contact_number']) . "\n";
    $message .= "Email: " . htmlspecialchars($bookingDetails['email']) . "\n";
    $message .= "NIC: " . htmlspecialchars($bookingDetails['nic']) . "\n";
    $message .= "Address: " . htmlspecialchars($bookingDetails['address']) . "\n\n";
    
    $message .= "You can review and approve this booking in the admin portal:\n";
    $message .= "Admin Portal: http://localhost/php-calendar/admin.php\n\n";
    
    $message .= "Please take action on this request as soon as possible.\n";
    
    // Additional headers
    $headers = "From: bookings@powerstation.com\r\n";
    $headers .= "Reply-To: bookings@powerstation.com\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion();

    // Send email
    return mail($to, $subject, $message, $headers);
}

// Update the form submission processing section
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $mysqli = initDatabase();
        
        // Validate and sanitize inputs
        $required_fields = ['title', 'Name', 'Organization', 'address', 'nic', 'email', 'contactNumber', 'station', 'date', 'time_slot', 'visitors_count'];
        
        // Validate all required fields are present
        foreach ($required_fields as $field) {
            if (!isset($_POST[$field]) || empty($_POST[$field])) {
                throw new Exception("Missing required field: $field");
            }
        }

        // PDF Upload handling
        $filename = '';
        if (isset($_FILES['pdf_attachment']) && $_FILES['pdf_attachment']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = 'uploads/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }

            // Validate PDF
            $allowed_types = ['application/pdf'];
            $file_type = mime_content_type($_FILES['pdf_attachment']['tmp_name']);
            if (!in_array($file_type, $allowed_types)) {
                throw new Exception("Invalid file type. Only PDFs are allowed.");
            }

            // Check file size (10MB max)
            if ($_FILES['pdf_attachment']['size'] > 10 * 1024 * 1024) {
                throw new Exception("File too large. Maximum 10MB.");
            }

            // Generate unique filename
            $filename = uniqid() . '_' . basename($_FILES['pdf_attachment']['name']);
            $destination = $upload_dir . $filename;

            // Move uploaded file
            if (!move_uploaded_file($_FILES['pdf_attachment']['tmp_name'], $destination)) {
                throw new Exception("Failed to save uploaded file");
            }
        } else {
            throw new Exception("PDF attachment is required or file upload failed");
        }

        // Get the selected power station name
        $power_stations = getPowerStations();
        $selected_station = '';
        foreach ($power_stations as $station) {
            if ($station['id'] == $_POST['station']) {
                $selected_station = $station['name'];
                break;
            }
        }

        // Prepare booking data
        $booking_data = [
            'station_id' => $_POST['station'],
            'station' => $selected_station,
            'name' => $_POST['title'] . ' ' . $_POST['Name'],
            'organization' => $_POST['Organization'],
            'address' => $_POST['address'],
            'nic' => $_POST['nic'],
            'email' => $_POST['email'],
            'contact_number' => $_POST['contactNumber'],
            'date' => $_POST['date'],
            'time_slot' => $_POST['time_slot'],
            'visitors_count' => $_POST['visitors_count'],
            'pdf_attachment' => $filename
        ];

        // Insert booking
        $stmt = $mysqli->prepare("
            INSERT INTO bookings (
                power_station_id, 
                Name, 
                Organization,
                address,
                nic,
                email,
                ContactNumber,
                date, 
                time_slot,
                visitors_count,
                pdf_attachment
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
             
        $stmt->bind_param(
            'issssssssis',
            $booking_data['station_id'],
            $booking_data['name'],
            $booking_data['organization'],
            $booking_data['address'],
            $booking_data['nic'],
            $booking_data['email'],
            $booking_data['contact_number'],
            $booking_data['date'],
            $booking_data['time_slot'],
            $booking_data['visitors_count'],
            $booking_data['pdf_attachment']
        );
        
        if (!$stmt->execute()) {
            throw new Exception("Failed to save booking: " . $stmt->error);
        }

        // Send confirmation emails
        $user_email_sent = sendUserConfirmationEmail($_POST['email'], $booking_data);
        $secretary_email_sent = sendSecretaryNotificationEmail($booking_data);

        // Redirect to success page
        session_start();
        $_SESSION['booking_success'] = true;
        $_SESSION['user_email_sent'] = $user_email_sent;
        $_SESSION['secretary_email_sent'] = $secretary_email_sent;
        header('Location: success.php');
        exit();

    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}