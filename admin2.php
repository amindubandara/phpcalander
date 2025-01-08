<?php
session_start();

// Database connection function
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

// Authentication function for admin2
function authenticate($username, $password) {
    // Array of valid credentials
    $valid_credentials = [
        'DGM' => 'DGM123',
        'CEMC' => 'CEMC123'  // Changed from CEMC1234 to CEMC123
    ];
    
    // Check if username exists and password matches
    if (isset($valid_credentials[$username])) {
        return $valid_credentials[$username] === $password;
    }
    
    return false;
}

// Get pending bookings
function getPendingBookings() {
    $mysqli = initDatabase();
    
    $query = "SELECT b.*, p.name as station_name 
              FROM bookings b
              JOIN power_stations p ON b.power_station_id = p.id
              WHERE b.status = 'pending'
              ORDER BY b.id ASC";
    
    $result = $mysqli->query($query);
    return $result->fetch_all(MYSQLI_ASSOC);
}

// Get approved bookings (changed from confirmed)
function getApprovedBookings() {
    $mysqli = initDatabase();
    
    $query = "SELECT b.*, p.name as station_name 
              FROM bookings b
              JOIN power_stations p ON b.power_station_id = p.id
              WHERE b.status = 'approved' OR b.status2 = 'approved'
              ORDER BY b.id ASC";
    
    $result = $mysqli->query($query);
    return $result->fetch_all(MYSQLI_ASSOC);
}

// Get rejected bookings
function getRejectedBookings() {
    $mysqli = initDatabase();
    
    $query = "SELECT b.*, p.name as station_name 
              FROM bookings b
              JOIN power_stations p ON b.power_station_id = p.id
              WHERE b.status = 'reject' OR b.status2 = 'reject'
              ORDER BY b.id ASC";
    
    $result = $mysqli->query($query);
    return $result->fetch_all(MYSQLI_ASSOC);
}

// Approve pending booking (changed from confirm)
function approvePendingBooking($booking_id) {
    $mysqli = initDatabase();
    
    // Start a transaction for better error handling
    $mysqli->begin_transaction();
    
    try {
        // Update both status and status2 for pending bookings
        $stmt = $mysqli->prepare("
            UPDATE bookings 
            SET status = 'approved', 
                status2 = 'approved' 
            WHERE id = ? AND status = 'pending' AND (status2 IS NULL OR status2 = '')
        ");
        $stmt->bind_param('i', $booking_id);
        
        $result = $stmt->execute();
        
        if ($result && $stmt->affected_rows > 0) {
            $mysqli->commit();
            return true;
        } else {
            $mysqli->rollback();
            return false;
        }
    } catch (Exception $e) {
        $mysqli->rollback();
        error_log("Booking approval error: " . $e->getMessage());
        return false;
    }
}

// Cancel/Reject pending booking
function cancelPendingBooking($booking_id) {
    $mysqli = initDatabase();
    
    // Start a transaction
    $mysqli->begin_transaction();
    
    try {
        // Update both status and status2 to reject for pending bookings
        $stmt = $mysqli->prepare("
            UPDATE bookings 
            SET status = 'reject', 
                status2 = 'reject' 
            WHERE id = ? AND status = 'pending' AND (status2 IS NULL OR status2 = '')
        ");
        $stmt->bind_param('i', $booking_id);
        
        $result = $stmt->execute();
        
        if ($result && $stmt->affected_rows > 0) {
            $mysqli->commit();
            return true;
        } else {
            $mysqli->rollback();
            return false;
        }
    } catch (Exception $e) {
        $mysqli->rollback();
        error_log("Booking cancellation error: " . $e->getMessage());
        return false;
    }
}
// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Login action
    if (isset($_POST['login'])) {
        $username = $_POST['username'];
        $password = $_POST['password'];
        
        if (authenticate($username, $password)) {
            $_SESSION['admin2_logged_in'] = true;
            header('Location: admin2.php');
            exit();
        } else {
            $login_error = "Invalid credentials";
        }
    }
    
    // Approve booking action (changed from confirm)
    if (isset($_POST['approve_booking']) && isset($_POST['booking_id'])) {
        if (approvePendingBooking($_POST['booking_id'])) {
            $_SESSION['success_message'] = "Booking approved successfully";
            header('Location: admin2.php?view=approved');
            exit();
        } else {
            $_SESSION['error_message'] = "Failed to approve booking. It may have already been processed.";
            header('Location: admin2.php?view=pending');
            exit();
        }
    }
    
    // Cancel booking action
    if (isset($_POST['cancel_booking']) && isset($_POST['booking_id'])) {
        if (cancelPendingBooking($_POST['booking_id'])) {
            $_SESSION['success_message'] = "Booking cancelled successfully";
            header('Location: admin2.php?view=pending');
            exit();
        } else {
            $_SESSION['error_message'] = "Failed to cancel booking. It may have already been processed.";
            header('Location: admin2.php?view=pending');
            exit();
        }
    }
}

// Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: admin2.php');
    exit();
}

// Determine current view
$view = isset($_GET['view']) ? $_GET['view'] : 'pending';

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Management Dashboard</title>
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css">
    <style>
    body {
        background-color: #f4f4f4;
        padding: 50px;
        background-image: url(photo/280-25.jpg);
        background-repeat: no-repeat;
        background-size: cover;
        background-attachment: fixed;
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    }

    .admin-container {
        background: rgba(255, 255, 255, 0.3);
        backdrop-filter: blur(10px);
        -webkit-backdrop-filter: blur(10px);
        border: 1px solid rgba(255, 255, 255, 0.2);
        border-radius: 12px;
        padding: 40px;
        box-shadow: 0 4px 6px rgba(0,0,0,0.1);
    }

    .nav-tabs > li > a {
        border-radius: 4px 4px 0 0;
        transition: all 0.3s ease;
    }

    .nav-tabs > li.active > a, 
    .nav-tabs > li.active > a:focus, 
    .nav-tabs > li.active > a:hover {
        background-color: rgba(255,255,255,0.7);
        border-bottom: 2px solid #007bff;
        color: #007bff;
    }
    .nav-tabs > li.active > a.rejected-tab {
        border-bottom: 2px solid #d9534f;
    }
    
    .booking-details.rejected {
        border-left: 4px solid #d9534f;
    }
    .booking-details {
        margin-bottom: 15px;
        padding: 20px;
        border-radius: 8px;
        border-left: 4px solid #007bff;
        background-color: rgba(255,255,255,0.8);
        position: relative;
        transition: all 0.3s ease;
        box-shadow: 0 2px 5px rgba(0,0,0,0.1);
    }

    .booking-details:hover {
        transform: translateY(-5px);
        box-shadow: 0 4px 10px rgba(0,0,0,0.15);
    }

    .booking-details .label {
        position: absolute;
        top: 20px;
        right: 20px;
        font-size: 12px;
        padding: 5px 10px;
    }

    .office-type-container {
        display: flex;
        flex-wrap: wrap;
        gap: 15px;
        justify-content: center;
    }

    .office-type-card {
        background-color: rgba(255,255,255,0.8);
        border-radius: 10px;
        padding: 20px;
        width: calc(33.333% - 15px);
        box-shadow: 0 3px 6px rgba(0,0,0,0.1);
        transition: all 0.3s ease;
        text-align: center;
    }

    .office-type-card:hover {
        transform: scale(1.05);
        box-shadow: 0 5px 15px rgba(0,0,0,0.2);
    }

    .office-type-card h4 {
        margin-bottom: 15px;
        color: #333;
    }

    .office-type-card .stat {
        font-size: 24px;
        font-weight: bold;
        color: #007bff;
    }

    @media (max-width: 768px) {
        .office-type-card {
            width: calc(50% - 15px);
        }
    }

    @media (max-width: 480px) {
        .office-type-card {
            width: 100%;
        }
    }
</style>

</head>
<body>
    <div class="container">
        <div class="row">
            <div class="col-md-12 admin-container">
                <?php if (!isset($_SESSION['admin2_logged_in'])): ?>
                    <!-- Login Form -->
                    <h2 class="text-center">Booking Management Portal-DGM</h2>
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
                    <h2 class="text-center">Booking Management Dashboard-DGM
                        <a href="admin2.php?logout=1" class="btn btn-danger btn-sm pull-right">Logout</a>
                    </h2>
                    
                    <!-- Success and Error Messages -->
                    <?php 
                    if (isset($_SESSION['success_message'])): ?>
                        <div class="alert alert-success"><?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?></div>
                    <?php endif; ?>
                    
                    <?php if (isset($_SESSION['error_message'])): ?>
                        <div class="alert alert-danger"><?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?></div>
                    <?php endif; ?>
                    
                    <!-- Navigation Tabs -->
                    <ul class="nav nav-tabs">
                        <li class="<?php echo $view === 'pending' ? 'active' : ''; ?>">
                            <a href="admin2.php?view=pending">Pending Bookings</a>
                        </li>
                        <li class="<?php echo $view === 'approved' ? 'active' : ''; ?>">
                            <a href="admin2.php?view=approved">Approved Bookings</a>
                        </li>
                        <li class="<?php echo $view === 'rejected' ? 'active' : ''; ?>">
                            <a href="admin2.php?view=rejected" class="rejected-tab">Rejected Bookings</a>
                        </li>
                    </ul>
                    
                    <?php 
                    // Determine which bookings to fetch based on current view
                    switch ($view) {
                        case 'pending':
                            $bookings = getPendingBookings();
                            $page_title = "Pending Bookings";
                            $label_class = "label-warning";
                            break;
                        case 'approved':
                            $bookings = getApprovedBookings();
                            $page_title = "Approved Bookings";
                            $label_class = "label-success";
                            break;
                            case 'rejected':
                                $bookings = getRejectedBookings();
                                $page_title = "Rejected Bookings";
                                $label_class = "label-danger";
                                break;
                        default:
                            $bookings = getPendingBookings();
                            $page_title = "Pending Bookings";
                            $label_class = "label-warning";
                    }
                    ?>
                    
                    <h3><?php echo $page_title; ?></h3>
                    
                    <?php if (empty($bookings)): ?>
                        <div class="alert alert-info">No <?php echo strtolower($page_title); ?> at the moment.</div>
                    <?php else: ?>
                        <?php foreach ($bookings as $booking): ?> 
                            <div class="booking-details">
                                <span class="label <?php echo $label_class; ?>"><?php 
                                     switch($view) {
                                        case 'pending':
                                            echo 'Pending';
                                            break;
                                        case 'approved':
                                            echo 'Approved';
                                            break;
                                        case 'rejected':
                                            echo 'Rejected';
                                            break;
                                    }
                                ?></span>
                                
                                <h4>
                                    Booking at <?php echo htmlspecialchars($booking['station_name']); ?>
                                    <?php if ($view === 'pending'): ?>
                                        <form method="POST" class="pull-right" style="margin-left: 10px;" onsubmit="return confirm('Are you sure?');">
                                            <input type="hidden" name="booking_id" value="<?php echo $booking['id']; ?>"><br>
                                            <button type="submit" name="approve_booking" class="btn btn-xs btn-success">Approve</button>
                                            <button type="submit" name="cancel_booking" class="btn btn-xs btn-danger">Cancel</button>
                                        </form>
                                    <?php endif; ?>
                                </h4> 
                                
                                <!-- Booking Details -->
                                <p style="color: red;"><strong>Booking ID:</strong> <?php echo htmlspecialchars($booking['id']); ?></p>
                                <p style="color: red;"><strong>Power Station ID :</strong> <?php echo htmlspecialchars($booking['power_station_id']); ?></p>
                                <p><strong>Name:</strong> <?php echo htmlspecialchars($booking['Name']); ?></p>
                                <p><strong>Organization:</strong> <?php echo htmlspecialchars($booking['Organization']); ?></p>
                                <p><strong>Address:</strong> <?php echo htmlspecialchars($booking['address']); ?></p>
                                <p><strong>Email:</strong> <?php echo htmlspecialchars($booking['email']); ?></p>
                                <p><strong>CEB Employee:</strong><?php echo htmlspecialchars($booking['ceb_employee']); ?>
                                <strong>EPF Number:</strong><?php echo htmlspecialchars($booking['epf_number']); ?> </p>
                                <p><strong>NIC:</strong> <?php echo htmlspecialchars($booking['nic']); ?></p>
                                <p><strong>Date:</strong> <?php echo htmlspecialchars($booking['date']); ?></p>
                                <p><strong>Time:</strong> <?php echo htmlspecialchars($booking['time_slot']); ?></p>
                                <p><strong>Visitors:</strong> <?php echo htmlspecialchars($booking['visitors_count']); ?></p>
                                <p><strong>Contact:</strong> <?php echo htmlspecialchars($booking['email']); ?> | <?php echo htmlspecialchars($booking['ContactNumber']); ?></p>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/1.12.4/jquery.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>
</body>
</html>   