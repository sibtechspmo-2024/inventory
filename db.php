<?php
// Siguraduhing ma-start ang session kung wala pa
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$host = "localhost";
$user = "root";
$pass = "";
$dbname = "sibtech_inventory";

// Gumawa ng connection sa MySQL Database
mysqli_report(MYSQLI_REPORT_OFF);
$conn = @new mysqli($host, $user, $pass, $dbname);

// Suriin kung may error sa connection
if ($conn->connect_error) {
    die("Koneksyon sa database ay nabigo: " . $conn->connect_error);
} else {
    $conn->set_charset("utf8mb4");

    // Tiyaking umiiral ang stock_history table
    $conn->query("
        CREATE TABLE IF NOT EXISTS stock_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            item_id INT NOT NULL,
            item_name VARCHAR(255) NOT NULL,
            category VARCHAR(50) NOT NULL DEFAULT 'Office',
            previous_stock INT NOT NULL DEFAULT 0,
            new_stock INT NOT NULL DEFAULT 0,
            added_qty INT NOT NULL DEFAULT 0,
            updated_by VARCHAR(100) NOT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // Tiyaking umiiral ang notifications table
    $conn->query("
        CREATE TABLE IF NOT EXISTS notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            message TEXT NOT NULL,
            is_read TINYINT(1) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // Tiyaking umiiral ang borrow_requests table
    $conn->query("
        CREATE TABLE IF NOT EXISTS borrow_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            request_group_id VARCHAR(100) NOT NULL,
            requisitioner_name VARCHAR(255) NOT NULL,
            department VARCHAR(100) NOT NULL,
            item_id INT NOT NULL DEFAULT 0,
            item_name VARCHAR(255) NULL,
            quantity INT NOT NULL DEFAULT 1,
            borrow_date DATE NOT NULL,
            expected_return_date DATE NOT NULL,
            scheduled_time VARCHAR(50) NULL DEFAULT '09:00 AM - 10:00 AM',
            purpose TEXT NULL,
            status VARCHAR(50) NOT NULL DEFAULT 'Pending',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    @$conn->query("ALTER TABLE borrow_requests ADD COLUMN item_name VARCHAR(255) NULL");

    // Add scheduled_time column to supply_requests and maintenance_requests if they exist
    @$conn->query("ALTER TABLE supply_requests ADD COLUMN scheduled_time VARCHAR(50) NULL DEFAULT '09:00 AM - 10:00 AM'");
    @$conn->query("ALTER TABLE maintenance_requests ADD COLUMN scheduled_time VARCHAR(50) NULL DEFAULT '09:00 AM - 10:00 AM'");

    // Tiyaking umiiral ang document_printing_requests table
    $conn->query("
        CREATE TABLE IF NOT EXISTS document_printing_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            request_group_id VARCHAR(100) NOT NULL,
            requisitioner_name VARCHAR(255) NOT NULL,
            department VARCHAR(100) NOT NULL,
            document_file VARCHAR(255) NOT NULL,
            paper_size VARCHAR(50) NOT NULL DEFAULT 'A4',
            print_color VARCHAR(50) NOT NULL DEFAULT 'Black & White',
            print_sides VARCHAR(50) NOT NULL DEFAULT 'Single-sided',
            binding_option VARCHAR(50) NOT NULL DEFAULT 'None',
            page_count INT NOT NULL DEFAULT 1,
            copies INT NOT NULL DEFAULT 1,
            total_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            purpose TEXT NULL,
            date_needed DATE NULL,
            scheduled_time VARCHAR(50) NULL DEFAULT '09:00 AM - 10:00 AM',
            status VARCHAR(50) NOT NULL DEFAULT 'Pending',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
}
?>