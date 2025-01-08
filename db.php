<?php
class DatabaseConnection {
    private $host = 'localhost';
    private $username = 'root';  // Replace with your username
    private $password = '';   // As specified
    private $database = 'bookps';
    public $conn;
    
    public function __construct() {
        try {
            // Create connection
            $this->conn = new mysqli($this->host, $this->username, $this->password, $this->database);
            
            // Check connection
            if ($this->conn->connect_error) {
                throw new Exception("Connection failed: " . $this->conn->connect_error);
            }
        } catch (Exception $e) {
            // Log error and display user-friendly message
            error_log($e->getMessage());
            die("Unable to connect to database. Please try again later.");
        }
    }
    
    public function getUserData() {
        $query = "SELECT * FROM users ORDER BY registration_date DESC LIMIT 10";
        $result = $this->conn->query($query);
        return $result;
    }
    
    public function getStats() {
        $stats = [];
        
        // Get total users
        $query = "SELECT COUNT(*) as total FROM users";
        $result = $this->conn->query($query);
        $stats['total_users'] = $result->fetch_assoc()['total'];
        
        // Get active bookings
        $query = "SELECT COUNT(*) as total FROM bookings WHERE status = 'active'";
        $result = $this->conn->query($query);
        $stats['active_bookings'] = $result->fetch_assoc()['total'];
        
        // Get total stations
        $query = "SELECT COUNT(*) as total FROM power_stations";
        $result = $this->conn->query($query);
        $stats['total_stations'] = $result->fetch_assoc()['total'];
        
        return $stats;
    }
    
    public function closeConnection() {
        $this->conn->close();
    }
}
?>