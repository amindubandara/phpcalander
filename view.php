<?php
session_start();

// Hardcoded username and password
define('USERNAME', 'view');
define('PASSWORD', 'view123');

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $inputUsername = $_POST['username'] ?? '';
    $inputPassword = $_POST['password'] ?? '';

    if ($inputUsername === USERNAME && $inputPassword === PASSWORD) {
        $_SESSION['logged_in'] = true;
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    } else {
        $loginError = "Invalid username or password.";
    }
}

// Redirect to login page if not logged in
if (!isset($_SESSION['logged_in'])) {
    showLoginForm($loginError ?? null);
    exit();
}

// Fetch data and display in a dashboard
function fetchAndDisplayDashboard() {
    class DatabaseConnection {
        private $host = 'localhost';
        private $username = 'root';
        private $password = '';
        private $database = 'bookps';
        public $conn;

        public function __construct() {
            $this->conn = new mysqli($this->host, $this->username, $this->password, $this->database);
            if ($this->conn->connect_error) {
                die("Connection failed: " . $this->conn->connect_error);
            }
        }

        public function closeConnection() {
            $this->conn->close();
        }
    }

    $db = new DatabaseConnection();

    // Fetch data
    $bookingsResult = $db->conn->query("SELECT * FROM bookings");
    $stationsResult = $db->conn->query("SELECT * FROM power_stations");
    $usersResult = $db->conn->query("SELECT * FROM power_station_users");

    echo "<div class='dashboard'>";

    // Sidebar navigation
    echo "<nav class='sidebar'>
            <h2>Navigation</h2>
            <ul>
                <li><a href='#bookings'>Bookings</a></li>
                <li><a href='#stations'>Power Stations</a></li>
                <li><a href='#users'>Power Station Users</a></li>
                <li><a href='?logout=1' class='logout'>Logout</a></li>
            </ul>
          </nav>";

    // Dashboard main content
    echo "<div class='main-content'>
            <header>
                <h1>Database Dashboard</h1>
            </header>
            <section id='bookings'>
                <h2>Bookings</h2>
                <div class='card'>
                    <table class='table'>
                        <thead><tr><th>ID</th><th>Power Station ID</th><th>Name</th><th>Email</th><th>Visitors Count</th><th>Status</th></tr></thead><tbody>";
    while ($row = $bookingsResult->fetch_assoc()) {
        $emailLink = "<a href='mailto:{$row['email']}'>{$row['email']}</a>";
        echo "<tr>
                <td>{$row['id']}</td>
                <td>{$row['power_station_id']}</td>
                <td>{$row['Name']}</td>
                <td>{$emailLink}</td>
                <td>{$row['visitors_count']}</td>
                <td>{$row['status']}</td>
              </tr>";
    }
    echo "</tbody></table>
                </div>
            </section>

            <section id='stations'>
                <h2>Power Stations</h2>
                <div class='card'>
                    <table class='table'>
                        <thead><tr><th>ID</th><th>Name</th><th>Visitors Per Hour</th><th>Capacity</th></tr></thead><tbody>";
    while ($row = $stationsResult->fetch_assoc()) {
        echo "<tr><td>{$row['id']}</td><td>{$row['name']}</td><td>{$row['daily_limit']}</td><td>{$row['visitors_per_hour']}</td></tr>";
    }
    echo "</tbody></table>
                </div>
            </section>

            <section id='users'>
                <h2>Power Station Users</h2>
                <div class='card'>
                    <table class='table'>
                        <thead><tr><th>ID</th><th>Name</th><th>User</th><th>Password</th></tr></thead><tbody>";
    while ($row = $usersResult->fetch_assoc()) {
        echo "<tr><td>{$row['id']}</td><td>{$row['power_station_id']}</td><td>{$row['username']}</td><td>{$row['password_hash']}</td></tr>";
    }
    echo "</tbody></table>
                </div>
            </section>
          </div>";

    echo "</div>";

    $db->closeConnection();
}

// Login form
function showLoginForm($error = null) {
    echo "<!DOCTYPE html>
    <html lang='en'>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>Login</title>
        <link rel='stylesheet' href='https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css'>
    </head>
    <body>
    <div class='container' style='margin-top: 50px; max-width: 400px;'>
        <h2>Login</h2>
        <form method='POST'>
            <div class='form-group'>
                <label for='username'>Username:</label>
                <input type='text' class='form-control' id='username' name='username' required>
            </div>
            <div class='form-group'>
                <label for='password'>Password:</label>
                <input type='password' class='form-control' id='password' name='password' required>
            </div>";
    if ($error) {
        echo "<div class='alert alert-danger'>{$error}</div>";
    }
    echo "  <button type='submit' class='btn btn-primary'>Login</button>
        </form>
    </div>
    </body>
    </html>";
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard</title>
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>
        .dashboard {
            display: flex;
        }
        .sidebar {
            width: 20%;
            background: #343a40;
            color: white;
            padding: 20px;
        }
        .sidebar h2 {
            font-size: 20px;
            text-align: center;
            margin-bottom: 20px;
        }
        .sidebar ul {
            list-style-type: none;
            padding: 0;
        }
        .sidebar ul li {
            margin-bottom: 10px;
        }
        .sidebar ul li a {
            color: white;
            text-decoration: none;
        }
        .main-content {
            width: 80%;
            padding: 20px;
        }
        .card {
            background: white;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }
    </style>
</head>
<body>
<?php fetchAndDisplayDashboard(); ?>
</body>
</html>
