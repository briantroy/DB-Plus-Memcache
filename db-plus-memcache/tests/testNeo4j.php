<?php
/**
 * This is a test script for the new Neo4j pluggable.
 *
 * @author: Brian Roy
 * @date: 6/27/2012
 *
 */

require_once("../dal-class.php");

$myDal = new dal();

// Turn on debugging...
$myDal->doDebug(true);

/*
 * Test Neo4j Pluggable
 */


/*
 *  Test setting connection info and - using do_connection_test => true
 *  verify that we can query the root of the Neo4j REST api.
 *
*/
$neoConnInfo = array(
    "hostname" => "localhost",
    "port" => 7474,
    "protocol" => "http",
    "baseurl" => "/db/data/",
    "do_connection_test" => true,
    "debug" => true,
    "neo4j_debug.log",
);

$ret = $myDal->plugables['plugNeo4j']->setConnectInfo($neoConnInfo);
echo "Connection setup and test: \n";
print_r($ret);
echo "\n\n";

/*
 * Cypher Query
 */


$strQuery = "start inst=node:nodeType(type = {type}) match inst-[:PRIMARY_STATE]->state, inst-[:HAS_ER_VISITS]->ernum where state.value = {state} and ernum.matter_value <> 'NA' return inst.name, ernum.matter_value, state.value order by inst.name";

$aryQ['query'] = $strQuery;
$aryQ['params'] = array("type" => "INSTITUTION", "state" => "CA");
$aryFindNode = array(
    "getop" => dalNeo4j::NEOGETOPCYPHER,
    "oid" => null,
    "index" => null,
    "query" => $aryQ,
);
$mNode = $myDal->plugables['plugNeo4j']->dbGet($aryFindNode);

$rsltJson = json_decode($mNode['response'], true);

print_r($rsltJson);

echo "\n\n\n";

/*
 * Create a node...
 */

$aryIn = array(
    "what" => dalNeo4j::NEONODE,
    "operation" => dalNeo4j::NEOOPCREATE,
    "oid" => null,
    "props" => array("type" => "user", "name" => "Brian Roy"),
    "relationship_data" => null,
    "node_index_data" => array(
        0 => array(
            "index_name" => "nodeType",
            "key" => "type",
            "value" => "user",
        )
    ),

    );

$ret = $myDal->plugables['plugNeo4j']->dbSave($aryIn);
echo "Node 1: \n";
print_r($ret);
echo "\n\n";

$node1URI = $ret['node_uri'];
$node1ID = $ret['node_id'];

/*
 * Add another node and create a relationship between it and the one above
 */

$aryIn = array(
    "what" => dalNeo4j::NEONODE,
    "operation" => dalNeo4j::NEOOPCREATE,
    "oid" => null,
    "props" => array("type" => "user", "name" => "Joe James"),
    "relationship_data" => null,
    "node_index_data" => array(
        0 => array(
            "index_name" => "nodeType",
            "key" => "type",
            "value" => "user",
        )
    ),

);

$ret = $myDal->plugables['plugNeo4j']->dbSave($aryIn);

echo "Node 2: \n";
print_r($ret);
echo "\n\n";

$node2URI = $ret['node_uri'];
$node2ID = $ret['node_id'];

// Now relate them
$aryRel = array(
    "what" => dalNeo4j::NEORELATIONSHIP,
    "operation" => dalNeo4j::NEOOPCREATE,
    "oid" => $node1ID,
    "props" => array("label" => "friends"),
    "relationship_data" => array(
        "target_uri" => $node2URI,
        "relationship_name" => "FRIEND",
    ),
    "node_index_data" => null,
);

$ret = $myDal->plugables['plugNeo4j']->dbSave($aryRel);
echo "Relationship Creation: \n";
print_r($ret);
echo "\n\n";

$relationID = $ret['relationship_id'];


/*
 * Update the first node with a new property
 */

$aryUpdNode = array(
    "what" => dalNeo4j::NEONODE,
    "operation" => dalNeo4j::NEOOPSETPROP,
    "oid" => $node1ID,
    "props" => array("gender" => "male"),
    "relationship_data" => null,
    "node_index_data" => null,
);

$ret4 = $myDal->plugables['plugNeo4j']->dbSave($aryUpdNode);
echo "Property Setting: \n";
print_r($ret4);
echo "\n\n";

/*
 * delete the relationship
 */
$aryDelRel = array(
    "what" => dalNeo4j::NEORELATIONSHIP,
    "scope" => dalNeo4j::NEOSCOPEOBJECT,
    "oid" => $relationID,
    "props" => null,
);

$ret5 = $myDal->plugables['plugNeo4j']->dbDelete($aryDelRel);
echo "Deleting a Relationship: \n";
print_r($ret5);
echo "\n\n";


/*
 * Now delete the two nodes
 */

$aryDelNode = array(
    "what" => dalNeo4j::NEONODE,
    "scope" => dalNeo4j::NEOSCOPEOBJECT,
    "oid" => $node1ID,
    "props" => null,
);

$ret6 = $myDal->plugables['plugNeo4j']->dbDelete($aryDelNode);
echo "Deleting a Node: \n";
print_r($ret6);
echo "\n\n";

$aryDelNode = array(
    "what" => dalNeo4j::NEONODE,
    "scope" => dalNeo4j::NEOSCOPEOBJECT,
    "oid" => $node2ID,
    "props" => null,
);

$ret7 = $myDal->plugables['plugNeo4j']->dbDelete($aryDelNode);
echo "Deleting Last Node: \n";
print_r($ret7);
echo "\n\n";
?>