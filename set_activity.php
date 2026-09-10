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

    if (isset($body['name'])) {
        $quarriedName = $body['name'];
    } else {
        respond(400, ["success" => false, "error" => "Data either missing or invalid."]);
    }

    $quarriedDate = $body["meeting_date"] ?? null;
    $quarriedNote = $body["note"] ?? null;

    $currentDate = date("Y-m-d H:i:s");

    try {
        $stmt = $conn->prepare(
            "INSERT INTO `aktywnosc` (`Nazwa`, `Data Dodania`, `Data Umówienia`, `Notatka`) VALUES (:name, :dataDodania, :dataUmowienia, :note)"
        );
        $stmt->bindValue(':name', $quarriedName, PDO::PARAM_STR);
        $stmt->bindValue(':dataDodania', $currentDate, PDO::PARAM_STR);
        $stmt->bindValue(':dataUmowienia', $quarriedDate, $quarriedDate === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':note', $quarriedNote, $quarriedNote === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->execute();
    } catch (PDOException $e) {
        respond(500, ["success" => false, "error" => 'Insert failed: ' . $e->getMessage()]);
    }
    respond(200, ["success" => true]);
?>