<?php
// Siguraduhing ma-start ang session kung wala pa
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$host = "localhost";
$user = "root";
$pass = "";
$dbname = "sibtech_inventory";

class SQLiteDBResult {
    private $rows;
    private $index = 0;
    public $num_rows = 0;

    public function __construct($rows) {
        $this->rows = is_array($rows) ? array_values($rows) : [];
        $this->num_rows = count($this->rows);
    }

    public function fetch_assoc() {
        if ($this->index < $this->num_rows) {
            return $this->rows[$this->index++];
        }
        return null;
    }

    public function fetch_all($mode = MYSQLI_ASSOC) {
        return $this->rows;
    }

    public function fetch_array() {
        $row = $this->fetch_assoc();
        if (!$row) return null;
        return array_values($row);
    }
}

class SQLiteDBStmt {
    private $pdo;
    private $sql;
    private $params = [];
    public $affected_rows = 0;

    public function __construct($pdo, $sql) {
        $this->pdo = $pdo;
        $this->sql = $sql;
    }

    public function bind_param($types, ...$vars) {
        $this->params = $vars;
    }

    public function execute() {
        $sql = $this->sql;
        $sql = preg_replace('/NOW\(\)/i', "DATETIME('now', 'localtime')", $sql);
        $sql = preg_replace('/CURDATE\(\)/i', "DATE('now', 'localtime')", $sql);
        $sql = preg_replace('/CURRENT_DATE\(\)/i', "DATE('now', 'localtime')", $sql);

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->params);
        $this->affected_rows = $stmt->rowCount();

        if (preg_match('/^\s*(SELECT|PRAGMA|SHOW|EXPLAIN)/i', $sql)) {
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $this->last_result = new SQLiteDBResult($rows);
        } else {
            $this->last_result = new SQLiteDBResult([]);
        }
        return true;
    }

    private $last_result;

    public function get_result() {
        return $this->last_result;
    }
}

class SQLiteDBConn {
    public $pdo;
    public $connect_error = null;
    public $insert_id = 0;

    public function __construct($file = 'sibtech.sqlite') {
        try {
            $this->pdo = new PDO("sqlite:" . $file);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->exec("PRAGMA foreign_keys = ON;");
        } catch (Exception $e) {
            $this->connect_error = $e->getMessage();
        }
    }

    public function set_charset($cs) {}

    public function real_escape_string($str) {
        return str_replace("'", "''", $str);
    }

    public function query($sql) {
        $sql_converted = $sql;
        $sql_converted = preg_replace('/INT AUTO_INCREMENT PRIMARY KEY/i', 'INTEGER PRIMARY KEY AUTOINCREMENT', $sql_converted);
        $sql_converted = preg_replace('/ENGINE=InnoDB/i', '', $sql_converted);
        $sql_converted = preg_replace('/DEFAULT CHARSET=\w+/i', '', $sql_converted);
        $sql_converted = preg_replace('/TINYINT\(1\)/i', 'INTEGER', $sql_converted);
        $sql_converted = preg_replace('/NOW\(\)/i', "DATETIME('now', 'localtime')", $sql_converted);
        $sql_converted = preg_replace('/CURDATE\(\)/i', "DATE('now', 'localtime')", $sql_converted);
        $sql_converted = preg_replace('/CURRENT_DATE\(\)/i', "DATE('now', 'localtime')", $sql_converted);
        $sql_converted = preg_replace('/DATE_SUB\(NOW\(\),\s*INTERVAL\s*7\s*DAY\)/i', "DATETIME('now', '-7 days')", $sql_converted);

        try {
            if (preg_match('/^\s*(SELECT|PRAGMA|SHOW|EXPLAIN)/i', $sql_converted)) {
                $stmt = $this->pdo->query($sql_converted);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                return new SQLiteDBResult($rows);
            } else {
                $this->pdo->exec($sql_converted);
                $this->insert_id = $this->pdo->lastInsertId();
                return true;
            }
        } catch (Exception $e) {
            return false;
        }
    }

    public function prepare($sql) {
        return new SQLiteDBStmt($this->pdo, $sql);
    }

    public function begin_transaction() {
        return $this->pdo->beginTransaction();
    }

    public function commit() {
        return $this->pdo->commit();
    }

    public function rollback() {
        return $this->pdo->rollBack();
    }
}

mysqli_report(MYSQLI_REPORT_OFF);
$conn = @new mysqli($host, $user, $pass, $dbname);

if ($conn->connect_error) {
    $conn = new SQLiteDBConn('sibtech.sqlite');
} else {
    $conn->set_charset("utf8mb4");
}

// Auto create tables if not exists
$conn->query("
    CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        fullname VARCHAR(255) NOT NULL,
        username VARCHAR(100) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        role VARCHAR(50) NOT NULL DEFAULT 'user',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$conn->query("
    CREATE TABLE IF NOT EXISTS items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        item_name VARCHAR(255) NOT NULL,
        unit VARCHAR(50) NOT NULL,
        actual_stocks INT NOT NULL DEFAULT 0,
        image VARCHAR(255) DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$conn->query("
    CREATE TABLE IF NOT EXISTS maintenance_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        item_name VARCHAR(255) NOT NULL,
        unit VARCHAR(50) NOT NULL,
        actual_stocks INT NOT NULL DEFAULT 0,
        image VARCHAR(255) DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$conn->query("
    CREATE TABLE IF NOT EXISTS supply_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        request_group_id VARCHAR(100) NOT NULL,
        user_id INT NOT NULL,
        requisitioner_name VARCHAR(255) DEFAULT '',
        department VARCHAR(100) NOT NULL,
        item_id INT NOT NULL,
        quantity INT NOT NULL,
        purpose TEXT NOT NULL,
        date_needed DATE DEFAULT NULL,
        room_reserved VARCHAR(255) DEFAULT '',
        status VARCHAR(50) NOT NULL DEFAULT 'Pending',
        request_date DATETIME DEFAULT CURRENT_TIMESTAMP,
        approved_at DATETIME DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$conn->query("
    CREATE TABLE IF NOT EXISTS maintenance_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        request_group_id VARCHAR(100) NOT NULL,
        user_id INT NOT NULL,
        requisitioner_name VARCHAR(255) DEFAULT '',
        department VARCHAR(100) NOT NULL,
        item_id INT NOT NULL,
        quantity INT NOT NULL,
        purpose TEXT NOT NULL,
        date_needed DATE DEFAULT NULL,
        room_reserved VARCHAR(255) DEFAULT '',
        status VARCHAR(50) NOT NULL DEFAULT 'Pending',
        request_date DATETIME DEFAULT CURRENT_TIMESTAMP,
        approved_at DATETIME DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

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

$conn->query("
    CREATE TABLE IF NOT EXISTS notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        message TEXT NOT NULL,
        is_read TINYINT(1) DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$conn->query("
    CREATE TABLE IF NOT EXISTS calendar_schedules (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        requisitioner_name VARCHAR(255) NOT NULL,
        department VARCHAR(100) NOT NULL,
        purpose TEXT NOT NULL,
        room_reserved VARCHAR(255) NOT NULL,
        date_needed DATE NOT NULL,
        status VARCHAR(50) NOT NULL DEFAULT 'Approved',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// Check if room_reserved column exists
@$conn->query("ALTER TABLE supply_requests ADD COLUMN room_reserved VARCHAR(255) DEFAULT ''");
@$conn->query("ALTER TABLE maintenance_requests ADD COLUMN room_reserved VARCHAR(255) DEFAULT ''");
@$conn->query("ALTER TABLE calendar_schedules ADD COLUMN room_reserved VARCHAR(255) DEFAULT ''");

// Ensure default user and admin exist
$chk_user = $conn->query("SELECT id FROM users LIMIT 1");
if (!$chk_user || $chk_user->num_rows == 0) {
    $admin_pass = password_hash('admin123', PASSWORD_BCRYPT);
    $user_pass = password_hash('user123', PASSWORD_BCRYPT);
    $conn->query("INSERT INTO users (fullname, username, password, role) VALUES ('System Admin', 'admin', '{$admin_pass}', 'admin')");
    $conn->query("INSERT INTO users (fullname, username, password, role) VALUES ('Juan Dela Cruz', 'user', '{$user_pass}', 'user')");
}

// Ensure default items exist if empty
$chk_item = $conn->query("SELECT id FROM items LIMIT 1");
if (!$chk_item || $chk_item->num_rows == 0) {
    $conn->query("INSERT INTO items (item_name, unit, actual_stocks) VALUES ('Bond Paper A4', 'ream', 50)");
    $conn->query("INSERT INTO items (item_name, unit, actual_stocks) VALUES ('Ballpen Blue', 'box', 20)");
    $conn->query("INSERT INTO items (item_name, unit, actual_stocks) VALUES ('Stapler #35', 'pcs', 15)");
}

$chk_mitem = $conn->query("SELECT id FROM maintenance_items LIMIT 1");
if (!$chk_mitem || $chk_mitem->num_rows == 0) {
    $conn->query("INSERT INTO maintenance_items (item_name, unit, actual_stocks) VALUES ('LED Bulb 12W', 'pcs', 30)");
    $conn->query("INSERT INTO maintenance_items (item_name, unit, actual_stocks) VALUES ('Extension Wire 5m', 'pcs', 10)");
}
?>