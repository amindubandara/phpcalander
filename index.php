<?php
// index.php
function build_calendar($month, $year, $selected_station = null) {
    $mysqli = new mysqli('localhost', 'root', '', 'bookps');
    
    if ($mysqli->connect_error) {
        die("Connection failed: " . $mysqli->connect_error);
    }
    
    // Operating hours (8 AM to 5 PM)
    $operating_hours = range(8, 16); // Last entry at 4 PM for 1-hour slots
    $visitors_per_hour = 50;
    
    
    // Get power stations
    $stations_query = "SELECT * FROM power_stations ORDER BY name";
    $stmt = $mysqli->prepare($stations_query);
    $stmt->execute();
    $power_stations = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); 

    // Get start and end date of month
    $start_date = "$year-$month-01";
    $end_date = date('Y-m-t', strtotime($start_date));
    
    // Initialize arrays
    $bookings = [];
    $daily_totals = [];
    
    if ($selected_station) {
        // Debug: Print query parameters
        error_log("Selected Station: $selected_station");
        error_log("Start Date: $start_date");
        error_log("End Date: $end_date");
        
        // Modified query to get all bookings with detailed information
        $query = "SELECT 
                    DATE(date) as booking_date,
                    time_slot,
                    COUNT(*) as booking_count,
                    SUM(visitors_count) as total_visitors
                 FROM bookings 
                 WHERE power_station_id = ? 
                 AND date BETWEEN ? AND ?
                 GROUP BY DATE(date), time_slot
                 ORDER BY DATE(date), time_slot";
        
        $stmt = $mysqli->prepare($query);
        if (!$stmt) {
            error_log("Prepare failed: " . $mysqli->error);
            die("Query prepare failed");
        }
        
        $stmt->bind_param('iss', $selected_station, $start_date, $end_date);
        
        // Execute and check for errors
        if (!$stmt->execute()) {
            error_log("Execute failed: " . $stmt->error);
            die("Query execution failed");
        }
        
        $result = $stmt->get_result();
        
        // Debug: Print row count
        error_log("Number of rows returned: " . $result->num_rows);
        
        while ($row = $result->fetch_assoc()) {
            // Debug: Print each row
            error_log("Processing row: " . print_r($row, true));
            
            $date = $row['booking_date'];
            $time_slot = $row['time_slot'];
            
            // Store time slot bookings
            if (!isset($bookings[$date])) {
                $bookings[$date] = [];
            }
            $bookings[$date][$time_slot] = $row['total_visitors'];
            
            // Update daily totals
            if (!isset($daily_totals[$date])) {
                $daily_totals[$date] = 0;
            }
            $daily_totals[$date] += $row['total_visitors'];
        }
        
        // Debug: Print final arrays
        error_log("Bookings array: " . print_r($bookings, true));
        error_log("Daily totals array: " . print_r($daily_totals, true));
    }

    // Calendar header
    $calendar = '<div class="calendar-container">';
    
    // Power station selector
    $calendar .= '<div class="station-selector mb-4" style="margin-bottom: 30px;">';
    $calendar .= '<h2>Select Your Power Station</h2>';
    $calendar .= '<form method="GET" action="index.php" class="form-inline">';
    $calendar .= '<select name="station" class="form-control mr-3" style="margin-right: 10px;">'; 
    $calendar .= '<option value="" disabled selected>Select Power Station</option>';
    foreach ($power_stations as $station) {
        $selected = ($selected_station == $station['id']) ? 'selected' : '';
        $calendar .= "<option value='{$station['id']}' $selected>" . htmlspecialchars($station['name']) . "</option>";
    }
    $calendar .= '</select>';
    $calendar .= '<input type="hidden" name="month" value="' . $month . '">';
    $calendar .= '<input type="hidden" name="year" value="' . $year . '">';
    $calendar .= '<button type="submit" class="btn btn-primary" style="padding: 7px 6px;">View Calendar</button>';
    $calendar .= '</form>';
    $calendar .= '</div>';

    // Return if no station selected
    if (!$selected_station) {
        return $calendar;
    }

    // Get station details
    $stmt = $mysqli->prepare("SELECT * FROM power_stations WHERE id = ?");
    $stmt->bind_param('i', $selected_station);
    $stmt->execute();
    $station = $stmt->get_result()->fetch_assoc();
    
    $calendar .= "<h2>" . htmlspecialchars($station['name']) . " - Booking Calendar</h2>";
    
    // Navigation buttons 
    //$calendar .= "<a class='btn btn-primary' href='?station=$selected_station&month=".date('m',mktime(0,0,0,$month-1,1,$year))."&year=".date('Y',mktime(0,0,0,$month-1,1,$year))."'>Previous Month</a> ";
    $calendar .= "<div class='calendar-nav'>";
    $calendar .= "<a class='btn btn-default' href='index.php'>All Stations</a> ";
    $calendar .= "<a class='btn btn-primary' href='?station=$selected_station&month=".date('m',mktime(0,0,0,$month+1,1,$year))."&year=".date('Y',mktime(0,0,0,$month+1,1,$year))."'>Next Month </a>";
    $calendar .= "</div>";

    // Calendar grid
    $daysOfWeek = array('Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday');
    $firstDayOfMonth = mktime(0,0,0,$month,1,$year);
    $numberDays = date('t',$firstDayOfMonth);
    $dateComponents = getdate($firstDayOfMonth);
    $monthName = $dateComponents['month'];
    $dayOfWeek = $dateComponents['wday'];
    
    // Table structure
    $calendar .= "<table class='table table-bordered'>";
    $calendar .= "<thead>";
    $calendar .= "<tr><th colspan='7' class='month-header'>$monthName $year</th></tr>";
    $calendar .= "<tr>";
    foreach($daysOfWeek as $day) {
        $calendar .= "<th class='header'>$day</th>";
    }
    $calendar .= "</tr></thead><tbody><tr>";

    if ($dayOfWeek > 0) {
        for($k = 0; $k < $dayOfWeek; $k++) {
            $calendar .= "<td class='empty'></td>";
        }
    }
    
    $currentDay = 1;
    $month = str_pad($month, 2, "0", STR_PAD_LEFT);
    

    // Calculate the minimum bookable date (2 days from now)
    $min_bookable_date = date('Y-m-d', strtotime('+2 days'));
    
    while ($currentDay <= $numberDays) {
        if ($dayOfWeek == 7) {
            $dayOfWeek = 0;
            $calendar .= "</tr><tr>";
        }
        
        $currentDayRel = str_pad($currentDay, 2, "0", STR_PAD_LEFT);
        $date = "$year-$month-$currentDayRel";
        $today = $date == date('Y-m-d') ? "today" : "";
        
        $calendar .= "<td class='day $today'>";
        $calendar .= "<div class='date'>$currentDay</div>";
        
        // Show bookings only for dates 2 or more days in the future
        if (strtotime($date) >= strtotime($min_bookable_date)) {
            $calendar .= "<div class='time-slots'>";
            
            // Get daily total from our tracking array
            $totalDayBookings = isset($daily_totals[$date]) ? $daily_totals[$date] : 0;
            $totalDayAvailable = $station['daily_limit'] - $totalDayBookings;
            
            // Display daily total
            $calendar .= "<div class='daily-total'>";
            $calendar .= "<strong>Daily Total:</strong> ";
            $calendar .= "<span class='booked'>Booked: $totalDayBookings</span> ";
            $calendar .= "<span class='available'>Available: $totalDayAvailable</span>";
            $calendar .= "</div>";
            
            // Display time slots
            foreach ($operating_hours as $hour) {
                $time_slot = str_pad($hour, 2, "0", STR_PAD_LEFT) . ":00";
                
                // Get actual bookings for this time slot
                $slot_bookings = isset($bookings[$date][$time_slot]) ? $bookings[$date][$time_slot] : 0;
                $slot_available = min($visitors_per_hour - $slot_bookings, $totalDayAvailable);
                
                $calendar .= "<div class='time-slot " . ($slot_available <= 0 ? 'full' : '') . "'>";
                $calendar .= "<div class='time-info'>";
                $calendar .= "<span class='time'>$time_slot</span>";
                $calendar .= "<div class='count-info'>";
                
                if ($slot_available > 0 && $totalDayAvailable > 0) {
                    $calendar .= "<a href='book.php?date=$date&station=$selected_station&time=$time_slot' 
                                class='btn btn-xs btn-success'>";
                    $calendar .= "Book</a> ";
                    $calendar .= "<div class='slot-counts'>";
                    $calendar .= "<span class='booked'>Booked: $slot_bookings</span>";
                    $calendar .= "<span class='available'>Available: $slot_available</span>";
                    $calendar .= "</div>";
                } else {
                    $calendar .= "<span class='badge badge-danger'>FULL</span>";
                    $calendar .= "<div class='slot-counts'>";
                    $calendar .= "<span class='booked'>Booked: $slot_bookings</span>";
                    $calendar .= "</div>";
                }
                
                $calendar .= "</div></div></div>";
            }
            $calendar .= "</div>";
        } else {
            $calendar .= "<div class='past-date'>Not available for booking</div>";
        }
        
        $calendar .= "</td>";
        $currentDay++;
        $dayOfWeek++;
    }
    
    if ($dayOfWeek != 7) {
        $remainingDays = 7 - $dayOfWeek;
        for($i = 0; $i < $remainingDays; $i++) {
            $calendar .= "<td class='empty'></td>";
        }
    }
    
    $calendar .= "</tr></tbody></table>";
    $calendar .= "</div>";
    
    return $calendar;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Power Station Visit Bookings</title>
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.0/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
    <style>
    body {
        background-color: #f9f9f9;
        color: #333;
        font-family: Arial, sans-serif;
        display: flex;
        flex-direction: column;
    }

    .container {
        background-color: #ffffff;
        border-radius: 15px;
        margin-top: 30px;
        margin-bottom: 20px;
        box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
        padding: 20px;
    }

    h1.text-center {
        color: #0056b3;
        margin-bottom: 30px;
        font-weight: 600;
        letter-spacing: 1px;
        text-transform: uppercase;
        font-size: 32px;
    }

    .calendar-container, .station-selector {
        max-width: 1200px;
        margin: 0 auto;
        background-color: #ffffff;
        border: 1px solid #eaeaea;
        border-radius: 10px;
        padding: 20px;
        margin-bottom: 30px;
    }

    .station-selector {
        text-align: center;
        color: #0056b3;
        font-size: 20px;
        font-weight: 500;
    }

    .btn-station {
        display: inline-block;
        margin: 10px;
        padding: 12px 20px;
        background-color: #e9f4ff;
        color: #0056b3;
        border: 1px solid #0056b3;
        border-radius: 5px;
        text-transform: uppercase;
        font-weight: bold;
        transition: all 0.3s ease;
    }

    .btn-station:hover {
        background-color: #0056b3;
        color: #ffffff;
    }

    .table-bordered {
        background-color: #fdfdfd;
        border: 1px solid #eaeaea;
    }

    .table-bordered > thead > tr > th {
        background-color: #0056b3;
        color: #ffffff;
        border-color: #ffffff;
    }

    .day {
        background-color: #f9f9f9;
        border-color: #eaeaea;
    }

    .today {
        background-color: #e9f4ff;
        border-color: #0056b3;
    }

    .date {
        color: #333;
    }

    .booked {
        color: #d9534f;
    }

    .available {
        color: #5cb85c;
    }

    .month-header {
        background-color: #0056b3;
        color: #ffffff;
        padding: 10px 0;
    }

    .footer {
        background-color: #f1f1f1;
        color: #333;
        padding: 30px 0;
        border-top: 1px solid #eaeaea;
    }

    .footer-content {
        display: flex;
        justify-content: space-around;
        flex-wrap: wrap;
    }

    .footer-section {
        flex: 1;
        margin: 0 15px;
        min-width: 200px;
    }

    .footer-section h4 {
        color: #0056b3;
        border-bottom: 2px solid #0056b3;
        padding-bottom: 10px;
        margin-bottom: 15px;
        font-weight: bold;
    }

    .footer-section ul {
        list-style-type: none;
        padding: 0;
    }

    .footer-section ul li {
        margin-bottom: 10px;
    }

    .footer-section ul li a {
        color: #0056b3;
        text-decoration: none;
        transition: color 0.3s ease;
    }

    .footer-section ul li a:hover {
        color: #d9534f;
    }

    .power-station-banner {
        width: 100%;
        height: 250px;
        background-image: url('https://images.unsplash.com/photo-1516737490857-847eca181dc1?ixlib=rb-4.0.3&ixid=MnwxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8&auto=format&fit=crop&w=1920&q=80');
        background-size: cover;
        background-position: center;
        position: relative;
        margin-bottom: 20px;
    }

    .banner-overlay {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(255, 255, 255, 0.8);
        display: flex;
        justify-content: center;
        align-items: center;
    }

    .banner-text {
        text-align: center;
        color: #0056b3;
        font-size: 24px;
        font-weight: 500;
    }

    

    .important-note {
        background-color: #ffeeba;
        border: 1px solid #ffeeba;
        color:rgb(17, 16, 16);
        padding: 10px;
        margin-top: 15px;
        border-radius: 5px;
        font-size: 14px;
    }
</style>

    </style>
</head>
<body>
    

    <div class="container">
        
        <h1 class="text-center">Power Station Visit Booking </h1>
        
        <?php
        
        $month = isset($_GET['month']) ? $_GET['month'] : date('m');
        $year = isset($_GET['year']) ? $_GET['year'] : date('Y');
        $station = isset($_GET['station']) ? $_GET['station'] : null;
        
        echo build_calendar($month, $year, $station);
        ?>
        <div class="important-note">
                <i class="fas fa-exclamation-triangle"></i> <strong>Important Notes Read Carefully </strong>
                <ul>
                    <li style="font-weight: bold;  color: red;">Advance Booking Required: All bookings must be made minimum 2 days before the visit date</li>
                    <li>Operating Hours: 8:30 AM to 4:00 PM (last entry at 3:00 PM)</li>
                    <li>Maximum 50 visitors per time slot</li>
                    <li>Bookings are availability 50 visitors per time slot 8:00 AM to 4:00 PM </li>
                </ul>
            </div>
    </div>
    

    <footer class="footer">
            <div class="footer-content">
                <div class="footer-section">
                    <h4>Power Stations</h4>
                    <ul>
                        <li><a href="https://www.mahawelicomplex.lk/power-stations/bow/">Bowatanna Power Station</a></li>
                        <li><a href="https://www.mahawelicomplex.lk/power-stations/kot/">kothmale Power Station</a></li>
                        <li><a href="https://www.mahawelicomplex.lk/power-stations/nil/">Nilambe Power Station</a></li>
                       
                    </ul>
                </div>
                <div class="footer-section">
                    <h4>Power Stations</h4>
                    <ul>
                        <li><a href="https://www.mahawelicomplex.lk/power-stations/rnd/">Randenigala Power Station</a></li>
                        <li><a href="https://www.mahawelicomplex.lk/power-stations/rntb/">Rantambe Power Station</a></li>
                        <li><a href="https://www.mahawelicomplex.lk/power-stations/uku/">Ukuwela Power Station</a></li>
                    </ul>
                </div>
                <div class="footer-section">
                    <h4>Power Stations</h4>
                    <ul>
                        <li><a href="https://www.mahawelicomplex.lk/power-stations/ukot/">Uper Kothmale Power Station</a></li>
                        <li><a href="https://www.mahawelicomplex.lk/power-stations/vps/">Victoria Power Station</a></li>
                        <li><a href="https://www.mahawelicomplex.lk/power-stations/twpp/">Thambapawani Power Station</a></li>
                    </ul>
                </div>
                <div class="footer-section">
                    <h4>Contact Info</h4>
                    <ul>
                        <li><i class="fas fa-phone"></i>+94 81 222 4568 | +94 81 222 4568</li>
                        <li><i class="fas fa-envelope"></i> office.mc@ceb.lk</li>
                        <li><i class="fas fa-map-marker-alt"></i> Mahaweli Hydro Power Complex,Ceylon Electricity Board,No:40/20, Heelpankadura Mawatha,Ampitiya RoadKandy 20000,</li>
                    </ul>
                </div>
            </div>
        </div>
    </footer>
</body>
</html>