# DB-Plus-Memcache

DB Plus Memcache is a RDBMS (via PHP PDO) independent PHP Data Access Layer class that makes is easy
(really, really easy) to build your PHP applications with Memcache support by default.

This has been extensively tested against MySQL - Results on other DBs may vary.

## What's New In Version 1.2

Version 1.2 of the Data Access Layer brings pluggable peristance classes to the DAL. You can now implement
any persistance solution (Cassandra, Redis, Riak, etc.) directly within the DAL by creating a
pluggable module (class).
The repo no contains the dalMonogo class which implements a MongoDB pluggable persistance module.

See the "pluggables" section below for more information.

## Quick Start

Files:

1. dal-conf.php - Contains the configuration the class - used by DB Plus Memcache
2. dal-class.php - The actual DB Plus Memcache DAL class implementation.

Configure dal-conf.php to your specifics. Notably you will add your memcache server(s),
your DSN information for any Database Connections you will use, and log file locations.

The simplest usage is executing a prepared statement and caching it for some duration. The
following example connects to the primary DB (as defined in dal-conf.php) and executes
a simple query caching the result for 30 seconds (see the $myDal->fetchPreparedResult call).

### Example

    require_once("dal-class.php");

    $myDal = new dal();

    // Turn on debugging...
    $myDal->doDebug(true);

    try {

        $myDal->dbConnect("primary");

        $query = "select :this+:that as mysum";

        $prepared = $myDal->prepareStatement($query, dal::PREPARE_SELECT);


        $input = array(
            ":this" => array("value" => 1, "data_type" => PDO::PARAM_INT),
            ":that" => array("value" => 14, "data_type" => PDO::PARAM_INT),

        );

        $result = $myDal->executeStatement($prepared, $input);

        $array_result = $myDal->fetchPreparedResult($prepared, dal::RETURN_ASSOC, true, 30);

        print_r($array_result);

    } catch(dalException $e) {
        echo "Throwing a dalException exception!\n";
        print_r($e->dbMessages);
        echo "\n".$e->getMessage()."\n";
    }

## Specifics

The DAL supports both prepared statements (which you should really, really use) and
direct database queries using dal->QueryInCache(). It also exposes all the needed methods
to cache, fetch and remove objects from Memcache.

### Return Formats

The DAL returns your data in one of 3 formats:
1. An Associative Array (default)
2. JSON (string)
3. XML (string)

### SQL Support

The DAL supports transactions via the startTransaction, commitTransaction and rollbackTransaction
methods. All methods are transaction safe - in that they will not interrupt an in process
transaction (e.g., you can't close the DB Connection if there is a transaction in process).

### Performance Specifics

DB connects are done optimistically - that is, we only connect to the DB if we first find the
requested object IS NOT in memcache. We also immediately disconnect when we get a result set and
return it. This has two effects:

1. Your application may connect/disconnect multiple times over a set of queries. This may NOT be appropriate for all applications.
2. In high cache hit rate scenarios the actual connection overhead is dramatically reduced, in low hit rate scenarios it may actually increase.

## Pluggables

The DAL can now be exteded via pluggable persistance modules (classes) which add different persistance
solutions to the DAL.

Pluggable modules are configured in the dal-conf.php file as follows:

    /*
     * Pluggable persistence classes to add to the DAL
     */
    public static $aryPluggables = array(
        "MongoDB" => array(
            "objectName" => "plugMdb",
            "className" => "dalMongo",
            "classFile" => "dalMongo.php",
        )
    );

The *objectName* will be used from within your code to access the pluggable module.
The *className* is the pluggable class implementation.
The *classFile* is the path to the file containing the pluggable class implementation. The path is relative
unless it begins with a "/".

When the DAL is instantiated every pluggable module in *$aryPluggables* is instantiated. See the
private DAL method *loadPlugables* for details.

Once the DAL is instantiated you can access the pluggable module in two ways:

1. Directly via the public property *plugables* which contains an array of pluggable module objects indexed by the *objectName* found in the configuration.
1. With Memcache integration via the public method *doPluggableFindWithCache($pluggableName, $findData, $cacheDurr)*

The direct method allows you to call the methods of your pluggable class directly. This is especially useful
in cases where you need non-standard CRUD methods. For example, in the MongoDB pluggable class *dalMongo* we have
a method for performing a Map Reduce job on the MongoDB Replica Set.

The Memcache integration allows you to perform get/find operations on the pluggable module and
cache the resutls in Memcache. This allows you to leverage the DAL internals for caching objects quickly
and easily.

**NOTE - The result of the dbGet method in your pluggable class MUST be serializable for the Memcache
integration to work. Attempting to cache un-serializable results will cause unexpected and unpredictable
behavior**

### Using a Pluggable

The file *testDal.php* contains a series of tests to ensure the DAL is functioning correctly in your
environment. The first set of tests now test the *dalMongo* pluggable class as follows:

    /*
     * Test MongoDB Pluggable
     */

    $mdbConn = array("connectTo" => "local",
        "configs" => dalConfig::$aryMdbConnections,
    );
    $myDal->plugables['plugMdb']->setConnectInfo($mdbConn);

    echo "Insert Test\n";

    $aryInsert = array(
        "x" => 12,
        "name" => "Luke Skywalker",
        "role" => "Yoda",
        "now" => time(),
    );

    $aryInsOp = array(
        'operation' => 'insert',
        'document' => $aryInsert,
        'collection' => 'zmUpload',
        'opts' => array("safe" => true),
    );

    $result = $myDal->plugables['plugMdb']->dbSave($aryInsOp);

    echo "Insert Result:\n";
    print_r($result);
    echo "Resulting ID: \n";
    print_r($aryInsert);
    echo "\n".$aryInsert['_id']."\n";
    $myId = $aryInsert['_id'];

    echo "Now look it up using doPluggableFindwithCache:\n";

    $aryIn = array(
        'return_type' => 'array',
        'query' => array('_id' => $myId),
        'fields' => array(),
        'collection' => 'zmUpload',
    );

    $result = $myDal->doPluggableFindWithCache('plugMdb', $aryIn, 10);

    print_r($result);
    echo "\n\n";

    echo "Now Update it...\n";

    $aryUpdOp = array(
        'operation' => 'update',
        'document' => array('$set' => array('role' => 'Bounty Hunter', 'updated' => time())),
        'criteria' => array('_id' => $myId),
        'collection' => 'zmUpload',
        'opts' => array("safe" => true),
    );

    $result = $myDal->plugables['plugMdb']->dbSave($aryUpdOp);

    print_r($result);

    echo "\nNow grab it from cache to show that cache is working...\n";

    $result = $myDal->doPluggableFindWithCache('plugMdb', $aryIn, 10);

    print_r($result);
    echo "\n\n";

    echo "Sleeping to let cache expire...\n";

    sleep(10);

    echo "Now look it up one more time to be sure...\n";
    $aryIn = array(
        'return_type' => 'array',
        'query' => array('_id' => $myId),
        'fields' => array(),
        'collection' => 'zmUpload',
    );

    $result = $myDal->doPluggableFindWithCache('plugMdb', $aryIn, 10);

    print_r($result);

    echo "\n\nIf Luke is a bounty hunter the MongoDB Pluggable is working perfectly...\n\n\n";

    echo "Now, let's try a delete...\n";

    $aryDelOp = array(
        'criteria' => array('_id' => $myId),
        'collection' => 'zmUpload',
        'opts' => array("safe" => true),
    );

    $result = $myDal->plugables['plugMdb']->dbDelete($aryDelOp);

    print_r($result);
    echo "\n\n";

    echo "Now try to fetch it with dbGet (skipping cache) and make sure it is gone...\n";

    $result = $myDal->plugables['plugMdb']->dbGet($aryIn);

    print_r($result);

    echo "\n\n";

    echo "If it is gone the force is with you today... if not, well, the DAL pluggable MongoDB class isn't working right...\n";

    echo "\n\nMap Reduce Test...\n\n";

    $myMap = "function() {
        emit(this.role, {count:1});
    }";

    $myReduce = "function(key, values) {
        var result = {count:1};

        values.forEach(function(value) {
            result.count += value.count;

        });
        return result;
    }";

    $result = $myDal->plugables['plugMdb']->doMapReduce($myMap, $myReduce, 'zmUpload', 'zmMapReduceTest', null, array('x' => 12), dalMongo::OUTTYPEREPLACE);

    print_r($result);
    echo "\n\nIf there are no errors... the Map Reduce worked...\n";

    echo "\nGet the output collection of the Map Reduce Test\n\n";

    $aryIn = array(
        'return_type' => 'array',
        'query' => array(),
        'fields' => array(),
        'collection' => 'zmMapReduceTest',
    );

    $result = $myDal->doPluggableFindWithCache('plugMdb', $aryIn, 2);

    print_r($result);

    echo "\n\nTests Complete\n\n";

These tests cover all the basic CRUD operations, using both the direct and Memcache integrated methods,
and - for good measure - performing a map reduce on the MongoDB Collection.

### Implementing a Pluggable

The interface for the Pluggable DAL module is:

    Interface pluggableDB {

        /*
         * Sets the connection information to be used internally on dbConnect.
         *
         * We do this so connections can only be processed AS NEEDED (i.e.,
         * when the DAL doesn't find the needed data in Memcache).
         *
         * Also, internally, the pluggable class can defer connection until it is needed
         * and not simply sit with an open connection waiting for a CRUD operation to be
         * called.
         *
         */
        public function setConnectInfo($connInfo);

        /*
         * Connect to the persistence server.
         *
         */
        public function dbConnect();

        /*
         * Determine if the connection has been made.
         * This is utilized internally in the DAL to auto-connect when doing
         * doPluggableFindWithCache calls.
         *
         */
        public function isConnected();


        /*
         * Save (create OR update) a set of data.
         *
         * @param $saveData Contains all information need to save the data.
         */
        public function dbSave($saveData);

        /*
         * Delete a set of data.
         *
         * @param $deleteData Contains all the information needed to delete the data.
         */
        public function dbDelete($deleteData);

        /*
         * Disconnect from the persistence server.
         *
         */
        public function dbDisconnect();


        /*
         * Fetch a set of data.
         *
         * @param $findData Contains all information needed to get one or many sets of data.
         */
        public function dbGet($findData);

    }

Each method accepts one (and only one) parameter which should (internally) contain all the information
needed to implement that persistance call. In the included *dalMongo* pluggable we used associative
arrays, but objects would have worked just as well.



