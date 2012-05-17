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

    private $myConfig;

    private $mdb;

    private $db;

    const DEFAULT_PORT = 27017;

    public function dbConnect($connInfo) {
        if(array_key_exists("connectTo", $connInfo) && array_key_exists("configs", $connInfo)) {
            $connKey = $connInfo['connectTo'];
            $config = $connInfo['configs'];
            if(!array_key_exists($connKey, $config)) {
                throw new dalMongoException("Invalid Configuration, the connection: ".$connKey." is not defined.");
            }
        } else {
            throw new dalMongoException("Invalid Connection Information supplied in dbConnect.");
        }

        $this->myConfig = $config[$connKey];

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

    public function dbSave($saveData) {

    }

    public function dbDelete($deleteData) {

    }

    public function dbDisconnect() {

    }

    public function dbGet($findData) {

    }


}

class dalMongoException extends Exception{};
