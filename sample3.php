<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Power Station Visit Booking</title>
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css">
    <style>
        body { padding: 20px; }
        .booking-container {
            max-width: 600px;
            margin: 0 auto;
            padding: 15px;
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 5px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 30px;
            border-radius: 10px;
        }
        .form-header {
            background: #1e5dd3;
            color: white;
            padding: 20px;
            margin: -15px -15px 15px;
            font-size: 18px;
            font-weight: bold;
            text-align: center;
        }
        .form-group {
            margin-bottom: 10px;
        }
        .form-control {
            height: 34px;
            padding: 6px 12px;
        }
        .error-message {
            color: red;
            font-size: 12px;
            margin-top: 2px;
        }
        .btn-primary {
            margin-top: 10px;
            background: #28a745;
            border-color: #28a745;
        }
        label { font-weight: 600; margin-bottom: 3px; }
        small { font-size: 11px; color: #666; }
        .form-group:last-child { margin-bottom: 0; }
    </style>
</head>
<body>
    <div class="booking-container">
        <div class="form-header">Power Station Visit Booking</div>
        <?php if (isset($error_message)): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div>
        <?php endif; ?> 

        <form action="" method="post" enctype="multipart/form-data">
            <div class="row">
                <div class="col-xs-4">
                    <div class="form-group">
                        <label>Title</label>
                        <select name="title" class="form-control" required>
                            <option value="Mr.">Mr.</option>
                            <option value="Mrs.">Mrs.</option>
                            <option value="Ms.">Ms.</option>
                            <option value="Dr.">Dr.</option>
                        </select>
                    </div>
                </div>
                <div class="col-xs-8">
                    <div class="form-group">
                        <label>Full Name</label>
                        <input type="text" name="Name" class="form-control" required>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-xs-6">
                    <div class="form-group">
                        <label>Organization</label>
                        <input type="text" name="Organization" class="form-control" required>
                    </div>
                </div>
                <div class="col-xs-6">
                    <div class="form-group">
                        <label>NIC Number</label>
                        <input type="text" name="nic" class="form-control" required>
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label>Address</label>
                <input type="text" name="address" class="form-control" required>
            </div>

            <div class="row">
                <div class="col-xs-6">
                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" name="email" class="form-control" required>
                    </div>
                </div>
                <div class="col-xs-6">
                    <div class="form-group">
                        <label>Contact Number</label>
                        <input type="tel" name="contactNumber" class="form-control" required>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-xs-6">
                    <div class="form-group">
                        <label>Power Station</label>
                        <select name="station" class="form-control" required>
                            <option value="">Select Station</option>
                            <?php foreach($power_stations as $station): ?>
                                <option value="<?php echo $station['id']; ?>">
                                    <?php echo htmlspecialchars($station['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="col-xs-6">
                    <div class="form-group">
                        <label>Time Slot</label>
                        <select name="time_slot" class="form-control" required>
                            <option value="">Select Time</option>
                            <option value="08:00">08:00 AM</option>
                            <option value="09:00">09:00 AM</option>
                            <option value="10:00">10:00 AM</option>
                            <option value="11:00">11:00 AM</option>
                            <option value="12:00">12:00 PM</option>
                            <option value="13:00">01:00 PM</option>
                            <option value="14:00">02:00 PM</option>
                            <option value="15:00">03:00 PM</option>
                            <option value="16:00">04:00 PM</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-xs-6">
                    <div class="form-group">
                        <label>Booking Date</label>
                        <input type="date" name="date" class="form-control" 
                               min="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                </div>
                <div class="col-xs-6">
                    <div class="form-group">
                        <label>Number of Visitors</label>
                        <input type="number" name="visitors_count" 
                               class="form-control" min="1" max="50" required>
                    </div>
                </div>
            </div>
            <div class="form-group ceb-employee-section">
    <label>Are you a CEB Employee?</label>
    <div class="radio-group">
        <label class="radio-inline">
            <input type="radio" name="ceb_employee" value="1" onclick="toggleEPFField(true)"> Yes
        </label>
        <label class="radio-inline" style="margin-left: 15px;">
            <input type="radio" name="ceb_employee" value="0" onclick="toggleEPFField(false)" checked> No
        </label>
    </div>
    <div id="epf_field" style="display: none;">
        <label for="epf_number">EPF Number</label>
        <input type="text" class="form-control" name="epf_number" id="epf_number" pattern="[0-9]{5,10}" title="Please enter a valid EPF number">
        <small class="help-block">Enter your CEB EPF number for verification</small>
    </div>
</div>
            <div class="form-group">
                <label>Organization Letter (PDF)</label>
                <input type="file" name="pdf_attachment" accept=".pdf" class="form-control" required>
                <small>Max: 10MB, PDF only</small>
            </div>

            <button type="submit" class="btn btn-primary btn-block">Submit Booking</button>
        </form>
    </div>
    <script>
function toggleEPFField(show) {
    const epfField = document.getElementById('epf_field');
    const epfInput = document.getElementById('epf_number');
    
    if (show) {
        epfField.style.display = 'block';
        epfInput.required = true;
    } else {
        epfField.style.display = 'none';
        epfInput.required = false;
        epfInput.value = '';
    }
}
</script>
</body>
</html>