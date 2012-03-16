<?php
	
// This included file contains the configuraiton information needed to connect to the database.
require_once("dal-conf.php");




/*	The dal class does the work of interacting with an RDBMS (via PHP PDO) and
        memcache.
        Previously cached results are returned from memcache, new results are
        cached for the period of time specified in the original request.

        The goal of this implementation was to provide a single mechanism which
        transparently unifed the DB, Memcache and Cassandra.
        NOTE: Cassandra has been removed from this implementation due to changes in
        cassandra's Thrift API.

        The resultset is serialized into an associative array for easy caching in 
        memcache (NOTE: Memcache only accepts serialiable objects!!!!)

        An excption of type dalException is thrown on error.

 *
 * Need to add support for:
 *  Prepared Statements **DONE 01/29/2011**
 *  Files (from a file system)
 *
 * @author: Brian Roy
 * @date: 01/28/2011
 *
 * Adpated from justSignal's RDBMS/Memcache/Cassandra DAL
 * 

*/
	
	class dal {

		private $dbLink = false; // Database connection used internally
		
		private $modsAllowed = true; // If this DB Connection allows modification (create/update)

        private $memc = false; // Using Memcache or not

        private $debug = false; // Debugging

        private $aryDSN; // DSN for DB connections

        private $aryMemC;

        private $aryStatement;

        private $whichConn;

        private $transactionInProgress = false;

        // Return Format Types
        const RETURN_ASSOC = 1;
        const RETURN_JSON = 2;
        const RETURN_XML = 3;

        // Query Types for Prepared Statments
        const PREPARE_SELECT = 1;
        const PREPARE_INSERT = 2;
        const PREPARE_UPDATE = 3;
        const PREPARE_DELETE = 4;

        public function __construct() {
            if($this->debug) $this->sendToLog("In Constructor...", "DEBUG");
            $this->aryDSN = dalConfig::$aryDalDSNPool;
            $this->aryMemC = dalConfig::$aryDalMemC;
        }

		public function keepAlive() {
			/* 	This function will run an inocuous query 
					to keep the connection from timing out.
			*/
			
			$strSQL = "select now()";
			$rslt = $this->DoQuery($strSQL, "select");
			unset($rslt);
			return true;
		}

        public function doDebug($debugState) {
            $this->debug = $debugState;
        }
		
		public function quoteString($strS) {
                    $this->internalDBConnect();
                    return $this->dbLink->quote($strS);
                    $this->terminateDBConnection();
		}
		
		public function startTransaction() {
                        $this->internalDBConnect();
			$this->dbLink->beginTransaction();
                        $this->transactionInProgress = true;
			return true;
		}
		
		public function commitTransaction() {
			$this->dbLink->commit();
                        $this->transactionInProgress = false;
                        $this->terminateDBConnection();
			return true;
		}
		
		public function rollbackTransaction() {
			$this->dbLink->rollBack();
                        $this->transactionInProgress = false;
                        $this->terminateDBConnection();
			return true;
		}

        private function sendToLog($msg, $kind) {
            $strMsg = date('r')." ".$kind.": ".$msg."\n";
            error_log($strMsg, 3, dalConfig::$DALLOG);
        }

        /*
         * Connect to our database. The db "name" provided must exist
         * in the DSN array found in dal-conf.php.
         * Note - We don't actually "connect" here... because we don't want to if
         * the result we need is in cache. Actual connection is done in
         * the private internalDBConnect method.
         *
         * @param $db string The name of the DB to connect to.
         * @throws dalException
         */
		public function dbConnect($db = "primary") {
            if (array_key_exists($db, $this->aryDSN)) {

                $this->whichConn = $db;
                return array("result" => "200");

            } else {
                $aryRslt = array("result" => "400", "error" => array("That database is not in the dal_config file."));
                throw new dalException("No Such Database", $aryRslt, 400);
            }
		}

        /*
         * Actually connect to the DB as needed internally.
         */
        private function internalDBConnect() {
            $db = $this->whichConn;
            if($this->dbLink) return array("result" => "200");
            if (array_key_exists($db, $this->aryDSN)) {
                $options = array(
                    PDO::ATTR_PERSISTENT => $this->aryDSN[$db]['persistent'],
                    PDO::ATTR_ERRMODE    => PDO::ERRMODE_EXCEPTION
                );
                try {
                        $this->dbLink = new PDO($this->aryDSN[$db]['dsn'], $this->aryDSN[$db]['dbuser'], $this->aryDSN[$db]['dbpwd'], $options);
                        $rsltArray['result'] = "200";
                        return $rsltArray;
                } catch (PDOException $e) {
                        $rsltArray['result'] = "400";
                        $rsltArray['error'][0] = "could not connect to the DB";
                        $rsltArray['error'][1] = $e->getMessage();
                        $this->sendToLog(var_export($rsltArray, true), "CONNECT-ERROR");
                        throw new dalException("DB Connection Error", $rsltArray, "400");
                }
            } else {
                $aryRslt = array("result" => "400", "error" => array("That database is not in the dal_config file."));
                throw new dalException("No Such Database", $aryRslt, 400);
            }
        }

        /*
         * Close DB connection
         */
        private function terminateDBConnection() {
            // Drop our connection IF there is no transaction in progress.
            if(!$this->transactionInProgress) {
                unset($this->dbLink);
                $this->dbLink = false;
            }
        }


        /*
         * Perform a DB query directly - no cache is used in this method.
         *
         * @param $strSQL String The query to run
         * @param $action String The type of query to run.
         * @param $retType Int The constant for the return type - defaults to Associative Array
         *
         * @throws dalException
         */
		public function DoQuery($strSQL, $action = "select", $retType = 1) {
			
            // Connect to DB
            try {
                $this->internalDBConnect();
            } catch (dalException $e) {
                sendToLog("dalException connecting to db in DoQuery", "DAL-ERROR");
                throw $e;
            }
            $timeStart = microtime(true);
			if ($action == "select") {
                try {
                    $rsltB = $this->dbLink->query($strSQL);
                } catch (PDOException $e) {
                    throw new dalException($e->getMessage(), $this->dbLink->errorInfo(), 400);
                }
				
				if(!is_object($rsltB)) {
					$objErr = $this->dbLink->errorInfo();
					$rsltArray['result'] = "400";
					$rsltArray['error'][0] = "Query Failed - SQLSTATE: ".$objErr[0]." MySQL: ".$objErr[1];
					$rsltArray['error'][1] = "MySQL Error: ".$objErr[0];
					$rsltArray['error'][2] = "Query: ".$strSQL;
                    $rsltArray['error'][3] = $objErr[0];
                    $rsltArray['error'][4] = $objErr[1];
                    $rsltArray['error'][5] = $objErr[2];

                    if($this->debug) {
                        $this->sendToLog($rsltArray['error'][0]." SQL: ".$strSQL, "QUERY-ERROR");
                    }

					throw new dalException("DB Error", $rsltArray, "400");
				}
				
				// build an object (array of arrays) containing the resultset
				$j = 0;
				$rsltArray['result'] = "200";
				
				foreach($rsltB as $row) {
					$rsltArray['rows'][$j] = $row;
					++$j;
				}
					
				$rsltB->closeCursor();
			} else {
				
				// Make sure we are connected to the primary and modifications are allowed
				if($this->modsAllowed) {
				
                                        
					$rowsA = $this->dbLink->exec($strSQL);
					$this->sendToLog(var_export($rowsA, true), "DEBUG");
					if($rowsA === false) {
						$objErr = $this->dbLink->errorInfo();
						$rsltArray['result'] = "400";
						$rsltArray['error'][0] = "Query Failed - SQLSTATE: ".$objErr[0]." MySQL: ".$objErr[1];
						$rsltArray['error'][1] = "MySQL Error: ".$objErr[0];
						$rsltArray['error'][2] = "Query: ".$strSQL;
                        $rsltArray['error'][3] = $objErr[0];
                        $rsltArray['error'][4] = $objErr[1];
                        $rsltArray['error'][5] = $objErr[2];

                        if($this->debug) {
                            $this->sendToLog($rsltArray['error'][0]." SQL: ".$strSQL, "QUERY-ERROR");
                        }

                        throw new dalException("DB Error", $rsltArray, "400");
					}
					
					$rsltArray['result'] = "200";
					if($action == "insert") {
						$rsltArray['insertid'] = $this->dbLink->lastInsertId();
					}
					
					$rsltArray['rows_affected'] = $rowsA;
				} else {
					$rsltArray['result'] = "400";
					$rsltArray['error'][0] = "Connected to a read only DB, no modifications allowed.";
					throw new dalException("DB Error", $rsltArray, "400");
				}
			}
			
			// return the array.
			if($this->debug) {
                $tTime = microtime(true) - $timeStart;
                $this->sendToLog("SQL: ".$strSQL." Completed in: ".$tTime, "QUERY-COMPLETE");
            }
            $this->terminateDBConnection();
			return $this->getReturnValue($rsltArray, $retType);
			
		}

               
        /*
         * This function will cache a generic object. We will check for object
         * existance and then do the appropriate add/replace function.
         *
         * @return boolean false if the configuration isn't using Memcache
         * @param $aryObj Serializable object to cache
         * @param $id string The key/id the object will have in memcache
         * @param $durr Int The number of seconds to cache the object for.
         *
         */

        public function cacheObj($aryObj, $id, $durr) {

            if(dalConfig::$DALUSEMEMC) {
                if(!$this->memc) {
                    // Connect to Memcache server(s)
                    $this->memc = new Memcached;
                    foreach($this->aryMemC as $host => $port) {
                            $this->memc->addServer($host, $port);
                    }
                }

                // See if the object is cached
                $rsltC = $this->memc->get($id);

                if($rsltC) {
                    // Result found in MemCache... replace it
                    $this->memc->replace($id, $aryObj, $durr);
                    return true;
                } else {
                    $this->memc->add($id, $aryObj, $durr);
                    return true;
                }
            } else {
                return false;
            }
        }

        /*
         * Remove an item from cache by id.
         *
         * @param $id string The key/id of the object in memcache
         * @return Boolean The result of the unset.
         *
         */
        public function cacheUnset($id) {

            if(dalConfig::$DALUSEMEMC) {
                if(!$this->memc) {
                    // Connect to Memcache server(s)
                    $this->memc = new Memcached;
                    foreach($this->aryMemC as $host => $port) {
                            $this->memc->addServer($host, $port);
                    }
                }

                // See if the object is cached
                $rsltC = $this->memc->delete($id);
                return $rsltC;
            } else {
                return false;
            }
        }

        /*
         * This function gets an object from the memcache cluster.
         *
         * @return boolean The object or false if the object isn't in memcache.
         * @param $id string The key/id of the object in memcache
         * @param $retType int CONST value of return type - default Associative array.
         */
        public function getObj($id, $retType = 1) {

            if(dalConfig::$DALUSEMEMC) {
                if(!$this->memc) {
                    // Connect to Memcache server(s)
                    $this->memc = new Memcached;
                    foreach($this->aryMemC as $host => $port) {
                            $this->memc->addServer($host, $port);
                    }
                }

                // See if the object is cached
                $rsltC = $this->memc->get($id);
                return $this->getReturnValue($rsltC, $retType);
            } else {
                return false;
            }
        }
		
		/* The QueryInCache function evaluates Memcache to determine if a specific result set
			is in the cache. If it is it is immediately returned. If the result set is NOT in the 
			cache the function DoQuery is called to fetch the data from the underlying database.
			
			If there are no errors returned by DoQuery the result set is cached in memcache.
			
			The function accepts the SQL to execute ($strSQL) and the number of seconds the result set
			should be cached in memcache ($durr).
			
			The function returns an assoicative array containing both the result (200=success 400=error) and 
			the contents of the result set.

		    @param $strSQL string The SQL Statement to run.
		    @param $durr Int The number of seconds to cache the result for
		    @param $retType Int CONST - The format of the return - default Associative Array
			
		*/
		public function QueryInCache($strSQL, $durr, $retType = 1) {
			
            $timeStart = microtime(true);
			if(dalConfig::$DALUSEMEMC) {
                if(!$this->memc) {
                    // Connect to Memcache server(s)
                    $this->memc = new Memcached;
                    foreach($this->aryMemC as $host => $port) {
                        $this->memc->addServer($host, $port);
                    }
                }
                // Look up result in MemCache
                $rsltC = $this->memc->get(md5($strSQL));

				
				if($rsltC) {
					// Result found in MemCache... return it.
					// echo "Found result in Memcache...\n";

                    // Indicate it was found in cache
                    $rsltC['cache'] = "true";
                    if($this->debug) {
                        $tTime = microtime(true) - $timeStart;
                        $this->sendToLog(" SQL: ".$strSQL." found in cache in: ".$tTime, "CACHE-FOUND");
                        // $this->sendToLog("Object: ".var_export($rsltC, true), "CACHE-DEBUG");
                    }
					return $this->getReturnValue($rsltC, $retType);

				} else {
                    try{
					    $rsltC = $this->DoQuery($strSQL, "select");
                    }catch(dalException $e) {
                        throw $e;
                    }
                    if($rsltC['result'] != "400") {		// only cache the result if there is no error
                        if($this->cacheObj($rsltC, md5($strSQL), $durr)) {
                            if($this->debug) $this->sendToLog("Object cached: ".$strSQL, "CACHE");
                        } else {
                            if($this->debug) $this->sendToLog("Failed to cache object: ".$strSQL."\n", "CACHE-ERROR");
                        }

                    }
                    $rsltC['cache'] = "false";
                    if($this->debug) {
                        $tTime = microtime(true) - $timeStart;
                        $this->sendToLog(" SQL: ".$strSQL." not found in cache, retrieved from DB in: ".$tTime, "CACHE-NOTFOUND");
                    }
                    return $this->getReturnValue($rsltC, $retType);
				}
				
			} else {
				// Memcache is off... get result from DB
                try {
				    $rsltC = $this->DoQuery($strSQL);
                    $rsltC['cache'] = "disabled";
                } catch(dalException $e) {
                    throw $e;
                }

                if($this->debug) {
                    $tTime = microtime(true) - $timeStart;
                    $this->sendToLog(" Cache is off -> SQL: ".$strSQL." retrieved from DB in: ".$tTime, "CACHE-DISABLED");
                }
                // Don't cache it... since MEMC is off
                return $this->getReturnValue($rsltC, $retType);
			}
			
		}

        /*
         * This method returns the result in the specified format.
         *
         * We currently support a PHP Associative Array, JSON and XML
         *
         * @author: Brian Roy
         * @date: 01/28/2011
         *
         * @param $rslt Associative Array - As returned by DB
         * @param $retType INT CONST for the type to be returned.
         *
         */
        private function getReturnValue($rslt, $retType) {

            switch($retType) {
                case self::RETURN_ASSOC:
                    return $this->cleanupAssoc($rslt);
                    break;
                case self::RETURN_JSON:
                    return $this->generateJSON($rslt);
                    break;
                case self::RETURN_XML:
                    return $this->generateXML($rslt);
                    break;
                default:
                    // Unknown Return Type? Return as associative (default)
                    return $rslt;

            }
        }
        /*
         * PDO returns each column twice - once with the column name
         * and once with a numeric index. We drop the numeric index.
         *
         * @param $rslt The result from the DB
         * @return Array The cleaned up result.
         *
         */
        private function cleanupAssoc($rslt) {
            if($rslt && array_key_exists("rows", $rslt)) {
                for($i=0; $i < count($rslt['rows']); ++$i) {
                    foreach($rslt['rows'][$i] as $key => $val) {
                            // Remove numeric indexed column.
                            if(is_numeric($key)) unset($rslt['rows'][$i][$key]);
                    }
                }
            }
            return $rslt;
        }

        /*
         * The generateJSON method will generate well formed JSON from a result.
         *
         *
         *
         * @author: Brian Roy
         * @date: 01/31/2011
         *
         */

        private function generateJSON($rslt) {
            $clean = $this->cleanupAssoc($rslt);
            return json_encode($clean);
        }
                

		
		/* The generateXML function will generate well formed XML from any result set generated
			by the Data Access Layer. The actual data content is not relavant.
			
			The XML returned contains top level elements like "result" as needed. Each row contained in the 
			result set is returned as a child element of the <rows> tag.
			Each row is returned as <field_name>value</field_name>.
			
			The XML generated is suitable for direct output once headers have been output.
			
			The function accepts an associatie array (generaged by QueryInCache) and returns the XML as a 
			string.
			
		*/
		private function generateXML($rslt) {
			
			$strXML = "<xml>";
			
			// print_r($rslt);
			
			foreach($rslt as $key => $val) {
				if($key == "rows") {	
					// do nothing...
				} else {
					$strXML .= "<".$key.">".$val."</".$key.">";
				}
			}
			// now output each rows in the Rows array
			$strXML .= "<rows>";
			for($i=0; $i < count($rslt['rows']); ++$i) {
				$strXML .= "<row>";
				foreach($rslt['rows'][$i] as $key => $val) {
					// Ouputting columns/values as XML
					if(!is_numeric($key)) {
						// PDO double outputs all results with the associative (column name = key) and 
						// standard array notation (integer = key). This drops the standard array notation.
						// NOTE: If you name a database column with a numeric value it will be suppressed.
						$strXML .= "<".$key.">".$this->xmlencode($val)."</".$key.">";
					}
				}
				$strXML .= "</row>";
			}
			$strXML .= "</rows>";
			
			$strXML .= "</xml>";
			
			return $strXML;
		
		}
        /*
         * XML Encoding conversions and character dropping.
         * Used to make up for PHPs woeful UTF-8 Support.
         */
        private function xmlencode($tag) {

            // When we convert encodings... drop illegal characters
            mb_substitute_character("none");

            $tag = $this->stripASCIINonPrinting($tag);

            if(mb_detect_encoding($tag) != 'UTF-8') {
                    $tag = mb_convert_encoding($tag, 'UTF-8', 'auto');
            }
            $tag = mb_convert_encoding($tag, 'UTF-8', 'HTML-ENTITIES');
            $tag = str_replace("&", "&amp;", $tag);
            $tag = str_replace("<", "&lt;", $tag);
            $tag = str_replace(">", "&gt;", $tag);
            $tag = str_replace("\n", "", $tag);
            $tag = str_replace("\r", "", $tag);
            return $tag;

        }

        private function stripASCIINonPrinting($tag) {
            // Function strips ASCII characters under 32 (non printing control chars)
            //	because a) they tend to appear from time to time in Tweets (and sometimes
            //	other data sources) and b) They are illegal in XML
            //
            //	@author: Brian Roy
            //	@date: 08-18-2009

            $aryChars = array();
            for($i=0; $i<=31; ++$i) {
                    $aryChars[] = chr($i);
            }

            return str_replace($aryChars, "", $tag);
        }

        /*
         * This method will prepre a statement.
         *
         * NOTE: Do NOT trick $stmtType, caching INSERT, UPDATE and
         * DELETE prepared statements is dangerous and can cause
         * a variety of unintended results (like the statement not
         * beign executed).
         *
         * The sql passed in should be proper prepare syntax for
         * the RDBMS being used (the one we are currently connected to).
         *
         * The statement id is returned and will be used to execute the
         * statement.
         *
         * @author: Brian Roy
         * @date: 01/29/2011
         *
         */
        public function prepareStatement($sql, $stmtType = self::PREPARE_SELECT) {
            $intS = microtime(true);


            $this->aryStatement[]['statement'] = true;

            $tIdx = (count($this->aryStatement) - 1);

            $this->aryStatement[$tIdx]['sql'] = $sql;
            $this->aryStatement[$tIdx]['type'] = $stmtType;

            if($this->debug) {
                $tTime = microtime(true) - $intS;
                $this->sendToLog('Prepared in: ' . $tTime, crc32($sql) . ' PREPARE-COMPLETE');
            }

            return $tIdx;


        }

        /*
         * This method will execute a prepared statement previously prepared
         * with prepareStatement($sql).
         *
         * @parameters: $stmtID is the id of the prepared statement returned by
         *                  prepareStatement($sql).
         *
         *              $aryBindParms is an associative array with the key being the
         *                  parameter name from the prepareStatement.
         *                  The value is an associative array containing
         *                  two members "value" => <parameter value> and
         *                  "data_type" => PDO::<data type>
         *
         * Returns true on success or a dalException on failure.
         *
         * @author: Brian Roy
         * @date: 01/29/2011
         *
         *
         */

        public function executeStatement($stmtID, $aryBindPrams) {
            $intS = microtime(true);
            if(!array_key_exists($stmtID, $this->aryStatement))
                    throw new dalException ("Execute: No Such Prepared Statement", array(), 0);

            $this->aryStatement[$stmtID]['bindarray'] = $aryBindPrams;
            $strBind = "";
            foreach($aryBindPrams as $pram => $aryInfo) {
                $strBind .= "[".$pram."|".$aryInfo['value']."]";
            }

            // Store the bind parameters for use in cache id (if needed)
            $this->aryStatement[$stmtID]['bindParams'] = $strBind;

            // Is it in cache?

            $strCacheID = $this->getStmtCacheId($stmtID);

            $fromCache = $this->getObj($strCacheID);
            if(!$fromCache) {
                // Not in Cache - Execute the statement.
                $this->aryStatement[$stmtID]['incache'] = false;

            } else {
                /*
                * Adding cached object to the statment structure to avoid
                 * cache expiry between execute and fetch.
                 * @author Brian Roy
                */
                $this->aryStatement[$stmtID]['incache'] = true;
                $this->aryStatement[$stmtID]['cacheresult'] = $fromCache;
            }

            if($this->debug) {
                $tTime = microtime(true) - $intS;

                $params = '';

                foreach ($aryBindPrams as $key => $value) {
                    if (empty($params) == false) {
                        $params .= ', ';
                    }
                    $params .= $key . ' => ' . $value['value'];
                }

                $this->sendToLog('Executed in: ' . $tTime . "\n " . $this->aryStatement[$stmtID]['sql'] . "\n $params \n", crc32($this->aryStatement[$stmtID]['sql']) . ' PREPARE-EXECUTE-COMPLETE');
            }

            return true;

        }

        /*
         * This method returns the result set from a prepared statement.
         *
         * @parameters: $stmtID is the id of the prepared statement returned by
         *                  prepareStatement($sql).
         *
         * Returns the result set as an associative array on sucess or
         * throws a dalException on error.
         *
         * @author: Brian Roy
         * @date: 01/29/2011
         *
         */

        public function fetchPreparedResult($stmtID, $retType = 1, $doCache = false, $cacheDurr = 10) {
            $intS = microtime(true);
            if(!array_key_exists($stmtID, $this->aryStatement))
                    throw new dalException ("Fetch: No Such Prepared Statement", array(), 400);

            if($doCache && $this->aryStatement[$stmtID]['type'] != self::PREPARE_SELECT) {
                if($this->debug) {
                    $strLog = "ERROR: ".date('r')." Trying to cache: ".$this->aryStatement[$stmtID]['sql']." but is isn't an insert (value: ".$this->aryStatement[$stmtID]['type']."\n";
                    error_log($strLog, 3, dalConfig::$DALLOG);
                }
                throw new dalException("Fetch: Cannot cache this statement type. Only PREPARE_SELECT can be cached.", array(), 0);
            }

            // Check Cache
            /*
             * Depreciated - the cache is now part of the statement
             * structure and should have been set in executeStatement.

            $cRslt = $this->getObj($strCacheID);
             *
             * New method for checking cache
             */
            $strCacheID = $this->getStmtCacheId($stmtID);
            $cRslt = false;
            if($this->aryStatement[$stmtID]['incache']) $cRslt = $this->aryStatement[$stmtID]['cacheresult'];
            if($cRslt) {
                if($this->debug) {
                    $tTime = microtime(true) - $intS;
                    $this->sendToLog('Found from CACHE in: ' . $tTime, crc32($this->aryStatement[$stmtID]['sql']) . ' PREPARE-FETCH-COMPLETE');
                }
                $cRslt['cache'] = "true";
                if($this->debug) {
                    $tTime = microtime(true) - $intS;
                    $varS = var_export($cRslt, true);
                    // $this->sendToLog("Object: ".$varS." Found in: ".$tTime, "PREPARE-CACHE-DEBUG");
                }
                return $this->getReturnValue($cRslt, $retType);
            }

            // build an object (array of arrays) containing the resultset

            /*
             * Connect and actually prepare, execute and fetch
             */
            try {
                $this->internalDBConnect();
                $this->aryStatement[$stmtID]['statement'] = $this->dbLink->prepare($this->aryStatement[$stmtID]['sql']);

                // Bind Prep
                foreach($this->aryStatement[$stmtID]['bindarray'] as $pram => $aryInfo) {
                    if(! $this->aryStatement[$stmtID]['statement']->bindParam($pram, $aryInfo['value'], $aryInfo['data_type'])) {
                        throw new dalException("Failed to bind a parameter to the prepared statement",
                                $this->aryStatement[$stmtID]['statement']->errorInfo(),
                                $this->aryStatement[$stmtID]['statement']->errorCode());
                    }
                }

                // Execute

                $this->aryStatement[$stmtID]['statement']->execute();


            } catch (PDOException $e) {
                throw new dalException($e->getMessage(), $e->errorInfo, 0);
            }
            $rsltArray['result'] = "200";
            $rsltArray['cache'] = "false";
            $rsltArray['rows_affected'] = $this->aryStatement[$stmtID]['statement']->rowCount();
            if($this->aryStatement[$stmtID]['type'] == self::PREPARE_INSERT) $rsltArray['insertid'] = $this->dbLink->lastInsertId();
            if($this->aryStatement[$stmtID]['type'] == self::PREPARE_SELECT) {

                try {
                    $rslt = $this->aryStatement[$stmtID]['statement']->fetchAll();
                    if($this->debug) {
                        $tTime = microtime(true) - $intS;
                        $varS = var_export($rslt, true);
                        // $this->sendToLog("Object: ".$varS." Found in: ".$tTime, "PREPARE-CACHE-DEBUG");
                    }
                    $j = 0;
                    foreach($rslt as $row) {
                        $rsltArray['rows'][$j] = $row;
                        ++$j;
                    }
                } catch(PDOException $e) {
                    throw new dalException("PDO Exception: ".$e->getMessage(), array("message" => "fetchAll called for a statement with a null result."), 400);
                }

            }

            if($doCache) {
                $this->cacheObj($rsltArray, $strCacheID, $cacheDurr);
                if($this->debug) {
                    $tTime = microtime(true) - $intS;
                    $this->sendToLog('Result CACHED in: ' . $tTime, crc32($this->aryStatement[$stmtID]['sql']) . ' PREPARE-CACHE-COMPLETE');
                }
            }

            if($this->debug) {
                $tTime = microtime(true) - $intS;
                $this->sendToLog('Fetched in: ' . $tTime, crc32($this->aryStatement[$stmtID]['sql']) . ' PREPARE-FETCH-COMPLETE');
            }

            if(!dalConfig::$DALUSEMEMC) $rsltArray['cache'] = 'disabled';
            $this->terminateDBConnection();
            return $this->getReturnValue($rsltArray, $retType);

        }

        /*
         * Method getStmtCacheId returns the prepared statement's cache id.
         * This used for extenal cacheing - for example, when you only want
         * to cache a result based on some data found in the result set.
         *
         * NOTE: This should not be called until the statment is executed since the
         * cache id is both the statement and the bind parameters.
         *
         * @param $stmt int The statement ID returned when the statement was prepared & executed.
         */
        public function getStmtCacheId($stmt) {
            return md5(($this->aryStatement[$stmt]['sql']."-".$this->aryStatement[$stmt]['bindParams']));
        }
		
	}

/*
 * Class extending Exception for our dal exceptions.
 *
 * @author: Brian Roy
 * @date: 01/28/2011
 *
 */

class dalException extends Exception
{

    private $debug = true;
    public $dbMessages;

    public function __construct($message, $aryMessages, $code = 0)
    {
        $this->dbMessages = $aryMessages;        
        if ($this->debug) {
            $einfo    = var_export($aryMessages, true);
            $trace   = "Trace \n" . var_export(self::getTrace(), true);
            $tmessage = "EXCEPTION: ".date('r')." in dal - ".' Code:' . $code . ' Message: ' . $message . "\nInfo: " . $einfo . "\n" . $trace."\n\n\n";       
            error_log($tmessage, 3, dalConfig::$DALEXCEPTIONLOG);
        }
                        
        parent::__construct($message, $code);
    }
    
}
