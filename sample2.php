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
    <title>Power Station Booking Admin Dashboard</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-gray-50">
    <?php if (!isset($_SESSION['admin_logged_in'])): ?>
    <!-- Login Form -->
    <div class="min-h-screen flex items-center justify-center">
        <div class="bg-white p-8 rounded-lg shadow-md w-96">
            <h2 class="text-2xl font-bold mb-6 text-center">Power Station Admin Login</h2>
            <?php if (isset($login_error)): ?>
                <div class="bg-red-100 text-red-700 p-3 rounded mb-4"><?php echo $login_error; ?></div>
            <?php endif; ?>
            <form method="POST">
                <div class="mb-4">
                    <label class="block text-gray-700 mb-2">Username</label>
                    <input type="text" name="username" class="w-full p-2 border rounded" required>
                </div>
                <div class="mb-6">
                    <label class="block text-gray-700 mb-2">Password</label>
                    <input type="password" name="password" class="w-full p-2 border rounded" required>
                </div>
                <button type="submit" name="login" class="w-full bg-blue-600 text-white py-2 rounded hover:bg-blue-700">
                    Login
                </button>
            </form>
        </div>
    </div>
    <?php else: ?>
    <!-- Dashboard Layout -->
    <div class="min-h-screen flex">
        <!-- Sidebar -->
        <div class="bg-gray-800 text-white w-64 flex-shrink-0">
            <div class="p-4 border-b border-gray-700">
                <h2 class="text-xl font-bold">Power Station Admin</h2>
            </div>
            <nav class="mt-4">
                <a href="?status=New" class="flex items-center py-2 px-4 <?php echo $status === 'New' ? 'bg-gray-900' : 'hover:bg-gray-700'; ?>">
                    <i class="fas fa-inbox mr-3"></i> New Bookings
                </a>
                <a href="?status=Pending" class="flex items-center py-2 px-4 <?php echo $status === 'Pending' ? 'bg-gray-900' : 'hover:bg-gray-700'; ?>">
                    <i class="fas fa-clock mr-3"></i> Pending
                </a>
                <a href="?status=Rejected" class="flex items-center py-2 px-4 <?php echo $status === 'Rejected' ? 'bg-gray-900' : 'hover:bg-gray-700'; ?>">
                    <i class="fas fa-times-circle mr-3"></i> Rejected
                </a>
            </nav>
        </div>

        <!-- Main Content -->
        <div class="flex-1">
            <!-- Top Bar -->
            <header class="bg-white shadow">
                <div class="flex items-center justify-between px-6 py-4">
                    <div class="flex items-center">
                        <h1 class="text-xl font-semibold">Dashboard Overview</h1>
                    </div>
                    <div class="flex items-center space-x-4">
                        <form method="POST" class="flex items-center">
                            <select name="search_type" class="mr-2 p-2 border rounded">
                                <option value="station">Search by Station</option>
                                <option value="booking_id">Search by Booking ID</option>
                            </select>
                            <input type="text" name="search_term" class="p-2 border rounded mr-2" placeholder="Search...">
                            <button type="submit" name="search" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
                                Search
                            </button>
                        </form>
                        <a href="?logout=1" class="bg-red-600 text-white px-4 py-2 rounded hover:bg-red-700">Logout</a>
                    </div>
                </div>
            </header>

            <!-- Main Dashboard Content -->
            <div class="p-6">
                <!-- Stats Cards -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                    <div class="bg-white rounded-lg shadow p-6">
                        <div class="flex items-center">
                            <div class="p-3 bg-blue-500 rounded-full">
                                <i class="fas fa-calendar-check text-white text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <h3 class="text-gray-500 text-sm">Current Month Stats</h3>
                                <div class="text-lg font-semibold">
                                    <?php echo displayCurrentMonthBookingStats(); ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="bg-white rounded-lg shadow p-6">
                        <div class="flex items-center">
                            <div class="p-3 bg-green-500 rounded-full">
                                <i class="fas fa-building text-white text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <h3 class="text-gray-500 text-sm">Power Stations</h3>
                                <select id="powerStationSelect" class="mt-2 w-full p-2 border rounded">
                                    <option value="">Select Station</option>
                                    <?php 
                                    $power_stations = getPowerStations();
                                    foreach ($power_stations as $station): 
                                    ?>
                                    <option value="<?php echo htmlspecialchars($station['id']); ?>">
                                        <?php echo htmlspecialchars($station['name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <!--<div class="bg-white rounded-lg shadow p-6">
                        <div class="flex items-center">
                            <div class="p-3 bg-purple-500 rounded-full">
                                <i class="fas fa-file-export text-white text-xl"></i>
                            </div>
                            <<div class="ml-4">
                                <h3 class="text-gray-500 text-sm">Export Data</h3>
                                <form method="POST" class="mt-2">
                                    <button type="submit" name="export_bookings" class="bg-purple-600 text-white px-4 py-2 rounded hover:bg-purple-700">
                                        Export to CSV
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>-->
                </div>

                <!-- Bookings Table -->
                <div class="bg-white rounded-lg shadow">
                    <div class="p-6 border-b border-gray-200">
                        <h2 class="text-lg font-semibold">
                            <?php echo ucfirst($status); ?> Bookings
                        </h2>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">ID</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Station</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Time</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <?php 
                                $bookings_data = getPaginatedBookings($page, 10, $status);
                                foreach ($bookings_data['bookings'] as $booking): 
                                ?>
                                <tr>
                                    <td class="px-6 py-4">#<?php echo htmlspecialchars($booking['id']); ?></td>
                                    <td class="px-6 py-4"><?php echo htmlspecialchars($booking['station_name']); ?></td>
                                    <td class="px-6 py-4"><?php echo htmlspecialchars($booking['Name']); ?></td>
                                    <td class="px-6 py-4"><?php echo htmlspecialchars($booking['date']); ?></td>
                                    <td class="px-6 py-4"><?php echo htmlspecialchars($booking['time_slot']); ?></td>
                                    <td class="px-6 py-4"><?php echo htmlspecialchars($booking['Name']); ?></td>
                                    <td class="px-6 py-4"><?php echo htmlspecialchars($booking['date']); ?></td>
                                    <td class="px-6 py-4"><?php echo htmlspecialchars($booking['time_slot']); ?></td>
                                    <td class="px-6 py-4">
                                        <form method="POST" class="inline-flex space-x-2" onsubmit="return confirm('Are you sure?');">
                                            <input type="hidden" name="booking_id" value="<?php echo $booking['id']; ?>">
                                            <?php if ($booking['status'] === 'New'): ?>
                                                <button type="submit" name="validate_booking" class="text-white bg-green-600 px-3 py-1 rounded hover:bg-green-700">
                                                    Validate
                                                </button>
                                                <button type="submit" name="reject_booking" class="text-white bg-red-600 px-3 py-1 rounded hover:bg-red-700">
                                                    Reject
                                                </button>
                                            <?php endif; ?>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <!-- Pagination -->
                    <div class="px-6 py-4 border-t border-gray-200">
                        <div class="flex justify-center">
                            <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px">
                                <?php for ($i = 1; $i <= $bookings_data['total_pages']; $i++): ?>
                                    <a href="?page=<?php echo $i; ?>&status=<?php echo $status; ?>" 
                                       class="relative inline-flex items-center px-4 py-2 border text-sm font-medium
                                              <?php echo $i == $page ? 'bg-blue-50 border-blue-500 text-blue-600' : 'bg-white border-gray-300 text-gray-500 hover:bg-gray-50'; ?>">
                                        <?php echo $i; ?>
                                    </a>
                                <?php endfor; ?>
                            </nav>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</body>
</html>