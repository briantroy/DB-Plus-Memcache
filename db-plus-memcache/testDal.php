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
$aryIn = array(
    'return_type' => 'array',
    'query' => array('level' => 'error'),
    'fields' => array(),
    'collection' => 'zmUpload',
);

$result = $myDal->plugables['plugMdb']->dbGet($aryIn);
print_r($result);

echo "\n\n";
echo "Now with caching...\n";

$result = $myDal->doPluggableFindWithCache('plugMdb', $aryIn, 10);

echo "Did we get it from cache? -> ".$result['is_from_cache']."\n";

echo "\n\n";
echo "Now it should come FROM cache...\n";

$result = $myDal->doPluggableFindWithCache('plugMdb', $aryIn, 10);

echo "From Cache -> ".$result['is_from_cache']."\n\n";

exit();

/*
 * Prepared Statements - perferd method.
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


