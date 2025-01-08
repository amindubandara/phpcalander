<?php
session_start();

// Database migration function
function migrateDatabase() {
    $mysqli = new mysqli('localhost', 'root', '', 'bookps');
    
    if ($mysqli->connect_error) {
        die("Connection failed: " . $mysqli->connect_error);
    }

    // Check if status column exists, if not add it 
    $check_column = $mysqli->query("SHOW COLUMNS FROM bookings LIKE 'status'");
    
    if ($check_column->num_rows == 0) {
        // Add status column with default value 
        $alter_query = "ALTER TABLE bookings 
                        ADD COLUMN status ENUM('New', 'Pending', 'Rejected', 'Confirmed') DEFAULT 'New',
                        ADD COLUMN validated_by INT NULL";
        
        if ($mysqli->query($alter_query)) {
            // Update existing rows to have 'New' status
            $update_query = "UPDATE bookings SET status = 'New' WHERE status IS NULL";
            $mysqli->query($update_query);
            
            echo "Database schema updated successfully.";
        } else {
            die("Error updating database schema: " . $mysqli->error);
        }
    }

    return $mysqli;
}

// Database connection function
function initDatabase() {
    return migrateDatabase();
}

// Authentication function
function authenticate($username, $password) {
    // Hardcoded credentials as requested
    return ($username === 'mahawali' && $password === 'test123');
}

// PDF Serving Function with Enhanced Error Handling
function servePDFFile($filename) {
    // Potential upload directories
    $upload_dirs = [
        'uploads/', 
        '../uploads/', 
        $_SERVER['DOCUMENT_ROOT'] . '/bookings/', 
        $_SERVER['DOCUMENT_ROOT'] . '/bookps/bookings/',
        __DIR__ . '/bookings/'
    ];

    // Sanitize filename to prevent directory traversal
    $safe_filename = basename($filename);

    // Try multiple potential upload paths
    $found_file = null;
    foreach ($upload_dirs as $dir) {
        $filepath = $dir . $safe_filename;
        
        if (file_exists($filepath)) {
            $found_file = $filepath;
            break;
        }
    }

    // If no file found, provide detailed debugging
    if (!$found_file) {
        error_log("PDF Not Found: " . $safe_filename);
        die("PDF file not found.");
    }

    // Verify it's a PDF
    if (strtolower(pathinfo($found_file, PATHINFO_EXTENSION)) !== 'pdf') {
        die('Invalid file type');
    }

    // PDF serving headers
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $safe_filename . '"');
    header('Content-Length: ' . filesize($found_file));
    
    readfile($found_file);
    exit();
}

// Pagination function with robust status handling
function getPaginatedBookings($page = 1, $limit = 10, $status = null) {
    $mysqli = initDatabase();
    
    // Calculate offset
    $offset = ($page - 1) * $limit;
    
    // Base queries
    $total_query = "SELECT COUNT(*) as total FROM bookings";
    $bookings_query = "SELECT b.*, p.name as station_name 
                       FROM bookings b
                       JOIN power_stations p ON b.power_station_id = p.id";
    
    // Default to 'New' status if no status is provided
    if ($status === null) {
        $status = 'New';
    }
    
    // Add status filter
    $total_query .= " WHERE status = ?";
    $bookings_query .= " WHERE b.status = ?";
    
    $bookings_query .= " ORDER BY b.id LIMIT ? OFFSET ?";
    
    // Prepare and execute total count query
    $total_stmt = $mysqli->prepare($total_query);
    $total_stmt->bind_param('s', $status);
    $total_stmt->execute();
    $total_result = $total_stmt->get_result();
    
    $total_row = $total_result->fetch_assoc();
    $total_bookings = $total_row['total'];
    
    // Total pages
    $total_pages = ceil($total_bookings / $limit);
    
    // Prepare bookings query
    $stmt = $mysqli->prepare($bookings_query);
    $stmt->bind_param('sii', $status, $limit, $offset);
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    return [
        'bookings' => $result->fetch_all(MYSQLI_ASSOC),
        'total_pages' => $total_pages,
        'current_page' => $page
    ];
}

// Update booking status function
function updateBookingStatus($booking_id, $status) {
    $mysqli = initDatabase();
    
    $stmt = $mysqli->prepare("UPDATE bookings SET status = ? WHERE id = ?");
    $stmt->bind_param('si', $status, $booking_id);
    
    return $stmt->execute();
}
function displayCurrentMonthBookingStats() {
    $mysqli = initDatabase();

    // SQL query to count total bookings for the current month
    $queryTotal = "SELECT COUNT(*) as total_count 
                   FROM bookings 
                   WHERE MONTH(date) = MONTH(CURRENT_DATE())
                   AND YEAR(date) = YEAR(CURRENT_DATE())";

    // SQL query to count pending bookings for the current month
    $queryPending = "SELECT COUNT(*) as pending_count 
                     FROM bookings 
                     WHERE MONTH(date) = MONTH(CURRENT_DATE())
                     AND YEAR(date) = YEAR(CURRENT_DATE())
                     AND status = 'Pending'";
    
    // Execute the queries
    $resultTotal = $mysqli->query($queryTotal);
    $resultPending = $mysqli->query($queryPending);

    $totalBookings = $resultTotal->fetch_assoc()['total_count'];
    $pendingBookings = $resultPending->fetch_assoc()['pending_count'];

    // Display the results
    echo "Bookings Received This Month: " . $totalBookings . "<br>";
    echo "Pending Bookings This Month: " . $pendingBookings;
}

// Delete booking function
function deleteBooking($booking_id) {
    $mysqli = initDatabase();
    
    $stmt = $mysqli->prepare("DELETE FROM bookings WHERE id = ?");
    $stmt->bind_param('i', $booking_id);
    
    return $stmt->execute();
}

// Export bookings to CSV
function exportBookingsToCSV($status = null) {
    $mysqli = initDatabase();
    
    $query = "SELECT b.*, p.name as station_name 
              FROM bookings b
              JOIN power_stations p ON b.power_station_id = p.id";
    
    // Default to 'New' status if no status is provided
    if ($status === null) {
        $status = 'New';
    }
    
    $query .= " WHERE b.status = ?";
    $query .= " ORDER BY b.date DESC, b.time_slot";
    
    $stmt = $mysqli->prepare($query);
    $stmt->bind_param('s', $status);
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    // Output headers
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="bookings_export_' . date('Y-m-d') . '.csv"');
    
    $fp = fopen('php://output', 'wb');
    
    $headers = [
        'ID', 'Status', 'Station', 'Name', 'Organization', 'Address', 
        'Email', 'Contact Number', 'Date', 'Time Slot', 
        'Visitors Count', 'PDF Attachment'
    ];
    fputcsv($fp, $headers);
    
    while ($row = $result->fetch_assoc()) {
        $csv_row = [
            $row['id'], 
            $row['status'] ?? 'New',
            $row['station_name'], 
            $row['Name'], 
            $row['Organization'], 
            $row['address'], 
            $row['email'], 
            $row['ContactNumber'], 
            $row['date'], 
            $row['time_slot'], 
            $row['visitors_count'], 
            $row['pdf_attachment']
        ];
        fputcsv($fp, $csv_row);
    }
    
    fclose($fp);
    exit();
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Login action
    if (isset($_POST['login'])) {
        $username = $_POST['username'];
        $password = $_POST['password'];
        
        if (authenticate($username, $password)) {
            $_SESSION['admin_logged_in'] = true;
            header('Location: admin.php');
            exit();
        } else {
            $login_error = "Invalid credentials";
        }
    }
    
    // Booking status update actions
    if (isset($_POST['validate_booking']) && isset($_POST['booking_id'])) {
        if (updateBookingStatus($_POST['booking_id'], 'Pending')) {
            $success_message = "Booking moved to Pending status";
        } else {
            $error_message = "Failed to update booking status";
        }
    }
     
    if (isset($_POST['reject_booking']) && isset($_POST['booking_id'])) {
        if (updateBookingStatus($_POST['booking_id'], 'Rejected')) {
            $success_message = "Booking rejected";
        } else {
            $error_message = "Failed to reject booking";
        }
    }
    
    // Delete booking action
    if (isset($_POST['delete_booking']) && isset($_POST['booking_id'])) {
        if (deleteBooking($_POST['booking_id'])) {
            $success_message = "Booking deleted successfully";
        } else {
            $error_message = "Failed to delete booking";
        }
    }
    
    // Export bookings action
    if (isset($_POST['export_bookings'])) {
        $status = $_POST['export_status'] ?? 'New';
        exportBookingsToCSV($status);
    }
}

// Handle PDF download
if (isset($_GET['download_pdf']) && isset($_GET['filename'])) {
    if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
        servePDFFile($_GET['filename']);
    } else {
        die('Unauthorized access');
    }
}
function searchBookings($search_term, $search_type) {
    $mysqli = initDatabase();
    
    $query = "SELECT b.*, p.name as station_name 
              FROM bookings b
              JOIN power_stations p ON b.power_station_id = p.id
              WHERE ";
              
    if ($search_type === 'station') {
        $query .= "p.name LIKE ?";
        $search_term = "%$search_term%";
    } else if ($search_type === 'booking_id') {
        $query .= "b.id = ?";
    }
    
    $stmt = $mysqli->prepare($query);
    
    if ($search_type === 'station') {
        $stmt->bind_param('s', $search_term);
    } else if ($search_type === 'booking_id') {
        $stmt->bind_param('i', $search_term);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    return $result->fetch_all(MYSQLI_ASSOC);
}

// Add this to your POST handler section
if (isset($_POST['search'])) {
    $search_term = $_POST['search_term'];
    $search_type = $_POST['search_type'];
    $search_results = searchBookings($search_term, $search_type);
}
// Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: admin.php');
    exit();
}

// Determine page content and status filter
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$status = isset($_GET['status']) ? $_GET['status'] : 'New'; // Default to 'New'

function getPowerStations() {
    $mysqli = initDatabase();
    
    $query = "SELECT id, name FROM power_stations ORDER BY name";
    $result = $mysqli->query($query);
    
    if (!$result) {
        return [];
    }
    
    return $result->fetch_all(MYSQLI_ASSOC);
}
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Power Station Booking Admin Dashboard </title>
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css"> 
    <style>
        body {
    background-color: #f7f9fc; /* Light background for readability */
    padding: 20px;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; /* Modern, clean font */
    color: #333; /* Neutral text color */
    
}

.admin-container {
    background: #ffffff; /* Clean white background */
    border: 1px solid #e0e0e0; /* Light gray border */
    border-radius: 8px; /* Slightly rounded corners */
    padding: 20px;
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1); /* Subtle shadow for depth */
    margin-top: 25px;
    
    
}


h1 {
    font-weight: 600; /* Bold headings */
    margin-bottom: 30px;
    margin-top: 50px;
    color: #4a4a4a; /* Slightly darker heading color */
    text-align: center; /* Centered title */
}

.booking-container {
    display: flex;
    flex-direction: column;
    gap: 20px;
    padding: 20px;

}

.booking-header {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr; /* Booking ID, Name, Address in columns */
    gap: 15px;
    padding: 20px;
    background-color: #f9f9f9; /* Light gray background */
    border-radius: 5px;
    box-shadow: inset 0 1px 3px rgba(0, 0, 0, 0.05); /* Slight inner shadow */
    border: 1px solid #ddd; /* Soft border */
    font-size: 14px;
}

.booking-header div {
    display: flex;
    flex-direction: column;
    
}

.booking-header label {
    font-weight: bold;
    margin-bottom: 5px;
    color: #555;
    
}

.booking-details {
    display: grid;
    grid-template-columns: 1fr; /* Single column for vertical details */
    padding: 20px;
    background-color: #ffffff; /* Clean white background */
    border-radius: 5px;
    box-shadow: 0 1px 5px rgba(0, 0, 0, 0.1); /* Subtle outer shadow */
    border: 1px solid #e0e0e0; /* Light border */
    font-size: 14px;
    margin-top: 20px;
}

.booking-details div {
    margin-bottom: 10px;
}

label {
    font-weight: bold;
    margin-bottom: 5px;
    color: #333;
}

.status-label {
    display: inline-block;
    padding: 8px 12px;
    font-size: 12px;
    font-weight: bold;
    border-radius: 5px;
    margin-top: 10px;
    text-align: center;
    text-transform: uppercase;
}

.status-label.confirm {
    background-color: #28a745; /* Green for confirmed */
    color: #fff;
}

.status-label.reject {
    background-color: #dc3545; /* Red for rejected */
    color: #fff;
}

.status-label.pending {
    background-color: #ffc107; /* Yellow for pending */
    color: #212529;
}

@media (max-width: 768px) {
    .booking-header {
        grid-template-columns: 1fr; /* Stack horizontally on smaller screens */
    }
}
.stats-card {
            background: #fff;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            border-left: 4px solid #007bff;
        }
        .stats-number {
            font-size: 24px;
            font-weight: bold;
            color:rgb(0, 64, 255);
        }
        .stats-label {
            color: #666;
            font-size: 14px;
        }

        .power-stations-card {
    background: #fff;
    border-radius: 8px;
    padding: 15px;
    margin-bottom: 20px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    border-left: 4px solid blue;;
}

.power-stations-card h4 {
    color: blue;
    margin-bottom: 15px;
}

.table-responsive {
    margin-top: 10px;
}

.table-striped > tbody > tr:nth-child(odd) {
    background-color: #f9f9f9;
}
    </style>
</head>
<body>
    <div class="container">
        <div class="row">
            <div class="col-md-10 col-md-offset-1 admin-container">
                <?php if (!isset($_SESSION['admin_logged_in'])): ?>
                    <!-- Login Form -->
                    <h2 class="text-center">Power Station Booking Admin Login-Secretary</h2>
                    
                    <?php if (isset($login_error)): ?>
                        <div class="alert alert-danger"><?php echo $login_error; ?></div>
                    <?php endif; ?>
                    <form method="POST">
                        <div class="form-group">
                            <label for="username">Username</label>
                            <input type="text" class="form-control" name="username" required>
                        </div>
                        <div class="form-group">
                            <label for="password">Password</label>
                            <input type="password" class="form-control" name="password" required>
                        </div>
                        <button type="submit" name="login" class="btn btn-primary">Login</button>
                    </form>
                <?php else: ?>
                    <h2 class="text-center">
                        Power Station Booking Admin Dashboard-Secretary
                        <a href="admin.php?logout=1" class="btn btn-danger btn-sm pull-right">Logout</a>
                    </h2><br>
                    <div class="row" style="margin-bottom: 20px;">
    <div class="col-md-12">
        <form method="POST" class="form-inline">
            <div class="form-group" style="margin-right: 10px;">
                <select name="search_type" class="form-control">
                    <option value="station">Search by Power Station</option>
                    <option value="booking_id">Search by Booking ID</option>
                </select>
            </div>
            <div class="form-group" style="margin-right: 10px;">
                <input type="text" name="search_term" class="form-control" placeholder="Enter search term..." required>
            </div>
            <button type="submit" name="search" class="btn btn-primary">Search</button>
            <?php if (isset($_POST['search'])): ?>
                <a href="admin.php" class="btn btn-default" style="margin-left: 10px;">Clear Search</a>
            <?php endif; ?>
        </form>
    </div>
</div>
<div class="bookings-list">
    <?php if (isset($search_results)): ?>
        <?php if (empty($search_results)): ?>
            <div class="alert alert-info">No bookings found matching your search.</div>
        <?php else: ?>
            <div class="alert alert-success">Found <?php echo count($search_results); ?> matching booking(s)</div>
            <?php foreach ($search_results as $booking): ?>
                <div class="booking-details">
                    <h4>
                        Booking at <?php echo htmlspecialchars($booking['station_name']); ?>
                        <span class="label label-<?php 
                            switch($booking['status']) {
                                case 'New': echo 'primary'; break;
                                case 'Pending': echo 'warning'; break;
                                case 'Rejected': echo 'danger'; break;
                                default: echo 'default';
                            }
                        ?> status-label">
                            <?php echo htmlspecialchars($booking['status'] ?? 'New'); ?>
                        </span>
                        
                        <form method="POST" class="pull-right" onsubmit="return confirm('Are you sure?');">
                            <input type="hidden" name="booking_id" value="<?php echo $booking['id']; ?>">
                            
                            <?php if ($booking['status'] === 'New' || $booking['status'] === null): ?>
                                <button type="submit" name="validate_booking" class="btn btn-xs btn-success">Validate</button>
                                <button type="submit" name="reject_booking" class="btn btn-xs btn-danger">Reject</button>
                                <button type="submit" name="delete_booking" class="btn btn-xs btn-danger">Delete</button>
                            <?php endif; ?>
                        </form>
                    </h4>
                    
                    <p style="color: red;"><strong>Booking ID:</strong> <?php echo htmlspecialchars($booking['id']); ?></p>
                    
                    <p><strong>Name:</strong> <?php echo htmlspecialchars($booking['Name']); ?></p>
                    <p><strong>Organization:</strong> <?php echo htmlspecialchars($booking['Organization']); ?></p>
                    <p><strong>Address:</strong> <?php echo htmlspecialchars($booking['address']); ?></p>
                    <p><strong>Email:</strong> <?php echo htmlspecialchars($booking['email']); ?></p>
                    <p><strong>NIC:</strong> <?php echo htmlspecialchars($booking['nic']); ?></p>
                    <p><strong>Date:</strong> <?php echo htmlspecialchars($booking['date']); ?> 
                    <strong>Time:</strong> <?php echo htmlspecialchars($booking['time_slot']); ?></p>
                    <p><strong>Visitors:</strong> <?php echo htmlspecialchars($booking['visitors_count']); ?></p>
                    <p><strong>Contact:</strong> <?php echo htmlspecialchars($booking['email']); ?> | <?php echo htmlspecialchars($booking['ContactNumber']); ?></p>
                    
                    <?php if (!empty($booking['pdf_attachment'])): ?>
                        <p>
                            <strong>PDF Attachment:</strong> 
                            <a href="admin.php?download_pdf=1&filename=<?php echo urlencode($booking['pdf_attachment']); ?>" target="_blank" class="btn btn-primary btn-xs">
                                View/Download PDF
                            </a>
                        </p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    <?php else: ?>
      
         
    <?php endif; ?>
</div>
                    <div class="stats-card">
                        <div class="stats-number">
                            <?php echo displayCurrentMonthBookingStats(); ?>
                        </div>
                        <div class="stats-label">
                            <h4>New Bookings This Month (<?php echo date('F Y'); ?>)</h3><br>
    <div class="form-group">
        <label for="powerStationSelect">View Power Stations:</label>
        <select id="powerStationSelect" class="form-control">
            <option value="">View Power Station</option> <!-- Default option -->
            <?php 
            $power_stations = getPowerStations();
            
            // Sort power stations by ID in ascending order
            usort($power_stations, function ($a, $b) {
                return $a['id'] <=> $b['id'];
            });

            // Generate options
            foreach ($power_stations as $station): 
            ?>
            <option value="<?php echo htmlspecialchars($station['id']); ?>">
                <?php echo htmlspecialchars($station['id'] . ' - ' . $station['name']); ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
                        </div>
                    </div> 
                    
                    <!-- Status Filter Buttons -->
                    <div class="btn-group mb-3">
                        <a href="?status=New" class="btn <?php echo $status === 'New' ? 'btn-primary active' : 'btn-default'; ?>">New Bookings</a>
                        <a href="?status=Pending" class="btn <?php echo $status === 'Pending' ? 'btn-warning active' : 'btn-default'; ?>">Pending Bookings</a>
                        <a href="?status=Rejected" class="btn <?php echo $status === 'Rejected' ? 'btn-danger active' : 'btn-default'; ?>">Rejected Bookings</a>
                    </div>
                    
                    <!-- Success/Error Messages -->
                    <?php if (isset($success_message)): ?>
                        <div class="alert alert-success"><?php echo $success_message; ?></div>
                    <?php endif; ?>
                    <?php if (isset($error_message)): ?>
                        <div class="alert alert-danger"><?php echo $error_message; ?></div>
                    <?php endif; ?>
                    
                    <?php 
                    // Get bookings with default 'New' status
                    $bookings_data = getPaginatedBookings($page, 10, $status);
                    ?>
                    
                    <div class="bookings-list">
                        <?php if (empty($bookings_data['bookings'])): ?>
                            <div class="alert alert-info">No <?php echo htmlspecialchars($status); ?> bookings found.</div>
                        <?php endif; ?>
                        
                        <?php foreach ($bookings_data['bookings'] as $booking): ?> 
                            <div class="booking-details">
                                <h4>
                                    Booking at <?php echo htmlspecialchars($booking['station_name']); ?>
                                    <span class="label label-<?php 
                                        switch($booking['status']) {
                                            case 'New': echo 'primary'; break;
                                            case 'Pending': echo 'warning'; break;
                                            case 'Rejected': echo 'danger'; break;
                                            default: echo 'default';
                                        }
                                    ?> status-label">
                                        <?php echo htmlspecialchars($booking['status'] ?? 'New'); ?>
                                    </span>
                                    
                                    <form method="POST" class="pull-right" onsubmit="return confirm('Are you sure?');">
                                        <input type="hidden" name="booking_id" value="<?php echo $booking['id']; ?>">
                                        
                                        <?php if ($booking['status'] === 'New' || $booking['status'] === null): ?>
                                            <button type="submit" name="validate_booking" class="btn btn-xs btn-success">Validate</button>
                                            <button type="submit" name="reject_booking" class="btn btn-xs btn-danger">Reject</button>
                                            <button type="submit" name="delete_booking" class="btn btn-xs btn-danger">Delete</button>
        
                                        <?php endif; ?>
                                    </form>
                                </h4>
                                
                                <!-- Rest of the booking details remain the same -->
                                <p style="color: red;"><strong>Booking ID:</strong> <?php echo htmlspecialchars($booking['id']); ?></p>
                                <p style="color: red;"><strong>Power Station ID :</strong> <?php echo htmlspecialchars($booking['power_station_id']); ?></p>
                                <p><strong>Name:</strong> <?php echo htmlspecialchars($booking['Name']); ?></p>
                                <p><strong>Organization:</strong> <?php echo htmlspecialchars($booking['Organization']); ?></p>
                                <p><strong>Address:</strong> <?php echo htmlspecialchars($booking['address']); ?></p>
                                <p><strong>Email:</strong> <?php echo htmlspecialchars($booking['email']); ?></p>
                                <p><strong>CEB Employee:</strong><?php echo htmlspecialchars($booking['ceb_employee']); ?>
                                <strong>EPF Number:</strong><?php echo htmlspecialchars($booking['epf_number']); ?> </p>
                                <p><strong>NIC:</strong> <?php echo htmlspecialchars($booking['nic']); ?></p>
                                <p><strong>Date:</strong> <?php echo htmlspecialchars($booking['date']); ?> 
                                <strong>Time:</strong> <?php echo htmlspecialchars($booking['time_slot']); ?></p>
                                <p><strong>Visitors:</strong> <?php echo htmlspecialchars($booking['visitors_count']); ?></p>
                                <p><strong>Contact:</strong> <?php echo htmlspecialchars($booking['email']); ?> | <?php echo htmlspecialchars($booking['ContactNumber']); ?></p>


                                
                                <!-- ... other booking details ... -->
                                <?php if (!empty($booking['pdf_attachment'])): ?>
                                    <p>
                                        <strong>PDF Attachment:</strong> 
                                        <a href="admin.php?download_pdf=1&filename=<?php echo urlencode($booking['pdf_attachment']); ?>" target="_blank" class="btn btn-primary btn-xs">
                                            View/Download PDF
                                        </a>
                                    </p>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        
                        
                    </div>
                    
                    <nav>
                        <ul class="pagination">
                            <?php for ($i = 1; $i <= $bookings_data['total_pages']; $i++): ?>
                                <li class="<?php echo $i == $page ? 'active' : ''; ?>">
                                    <a href="?page=<?php echo $i; ?><?php echo $status ? '&status=' . urlencode($status) : ''; ?>"><?php echo $i; ?></a>
                                </li>
                            <?php endfor; ?>
                        </ul>
                        
                        <!-- Export form with status selection -->
                        <form method="POST" class="mb-3">
                            <!--<select name="export_status" class="form-control" style="width:auto; display:inline-block;">
                                <option value="">All Bookings</option>
                                <option value="New">New Bookings</option>
                                <option value="Pending">Pending Bookings</option>
                                <option value="Rejected">Rejected Bookings</option>
                            </select>-->
                            <button type="submit" name="export_bookings" class="btn btn-success">
                                Export Bookings to CSV
                            </button>
                        </form>
                    </nav>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/1.12.4/jquery.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>
</body>
</html>