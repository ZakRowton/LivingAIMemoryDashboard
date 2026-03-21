<?php
/**
 * Azure SQL / SQL Server connection for ETL tools.
 *
 * Primary: PDO sqlsrv — same client stack as .NET SqlConnection (no ODBC DSN in code).
 * Fallback: PDO ODBC + Driver 17/18 if pdo_sqlsrv is not installed.
 *
 * .env: ETL_PAYROLL_HOST, ETL_PAYROLL_DATABASE, ETL_PAYROLL_USER, ETL_PAYROLL_PASSWORD
 * Optional: ETL_PAYROLL_PORT (default 1433)
 */
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'env.php';

if (!function_exists('etl_sql_connect')) {
    function etl_sql_connect(?string $databaseOverride = null): array {
        $host = trim((string) memory_graph_env('ETL_PAYROLL_HOST', ''));
        $database = trim((string) ($databaseOverride !== null ? $databaseOverride : memory_graph_env('ETL_PAYROLL_DATABASE', 'etl_Payroll')));
        $user = (string) memory_graph_env('ETL_PAYROLL_USER', '');
        $password = (string) memory_graph_env('ETL_PAYROLL_PASSWORD', '');
        $port = (int) memory_graph_env('ETL_PAYROLL_PORT', '1433');
        if ($port <= 0) {
            $port = 1433;
        }

        if ($host === '' || $database === '' || $user === '' || $password === '') {
            return [
                'error' => 'Missing ETL_PAYROLL_* in .env (HOST, DATABASE, USER, PASSWORD).',
            ];
        }

        $server = $host . ',' . $port;
        $opts = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];

        $lastErr = null;

        if (extension_loaded('pdo_sqlsrv')) {
            $dsn = 'sqlsrv:Server=' . $server . ';Database=' . $database . ';Encrypt=yes;TrustServerCertificate=no';
            try {
                $pdo = new PDO($dsn, $user, $password, $opts);
                return [
                    'pdo' => $pdo,
                    'driver' => 'pdo_sqlsrv',
                    'server' => $server,
                    'database' => $database,
                ];
            } catch (PDOException $ex) {
                $lastErr = $ex->getMessage();
            }
        }

        if (extension_loaded('pdo_odbc')) {
            $odbcDrivers = [
                'ODBC Driver 18 for SQL Server',
                'ODBC Driver 17 for SQL Server',
                'SQL Server',
            ];
            foreach ($odbcDrivers as $drv) {
                $dsn = 'odbc:Driver={' . $drv . '};Server=tcp:' . $server . ';Database=' . $database . ';Encrypt=yes;TrustServerCertificate=no';
                try {
                    $pdo = new PDO($dsn, $user, $password, $opts);
                    return [
                        'pdo' => $pdo,
                        'driver' => 'pdo_odbc:' . $drv,
                        'server' => $server,
                        'database' => $database,
                    ];
                } catch (PDOException $ex) {
                    $lastErr = $ex->getMessage();
                }
            }
        }

        $hint = 'Install Microsoft Drivers for PHP for SQL Server (pdo_sqlsrv) for the same path as C# SqlConnection, '
            . 'or enable pdo_odbc + ODBC Driver 17/18. See: https://learn.microsoft.com/sql/connect/php/';

        return [
            'error' => 'Could not connect to Azure SQL.',
            'detail' => $lastErr,
            'hint' => $hint,
        ];
    }
}

if (!function_exists('etl_odbc_connect')) {
    function etl_odbc_connect(?string $databaseOverride = null): array {
        return etl_sql_connect($databaseOverride);
    }
}
