<?php
$credentials = fopen("credentials.txt", "r");
$DB_HOST = trim(fgets($credentials));
$DB_NAME = trim(fgets($credentials));
$DB_USER = trim(fgets($credentials));
$DB_PASS = '';
if (!feof($credentials)) {
    $DB_PASS = trim(fgets($credentials));
}
fclose($credentials);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function respond(int $httpCode, array $data): void {
    http_response_code($httpCode);
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['success' => false, 'error' => 'Only POST is allowed.']);
}


if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
    respond(400, ['success' => false, 'error' => 'No valid file uploaded under field "csv_file".']);
}

$tmpPath = $_FILES['csv_file']['tmp_name'];

$handle = fopen($tmpPath, 'r');
if ($handle === false) {
    respond(400, ['success' => false, 'error' => 'Could not open uploaded file.']);
}

$header = fgetcsv($handle);
if ($header === false) {
    respond(400, ['success' => false, 'error' => 'CSV file appears to be empty.']);
}

$columnConfig = [ //Configure added columns to match the CSV structure
        '___Computer_Name' => "Nazwa",
        'Device_Name' => 'Nazwa Urządzenia',
        'Group_Name' => 'Nazwa Klienta',
        'Operating_System' => 'System Operacyjny',
        'Streamer_Version' => 'Wersja Streamera',
        'IP_Address' => 'Adres IP',
        'Last_Session_Start_Time' => 'Ostatnie Rozpoczęcie Sesji',
        'Last_Session_End_Time' => 'Ostatnia Sesja',
        'Last_Online' => 'Ostatnio Online',
        'Last_Remote_User' => 'Ostatnio zalogowany',
        'LAN_IP_Addresses' => 'Adres IP sieci LAN',
        'Note' => 'Notatka z Splashtopa'
];

$originalcolumns = array_map(function ($col) {
    $col = trim($col);
    $col = preg_replace('/[^A-Za-z0-9_]/', '_', $col);
    if ($col === '' || preg_match('/^[0-9]/', $col)) {
        $col = 'col_' . $col;
    }
    return $col;
}, $header);


$validIndices = [];
foreach ($originalcolumns as $idx => $rawCol) {
    if (array_key_exists($rawCol, $columnConfig)) {
        $validIndices[] = $idx;
    }
}

$columns = array_map(
    fn($idx) => $columnConfig[$originalcolumns[$idx]],
    $validIndices
);

$rows = [];
while (($row = fgetcsv($handle)) !== false) {
    $filteredRow = [];
    foreach ($validIndices as $idx) {
        $filteredRow[] = $row[$idx] ?? null;
    }
    $rows[] = $filteredRow;
}
fclose($handle);
function detectColumnType(array $values): string {
    $nonEmpty = array_filter($values, fn($v) => $v !== null && trim((string)$v) !== '');

    if (count($nonEmpty) === 0) {
        return 'TEXT';
    }

    $allInt = $allDecimal = $allDate = $allDateTime = true;
    $maxLen = 0;

    foreach ($nonEmpty as $v) {
        $v = trim((string)$v);
        $maxLen = max($maxLen, strlen($v));

        if (!preg_match('/^-?\d+$/', $v)) {
            $allInt = false;
        }
        if (!preg_match('/^-?\d+\.\d+$/', $v) && !preg_match('/^-?\d+$/', $v)) {
            $allDecimal = false;
        }
        if (!isValidDate($v, 'Y-m-d')) {
            $allDate = false;
        }
        if (!isValidDate($v, 'Y-m-d H:i:s')) {
            $allDateTime = false;
        }
    }

    if ($allInt) {
        // Use BIGINT if any value exceeds standard INT range
        foreach ($nonEmpty as $v) {
            if (abs((int)$v) > 2147483647) {
                return 'BIGINT';
            }
        }
        return 'INT';
    }
    if ($allDecimal) {
        return 'DECIMAL(18,4)';
    }
    if ($allDate) {
        return 'DATE';
    }
    if ($allDateTime) {
        return 'DATETIME';
    }

    // Fall back to text
    if ($maxLen <= 255) {
        $size = max(50, $maxLen * 2); // headroom for future rows
        return 'VARCHAR(' . min($size, 255) . ')';
    }
    return 'TEXT';
}

function isValidDate(string $value, string $format): bool {
    $d = DateTime::createFromFormat($format, $value);
    return $d !== false && $d->format($format) === $value;
}

$columnTypes = [];
foreach ($columns as $i => $col) {
        $values = array_column($rows, $i);
        $columnTypes[$col] = detectColumnType($values);
}


try {
    $pdo = new PDO(
        "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    respond(500, ['success' => false, 'error' => 'Database connection failed: ' . $e->getMessage()]);
}


$tableName = 'data_' . date('Y-m-d');
$quotedTable = '`' . str_replace('`', '``', $tableName) . '`';

$columnDefs = [];
foreach ($columnTypes as $col => $type) {
    $quotedCol = '`' . str_replace('`', '``', $col) . '`';
    $columnDefs[] = "$quotedCol $type";
    
}

$createSql = "CREATE TABLE $quotedTable (\n"
    . "    id INT AUTO_INCREMENT PRIMARY KEY,\n"
    . "    " . implode(",\n    ", $columnDefs) . "\n"
    . ")";

try {
    $pdo->exec("DROP TABLE IF EXISTS $quotedTable");
    $pdo->exec($createSql);
} catch (PDOException $e) {
    respond(500, ['success' => false, 'createsql' => $createSql, 'error' => 'Table creation failed: ' . $e->getMessage()]);
}

$placeholders = implode(', ', array_fill(0, count($columns), '?'));
$quotedCols = implode(', ', array_map(fn($c) => '`' . str_replace('`', '``', $c) . '`', $columns));
$insertSql = "INSERT INTO $quotedTable ($quotedCols) VALUES ($placeholders)";
$stmt = $pdo->prepare($insertSql);


try {
    $pdo->beginTransaction();
    foreach ($rows as $row) {
        $bound = array_map(function ($v, $col) use ($columnTypes) {
            $v = $v === null ? null : trim((string)$v);
            if ($v === '' && $columnTypes[$col] !== 'TEXT' && strpos($columnTypes[$col], 'VARCHAR') === false) {
                return null;
            }
            return $v;
        }, $row, $columns);
        $stmt->execute($bound);
    }
    $pdo->commit();
} catch (PDOException $e) {
    $pdo->rollBack();
    respond(500, ['success' => false, 'bound' => $bound, 'error' => 'Row insert failed: ' . $e->getMessage()]);
}


respond(200, [
    'success'   => true,
]);
