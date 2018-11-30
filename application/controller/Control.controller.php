<?php

use \Glial\Synapse\Controller;
use \Monolog\Logger;
use \Monolog\Formatter\LineFormatter;
use \Monolog\Handler\StreamHandler;
use \App\Library\Debug;
use \App\Library\Mysql;

/*
 * ./glial Aspirateur testAllMysql 6 --debug
 * ./glial integrate evaluate --debug
 */

class Control extends Controller {

    var $tables = array("ts_value_general", "ts_value_slave");
    var $ext = array("int", "double", "text");
    var $field_value = array("int" => "bigint(20) unsigned NOT NULL", "double" => "double NOT NULL", "text" => "text NOT NULL");
    var $primary_key = array("ts_value_general" => "PRIMARY KEY (`id`, `date`)"
        , "ts_value_slave" => "PRIMARY KEY (`id`,`date`)");
    //var $primary_key = array("ts_value_general" => "PRIMARY KEY (`id`)", "ts_value_slave" => "PRIMARY KEY (`id`)");
    var $index = array("ts_value_general" => " INDEX (`id_mysql_server`, `id_ts_variable`, `date`)",
        "ts_value_slave" => "INDEX (`id_mysql_server`, `id_ts_variable`, `date`)",
        "ts_date_by_server" => "UNIQUE KEY `id_mysql_server` (`id_mysql_server`,`id_ts_file`,`date`)"
    );
    var $engine = "tokudb";
    var $engine_preference = array("TokuDB", "ROCKSDB");
    var $extra_field = array("ts_value_slave" => "`connection_name` varchar(64) NOT NULL,", "ts_value_general" => "");
    var $percent_max_disk_used = 80;

    /*
     *
     * return space used on partition where is datadir of MySQL / MariaDB
     */

    private function checkSize() {
        $db = $this->di['db']->sql(DB_DEFAULT);

        $datadir = $db->getVariables("datadir");



        // connect to ssh to sql server
        //$ssh->
        // or local
        $size = shell_exec('cd ' . $datadir . ' && df -k . | tail -n +2 | sed ":a;N;$!ba;s/\n/ /g" | sed "s/\ +/ /g" | awk \'{print $5}\'');




        $percent = substr($size, 0, -1);

        /*
          $size = shell_exec('cd '.$datadir.' && df -k . | tail -n +2 | sed ":a;N;$!ba;s/\n/ /g" | sed "s/\ +/ /g"');
          Debug::debug($size);

          $resultats = preg_replace('`([ ]{2,})`', ' ', $size);
          Debug::debug($resultats);

          $results = explode(' ', trim($resultats));

          $data['size']      = $results['1'];
          $data['used']      = $results['2'];
          $data['available'] = $results['3'];

          Debug::debug($data);

          $percent = ceil($data['used'] / $data['size'] * 100);

          Debug::debug($percent);
         */
        return $percent;
    }

    public function before($param = "") {
        $logger = new Logger(__CLASS__);
        $file_log = LOG_FILE;
        $handler = new StreamHandler($file_log, Logger::DEBUG);
        $handler->setFormatter(new LineFormatter(null, null, false, true));
        $logger->pushHandler($handler);
        $this->logger = $logger;

        $this->selectEngine();
    }

    public function selectEngine() {
        $db = $this->di['db']->sql(DB_DEFAULT);

        $sql = "select * from information_schema.ENGINES where SUPPORT = 'YES' and ENGINE in('" . implode("','", $this->engine_preference) . "');";
        $res = $db->sql_query($sql);


        $engine_possible = array();
        while ($ob = $db->sql_fetch_object($res)) {
            $engine_possible[] = $ob->ENGINE;
        }


        foreach ($this->engine_preference as $engine) {
            if (in_array($engine, $engine_possible)) {
                $this->engine = $engine;
                return true;
            }
        }

        throw new \Exception("PMACTRL-991 : there is no engine in this list installed : '" . implode(",", $this->engine_preference) . "'", 80);
    }

    public function addPartition($param) {
        $partition_number = $param[0];
        $db = $this->di['db']->sql(DB_DEFAULT);

        $combi = $this->makeCombinaison();

        foreach ($combi as $table) {
            $sql = "ALTER TABLE `" . $table . "` ADD PARTITION (PARTITION `p" . $partition_number . "` VALUES LESS THAN (" . $partition_number . ") ENGINE = " . $this->engine . ");";
            
            Debug::sql($sql);
            $db->sql_query($sql);
            $this->logger->info($sql);
        }
    }

    private function makeCombinaison() {
        $combinaisons = array();

        foreach ($this->tables as $table) {
            foreach ($this->ext as $ext) {
                $combinaisons[] = $table . "_" . $ext;
            }
        }
        
        $combinaisons[] = "ts_date_by_server";
        

        return $combinaisons;
    }

    public function dropPartition($param) {
        $partition_number = $param[0];
        $db = $this->di['db']->sql(DB_DEFAULT);

        $combi = $this->makeCombinaison();

        foreach ($combi as $table) {
            $sql = "ALTER TABLE `" . $table . "` DROP PARTITION `p" . $partition_number . "`;";
            $db->sql_query($sql);

            $this->logger->info($sql);
        }
    }

    /*
     * récupérer la partition la plus vieille dans le but de l'effacé
     *
     * et la dernière
     */

    public function getMinMaxPartition() {
        $db = $this->di['db']->sql(DB_DEFAULT);
        $combi = $this->makeCombinaison();

        $sql = "SELECT DISTINCT `PARTITION_NAME` FROM information_schema.partitions 
            where table_name IN ('" . implode("','", $combi) . "') AND `PARTITION_NAME` IS NOT NULL;";
        $res = $db->sql_query($sql);

        $partitions = array();
        while ($ob = $db->sql_fetch_object($res)) {
            $partitions[] = substr($ob->PARTITION_NAME, 1);
        }

        $older_partition['min'] = min($partitions);
        $older_partition['max'] = max($partitions);
        $older_partition['other'] = $partitions;

        return $older_partition;
    }

    public function getToDays($param) {
        $date = $param[0];
        $db = $this->di['db']->sql(DB_DEFAULT);

        $sql = "SELECT TO_DAYS('" . $date . "') as number";
        $res = $db->sql_query($sql);

        while ($ob = $db->sql_fetch_object($res)) {
            $partition = $ob->number;
        }

        return $partition;
    }

    // each hour ?
    /*
     * check space and delete old partition
     * and create new parttion
     */

    public function service($param = "") {

        Debug::parseDebug($param);
        $partitions = $this->getMinMaxPartition();

        //we drop oldest parttion if free space is low
        if ($this->checkSize() > $this->percent_max_disk_used) {
            Debug::debug($partitions['min'], "Drop Partition");

            if (count($partitions['other']) > 2) {   //minimum we let two partitions
                $this->dropPartition(array($partitions['min']));
            }
        }

        $part = $this->getDates();

        Debug::debug($part);

        // check partition of today and tomorow and create it if it's not exist
        foreach ($part as $date) {
            $partition_to_check = $this->getToDays(array($date));

            Debug::debug($partition_to_check);

            if (!in_array($partition_to_check, $partitions['other'])) {
                $this->addPartition(array($partition_to_check));
            }
        }

        $this->updateLinkVariableServeur();




        //Mysql::onAddMysqlServer($this->di['db']->sql(DB_DEFAULT));
    }

    public function dropTsTable($param = array()) {

        Debug::parseDebug($param);
        
        
        $db = $this->di['db']->sql(DB_DEFAULT);

        $combi = $this->makeCombinaison();

        foreach ($combi as $table) {
            $sql = "DROP TABLE IF EXISTS `" . $table . "`;";
            $db->sql_query($sql);
            Debug::sql($sql);

            $this->logger->info($sql);
        }

        
        /*
        $sql = "DROP TABLE IF EXISTS `ts_date_by_server`";
        $db->sql_query($sql);
        */

        


        $to_delete = array("/dev/shm/server_*", "/dev/shm/answer_*", "/dev/shm/variable_*");


        foreach ($to_delete as $file_to_delete) {

            $files = glob($file_to_delete);

            if (count($files) > 0) {
                shell_exec("rm " . $file_to_delete);
            }
        }
    }

    public function createTsTable() {
        $db = $this->di['db']->sql(DB_DEFAULT);


        $dates = $this->getDates();


        foreach ($this->tables as $table) {
            foreach ($this->ext as $ext) {
                $table_name = $table . "_" . $ext;

                /*
                 *
                 *
                 */


                $sql = "CREATE TABLE `" . $table_name . "` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT ,
  `id_mysql_server` int(11) NOT NULL,
  `id_ts_variable` int(11) NOT NULL,
  " . $this->extra_field[$table] . "
  `date` datetime NOT NULL,
  `value` " . $this->field_value[$ext] . ",
  " . $this->primary_key[$table] . "
) ENGINE=" . $this->engine . " DEFAULT CHARSET=latin1
PARTITION BY RANGE (to_days(`date`))
(";

                $partition = array();
                foreach ($dates as $date) {

                    $partition_nb = $this->getToDays(array($date));
                    $partition[] = "PARTITION `p" . $partition_nb . "` VALUES LESS THAN (" . $partition_nb . ") ENGINE = " . $this->engine . "";
                }
                $sql .= implode(",", $partition) . ")";

                $db->sql_query($sql);


                $db->sql_query("ALTER TABLE `" . $table_name . "` ADD " . $this->index[$table] . ";");

                $this->logger->info($sql);
            }
        }



        $sql = "CREATE TABLE `ts_date_by_server` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `id_mysql_server` int(11) NOT NULL,
  `id_ts_file` int(11) NOT NULL,
  `date` datetime NOT NULL,
  PRIMARY KEY (`id`,`date`),
  UNIQUE KEY `id_mysql_server` (`id_mysql_server`,`id_ts_file`,`date`)
) ENGINE=" . $this->engine . " DEFAULT CHARSET=latin1 ROW_FORMAT=COMPACT
            PARTITION BY RANGE (to_days(`date`))
(";

        $partition = array();
        foreach ($dates as $date) {

            $partition_nb = $this->getToDays(array($date));
            $partition[] = "PARTITION `p" . $partition_nb . "` VALUES LESS THAN (" . $partition_nb . ") ENGINE = " . $this->engine . "";
        }
        $sql .= implode(",", $partition) . ")\n";

        echo Debug::sql($sql);

        $db->sql_query($sql);
    }

    public function rebuildAll($param = "") {

        $db = $this->di['db']->sql(DB_DEFAULT);


        Debug::parseDebug($param);

        $this->dropTsTable();
        $this->createTsTable();


        Mysql::onAddMysqlServer($this->di['db']->sql(DB_DEFAULT));
    }

    public function statistique($param = "") {
        Debug::parseDebug($param);

        $db = $this->di['db']->sql(DB_DEFAULT);

        $combi = $this->makeCombinaison();

        $sql = "SELECT `TABLE_NAME`,`PARTITION_NAME`,`SUBPARTITION_NAME` ,`TABLE_ROWS` FROM information_schema.partitions
            where table_name IN ('" . implode("','", $combi) . "') AND `PARTITION_NAME` IS NOT NULL;";

        Debug::debug(SqlFormatter::format($sql));
    }

    private function getDates() {
        $today = date("Y-m-d");

        $date = new DateTime($today);
        $date->modify('+1 day');
        $part[] = $date->format('Y-m-d');
        $date->modify('+1 day');
        $part[] = $date->format('Y-m-d');


        return $part;
    }

    public function updateLinkVariableServeur() {

        Debug::debug("UPDATE link__ts_variable__mysql_server");


        $db = $this->di['db']->sql(DB_DEFAULT);

        $sql = "SELECT * FROM mysql_server;";
        $res = $db->sql_query($sql);




        while ($ob = $db->sql_fetch_object($res)) {
            $this->updateLinkServeur(array($ob->id));
        }
    }

    public function updateLinkServeur($param) {
        $db = $this->di['db']->sql(DB_DEFAULT);

        $id_mysql_server = $param[0];


        Debug::debug($id_mysql_server, "id_mysql_server");


        $sql = "SELECT id_ts_variable FROM link__ts_variable__mysql_server where id_mysql_server =" . $id_mysql_server;


        //Debug::sql($sql);

        $res = $db->sql_query($sql);

        $link1 = array();
        while ($ob = $db->sql_fetch_object($res)) {
            $link1[] = $ob->id_ts_variable;
        }

        $sql = "(SELECT b.id_ts_variable FROM ts_max_date a
            INNER JOIN ts_value_general_int b ON a.date = b.date AND a.id_mysql_server = b.id_mysql_server
            INNER JOIN ts_variable c on c.id = b.id_ts_variable AND c.id_ts_file = a.id_ts_file
            WHERE a.id_mysql_server=" . $id_mysql_server . " AND a.id_ts_file=3)
                UNION ALL
                (
                SELECT b.id_ts_variable FROM ts_max_date a
            INNER JOIN ts_value_general_int b ON a.date = b.date AND a.id_mysql_server = b.id_mysql_server
            INNER JOIN ts_variable c on c.id = b.id_ts_variable AND c.id_ts_file = a.id_ts_file
            WHERE a.id_mysql_server=" . $id_mysql_server . " AND a.id_ts_file=4
                )
            ";


        //Debug::sql($sql);

        $res = $db->sql_query($sql);

        $link2 = array();
        while ($ob = $db->sql_fetch_object($res)) {
            $link2[] = $ob->id_ts_variable;
        }

        //$resultat = array_intersect($link1, $link2);
        //Debug::debug($link1, "link1");
        //Debug::debug($link2, "link2");


        $to_delete = array_diff($link1, $link2);
        $to_create = array_diff($link2, $link1);



        //Debug::debug($to_delete, "to delete");
        //Debug::debug($to_create, "to create");

        if (count($to_create) > 0) {
            $sql = "INSERT INTO link__ts_variable__mysql_server (`id_mysql_server`,`id_ts_variable`)
            VALUES (" . $id_mysql_server . "," . implode("),(" . $id_mysql_server . ",", $to_create) . ")";

            $db->sql_query($sql);
        }

        if (count($to_delete) > 0) {
            $sql = "DELETE FROM link__ts_variable__mysql_server
            WHERE `id_mysql_server`=" . $id_mysql_server . " AND id_ts_variable IN (" . implode(",", $to_delete) . ")";

            $db->sql_query($sql);
        }
    }

    public function updateConfig() {
        $db = $this->di['db']->sql(DB_DEFAULT);
        Mysql::generateMySQLConfig($db);
    }

}
