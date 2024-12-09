<?php

namespace Dcblogdev\DbSync\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DbSyncCommand extends Command
{
    protected $signature   = 'db:production-sync {--T|test} {--F|filename=}';
    protected $description = 'Sync production database with local';

    public function handle(): bool
    {
        $inTest = $this->option('test');
        $mysqlComatibile = $this->option('mysql-comatibile');

        if (! in_array(config('app.env'), config('dbsync.environments'))) {
            $this->error('DB sync will only run on local and staging environments');

            return true;
        }

        $useSsh      = config('dbsync.useSsh');
        $sshUsername = config('dbsync.sshUsername');
        $sshPort     = config('dbsync.sshPort');
        $host        = config('dbsync.host');

        $mysqlHostName         = config('dbsync.mysqlHostName');
        $username              = config('dbsync.username');
        $database              = config('dbsync.database');
        $port                  = config('dbsync.port');
        $password              = config('dbsync.password');
        $ignore                = config('dbsync.ignore');
        $ignoreTables          = explode(',', $ignore);
        $importSqlFile         = config('dbsync.importSqlFile');
        $removeFileAfterImport = config('dbsync.removeFileAfterImport');
        $fileName              = $this->option('filename') ?? config('dbsync.defaultFileName');
        $mysqldumpSkipTzUtc    = config('dbsync.mysqldumpSkipTzUtc') ? '--skip-tz-utc' : '';

        $targetConnection      = config('dbsync.targetConnection');
        $defaultConnection     = config('database.default');

        $defaultConnection = empty($targetConnection) ? $defaultConnection: $targetConnection;

        $localUsername = config("database.connections.{$defaultConnection}.username");
        $localPassword = config("database.connections.{$defaultConnection}.password");
        $localHostname = config("database.connections.{$defaultConnection}.host");
        $localPort = config("database.connections.{$defaultConnection}.port");
        $localDatabase = config("database.connections.{$defaultConnection}.database");
        $localMysqlPath = config('dbsync.localMysqlPath');

        if (empty($host) || empty($username) || empty($database)) {
            $this->error('DB credentials not set, have you published the config and set ENV variables?');

            return true;
        }

        if ($inTest === false) {
            $ignoreString = null;

            foreach ($ignoreTables as $name) {
                $ignoreString .= " --ignore-table=$database.$name";
            }

            $totalSteps = 2;
            $progressBar = $this->output->createProgressBar($totalSteps);

            if ($useSsh === true) {
                echo($mysqlHostName . PHP_EOL);
                exec("ssh $sshUsername@$host -p$sshPort mysqldump --single-transaction --set-gtid-purged=OFF --port=$port --host=$mysqlHostName --user=$username --password=$password $database $ignoreString > $fileName", $output);
            } else {
                exec("mysqldump --single-transaction --set-gtid-purged=OFF --port=$port --host=$mysqlHostName --user=$username --password=$password $database $ignoreString $mysqldumpSkipTzUtc --column-statistics=0 > $fileName", $output);
            }
            
            if($mysqlComatibile) {
                $this->convertMariadbToMysql($fileName);
            }

            $progressBar->advance();

            $command = $localPassword
                ? "$localMysqlPath -u$localUsername -h$localHostname -p$localPassword -P$localPort $localDatabase < $fileName"
                : "$localMysqlPath -u$localUsername -h$localHostname -P$localPort $localDatabase < $fileName";
            exec($command, $output);

            $progressBar->advance();
            $progressBar->finish();

            if ($removeFileAfterImport === true) {
                unlink($fileName);
            }
        }

        $this->comment("\nDB Synced");

        return true;
    }

    private function convertMariadbToMysql($inputFile) {
        if (!file_exists($inputFile)) {
            throw new \Exception("Plik wejściowy nie istnieje: $inputFile");
        }
    
        $inputLines = file($inputFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $outputLines = [];
    
        foreach ($inputLines as $line) {
            // Zamiana LONGTEXT + CHECK(json_valid(...)) na JSON
            if (strpos($line, 'longtext') !== false && strpos($line, 'json_valid') !== false) {
                // Zamiana LONGTEXT na JSON
                $line = preg_replace('/longtext/', 'JSON', $line);
                // Usunięcie CHECK(json_valid(...)) i CHARACTER SET, COLLATE
                $line = preg_replace('/ CHECK \(json_valid\([^)]+\)\)/', '', $line);
                $line = preg_replace('/ CHARACTER SET [^ ]* COLLATE [^ ]*/', '', $line);
                
            }

            // Zamiana json_array('PL') na (json_array(_utf8mb4'PL'))
            if (strpos($line, 'json_array') !== false) {
                $line = preg_replace(
                    '/json_array\(([^)]+)\)/',
                    '(json_array(_utf8mb4\1))',
                    $line
                );

                if (!preg_match('/\(json_array/', $line)) {
                    // Dodajemy nawiasy wokół json_array
                    $line = preg_replace('/json_array([^)]*)/', '(json_array\1)', $line);
                }
    
                // Sprawdzamy, czy _utf8mb4 jest obecne
                if (!strpos($line, "_utf8mb4")) {
                    // Dodajemy _utf8mb4
                    $line = preg_replace('/\(json_array/', '(json_array(_utf8mb4', $line);
                }
            }
    
            $outputLines[] = $line;
        }
    
        // Zapisanie zmodyfikowanych linii do pliku wyjściowego
        file_put_contents($inputFile, implode(PHP_EOL, $outputLines));
    }
}
