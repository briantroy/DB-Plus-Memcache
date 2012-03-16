# DB-Plus-Memcache

DB Plus Memcache is a RDBMS (via PHP PDO) independent PHP Data Access Layer class that makes is easy
(really, really easy) to build your PHP applications with Memcache support by default.

This has been extensively tested against MySQL - Results on other DBs may vary.

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

