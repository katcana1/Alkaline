<?php

/*
// Alkaline
// Copyright (c) 2010-2011 by Budin Ltd. All rights reserved.
// Do not redistribute this code without written permission from Budin Ltd.
// http://www.alkalinenapp.com/
*/

/**
 * @author Budin Ltd. <contact@budinltd.com>
 * @copyright Copyright (c) 2010-2011, Budin Ltd.
 * @version 1.0
 */

function __autoload($class){
	$file = strtolower($class) . '.php';
	require_once(PATH . CLASSES . $file);
}

class Alkaline{
	const build = 605;
	const copyright = 'Powered by <a href="http://www.alkalineapp.com/">Alkaline</a>. Copyright &copy; 2010-2011 by <a href="http://www.budinltd.com/">Budin Ltd.</a> All rights reserved.';
	const edition = 'standard';
	const product = 'Alkaline';
	const version = '1.0';
	
	public $db_type;
	public $db_version;
	public $tables;
	
	protected $db;
	protected $notifications;
	
	private static $validated;
	
	/**
	 * Initiates Alkaline
	 *
	 * @return void
	 **/
	public function __construct(){
		// Send browser headers
		if(!headers_sent()){
			header('Cache-Control: no-cache, must-revalidate', false);
			header('Expires: Sat, 26 Jul 1997 05:00:00 GMT', false);
		}
		
		// Set error handler
		set_error_handler(array($this, 'addError'), E_ALL);
		
		// Determine class
		$class = get_class($this);
		
		// Begin a session, if one does not yet exist
		if(session_id() == ''){ session_start(); }
		
		// Debug info
		if(get_class($this) == 'Alkaline'){
			$_SESSION['alkaline']['debug']['start_time'] = microtime(true);
			$_SESSION['alkaline']['debug']['queries'] = 0;
			$_SESSION['alkaline']['config'] = json_decode(@file_get_contents($this->correctWinPath(PATH . 'config.json')), true);
			
			if(empty($_SESSION['alkaline']['config'])){
				$_SESSION['alkaline']['config'] = array();
			}
			
			if($timezone = $this->returnConf('web_timezone')){
				date_default_timezone_set($timezone);
			}
			else{
				date_default_timezone_set('GMT');
			}
		}
		
		// Write tables
		$this->tables = array('photos' => 'photo_id', 'tags' => 'tag_id', 'comments' => 'comment_id', 'piles' => 'pile_id', 'pages' => 'page_id', 'rights' => 'right_id', 'exifs' => 'exif_id', 'extensions' => 'extension_id', 'themes' => 'theme_id', 'sizes' => 'size_id', 'users' => 'user_id', 'guests' => 'guest_id');
		
		// Set back link
		if(!empty($_SERVER['HTTP_REFERER']) and ($_SERVER['HTTP_REFERER'] != LOCATION . $_SERVER['REQUEST_URI'])){
			$_SESSION['alkaline']['back'] = $_SERVER['HTTP_REFERER'];
		} 
		
		// Initiate database connection, if necessary
		$no_db_classes = array('Canvas');
		
		if(!in_array($class, $no_db_classes)){
			if(defined('DB_TYPE') and defined('DB_DSN')){
				// Determine database type
				$this->db_type = DB_TYPE;
			
				if($this->db_type == 'mssql'){
					// $this->db = new PDO(DB_DSN);
				}
				elseif($this->db_type == 'mysql'){
					$this->db = new PDO(DB_DSN, DB_USER, DB_PASS, array(PDO::ATTR_PERSISTENT => true, PDO::FETCH_ASSOC => true, PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT));
				}
				elseif($this->db_type == 'pgsql'){
					$this->db = new PDO(DB_DSN, DB_USER, DB_PASS);
					$this->db->setAttribute(PDO::ATTR_ORACLE_NULLS, PDO::NULL_EMPTY_STRING);
				}
				elseif($this->db_type == 'sqlite'){
					$this->db = new PDO(DB_DSN, null, null, array(PDO::ATTR_PERSISTENT => true, PDO::FETCH_ASSOC => true, PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT));
				
					$this->db->sqliteCreateFunction('ACOS', 'acos', 1);
					$this->db->sqliteCreateFunction('COS', 'cos', 1);
					$this->db->sqliteCreateFunction('RADIANS', 'deg2rad', 1);
					$this->db->sqliteCreateFunction('SIN', 'sin', 1);
				}
				
				$this->db_version = $this->db->getAttribute(PDO::ATTR_SERVER_VERSION);
			}
		}
		
		// Delete saved Orbit extension session references
		if($class == 'Alkaline'){
			unset($_SESSION['alkaline']['extensions']);
		}
		
		// Check license
		if(empty(Alkaline::$validated)){
			if(!$this->validate()){
				$this->addError(E_USER_ERROR, 'Your Alkaline license is invalid or missing');
			}
		}
	}
	
	/**
	 * Terminates object, closes the database connection
	 *
	 * @return void
	 **/
	public function __destruct(){
		$this->db = null;
	}
	
	// DATABASE
	
	/**
	 * Prepares and executes SQL statement
	 *
	 * @param string $query Query
	 * @return int Number of affected rows
	 */
	public function exec($query){
		if(!$this->db){ $this->addError(E_USER_ERROR, 'No database connection'); }
		
		$this->prequery($query);
		$response = $this->db->exec($query);
		$this->postquery($query);
		
		return $response;
	}
	
	/**
	 * Prepares a statement for execution and returns a statement object
	 *
	 * @param string $query Query
	 * @return PDOStatement
	 */
	public function prepare($query){
		if(!$this->db){ $this->addError(E_USER_ERROR, 'No database connection'); }
		
		$this->prequery($query);
		$response = $this->db->prepare($query, array(PDO::ATTR_CURSOR => PDO::CURSOR_FWDONLY));
		$this->postquery($query);
		
		if(!$response){ $this->addError(E_USER_ERROR, 'Invalid query, check database connection'); }
		
		return $response;
	}
	
	/**
	 * Translate query for different database types
	 *
	 * @param string $query Query
	 * @return string Translated query
	 */
	public function prequery(&$query){
		$_SESSION['alkaline']['debug']['queries']++;
		
		if(TABLE_PREFIX != ''){
			// Add table prefix
			$query = preg_replace('#(FROM|JOIN)\s+([\sa-z0-9_\-,]*)\s*(WHERE|GROUP|HAVING|ORDER)?#se', "'\\1 '.Alkaline::appendTablePrefix('\\2').' \\3'", $query);
			$query = preg_replace('#([a-z]+[a-z0-9-\_]*)\.#si', TABLE_PREFIX . '\\1.', $query);
			$query = preg_replace('#(INSERT INTO|UPDATE)\s+(\w+)#si', '\\1 ' . TABLE_PREFIX . '\\2', $query);
		}
		
		if($this->db_type == 'mssql'){
			/*
			preg_match('#GROUP BY (.*) ORDER BY#si', $query, $match);
			$find = @$match[0];
			if(!empty($find)){
				$replace = $find;
				$replace = str_replace('stat_day', 'DAY(stat_date)', $replace);
				$replace = str_replace('stat_month', 'MONTH(stat_date)', $replace);
				$replace = str_replace('stat_year', 'YEAR(stat_date)', $replace);
				$query = str_replace($find, $replace, $query);
			}
			
			if(preg_match('#SELECT (?:.*) LIMIT[[:space:]]+([0-9]+),[[:space:]]*([0-9]+)#si', $query, $match)){
				$query = preg_replace('#LIMIT[[:space:]]+([0-9]+),[[:space:]]*([0-9]+)#si', '', $query);
				$offset = @$match[1];
				$limit = @$match[2];
				preg_match('#FROM (.+?)(?:\s|,)#si', $query, $match);
				$table = @$match[1];
				$query = str_replace('SELECT ', 'SELECT TOP 999999999999999999 ROW_NUMBER() OVER (ORDER BY ' . $this->tables[$table]  . ' ASC) AS row_number,', $query);
				$query = 'SELECT * FROM (' . $query . ') AS temp WHERE temp.row_number > ' . $offset . ' AND temp.row_number <= ' . ($offset + $limit);
			}
			*/
		}
		elseif($this->db_type == 'pgsql'){
			$query = preg_replace('#LIMIT[[:space:]]+([0-9]+),[[:space:]]*([0-9]+)#si', 'LIMIT \2 OFFSET \1', $query);
			$query = str_replace('HOUR(', 'EXTRACT(HOUR FROM ', $query);
			$query = str_replace('DAY(', 'EXTRACT(DAY FROM ', $query);
			$query = str_replace('MONTH(', 'EXTRACT(MONTH FROM ', $query);
			$query = str_replace('YEAR(', 'EXTRACT(YEAR FROM ', $query);
		}
		elseif($this->db_type == 'sqlite'){
			$query = str_replace('HOUR(', 'strftime("%H",', $query);
			$query = str_replace('DAY(', 'strftime("%d",', $query);
			$query = str_replace('MONTH(', 'strftime("%m",', $query);
			$query = str_replace('YEAR(', 'strftime("%Y",', $query);
		}
		
		$query = trim($query);
	}
	
	/**
	 * Append table prefix to table names (before executing query)
	 *
	 * @param string $tables Comma-separated tables
	 * @return string Comma-separated tables
	 */
	protected function appendTablePrefix($tables){
		if(strpos($tables, ',') === false){
			$tables = trim($tables);
			$tables = TABLE_PREFIX . $tables;
		}
		else{
			$tables = explode(',', $tables);
			$tables = array_map('trim', $tables);
			foreach($tables as &$table){
				$table = TABLE_PREFIX . $table;
			}
			$tables = implode(', ', $tables);
		}
		return $tables;
	}
	
	/**
	 * Determine if query was successful; if not, log it using report()
	 *
	 * @param string $query
	 * @param string $db 
	 * @return bool True if successful
	 */
	public function postquery(&$query, $db=null){
		if(empty($db)){ $db = $this->db; }
		
		$error = $db->errorInfo();
		
		if(isset($error[2])){
			$code = $error[0];
			$message = $query . ' ' . ucfirst(preg_replace('#^Error\:[[:space:]]+#si', '', $error[2])) . ' (' . $code . ').';
			
			if(substr($code, 0, 2) == '00'){
				// $this->report($message, $code);
			}
			elseif($code == '23000'){
				$this->report($message, $code);
				return false;
			}
			else{
				$this->report($message, $code);
				return false;
			}
		}
		
		return true;
	}
	
	/**
	 * Remove nulls from a JSON string
	 *
	 * @param string $input JSON input
	 * @return string JSON ouput
	 */
	public function removeNull($input){
		return str_replace(':null', ':""', $input);
	}
	
	/**
	 * Retrieve data from http://www.alkalineapp.com/
	 *
	 * @param string $request Request
	 * @return string Response
	 */
	public function boomerang($request){
		ini_set('default_socket_timeout', 1);
		$contents = @file_get_contents('http://www.alkalineapp.com/boomerang/' . $request . '/');
		ini_restore('default_socket_timeout');
		
		if(empty($contents)){
			$this->addNote('Alkaline could not connect to <a href="http://www.alkalineapp.com/">alkalineapp.com</a> to retrieve data.', 'notice');
		}
		
		$reply = self::removeNull(json_decode($contents, true));
		return $reply;
	}	
	
	// GUESTS
	
	/**
	 * Authenticate guest access
	 *
	 * @param string $key Guest access key
	 * @return void Redirects if unsuccessful
	 */
	public function access($key=null){
		// Error checking
		if(empty($key)){ return false; }
		
		$key = strip_tags($key);
		
		$query = $this->prepare('SELECT * FROM guests WHERE guest_key = :guest_key LIMIT 0, 1;');
		$query->execute(array(':guest_key' => $key));
		$guests = $query->fetchAll();
		$guest = $guests[0];
		
		if(!$guest){
			$this->addError(E_USER_ERROR, 'You are not authorized');
		}
		
		$_SESSION['alkaline']['guest'] = $guest;
	}
	
	// NOTIFICATIONS
	
	/**
	 * Add a notification
	 *
	 * @param string $message Message
	 * @param string $type Notification type (usually 'success', 'error', or 'notice')
	 * @return void
	 */
	public function addNote($message, $type=null){
		$_SESSION['alkaline']['notifications'][] = array('type' => $type, 'message' => $message);
	}
	
	/**
	 * Check notifications
	 *
	 * @param string $type Notification type
	 * @return int Number of notifications
	 */
	public function countNotes($type=null){
		if(!empty($type)){
			$notifications = @$_SESSION['alkaline']['notifications'];
			$count = @count($notifications);
			if($count > 0){
				$count = 0;
				foreach($notifications as $notification){
					if($notification['type'] == $type){
						$count++;
					}
				}
				if($count > 0){
					return $count;
				}
			}			
		}
		else{
			$count = @count($_SESSION['alkaline']['notifications']);
			if($count > 0){
				return $count;
			}
		}
		
		return 0;
	}
	
	/**
	 * View notifications
	 *
	 * @param string $type Notification type
	 * @return string HTML-formatted notifications 
	 */
	public function returnNotes($type=null){
		if(!isset($_SESSION['alkaline']['notifications'])){ return; }
		
		$count = count($_SESSION['alkaline']['notifications']);
		
		if($count == 0){ return; }
		
		$return = '';
		
		// Determine unique types
		$types = array();
		foreach($_SESSION['alkaline']['notifications'] as $notifications){
			$types[] = $notifications['type'];
		}
		$types = array_unique($types);
		
		// Produce HTML for display
		foreach($types as $type){
			$return = '<p class="' . $type . '">';
			$messages = array();
			foreach($_SESSION['alkaline']['notifications'] as $notification){
				if($notification['type'] == $type){
					$messages[] = $notification['message'];
				}
			}
			$return .= implode(' ', $messages) . '</p>';
		}
		
		$return .= '<br />';

		// Dispose of messages
		unset($_SESSION['alkaline']['notifications']);
		
		return $return;
	}
	
	// FILE HANDLING
	
	/**
	 * Browse a local directory (non-recursive) for filenames
	 *
	 * @param string $dir Full path to directory
	 * @param string $ext File extensions to seek
	 * @return array Full paths of files
	 */
	public function seekDirectory($dir=null, $ext=IMG_EXT){
		// Error checking
		if(empty($dir)){
			return false;
		}
		
		// Windows-friendly
		$dir = $this->correctWinPath($dir);
		
		$files = array();
		$ignore = array('.', '..');
		
		// Open listing
		$handle = opendir($dir);
		
		// Seek directory
		while($filename = readdir($handle)){
			if(!in_array($filename, $ignore)){ 
				// Recusively check directories
				/*
				if(is_dir($dir . '/' . $filename)){
					self::seekDirectory($dir . $filename . '/', $files);
				}
				*/
				
				if(!empty($ext)){
					// Find files with proper extensions
					if(preg_match('#([a-zA-Z0-9\-\_]+\.(' . $ext . '){1,1})#si', $filename)){
						$files[] = $dir . $filename;
					}
				}
				else{
					$files[] = $dir . $filename;
				}
			}
	    }
	
		// Close listing
		closedir($handle);
		
		return $files;
	}
	
	/**
	 * Browse a local directory (non-recursive) for file count
	 *
	 * @param string $dir Full path to directory
	 * @param string $ext File extensions to seek
	 * @return int Number of files
	 */
	public function countDirectory($dir=null, $ext=IMG_EXT){
		// Error checking
		if(empty($dir)){
			return false;
		}
		
		$files = self::seekDirectory($dir, $ext);
		
		return count($files);
	}
	
	/**
	 * Determine a filename from a path
	 *
	 * @param string $file Full or relative file path
	 * @return string|false Filename (including extension) or error
	 */
	public function getFilename($file){
		$matches = array();
		
		// Windows cheat
		$file = str_replace('\\', '/', $file);
		
		preg_match('#^(.*/)?(?:$|(.+?)(?:(\.[^.]*$)|$))#si', $file, $matches);
		
		if(count($matches) < 1){
			return false;
		}
		
		$filename = $matches[2];
		
		if(isset($matches[3])){
			$filename .= $matches[3];
		}
		
		return $filename;
	}
	
	/**
	 * Empty a directory
	 *
	 * @param string $dir Full path to directory
	 * @return void
	 */
	public function emptyDirectory($dir=null){
		// Error checking
		if(empty($dir)){
			return false;
		}
		
		$ignore = array('.', '..');
		
		// Open listing
		$handle = opendir($dir);
		
		// Seek directory
		while($filename = readdir($handle)){
			if(!in_array($filename, $ignore)){
				// Delete directories
				if(is_dir($dir . '/' . $filename)){
					self::emptyDirectory($dir . $filename . '/');
					@rmdir($dir . $filename . '/');
				}
				// Delete files
				else{
					chmod($dir . $filename, 0777);
					unlink($dir . $filename);
				}
			}
	    }
	
		// Close listing
		closedir($handle);
	}
	
	/**
	 * Check file permissions
	 *
	 * @param string $file Full path to file
	 * @return string Octal value (e.g., 0644)
	 */
	public function checkPerm($file){
		return substr(sprintf('%o', @fileperms($file)), -4);
	}
	
	/**
	 * Replace a variable's value in a PHP file (for installation)
	 *
	 * @param string $var Variable (e.g., $var)
	 * @param string $replacement Full line replacement (e.g., $var = 'dog';)
	 * @param string $subject Subject input
	 * @return string Subject output
	 */
	public function replaceVar($var, $replacement, $subject){
		return preg_replace('#^\s*' . str_replace('$', '\$', $var) . '\s*=(.*)$#mi', $replacement, $subject);
	}
	
	// TYPE CONVERSION
	
	/**
	 * Convert a possible string or integer into an array
	 *
	 * @param mixed $input
	 * @return array
	 */
	public function convertToArray(&$input){
		if(is_string($input)){
			$find = strpos($input, ',');
			if($find === false){
				$input = array($input);
			}
			else{
				$input = explode(',', $input);
				$input = array_map('trim', $input);
			}
		}
		elseif(is_int($input)){
			$input = array($input);
		}
		return $input;
	}
	
	/**
	 * Convert a possible string or integer into an array of integers
	 *
	 * @param mixed $input 
	 * @return array
	 */
	public function convertToIntegerArray(&$input){
		if(is_int($input)){
			$input = array($input);
		}
		elseif(is_string($input)){
			$find = strpos($input, ',');
			if($find === false){
				$input = array(intval($input));
			}
			else{
				$input = explode(',', $input);
				$input = array_map('trim', $input);
			}
		}
		return $input;
	}
	
	/**
	 * Change filename extension
	 *
	 * @param string $file Filename
	 * @param string $ext Desired extension
	 * @return string Changed filename
	 */
	public function changeExt($file, $ext){
		$file = preg_replace('#\.([a-z0-9]*)$#si', '.' . $ext, $file);
		return $file;
	}
	
	// TIME FORMATTING
	
	/**
	 * Make time more human-readable
	 *
	 * @param string $time Time
	 * @param string $format Format (as in date();)
	 * @param string $empty If null or empty input time, return this string
	 * @return string|false Time or error
	 */
	public function formatTime($time, $format=null, $empty=false){
		// Error checking
		if(empty($time) or ($time == '0000-00-00 00:00:00')){
			return $empty;
		}
		if(empty($format)){
			$format = DATE_FORMAT;
		}
		
		$time = str_replace('tonight', 'today', $time);
		$time = @strtotime($time);
		$time = date($format, $time);
		
		$ampm = array(' am', ' pm');
		$ampm_correct = array(' a.m.', ' p.m.');
		
		$time = str_replace($ampm, $ampm_correct, $time);
		
		return $time;
	}
	
	/**
	 * Make time relative
	 *
	 * @param string $time Time
	 * @param string $format Format (as in date();)
	 * @param string $empty If null or empty input time, return this string
	 * @return string|false Time or error
	 */
	public function formatRelTime($time, $format=null, $empty=false){
		// Error checking
		if(empty($time) or ($time == '0000-00-00 00:00:00')){
			return $empty;
		}
		if(empty($format)){
			$format = DATE_FORMAT;
		}
		
		$time = @strtotime($time);
		$seconds = time() - $time;
		
		switch($seconds){
			case($seconds < 3600):
				$minutes = intval($seconds / 60);
				if($minutes < 2){ $span = 'a minute ago'; }
				else{ $span = $minutes . ' minutes ago'; }
				break;
			case($seconds < 86400):
				$hours = intval($seconds / 3600);
				if($hours < 2){ $span = 'an hour ago'; }
				else{ $span = $hours . ' hours ago'; }
				break;
			case($seconds < 2419200):
				$days = intval($seconds / 86400);
				if($days < 2){ $span = 'yesterday'; }
				else{ $span = $days . ' days ago'; }
				break;
			case($seconds < 29030400):
				$months = intval($seconds / 2419200);
				if($months < 2){ $span = 'a month ago'; }
				else{ $span = $months . ' months ago'; }
				break;
			default:
				$span = date($format, $time);
				break;
		}
		
		return $span;
	}
	
	/**
	 * Convert numerical month to written month (U.S. English)
	 *
	 * @param string|int $num Numerical month (e.g., 01)
	 * @return string|false Written month (e.g., January) or error
	 */
	public function numberToMonth($num){
		$int = intval($num);
		switch($int){
			case 1:
				return 'January';
				break;
			case 2:
				return 'February';
				break;
			case 3:
				return 'March';
				break;
			case 4:
				return 'April';
				break;
			case 5:
				return 'May';
				break;
			case 6:
				return 'June';
				break;
			case 7:
				return 'July';
				break;
			case 8:
				return 'August';
				break;
			case 9:
				return 'September';
				break;
			case 10:
				return 'October';
				break;
			case 11:
				return 'November';
				break;
			case 12:
				return 'December';
				break;
			default:
				return false;
				break;
		}
	}
	
	/**
	 * Convert number to words (U.S. English)
	 *
	 * @param string $num
	 * @param string $power
	 * @param string $powsuffix
	 * @return string
	 */
	public function numberToWords($num, $power = 0, $powsuffix = ''){
		$_minus = 'minus'; // minus sign
		
	    $_exponent = array(
	        0 => array(''),
	        3 => array('thousand'),
	        6 => array('million'),
	        9 => array('billion'),
	       12 => array('trillion'),
	       15 => array('quadrillion'),
	       18 => array('quintillion'),
	       21 => array('sextillion'),
	       24 => array('septillion'),
	       27 => array('octillion'),
	       30 => array('nonillion'),
	       33 => array('decillion'),
	       36 => array('undecillion'),
	       39 => array('duodecillion'),
	       42 => array('tredecillion'),
	       45 => array('quattuordecillion'),
	       48 => array('quindecillion'),
	       51 => array('sexdecillion'),
	       54 => array('septendecillion'),
	       57 => array('octodecillion'),
	       60 => array('novemdecillion'),
	       63 => array('vigintillion'),
	       66 => array('unvigintillion'),
	       69 => array('duovigintillion'),
	       72 => array('trevigintillion'),
	       75 => array('quattuorvigintillion'),
	       78 => array('quinvigintillion'),
	       81 => array('sexvigintillion'),
	       84 => array('septenvigintillion'),
	       87 => array('octovigintillion'),
	       90 => array('novemvigintillion'),
	       93 => array('trigintillion'),
	       96 => array('untrigintillion'),
	       99 => array('duotrigintillion'),
	       // 100 => array('googol') - not latin name
	       // 10^googol = 1 googolplex
	      102 => array('trestrigintillion'),
	      105 => array('quattuortrigintillion'),
	      108 => array('quintrigintillion'),
	      111 => array('sextrigintillion'),
	      114 => array('septentrigintillion'),
	      117 => array('octotrigintillion'),
	      120 => array('novemtrigintillion'),
	      123 => array('quadragintillion'),
	      126 => array('unquadragintillion'),
	      129 => array('duoquadragintillion'),
	      132 => array('trequadragintillion'),
	      135 => array('quattuorquadragintillion'),
	      138 => array('quinquadragintillion'),
	      141 => array('sexquadragintillion'),
	      144 => array('septenquadragintillion'),
	      147 => array('octoquadragintillion'),
	      150 => array('novemquadragintillion'),
	      153 => array('quinquagintillion'),
	      156 => array('unquinquagintillion'),
	      159 => array('duoquinquagintillion'),
	      162 => array('trequinquagintillion'),
	      165 => array('quattuorquinquagintillion'),
	      168 => array('quinquinquagintillion'),
	      171 => array('sexquinquagintillion'),
	      174 => array('septenquinquagintillion'),
	      177 => array('octoquinquagintillion'),
	      180 => array('novemquinquagintillion'),
	      183 => array('sexagintillion'),
	      186 => array('unsexagintillion'),
	      189 => array('duosexagintillion'),
	      192 => array('tresexagintillion'),
	      195 => array('quattuorsexagintillion'),
	      198 => array('quinsexagintillion'),
	      201 => array('sexsexagintillion'),
	      204 => array('septensexagintillion'),
	      207 => array('octosexagintillion'),
	      210 => array('novemsexagintillion'),
	      213 => array('septuagintillion'),
	      216 => array('unseptuagintillion'),
	      219 => array('duoseptuagintillion'),
	      222 => array('treseptuagintillion'),
	      225 => array('quattuorseptuagintillion'),
	      228 => array('quinseptuagintillion'),
	      231 => array('sexseptuagintillion'),
	      234 => array('septenseptuagintillion'),
	      237 => array('octoseptuagintillion'),
	      240 => array('novemseptuagintillion'),
	      243 => array('octogintillion'),
	      246 => array('unoctogintillion'),
	      249 => array('duooctogintillion'),
	      252 => array('treoctogintillion'),
	      255 => array('quattuoroctogintillion'),
	      258 => array('quinoctogintillion'),
	      261 => array('sexoctogintillion'),
	      264 => array('septoctogintillion'),
	      267 => array('octooctogintillion'),
	      270 => array('novemoctogintillion'),
	      273 => array('nonagintillion'),
	      276 => array('unnonagintillion'),
	      279 => array('duononagintillion'),
	      282 => array('trenonagintillion'),
	      285 => array('quattuornonagintillion'),
	      288 => array('quinnonagintillion'),
	      291 => array('sexnonagintillion'),
	      294 => array('septennonagintillion'),
	      297 => array('octononagintillion'),
	      300 => array('novemnonagintillion'),
	      303 => array('centillion'),
	      309 => array('duocentillion'),
	      312 => array('trecentillion'),
	      366 => array('primo-vigesimo-centillion'),
	      402 => array('trestrigintacentillion'),
	      603 => array('ducentillion'),
	      624 => array('septenducentillion'),
	     // bug on a earthlink page: 903 => array('trecentillion'),
	     2421 => array('sexoctingentillion'),
	     3003 => array('millillion'),
	     3000003 => array('milli-millillion')
	        );
		
	    $_digits = array(
	        0 => 'zero', 'one', 'two', 'three', 'four',
	        'five', 'six', 'seven', 'eight', 'nine'
	    );
		
	    $_sep = ' '; // word seperator
	
        $ret = '';

        // add a minus sign
        if (substr($num, 0, 1) == '-') {
            $ret = $_sep . $_minus;
            $num = substr($num, 1);
        }

        // strip excessive zero signs and spaces
        $num = trim($num);
        $num = preg_replace('/^0+/', '', $num);

        if (strlen($num) > 3) {
            $maxp = strlen($num)-1;
            $curp = $maxp;
            for ($p = $maxp; $p > 0; --$p) { // power

                // check for highest power
                if (isset($_exponent[$p])) {
                    // send substr from $curp to $p
                    $snum = substr($num, $maxp - $curp, $curp - $p + 1);
                    $snum = preg_replace('/^0+/', '', $snum);
                    if ($snum !== '') {
                        $cursuffix = $_exponent[$power][count($_exponent[$power])-1];
                        if ($powsuffix != '') {
                            $cursuffix .= $_sep . $powsuffix;
                        }

                        $ret .= $this->toWords($snum, $p, $cursuffix);
                    }
                    $curp = $p - 1;
                    continue;
                }
            }
            $num = substr($num, $maxp - $curp, $curp - $p + 1);
            if ($num == 0) {
                return $ret;
            }
        } elseif ($num == 0 || $num == '') {
            return $_sep . $_digits[0];
        }

        $h = $t = $d = 0;

        switch(strlen($num)) {
        case 3:
            $h = (int)substr($num, -3, 1);

        case 2:
            $t = (int)substr($num, -2, 1);

        case 1:
            $d = (int)substr($num, -1, 1);
            break;

        case 0:
            return;
            break;
        }

        if ($h) {
            $ret .= $_sep . $_digits[$h] . $_sep . 'hundred';

            // in English only - add ' and' for [1-9]01..[1-9]99
            // (also for 1001..1099, 10001..10099 but it is harder)
            // for now it is switched off, maybe some language purists
            // can force me to enable it, or to remove it completely
            // if (($t + $d) > 0)
            //   $ret .= $_sep . 'and';
        }

        // ten, twenty etc.
        switch ($t) {
        case 9:
        case 7:
        case 6:
            $ret .= $_sep . $_digits[$t] . 'ty';
            break;

        case 8:
            $ret .= $_sep . 'eighty';
            break;

        case 5:
            $ret .= $_sep . 'fifty';
            break;

        case 4:
            $ret .= $_sep . 'forty';
            break;

        case 3:
            $ret .= $_sep . 'thirty';
            break;

        case 2:
            $ret .= $_sep . 'twenty';
            break;

        case 1:
            switch ($d) {
            case 0:
                $ret .= $_sep . 'ten';
                break;

            case 1:
                $ret .= $_sep . 'eleven';
                break;

            case 2:
                $ret .= $_sep . 'twelve';
                break;

            case 3:
                $ret .= $_sep . 'thirteen';
                break;

            case 4:
            case 6:
            case 7:
            case 9:
                $ret .= $_sep . $_digits[$d] . 'teen';
                break;

            case 5:
                $ret .= $_sep . 'fifteen';
                break;

            case 8:
                $ret .= $_sep . 'eighteen';
                break;
            }
            break;
        }

        if ($t != 1 && $d > 0) { // add digits only in <0>,<1,9> and <21,inf>
            // add minus sign between [2-9] and digit
            if ($t > 1) {
                $ret .= '-' . $_digits[$d];
            } else {
                $ret .= $_sep . $_digits[$d];
            }
        }

        if ($power > 0) {
            if (isset($_exponent[$power])) {
                $lev = $_exponent[$power];
            }

            if (!isset($lev) || !is_array($lev)) {
                return null;
            }

            $ret .= $_sep . $lev[0];
        }

        if ($powsuffix != '') {
            $ret .= $_sep . $powsuffix;
        }

        return $ret;
    }
	
	// FORMAT STRINGS
	
	/**
	 * Convert to Unicode (UTF-8)
	 *
	 * @param string $string 
	 * @return string
	 */
	public function makeUnicode($string){
		return mb_detect_encoding($string, 'UTF-8') == 'UTF-8' ? $string : utf8_encode($string);
	}
	
	/**
	 * Sanitize table and column names (to prevent SQL injection attacks)
	 *
	 * @param string $string 
	 * @return string
	 */
	public function sanitize($string){
		return preg_replace('#(?:(?![a-z0-9_\.-\s]).)*#si', '', $string);
	}
	
	/**
	 * Make HTML-safe quotations
	 *
	 * @param string $input 
	 * @return string
	 */
	public function makeHTMLSafe($input){
		if(is_string($input)){
			$input = self::makeHTMLSafeHelper($input);
		}
		if(is_array($input)){
			foreach($input as &$value){
				$value = self::makeHTMLSafe($value);
			}
		}
		
		return $input;
	}
	
	private function makeHTMLSafeHelper($string){
		$string = preg_replace('#\'#s', '&#0039;', $string);	
		$string = preg_replace('#\"#s', '&#0034;', $string);
		return $string;
	}
	
	/**
	 * Reverse HTML-safe quotations
	 *
	 * @param string $input 
	 * @return string
	 */
	public function reverseHTMLSafe($input){
		if(is_string($input)){
			$input = self::reverseHTMLSafeHelper($input);
		}
		if(is_array($input)){
			foreach($input as &$value){
				$value = self::reverseHTMLSafe($value);
			}
		}
		
		return $input;
	}
	
	private function reverseHTMLSafeHelper($string){
		$string = preg_replace('#\&\#0039\;#s', '\'', $string);	
		$string = preg_replace('#\&\#0034\;#s', '"', $string);
		return $string;
	}
	
	/**
	 * Strip tags from string or array
	 *
	 * @param string|array $var
	 * @return string|array
	 */
	public function stripTags($var){
		if(is_string($var)){
			$var = strip_tags($var);
		}
		elseif(is_array($var)){
			foreach($var as $key => $value){
				$var[$key] = self::stripTags($value);
			}
		}
		return $var;
	}
	
	/**
	 * Count the number of words in a string (more reliable than str_word_count();)
	 *
	 * @param string $string 
	 * @return int Word count
	 */
	public function countWords($string){
		$string = strip_tags($string);
		preg_match_all("/\S+/", $string, $matches); 
	    return count($matches[0]);
	}
	
	
	/**
	 * Return random integer using best-available algorithm
	 *
	 * @param string $min 
	 * @param string $max 
	 * @return void
	 */
	public function randInt($min=null, $max=null){
		if(function_exists('mt_rand')){
			$num = mt_rand($min, $max);
		}
		else{
			$num = rand($min, $max);
		}
		
		return $num;
	}
	
	// COMMENTS
	
	/**
	 * Add comments from $_POST data
	 *
	 * @return bool True if successful
	 */
	public function addComments(){
		// Configuration: comm_enabled
		if(!$this->returnConf('comm_enabled')){
			return false;
		}
		
		if(empty($_POST['comment_id'])){
			return false;
		}
		
		$id = self::findID($_POST['comment_id']);
		
		// Configuration: comm_mod
		if($this->returnConf('comm_mod')){
			$comment_status = 0;
		}
		else{
			$comment_status = 1;
		}
		
		$comment_text_raw = $this->makeUnicode(strip_tags($_POST['comment_' . $id .'_text']));
		
		$orbit = new Orbit;
		
		// Configuration: comm_markup
		if($this->returnConf('comm_markup')){
			$comm_markup_ext = $this->returnConf('comm_markup_ext');
			$comment_text = $orbit->hook('markup_' . $comm_markup_ext, $comment_text_raw, null);
		}
		
		if(!isset($comment_text)){
			$comm_markup_ext = '';
			$comment_text = nl2br($comment_text_raw);
		}
		
		$fields = array('photo_id' => $id,
			'comment_status' => $comment_status,
			'comment_text' => $comment_text,
			'comment_text_raw' => $comment_text_raw,
			'comment_markup' => $comm_markup_ext,
			'comment_author_name' => $comment_text,
			'comment_author_uri' => strip_tags($_POST['comment_' . $id .'_author_uri']),
			'comment_author_email' => strip_tags($_POST['comment_' . $id .'_author_email']),
			'comment_author_ip' => $_SERVER['REMOTE_ADDR']);
		
		$fields = $orbit->hook('comment_add', $fields, $fields);
		
		if(!$this->addRow($fields, 'comments')){
			return false;
		}
		
		if($this->returnConf('comm_email')){
			$this->email(0, 'New comment', 'A new comment has been submitted:' . "\r\n\n" . $comment_text);
		}
		
		$this->updateCount('comments', 'photos', 'photo_comment_count', $id);
		
		return true;
	}
	
	// TABLE COUNTING
	
	/**
	 * Update count of single field
	 *
	 * @param string $count_table 
	 * @param string $result_table 
	 * @param string $result_field 
	 * @param string $result_id 
	 * @return bool True if successful
	 */
	public function updateCount($count_table, $result_table, $result_field, $result_id){
		$result_id = intval($result_id);
		
		$count_table = $this->sanitize($count_table);
		$result_table = $this->sanitize($result_table);
		
		$count_id_field = $this->tables[$count_table];
		$result_id_field = $this->tables[$result_table];
		
		// Get count
		$query = $this->prepare('SELECT COUNT(' . $count_id_field . ') AS count FROM ' . $count_table . ' WHERE ' . $result_id_field  . ' = :result_id;');
		
		if(!$query->execute(array(':result_id' => $result_id))){
			return false;
		}
		
		$counts = $query->fetchAll();
		$count = $counts[0]['count'];
		
		// Update row
		$query = $this->prepare('UPDATE ' . $result_table . ' SET ' . $result_field . ' = :count WHERE ' . $result_id_field . ' = :result_id;');
		
		if(!$query->execute(array(':count' => $count, ':result_id' => $result_id))){
			return false;
		}
		
		return true;
	}
	
	/**
	 * Update count of entire column
	 *
	 * @param string $count_table 
	 * @param string $result_table 
	 * @param string $result_field 
	 * @return bool True if successful
	 */
	public function updateCounts($count_table, $result_table, $result_field){
		$count_table = $this->sanitize($count_table);
		$result_table = $this->sanitize($result_table);
		
		$count_id_field = $this->tables[$count_table];
		$result_id_field = $this->tables[$result_table];
		
		$results = $this->getTable($result_table);
		
		// Get count
		$select = $this->prepare('SELECT COUNT(' . $count_id_field . ') AS count FROM ' . $count_table . ' WHERE ' . $result_id_field  . ' = :result_id;');
		
		// Update row
		$update = $this->prepare('UPDATE ' . $result_table . ' SET ' . $result_field . ' = :count WHERE ' . $result_id_field . ' = :result_id;');
		
		foreach($results as $result){
			$result_id = $result[$result_id_field];
			if(!$select->execute(array(':result_id' => $result_id))){
				return false;
			}
		
			$counts = $select->fetchAll();
			$count = $counts[0]['count'];
		
			if(!$update->execute(array(':count' => $count, ':result_id' => $result_id))){
				return false;
			}
		}
		
		return true;
	}
	
	// RETRIEVE LIBRARY DATA
	
	/**
	 * Get all table row counts
	 *
	 * @return array Tables and their row counts
	 */
	public function getInfo(){
		$info = array();
		
		// Get tables
		$tables = $this->tables;
		
		// Exclude tables
		unset($tables['rights']);
		unset($tables['exifs']);
		unset($tables['extensions']);
		unset($tables['themes']);
		unset($tables['sizes']);
		unset($tables['rights']);
		
		// Run helper function
		foreach($tables as $table => $selector){
			$info[] = array('table' => $table, 'count' => self::countTable($table));
		}
		
		foreach($info as &$table){
			if($table['count'] == 1){
				$table['display'] = preg_replace('#s$#si', '', $table['table']);
			}
			else{
				$table['display'] = $table['table'];
			}
		}
		
		return $info;
	}
	
	/**
	 * Get array of tags
	 *
	 * @return array Associative array of tags
	 */
	public function getTags(){
		if($this->returnConf('tag_alpha')){
			$query = $this->prepare('SELECT tags.tag_name, tags.tag_id, photos.photo_id FROM tags, links, photos WHERE tags.tag_id = links.tag_id AND links.photo_id = photos.photo_id ORDER BY tags.tag_name;');
		}
		else{
			$query = $this->prepare('SELECT tags.tag_name, tags.tag_id, photos.photo_id FROM tags, links, photos WHERE tags.tag_id = links.tag_id AND links.photo_id = photos.photo_id ORDER BY tags.tag_id ASC;');
		}
		$query->execute();
		$tags = $query->fetchAll();
		
		$tag_ids = array();
		$tag_names = array();
		$tag_counts = array();
		$tag_uniques = array();
		
		foreach($tags as $tag){
			$tag_names[] = $tag['tag_name'];
			$tag_ids[$tag['tag_name']] = $tag['tag_id'];
		}
		
		$tag_counts = array_count_values($tag_names);
		$tag_count_values = array_values($tag_counts);
		$tag_count_high = 0;
		
		foreach($tag_count_values as $value){
			if($value > $tag_count_high){
				$tag_count_high = $value;
			}
		}
		
		$tag_uniques = array_unique($tag_names);
		$tags = array();
		
		foreach($tag_uniques as $tag){
			$tags[] = array('id' => $tag_ids[$tag],
				'size' => round(((($tag_counts[$tag] - 1) * 3) / $tag_count_high) + 1, 2),
				'name' => $tag,
				'count' => $tag_counts[$tag]);
		}
		
		return $tags;
	}
	
	/**
	 * Get array of all includes
	 *
	 * @return array Array of includes
	 */
	public function getIncludes(){
		$includes = self::seekDirectory(PATH . INCLUDES, '.*');
		
		foreach($includes as &$include){
			$include = self::getFilename($include);
		}
		
		return $includes;
	}
	
	/**
	 * Get HTML <select> of all rights
	 *
	 * @param string $name Name and ID of <select>
	 * @param integer $right_id Default or selected right_id
	 * @return string
	 */
	public function showRights($name, $right_id=null){
		if(empty($name)){
			return false;
		}
		
		$query = $this->prepare('SELECT right_id, right_title FROM rights;');
		$query->execute();
		$rights = $query->fetchAll();
		
		$html = '<select name="' . $name . '" id="' . $name . '"><option value=""></option>';
		
		foreach($rights as $right){
			$html .= '<option value="' . $right['right_id'] . '"';
			if($right['right_id'] == $right_id){
				$html .= ' selected="selected"';
			}
			$html .= '>' . $right['right_title'] . '</option>';
		}
		
		$html .= '</select>';
		
		return $html;
	}
	
	/**
	 * Get HTML <select> of all privacy levels
	 *
	 * @param string $name Name and ID of <select>
	 * @param integer $privacy_id Default or selected privacy_id
	 * @return string
	 */
	public function showPrivacy($name, $privacy_id=1){
		if(empty($name)){
			return false;
		}
		
		$privacy_levels = array(1 => 'Public', 2 => 'Protected', 3 => 'Private');
		
		$html = '<select name="' . $name . '" id="' . $name . '">';
		
		foreach($privacy_levels as $privacy_level => $privacy_label){
			$html .= '<option value="' . $privacy_level . '"';
			if($privacy_level == $privacy_id){
				$html .= ' selected="selected"';
			}
			$html .= '>' . $privacy_label . '</option>';
		}
		
		$html .= '</select>';
		
		return $html;
	}
	
	/**
	 * Get HTML <select> of all piles
	 *
	 * @param string $name Name and ID of <select>
	 * @param integer $pile_id Default or selected pile_id
	 * @param bool $static_only Display on static piles
	 * @return string
	 */
	public function showPiles($name, $pile_id=null, $static_only=false){
		if(empty($name)){
			return false;
		}
		
		if($static_only === true){	
			$query = $this->prepare('SELECT pile_id, pile_title FROM piles WHERE pile_type = :pile_type;');
			$query->execute(array(':pile_type', 'static'));
		}
		else{
			$query = $this->prepare('SELECT pile_id, pile_title FROM piles;');
			$query->execute();
		}
		$piles = $query->fetchAll();
		
		$html = '<select name="' . $name . '" id="' . $name . '">';
		
		foreach($piles as $pile){
			$html .= '<option value="' . $pile['pile_id'] . '"';
			if($pile['pile_id'] == $pile_id){
				$html .= ' selected="selected"';
			}
			$html .= '>' . $pile['pile_title'] . '</option>';
		}
		
		$html .= '</select>';
		
		return $html;
	}
	
	/**
	 * Get HTML <select> of all themes
	 *
	 * @param string $name Name and ID of <select>
	 * @param integer $theme_id Default or selected theme_id
	 * @return string
	 */
	public function showThemes($name, $theme_id=null){
		if(empty($name)){
			return false;
		}
		
		$query = $this->prepare('SELECT theme_id, theme_title FROM themes;');
		$query->execute();
		$themes = $query->fetchAll();
		
		$html = '<select name="' . $name . '" id="' . $name . '">';
		
		foreach($themes as $theme){
			$html .= '<option value="' . $theme['theme_id'] . '"';
			if($theme['theme_id'] == $theme_id){
				$html .= ' selected="selected"';
			}
			$html .= '>' . $theme['theme_title'] . '</option>';
		}
		
		$html .= '</select>';
		
		return $html;
	}
	
	/**
	 * Get HTML <select> of all EXIF names
	 *
	 * @param string $name Name and ID of <select>
	 * @param integer $theme_id Default or selected exif_name
	 * @return string
	 */
	public function showEXIFNames($name, $exif_name=null){
		if(empty($name)){
			return false;
		}
		
		$query = $this->prepare('SELECT DISTINCT exif_name FROM exifs ORDER BY exif_name ASC;');
		$query->execute();
		$exifs = $query->fetchAll();
		
		$html = '<select name="' . $name . '" id="' . $name . '"><option value=""></option>';
		
		foreach($exifs as $exif){
			$html .= '<option value="' . $exif['exif_name'] . '"';
			if($exif['exif_name'] == $exif_name){
				$html .= ' selected="selected"';
			}
			$html .= '>' . $exif['exif_name'] . '</option>';
		}
		
		$html .= '</select>';
		
		return $html;
	}
	
	
	// TABLE AND ROW MANIPULATION
	
	/**
	 * Get table
	 *
	 * @param string $table Table name
	 * @param string|int|array $ids Row IDs
	 * @param string $limit
	 * @param string $page 
	 * @param string $order_by 
	 * @return array
	 */
	public function getTable($table, $ids=null, $limit=null, $page=1, $order_by=null){
		if(empty($table)){
			return false;
		}
		if(!is_int($page) or ($page < 1)){
			$page = 1;
		}
		
		$table = $this->sanitize($table);
		
		$sql_params = array();
		
		$order_by_sql = '';
		$limit_sql = '';
		
		if(!empty($order_by)){
			if(is_string($order_by)){
				$order_by = $this->sanitize($order_by);
				$order_by_sql = ' ORDER BY ' . $order_by;
			}
			elseif(is_array($order_by)){
				foreach($order_by as &$by){
					$by = $this->sanitize($by);
				}
				$order_by_sql = ' ORDER BY ' . implode(', ', $order_by);
			}
		}
		
		if(!empty($limit)){
			$limit = intval($limit);
			$page = intval($page);
			$limit_sql = ' LIMIT ' . ($limit * ($page - 1)) . ', ' . $limit;
		}
		
		if(empty($ids)){
			$query = $this->prepare('SELECT * FROM ' . $table . $order_by_sql . $limit_sql . ';');
		}
		else{
			$ids = self::convertToIntegerArray($ids);
			$field = $this->tables[$table];
			
			$query = $this->prepare('SELECT * FROM ' . $table . ' WHERE ' . $field . ' = ' . implode(' OR ' . $field . ' = ', $ids) . $order_by_sql . $limit_sql . ';');
		}
		
		$query->execute($sql_params);
		$table = $query->fetchAll();
		return $table;
	}
	
	/**
	 * Get row
	 *
	 * @param string $table Table name
	 * @param string|int $id Row ID
	 * @return array
	 */
	public function getRow($table, $id){
		// Error checking
		if(empty($id)){ return false; }
		if(!($id = intval($id))){ return false; }
		
		$table = $this->getTable($table, $id);
		if(count($table) != 1){ return false; }
		return $table[0];
	}
	
	/**
	 * Add row (includes updating default fields)
	 *
	 * @param array $fields Associative array of key (column) and value (field)
	 * @param string $table Table name
	 * @return int|false Row ID or error
	 */
	public function addRow($fields=null, $table){
		// Error checking
		if(empty($table) or (!is_array($fields) and isset($fields))){
			return false;
		}
		
		if(empty($fields)){
			$fields = array();
		}
		
		$table = $this->sanitize($table);
		
		// Add default fields
		switch($table){
			case 'comments':
				$fields['comment_created'] = date('Y-m-d H:i:s');
				break;
			case 'guests':
				$fields['guest_views'] = 0;
				$fields['guest_created'] = date('Y-m-d H:i:s');
				break;
			case 'rights':
				$fields['right_modified'] = date('Y-m-d H:i:s');
				break;
			case 'pages':
				$fields['page_views'] = 0;
				$fields['page_created'] = date('Y-m-d H:i:s');
				$fields['page_modified'] = date('Y-m-d H:i:s');
				break;
			case 'piles':
				$fields['pile_views'] = 0;
				$fields['pile_created'] = date('Y-m-d H:i:s');
				$fields['pile_modified'] = date('Y-m-d H:i:s');
				break;
			case 'sizes':
				if(!isset($fields['size_title'])){ $fields['size_title'] = ''; }
				break;
			case 'users':
				if(Alkaline::edition != 'multiuser'){
					return false;
				}
				$fields['user_created'] = date('Y-m-d H:i:s');
				break;
			default:
				break;
		}
		
		$field = $this->tables[$table];
		unset($fields[$field]);
		
		if(count($fields) > 0){
			$columns = array_keys($fields);
			$values = array_values($fields);
		
			$value_slots = array_fill(0, count($values), '?');
		
			// Add row to database
			$query = $this->prepare('INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $value_slots) . ');');
		}
		else{
			$values = array();
			$query = $this->prepare('INSERT INTO ' . $table . ' (' . $this->tables[$table] . ') VALUES (?);');
			$values = array(PDO::PARAM_NULL);
		}
		
		if(!$query->execute($values)){
			return false;
		}
		
		// Return ID
		$id = intval($this->db->lastInsertId(TABLE_PREFIX . $table . '_' . $field . '_seq'));
		
		if($id == 0){
			return false;
		}
		
		return $id;
	}
	
	/**
	 * Update row
	 *
	 * @param string $fields Associative array of key (column) and value (field)
	 * @param string $table Table name
	 * @param string|array $ids Row IDs
	 * @param string $default Include default fields (e.g., update modified dates)
	 * @return bool True if successful
	 */
	public function updateRow($fields, $table, $ids=null, $default=true){
		// Error checking
		if(empty($fields) or empty($table) or !is_array($fields)){
			return false;
		}
		
		$table = $this->sanitize($table);
		
		$ids = self::convertToIntegerArray($ids);
		$field = $this->tables[$table];
		
		// Add default fields
		if($default === true){
			switch($table){
				case 'photos':
					$fields['photo_modified'] = date('Y-m-d H:i:s');
					break;
				case 'piles':
					$fields['pile_modified'] = date('Y-m-d H:i:s');
					break;
				case 'pages':
					$fields['page_modified'] = date('Y-m-d H:i:s');
					break;
			}
		}
		
		$columns = array_keys($fields);
		$values = array_values($fields);

		// Add row to database
		$query = $this->prepare('UPDATE ' . $table . ' SET ' . implode(' = ?, ', $columns) . ' = ? WHERE ' . $field . ' = ' . implode(' OR ' . $field . ' = ', $ids) . ';');
		if(!$query->execute($values)){
			return false;
		}
		
		return true;
	}
	
	/**
	 * Delete row
	 *
	 * @param string $table Table name
	 * @param string|int|array $ids Row IDs
	 * @return bool True if successful
	 */
	public function deleteRow($table, $ids=null){
		if(empty($table) or empty($ids)){
			return false;
		}
		
		$table = $this->sanitize($table);
		
		$ids = self::convertToIntegerArray($ids);
		$field = $this->tables[$table];
		
		// Delete row
		$query = 'DELETE FROM ' . $table . ' WHERE ' . $field . ' = ' . implode(' OR ' . $field . ' = ', $ids) . ';';
		
		if(!$this->exec($query)){
			return false;
		}
		
		return true;
	}
	
	/**
	 * Delete empty rows
	 *
	 * @param string $table Table name
	 * @param string|array $fields Fields to check for empty values (if any are empty, deletion will occur) 
	 * @return bool True if successful
	 */
	public function deleteEmptyRow($table, $fields){
		if(empty($table) or empty($fields)){
			return false;
		}
		
		$table = $this->sanitize($table);
		
		$fields = self::convertToArray($fields);
		
		$conditions = array();
		foreach($fields as $field){
			$conditions[] = '(' . $field . ' = ? OR ' . $field . ' IS NULL)';
		}
		
		$sql_params = array_fill(0, count($fields), '');
		
		// Delete empty rows
		$query = $this->prepare('DELETE FROM ' . $table . ' WHERE ' . implode(' OR ', $conditions) . ';');
		
		if(!$query->execute($sql_params)){
			return false;
		}
		
		return true;
	}
	
	/**
	 * Count table rows
	 *
	 * @param string $table Table name
	 * @return int Number of rows
	 */
	function countTable($table){
		$table = $this->sanitize($table);
		
		$field = $this->tables[$table];
		if(empty($field)){ return false; }
		
		$query = $this->prepare('SELECT COUNT(' . $table . '.' . $field . ') AS count FROM ' . $table . ';');
		$query->execute();
		$count = $query->fetch();
		
		$count = intval($count['count']);
		return $count;
	}
	
	// RECORD STATISTIC
	// Record a visitor to statistics
	public function recordStat($page_type=null){
		if(!$this->returnConf('stat_enabled')){
			return false;
		}
		
		if(empty($_SESSION['alkaline']['duration_start']) or ((time() - @$_SESSION['alkaline']['duration_recent']) > 3600)){
			$duration = 0;
			$_SESSION['alkaline']['duration_start'] = time();
		}
		else{
			$duration = time() - $_SESSION['alkaline']['duration_start'];
		}
		
		$_SESSION['alkaline']['duration_recent'] = time();
		
		$referrer = (!empty($_SERVER['HTTP_REFERER'])) ? $_SERVER['HTTP_REFERER'] : null;
		$page = (!empty($_SERVER['REQUEST_URI'])) ? $_SERVER['REQUEST_URI'] : null;
		
		if(stripos($referrer, LOCATION . BASE) === false){
			$local = 0;
		}
		else{
			$local = 1;
		}
		
		$query = $this->prepare('INSERT INTO stats (stat_session, stat_date, stat_duration, stat_referrer, stat_page, stat_page_type, stat_local) VALUES (:stat_session, :stat_date, :stat_duration, :stat_referrer, :stat_page, :stat_page_type, :stat_local);');
		
		$query->execute(array(':stat_session' => session_id(), ':stat_date' => date('Y-m-d H:i:s'), ':stat_duration' => $duration, ':stat_referrer' => $referrer, ':stat_page' => $page, ':stat_page_type' => $page_type, ':stat_local' => $local));
	}
	
	// FORM HANDLING
	
	/**
	 * Set form option
	 *
	 * @param string $array 
	 * @param string $name 
	 * @param string $unset 
	 * @return void
	 */
	public function setForm(&$array, $name, $unset=''){
		if(isset($_POST[$name])){
			$value = $_POST[$name];
			if(empty($value)){
				$array[$name] = '';
			}
			elseif($value == 'true'){
				$array[$name] = true;
			}
			else{
				$array[$name] = $value;
			}
		}
		else{
			$array[$name] = $unset;
		}
	}
	
	/**
	 * Retrieve HTML-formatted form option
	 *
	 * @param string $array 
	 * @param string $name 
	 * @param string $check 
	 * @return string
	 */
	public function readForm($array=null, $name, $check=true){
		if(is_array($array)){
			@$value = $array[$name];
		}
		else{
			$value = $name;
		}
		
		if(!isset($value)){
			return false;
		}
		elseif($check === true){
			if($value === true){
				return 'checked="checked"';
			}
		}
		elseif(!empty($check)){
			if($value == $check){
				return 'selected="selected"';
			}
		}
		else{
			return 'value="' . $value . '"';
		}
	}
	
	/**
	 * Return form option
	 *
	 * @param string $array 
	 * @param string $name 
	 * @param string $default 
	 * @return string
	 */
	public function returnForm($array, $name, $default=null){
		@$value = $array[$name];
		if(!isset($value)){
			if(isset($default)){
				return $default;
			}
			else{
				return false;
			}
		}
		return $value;
	}
	
	// CONFIGURATION HANDLING
	
	/**
	 * Set configuration key
	 *
	 * @param string $name 
	 * @param string $unset 
	 * @return void
	 */
	public function setConf($name, $unset=''){
		return self::setForm($_SESSION['alkaline']['config'], $name, $unset);
	}
	
	/**
	 * Return HTML-formatted configuration key
	 *
	 * @param string $name 
	 * @param string $check 
	 * @return string
	 */
	public function readConf($name, $check=true){
		return self::readForm($_SESSION['alkaline']['config'], $name, $check);
	}
	
	/**
	 * Return configuration key
	 *
	 * @param string $name 
	 * @return string
	 */
	public function returnConf($name){
		return self::makeHTMLSafe(self::returnForm($_SESSION['alkaline']['config'], $name));
	}
	
	/**
	 * Save configuration
	 *
	 * @return int|false Bytes written or error
	 */
	public function saveConf(){
		return file_put_contents($this->correctWinPath(PATH . 'config.json'), json_encode(self::reverseHTMLSafe($_SESSION['alkaline']['config'])));
	}
	
	// URL HANDLING
	
	/**
	 * Find ID number from string
	 *
	 * @param string $string Input string
	 * @param string $numeric_required If true, will return false if number not found
	 * @return int|string|false ID, string, or error
	 */
	public function findID($string, $numeric_required=false){
		$matches = array();
		if(is_numeric($string)){
			$id = intval($string);
		}
		elseif(preg_match('#^([0-9]+)#s', $string, $matches)){
			$id = intval($matches[1]);
		}
		elseif($numeric_required === true){
			return false;
		}
		else{
			$id = $string;
		}
		return $id;
	}
	
	/**
	 * Find photo IDs (in <a>, <img>, etc.) from a string
	 *
	 * @param string $str Input string
	 * @return array Photo IDs
	 */
	public function findIDRef($str){
		preg_match_all('#["\']{1}(?=' . LOCATION . '/|/)[^"\']*([0-9]+)[^/.]*\.(?:' . IMG_EXT . ')#si', $str, $matches, PREG_SET_ORDER);
		
		$photo_ids = array();
		
		foreach($matches as $match){
			$photo_ids[] = intval($match[1]);
		}
		
		$photo_ids = array_unique($photo_ids);
		
		return $photo_ids;
	}
	
	/**
	 * Make a URL-friendly string (removes special characters, replaces spaces)
	 *
	 * @param string $string
	 * @return string
	 */
	public function makeURL($string){
		$string = html_entity_decode($string, 1, 'UTF-8');
		$string = strtolower($string);
		$string = preg_replace('#([^a-zA-Z0-9]+)#s', '-', $string);
		$string = preg_replace('#^(\-)+#s', '', $string);
		$string = preg_replace('#(\-)+$#s', '', $string);
		return $string;
	}
	
	/**
	 * Minimize URL for display purposes
	 *
	 * @param string $url
	 * @return string
	 */
	public function minimizeURL($url){
		$url = preg_replace('#^http\:\/\/www\.#s', '', $url);
		$url = preg_replace('#^http\:\/\/#s', '', $url);
		$url = preg_replace('#^www\.#s', '', $url);
		$url = preg_replace('#\/$#s', '', $url);
		return $url;
	}
	
	/**
	 * Trim long strings
	 *
	 * @param string $string 
	 * @param string $length Maximum character length
	 * @return string
	 */
	public function fitString($string, $length=50){
		$length = intval($length);
		if($length < 3){ return false; }
		
		$string = trim($string);
		if(strlen($string) > $length){
			$string = rtrim(substr($string, 0, $length - 3)) . '&#0133;';
		}
		return $string;
	}
	
	/**
	 * Trim strings, end on a whole word
	 *
	 * @param string $string 
	 * @param string $length Maximum character length
	 * @return string 
	 */
	public function fitStringByWord($string, $length=50){
		$length = intval($length);
		if($length < 3){ return false; }
		
		$string = trim($string);
		if(strlen($string) > $length){
			$space = strpos($string, ' ', $length);
			if($space !== false){
				$string = substr($string, 0, $space) . '&#0133;';
			}
		}
		return $string;
	}
	
	/**
	 * Choose between singular and plural forms of a string
	 *
	 * @param string $count Count
	 * @param string $singular Singular form
	 * @param string $plural Plural form
	 * @return void
	 */
	public function echoCount($count, $singular, $plural=null){
		if(empty($plural)){
			$plural = $singular . 's';
		}
		
		if($count == 1){
			echo $singular;
		}
		else{
			echo $plural;
		}
	}
	
	/**
	 * Choose between singular and plural forms of a string and include count
	 *
	 * @param string $count Count
	 * @param string $singular Singular form
	 * @param string $plural Plural form
	 * @return void
	 */
	public function echoFullCount($count, $singular, $plural=null){
		$count = number_format($count) . ' ' . self::echoCount($count, $singular, $plural);
	}
	
	/**
	 * If Windows Server, make path Windows-friendly
	 *
	 * @param string $path
	 * @return string
	 */
	public function correctWinPath($path){
		if(SERVER_TYPE == 'win'){
			$path = str_replace('/', '\\', $path);
		}
		return $path;
	}
	
	// REDIRECT HANDLING
	
	/**
	 * Current page for redirects (removes all GET variables except page)
	 *
	 * @return string
	 */
	public function location(){
		$location = LOCATION;
		$location .= preg_replace('#\?.*$#si', '', $_SERVER['REQUEST_URI']);
		
		// Retain page data
		preg_match('#page=[0-9]+#si', $_SERVER['REQUEST_URI'], $matches);
		if(!empty($matches[0])){
			$location .= '?' . $matches[0];
		}
		
		return $location;
	}
	
	/**
	 * Current page for redirects
	 *
	 * @param string $append Append to URL (GET variables)
	 * @return void
	 */
	public function locationFull($append=null){
		$location = LOCATION . $_SERVER['REQUEST_URI'];
		if(!empty($append)){
			if(preg_match('#\?.*$#si', $location)){
				$location .= '&' . urlencode($append);
			}
			else{
				$location .= '?' . urlencode($append);
			}
		}
		
		return $location;
	}
	
	/**
	 * Set callback location
	 *
	 * @param string $page 
	 * @return void
	 */
	public function setCallback($page=null){
		if(!empty($page)){
			$_SESSION['alkaline']['callback'] = $page;
		}
		else{
			$_SESSION['alkaline']['callback'] = self::location();
		}
	}
	
	/**
	 * Send to callback location
	 *
	 * @param string $url Fallback URL if callback URL isn't set
	 * @return void
	 */
	public function callback($url=null){
		if(!empty($_SESSION['alkaline']['callback'])){
			header('Location: ' . $_SESSION['alkaline']['callback']);
		}
		elseif(!empty($url)){
			header('Location: ' . $url);
		}
		else{
			header('Location: ' . LOCATION . BASE . ADMIN . 'dashboard/');
		}
		exit();
	}
	
	/**
	 * Send back (for cancel links)
	 *
	 * @return void
	 */
	public function back(){
		if(!empty($_SESSION['alkaline']['back'])){
			echo $_SESSION['alkaline']['back'];
		}
		elseif(!empty($_SERVER['HTTP_REFERER'])){
			echo $_SERVER['HTTP_REFERER'];
		}
		else{
			header('Location: ' . LOCATION . BASE . ADMIN . 'dashboard/');
		}
	}
	
	// MAIL
	
	/**
	 * Send email
	 *
	 * @param int|string $to If integer, looks up email address from users table; else, an email address
	 * @param string $subject 
	 * @param string $message 
	 * @return True if successful
	 */
	protected function email($to=0, $subject, $message){
		if(empty($subject) or empty($message)){ return false; }
		
		if($to == 0){
			$to = $this->returnConf('web_email');
		}
		
		if(is_int($to) or preg_match('#[0-9]+#s', $to)){
			$query = $this->prepare('SELECT user_email FROM users WHERE user_id = ' . $to);
			$query->execute();
			$user = $query->fetch();
			$to = $user['user_email'];
		}
		
		$subject = 'Alkaline: ' . $subject;
		$message = $message . "\r\n\n" . '-- Alkaline';
		$headers = 'From: ' . $this->returnConf('web_email') . "\r\n" .
			'Reply-To: ' . $this->returnConf('web_email') . "\r\n" .
			'X-Mailer: PHP/' . phpversion();
		
		return mail($to, $subject, $message, $headers);
	}
	
	// DEBUGGING AND LOGGING
	
	/**
	 * Set errors
	 *
	 * @param int $severity 
	 * @param string $message 
	 * @param string $filename
	 * @param int $line_number
	 * @return void
	 */
	public function addError($severity, $message, $filename=null, $line_number=null){
		if(!(error_reporting() & $severity)){
			// This error code is not included in error_reporting
			// return;
		}
		
		switch($severity){
			case E_USER_NOTICE:
				$_SESSION['alkaline']['errors'][] = array('severity' => 'notice', 'message' => $message, 'filename' => $filename, 'line_number' => $line_number);
				break;
			case E_USER_WARNING:
				$_SESSION['alkaline']['errors'][] = array('severity' => 'warning', 'message' => $message, 'filename' => $filename, 'line_number' => $line_number);
				break;
			case E_USER_ERROR:
				$_SESSION['alkaline']['errors'][] = array('severity' => 'error', 'message' => $message, 'filename' => $filename, 'line_number' => $line_number);
				session_write_close();
				require_once(PATH . '/error.php');
				exit();
				break;
			default:
				$_SESSION['alkaline']['errors'][] = array('severity' => 'warning', 'message' => $message, 'filename' => $filename, 'line_number' => $line_number);
				break;
		}
		
		return true;
	}
	
	/**
	 * Display errors
	 *
	 * @return void|string HTML-formatted notifications 
	 */
	public function returnErrors(){
		if(!isset($_SESSION['alkaline']['errors'])){ return; }
		
		$count = @count($_SESSION['alkaline']['errors']);
		
		if(empty($count)){ return; }
		
		// Determine unique types
		$types = array();
		foreach($_SESSION['alkaline']['errors'] as $error){
			$types[] = $error['severity'];
		}
		$types = array_unique($types);
		
		$overview = array();
		$list = array();
		
		// Produce HTML for display
		foreach($types as $type){
			$i = 0;
			
			foreach($_SESSION['alkaline']['errors'] as $error){
				if($error['severity'] == $type){
					$i++;
				}
			}
			
			if($i == 1){
				$overview[] = $i . ' ' . $type;
			}
			else{
				$overview[] = $i . ' ' . $type . 's';
			}
		}
		
		foreach($_SESSION['alkaline']['errors'] as $error){
			$item = '<li><strong>' . ucwords($error['severity']) .':</strong> ' . $error['message'];
			if(!empty($error['filename'])){
				$item .= ' (' . $error['filename'] . ', line ' . $error['line_number'] .')';
			} 
			$item .= '.</li>';
			$list[] = $item;
		}
		
		// Dispose of messages
		unset($_SESSION['alkaline']['errors']);
		
		return '<span>(<a href="#" class="show">' . implode(' ,', $overview) . '</a>)</span><div class="reveal"><ol class="errors">' . implode("\n", $list) . '</ol></div>';
	}
	
	/**
	 * Return debug array
	 *
	 * @return array
	 */
	public function debug(){
		$_SESSION['alkaline']['debug']['execution_time'] = microtime(true) - $_SESSION['alkaline']['debug']['start_time'];
		return $_SESSION['alkaline']['debug'];
	}
	
	/**
	 * Add message to error log
	 *
	 * @param string $message 
	 * @param string $number 
	 * @return void
	 */
	public function report($message, $number=null){
		if(@$_SESSION['alkaline']['warning'] == $message){ return false; }
		
		$_SESSION['alkaline']['warning'] = $message;
		
		// Format message
		$message = date('Y-m-d H:i:s') . "\t" . $message;
		if(!empty($number)){ $message .= ' (' . $number . ')'; }
		$message .= "\n";
		
		// Write message
		$handle = fopen($this->correctWinPath(PATH . DB . 'log.txt'), 'a');
		if(@fwrite($handle, $message) === false){
			$this->addError(E_USER_ERROR, 'Cannot write to report file');
		}
		fclose($handle);
	}
	
	// VALIDATION
	
	/**
	 * Determine whether Alkaline license is authentic
	 *
	 * @return bool True if validate
	 */
	public function validate(){		
		// Error checking
		if(!require(PATH . '/license.php')){ return false; }
		
		$domain = sha1(Alkaline::edition . $_SERVER['SERVER_NAME']);
		$domain2 = intval(preg_replace('#[^0-9]+#si', '', base64_encode($domain)));
		
		$secret1 = sha1(sin($domain2));
		$secret2 = sha1(cos($domain2));
		$secret3 = sha1(tan($domain2));
		$secret4 = sha1($domain2);
		$secret5 = $domain2;
		$secret6 = $domain;
		
		$match = $secret2[7] . '....................' . $secret1[24] . $secret3[5] . '.........................' . $secret6[11] . '......' . $secret3[21] . '......................' . $secret4[1] . '..........' . $secret4[37] . $secret5[34] . $secret5[16] . '..........' . $secret6[34] . '......' . $secret6[37] . '...' . $secret1[2] . '......' . $secret1[20] . '.......' . $secret6[14] . '..........' . $secret4[12] . '.................' . $secret3[12] . '..' . $secret5[6] . '...........' . $secret1[30] . '..' . $secret4[38] . '.' . $secret4[26] . '............' . $secret6[35] . '...' . $secret2[22] . $secret4[7] . '...' . $secret2[31] . '.................' . $secret4[39] . $secret2[26] . '........' . $secret2[1] . '........' . $secret3[1] . '.' . $secret2[3] . '.' . $secret2[25] . $secret2[23] . '...............' . $secret4[3] . '..................' . $secret4[14] . '.......' . $secret2[10] . '......' . $secret4[5] . '.......' . $secret3[37] . '.........' . $secret4[22] . '.....' . $secret1[1] . '.........................' . $secret1[27] . '..........' . $secret1[25] . '................' . $secret4[36] . '................' . $secret5[17] . $secret4[15] . '........' . $secret1[5] . '.......' . $secret5[22] . '.....................' . $secret5[36] . '...........' . $secret6[30] . '...............................' . $secret2[4] . '............' . $secret2[2] . '...' . $secret1[26] . '.....................................' . $secret2[28] . '.................' . $secret3[9] . '.............................' . $secret4[8] . $secret4[13] . '..' . $secret1[37] . '......' . $secret2[11] . '.......' . $secret2[8] . '..' . $secret6[26] . '...' . $secret6[31] . '.' . $secret3[24] . '.......' . $secret2[24] . '............' . $secret3[36] . '........' . $secret3[38] . '........' . $secret6[16] . '..............' . $secret6[1] . '....................' . $secret6[2] . '....................' . $secret5[0] . '.........................' . $secret6[3] . '.................' . $secret2[16] . '..................' . $secret3[26] . '.....' . $secret3[2] . $secret3[29] . '.............................' . $secret5[14] . '.' . $secret4[30] . '..........' . $secret1[32] . '...' . $secret3[11] . '.........' . $secret2[15] . '.....' . $secret3[18] . '..................' . $secret6[18] . '..............................' . $secret3[25] . '.........................' . $secret4[16] . $secret2[34] . '........................' . $secret5[7] . $secret6[10] . '........................................' . $secret5[31] . '....................' . $secret4[35] . '...' . $secret5[19] . '......' . $secret5[1] . '..............' . $secret2[30] . '...........' . $secret1[7] . '...' . $secret3[17] . '..' . $secret1[18] . '.........' . $secret5[35] . $secret3[33] . '...' . $secret3[22] . '.....' . $secret2[12] . '..' . $secret6[33] . '.............' . $secret2[14] . '.......' . $secret1[4] . '...............' . $secret6[20] . '.........................' . $secret2[9] . '.........' . $secret6[32] . $secret5[5] . '...........................' . $secret2[20] . '........' . $secret5[12] . '...' . $secret4[17] . '..............' . $secret6[6] . '..........................' . $secret6[8] . '.....' . $secret1[38] . '.' . $secret2[0] . '.......' . $secret2[5] . '........' . $secret5[13] . '.......' . $secret3[39] . '.' . $secret4[4] . '.' . $secret4[25] . '.............' . $secret5[2] . '..............' . $secret5[10] . '.........' . $secret3[7] . '..............' . $secret3[10] . '...................' . $secret6[9] . '................' . $secret6[24] . $secret5[27] . '........' . $secret3[28] . '.........' . $secret6[28] . '...........' . $secret3[23] . '....' . $secret3[14] . '........' . $secret1[10] . '.........' . $secret1[8] . '.....' . $secret3[4] . '.....' . $secret1[22] . '....' . $secret6[22] . '...........' . $secret1[39] . '....' . $secret5[23] . '......' . $secret2[33] . $secret3[31] . '.....................................' . $secret5[21] . $secret5[33] . '..' . $secret2[39] . '.' . $secret1[21] . '..' . $secret3[19] . '...................................' . $secret2[37] . '.....................' . $secret5[37] . '...........' . $secret4[21] . '...................' . $secret1[28] . '....' . $secret1[9] . '...' . $secret6[13] . '........' . $secret5[11] . '.' . $secret4[29] . '....................' . $secret1[23] . '.............................' . $secret6[38] . '....' . $secret2[17] . '....................' . $secret4[20] . '..' . $secret5[4] . $secret4[24] . '........' . $secret5[39] . '....' . $secret5[15] . '..' . $secret3[0] . $secret2[35] . '...' . $secret3[16] . '.......' . $secret6[21] . '.....' . $secret2[38] . '....' . $secret4[9] . '....' . $secret1[6] . '....' . $secret4[10] . '..........' . $secret4[23] . '....' . $secret4[34] . '..' . $secret6[25] . '............' . $secret1[16] . '.' . $secret5[9] . '.' . $secret2[21] . $secret1[33] . $secret6[5] . '...........' . $secret2[6] . '...' . $secret3[30] . '.....' . $secret1[12] . '..........' . $secret1[3] . '.......' . $secret6[0] . '...............' . $secret1[17] . $secret5[26] . '...' . $secret6[39] . '....' . $secret3[3] . '.....' . $secret4[32] . '....' . $secret6[12] . '..' . $secret4[2] . $secret1[14] . '................' . $secret4[6] . '..' . $secret1[31] . $secret3[6] . '.......' . $secret5[32] . '.' . $secret4[33] . '........' . $secret1[35] . '...........' . $secret4[11] . '......' . $secret5[8] . '...................' . $secret4[28] . '....................' . $secret2[29] . '..........' . $secret2[19] . '.' . $secret6[4] . '..' . $secret3[35] . '.....' . $secret3[34] . '...' . $secret6[27] . '..' . $secret6[7] . '........' . $secret5[18] . '......' . $secret1[19] . '...' . $secret1[0] . $secret1[34] . '..............' . $secret4[0] . '.......' . $secret1[36] . '......' . $secret6[23] . '....' . $secret5[3] . '.................................' . $secret5[38] . '..................' . $secret6[17] . '.....................' . $secret2[32] . '.' . $secret3[32] . '.........' . $secret4[31] . '...................' . $secret1[13] . '.....................' . $secret1[29] . $secret3[15] . '.......' . $secret6[19] . '...............' . $secret2[18] . '.....' . $secret4[18] . $secret3[13] . '.' . $secret4[19] . '.......' . $secret1[11] . '.' . $secret2[27] . '......' . $secret6[29] . '.......' . $secret1[15] . '.................' . $secret5[29] . '..' . $secret6[15] . '.' . $secret2[13] . '............' . $secret5[28] . '..' . $secret4[27] . '....' . $secret5[20] . '....' . $secret5[24] . '...........' . $secret5[25] . '............' . $secret6[36] . '.........................................' . $secret3[8] . $secret3[20] . '........' . $secret3[27] . '...................' . $secret5[30] . '.............' . $secret2[36] . '.........';
		
		if(!preg_match('#' . $match . '#', $license)){
			return false;
		}
		
		$key = sha1($this->randInt());
		$this->setConf('key', $key);
		$this->saveConf();
		
		$config = json_decode(file_get_contents(LOCATION . '/config.json'), true);
		$key_retrieved = self::returnForm($config, 'key');
		
		if($key_retrieved != $key){ return false; }
		
		// Check for multiple users
		if(Alkaline::edition != 'multiuser'){
			if($this->countTable('users') > 1){
				$this->addError(E_USER_ERROR, 'Your Alkaline license does not support multiple users');
				return false;
			}
		}
		
		Alkaline::$validated = true;
		
		return true;
	}
}

?>