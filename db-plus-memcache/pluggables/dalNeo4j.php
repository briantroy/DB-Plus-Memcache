<?php
/**
 * Base testing pluggable implementing Neo4j's REST API.
 *
 * Will focus on basic CRUD Ops and adding nodes/relationships to indexes.
 *
 * @author: Brian Roy
 * @date: 6/25/2012
 */
class dalNeo4j implements pluggableDB
{

    public function setConnectInfo($connInfo){

    }


    /*
     * Connect to the persistence server.
     *
     */
    public function dbConnect(){

    }

    /*
     * Determine if the connection has been made.
     * This is utilized internally in the DAL to auto-connect when doing
     * doPluggableFindWithCache calls.
     *
     */
    public function isConnected(){

    }


    /*
     * Save (create OR update) a set of data.
     *
     * @param $saveData Contains all information need to save the data.
     */
    public function dbSave($saveData){

    }

    /*
     * Delete a set of data.
     *
     * @param $deleteData Contains all the information needed to delete the data.
     */
    public function dbDelete($deleteData){

    }

    /*
     * Disconnect from the persistence server.
     *
     */
    public function dbDisconnect(){

    }


    /*
     * Fetch a set of data.
     *
     * @param $findData Contains all information needed to get one or many sets of data.
     */
    public function dbGet($findData){
        
    }

}
