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
use Kwkm\OptParser\OptParser;
use Kwkm\NagiosPluginSDK\NagiosThresholdPair;
use Kwkm\NagiosPluginSDK\NagiosStatus;
use Kwkm\NagiosPluginSDK\NagiosOutput;

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

    private $optParser;

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
                NagiosOutput::display(NagiosStatus::CRITICAL, $value);
            }
        }

        if (!is_null($this->warning)) {
            $warningThresholed = new NagiosThresholdPair($this->warning);
            if (!$warningThresholed->check($value)) {
                NagiosOutput::display(NagiosStatus::WARNING, $value);
            }
        }

        NagiosOutput::display(NagiosStatus::OK, $value);
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
            NagiosOutput::display(NagiosStatus::UNKNOWN, $ex->getMessage());
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

    private function parseArgument()
    {
        if ($this->optParser->isOption('-V')) {
            $this->outputVersion();
            exit(NagiosStatus::OK);
        }

        if ($this->optParser->isOption('-?')) {
            $this->outputHelp();
            exit(NagiosStatus::OK);
        }

        if (!$this->optParser->checkRequired()) {
            $this->outputHelp();
            exit(NagiosStatus::UNKNOWN);
        }

        $this->address = $this->optParser->getOption('-h');
        $this->database = $this->optParser->getOption('-d');
        $this->username = $this->optParser->getOption('-U');
        $this->password = $this->optParser->getOption('-P');

        $this->timeout = $this->optParser->getOption('-t');
        $this->port = $this->optParser->getOption('-p');
        $this->critical = $this->optParser->getOption('-c');
        $this->warning = $this->optParser->getOption('-w');

        switch ($this->optParser->getOption('--type')) {
            case 'table':
                $this->type = 'table';
                break;
            case 'index':
                $this->type = 'index';
                break;
            default:
                $this->type = 'db';
        }

        if (!$this->optParser->isOption('--rel')) {
            $this->rel = $this->database;
        } else {
            $this->rel = $this->optParser->getOption('--rel');
        }
    }

    private function outputVersion()
    {
        echo 'check_pgsql_cachehit Version ', check_pgsql_cachehit::VERSION, PHP_EOL;
        echo 'usage: check_pgsql_cachehit -h <DB Address> -U <DB User> -P <DB Password> -d <DB Name>', PHP_EOL;
    }

    private function outputHelp()
    {
        $this->outputVersion();
        $this->optParser->help();
    }

    private function initializationOption()
    {
        $this->optParser->addOption(
            '-h',
            array(
                'alias' => '--host',
                'var' => 'HOSTNAME',
                'help' => 'database server host',
                'required' => true,
            )
        )->addOption(
            '-U',
            array(
                'alias' => '--username',
                'var' => 'USERNAME',
                'help' => 'database user name',
                'required' => true,
            )
        )->addOption(
            '-P',
            array(
                'alias' => '--password',
                'var' => 'PASSWORD',
                'help' => 'database user password',
                'required' => true,
            )
        )->addOption(
            '-d',
            array(
                'alias' => '--database',
                'var' => 'DBNAME',
                'help' => 'database name',
                'required' => true,
            )
        )->addOption(
            '-p',
            array(
                'alias' => '--port',
                'var' => 'PORTNUMBER',
                'help' => 'database port / default: 5432',
                'default' => '5432',
            )
        )->addOption(
            '-c',
            array(
                'alias' => '--critical',
                'var' => 'THRESHOLD',
                'help' => 'Critical Threshold',
            )
        )->addOption(
            '-w',
            array(
                'alias' => '--warning',
                'var' => 'THRESHOLD',
                'help' => 'Warning Threshold',
            )
        )->addOption(
            '--type',
            array(
                'var' => '<db|table|index>',
                'help' => 'Monitoring target / default: db',
                'default' => 'db',
            )
        )->addOption(
            '--rel',
            array(
                'var' => '<DB Name|Table Name>',
                'help' => 'Relation target / default: DB Name',
            )
        )->addOption(
            '-t',
            array(
                'var' => 'SECOND',
                'help' => 'Timeout / default: 10',
                'default' => '10',
            )
        )->addOption(
            '-V',
            array(
                'alias' => '--version',
                'help' => 'show this version, then exit',
            )
        )->addOption(
            '-?',
            array(
                'alias' => '--help',
                'help' => 'show this help, then exit',
            )
        );
    }

    public function __construct($argv)
    {
        $this->optParser = new OptParser($argv);
        $this->initializationOption();
        $this->parseArgument();
    }
}

$plugin = new check_pgsql_cachehit($argv);
$plugin->run();


