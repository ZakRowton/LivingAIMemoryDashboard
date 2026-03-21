<?php
// etl_payroll_tool.php
// Connect to Azure SQL using PDO and execute query.

function run($args) {
    $query = $args['query'];
    // Get connection details from environment variables
    $host = getenv('ETL_PAYROLL_HOST');
    $db = getenv('ETL_PAYROLL_DATABASE');
    $user = getenv('ETL_PAYROLL_USER');
    $pass = getenv('ETL_PAYROLL_PASSWORD');
    if (!$host || !$db || !$user) {
        return ['error' => 'Missing DB connection environment variables'];
    }
    // Build DSN for sqlsrv driver
    $dsn = "sqlsrv:Server=$host;Database=$db";
    try {
        $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } catch (PDOException $e) {
        return ['error' => 'Connection failed: ' . $e->getMessage()];
    }
    try {
        $stmt = $pdo->query($query);
        $cols = [];
        for ($i = 0; $i < $stmt->columnCount(); $i++) {
            $meta = $stmt->getColumnMeta($i);
            $cols[] = $meta['name'];
        }
        $rows = [];
        while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
            // Cast all values to strings
            $strRow = array_map('strval', $row);
            $rows[] = $strRow;
        }
        return ['headers' => $cols, 'rows' => $rows];
    } catch (PDOException $e) {
        return ['error' => 'Query failed: ' . $e->getMessage()];
    }
}
?>