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
        // Use real (native) prepared statements rather than PDO's emulated ones.
        // Not strictly required for what's below, but it's a good default for
        // anything touching user input.
        $conn->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    } catch (PDOException $e) {
        respond(500, ['success' => false, 'error' => 'Database connection failed: ' . $e->getMessage()]);
    }

    // Change from frontend definitions to ones internally in the database
    $columnDefs = [
        'ID'=> 'id',
        'Nazwa'=> '___Computer_Name',
        'Nazwa Urządzenia' => 'Device_Name',
        'Nazwa Klienta'=> 'Group_Name',
        'System Operacyjny' => 'Operating_System',
        'Wersja Streamera' => 'Streamer_Version',
        'Adres IP' => 'IP_Address',
        'Ostatnia Sesja' => 'Last_Session_End_Time',
        'Ostatnio Online' => 'Last_Online',
        'Ostatnio Zalogowany' => 'Last_Remote_User',
        'Adres IP LAN' => 'LAN_IP_Addresses',
        'Notatka' => 'Note'
    ];

    // Only these tables may ever be queried through this endpoint.
    // TODO: put your real table name(s) here.

    $allowedTables = ['data_2026-09-04'];

    $body = json_decode(file_get_contents('php://input'), true);
    //table is a string with the quarried table
    //columns is an array whose keys are the columns affected by the filters, and values are the applied filters.
    //sort is a bool. false is sorting descending, true is ascending
    //range is a array containing 2 elements, the min and max, dictating from where the query should start from and where to end.
    if (isset($body['table']) && isset($body['columns']) && isset($body['sort']) && isset($body['range'])) {
        $quarriedTable = $body['table'];
        $sort = $body['sort'];
        $range = $body['range'];
    } else {
        respond(400, ["success" => false, "error" => "Data either missing or invalid."]);
    }

    // --- Table name: never trust this directly, only allow known tables ---
    if (!in_array($quarriedTable, $allowedTables, true)) {
        respond(400, ["success" => false, "error" => "Unknown table."]);
    }

    // --- Columns: only accept keys we actually recognise from $columnDefs.
    // Anything not in that map is dropped rather than passed to SQL, so a
    // malicious/unexpected key in the request body just gets ignored.
    $filteredColumns = []; // internal_name => raw filter value from the client
    foreach ($body['columns'] as $friendlyName => $filterValue) {
        if (!array_key_exists($friendlyName, $columnDefs)) {
            continue;
        }
        $filteredColumns[$columnDefs[$friendlyName]] = $filterValue;
    }

    // --- Find out which of the requested columns are date/datetime typed ---
    $columnTypes = []; // internal_name => data_type
    if (!empty($filteredColumns)) {
        try {
            $placeholders = implode(',', array_fill(0, count($filteredColumns), '?'));
            $stmt = $conn->prepare(
                "SELECT COLUMN_NAME, DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                 AND TABLE_NAME = ?
                 AND COLUMN_NAME IN ($placeholders)"
            );
            $stmt->execute(array_merge([$quarriedTable], array_keys($filteredColumns)));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $columnTypes[$row['COLUMN_NAME']] = strtolower($row['DATA_TYPE']);
            }
        } catch (PDOException $e) {
            respond(500, ["success" => false, "error" => "Could not read column metadata: " . $e->getMessage()]);
        }
    }

    /**
     * Turns a partial numeric filter (e.g. "2", "202", "2023-5") into a
     * concrete boundary date to compare against.
     *
     * Year: the digits given are treated as the *start* of the year, so they
     * are right-padded with zeros up to 4 digits.
     *   "2"    -> "2000"
     *   "202"  -> "2020"
     *   "2023" -> "2023"
     *
     * Month/day: treated as normal numbers, left-padded to 2 digits, and
     * default to "01" (the very start of the month/year) when omitted.
     */
    function partialInputToDate(string $input): string {
        $parts = preg_split('/[-\/.\s]+/', trim($input));

        $yearDigits = preg_replace('/\D/', '', $parts[0] ?? '');
        if ($yearDigits === '') {
            $yearDigits = '0';
        }
        $year = (int) str_pad(substr($yearDigits, 0, 4), 4, '0', STR_PAD_RIGHT);

        $month = isset($parts[1]) ? (int) preg_replace('/\D/', '', $parts[1]) : 1;
        $day   = isset($parts[2]) ? (int) preg_replace('/\D/', '', $parts[2]) : 1;
        $month = min(max($month, 1), 12);
        $day   = min(max($day, 1), 31);

        return sprintf('%04d-%02d-%02d 00:00:00', $year, $month, $day);
    }

    // --- Build the WHERE clause. Identifiers (column names) only ever come
    // from $columnDefs above, never from the raw request; values always go
    // in through bound parameters. ---
    $whereParts = [];
    $params = [];
    foreach ($filteredColumns as $internalName => $filterValue) {
        if ($filterValue === '' || $filterValue === null) {
            continue;
        }
        $type = $columnTypes[$internalName] ?? null;
        if (in_array($type, ['date', 'datetime', 'timestamp'], true)) {
            $whereParts[] = "`$internalName` >= ?";
            $params[] = partialInputToDate((string) $filterValue);
        } else {
            $whereParts[] = "`$internalName` LIKE ?";
            $params[] = '%' . $filterValue . '%';
        }
    }
    $whereSql = $whereParts ? ('WHERE ' . implode(' AND ', $whereParts)) : '';

    // --- Sort column. Rather than requiring the frontend to say what to sort
    // by, sort by whichever column(s) are actually being filtered on. If more
    // than one filter is active, chain them in the order they were given
    // (all in the same direction) so the primary filter still dominates the
    // order. Falls back to ID when nothing is being filtered. ---
    $sortDirection = $sort ? 'ASC' : 'DESC';
    $sortColumnsInternal = array_keys($filteredColumns);
    if (empty($sortColumnsInternal)) {
        $sortColumnsInternal = [$columnDefs['ID']];
    }
    $orderBySql = implode(', ', array_map(
        fn($col) => "`$col` $sortDirection",
        $sortColumnsInternal
    ));

    // --- Range -> LIMIT / OFFSET. Cast to int so these can never carry SQL. ---
    $offset = max(0, (int) ($range[0] ?? 0));
    $limitCount = max(0, (int) ($range[1] ?? 0) - $offset);

    $sql = "SELECT id AS 'ID',
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
        FROM `$quarriedTable` $whereSql ORDER BY $orderBySql LIMIT $limitCount OFFSET $offset";

    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        respond(200, ['success' => true, 'result' => $data, 'wheresql' => $whereSql, 'orderbysql' => $orderBySql, 'offset'=> $offset,'limit'=> $limitCount, 'body' => $body]);
    } catch (PDOException $e) {
        respond(500, ['success' => false, 'error' => $e->getMessage()]);
    }
