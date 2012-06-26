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

    private $aryReqConfig = array(
        "hostname" => "string",
        "protocol" => array('http', 'https'),
        "port" => "number",
        "baseurl" => "string",
    );

    private $arySaveMandatory = array(
        "what",
        "operation",
        "props",
        "relationship_data",
        "node_index_data"
    );

    CONST HTTPPUT = "PUT";
    CONST HTTPGET = "GET";
    CONST HTTPPOST = "POST";
    CONST HTTPDELETE = "DELETE";

    CONST NEONODE = "node";
    CONST NEORELATIONSHIP = "relationship";

    CONST NEOOPCREATE = "create";
    CONST NEOOPUPDATE = "update";

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
        $blnIsGood = true;
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
         * "operation" = "create" or "update"
         * "props" = null or an associative array of properties for the new thing.
         * "relationship_data" = null or an array containing the relationship type and target URI.
         * "node_index_data" = null or the information to index the node (index name, key, value)
         */

        foreach($this->arySaveMandatory as $key => $value) {
            if(!in_array($key, $saveData)) {
                throw new dalNeo4jException("The requried dbSave info: ".$key." was not found.");
            }
        }

        if($saveData['what'] == dalNeo4j::NEONODE) {
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

            if(!is_null($saveData['node_index_data'])) {
                // Add the index...
                if($res['result'] == 201) {
                    // Good Save... get the resource URL
                    $uri = $res['headers']['Location'];
                } else {
                    throw new dalNeo4jException("Node Creation failed: ".$res['response_body']);
                }
                // Create the index...
                try{
                    $resp = $this->makeNeo4jIndex($uri, $saveData['node_index_data']['index_name'], $saveData['node_index_data']['key'],$saveData['node_index_data']['value']);
                } catch (dalNeo4jException $e) {
                    throw $e;
                }
            }
            // build the return...
            $aryRet = array(
                "result" => "success",
                "node_uri" => $uri,
            );
            return $aryRet;
        }


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
        return true;
    }


    /*
     * Fetch a set of data.
     *
     * @param $findData Contains all information needed to get one or many sets of data.
     */
    public function dbGet($findData){

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

        $urlAdd = "index/node/".$index;

        $aryParams = array(
            "to" => $targetURI,
            "key" => $key,
            "value" => $value,
        );

        $ret = $this->doCurlTransaction($aryParams, $urlAdd, dalNeo4j::HTTPPOST);

        if($ret['result'] == 201) return true;

        throw new dalNeo4jException("Index creation failed: ".$ret['response_body']);

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

        /* Create the full URL */
        $reqUrl = $this->n4jconfig['protocol']."://".$this->n4jconfig['hostname'].":".$this->n4jconfig['port'].$this->n4jconfig['baseurl'].$urlEnd;

        $curlOpts = array(
            CURLOPT_URL => $reqUrl,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLINFO_HEADER_OUT => true
        );

        switch($type) {
            case dalNeo4j::HTTPDELETE:
                $curlOpts[CURLOPT_CUSTOMREQUEST] = dalNeo4j::HTTPDELETE;
                break;
            case dalNeo4j::HTTPGET:
                $curlOpts[CURLOPT_HTTPGET] = true;
                $curlOpts[CURLOPT_URL] .= "?".http_build_query($aryVars);
                break;
            case dalNeo4j::HTTPPOST:
                $curlOpts[CURLOPT_POST] = true;
                $curlOpts[CURLOPT_POSTFIELDS] = $aryVars;
                break;
            case dalNeo4j::HTTPPUT:
                $curlOpts[CURLOPT_PUT] = true;
                $curlOpts[CURLOPT_POSTFIELDS] = http_build_query($aryVars);
                break;
            default:
                throw new dalNeo4jException("Invalid HTTP Request Type: ".$type);
        }

        $ch = curl_init();
        curl_setopt_array($ch, $curlOpts);

        $ret = curl_exec($ch);

        $res = curl_getinfo($ch);

        $decodeReturn = $this->parseHeadersFromJSON($ret);

        if($res["http_code"] >= 200 && $res["http_code"] < 300) {
            return array("result" => $res["http_code"], "curl_info" => $res, "response_body" => $decodeReturn['JSON'], "headers" => $decodeReturn['headers']);
        } else {
            return array("result" => $res["http_code"], "response_body" => $decodeReturn['JSON'], "headers" => $decodeReturn['headers']);
        }

    }

    /*
     * Parses the headers in teh CuRL output from the JSON body return.
     *
     * Needed because we require the Location: header - it is the resoruce URI in the case
     * of the creation of a node.
     *
     * @param String $curlout Raw CuRL return.
     */
    private function parseHeadersFromJSON($curlout) {
        $aryParts = explode("\n", $curlout);
        $restIsJson = false;
        $jsonStr = "";
        $aryHeaders = array();
        foreach($aryParts as $part) {
            $part = trim($part);
            if(!$restIsJson) {
                if($part == "" && $restIsJson == false) {
                } else if((substr($part, 0, 4) === "HTTP")) {
                } else if($part == "{") {
                    $restIsJson = true;
                } else {
                    $arySplitHeader = explode(":", $part, 2);
                    $aryHeaders[$arySplitHeader[0]] = $arySplitHeader[1];
                }
            }

            if($restIsJson) {
                $jsonStr .= $part;
            }

        }

        return(array("JSON" => $jsonStr, "headers" => $aryHeaders));
    }

}

class dalNeo4jException extends Exception{};
