<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Registration Successful</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
            text-align: center;
            background-color: #f4f4f4;
        }
        .success-container {
            background-color: white;
            border-radius: 10px;
            padding: 30px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        .success-icon {
            color: #4CAF50;
            font-size: 64px;
            margin-bottom: 20px;
        }
        .registration-details {
            text-align: left;
            margin-top: 20px;
            background-color: #f9f9f9;
            padding: 15px;
            border-radius: 5px;
        }
    </style>
</head>
<body>
    <div class="success-container">
        <div class="success-icon">✓</div>
        <h1>Registration Submitted Successfully</h1>
        <p>Thank you for your registration to visit the power station.</p>
        
        <?php
        require_once 'db_connection.php';

        if (isset($_GET['id'])) {
            $registrationId = intval($_GET['id']);
            $db = new DatabaseConnection();
            
            $sql = "SELECT * FROM visitor_registrations WHERE registration_id = $registrationId";
            $result = $db->conn->query($sql);
            
            if ($result->num_rows > 0) {
                $registration = $result->fetch_assoc();
                echo "<div class='registration-details'>";
                echo "<h2>Registration Details:</h2>";
                echo "<p><strong>Registration ID:</strong> " . htmlspecialchars($registration['registration_id']) . "</p>";
                echo "<p><strong>Name:</strong> " . htmlspecialchars($registration['full_name']) . "</p>";
                echo "<p><strong>Email:</strong> " . htmlspecialchars($registration['email']) . "</p>";
                echo "<p><strong>Visit Date:</strong> " . htmlspecialchars($registration['visit_date']) . "</p>";
                echo "<p><strong>Power Station:</strong> " . htmlspecialchars($registration['power_station']) . "</p>";
                echo "</div>";
            }
            
            $db->closeConnection();
        }
        ?>
        
        <p>Your registration is currently being processed. You will be notified about the status.</p>
        <a href="index.php" style="display: inline-block; margin-top: 20px; padding: 10px 20px; background-color: #4CAF50; color: white; text-decoration: none; border-radius: 5px;">Back to Registration</a>
    </div>
</body>
</html>