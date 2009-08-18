<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 * This script is distributed under the GNU General Public License 2 or later.
 * 
 * @package 	TestLink
 * @author 		Martin Havlat, Chad Rosen
 * @copyright 	2007-2009, TestLink community 
 * @version    	CVS: $Id: common.php,v 1.164 2009/08/18 19:58:15 schlundus Exp $
 * @link 		http://www.teamst.org/index.php
 *
 * Load core functions for TestLink GUI
 * Common functions: database connection, session and data initialization,
 * maintain $_SESSION data, redirect page, log, etc.
 * 
 * Note: this file cannot include a feature specific code for performance and 
 * readability reason
 *
 * @internal Revisions:
 * 
 * 20090425 - amitkhullar - BUGID 2431 - Improper Session Handler	
 * 20090409 - amitkhullar- BUGID 2354
 * 20090111 - franciscom - commented some required_once and some global coupling
 * 20081027 - havlatm - refactorization, description
 * 						removed unused $g_cache_config and some functions 
 * 20080907 - franciscom - isValidISODateTime()
 * 20080518 - franciscom - translate_tc_status()
 * 20080412 - franciscom - templateConfiguration()
 * 20080326 - franciscom - config_get() - refactored removed eval()
 * 20071027 - franciscom - added ini_get_bool() from mantis code, needed to user
 *                         string_api.php, also from Mantis.
 *
 * 20071002 - jbarchibald - BUGID 1051
 * 20070705 - franciscom - init_labels()
 * 20070623 - franciscom - improved info in header of localize_dateOrTimeStamp()
 *
 * ----------------------------------------------------------------------------------- */

/** core and parenthal classes */
require_once('object.class.php');
require_once('metastring.class.php');

/** library for localization */
require_once('lang_api.php');

/** library of database wrapper */
require_once('database.class.php');

/** user right checking */
require_once('roles.inc.php');

/** Testlink Smarty class wrapper sets up the default smarty settings for testlink */
require_once('tlsmarty.inc.php');

/** logging functions */
require_once('logging.inc.php');
require_once('logger.class.php');
require_once('pagestatistics.class.php');

/** BTS interface */
/** @TODO martin: remove from global loading - limited using */ 
if ($g_interface_bugs != 'NO')
{
  require_once(TL_ABS_PATH. 'lib' . DIRECTORY_SEPARATOR . 'bugtracking' . 
               DIRECTORY_SEPARATOR . 'int_bugtracking.php');
}
require_once("role.class.php");
require_once("attachment.class.php");
require_once("testproject.class.php"); 

/** @TODO use the next include only if it is used -> must be removed */
require_once("user.class.php");
require_once("keyword.class.php");
require_once("treeMenu.inc.php");
require_once("exec_cfield_mgr.class.php");
require_once("inputparameter.inc.php");

//@TODO schlundus, i think we can remove php4 legacy stuff?
/** 
 * load the php4 to php5 domxml wrapper if the php5 is used and 
 * the domxml extension is not loaded 
 **/
if (version_compare(PHP_VERSION,'5','>=') && !extension_loaded("domxml"))
{
	require_once(TL_ABS_PATH . 'third_party'. DIRECTORY_SEPARATOR . 
		'domxml-php4-to-php5.php');
}

// -------------------------------------------------------------------------------------
/** @var integer global main DB connection identifier */
$db = 0;


// --------------------------------------------------------------------------------------
/* See PHP Manual for details */
function __autoload($class_name) 
{
	// exceptions
	$tlClassPrefixLen=2;
	$tlClasses = array('tlPlatform' => true);
	$classFileName = $class_name;
    
	if ( isset($tlClasses[$classFileName]) )
	{
    	$len = tlStringLen($classFileName) - $tlClassPrefixLen;
		$classFileName = strtolower(tlSubstr($classFileName,$tlClassPrefixLen,$len));
	} 
    require_once $classFileName . '.class.php';
}


// --------------------------------------------------------------------------------------
/**
 * TestLink connects to the database
 *
 * @return array
 *         aa['status'] = 1 -> OK , 0 -> KO
 *         aa['dbms_msg''] = 'ok', or $db->error_msg().
 */
function doDBConnect(&$db)
{
	global $g_tlLogger;
	
	$charSet = config_get('charset');
	$result = array('status' => 1, 'dbms_msg' => 'ok');

	$db = new database(DB_TYPE);
	$result = $db->connect(DSN, DB_HOST, DB_USER, DB_PASS, DB_NAME);

	if (!$result['status'])
	{
		echo $result['dbms_msg'];
		$result['status'] = 0;
		tLog('Connect to database fails!!! ' . $result['dbms_msg'], 'ERROR');
  }
  else
	{
		if((DB_TYPE == 'mysql') && ($charSet == 'UTF-8'))
		{
				$db->exec_query("SET CHARACTER SET utf8");
				$db->exec_query("SET collation_connection = 'utf8_general_ci'");
		}
	}

	//if we establish a DB connection, we reopen the session, to attach the db connection
	$g_tlLogger->endTransaction();
	$g_tlLogger->startTransaction();

 	return $result;
}


// --------------------------------------------------------------------------------------
/**
 * Set session data related to the current test plan
 * @param array $tplan_info result of DB query
 * @TODO move to testPlan class
 */
function setSessionTestPlan($tplan_info)
{
	if ($tplan_info)
	{
		$_SESSION['testplanID'] = $tplan_info['id'];
		$_SESSION['testplanName'] = $tplan_info['name'];

		tLog("Test Plan was adjusted to '" . $tplan_info['name'] . "' ID(" . $tplan_info['id'] . ')', 'INFO');
	}
	else
	{
		unset($_SESSION['testplanID']);
		unset($_SESSION['testplanName']);
	}
}


// --------------------------------------------------------------------------------------
/**
 * Set home URL path
 * @todo solve problems after session expires
 * 200806 - havlatm - removed rpath
 */
function setPaths()
{
	if (!isset($_SESSION['basehref']))
	{
		$_SESSION['basehref'] = get_home_url();
	}	
}


// --------------------------------------------------------------------------------------
/** Verify if user is log in. Redirect to login page if not. */
function checkSessionValid(&$db)
{
	$isValidSession = false;
	if (isset($_SESSION['userID']) && $_SESSION['userID'] > 0)
	{
		/** @TODO martin: 
		    Talk with Andreas to understand:
		    1. advantages of this approach
		    2. do we need to recreate it every time ? why ?
		   
		 * a) store just data -not all object
		 * b) do not read again and again the same data from DB
		 * c) this function check JUST session validity
		 **/
		$now = time();
		$lastActivity = $_SESSION['lastActivity'];
		if (($now - $lastActivity) <= (config_get("sessionInactivityTimeout") * 60))
		{
			$_SESSION['lastActivity'] = $now;
			$user = new tlUser($_SESSION['userID']);
			$user->readFromDB($db);
			$_SESSION['currentUser'] = $user;
			$isValidSession = true;
		}
	}
	if (!$isValidSession)
	{
        $ip = $_SERVER["REMOTE_ADDR"];
	    tLog('Invalid session from ' . $ip . '. Redirected to login page.', 'INFO');
		
		$fName = "login.php";
        $baseDir = dirname($_SERVER['SCRIPT_FILENAME']);
        
        while(!file_exists($baseDir.DIRECTORY_SEPARATOR.$fName))
        {
            $fName = "../" . $fName;
        }
        redirect($fName . "?note=expired","top.location");
        exit();
	}
}


// --------------------------------------------------------------------------------------
/**
 * start session
 */
function doSessionStart()
{
	session_set_cookie_params(99999);

	if(!isset($_SESSION))
	{
		session_start();
	}
}

// --------------------------------------------------------------------------------------
// If we receive TestPlan ID in the _SESSION
//    then do some checks and if everything OK
//    Update this value at Session Level, to set it available in other
//    pieces of the application
//
// rev :
//      20090726 - franciscom - getAccessibleTestPlans() now is method on user class
function upd_session_tplan_tproject(&$db,$hash_user_sel)
{
	$tproject = new testproject($db);
	$user_sel = array("tplan_id" => 0, "tproject_id" => 0 );
	$user_sel["tproject_id"] = isset($hash_user_sel['testproject']) ? intval($hash_user_sel['testproject']) : 0;
	$user_sel["tplan_id"] = isset($hash_user_sel['testplan']) ? intval($hash_user_sel['testplan']) : 0;

	$tproject_id = isset($_SESSION['testprojectID']) ? $_SESSION['testprojectID'] : 0;

	// test project is Test Plan container, then we start checking the container
	if( $user_sel["tproject_id"] != 0 )
	{
		$tproject_id = $user_sel["tproject_id"];
	}
	// We need to do checks before updating the SESSION to cover the case that not defined but exists
	if (!$tproject_id)
	{
		$all_tprojects = $tproject->get_all();
		if ($all_tprojects)
		{
			$tproject_data = $all_tprojects[0];
			$tproject_id = $tproject_data['id'];
		}
	}
	$tproject->setSessionProject($tproject_id);
	
	// set a Test Plan
	// Refresh test project id after call to setSessionProject
	$tproject_id = isset($_SESSION['testprojectID']) ? $_SESSION['testprojectID'] : 0;
	$tplan_id = isset($_SESSION['testplanID']) ? $_SESSION['testplanID'] : null;
	// Now we need to validate the TestPlan
	if($user_sel["tplan_id"] != 0)
	{
		$tplan_id = $user_sel["tplan_id"];
	}
  
	// check if the specific combination of testprojectid and testplanid is valid
	$tplan_data = $_SESSION['currentUser']->getAccessibleTestPlans($db,$tproject_id,$tplan_id);
	if(is_null($tplan_data))
	{
		// Need to get first accessible test plan for user, if any exists.
		$tplan_data = $_SESSION['currentUser']->getAccessibleTestPlans($db,$tproject_id);
    }
	
	if(!is_null($tplan_data))
	{
		$tplan_data = $tplan_data[0];
		setSessionTestPlan($tplan_data);
	}
   
}


// --------------------------------------------------------------------------------------
/**
* General page initialization procedure
* - init session
* - init database
* - check rights
* 
* @param integer $db DB connection identifier
* @param boolean $initProject (optional) Set true if adjustment of Product or
* 		Test Plan is required; default is FALSE
* @param boolean $bDontCheckSession (optional) Set to true if no session should be
* 		 started
*/
function testlinkInitPage(&$db, $initProject = FALSE, $bDontCheckSession = false,$userRightsCheckFunction = null)
{
	doSessionStart();
	setPaths();
	set_dt_formats();
	
	doDBConnect($db);
	
	static $pageStatistics = null;
	if (!$pageStatistics && (config_get('log_level') == 'EXTENDED'))
		$pageStatistics = new tlPageStatistics($db);
	
	if (!$bDontCheckSession)
		checkSessionValid($db);

	if ($userRightsCheckFunction)
		checkUserRightsFor($db,$userRightsCheckFunction);
		
	// adjust Product and Test Plan to $_SESSION
	if ($initProject)
	{
		upd_session_tplan_tproject($db,$_REQUEST);
    }
   
	// used to disable the attachment feature if there are problems with repository path
	/** @TODO this check should not be done anytime but on login and using */
	global $g_repositoryType;
	global $g_attachments;
	global $g_repositoryPath;
	$g_attachments->disabled_msg = "";
	if($g_repositoryType == TL_REPOSITORY_TYPE_FS)
	{
	  $ret = checkForRepositoryDir($g_repositoryPath);
	  if(!$ret['status_ok'])
	  {
		  $g_attachments->enabled = FALSE;
		  $g_attachments->disabled_msg = $ret['msg'];
	  }
	}
}


// --------------------------------------------------------------------------------------
/**
 * Redirect page to another one
 *
 * @param   string   URL of required page
 * @param   string   Browser location - use for redirection or refresh of another frame
 * 					 Default: 'location'
 */
function redirect($path, $level = 'location')
{
	echo "<html><head></head><body>";
	echo "<script type='text/javascript'>";
	echo "$level.href='$path';";
	echo "</script></body></html>";
	exit;
}


// --------------------------------------------------------------------------------------
/**
 * Security parser for input strings
 * @param string $parameter
 * @return string cleaned parameter
 */
function strings_stripSlashes($parameter,$bGPC = true)
{
	if ($bGPC && !ini_get('magic_quotes_gpc'))
		return $parameter;

	if (is_array($parameter))
	{
		$retParameter = null;
		if (sizeof($parameter))
		{
			foreach($parameter as $key=>$value)
			{
				if (is_array($value))
					$retParameter[$key] = strings_stripSlashes($value,$bGPC);
				else
					$retParameter[$key] = stripslashes($value);
			}
		}
		return $retParameter;
	}
	else
		return stripslashes($parameter);
}


// --------------------------------------------------------------------------------------
function to_boolean($alt_boolean)
{
	$the_val = 1;

	if (is_numeric($alt_boolean) && !intval($alt_boolean))
	{
		$the_val = 0;
	}
	else
	{
		$a_bool	= array ("on" => 1, "y" => 1, "off" => 0, "n" => 0);
		$alt_boolean = strtolower($alt_boolean);
		if(isset($a_bool[$alt_boolean]))
		{
			$the_val = $a_bool[$alt_boolean];
		}
	}

	return $the_val;
}


// --------------------------------------------------------------------------------------
/*
20050708 - fm
Modified to cope with situation where you need to assign a Smarty Template variable instead
of generate output.
Now you can use this function in both situatuons.

if the key 'var' is found in the associative array instead of return a value,
this value is assigned to $params['var`]

usage: Important: if registered as localize_date()
       {localize_date d='the date to localize'}
------------------------------------------------------------------------------------------
*/
function localize_date_smarty($params, &$smarty)
{
	return localize_dateOrTimeStamp($params,$smarty,'date_format',$params['d']);
}


// --------------------------------------------------------------------------------------
/*
  function:
  args:
  returns:
*/
function localize_timestamp_smarty($params, &$smarty)
{
	return localize_dateOrTimeStamp($params,$smarty,'timestamp_format',$params['ts']);
}

// --------------------------------------------------------------------------------------
/*
  function:
  args :
         $params: used only if you call this from an smarty template
                  or a wrapper in an smarty function.

         $smarty: when not used in an smarty template, pass NULL.
         $what: give info about what kind of value is contained in value.
                possible values: timestamp_format
                                 date_format
         $value: must be a date or time stamp in ISO format

  returns:
*/
function localize_dateOrTimeStamp($params,&$smarty,$what,$value)
{
	// to supress E_STRICT messages
	setlocale(LC_ALL, TL_DEFAULT_LOCALE);

	$format = config_get($what);
	if (!is_numeric($value))
	{
		$value = strtotime($value);
	}
	
	$retVal = strftime($format, $value);
	if(isset($params['var']))
	{
		$smarty->assign($params['var'],$retVal);
	}
	return $retVal;
}


// --------------------------------------------------------------------------------------
/**
 *
 * @param string $str2check
 * @param string  $regexp_forbidden_chars: regular expression (perl format)
 *
 * @return  1: check ok, 0:check KO
 */
function check_string($str2check, $regexp_forbidden_chars)
{
	$status_ok = 1;

	if( $regexp_forbidden_chars != '' && !is_null($regexp_forbidden_chars))
	{
		if (preg_match($regexp_forbidden_chars, $str2check))
		{
			$status_ok=0;
		}
	}
	return $status_ok;
}


// --------------------------------------------------------------------------------------
/*
  function:
  args :
  returns:
*/
function set_dt_formats()
{
	global $g_date_format;
	global $g_timestamp_format;
	global $g_locales_date_format;
	global $g_locales_timestamp_format;

	if(isset($_SESSION['locale']))
	{
		if($g_locales_date_format[$_SESSION['locale']])
		{
			$g_date_format = $g_locales_date_format[$_SESSION['locale']];
		}
		if($g_locales_timestamp_format[$_SESSION['locale']])
		{
			$g_timestamp_format = $g_locales_timestamp_format[$_SESSION['locale']];
		}
	}
}


// --------------------------------------------------------------------------------------
/*
  function: config_get
  args :
  returns:
  
  rev:
      20080326 - franciscom - removed eval
*/
function config_get($config_id)
{
	$t_value = '';  
	$t_found = false;  

	if(!$t_found)
	{
 		$my = "g_" . $config_id;
        if (isset($GLOBALS[$my]))
	    	$t_value = $GLOBALS[$my];
	    else 
	    {
			$cfg = $GLOBALS['tlCfg'];
			if (property_exists($cfg,$config_id))
				$t_value = $cfg->$config_id;
	    }
	}
	tlog('config_get global var with key ['.$config_id.'] is ' . $t_value);
	return $t_value;
}


// --------------------------------------------------------------------------------------
/**  
 * Return true if the parameter is an empty string or a string
 * containing only whitespace, false otherwise
 * @author Copyright (C) 2000 - 2004  Mantis Team, Kenzaburo Ito
 */ 
function is_blank( $p_var ) {
	$p_var = trim( $p_var );
	$str_len = strlen( $p_var );
	if ( 0 == $str_len ) {
		return true;
	}
	return false;
}


// --------------------------------------------------------------------------------------
/**
 * Builds the header needed to make the content available for downloading
 *
 * @param string $content the content which should be downloaded
 * @param string $fileName the filename
 *
 *
**/
function downloadContentsToFile($content,$fileName)
{
	$charSet = config_get('charset');

	ob_get_clean();
	header('Pragma: public' );
	header('Content-Type: text/plain; charset='. $charSet . '; name=' . $fileName );
	header('Content-Transfer-Encoding: BASE64;' );
	header('Content-Disposition: attachment; filename="' . $fileName .'"');
	echo $content;
}


// --------------------------------------------------------------------------------------
/** @TODO martin: move the next two functions to appropriate class + describe */
/*
  function: translate_tc_status
  args :
  returns:
*/
function translate_tc_status($status_code)
{
	$resultsCfg = config_get('results'); 
	$verbose = lang_get('test_status_not_run');
	if( $status_code != '')
	{
		$suffix = $resultsCfg['code_status'][$status_code];
		$verbose = lang_get('test_status_' . $suffix);
	}
	return $verbose;
}

/*
  function: translate_tc_status_smarty
  args :
  returns:
*/
function translate_tc_status_smarty($params, &$smarty)
{
	$the_ret = translate_tc_status($params['s']);
	if(	isset($params['var']) )
	{
		$smarty->assign($params['var'], $the_ret);
	}
	else
	{
		return $the_ret;
	}
}


// --------------------------------------------------------------------------------------
/** @TODO describe */
/*
  function:
  args :
  returns:
*/
function my_array_intersect_keys($array1,$array2)
{
	$aresult = array();
	foreach($array1 as $key => $val)
	{
		if(isset($array2[$key]))
		{
			$aresult[$key] = $array2[$key];
		}
	}
	return($aresult);
}


// --------------------------------------------------------------------------------------
/*
  function for performance timing
  @TODO martin: move to logger?
  returns:
*/
function microtime_float()
{
   list($usec, $sec) = explode(" ", microtime());
   return ((float)$usec + (float)$sec);
}


// --------------------------------------------------------------------------------------
/*
  function: init_labels

  args : map key=a code
             value: string_to_translate, that can be found in strings.txt

  returns: map key=a code
               value: lang_get(string_to_translate)
*/
function init_labels($map_code_label)
{
	foreach($map_code_label as $key => $label)
	{
		$map_code_label[$key] = lang_get($label);
	}
	return $map_code_label;
}

/*
 * Converts a priority weight (urgency * importance) to HIGH, MEDUIM or LOW
 *
 * @return HIGH, MEDUIM or LOW
 */
function priority_to_level($priority) {
	$levels = config_get('priority_levels');
	if ($priority >= $levels[HIGH])
		return HIGH;
	else if ($priority >= $levels[MEDIUM])
		return MEDIUM;
	else
		return LOW;
}



// --------------------------------------------------------------------------------------
/**
 * Get the named php ini variable but return it as a bool
 * @author Copyright (C) 2000 - 2004  Mantis Team, Kenzaburo Ito
 */
function ini_get_bool( $p_name ) {
	$result = ini_get( $p_name );

	if ( is_string( $result ) ) {
		switch ( $result ) {
			case 'off':
			case 'false':
			case 'no':
			case 'none':
			case '':
			case '0':
				return false;
				break;
			case 'on':
			case 'true':
			case 'yes':
			case '1':
				return true;
				break;
		}
	} else {
		return (bool)$result;
	}
}


/** @TODO martin: this is specific library and cannot be loaded via common.php
 * USE EXTRA LIBRARY             
// Contributed code - manish
$phpxmlrpc = TL_ABS_PATH . 'third_party'. DIRECTORY_SEPARATOR . 'phpxmlrpc' . 
             DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR;
require_once($phpxmlrpc . 'xmlrpc.inc');
require_once($phpxmlrpc . 'xmlrpcs.inc');
require_once($phpxmlrpc . 'xmlrpc_wrappers.inc');
*/


/**
* Initiate the execution of a testcase through XML Server RPCs.
* All the object instantiations are done here.
* XML-RPC Server Settings need to be configured using the custom fields feature.
* Three fields each for testcase level and testsuite level are required.
* The fields are: server_host, server_port and server_path.
* Precede 'tc_' for custom fields assigned to testcase level.
*
* @param $testcase_id: The testcase id of the testcase to be executed
* @param $tree_manager: The tree manager object to read node values and testcase and parent ids.
* @param $cfield_manager: Custom Field manager object, to read the XML-RPC server params.
* @return map:
*         keys: 'result','notes','message'
*         values: 'result' -> (Pass, Fail or Blocked)
*                 'notes' -> Notes text
*                 'message' -> Message from server
*/
/*
function executeTestCase($testcase_id,$tree_manager,$cfield_manager){

	//Fetching required params from the entire node hierarchy
	$server_params = $cfield_manager->getXMLServerParams($testcase_id);

  $ret=array('result'=>AUTOMATION_RESULT_KO,
             'notes'=>AUTOMATION_NOTES_KO, 'message'=>'');

	$server_host = "";
	$server_port = "";
	$server_path = "";
  $do_it=false;
	if( ($server_params != null) or $server_params != ""){
		$server_host = $server_params["xml_server_host"];
		$server_port = $server_params["xml_server_port"];
		$server_path = $server_params["xml_server_path"];
	  $do_it=true;
	}

  if($do_it)
  {
  	// Make an object to represent our server.
  	// If server config objects are null, it returns an array with appropriate values
  	// (-1 for executions results, and fault code and error message for message.
  	$xmlrpc_client = new xmlrpc_client($server_path,$server_host,$server_port);

  	$tc_info = $tree_manager->get_node_hierachy_info($testcase_id);
  	$testcase_name = $tc_info['name'];

  	//Create XML-RPC Objects to pass on to the the servers
  	$myVar1 = new xmlrpcval($testcase_name,'string');
  	$myvar2 = new xmlrpcval($testcase_id,'string');

  	$messageToServer = new xmlrpcmsg('ExecuteTest', array($myVar1,$myvar2));
  	$serverResp = $xmlrpc_client->send($messageToServer);

  	$myResult=AUTOMATION_RESULT_KO;
  	$myNotes=AUTOMATION_NOTES_KO;

  	if(!$serverResp) {
  		$message = lang_get('test_automation_server_conn_failure');
  	} elseif ($serverResp->faultCode()) {
  		$message = lang_get("XMLRPC_error_number") . $serverResp->faultCode() . ": ".$serverResp->faultString();
  	}
  	else {
  		$message = lang_get('test_automation_exec_ok');
  		$arrayVal = $serverResp->value();
  		$myResult = $arrayVal->arraymem(0)->scalarval();
  		$myNotes = $arrayVal->arraymem(1)->scalarval();
  	}
  	$ret = array('result'=>$myResult, 'notes'=>$myNotes, 'message'=>$message);
  } //$do_it

	return $ret;
} // function end
*/


// --------------------------------------------------------------------------------------
// MHT: I'm not able find a simple SQL (subquery is not supported
// in MySQL 4.0.x); probably temporary table should be used instead of the next
function array_diff_byId ($arrAll, $arrPart)
{
	// solve empty arrays
	if (!count($arrAll) || is_null($arrAll))
	{
		return(null);
	}
	if (!count($arrPart) || is_null($arrPart))
	{
		return $arrAll;
	}

	$arrTemp = array();
	$arrTemp2 = array();

	// converts to associated arrays
	foreach ($arrAll as $penny) {
		$arrTemp[$penny['id']] = $penny;
	}
	foreach ($arrPart as $penny) {
		$arrTemp2[$penny['id']] = $penny;
	}

	// exec diff
	$arrTemp3 = array_diff_assoc($arrTemp, $arrTemp2);

	$arrTemp4 = null;
	// convert to numbered array
	foreach ($arrTemp3 as $penny) {
		$arrTemp4[] = $penny;
	}
	return $arrTemp4;
}


// --------------------------------------------------------------------------------------
/**
 * trim string and limit to N chars
 * @param string
 * @param int [len]: how many chars return
 *
 * @return string trimmed string
 *
 * @author Francisco Mancardi - 20050905 - refactoring
 */
function trim_and_limit($s, $len = 100)
{
	$s = trim($s);
	if (tlStringLen($s) > $len) {
		$s = tlSubStr($s, 0, $len);
	}

	return $s;
}

// --------------------------------------------------------------------------------------
//
// nodes_order format:  NODE_ID-?,NODE_ID-?
// 2-0,10-0,3-0
//
function transform_nodes_order($nodes_order,$node_to_exclude=null)
{
  $fa = explode(',',$nodes_order);

  foreach($fa as $key => $value)
  {
	// $value= X-Y
	$fb = explode('-',$value);

	if( is_null($node_to_exclude) || $fb[0] != $node_to_exclude)
  {
     $nodes_id[]=$fb[0];
  }
  }

  return $nodes_id;
}


// --------------------------------------------------------------------------------------
/**
 * Checks $_FILES for errors while uploading
 * @param array $fInfo an array used by uploading files ($_FILES)
 * @return string containing an error message (if any)
 */
function getFileUploadErrorMessage($fInfo)
{
	$msg = null;
	if (isset($fInfo['error']))
	{
		switch($fInfo['error'])
		{
			case UPLOAD_ERR_INI_SIZE:
				$msg = lang_get('error_file_size_larger_than_maximum_size_check_php_ini');
				break;
			case UPLOAD_ERR_FORM_SIZE:
				$msg = lang_get('error_file_size_larger_than_maximum_size');
				break;
			case UPLOAD_ERR_PARTIAL:
			case UPLOAD_ERR_NO_FILE:
				$msg = lang_get('error_file_upload');
				break;
		}
	}
	return $msg;
}


// --------------------------------------------------------------------------------------
/**
 * @abstract redirect to a page with static html defined in locale/en_GB/texts.php
 * @param string $key keyword for finding exact html text in definition array
 * @return N/A 
 */
function show_instructions($key, $refreshTree=0)
{
    $myURL = $_SESSION['basehref'] . "lib/general/staticPage.php?key={$key}";
    
    if( $refreshTree )
    {
        $myURL .= "&refreshTree=1";  
    }
  	redirect($myURL);
}


// --------------------------------------------------------------------------------------
/*
  function: templateConfiguration
  args :
  returns:
*/
function templateConfiguration()
{
	$path_parts=explode("/",dirname($_SERVER['SCRIPT_NAME']));
    $last_part=array_pop($path_parts);
    
    $tcfg = new stdClass();
    $tcfg->template_dir = "{$last_part}/";
    $tcfg->default_template = str_replace('.php','.tpl',basename($_SERVER['SCRIPT_NAME']));
    $tcfg->template = null;
    return $tcfg;
}


// --------------------------------------------------------------------------------------
/*
  function: isValidISODateTime
            check if an string is a valid ISO date/time
            accepted format: YYYY-MM-DD HH:MM:SS

  args: datetime to check

  returns: true / false
  
  rev: 20080907 - franciscom - Code taked form PHP manual
*/
function isValidISODateTime($ISODateTime)
{
   $dateParts=array('YEAR' => 1, 'MONTH' => 2 , 'DAY' => 3);
   
   $matches=null;
   $status_ok=false;
   if (preg_match("/^(\d{4})-(\d{2})-(\d{2}) ([01][0-9]|2[0-3]):([0-5][0-9]):([0-5][0-9])$/", $ISODateTime, $matches)) 
   {
       $status_ok=checkdate($matches[$dateParts['MONTH']],$matches[$dateParts['DAY']],$matches[$dateParts['YEAR']]);
   }
   return $status_ok;
}

function checkUserRightsFor(&$db,$pfn)
{
	$script = basename($_SERVER['PHP_SELF']);
	$currentUser = $_SESSION['currentUser'];
	$bExit = false;
	$action = null;
	if (!$pfn($db,$currentUser,$action))
	{
		if (!$action)
			$action = "any";
		logAuditEvent(TLS("audit_security_user_right_missing",$currentUser->login,$script,$action),$action,$currentUser->dbID,"users");
		$bExit = true;
	}
	if ($bExit)
	{  	
		$myURL = $_SESSION['basehref'];
	  	redirect($myURL,"top.location");
		exit();
	}
}

function tlStringLen($str)
{
	$charset = config_get('charset');	
	$nLen = iconv_strlen($str,$charset);
	if ($nLen === false)
	{
		throw new Exception("Invalid UTF-8 Data detected!");
	}
	return $nLen; 
}

function tlSubStr($str,$start,$length = null)
{
	$charset = config_get('charset');
	if ($length === null)
	{
		$length = iconv_strlen($str,$charset);
	}	
	return iconv_substr($str,$start,$length,$charset);
}
?>