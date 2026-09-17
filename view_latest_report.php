<?php

    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST');
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
        $conn->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
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
        respond(404, ['success' => false, 'error' => 'No data_ tables found.']);
    }

    try {
        $stmt = $conn->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
             ORDER BY ORDINAL_POSITION"
        );
        $stmt->execute([$latestTable]);
        $reportColumns = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'COLUMN_NAME');
    } catch (PDOException $e) {
        respond(500, ['success' => false, 'error' => 'Could not read report columns: ' . $e->getMessage()]);
    }

    $reportSelectColumns = [];
    foreach ($reportColumns as $col) {
        if ($col === 'Ostatnia Sesja') {
            continue; // handled below via COALESCE
        }
        $reportSelectColumns[] = "r.`$col`";
    }
    $reportSelectColumns[] = in_array('Ostatnia Sesja', $reportColumns, true)
        ? "COALESCE(a_latest.`Ostatnia Sesja`, r.`Ostatnia Sesja`) AS `Ostatnia Sesja`"
        : "a_latest.`Ostatnia Sesja` AS `Ostatnia Sesja`";
    $reportSelectSql = implode(', ', $reportSelectColumns);

    $sql = "SELECT COUNT(*) AS 'count'
        FROM `$latestTable`";

    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $count = $data[0]["count"];
    } catch (PDOException $e) {
        respond(500, ['success' => false, 'error' => $e->getMessage()]);
    }

    try{
        $stmt = $conn->query("SELECT $reportSelectSql, a.`Data Umówiona`, a.`Notatka`, a.`Użytkownik`
        FROM `$latestTable` r
        LEFT JOIN aktywnosc a
            ON a.Nazwa = r.Nazwa
            AND a.Odznaczone = 0
            AND a.`Data Dodania` = (
                SELECT MAX(a2.`Data Dodania`)
                FROM aktywnosc a2
                WHERE a2.Nazwa = a.Nazwa AND a2.Odznaczone = 0
            )
        LEFT JOIN aktywnosc a_latest
            ON a_latest.Nazwa = r.Nazwa
            AND a_latest.`Data Dodania` = (
                SELECT MAX(a2.`Data Dodania`)
                FROM aktywnosc a2
                WHERE a2.Nazwa = r.Nazwa AND a2.`Ostatnia Sesja` IS NOT NULL
            )
        ORDER BY r.id LIMIT 50");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }catch(PDOException $e) {
        respond(500, ["success"=> false, "error" => 'Fetch failed: ' . $e->getMessage()]);
    }

    respond(200, ["success" => true, "table" => $latestTable, "result" => $rows, "count" => $count]);
?>
