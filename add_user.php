<?php
session_start();

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

// Get all power stations for dropdown
function getPowerStations() {
    $mysqli = initDatabase();
    $result = $mysqli->query("SELECT id, name FROM power_stations ORDER BY name");
    return $result->fetch_all(MYSQLI_ASSOC);
}

// Add new user
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_user'])) {
        $mysqli = initDatabase();
        
        $station_id = $_POST['power_station_id'];
        $username = $_POST['username'];
        $password = $_POST['password'];
        
        // Hash the password
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        
        $stmt = $mysqli->prepare("
            INSERT INTO power_station_users (power_station_id, username, password_hash)
            VALUES (?, ?, ?)
        ");
        
        $stmt->bind_param('iss', $station_id, $username, $password_hash);
        
        if ($stmt->execute()) {
            $success_message = "User added successfully!";
        } else {
            $error_message = "Error adding user: " . $mysqli->error;
        }
    }
}

$power_stations = getPowerStations();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Power Station User</title>
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css">
    <style>
        body {
            background-color: #f4f4f4;
            padding: 20px;
        }
        .container {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            max-width: 600px;
            margin: 40px auto;
        }
        .current-users {
            margin-top: 30px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h2 class="text-center mb-4">Add Power Station User</h2>
        
        <?php if (isset($success_message)): ?>
            <div class="alert alert-success"><?php echo $success_message; ?></div>
        <?php endif; ?>
        
        <?php if (isset($error_message)): ?>
            <div class="alert alert-danger"><?php echo $error_message; ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label>Power Station</label>
                <select name="power_station_id" class="form-control" required>
                    <option value="">Select Power Station</option>
                    <?php foreach ($power_stations as $station): ?>
                        <option value="<?php echo $station['id']; ?>">
                            <?php echo htmlspecialchars($station['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>Username</label>
                <input type="text" name="username" class="form-control" required
                       placeholder="Enter username">
            </div>

            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" class="form-control" required
                       placeholder="Enter password">
            </div>

            <button type="submit" name="add_user" class="btn btn-primary btn-block">
                Add User
            </button>
        </form>

        <div class="current-users">
            <h3>Current Users</h3>
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>Username</th>
                        <th>Power Station</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $mysqli = initDatabase();
                    $result = $mysqli->query("
                        SELECT u.username, p.name as station_name
                        FROM power_station_users u
                        JOIN power_stations p ON u.power_station_id = p.id
                        ORDER BY p.name
                    ");
                    while ($row = $result->fetch_assoc()):
                    ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['username']); ?></td>
                            <td><?php echo htmlspecialchars($row['station_name']); ?></td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script src="https://ajax.googleapis.com/ajax/libs/jquery/1.12.4/jquery.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>
</body>
</html>