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

// Enhanced authentication function
function authenticate($username, $password) {
    $mysqli = initDatabase();
    
    $stmt = $mysqli->prepare("
        SELECT ps.id, ps.name, u.password_hash 
        FROM power_station_users u
        JOIN power_stations ps ON u.power_station_id = ps.id
        WHERE u.username = ?
    ");
    
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        if (password_verify($password, $row['password_hash'])) {
            $_SESSION['power_station_id'] = $row['id'];
            $_SESSION['power_station_name'] = $row['name'];
            return true;
        }
    }
    return false;
}

// Get power station details for logged-in user
function getPowerStation() {
    if (!isset($_SESSION['power_station_id'])) {
        return null;
    }
    
    $mysqli = initDatabase();
    $stmt = $mysqli->prepare("SELECT * FROM power_stations WHERE id = ?");
    $stmt->bind_param('i', $_SESSION['power_station_id']);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

function updateVisitStatus($booking_id, $status4) {
    if (!isset($_SESSION['power_station_id'])) {
        return false;
    }
    
    $mysqli = initDatabase();
    
    $mysqli->begin_transaction();
    
    try {
        $stmt = $mysqli->prepare("
            UPDATE bookings 
            SET status4 = ?
            WHERE id = ? 
            AND power_station_id = ?
            AND status3 = 'confirmed'
        ");
        
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $mysqli->error);
        }
        
        $stmt->bind_param('sii', $status4, $booking_id, $_SESSION['power_station_id']);
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
        error_log("Visit status update error: " . $e->getMessage());
        return false;
    }
}

// Modify getStationBookings to include status4
function getStationBookings($status) {
    if (!isset($_SESSION['power_station_id'])) {
        return array();
    }
    
    $mysqli = initDatabase();
    
    $query = "SELECT b.*, p.name as station_name 
              FROM bookings b
              LEFT JOIN power_stations p ON b.power_station_id = p.id
              WHERE b.power_station_id = ? 
              AND b.status2 = 'approved'";
              
    if ($status === 'approved') {
        $query .= " AND (b.status3 IS NULL OR b.status3 = '')";
    } else if ($status === 'confirmed') {
        $query .= " AND b.status3 = 'confirmed'";
    } else if ($status === 'cancelled') {
        $query .= " AND b.status3 = 'cancelled'";
    } else if ($status === 'visited') {
        $query .= " AND b.status3 = 'confirmed' AND b.status4 = 'Visited'";
    } else if ($status === 'not_visited') {
        $query .= " AND b.status3 = 'confirmed' AND b.status4 = 'Not Visited'";
    }
    
    $stmt = $mysqli->prepare($query);
    $stmt->bind_param('i', $_SESSION['power_station_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if (!$result) {
        error_log("Query Error: " . $mysqli->error);
        return array();
    }
    
    return $result->fetch_all(MYSQLI_ASSOC);
}

// Add new POST handler for visit status
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_visit_status']) && isset($_POST['booking_id']) && isset($_POST['status4'])) {
        if (updateVisitStatus($_POST['booking_id'], $_POST['status4'])) {
            $_SESSION['success_message'] = "Visit status updated successfully";
        } else {
            $_SESSION['error_message'] = "Failed to update visit status";
        }
        header('Location: ' . $_SERVER['HTTP_REFERER']);
        exit();
    }}

// New function to cancel booking
function cancelBooking($booking_id) {
    if (!isset($_SESSION['power_station_id'])) {
        return false;
    }
    
    $mysqli = initDatabase();
    
    $mysqli->begin_transaction();
    
    try {
        $stmt = $mysqli->prepare("
            UPDATE bookings 
            SET status3 = 'cancelled'
            WHERE id = ? 
            AND power_station_id = ?
            AND status2 = 'approved'
        ");
        
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $mysqli->error);
        }
        
        $stmt->bind_param('ii', $booking_id, $_SESSION['power_station_id']);
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

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['login'])) {
        if (authenticate($_POST['username'], $_POST['password'])) {
            header('Location: admin3.php');
            exit();
        } else {
            $login_error = "Invalid credentials";
        }
    }
    
    if (isset($_POST['confirm_booking']) && isset($_POST['booking_id'])) {
        if (confirmApprovedBooking($_POST['booking_id'])) {
            $_SESSION['success_message'] = "Booking confirmed successfully";
        } else {
            $_SESSION['error_message'] = "Failed to confirm booking";
        }
        header('Location: ' . $_SERVER['HTTP_REFERER']);
        exit();
    }
    
    // New POST handler for cancelling bookings
    if (isset($_POST['cancel_booking']) && isset($_POST['booking_id'])) {
        if (cancelBooking($_POST['booking_id'])) {
            $_SESSION['success_message'] = "Booking cancelled successfully";
        } else {
            $_SESSION['error_message'] = "Failed to cancel booking";
        }
        header('Location: ' . $_SERVER['HTTP_REFERER']);
        exit();
    }
}

// Confirm booking function
function confirmApprovedBooking($booking_id) {
    if (!isset($_SESSION['power_station_id'])) {
        return false;
    }
    
    $mysqli = initDatabase();
    
    $mysqli->begin_transaction();
    
    try {
        // Add power station check to prevent confirming other stations' bookings
        $stmt = $mysqli->prepare("
            UPDATE bookings 
            SET status3 = 'confirmed'
            WHERE id = ? 
            AND power_station_id = ?
            AND status2 = 'approved'
            AND (status3 IS NULL OR status3 = '')
        ");
        
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $mysqli->error);
        }
        
        $stmt->bind_param('ii', $booking_id, $_SESSION['power_station_id']);
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
        error_log("Booking confirmation error: " . $e->getMessage());
        return false;
    }
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['login'])) {
        if (authenticate($_POST['username'], $_POST['password'])) {
            header('Location: admin3.php');
            exit();
        } else {
            $login_error = "Invalid credentials";
        }
    }
    
    if (isset($_POST['confirm_booking']) && isset($_POST['booking_id'])) {
        if (confirmApprovedBooking($_POST['booking_id'])) {
            $_SESSION['success_message'] = "Booking confirmed successfully";
        } else {
            $_SESSION['error_message'] = "Failed to confirm booking";
        }
        header('Location: ' . $_SERVER['HTTP_REFERER']);
        exit();
    }
}

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: admin3.php');
    exit();
}

$view = isset($_GET['view']) ? $_GET['view'] : 'approved';
$power_station = getPowerStation();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Power Station Booking Dashboard</title>
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css">
    <style>
    body {
        background-color: #f4f4f4;
        padding: 20px;
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
        border-radius: 12px;
        padding: 20px;
        margin-bottom: 20px;
        margin-top: 25px;   
    }

    .station-card {
        background: rgba(255, 255, 255, 0.9);
        border-radius: 8px;
        padding: 15px;
        margin-bottom: 20px;
        box-shadow: 0 2px 5px rgba(0,0,0,0.1);
    }

    .station-header {
        background: #007bff;
        color: white;
        padding: 10px;
        border-radius: 5px;
        margin-bottom: 15px;
    }

    .booking-item {
        border-left: 4px solid #007bff;
        padding: 10px;
        margin-bottom: 10px;
        background: white;
        border-radius: 4px;
    }

    .nav-tabs {
        border-bottom: 2px solid #007bff;
    }

    .nav-tabs > li.active > a {
        border-bottom: 2px solid #007bff;
    }

    .status-badge {
        float: right;
        padding: 5px 10px;
        border-radius: 3px;
        font-size: 12px;
    }

    .badge-approved {
        background: #ffc107;
        color: black;
    }

    .badge-confirmed {
        background: #28a745;
        color: white;
    }
    .visit-status-form {
        margin-top: 10px;
    }
    .visit-status-badge {
        display: inline-block;
        padding: 5px 10px;
        border-radius: 3px;
        margin-top: 10px;
        color: white;
        font-weight: bold;
    }
    .badge-success {
        background-color: #28a745;
    }
    .badge-danger {
        background-color: #dc3545;
    }
    </style>
</head>
<body>
    <div class="container-fluid">
        <?php if (!isset($_SESSION['power_station_id'])): ?>
            <div class="row">
                <div class="col-md-4 col-md-offset-4 admin-container">
                    <h2 class="text-center">Power Station Dashboard Login</h2>
                    <?php if (isset($login_error)): ?>
                        <div class="alert alert-danger"><?php echo $login_error; ?></div>
                    <?php endif; ?>
                    <form method="POST">
                        <div class="form-group">
                            <label>Username</label>
                            <input type="text" class="form-control" name="username" required>
                        </div>
                        <div class="form-group">
                            <label>Password</label>
                            <input type="password" class="form-control" name="password" required>
                        </div>
                        <button type="submit" name="login" class="btn btn-primary btn-block">Login</button>
                    </form>
                </div>
            </div>
        <?php else: ?>
            <div class="row">
                <div class="col-md-12 admin-container">
                    <h2 class="text-center">
                        <?php echo htmlspecialchars($_SESSION['power_station_name']); ?> - Booking Dashboard
                        <a href="admin3.php?logout=1" class="btn btn-danger btn-sm pull-right">Logout</a>
                    </h2>
                    
                    <?php if (isset($_SESSION['success_message'])): ?>
                        <div class="alert alert-success"><?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?></div>
                    <?php endif; ?>
                    
                    <?php if (isset($_SESSION['error_message'])): ?>
                        <div class="alert alert-danger"><?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?></div>
                    <?php endif; ?>

                    <ul class="nav nav-tabs">
                        <li class="<?php echo $view === 'approved' ? 'active' : ''; ?>">
                            <a href="admin3.php?view=approved">Approved Bookings</a>
                        </li>
                        <li class="<?php echo $view === 'confirmed' ? 'active' : ''; ?>">
                            <a href="admin3.php?view=confirmed">Confirmed Bookings</a>
                        </li>
                        <li class="<?php echo $view === 'cancelled' ? 'active' : ''; ?>">
                            <a href="admin3.php?view=cancelled">Cancelled Bookings</a>
                        </li>
                        <li class="<?php echo $view === 'visited' ? 'active' : ''; ?>">
                            <a href="admin3.php?view=visited">Visited</a>
                        </li>
                        <li class="<?php echo $view === 'not_visited' ? 'active' : ''; ?>">
                            <a href="admin3.php?view=not_visited">Not Visited</a>
                        </li>
                    </ul>
                    

                    <div class="row" style="margin-top: 20px;">
                        <div class="col-md-12">
                            <div class="station-card">
                                <?php $bookings = getStationBookings($view); ?>
                                <div class="station-header">
                                    <h4 style="margin: 0;">
                                        Bookings
                                        <span class="badge pull-right">
                                            <?php echo count($bookings); ?> bookings
                                        </span>
                                    </h4>
                                </div>
                                
                                <?php if (empty($bookings)): ?>
                                    <p class="text-muted">No <?php echo $view; ?> bookings</p>
                                <?php else: ?>
                                    <?php foreach ($bookings as $booking): ?>
                                        <div class="booking-item">
                                            <span class="status-badge <?php echo $view === 'approved' ? 'badge-approved' : 'badge-confirmed'; ?>">
                                                <?php echo ucfirst($view); ?>
                                            </span>
                                            <p style="color: red;"><strong>Booking ID:</strong> <?php echo htmlspecialchars($booking['id']); ?></p>
                                            <p style="color: red;"><strong>Power Station ID :</strong> <?php echo htmlspecialchars($booking['power_station_id']); ?></p>
                                            <p><strong>Name:</strong> <?php echo htmlspecialchars($booking['Name']); ?></p>
                                            <p><strong>Date:</strong> <?php echo htmlspecialchars($booking['date']); ?></p>
                                            <p><strong>Time:</strong> <?php echo htmlspecialchars($booking['time_slot']); ?></p>
                                            <p><strong>Visitors:</strong> <?php echo htmlspecialchars($booking['visitors_count']); ?></p>
                                            <!--<button type="button" class="btn btn-info btn-sm btn-block" 
                                                    data-toggle="collapse" 
                                                    data-target="#details-<?php echo $booking['id']; ?>">
                                                View Full Details
                                            </button><br>
                                            
                                            <div id="details-<?php echo $booking['id']; ?>" class="collapse" style="margin-top: 10px;">-->
                                                <p><strong>Organization:</strong> <?php echo htmlspecialchars($booking['Organization']); ?></p>
                                                <p><strong>Address:</strong> <?php echo htmlspecialchars($booking['address']); ?></p>
                                                <p><strong>Email:</strong> <?php echo htmlspecialchars($booking['email']); ?></p>
                                                <p><strong>CEB Employee:</strong><?php echo htmlspecialchars($booking['ceb_employee']); ?>
                                                <strong>EPF Number:</strong><?php echo htmlspecialchars($booking['epf_number']); ?> </p>
                                                <p><strong>NIC:</strong> <?php echo htmlspecialchars($booking['nic']); ?></p>
                                                <p><strong>Contact:</strong> <?php echo htmlspecialchars($booking['ContactNumber']); ?></p>
                                            </div>
                                            
                                            <?php if ($view === 'approved'): ?>
                                                <form method="POST" onsubmit="return confirm('Confirm this booking?');">
                                                    <input type="hidden" name="booking_id" value="<?php echo $booking['id']; ?>">
                                                    <button type="submit" name="confirm_booking" class="btn btn-success btn-sm btn-block">
                                                        Confirm Booking
                                                    </button>
                                                    <button type="submit" name="cancel_booking" class="btn btn-danger btn-sm btn-block">
                                                        Cancel Booking
                                                    </button>
                                                </form>
                                            <?php endif; ?>

                                            <?php if ($view === 'confirmed' && empty($booking['status4'])): ?>
                                                <form method="POST" class="visit-status-form">
                                                    <input type="hidden" name="booking_id" value="<?php echo $booking['id']; ?>">
                                                    <input type="hidden" name="update_visit_status" value="1">
                                                    <div class="btn-group btn-group-justified" role="group">
                                                        <div class="btn-group" role="group">
                                                            <button type="submit" name="status4" value="Visited" 
                                                                    class="btn btn-success">Mark as Visited</button>
                                                        </div>
                                                        <div class="btn-group" role="group">
                                                            <button type="submit" name="status4" value="Not Visited" 
                                                                    class="btn btn-danger">Mark as Not Visited</button>
                                                        </div>
                                                    </div>
                                                </form>
                                            <?php endif; ?>

                                            <?php if (!empty($booking['status4'])): ?>
                                                <div class="visit-status-badge <?php echo $booking['status4'] === 'Visited' ? 'badge-success' : 'badge-danger'; ?>">
                                                    <?php echo htmlspecialchars($booking['status4']); ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
    
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/1.12.4/jquery.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>
</body>
</html>