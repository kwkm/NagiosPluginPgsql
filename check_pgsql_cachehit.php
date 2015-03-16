#!/usr/bin/php
<?php
/**
 * Nagios Plugin Pgsql - Check Cache/Hit
 *
 * @package NagiosPluginSDK
 * @author Takehiro Kawakami <take@kwkm.org>
 * @license http://opensource.org/licenses/mit-license.php
 */
namespace Kwkm\NagiosPlugin\Pgsql;

use \PDO;
use Kwkm\NagiosPluginSDK\NagiosThresholdPair;
use Kwkm\NagiosPluginSDK\NagiosStatus;

require_once __DIR__ . '/vendor/autoload.php';

class check_pgsql_cachehit
{
    const VERSION = '0.1.0';

    private $con;
    private $address;
    private $database;
    private $port;
    private $critical;
    private $warning;
    private $timeout;
    private $username;
    private $password;
    private $type;
    private $rel;

    public function run()
    {
        $this->con = new PDO(
            "pgsql:host={$this->address};dbname={$this->database};port={$this->port}",
            $this->username,
            $this->password
        );
        $this->con->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $value = $this->getData();

        if (!is_null($this->critical)) {
            $criticalThresholed = new NagiosThresholdPair($this->critical);
            if (!$criticalThresholed->check($value)) {
                echo 'CRITICAL - ' . $value . PHP_EOL;
                exit(NagiosStatus::CRITICAL);
            }
        }

        if (!is_null($this->warning)) {
            $warningThresholed = new NagiosThresholdPair($this->warning);
            if (!$warningThresholed->check($value)) {
                echo 'WARNING - ' . $value . PHP_EOL;
                exit(NagiosStatus::WARNING);
            }
        }

        echo 'OK - ' . $value . PHP_EOL;
        exit(NagiosStatus::OK);
    }

    private function getData()
    {
        try {
            switch ($this->type) {
                case 'db':
                    return $this->queryDbCacheHit();
                    break;
                case 'table':
                    return $this->queryTableCacheHit();
                    break;
                case 'index':
                    return $this->queryIndexCacheHit();
                    break;
            }
        } catch (\Exception $ex) {
            echo 'UNKNOWN - ' . $ex->getMessage() . PHP_EOL;
            exit(NagiosStatus::UNKNOWN);
        }
    }

    private function queryDbCacheHit()
    {
        $query = "SELECT "
            . "CASE WHEN blks_read = 0 THEN 100.00 ELSE "
            . "round(blks_hit*100/(blks_hit+blks_read), 2) END AS cache_hit_ratio "
            . "FROM pg_stat_database "
            . " WHERE datname = ?";
        $stmt = $this->con->prepare($query);
        $stmt->execute(array($this->rel));

        $result = $stmt->fetchColumn();
        if ($result === false) {
            throw new \Exception("Database {$this->rel} was not found.");
        }

        return $result;
    }

    private function queryTableCacheHit()
    {
        $query = "SELECT "
            . "CASE WHEN heap_blks_read = 0 THEN 100.00 ELSE "
            . "round(heap_blks_hit*100/(heap_blks_hit+heap_blks_read), 2) "
            . "END AS cache_hit_ratio FROM pg_statio_user_tables "
            . " WHERE relname = ?";
        $stmt = $this->con->prepare($query);
        $stmt->execute(array($this->rel));

        $result = $stmt->fetchColumn();
        if ($result === false) {
            throw new \Exception("Table {$this->rel} was not found.");
        }

        return $result;
    }

    private function queryIndexCacheHit()
    {
        $query = "SELECT CASE WHEN heap_blks_read = 0 THEN 100.00 ELSE "
            . "round(heap_blks_hit*100/(heap_blks_hit+heap_blks_read), 2) END AS "
            . "cache_hit_ratio FROM pg_statio_user_tables WHERE relname = ? ";
        $stmt = $this->con->prepare($query);
        $stmt->execute(array($this->rel));

        $result = $stmt->fetchColumn();
        if ($result === false) {
            throw new \Exception("Index of table {$this->rel} was not found.");
        }

        return $result;
    }

    private function parseArgument($options)
    {
        if (isset($options['V'])) {
            $this->outputVersion();
            exit(NagiosStatus::OK);
        }

        if (isset($options['help'])) {
            $this->outputHelp();
            exit(NagiosStatus::OK);
        }

        if ((!isset($options['h'])) || (!isset($options['d'])) || (!isset($options['username'])) || (!isset($options['password']))) {
            $this->outputHelp();
            exit(NagiosStatus::OK);
        } else {
            $this->address = $options['h'];
            $this->database = $options['d'];
            $this->username = $options['username'];
            $this->password = $options['password'];
        }

        if (isset($options['t'])) {
            $this->timeout = $options['t'];
        } else {
            $this->timeout = 10;
        }

        if (isset($options['p'])) {
            $this->port = $options['p'];
        } else {
            $this->port = 5432;
        }

        if (isset($options['c'])) {
            $this->critical = $options['c'];
        }

        if (isset($options['w'])) {
            $this->warning = $options['w'];
        }

        if (!isset($options['type'])) {
            $this->type = 'db';
        } else {
            switch ($options['type']) {
                case 'table':
                    $this->type = 'table';
                    break;
                case 'index':
                    $this->type = 'index';
                    break;
                default:
                    $this->type = 'db';
            }
        }

        if (!isset($options['rel'])) {
            $this->rel = $options['d'];
        } else {
            $this->rel = $options['rel'];
        }
    }

    private function outputVersion()
    {
        echo 'check_pgsql_cachehit Version ', check_pgsql_cachehit::VERSION, PHP_EOL;
        echo 'usage: check_pgsql_cachehit -h <DB Address> --username <DB User> --password <DB Password> -d <DB Name>', PHP_EOL;
    }

    private function outputHelp()
    {
        $this->outputVersion();
        echo PHP_EOL;
        echo '<Require>', PHP_EOL;
        echo ' -h', PHP_EOL;
        echo '   DB Address', PHP_EOL;
        echo ' --username', PHP_EOL;
        echo '   DB User', PHP_EOL;
        echo ' --password', PHP_EOL;
        echo '   DB Password', PHP_EOL;
        echo ' -d', PHP_EOL;
        echo '   DB Name', PHP_EOL;
        echo PHP_EOL;
        echo '<Optional>', PHP_EOL;
        echo ' -c', PHP_EOL;
        echo '   Critical Threshold', PHP_EOL;
        echo ' -w', PHP_EOL;
        echo '   Warning Threshold', PHP_EOL;
        echo ' --type <db|table|index>', PHP_EOL;
        echo '   Monitoring target / default: db', PHP_EOL;
        echo ' --rel <DB Name|Table Name>', PHP_EOL;
        echo '   Relation target / default: DB Name', PHP_EOL;
        echo ' -t', PHP_EOL;
        echo '   Timeout / default: 10', PHP_EOL;
    }

    public function __construct()
    {
        $this->parseArgument(
            getopt(
                "h:d:c:w:p:t:V",
                array(
                    'username:',
                    'password:',
                    'type:',
                    'rel:',
                    'help',
                )
            )
        );
    }
}

$plugin = new check_pgsql_cachehit();
$plugin->run();


