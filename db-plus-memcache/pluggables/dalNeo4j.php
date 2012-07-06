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

    /*
     * The Neo4j Connection configuration...
     */
    private $n4jconfig;

    private $lastCuRLRequest;

    private $debug = false;
    private $log_file = "";

    private $aryReqConfig = array(
        "hostname" => "string",
        "protocol" => array('http', 'https'),
        "port" => "number",
        "baseurl" => "string",
    );

    private $arySaveMandatory = array(
        "what",
        "operation",
        "oid",
        "props",
        "relationship_data",
        "node_index_data"
    );
    private $aryDeleteMandatory = array(
        "oid",
        "props",
        "what",
        "scope",
    );
    private $aryGetMandatory = array(
        "oid",
        "query",
        "index",
        "getop",
    );

    CONST HTTPPUT = "PUT";
    CONST HTTPGET = "GET";
    CONST HTTPPOST = "POST";
    CONST HTTPDELETE = "DELETE";

    CONST NEONODE = "node";
    CONST NEORELATIONSHIP = "relationship";

    CONST NEOOPCREATE = "create";
    CONST NEOOPUPDATE = "update";
    CONST NEOOPSETPROP = "setproperty";

    CONST NEOSCOPEPROP = "property";
    CONST NEOSCOPEOBJECT = "object";

    CONST NEOGETOPINDEXFIND = "find_node_by_index";
    CONST NEOGETOPINDEXQUERY = "find_node_by_index_query";
    CONST NEOGETOPNODE = "get_node_by_id";
    CONST NEOGETOPREL = "get_relationship_by_id";
    CONST NEOGETOPNODEREL = "get_node_relationships";
    CONST NEOGETOPNODERELIN = "get_node_relationships_incoming";
    CONST NEOGETOPNODERELOUT = "get_node_relationships_outgoing";
    CONST NEOGETOPNODERELTYPED = "get_node_relationships_typed";
    CONST NEOGETOPCYPHER = "cypher_query";
    CONST NEOGETOPTRAVERSE = "traverse_operation";

    CONST CONTENTTYPEJSON = "application/json";
    CONST ACCEPTJSON = "application/json";


    /*
     * Sets the information that will be needed to connect to Neo4j
     *
     * We need:
     *  Server Host Name
     *  Protocol (http or https)
     *  Server Port
     *  Base URL (/db/data/)
     *
     */
    public function setConnectInfo($connInfo){
        foreach($this->aryReqConfig as $key => $val) {
            if(!array_key_exists($key, $connInfo)) {
                throw new dalNeo4jException("The configuration item: ".$key." was not supplied.");
            } else {
                if(is_array($this->aryReqConfig[$key])) {
                    // contains a list of valid values
                    if(! in_array($connInfo[$key], $this->aryReqConfig[$key])) {
                        throw new dalNeo4jException("The value: ".$connInfo[$key]." for configuration item: ".$key." is invalid.");
                    }
                }
            }
        }

        $this->n4jconfig = $connInfo;

        if(array_key_exists("debug", $connInfo) && array_key_exists("log_file", $connInfo)) {
            $this->debug = $connInfo['debug'];
            $this->log_file = $connInfo['log_file'];
            $this->sendToLog("Debug Logging Initialized.", array());
            echo "Debugging...\n";
            $this->sendToLog("Debug Logging Initialized.", array());
        }

        if($connInfo['do_connection_test']) {
            return $this->testNeo4jConnection();
        }

        return true;
    }


    /*
     * Connect to the persistence server.
     *
     */
    public function dbConnect(){
        /* Nothing to do here since we are hitting a REST API */

        return true;
    }

    /*
     * Determine if the connection has been made.
     * This is utilized internally in the DAL to auto-connect when doing
     * doPluggableFindWithCache calls.
     *
     */
    public function isConnected(){
        /* Nothing to do here since we are hitting a REST API */
        return true;
    }


    /*
     * Save (create OR update) a set of data.
     *
     * @param $saveData Contains all information need to save the data.
     */
    public function dbSave($saveData){
        /*
         * Contains:
         * "what" = "relationship" or "node"
         * "operation" = "create", "update" or "setproperty"
         * "oid" = null for create or the id for update.
         * "props" = null or an associative array of properties for the new thing.
         * "relationship_data" = null or an array containing the relationship type and target URI.
         * "node_index_data" = null or an array containing the information to index(es) the node (index_name, key, value)
         */

        foreach($this->arySaveMandatory as $key => $value) {
            if(!array_key_exists($value, $saveData)) {
                throw new dalNeo4jException("The required dbSave info: ".$value." was not found.");
            }
        }
        /*
         * Create a node with optional index(s)
         */
        if($saveData['what'] == dalNeo4j::NEONODE && $saveData['operation'] == dalNeo4j::NEOOPCREATE) {
            try {
                $ret = $this->makeNeo4jNode($saveData);
            } catch(dalNeo4jException $e) {
                throw $e;
            }
            return $ret;
        }

        /*
         * Update the properties of a node...
         *
         * NOTE: This is idempotent... post this update ONLY those properties specified in this update
         * will exist for the node.
         */
        if($saveData['what'] == dalNeo4j::NEONODE && $saveData['operation'] == dalNeo4j::NEOOPUPDATE) {
            try {
                // Update node properties
                $ret = $this->updateNeo4jNode($saveData);
            } catch (dalNeo4jException $e) {
                throw $e;
            }
            return $ret;
        }

        /*
         * Setting a single property of a node. All other properties remain and are unchanged.
         *
         */
        if($saveData['what'] == dalNeo4j::NEONODE && $saveData['operation'] == dalNeo4j::NEOOPSETPROP) {
            try{
                $ret = $this->setNeo4jNodeProperty($saveData);
            } catch(dalNeo4jException $e) {
                throw $e;
            }
            return $ret;
        }

        /*
         * Create a relationship.
         */
        if($saveData['what'] == dalNeo4j::NEORELATIONSHIP && $saveData['operation'] == dalNeo4j::NEOOPCREATE) {
            try{
                // Create relationship
                $ret = $this->addNeo4jRelationship($saveData);
            } catch (dalNeo4jException $e) {
                throw $e;
            }
            return $ret;
        }

        /*
        * Update the properties of a relationship...
        *
        * NOTE: This is idempotent... post this update ONLY those properties specified in this update
        * will exist for the relationship.
        */
        if($saveData['what'] == dalNeo4j::NEORELATIONSHIP && $saveData['operation'] == dalNeo4j::NEOOPUPDATE) {
            try{
                // Replace all relationship properties with new
                $ret = $this->updateNeo4jRelationship($saveData);
            } catch (dalNeo4jException $e) {
                throw $e;
            }
            return $ret;
        }

        /*
        * Setting a single property of a relationship. All other properties remain and are unchanged.
        *
        */
        if($saveData['what'] == dalNeo4j::NEORELATIONSHIP && $saveData['operation' == dalNeo4j::NEOOPSETPROP]) {
            try{
                $ret = $this->setNeo4jRelationshipProperty($saveData);
            } catch(dalNeo4jException $e) {
                throw $e;
            }
            return $ret;
        }


    }

    /*
     * Delete a set of data.
     *
     * @param $deleteData Contains all the information needed to delete the data.
     */
    public function dbDelete($deleteData){
        /*
         * $deleteData MUST contain
         * "what" = "relationship" or "node" What object type to act on.
         * "scope" = "property" or "object" Deleting one or more properties OR the object (node/relationship) itself.
         * "oid" = The node/relationship ID
         * "props" = An array (associative) of properties to delete for the node/relationship.
         *
         */

        foreach($this->aryDeleteMandatory as $key => $value) {
            if(!array_key_exists($value, $deleteData)) {
                throw new dalNeo4jException("The required dbDelete info: ".$value." was not found.");
            }
        }

        /*
         * Delete the node...
         */
        if($deleteData['what'] == dalNeo4j::NEONODE && $deleteData['scope'] == dalNeo4j::NEOSCOPEOBJECT) {
            $uriPart = "node/".$deleteData['oid'];
            $res = $this->doCurlTransaction(null, $uriPart, dalNeo4j::HTTPDELETE);
            if($res['result'] == 204) {
                return array("result" => "success");
            } else if($res['result'] == 409) {
                return array(
                    "result" => "failed",
                    "reason" => "Node has relationships and can not be deleted.",
                    "response" => $res['response_body'],
                );
            } else {
                throw new dalNeo4jException("Deleting node with id: ".$deleteData['oid']." failed. OUTPUT: ".$res['response_body']);
            }
        }
        /*
         * Delete the relationship...
         */
        if($deleteData['what'] == dalNeo4j::NEORELATIONSHIP && $deleteData['scope'] == dalNeo4j::NEOSCOPEOBJECT) {
            $uriPart = "relationship/".$deleteData['oid'];
            $res = $this->doCurlTransaction(null, $uriPart, dalNeo4j::HTTPDELETE);
            if($res['result'] == 204) {
                return array("result" => "success");
            } else {
                throw new dalNeo4jException("Deleting node with id: ".$deleteData['oid']." failed. OUTPUT: ".$res['response_body']);
            }
        }
        /*
         * Delete a list of node properties.
         */
        if($deleteData['what'] == dalNeo4j::NEONODE && $deleteData['scope'] == dalNeo4j::NEOSCOPEPROP) {
            if(! is_null($deleteData['props'])) {
                foreach($deleteData['props'] as $name => $val) {
                    try{
                        $res = $this->deleteNeo4jNodeProperty($deleteData['oid'], $name);
                    } catch (dalNeo4jException $e) {
                        throw $e;
                    }
                }
            } else {
                throw new dalNeo4jException("Request to delete node properties can not be processed if properties to delete (props) is null.");
            }
        }

        /*
        * Delete a list of relationship properties.
        */
        if($deleteData['what'] == dalNeo4j::NEORELATIONSHIP && $deleteData['scope'] == dalNeo4j::NEOSCOPEPROP) {
            if(! is_null($deleteData['props'])) {
                foreach($deleteData['props'] as $name => $val) {
                    try{
                        $res = $this->deleteNeo4jRelationshipProperty($deleteData['oid'], $name);
                    } catch (dalNeo4jException $e) {
                        throw $e;
                    }
                }
            } else {
                throw new dalNeo4jException("Request to delete node properties can not be processed if properties to delete (props) is null.");
            }
        }
    }

    /*
     * Disconnect from the persistence server.
     *
     */
    public function dbDisconnect(){
        return true;
    }


    /*
     * Fetch a set of data.
     *
     * @param $findData Contains all information needed to get one or many sets of data.
     */
    public function dbGet($findData){
        /*
         * $findData must contain:
         * "getop" one of the NEOGETOP constants defined for this class.
         * "oid" The ID of the object as defined in the "what" parameter.
         * "index" The name of the index to query OR null for non-index "getop" values.
         * "query" Either NULL or the query parameter of the GET. For Example:
         *   NEOGETOPNODERELTYPED would have a query like "RELATED_TO&LIKES"
         *   NEOGETOPCYPHER query would contain the Cypher Query String
         *   NEOGETOPTRAVERSE query would contain the JSON representing the POST body.
         *
         * NOTE: oid is scoped by the type of get being performed. For example:
         * A dalNeo4j::NEOGETOPNODEREL has the oid of a node (because it is a node GET).
         *
         */
        foreach($this->aryGetMandatory as $key => $value) {
            if(!array_key_exists($value, $findData)) {
                throw new dalNeo4jException("The required dbGet info: ".$value." was not found.");
            }
        }

        /*
         * Get node by ID
         */
        if($findData['getop'] == dalNeo4j::NEOGETOPNODE) {
            $ret = $this->getNeo4jNodeById($findData['oid']);
            return $ret;
        }

        /*
         * Get Relationship by ID
         */
        if($findData['getop'] == dalNeo4j::NEOGETOPREL) {
            $ret = $this->getNeo4jRelationshipById($findData['oid']);
            return $ret;
        }

        /*
         * All relationships for a given node.
         */
        if($findData['getop'] == dalNeo4j::NEOGETOPNODEREL) {
            $ret = $this->getAllNodeRelationships($findData['oid']);
            return $ret;
        }

        /*
         * Incoming relationships for a given node
         */
        if($findData['getop'] == dalNeo4j::NEOGETOPNODERELIN) {
            $ret = $this->getAllNodeIncomingRelationships($findData['oid']);
            return $ret;
        }

        /*
         * Outgoing relationships for a given node
         */
        if($findData['getop'] == dalNeo4j::NEOGETOPNODERELOUT) {
            $ret = $this->getAllNodeOutgoingRelationships($findData['oid']);
            return $ret;
        }

        /*
         * Get typed (named) relationships for a node
         */
        if($findData['getop'] == dalNeo4j::NEOGETOPNODERELTYPED) {
            $ret = $this->getAllNodeTypedRelationships($findData['oid'], $findData['query']);
            return $ret;
        }

        /*
         * Index exact match
         */
        if($findData['getop'] == dalNeo4j::NEOGETOPINDEXFIND) {
            if(is_null($findData['index'])) throw new dalNeo4jException("Index name must be specified for an index exact match search.");
            if(!array_key_exists('key', $findData['query']) && !array_key_exists('value', $findData['query'])) {
                throw new dalNeo4jException("For an index exact match get the query (array) must contain both key and value indexes with values.");
            }

            $ret = $this->getNodeByIndexExactMatch($findData['index'], $findData['query']['key'], $findData['query']['value']);
            return $ret;
        }

        /*
         * Index query match
         */

        if($findData['getop'] == dalNeo4j::NEOGETOPINDEXQUERY) {
            if(is_null($findData['index'])) throw new dalNeo4jException("Index name must be specified for an index query search.");
            if(is_null($findData['query'])) throw new dalNeo4jException("Query must be specified for an index query search.");

            $ret = $this->getNodeByIndexQueryMatch($findData['index'], $findData['query']);
            return $ret;
        }

    }

    /*
     * Get a set of nodes using an index query (Lucene by default).
     *
     * @param String $index The index to search against.
     * @param String $query The Lucene (by default) query string.
     */
    private function getNodeByIndexQueryMatch($index, $query) {
        $uriPart = "index/node/".$index."?".rawurlencode($query);
        $res = $this->doCurlTransaction(null, $uriPart, dalNeo4j::HTTPGET);
        if($res['result'] == 200) {
            $aryRet = array(
                "result" => "success",
                "response" => $res['response_body'],
            );
        } else {
            throw new dalNeo4jException("Getting nodes by exact match index search failed. OUTPUT: ".$res['response_body']);
        }
        return $aryRet;
    }

    /*
     * Get all nodes which match (exactly) an index key/value pair.
     *
     * @param String $index The name of the index.
     * @param String $key The name of the index key.
     * @param String $value The value for the index/key pair.
     */
    private function getNodeByIndexExactMatch($index, $key, $value) {
        $uriPart = "index/node/".rawurlencode($index)."/".rawurlencode($key)."/".rawurlencode($value);
        $res = $this->doCurlTransaction(null, $uriPart, dalNeo4j::HTTPGET);
        if($res['result'] == 200) {
            $aryRet = array(
                "result" => "success",
                "response" => $res['response_body'],
            );
        } else {
            throw new dalNeo4jException("Getting nodes by exact match index search failed. OUTPUT: ".$res['response_body']);
        }
        return $aryRet;

    }

    /*
     * Get all typed (named - e.g., RELATED_TO&LIKES )relationships for a given node by ID
     *
     * @param Int $nid The node ID
     * @param String $typeQuery The query (list of types separated by &).
     *
     * @return Mixed Array containing the result and the returned content.
     */
    private function getAllNodeTypedRelationships($nid, $typeQuery) {
        $uriPart = "node/".$nid."/relationships/all/".rawurlencode($typeQuery);
        $res = $this->doCurlTransaction(null, $uriPart, dalNeo4j::HTTPGET);
        if($res['result'] == 200) {
            $aryRet = array(
                "result" => "success",
                "response" => $res['response_body'],
            );
        } else {
            throw new dalNeo4jException("Getting typed relationships for node with id: ".$nid." by ID failed. OUTPUT: ".$res['response_body']);
        }
        return $aryRet;
    }

    /*
    * Get all incoming (follower) relationships for a given node (by id).
    *
    * @param Int $nid The node id.
    *
    * @return Mixed Array containing the result and the returned content.
    */
    private function getAllNodeIncomingRelationships($nid) {
        $uriPart = "node/".$nid."/relationships/in";
        $res = $this->doCurlTransaction(null, $uriPart, dalNeo4j::HTTPGET);
        if($res['result'] == 200) {
            $aryRet = array(
                "result" => "success",
                "response" => $res['response_body'],
            );
        } else {
            throw new dalNeo4jException("Getting incoming relationships for node with id: ".$nid." by ID failed. OUTPUT: ".$res['response_body']);
        }
        return $aryRet;
    }

    /*
    * Get all outgoing (follow) relationships for a given node (by id).
    *
    * @param Int $nid The node id.
    *
    * @return Mixed Array containing the result and the returned content.
    */
    private function getAllNodeOutgoingRelationships($nid) {
        $uriPart = "node/".$nid."/relationships/out";
        $res = $this->doCurlTransaction(null, $uriPart, dalNeo4j::HTTPGET);
        if($res['result'] == 200) {
            $aryRet = array(
                "result" => "success",
                "response" => $res['response_body'],
            );
        } else {
            throw new dalNeo4jException("Getting outgoing relationships for node with id: ".$nid." by ID failed. OUTPUT: ".$res['response_body']);
        }
        return $aryRet;
    }

    /*
     * Get all relationships for a given node (by id).
     *
     * @param Int $nid The node id.
     *
     * @return Mixed Array containing the result and the returned content.
     */
    private function getAllNodeRelationships($nid) {
        $uriPart = "node/".$nid."/relationships/all";
        $res = $this->doCurlTransaction(null, $uriPart, dalNeo4j::HTTPGET);
        if($res['result'] == 200) {
            $aryRet = array(
                "result" => "success",
                "response" => $res['response_body'],
            );
        } else {
            throw new dalNeo4jException("Getting relationships for node with id: ".$nid." by ID failed. OUTPUT: ".$res['response_body']);
        }
        return $aryRet;
    }

    /*
     * Get a relationship by ID.
     *
     * @param Int $rid The relationship id.
     *
     * @return Mixed Array containing the result and the returned content.
     */
    private function getNeo4jRelationshipById($rid){
        $uriPart = "relationship/".$rid;
        $res = $this->doCurlTransaction(null, $uriPart, dalNeo4j::HTTPGET);
        if($res['result'] == 200) {
            $aryRet = array(
                "result" => "success",
                "response" => $res['response_body'],
            );
        } else {
            throw new dalNeo4jException("Getting relationship with id: ".$rid." by ID failed. OUTPUT: ".$res['response_body']);
        }
        return $aryRet;

    }


    /*
     * Get a node by ID.
     *
     * @param Int $nid The node id.
     *
     * @return Mixed Array containing the result and the returned content.
     */
    private function getNeo4jNodeById($nid) {
        $uriPart = "node/".$nid;
        $res = $this->doCurlTransaction(null, $uriPart, dalNeo4j::HTTPGET);
        if($res['result'] == 200) {
            $aryRet = array(
                "result" => "success",
                "response" => $res['response_body'],
            );
        } else {
            throw new dalNeo4jException("Getting node with id: ".$nid." by ID failed. OUTPUT: ".$res['response_body']);
        }
        return $aryRet;
    }

    /*
     * Deletes a single property from a Node
     *
     * @param Int $nodeId The ID of the node the property should be deleted from.
     * @param String $propertyName The name of the property to be deleted from the node.
     *
     * @return Array
     * @throws dalNeo4jException
     */
    private function deleteNeo4jNodeProperty($nodeId, $propertyName) {
        $uriPart = "node/".$nodeId."/properties/".$propertyName;
        $res = $this->doCurlTransaction(null, $uriPart, dalNeo4j::HTTPDELETE);
        if($res['result'] == 204) {
            return array(
                "result" => "success",
            );
        } else {
            throw new dalNeo4jException("Property ".$propertyName." of Node with ID: ".$nodeId." could not be deleted. OUTPUT: ".$res['response_body']);
        }
    }

   /*
    * Deletes a single property from a Relationship
    *
    * @param Int $relationshipId The ID of the relationship the property should be deleted from.
    * @param String $propertyName The name of the property to be deleted from the relationship.
    *
    * @return Array
    * @throws dalNeo4jException
    */
    private function deleteNeo4jRelationshipProperty($relationshipId, $propertyName) {
        $uriPart = "relationship/".$relationshipId."/properties/".$propertyName;
        $res = $this->doCurlTransaction(null, $uriPart, dalNeo4j::HTTPDELETE);
        if($res['result'] == 204) {
            return array(
                "result" => "success",
            );
        } else {
            throw new dalNeo4jException("Property ".$propertyName." of Relationship with ID: ".$relationshipId." could not be deleted. OUTPUT: ".$res['response_body']);
        }
    }

    /*
     * Sets one or more properties on a node.
     * NOTE: NOT idempotent - properties not specifically modified by this method are left as is.
     *
     * The method will iterate through the array of properties found in $saveData['props'] setting
     * each one in turn.
     * In the event of a failure it is possible some of the properties will be set and others will
     * not.
     *
     * @params Mixed $saveData The data send to dbSave
     */
    private function setNeo4jNodeProperty($saveData) {
        $aryRet = array();
        $i = 1;
        foreach($saveData['props'] as $key => $value) {
            $uriPart = "node/".$saveData['oid']."/properties/".$key;
            $res = $this->doCurlTransaction($value, $uriPart, dalNeo4j::HTTPPUT);
            if($res['result'] == 204) {
                $aryRet[$i]['property'] = $key;
                $aryRet[$i]['set_to_value'] = $value;
                $aryRet[$i]['target_node_id'] = $saveData['oid'];
            } else {
                throw new dalNeo4jException("Could not set the property: ".$key." for node with id: ".$saveData['oid']);
            }
            $i = $i + 1;
        }
        return $aryRet;
    }

    /*
     * Sets one or more properties on a relationship.
     * NOTE: NOT idempotent - properties not specifically modified by this method are left as is.
     *
     * The method will iterate through the array of properties found in $saveData['props'] setting
     * each one in turn.
     * In the event of a failure it is possible some of the properties will be set and others will
     * not.
     *
     * @params Mixed $saveData The data send to dbSave
     */
    private function setNeo4jRelationshipProperty($saveData) {
        $aryRet = array();
        $i = 1;
        foreach($saveData['props'] as $key => $value) {
            $relId = $saveData['oid'];
            $uriPart = "relationship/".$relId."/properties/".$key;
            $res = $this->doCurlTransaction($value, $uriPart, dalNeo4j::HTTPPUT);
            if($res['result'] == 204) {
                $aryRet[$i]['property'] = $key;
                $aryRet[$i]['set_to_value'] = $value;
                $aryRet[$i]['target_relationship_id'] = $relId;
            } else {
                throw new dalNeo4jException("Could not set the property: ".$key." for relationship with id: ".$relId);
            }
            $i = $i + 1;
        }
        return $aryRet;
    }

    /*
     * Updates (overwrites) a neo4j relationship's properties. This method is IDEMPOTENT - the relationship
     * will have ONLY the properties specified in this request once the request is complete.
     *
     * @param Mixed $saveData The data supplied to dbSave.
     */
    private function updateNeo4jRelationship($saveData) {
        if(is_null($saveData['relationship_data'])) throw new dalNeo4jException("relationship_data must be specified to update a Relationship.");

        $uriPart = "relationship".$saveData['oid']."/properties";
        if(is_null($saveData['props'])) {
            $aryProps = array();
        } else {
            $aryProps = $saveData['props'];
        }

        $res = $this->doCurlTransaction($aryProps, $uriPart, dalNeo4j::HTTPPUT);

        if($res['result'] == 204) {
            $aryRet = array(
                "result" => "success",
            );
        } else {
            throw new dalNeo4jException("The relationship with ID: ".$saveData['oid']." could not be updated. OUTPUT: ".$res['response_body']);
        }
        return $aryRet;

    }

    /*
     * Adds a Neo4j edge (relationship) between nodes.
     *
     * @param Mixed $saveData The data supplied to dbSave.
     */
    private function addNeo4jRelationship($saveData) {
        /*
         * Since all Neo4j relationships are directional, the
         * oid is the SOURCE (from) and the target_uri is the
         * DESTINATION (to).
         */


        $uriPart = "node/".$saveData['oid']."/relationships";
        $aryDat = array(
            "to" => $saveData['relationship_data']['target_uri'],
            "type" => $saveData['relationship_data']['relationship_name'],
        );
        if(!is_null($saveData['props'])) $aryDat['data'] = $saveData['props'];

        $res = $this->doCurlTransaction($aryDat, $uriPart, dalNeo4j::HTTPPOST);

        if($res['result'] == 201) {
            $aryRet = array(
                "result" => "success",
                "relationship_uri" => $res['headers']['Location'],
                "relationship_id" => $this->extractIdFromURI($res['headers']['Location']),
                "response" => $res['response_body'],
            );
        } else {
            throw new dalNeo4jException("Creating relationship for node id: ".$saveData['oid']." failed. OUTPUT: ".$res['response_body']);
        }

        return $aryRet;

    }

    /*
     * Updates (overwrites) a neo4j node's properties. This method is IDEMPOTENT - the node
     * will have ONLY the properties specified in this request once the request is complete.
     *
     * @param Mixed $saveData The data supplied to dbSave.
     *
     */

    private function updateNeo4jNode($saveData) {
        $uriPart = "node/".$saveData['oid']."properties/";

        $res = $this->doCurlTransaction($saveData['props'], $uriPart, dalNeo4j::HTTPPUT);

        if($res['result'] == 204) {
            $aryRet = array(
                "result" => "success",
            );
        } else {
            throw new dalNeo4jException("Update of node id: ".$saveData['oid']." failed. OUTPUT: ".$res['response_body']);
        }

        return $aryRet;


    }

    /*
     * Creates a node - optionally with an index.
     *
     * @param Mixed $saveData The data supplied to dbSave.
     */
    private function makeNeo4jNode($saveData) {
        $path = "node";
        if(!is_null($saveData['props'])) {

            $vars = $saveData['props'];
        } else {
            $vars = array();
        }
        // Make the request
        $res = $this->doCurlTransaction($vars, $path, dalNeo4j::HTTPPOST);

        /*
        * Check $res for success...
        */

        if($res['result'] == 201) {
            // Good Save... get the resource URL
            $uri = trim($res['headers']['Location']);
        } else {
            throw new dalNeo4jException("Node Creation failed: ".$res['response_body']);
        }

        $index_info = array();
        if(!is_null($saveData['node_index_data'])) {
            // Create the index...
            try{
                foreach($saveData['node_index_data'] as $idx) {
                    $resp = $this->makeNeo4jIndex($uri, $idx['index_name'], $idx['key'], $idx['value']);
                    if($resp) {
                        $index_info[] = array(
                            "index_added" => "true",
                            "index_name" => $idx['index_name'],
                            "key" => $idx['key'],
                            "value" => $idx['value'],
                        );
                    } else {
                        throw new dalNeo4jException("Index Creation failed for node: ".$uri." index: ".$idx['index_name']." OUTPUT: ".$resp['response_body']);
                    }
                }
            } catch (dalNeo4jException $e) {
                throw $e;
            }
        }

        // build the return...
        $aryRet = array(
            "result" => "success",
            "node_uri" => $uri,
            "node_id" => $this->extractIdFromURI($uri),
            "indexes_added" => $index_info,
        );
        return $aryRet;
    }

    /*
     * Create an index on a Node.
     *
     * @param $targetURI String The URI for the node.
     * @param $index String The index name
     * @param $key String The index key
     * @param $value Mixed The index value
     */
    private function makeNeo4jIndex($targetURI, $index, $key, $value) {

        $urlAdd = "index/node/".rawurlencode($index);

        $aryParams = array(
            "uri" => $targetURI,
            "key" => $key,
            "value" => $value,
        );

        $ret = $this->doCurlTransaction($aryParams, $urlAdd, dalNeo4j::HTTPPOST);

        if($ret['result'] == 201) {
            return true;
        } else {
            throw new dalNeo4jException("Index creation failed: ".var_export($ret, true));
        }

    }

    /*
     * This method will hit the Neo4j root to ensure we can talk correctly.
     *
     * @return boolean True if HTTP 200, otherwise false.
     */
    private function testNeo4jConnection() {

        $aryE = array();
        $res = $this->doCurlTransaction($aryE, "");

        if($res['result'] == '200') {
            return array("result" => "pass", "message" => "Neo4j is reachable.", "detail" => $res);
        } else {
            return array("result" => "fail", "detail" => $res);
        }

    }

    /*
     * Processes an arbitrary CuRL transaction against the REST API
     *
     * @param $aryVars array An associative array of variable/values for the request.
     * @param $urlEnd string The resource address (after baseurl defined in the config).
     * @param $type string HTTP Type (PUT, GET, POST and DELETE)
     * @param $content_type string Input content type - for our purposes should be application/json
     * @param $accept_type string Accept content type - for our purposes should be application/json
     *
     */
    private function doCurlTransaction($aryVars, $urlEnd, $type = dalNeo4j::HTTPGET, $content_type = dalNeo4j::CONTENTTYPEJSON, $accept_type = dalNeo4j::ACCEPTJSON) {

        /*
         * Trying via socket.
         */
        /*
        if($type == dalNeo4j::HTTPPUT || $type == dalNeo4j::HTTPPOST || $type == dalNeo4j::HTTPDELETE) {
            $aryGet = array();
            $aryPost = $aryVars;
        } else {
            $aryGet = $aryVars;
            $aryPost = array();
        }

        $res = $this->http_request($type, $this->n4jconfig['hostname'].":7474",
                        $this->n4jconfig['port'], $this->n4jconfig['baseurl'].$urlEnd,
                        $aryGet, $aryPost, array(), array("Content-Type" => 'application/json', "Accept" => "application/json"),
                        10, false, true
        );
        if($res === false) echo "Error on stream...\n";
        if($res === "") echo "Empty response from Neo4j?\n";
        echo $res."\n\n";

        $decodeReturn = $this->parseHeadersFromJSON($res);
        //print_r($decodeReturn);

        if($decodeReturn["http_code"] >= 200 && $decodeReturn["http_code"] < 300) {
            return array("result" => $decodeReturn["http_code"], "curl_info" => array(), "response_body" => $decodeReturn['JSON'], "headers" => $decodeReturn['headers']);
        } else {
            return array("result" => $decodeReturn["http_code"], "response_body" => $decodeReturn['JSON'], "headers" => $decodeReturn['headers']);
        }

        */
        /* below here off if the above section is not commented out */


        /* Create the full URL */
        $reqUrl = $this->n4jconfig['protocol']."://".$this->n4jconfig['hostname'].":".$this->n4jconfig['port'].$this->n4jconfig['baseurl'].$urlEnd;

        $aryHeaders = Array("Content-Type: application/json", "Accept: application/json");
        $curlOpts = array(
            CURLOPT_URL => $reqUrl,
            // CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            // CURLINFO_HEADER_OUT => true,
        );

        switch($type) {
            case dalNeo4j::HTTPDELETE:
                $curlOpts[CURLOPT_CUSTOMREQUEST] = dalNeo4j::HTTPDELETE;
                $curlOpts[CURLOPT_HTTPHEADER] = $aryHeaders;
                break;
            case dalNeo4j::HTTPGET:
                $curlOpts[CURLOPT_CUSTOMREQUEST] = dalNeo4j::HTTPGET;
                if(is_array($aryVars)) $curlOpts[CURLOPT_URL] .= "?".http_build_query($aryVars);
                $curlOpts[CURLOPT_HTTPHEADER] = $aryHeaders;
                break;
            case dalNeo4j::HTTPPOST:
                $curlOpts[CURLOPT_CUSTOMREQUEST] = dalNeo4j::HTTPPOST;
                $curlOpts[CURLOPT_POST] = true;
                $varsStr = $this->encodeData($aryVars);
                $curlOpts[CURLOPT_POSTFIELDS] = $varsStr;
                $aryHeaders[] = 'Content-Length: '.strlen($varsStr);
                $curlOpts[CURLOPT_HTTPHEADER] = $aryHeaders;
                break;
            case dalNeo4j::HTTPPUT:
                $curlOpts[CURLOPT_CUSTOMREQUEST] = dalNeo4j::HTTPPUT;
                $varsStr = $this->encodeData($aryVars);
                $curlOpts[CURLOPT_POSTFIELDS] = $varsStr;
                $aryHeaders[] = 'Content-Length: '.strlen($varsStr);
                $curlOpts[CURLOPT_HTTPHEADER] = $aryHeaders;
                break;
            default:
                throw new dalNeo4jException("Invalid HTTP Request Type: ".$type);
        }


        // print_r($curlOpts);
        // echo "\n\n";
        $ch = curl_init();
        curl_setopt_array($ch, $curlOpts);

        // Save the curl opts in last request in case this fails
        $this->lastCuRLRequest['curl_opts'] = $curlOpts;

        $ret = curl_exec($ch);

        $res = curl_getinfo($ch);

        // Save it in last request
        $this->lastCuRLRequest['curl_info'] = $res;

        $decodeReturn = $this->parseHeadersFromJSON($ret);

        if($res["http_code"] >= 200 && $res["http_code"] < 300) {
            return array("result" => $res["http_code"], "curl_info" => $res, "response_body" => $decodeReturn['JSON'], "headers" => $decodeReturn['headers']);
        } else {
            if($this->debug) $this->sendToLog("Failed REST transaction.", $this->lastCuRLRequest);
            return array("result" => $res["http_code"], "response_body" => $decodeReturn['JSON'], "headers" => $decodeReturn['headers']);
        }

    }
    /*
     * Sends a mixed associative array to the log as JSON.
     */
    private function sendToLog($reason, $aryData) {
        $json = json_encode($aryData);
        $logMsg = "DEBUG - ".date('r')." REASON: ".$reason." JSON: ".$json."\n";
        error_log($logMsg, 3, $this->log_file);
    }

    /**
     * Encode data for transport
     *
     * @param mixed $data
     * @return string
     */
    private function encodeData($data)
    {
        $encoded = '';
        if (!is_scalar($data)) {
            if ($data) {
                $keys = array_keys($data);
                $nonNumeric = array_filter($keys, function ($var){
                    return !is_int($var);
                });
                if ($nonNumeric) {
                    $data = (object)$data;
                }
            } else {
                $data = (object)$data;
            }
        }

        $encoded = json_encode($data);
        return $encoded;
    }


    /*
     * Parses the headers in teh CuRL output from the JSON body return.
     *
     * Needed because we require the Location: header - it is the resource URI in the case
     * of the creation of a node.
     *
     * @param String $curlout Raw CuRL return.
     */
    private function parseHeadersFromJSON($curlout) {
        $aryParts = explode("\n", $curlout);
        $restIsJson = false;
        $jsonStr = "";
        $httpCode = 0;
        $aryHeaders = array();
        foreach($aryParts as $part) {
            $part = trim($part);
            if(!$restIsJson) {
                if($part == "" && $restIsJson == false) {
                } else if((substr($part, 0, 4) === "HTTP")) {
                    $httpCodeParts = explode(" ", $part);
                    $httpCode = $httpCodeParts[1];
                } else if($part == "{" || $part == "<html>" || $part == "[ {") {
                    $restIsJson = true;
                } else {
                    $arySplitHeader = explode(":", $part, 2);
                    if(count($arySplitHeader) == 2) {
                        $aryHeaders[$arySplitHeader[0]] = $arySplitHeader[1];
                    } else {
                        $aryHeaders[$arySplitHeader[0]] = "";
                    }
                }
            }

            if($restIsJson) {
                $jsonStr .= $part;
            }

        }

        return(array("JSON" => $jsonStr, "headers" => $aryHeaders, "http_code" => $httpCode));
    }

    /*
     * Extracts the ID (node or relationship) from the URI.
     *
     * @param String $uri The URI to extract the ID from.
     *
     * @return String The ID extracted from the URI.
     */
    private function extractIdFromURI($uri) {
        $parts = explode("/", $uri);
        $oid = $parts[(count($parts) - 1)];

        return $oid;
    }



}

class dalNeo4jException extends Exception{};
