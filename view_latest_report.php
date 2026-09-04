<?php

    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Content-Type: application/json');

    function respond(int $httpCode, array $data): void {
        http_response_code($httpCode);
        echo json_encode($data);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        respond(405, ['success' => false, 'error' => 'Only GET is allowed.']);
    }

    $credentials = fopen(__DIR__ . "/credentials.txt", "r");
    $DB_HOST = trim(fgets($credentials));
    $DB_NAME = trim(fgets($credentials));
    $DB_USER = trim(fgets($credentials));
    $DB_PASS = '';

    if (!feof($credentials)) {
        $DB_PASS = trim(fgets($credentials));
    }
    fclose($credentials);

    try {
        $conn = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME", $DB_USER, $DB_PASS);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch (PDOException $e) {
        respond(500, ['success' => false, 'error' => 'Database connection failed: ' . $e->getMessage()]);
    }

    $sql = "SHOW TABLES";
    $result = $conn->query($sql);

    $latestTable = null;
    $latestTimestamp = null;

    foreach ($result as $row) {
        $tableName = $row[0];

        if (strpos($tableName, 'data_') !== 0) {
            continue;
        }

        $recordDate = strtotime(str_replace("data_", "", $tableName));
        if ($recordDate === false) {
            continue;
        }

        if ($latestTimestamp === null || $recordDate > $latestTimestamp) {
            $latestTimestamp = $recordDate;
            $latestTable = $tableName;
        }
    }

    if ($latestTable === null) {
        respond(404, ['success' => false, 'error' => 'No date_ tables found.']);
    }


    $stmt = $conn->query("SELECT ___Computer_Name AS 'Nazwa', Group_Name AS 'Nazwa Klienta', Last_Session_End_Time AS 'Ostatnia Sesja', Last_Online AS 'Ostatnio Online' FROM `$latestTable`");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    respond(200, ["success" => true, "table" => $latestTable, "result" => $rows]);
?>
