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

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(405, ['success' => false, 'error' => 'Only POST is allowed.']);
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
    $body = json_decode(file_get_contents('php://input'), true);
    if (isset($body['ids'])) {
        $quarriedIds = $body['ids'];
    } else {
        respond(400, ["success" => false, "error" => "Data either missing or invalid."]);
    }

    //the amount of days where the alert will show beforehand. Change it to change how soon/late the alert shows.
    $cutoff = new DateTime();
    $cutoff->setTime(0, 0, 0);
    $cutoff->modify("+2 days");
    $sqlTime = $cutoff->format("Y-m-d H:i:s");

    $quarriedIds = array_values(array_filter($quarriedIds, 'is_numeric'));

    $excludeClause = "";
    if (count($quarriedIds) > 0) {
        $placeholders = implode(", ", array_fill(0, count($quarriedIds), "?"));
        $excludeClause = "AND id NOT IN ($placeholders)";
    }

    try {
        $stmt = $conn->prepare(
            "SELECT * FROM `aktywnosc` WHERE `Data Umówiona` < ? AND `Odznaczone` = 0 $excludeClause"
        );
        $stmt->execute(array_merge([$sqlTime], $quarriedIds));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        respond(500, ["success" => false, "error" => 'Insert failed: ' . $e->getMessage()]);
    }
    
    respond(200, ["success" => true, "result"=> $rows]);
?>