<?php
class DatabaseConnection {
    private $host = 'localhost';
    private $username = 'root';
    private $password = '';
    private $database = 'bookps';
    public $conn;

    public function __construct() {
        // Create connection
        $this->conn = new mysqli($this->host, $this->username, $this->password, $this->database);

        // Check connection
        if ($this->conn->connect_error) {
            die("Connection failed: " . $this->conn->connect_error);
        }
    }

    public function closeConnection() {
        $this->conn->close();
    }
}