<?php
/**
 * Logging system. Contains common constants and static functons.
 *
 * Severity constants based on RFC 5424: http://tools.ietf.org/html/rfc5424 .
 */
class SLogger
{
    /**
     * Named SLoggerObjects.
     */
    private static $_sloggerObjects = array();

    /**
     * Severity level to turn off logging. Prevents file rotation, deletion,
     * and creation.
     */
    const OFF = -1;

    const EMERGENCY = 0;
    const ALERT = 1;
    const CRITICAL = 2;
    const ERROR = 3;
    const WARNING = 4;
    const NOTICE = 5;
    const INFORMATIONAL = 6;
    const DEBUG = 7;

    /**
     * Value for data indicating no data. Do not use NULL, which is a valid
     * value.
     */
    const NO_DATA = "SLogger::NO_DATA";

    /**
     * Names of all the severities in order. Used to convert severities
     * between strings and ordinal values. Also used to match user provided
     * strings against severities.
     */
    public  static $_severities = array(
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
     * Helper to convert data to a string. Note, null is a valid value.
     * Use SLogger::NO_DATA for "no value".
     *
     * @param mixed $data.
     * @return string representation of $data.
     */
    public static function dataToString($data) {
        $s = "";
        if ($data !== self::NO_DATA) {
            ob_start();
            var_dump($data);
            $s = ob_get_clean();
        }
        return trim($s);
    }

    /**
     * Formats the current time, or $utimestamp, according to $format.
     *
     * @param string $format Same as that accepted by date(), plus 'u' represents microseconds.
     * @param float $utimestamp Time as returned by microtime(). Defaults to the current time.
     * @return string Date/time formatted as a string according to $format.
     *
     * @author daysnine at gmail dot com
     * @source http://php.net/manual/en/datetime.format.php
     * @since 2014-02-10
     */
    public static function udate($format = 'u', $utimestamp = null) {
        if (is_null($utimestamp))
            $utimestamp = microtime(true);
        $timestamp = floor($utimestamp);
        $milliseconds = round(($utimestamp - $timestamp) * 1000000);
        return date(preg_replace('`(?<!\\\\)u`', $milliseconds, $format), $timestamp);
    }

    /**
     * Create, configure, and name SLoggerObjects.
     *
     * @param array $confs Associative array whose keys are the names of loggers,
     * and whose values are associative arrays. These inner arrays' keys are
     * public attributes of SLogger, and their values are the initial values
     * for those attributes.
     */
    public static function add($confs) {
        foreach ($confs as $name => $conf) {
            $path = $conf[0];
            unset($conf[0]);
            self::$_sloggerObjects[$name] = new SLoggerObject($path, $conf);
        }
    }

    /**
     * Returns the SLoggerObject named by $name.
     *
     * @param string $name Default is 'default'.
     * @return SLoggerObject or FALSE if $name is not found.
     */
    public static function get($name = 'default') {
        if (array_key_exists($name, self::$_sloggerObjects)) {
            return self::$_sloggerObjects[$name];
        }
        return FALSE;
    }

    public static function toSeverityOrdinal($severityString) {
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

    public static function toSeverityString($severityOrdinal) {
        return self::$_severities[$severityOrdinal];
    }

}

/**
 * An SLoggerObject is responsible for managing log files
 * in a given directory and logging messages to those files.
 */
class SLoggerObject {

    /**
     * Report messages at or above this severity.
     */
    public $severityThreshold = SLogger::INFORMATIONAL;

    /**
     * Report all messages if any message is at or above this severity.
     */
    public $smartSeverityThreshold = SLogger::NOTICE;

    /**
     * Rotate log file if it becomes larger than this size in bytes.
     */
    public $maxFileSize = 100000000;

    /**
     * Format of date/time.
     */
    public $dateFormat = 'Y-m-d H:i:s.u';

    /**
     * Files created using these default permissions.
     */
    public $defaultPermission = 0777;

    /**
     * Delete logs after $deletAfterDays old.
     */
    public $maxDays = 7;

    /**
     * Directory to log to.
     */
    private $_logDirectory = NULL;

    /**
     * Path to log file.
     */
    private $_logFilePath = null;

    /**
     * Handle of log file.
     */
    private $_fileHandle = null;

    /**
     * TRUE indicates to log everything.
     */
    private $_verbose = FALSE;

    /**
     * Queues to hold log messages, indexed by severity.
     */
    private $_queues = array();

    /**
     * Path to lock file.
     */
    private $_lockFilePath = null;

    /**
     * ExclusiveLock object that manages the lock file.
     */
    private $_mutex = null;

    /**
     * Ensures directory and log file exists creating them as needed.
     * Deletes old log files. Rotates log files based on size and date.
     *
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
        $this->severityThreshold      = SLogger::toSeverityOrdinal($this->severityThreshold);
        $this->smartSeverityThreshold = SLogger::toSeverityOrdinal($this->smartSeverityThreshold);

        $this->_buildQueues();

        // Validate _logDirectory.
        if ($this->_logDirectory === NULL) {
            throw new Exception("Log directory is required");
        }

        // Sanatize _logDirectory. Remove trailing slash.
        $this->_logDirectory = rtrim($this->_logDirectory, '\\/');

        // Don't rotate, delete, create, or open log files if OFF.
        if ($this->severityThreshold !== SLogger::OFF) {

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
                // Rethrow after unlock. See below.
            }
            $this->_mutex->unlock();
            if ($e !== null) {
                throw $e;
            }
        }
    }


    /**
     * Writes messages to file.
     */
    public function __destruct() {
        if (is_resource($this->_fileHandle)) {
            $this->_write();
            fclose($this->_fileHandle);
        }
    }

    /**
     *
     * @param mixed $message May be a string or an exception.
     * @param int $severity
     * @param mixed $data Data to be dumped to log.
     *      Use SLogger::NO_DATA for "no value" instead of NULL.
     * @param array $args with following attributes.
     *      file        optional    ignored if $message is an exception
     *      line        optional    ignored if $message is an exception
     */
    public function log($message, $severity, $data = SLogger::NO_DATA, $args = array()) {
        if ($args === NULL) {
            $args = array();
        }
        if ($this->severityThreshold === SLogger::OFF) {
            return;
        }
        $time = SLogger::udate($this->dateFormat);

        if ($severity <= $this->smartSeverityThreshold) {
            $this->_verbose = TRUE;
        }
        $severityStr = strtoupper(SLogger::toSeverityString(SLogger::toSeverityOrdinal($severity)));

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
        $data = ($data === SLogger::NO_DATA) ? null : SLogger::dataToString($data);

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

    public function emergency($message, $data = SLogger::NO_DATA, $args = array()) {
        $this->log($message, SLogger::EMERGENCY, $data, $args);
    }
    public function alert($message, $data = SLogger::NO_DATA, $args = array()) {
        $this->log($message, SLogger::ALERT, $data, $args);
    }
    public function critical($message, $data = SLogger::NO_DATA, $args = array()) {
        $this->log($message, SLogger::CRITICAL, $data, $args);
    }
    public function error($message, $data = SLogger::NO_DATA, $args = array()) {
        $this->log($message, SLogger::ERROR, $data, $args);
    }
    public function warning($message, $data = SLogger::NO_DATA, $args = array()) {
        $this->log($message, SLogger::WARNING, $data, $args);
    }
    public function notice($message, $data = SLogger::NO_DATA, $args = array()) {
        $this->log($message, SLogger::NOTICE, $data, $args);
    }
    public function info($message, $data = SLogger::NO_DATA, $args = array()) {
        $this->log($message, SLogger::INFORMATIONAL, $data, $args);
    }
    public function debug($message, $data = SLogger::NO_DATA, $args = array()) {
        $this->log($message, SLogger::DEBUG, $data, $args);
    }

    /**
     * Flushes log messages to file.
     */
    public function flush() {
        $this->_write();
        fflush($this->_fileHandle);
    }


    /** Smartly write messages to log file. */
    private function _write() {

        // Do nothing if OFF.
        if ($this->severityThreshold === SLogger::OFF) {
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
        usort($messages, array("SLoggerObject", "_compareMessages"));

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
        foreach(SLogger::$_severities as $s) {
            $this->_queues[] = array();
        }
    }

    /**
     * Compares two messages stored in $_queues by ID for sorting.
     */
    private static function _compareMessages($a, $b) {
        return $a[0] - $b[0];
    }

}

/**
 * Facilities for logging PHP errors and exceptions.
 */
class SLoggerErrorHandler {

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
                emergency($str, SLogger::NO_DATA, array('file'=>$file,'line'=>$line));
            break;
        case E_ERROR:
        case E_COMPILE_ERROR:
        case E_USER_ERROR:
        case E_PARSE:
        case E_RECOVERABLE_ERROR:
            SLogger::get(self::$errorExceptionLogger)->
                alert($str, SLogger::NO_DATA, array('file'=>$file,'line'=>$line));
            break;
        case E_WARNING:
        case E_CORE_WARNING:
        case E_COMPILE_WARNING:
        case E_USER_WARNING:
        case E_DEPRECATED:
        case E_USER_DEPRECATED:
            SLogger::get(self::$errorExceptionLogger)->
                warning($str, SLogger::NO_DATA, array('file'=>$file,'line'=>$line));
            break;
        case E_NOTICE:
        case E_USER_NOTICE:
        case E_STRICT:
            SLogger::get(self::$errorExceptionLogger)->
                notice($str, SLogger::NO_DATA, array('file'=>$file,'line'=>$line));
            break;
        default:
            SLogger::get(self::$errorExceptionLogger)->
                warning('Unknown error type ('.$num.')', SLogger::NO_DATA,
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
            SLogger::get(self::$errorExceptionLogger)->alert($e);
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
    public static function install($logger = 'default', $errorTypes = -1, $displayErrors = 0) {
        self::$errorExceptionLogger = $logger;
        register_shutdown_function(array('SLoggerErrorHandler', "_logFatal"));
        set_error_handler         (array('SLoggerErrorHandler', '_logError'));
        set_exception_handler     (array('SLoggerErrorHandler', '_logException'));
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

