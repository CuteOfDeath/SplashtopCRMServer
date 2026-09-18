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
    //get credentials from the internal txt file
    $credentials = fopen(__DIR__ . "/credentials.txt", "r");
    $DB_HOST = trim(fgets($credentials));
    $DB_NAME = trim(fgets($credentials));
    $DB_USER = trim(fgets($credentials));
    $DB_PASS = '';

    if (!feof($credentials)) {
        $DB_PASS = trim(fgets($credentials));
    }
    fclose($credentials);


    //connect to db
    try {
        $conn = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME", $DB_USER, $DB_PASS);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $conn->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    } catch (PDOException $e) {
        respond(500, ['success' => false, 'error' => 'Database connection failed: ' . $e->getMessage()]);
    }

    //make sure that a malicious user can't query for other tables than the ones specified (Probably won't need this.)
    $sql = "SHOW TABLES";
    $result = $conn->query($sql);
    $allowedTables = [];
    foreach ($result as $row) {
        $tableName = $row[0];

        if (strpos($tableName, 'data_') == 0) {
            array_push($allowedTables,$tableName);
        }
    }

    //Loading data from the frontend fetch
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

    if (!in_array($quarriedTable, $allowedTables, true)) {
        respond(400, ["success" => false, "error" => "Unknown table."]);
    }

    //get all columns for later
    try {
        $stmt = $conn->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
             ORDER BY ORDINAL_POSITION"
        );
        $stmt->execute([$quarriedTable]);
        $reportColumns = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'COLUMN_NAME');
    } catch (PDOException $e) {
        respond(500, ["success" => false, "error" => "Could not read report columns: " . $e->getMessage()]);
    }

    //change the "Ostatnia Sesja" column to coelesce so it picks the non-null value from the two tables
    $hasOwnSession = in_array('Ostatnia Sesja', $reportColumns, true);
    $ostatniaSesjaExpr = $hasOwnSession
        ? "COALESCE(a_latest.`Ostatnia Sesja`, r.`Ostatnia Sesja`)"
        : "a_latest.`Ostatnia Sesja`";

    //assign columns their respective alias
    function resolveColumnExpr(string $internalName, string $ostatniaSesjaExpr): string {
        if ($internalName === 'Ostatnia Sesja') {
            return $ostatniaSesjaExpr;
        }
        if ($internalName === 'Data Umówiona' || $internalName === 'Notatka') {
            return "a.`$internalName`";
        }
        return "r.`$internalName`";
    }
    //finally, build the SELECT part of the sql query
    $reportSelectColumns = [];
    foreach ($reportColumns as $col) {
        if ($col === 'Ostatnia Sesja') {
            continue; 
        }
        $reportSelectColumns[] = "r.`$col`";
    }
    $reportSelectColumns[] = "$ostatniaSesjaExpr AS `Ostatnia Sesja`";
    $reportSelectSql = implode(', ', $reportSelectColumns);

    $filteredColumns = []; 
    foreach ($body['columns'] as $friendlyName => $filterValue) {
        $filteredColumns[$friendlyName] = $filterValue;
    }

    // get column type so we can filter through them correctly later
    $columnTypes = []; 
    if (!empty($filteredColumns)) {
        try {
            $placeholders = implode(',', array_fill(0, count($filteredColumns), '?'));
            $stmt = $conn->prepare(
                "SELECT COLUMN_NAME, DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                 AND TABLE_NAME = ? OR
                 TABLE_NAME = 'aktywnosc'
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

    //create a array of conditions to then use to build a sql query
    $whereParts = [];
    $params = [];
    foreach ($filteredColumns as $internalName => $filterValue) {
        if ($filterValue === '' || $filterValue === null) {
            continue;
        }
        $columnExpr = resolveColumnExpr($internalName, $ostatniaSesjaExpr);
        $type = $columnTypes[$internalName] ?? null;
        if (in_array($type, ['date', 'datetime', 'timestamp'], true)) {
            $whereParts[] = "$columnExpr >= ?";
            $params[] = partialInputToDate((string) $filterValue);
        } else {
            $whereParts[] = "$columnExpr LIKE ?";
            $params[] = '%' . $filterValue . '%';
        }
    }

    // Load the allow_null option.
    // Defaults to false when not provided, so rows with any null are filtered
    // out unless the frontend explicitly opts in with allow_null: true.
    $allowNull = isset($body['allow_null'])
        ? filter_var($body['allow_null'], FILTER_VALIDATE_BOOLEAN)
        : false;

    if (!$allowNull) {
        foreach (array_keys($filteredColumns) as $internalName) {
            $whereParts[] = resolveColumnExpr($internalName, $ostatniaSesjaExpr) . " IS NOT NULL";
        }
    }


    //Actully building the WHERE query now
    $whereSql = $whereParts ? ('WHERE ' . implode(' AND ', $whereParts)) : '';

    //descide load direction
    $sortDirection = $sort ? 'ASC' : 'DESC';

    //Build a sql query to ORDER BY specific columns.
    //Defaults to ordering by ID if omitted.
    $sortColumnsInternal = isset($body['orderby'])
        ? $body['orderby']
        : [];
    if (empty($sortColumnsInternal)) {
        $sortColumnsInternal = ['id'];
    }

    $orderBySql = [];
    foreach ($sortColumnsInternal as $column) {
        $orderBySql[] = resolveColumnExpr($column, $ostatniaSesjaExpr) . " $sortDirection";
    }
    $orderBySql = implode(", ", $orderBySql);


    $offset = max(0, (int) ($range[0] ?? 0));
    $limitCount = max(0, (int) ($range[1] ?? 0) - $offset);

    //Get count of all records returned by the query for the frontend to use
    $sql = "SELECT COUNT(*) AS 'count', a.`Data Umówiona`, a.`Notatka`, a.`Użytkownik`
            FROM `$quarriedTable` r

            LEFT JOIN aktywnosc a
                ON a.Nazwa = r.Nazwa
                AND a.Odznaczone = 0
                AND a.`Data Dodania` = (
                    SELECT MAX(a2.`Data Dodania`)
                    FROM aktywnosc a2
                    WHERE a2.Nazwa = a.Nazwa
                      AND a2.Odznaczone = 0
                )

            LEFT JOIN aktywnosc a_latest
                ON a_latest.Nazwa = r.Nazwa
                AND a_latest.`Data Dodania` = (
                    SELECT MAX(a2.`Data Dodania`)
                    FROM aktywnosc a2
                    WHERE a2.Nazwa = r.Nazwa AND a2.`Ostatnia Sesja` IS NOT NULL
                ) $whereSql ORDER BY $orderBySql";

    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $count = $data[0]["count"];
    } catch (PDOException $e) {
        respond(500, ['success' => false, 'error' => $e->getMessage()]);
    }

    //Finally query data from the database and return it to the frontend
    $sql = "SELECT $reportSelectSql, a.`Data Umówiona`, a.`Notatka`, a.`Użytkownik`
            FROM `$quarriedTable` r

            LEFT JOIN aktywnosc a
                ON a.Nazwa = r.Nazwa
                AND a.Odznaczone = 0
                AND a.`Data Dodania` = (
                    SELECT MAX(a2.`Data Dodania`)
                    FROM aktywnosc a2
                    WHERE a2.Nazwa = a.Nazwa
                      AND a2.Odznaczone = 0
                )

            LEFT JOIN aktywnosc a_latest
                ON a_latest.Nazwa = r.Nazwa
                AND a_latest.`Data Dodania` = (
                    SELECT MAX(a2.`Data Dodania`)
                    FROM aktywnosc a2
                    WHERE a2.Nazwa = r.Nazwa AND a2.`Ostatnia Sesja` IS NOT NULL
                ) $whereSql ORDER BY $orderBySql LIMIT $limitCount OFFSET $offset";

    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        respond(200, ['success' => true, 'result' => $data, 'count' => $count]);
    } catch (PDOException $e) {
        respond(500, ['success' => false, 'error' => $e->getMessage()]);
    }
