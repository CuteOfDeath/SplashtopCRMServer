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

        if (isset($body['table']) && isset($body['id'])) {
            $quarriedTable = $body['table'];
            $quarriedRecord = $body['id'];
        } else {
            respond(400, ["success" => false, "error" => "Data either missing or invalid."]);
        }
    //vomit
    try{
        $stmt = $conn->prepare("SELECT 
        id AS 'ID',
        ___Computer_Name AS 'Nazwa', 
        Device_Name AS 'Nazwa Urządzenia',
        Group_Name AS 'Nazwa Klienta', 
        Operating_System AS 'System Operacyjny',
        Streamer_Version AS 'Wersja Streamera',
        IP_Address AS 'Adres IP',
        Last_Session_End_Time AS 'Ostatnia Sesja', 
        Last_Online AS 'Ostatnio Online',
        Last_Remote_User AS 'Ostatnio Zalogowany',
        LAN_IP_Addresses AS 'Adres IP LAN',
        Note AS 'Notatka' 
        FROM `$quarriedTable` WHERE id = :id");
        $stmt->bindParam(':id', $quarriedRecord, PDO::PARAM_INT);
        $stmt->execute();
        
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }catch(PDOException $e) {
        respond(500, ["success"=> false, "error" => 'Fetch failed: ' . $e->getMessage()]);
    }
    respond(200, ["success" => true, "result" => $rows]);
?>