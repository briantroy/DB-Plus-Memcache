<?php
/**
 * @author: Brian Roy
 * @Date: 5/17/12
 * @Time: 11:11 AM
 *
 * The dalMongo is a pluggable persistence class which implements the
 * ability to use MongoDB within the DAL.
 *
 */
class dalMongo implements pluggableDB {

    private $connectInfo;
    private $myConfig = null;

    private $mdb = null;

    private $db;

    const DOINSERT = 'insert';
    const DOUPDATE = 'update';
    const RETURNCURSOR = 'cursor';
    const RETURNARRAY = 'array';

    const OUTTYPEREPLACE = 'replace';
    const OUTTYPEMERGE = 'merge';
    const OUTTYPEREDUCE = 'reduce';

    const DEFAULT_PORT = 27017;

    /*
     * Sets the connection data that will be used to connect to the DB
     *
     * @param $connData The data needed to connect to the DB.
     *
     */
    public function setConnectInfo($connData) {
        $this->connectInfo = $connData;
        if(array_key_exists("connectTo", $connData) && array_key_exists("configs", $connData)) {
            $connKey = $connData['connectTo'];
            $config = $connData['configs'];
            if(!array_key_exists($connKey, $config)) {
                throw new dalMongoException("Invalid Configuration, the connection: ".$connKey." is not defined.");
            }
        } else {
            throw new dalMongoException("Invalid Connection Information supplied in setConnectionInfo.");
        }

        $this->myConfig = $config[$connKey];

        return true;
    }

    /*
     * Method isConnected returns the current state of the db connection
     *
     * @return boolean True if connected, false if not.
     */
    public function isConnected() {
        if(is_null($this->mdb)) return false;
        return true;
    }

    public function dbConnect() {
        // Make sure we have configuration
        if(is_null($this->myConfig))
            throw new dalMongoException("No connection information has been supplied. Call setConnectInfo first.");
        // Set up the connection

        if(!array_key_exists("useAuth", $this->myConfig))
            throw new dalMongoException("Invalid Configuration, useAuth must be specified (true or false).");

        if(!array_key_exists("host", $this->myConfig))
            throw new dalMongoException("Invalid Configuration, host must be specified.");

        if(!array_key_exists("port", $this->myConfig)) {
            $tPort = dalMongo::DEFAULT_PORT;
        } else {
            $tPort = $this->myConfig['port'];
        }

        if(!array_key_exists("db", $this->myConfig))
            throw new dalMongoException("Invalid Configuration, db must be specified.");

        if($this->myConfig['useAuth']) {
            // Must have username, password and db
            if(!array_key_exists("username", $this->myConfig))
                throw new dalMongoException("Invalid Configuration, username must be specified when useAuth is true.");
            if(!array_key_exists("password", $this->myConfig))
                throw new dalMongoException("Invalid Configuration, password must be specified when useAuth is true.");

            // Good config... build the connection string
            $connString = "mongodb://".$this->myConfig['username'].":".$this->myConfig['password']."@".
                $this->myConfig['host'].":".$this->myConfig['port']."/".$this->myConfig['db'];
        } else {
            // Good config... build the connection string
            $connString = "mongodb://".$this->myConfig['host'].":".$this->myConfig['port']."/".$this->myConfig['db'];
        }

        try {
            if(array_key_exists('replicaSet', $this->myConfig)) {
                // Connect to the replica set
                $this->mdb = new Mongo($connString, array("replicaSet" => $this->myConfig['replicaSet']));
            } else {
                $this->mdb = new Mongo($connString);
            }
            // Set the DB
            $tDb = $this->myConfig['db'];
            $this->db = $this->mdb->$tDb;

        } catch (MongoConnectionException $e) {
            throw new dalMongoException("MongoConnectionException encountered with message: ".$e->getMessage());
        }

        return true;
    }

    /*
     * Save some data to MongoDB.
     * $saveData array Format:
     * 'opts' = options array as defined in: http://www.php.net/manual/en/mongocollection.update.php. Simplest form is array('safe' => true);
     * 'operation' = insert or update
     * 'document' = The document data for insert or update.
     * 'criteria' = The criteria to use to determine which records to update defined in: http://www.php.net/manual/en/mongo.updates.php. Optional - not checked for inserts.
     * 'collection' = The collection to perform the save (insert/update) on.
     *
     * @param $saveData Array The information needed to execute the MongoDB save (update/insert).
     *
     * @returns The result of the save (insert/update).
     * @throws dalMongoException
     *
     */
    public function dbSave($saveData) {

        // Connect if needed
        if(!$this->isConnected()) $this->dbConnect();

        if(!array_key_exists('operation', $saveData)) throw new dalMongoException("The operation must be specified, valid operations are insert and update.");

        $op = $saveData['operation'];
        if(!($op == dalMongo::DOINSERT || $op == dalMongo::DOUPDATE))
            throw new dalMongoException("The supplied operation type: ".$op." is not supported.\n");

        if(!array_key_exists('document', $saveData)) throw new dalMongoException("The document array is required.");
        if(!array_key_exists('opts', $saveData)) throw new dalMongoException("The opts array is required.");
        if(!array_key_exists('collection', $saveData)) throw new dalMongoException("The collection name is required.");

        $collection = $this->getMongoCollection($saveData['collection']);

        if($op == dalMongo::DOUPDATE) {
            if(!array_key_exists('criteria',$saveData)) throw new dalMongoException("The criteria array is required for updates.");
            try {
                $result = $collection->update($saveData['criteria'], $saveData['document'], $saveData['opts']);
            } catch(MongoCursorException $ec) {
                throw new dalMongoException("MongoCursorException: ".$ec->getMessage());
            } catch (MongoCursorTimeoutException $ect) {
                throw new dalMongoException("MongoCursorTimeoutException: ".$ect->getMessage());
            }

            return $result;
        }

        if($op == dalMongo::DOINSERT) {
            try {
                $result = $collection->insert($saveData['document'], $saveData['opts']);
            } catch(MongoCursorException $ec) {
                throw new dalMongoException("MongoCursorException: ".$ec->getMessage());
            } catch (MongoCursorTimeoutException $ect) {
                throw new dalMongoException("MongoCursorTimeoutException: ".$ect->getMessage());
            }
            return $result;
        }


    }

    /*
     * Delete some data from MongoDB.
     * $deleteData array Format:
     * 'opts' = options array as defined in: http://www.php.net/manual/en/mongocollection.remove.php. Simplest form is array('safe' => true);
     * 'criteria' = The criteria to use to determine which records to delete defined in: http://www.php.net/manual/en/mongocollection.remove.php.
     * 'collection' = The collection to perform the delete on.
     *
     * @param $saveData Array The information needed to execute the MongoDB save (update/insert).
     * @returns The result of the delete.
     * @throws dalMongoException
     */
    public function dbDelete($deleteData) {

        if(!array_key_exists('opts', $deleteData)) throw new dalMongoException("The opts array is required.");
        if(!array_key_exists('criteria', $deleteData)) throw new dalMongoException("The criteria array is required.");
        if(!array_key_exists('collection', $deleteData)) throw new dalMongoException("The collection name is required.");

        // Connect if needed
        if(!$this->isConnected()) $this->dbConnect();

        $collection = $this->getMongoCollection($deleteData['collection']);

        try{
            $result = $collection->remove($deleteData['criteria'], $deleteData['opts']);
        } catch(MongoCursorException $ec) {
            throw new dalMongoException("MongoCursorException: ".$ec->getMessage());
        } catch (MongoCursorTimeoutException $ect) {
            throw new dalMongoException("MongoCursorTimeoutException: ".$ect->getMessage());
        }
        return $result;
    }

    /*
     * Disconnect from Mongo
     * NOTE - this is not **really** needed after Mongo 1.2.0, however, it is provided
     * to enable specific use cases that may WANT a disconnect.
     *
     * @return Boolean The result of the close operation.
     */
    public function dbDisconnect() {
        $ret = $this->mdb->close();
        $this->mdb = null;
        return $ret;
    }

    /*
     * Do a MongoDB query and return the appropriate results.
     * $findData array Format:
     * return_type = cursor or array. If it is cursor it cannot be cached.
     * collection = The name of the collection on which to query
     * query = array of query search fields as defined at: http://www.php.net/manual/en/mongocollection.find.php
     * fields = array of fields to return as defined at: http://www.php.net/manual/en/mongocollection.find.php
     *
     * @param $findData Array The information needed to execute (and return) the MongoDB query.
     *
     * @returns The result of the query either as a MongoCursor object or an array.
     * @throws dalMongoException
     */
    public function dbGet($findData) {

        $blnNoFields = false;

        // Connect if needed
        if(!$this->isConnected()) $this->dbConnect();

        if(array_key_exists('collection', $findData)) {
            $collection = $this->getMongoCollection($findData['collection']);
        } else {
            throw new dalMongoException("The collection name must be supplied.");
        }

        if(!array_key_exists('query', $findData)) throw new dalMongoException("The query array must be specified - empty to return all documents in the collection");
        if(!array_key_exists('fields', $findData)) throw new dalMongoException("The fields array must be specified, if empty all fields will be returned.");
        if(!array_key_exists('return_type', $findData)) throw new dalMongoException("The return_type must be specified, valid values are 'cursor' or 'array'.");

        if(count($findData['fields']) == 0) $blnNoFields = true;


        if($blnNoFields) {
            $mCurr = $collection->find($findData['query']);
        } else {
            $mCurr = $collection->find($findData['query'], $findData['fields']);
        }

        if($findData['return_type'] == dalMongo::RETURNCURSOR) {
            return $mCurr;
        } else if($findData['return_type'] == dalMongo::RETURNARRAY) {
            return iterator_to_array($mCurr);
        } else {
            throw new dalMongoException("The return_type specified (".$findData['return_type']." is not supported. Valid types are 'cursor' and 'array'.");
        }

    }

    /*
     * Method doMapReduce executes a map reduce on the MongoDB Replica Set (or server).
     *
     * See: http://php.net/manual/en/mongodb.command.php
     *
     * @todo Test this. It should work as is but I'm not 100% confident.
     *
     * @param
     */
    public function doMapReduce($mapFunc, $reduceFunc, $inCollection, $outCollection, $finalize = null, $query = null, $outType = dalMongo::OUTTYPEREPLACE) {
        // Connect if needed
        if(!$this->isConnected()) $this->dbConnect();

        $tMap = new MongoCode($mapFunc);
        $tReduce = new MongoCode($reduceFunc);
        if(!is_null($finalize)) $tFinalize = new MongoCode($finalize);

        if($outType !== dalMongo::OUTTYPEREPLACE) {
            $outAry = array($outType => $outCollection);
        } else {
            $outAry = $outCollection;
        }

        // Build the Command Array

        $aryCmd = array(
            'mapreduce' => $inCollection,
            'map' => $tMap,
            'reduce' => $tReduce,
            'out' => $outAry,
        );

        if(!is_null($query)) $aryCmd['query'] = $query;
        if(!is_null($finalize)) $aryCmd['finalize'] = $tFinalize;

        // Now run it.

        $mrResult = $this->db->command($aryCmd);
        return $mrResult;


    }

    /*
     * Private method getMongoCollection gets an MongoCollection object instance
     * using the supplied collection name.
     *
     * @params $collectionName String The name of the collection.
     *
     * @returns MongoCollection
     * @throws dalMongoException
     */
    private function getMongoCollection($collectionName) {
        try {
            $collection = $this->db->$collectionName;
            return $collection;
        } catch (Exception $e){
            throw new dalMongoException($e->getMessage());
        }
    }


}

class dalMongoException extends Exception{};
