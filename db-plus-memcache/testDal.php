<?php
/* 
 * This is a basic test script for the DAL
 *
 * It will connect to the Primary DB and run a few
 * queries.
 *
 * @author: Brian Roy
 * @date: 01/28/2011
 *
 */

require_once("dal-class.php");

$myDal = new dal();



// Turn on debugging...
$myDal->doDebug(true);

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

exit();

/*
 * Prepared Statements - preferred method.
 */
try {
    
    $myDal->dbConnect("primary");
    
    $query = "select :this+:that as mysum";
    
    $prepared = $myDal->prepareStatement($query, dal::PREPARE_SELECT);
    
    
    $input = array(
        ":this" => array("value" => 1, "data_type" => PDO::PARAM_INT),
        ":that" => array("value" => 14, "data_type" => PDO::PARAM_INT),

    );

    $result = $myDal->executeStatement($prepared, $input);

    $array_result = $myDal->fetchPreparedResult($prepared, dal::RETURN_JSON, true, 30);

    print_r($array_result);

} catch(dalException $e) {
    echo "Throwing a dalException exception!\n";
    print_r($e->dbMessages);
    echo "\n".$e->getMessage()."\n";
}


// Do a regular query and return JSON

try {
    $myDal->dbConnect("primary");
    $durr = 120;
    $strSql = "select 1+1 as mysum from dual";

    $json = $myDal->QueryInCache($strSql, $durr, dal::RETURN_JSON);

    echo $json."\n\n";
    echo "Run this test again within ".$durr." seconds to get result from cache.\n";
} catch(dalException $e) {
    print_r($e->dbMessages);
}


