<?php
/**
 * Logging system.
 */
class SLogger
{
    // Hold named loggers.
    private static $_instances = array();

    // Severity level to turn off logging. Prevents file rotation, deletion, and creation.
    const OFF = -1;

    // Severity levels as in RFC5424:
    //    * http://tools.ietf.org/html/rfc5424
    //    * http://en.wikipedia.org/wiki/Syslog
    const EMERGENCY = 0;     // System wide failure
    const ALERT = 1;         // Primary system failure
    const CRITICAL = 2;      // Secondary system failure
    const ERROR = 3;         // Non-urgent failure
    const WARNING = 4;       // Will become an error if not corrected
    const NOTICE = 5;        // Unusual; unclear if it will become an error
    const INFORMATIONAL = 6; // Normal messages
    const DEBUG = 7;         // Details for debugging

    const NO_DATA = "SLogger::NO_DATA";

    // Names of all the severities in order. Used to convert
    // severities between strings and ordinal values. Also
    // used to match user provided strings against severities.
    private static $_severities = array(
        "emergency",
        "alert",
        "critical",
        "error",
        "warning",
        "notice",
        "informational",
        "debug",
    );

    /**
     * Helper to convert data to a string.
     */
    private static function _toString($data) {
        $s = "";
        if ($data !== self::NO_DATA) {
            ob_start();
            var_dump($data);
            $s = ob_get_clean();
        }
        return trim($s);
    }

    /**
     * Utility for formatting dates with microseconds.
     */
    private static function _udate($format = 'u', $utimestamp = null) {
        if (is_null($utimestamp))
            $utimestamp = microtime(true);
        $timestamp = floor($utimestamp);
        $milliseconds = round(($utimestamp - $timestamp) * 1000000);
        return date(preg_replace('`(?<!\\\\)u`', $milliseconds, $format), $timestamp);
    }

    /**
     * Add loggers.
     *
     * SLogger::add(array(
     *      'default' => array(
     *          '/path/to/log/directory',
     *          'severityThreshold'=>'info',
     *          'smartSeverityThreshold'=>'notice',
     *          'maxFileSize'=>100000000, // 100MB
     *          'maxDays'=>7, // delete logs after 7 days
     *          'dateFormat'=>'H-i-s.u', // u is microseconds
     *          'defaultPermission'=>0777, // permission used to create files
     *      )
     * ));
     *
     * @param array $confs Associative array whose keys are the names of loggers,
     * and whose values are associative arrays whose keys are public attributes
     * of SLogger and whose values are the initial values for those attributes.
     */
    public static function add($confs) {
        foreach ($confs as $name => $conf) {
            $path = $conf[0];
            unset($conf[0]);
            self::$_instances[$name] = new SLogger($path, $conf);
        }
    }

    /**
     * Returns a logger named $name.
     * @param string $name Name of logger to return. Default is 'default'.
     * @return SLogger or FALSE if $name is not found.
     */
    public static function get($name = 'default') {
        if (array_key_exists($name, self::$_instances)) {
            return self::$_instances[$name];
        }
        return FALSE;
    }

    /** Utility for comparing messages by timestamp for sorting. */
    private static function _compareMessages($a, $b) {
        return $a[0] - $b[0];
    }

    /** Report messages at or above this severity */
    public $severityThreshold = self::INFORMATIONAL;
    /** Report all messages if any message is at or above this severity */
    public $smartSeverityThreshold = self::NOTICE;
    /** Rotate log file if it becomes larger than this size in bytes */
    public $maxFileSize = 100000000;
    /** Format of date/time */
    public $dateFormat = 'Y-m-d H:i:s.u';
    /** Files created using these default permissions */
    public $defaultPermission = 0777;
    /** Delete logs after $deletAfterDays old. */
    public $maxDays = 7;

    private $_logDirectory = NULL;
    private $_logFilePath = null;
    private $_fileHandle = null;
    private $_verbose = FALSE;
    private $_queues = array();
    private $_lockFilePath = null;
    private $_lockHandle = null;
    private $_mutex = null;

    /**
     * @param string $logDirectory Where logs will be written.
     * @param array $args Configuration array to initialize public attributes.
     */
    public function __construct($logDirectory, $args = array()) {

        $this->_logDirectory = $logDirectory;

        // Initialize public attributes from $args.
        foreach ($args as $key => $val) {
            $key = ltrim($key, '_');
            $this->$key = $val;
        }

        // Convert severity strings to ordinals.
        $this->severityThreshold      = $this->_toSeverityOrdinal($this->severityThreshold);
        $this->smartSeverityThreshold = $this->_toSeverityOrdinal($this->smartSeverityThreshold);

        $this->_buildQueues();

        // Validate _logDirectory.
        if ($this->_logDirectory === NULL) {
            throw new Exception("Log directory is required");
        }

        // Sanatize _logDirectory.
        $this->_logDirectory = rtrim($this->_logDirectory, '\\/');

        // Don't rotate, delete, create, or open log files if OFF.
        if ($this->severityThreshold !== self::OFF) {

            // Create log directory as needed
            if (!file_exists($this->_logDirectory)) {
                mkdir($this->_logDirectory, $this->defaultPermission, true);
            }

            // Identify lock file for log directory
            $this->_lockFilePath = $this->_logDirectory
                . DIRECTORY_SEPARATOR
                . 'log.lock';

            // Verify permissions on lock file
            if (file_exists($this->_lockFilePath) && !is_writable($this->_lockFilePath)) {
                throw new Exception("Please check permissions on lock file: {$this->_lockFilePath}");
            }


            /*
            // Open lock file in preparation for locking
            $this->_lockHandle = fopen($this->_lockFilePath, 'w');
            if (!is_resource($this->_lockHandle)) {
                throw new Exception("Could not open lock file: {$this->_lockFilePath}");
            }
             */

            /*
            // Lock file.
            if (self::_lock($this->_lockHandle, LOCK_EX) === FALSE) {
                throw new Exception("Could not aquire lock on lock file: {$this->_lockFilePath}");
            }
             */

            $this->_mutex = new ExclusiveLock($this->_logDirectory . DIRECTORY_SEPARATOR);
            if (!$this->_mutex->lock()) {
                throw new Exception("Could not lock {$this->_lockFilePath}");
            }

            $e = null;
            try {

                // Identify log file for this call, rotating as needed.
                $fileNo = 0;
                while (
                    $this->_logFilePath == null ||
                    (
                        file_exists($this->_logFilePath) &&
                        filesize($this->_logFilePath) > $this->maxFileSize
                    )
                ) {
                    $this->_logFilePath = $this->_logDirectory
                        . DIRECTORY_SEPARATOR
                        . 'log_'
                        . date('Y-m-d-')
                        . sprintf('%03d', $fileNo++)
                        . '.csv';
                }

                // Verify permissions on log file
                if (file_exists($this->_logFilePath) && !is_writable($this->_logFilePath)) {
                    throw new Exception("Cannot write to log file. Please check permissions on log file: {$this->_logFilePath}");
                }

                // Open log file
                $this->_fileHandle = fopen($this->_logFilePath, 'a');

                // Verify log file is open
                if (!is_resource($this->_fileHandle) || $this->_fileHandle === FALSE) {
                    throw new Exception("Cannot append to log file: {$this->_logFilePath}");
                }

                $this->_deleteOldLogs();

            } catch (Exception $e) {
                // Rethrow after unluck. See below.
            }
            /*
            // Unlock file.
            self::_unlock($this->_lockHandle);
             */
            $this->_mutex->unlock();
            if ($e !== null) {
                throw $e;
            }
        }

    }

    public function __destruct() {
        if (is_resource($this->_fileHandle)) {
            $this->_write();
            fclose($this->_fileHandle);
        }
        /*
        if (is_resource($this->_lockHandle)) {
            fclose($this->_lockHandle);
        }
         */
    }

    /**
     * Information extracted from attributes with a higher priority
     * override equivelent information extracted from attributes with
     * lower priority.
     *
     * @param mixed $message May be a string or an exception.
     * @param int $severity
     * @param mixed $data Data to be dumped to log.
     *      Use SLogger::NO_DATA for "no value" instead of NULL.
     * @param array $args with following attributes (in order of priority)
     *      file        optional    ignored if $message is an exception
     *      line        optional    ignored if $message is an exception
     */
    public function log($message, $severity, $data = self::NO_DATA, $args = array()) {
        if ($args === NULL) {
            $args = array();
        }
        if ($this->severityThreshold === self::OFF) {
            return;
        }
        $time = self::_udate($this->dateFormat);

        if ($severity <= $this->smartSeverityThreshold) {
            $this->_verbose = TRUE;
        }
        $severityStr = strtoupper($this->_toSeverityString($this->_toSeverityOrdinal($severity)));

        $file = null;
        $line = null;
        $trace = null;

        if ($message instanceof Exception) {
            $exception = $message;
            $file = $exception->getFile();
            $line = $exception->getLine();
            $message = get_class($exception).": ".$exception->getMessage();

            // these are our templates
            $traceline = "#%s %s(%s): %s(%s)";

            // alter your trace as you please, here
            $etrace = $exception->getTrace();
            /*
            foreach ($trace as $key => $stackPoint) {
                // I'm converting arguments to their type
                // (prevents passwords from ever getting logged as anything other than 'string')
                $trace[$key]['args'] = array_map('gettype', $trace[$key]['args']);
            }
             */

            // build your tracelines
            $trace = array();
            $key = 0;
            foreach ($etrace as $key => $stackPoint) {
                $trace[] = sprintf(
                    $traceline,
                    $key,
                    $stackPoint['file'],
                    $stackPoint['line'],
                    $stackPoint['function'],
                    implode(', ', $stackPoint['args'])
                );
            }
            // trace always ends with {main}
            $trace[] = '#' . ++$key . ' {main}';
            $trace = implode("\n", $trace);
        }

        // $message is a string
        else {
            if (isset($args['file'])) {
                $file = $args['file'];
            }
            if (isset($args['line'])) {
                $line = $args['line'];
            }
            // Still no file (and line)?
            // Detect them using a backtrace.
            if (!$file) {
                // Detect file and line
                $bt = debug_backtrace();
                # Unroll to first call not from this file.
                $thisFile = basename(__FILE__);
                while (basename($bt[0]['file']) == $thisFile) {
                    array_shift($bt);
                }
                $info = array_shift($bt);
                $file = $info['file'];
                $line = $info['line'];
            }
        }

        // Extract data. null is a valid data value.
        // Check for the 'data' key (i.e., don't use isset($args['data']).
        if (array_key_exists('data', $args)) {
            $data = $args['data'];
        }
        $data = ($data === SLogger::NO_DATA) ? null : SLogger::_toString($data);

        // Enqueue the message.
        $this->_queues[$severity][] = array(
            $this->_nextMessageId++,
            array(
                $time,
                $severityStr,
                $message,
                "$file($line)",
                $trace,
                $data
            ),
        );
    }

    public function emergency($message, $data = self::NO_DATA, $args = array()) {
        $this->log($message, self::EMERGENCY, $data, $args);
    }
    public function alert($message, $data = self::NO_DATA, $args = array()) {
        $this->log($message, self::ALERT, $data, $args);
    }
    public function critical($message, $data = self::NO_DATA, $args = array()) {
        $this->log($message, self::CRITICAL, $data, $args);
    }
    public function error($message, $data = self::NO_DATA, $args = array()) {
        $this->log($message, self::ERROR, $data, $args);
    }
    public function warning($message, $data = self::NO_DATA, $args = array()) {
        $this->log($message, self::WARNING, $data, $args);
    }
    public function notice($message, $data = self::NO_DATA, $args = array()) {
        $this->log($message, self::NOTICE, $data, $args);
    }
    public function info($message, $data = self::NO_DATA, $args = array()) {
        $this->log($message, self::INFORMATIONAL, $data, $args);
    }
    public function debug($message, $data = self::NO_DATA, $args = array()) {
        $this->log($message, self::DEBUG, $data, $args);
    }

    /**
     * Flushes log messages to file.
     */
    public function flush() {
        $this->_write();
        fflush($this->_fileHandle);
    }


    private function _toSeverityOrdinal($severityString) {
        if (!is_string($severityString)) {
            return $severityString;
        }
        $severityString = strtolower($severityString);
        $len = strlen($severityString);
        $n = count(self::$_severities);
        for($i = 0; $i < $n; $i++) {
            if ($severityString === substr(self::$_severities[$i], 0, $len)) {
                return $i;
            }
        }
        throw new Exception("Unknown severity: $severityString");
    }

    private function _toSeverityString($severityOrdinal) {
        return self::$_severities[$severityOrdinal];
    }

    /** Smartly write messages to log file. */
    private function _write() {

        // Do nothing if OFF.
        if ($this->severityThreshold === self::OFF) {
            return;
        }

        // Gather queues smartly.
        $queues = array();
        if ($this->_verbose) {
            $queues = $this->_queues;
        } else {
            for($i = $this->severityThreshold ; $i >= 0 ; $i--) {
                $queues[] = $this->_queues[$i];
            }
        }

        // Merge and sort messages.
        $messages = call_user_func_array("array_merge", $queues);
        usort($messages, array("SLogger", "_compareMessages"));

        /*
        // Write messages to file.
        self::_lock($this->_lockHandle, $this->_lockFilePath);
         */
        if (!$this->_mutex->lock()) {
            throw new Exception("Could not lock {$this->_lockFilePath}");
        }
        $e = null;
        try {
            foreach ($messages as $m ) {
                fputcsv($this->_fileHandle, $m[1]);
            }
        } catch (Exception $f) {
            $e = $f;
            // Rethrow $e after unlock. See below.
        }
        /*
        $this->_unlock($this->_lockHandle);
         */
        $this->_mutex->unlock();
        $this->_buildQueues();
        if ($e !== null) {
            throw $e;
        }
    }

    /**
     * Delete logs greater than $this->maxDays.
     */
    private function _deleteOldLogs()
    {
        $seconds = $this->maxDays*24*60*60;
        $directory = dirname($this->_logFilePath);
        $pattern = '/log_(?P<date>[0-9]{4}-[0-9][0-9]-[0-9][0-9])(-[0-9]+)?\.csv$/';
        foreach (scandir($directory) as $file) {
            if (preg_match($pattern, $file, $matches)) {
                $cutoff = time() - $seconds;
                $fileTime = strtotime($matches['date']);
                if ($fileTime < $cutoff) {
                    $file_path = $directory.DIRECTORY_SEPARATOR.$file;
                    unlink($file_path);
                }
            }
        }
    }

    private function _buildQueues() {
        // Build queues.
        foreach(self::$_severities as $s) {
            $this->_queues[] = array();
        }
    }

    private static function _lock($handle, $file) {
        if (flock($handle, LOCK_EX) === FALSE) {
            throw new Exception("Could not lock log file: $file");
        }
    }
    private static function _unlock($handle) {
        flock($handle, LOCK_UN);
    }

    #################################################################
    # Facilities for logging PHP errors and exceptions.

    public static $errorExceptionLogger = 'default';

    /**
     * Handler to log errors.
     */
    static function _logError($num, $str, $file, $line, $context = null) {
        if (error_reporting() == 0) {
            // @ suppression support
            return;
        }
        switch($num) {
        case E_CORE_ERROR:
            SLogger::get(self::$errorExceptionLogger)->
                emergency($str, self::NO_DATA, array('file'=>$file,'line'=>$line));
            break;
        case E_ERROR:
        case E_COMPILE_ERROR:
        case E_USER_ERROR:
        case E_PARSE:
        case E_RECOVERABLE_ERROR:
            SLogger::get(self::$errorExceptionLogger)->
                alert($str, self::NO_DATA, array('file'=>$file,'line'=>$line));
            break;
        case E_WARNING:
        case E_CORE_WARNING:
        case E_COMPILE_WARNING:
        case E_USER_WARNING:
        case E_DEPRECATED:
        case E_USER_DEPRECATED:
            SLogger::get(self::$errorExceptionLogger)->
                warning($str, self::NO_DATA, array('file'=>$file,'line'=>$line));
            break;
        case E_NOTICE:
        case E_USER_NOTICE:
        case E_STRICT:
            SLogger::get(self::$errorExceptionLogger)->
                notice($str, self::NO_DATA, array('file'=>$file,'line'=>$line));
            break;
        default:
            SLogger::get(self::$errorExceptionLogger)->
                warning('Unknown error type ('.$num.')', self::NO_DATA,
                    array('file'=>__FILE__,'line'=>__LINE__));
            break;
        }
        return FALSE; // let the normal error handler run
    }

    /**
     * Handler to log exceptions.
     */
    static function _logException(Exception $e) {
        try {
            if (ini_get('display_errors') == 1) {
                echo "$e\n";
            }
            self::get(self::$errorExceptionLogger)->alert($e);
        } catch (Exception $e) {
            echo "$e";
        }
    }

    /**
     * Handler to log fatal errors.
     */
    static function _logFatal() {
        $error = error_get_last();
        if ($error["type"] == E_ERROR) {
            self::_logError($error["type"], $error["message"], $error["file"], $error["line"]);
        }
    }

    /**
     * Installs error and exception handlers to log them.
     *
     * @param string $logger Default logger for handlers. Default is 'default'.
     * @param int $errorTypes Error types to report. Default is -1 (all).
     * @param bool $displayErrors True displays errors to browser. Default is 0 (false).
     */
    public static function installErrorHandlers($logger = 'default', $errorTypes = -1, $displayErrors = 0) {
        self::$errorExceptionLogger = $logger;
        register_shutdown_function(array('SLogger', "_logFatal"));
        set_error_handler         (array('SLogger', '_logError'));
        set_exception_handler     (array('SLogger', '_logException'));
        ini_set                   ("display_errors", "$displayErrors");
        error_reporting           ($errorTypes);
    }

}


/*
 * @author harray http://stackoverflow.com/users/365999/harry
 * @source http://stackoverflow.com/questions/325806/best-way-to-obtain-a-lock-in-php
 * @since 2014-02-10
 *
CLASS ExclusiveLock
Description
==================================================================
This is a pseudo implementation of mutex since php does not have
any thread synchronization objects
This class uses flock() as a base to provide locking functionality.
Lock will be released in following cases
1 - user calls unlock
2 - when this lock object gets deleted
3 - when request or script ends
==================================================================
Usage:

//get the lock
$lock = new ExclusiveLock( "mylock" );

//lock
if( $lock->lock( ) == FALSE )
    error("Locking failed");
//--
//Do your work here
//--

//unlock
$lock->unlock();
===================================================================
 */
class ExclusiveLock
{
    protected $key   = null;  //user given value
    protected $file  = null;  //resource to lock
    protected $own   = FALSE; //have we locked resource
    protected $filename = null;  //name of lockfile.

    function __construct( $key ) 
    {
        $this->key = $key;
        $this->filename = "$key.lockfile";
        //create a new resource or get exisitng with same key
        $this->file = fopen($this->filename, 'w+');
    }


    function __destruct() 
    {
        if( $this->own == TRUE )
            $this->unlock( );
    }


    function lock( ) 
    {
        if( !flock($this->file, LOCK_EX)) 
        { //failed
            $key = $this->key;
            error_log("ExclusiveLock::acquire_lock FAILED to acquire lock [$key]");
            return FALSE;
        }
        ftruncate($this->file, 0); // truncate file
        //write something to just help debugging
        fwrite( $this->file, "Locked\n");
        fflush( $this->file );

        $this->own = TRUE;
        return $this->own;
    }


    function unlock( ) 
    {
        $key = $this->key;
        if( $this->own == TRUE ) 
        {
            if( !flock($this->file, LOCK_UN) )
            { //failed
                error_log("ExclusiveLock::lock FAILED to release lock [$key]");
                return FALSE;
            }
            ftruncate($this->file, 0); // truncate file
            //write something to just help debugging
            fwrite( $this->file, "Unlocked\n");
            fflush( $this->file );
        }
        else
        {
            error_log("ExclusiveLock::unlock called on [$key] but its not acquired by caller");
        }
        $this->own = FALSE;
        return $this->own;
    }

    function getFilename() {
        return $this->filename;
    }
};


