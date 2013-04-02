<?PHP

/*
 * Configuration class for the DB-Plus-Memcache DAL (Data Access Layer).
 *
 * This DAL uses PDO - and as such is compatable with any RDBMS that
 * has a suitable driver.
 *
 * This DAL uses Memcached. It requries that PHP-pecl-memcache be installed
 * (you can get away with NOT having it if you be sure to set DALUSEMEMC to false.
 *
 * @author: Brian Roy
 * @date: 01/28/20111
 * 
 */

class dalConfig {
	// Configuration for connecting to MEMCACHE
	// The following array contains one (or more for Memcache pooling) hostname/port combinations for
	// Memcache servers. 
	// We will connect to these servers for our memcache requests.
	public static $aryDalMemC = array("localhost" => "11211");
	
	// Setting DALUSEMEMC to false will cause the Data Access Layer to never connect to or
	// attempt to get data from Memcache. This will result in no data cache outside the DB
	// and probable performance degredation.
	public static $DALUSEMEMC = true;

    /*
     * Log file for debugging/instrumentation.
     * You can always point these to /dev/null to make them silent - not reccomended.
     */

	public static $DALLOG = "/tmp/dallog.log";
    public static $DALEXCEPTIONLOG = "/tmp/dal_exceptions.log";

	// Configuraiton for DB Connections
	// We are using PDO NOT database specific drivers. Please see the PHP PDO manual for the
	// proper DSN format for your Database
	

    public static $aryDalDSNPool = array(
        "primary" => array(
            "dsn" => "mysql:dbname=mydb;host=mydb_host",
            "dbuser" => "uname",
            "dbpwd" => "pwd",
            "persistent" => false,
        ),
        "slave" => array(
            "dsn" => "mysql:dbname=mydb;host=mydb_host.me.com",
            "dbuser" => 'uname',
            "dbpwd" => "pwd",
            "persistent" => false,
        ),
        "reports" => array(
            "dsn" => "mysql:dbname=mydb;host=mydb_host.reports.me.com",
            "dbuser" => 'uname',
            "dbpwd" => "pwd",
            "persistent" => false,
        ),

    );

    /*
     * Pluggable persistence classes to add to the DAL
     */
    public static $aryPluggables = array(
        "MongoDB" => array(
            "objectName" => "plugMdb",
            "className" => "dalMongo",
            "classFile" => "pluggables/dalMongo.php",
        ),
        "Neo4j" => array(
            "objectName" => "plugNeo4j",
            "className" => "dalNeo4j",
            "classFile" => "pluggables/dalNeo4j.php",
        ),
    );

    /*
     * MongoDB Connection Configuration
     */
    public static $aryMdbConnections = array(
        "local" => array(
            "host" => "localhost",
            "port" => 27017,
            "useAuth" => false,
            "username" => "myuser",
            "password" => "mypass",
            "db" => "mymongodb",
        )
    );


}
?>
