<?php
/**
 * Logging system.
 */
class SLogger
{
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

    private static $_instances = array();

    public static $severities = array(
        "emergency",
        "alert",
        "critical",
        "error",
        "warning",
        "notice",
        "informational",
        "debug",
    );

    public static function toString($data) {
        $s = "";
        if ($data !== self::NO_DATA) {
            ob_start();
            var_dump($data);
            $s = ob_get_clean();
        }
        return trim($s);
    }

    public static function udate($format = 'u', $utimestamp = null) {
        if (is_null($utimestamp))
            $utimestamp = microtime(true);

        $timestamp = floor($utimestamp);
        $milliseconds = round(($utimestamp - $timestamp) * 1000000);

        return date(preg_replace('`(?<!\\\\)u`', $milliseconds, $format), $timestamp);
    }

    public static function add($confs) {
        foreach ($confs as $name => $conf) {
            $path = $conf[0];
            unset($conf[0]);
            self::$_instances[$name] = new SLogger($path, $conf);
        }
    }

    public static function get($name = 'default') {
        return self::$_instances[$name];
    }

    static function compareMessages($a, $b) {
        return $a[0] - $b[0];
    }

    /** Directory of log files */
    public $logDirectory = NULL;
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


    private $_logFilePath = null;
    private $_fileHandle = null;
    private $_verbose = FALSE;
    private $_queues = array();
    private $_lockFilePath = null;
    private $_lockHandle = null;

    /**
     * @param string $logDirectory Where logs will be written.
     * @param array $args Configuration array to initialize public attributes.
     */
    public function __construct($logDirectory, $args = array()) {

        $this->logDirectory = $logDirectory;

        // Initialize public attributes from $args.
        foreach ($args as $key => $val) {
            $key = ltrim($key, '_');
            $this->$key = $val;
        }

        // Convert severity strings to ordinals.
        $this->severityThreshold      = $this->_toSeverityOrdinal($this->severityThreshold);
        $this->smartSeverityThreshold = $this->_toSeverityOrdinal($this->smartSeverityThreshold);

        $this->_buildQueues();

        // Validate logDirectory.
        if ($this->logDirectory === NULL) {
            throw new Exception("logDirectory is required");
        }

        // Sanatize logDirectory.
        $this->logDirectory = rtrim($this->logDirectory, '\\/');

        // Don't rotate, delete, create, or open log files if OFF.
        if ($this->severityThreshold !== self::OFF) {

            // Create log directory as needed
            if (!file_exists($this->logDirectory)) {
                mkdir($this->logDirectory, $this->defaultPermission, true);
            }

            // Identify lock file for log directory
            $this->_lockFilePath = $this->logDirectory
                . DIRECTORY_SEPARATOR
                . 'log.lock';

            // Verify permissions on lock file
            if (file_exists($this->_lockFilePath) && !is_writable($this->_lockFilePath)) {
                throw new Exception("Please check permissions on lock file: {$this->_lockFilePath}");
            }

            // Open lock file in preparation for locking
            $this->_lockHandle = fopen($this->_lockFilePath, 'w');
            if (!is_resource($this->_lockHandle)) {
                throw new Exception("Could not open lock file: {$this->_lockFilePath}");
            }

            // Lock file.
            if (self::_lock($this->_lockHandle, LOCK_EX) === FALSE) {
                throw new Exception("Could not aquire lock on lock file: {$this->_lockFilePath}");
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
                    $this->_logFilePath = $this->logDirectory
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
            // Unlock file.
            self::_unlock($this->_lockHandle);
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
        if (is_resource($this->_lockHandle)) {
            fclose($this->_lockHandle);
        }
    }

    /**
     * Log $message with $severity and $data.
     */
    public function log($message, $severity = self::DEBUG, $data = SLogger::NO_DATA) {
        if ($this->severityThreshold === self::OFF) {
            return;
        }
        $t = self::udate($this->dateFormat);
        $s = strtoupper($this->_toSeverityString($this->_toSeverityOrdinal($severity)));
        $i = new SCallInfo();
        $d = self::toString($data);
        $this->_queues[$s][] = array(
            $this->_nextMessageId++,
            array(
                $t,
                $s,
                $i->getContext(),
                $message,
                $d,
                $i->getLocation(),
            )
        );
        if ($s <= $this->smartSeverityThreshold) {
            $this->_verbose = TRUE;
        }
    }

    public function emergency($message, $data = SLogger::NO_DATA) {
        $this->log($message, self::EMERGENCY, $data);
    }
    public function alert($message, $data = SLogger::NO_DATA) {
        $this->log($message, self::ALERT, $data);
    }
    public function critical($message, $data = SLogger::NO_DATA) {
        $this->log($message, self::CRITICAL, $data);
    }
    public function error($message, $data = SLogger::NO_DATA) {
        $this->log($message, self::ERROR, $data);
    }
    public function warning($message, $data = SLogger::NO_DATA) {
        $this->log($message, self::WARNING, $data);
    }
    public function notice($message, $data = SLogger::NO_DATA) {
        $this->log($message, self::NOTICE, $data);
    }
    public function info($message, $data = SLogger::NO_DATA) {
        $this->log($message, self::INFORMATIONAL, $data);
    }
    public function debug($message, $data = SLogger::NO_DATA) {
        $this->log($message, self::DEBUG, $data);
    }


    private function _toSeverityOrdinal($severityString) {
        if (!is_string($severityString)) {
            return $severityString;
        }
        $severityString = strtolower($severityString);
        $len = strlen($severityString);
        $n = count(self::$severities);
        for($i = 0; $i < $n; $i++) {
            if ($severityString === substr(self::$severities[$i], 0, $len)) {
                return $i;
            }
        }
        throw new Exception("Unknown severity: $severityString");
    }

    private function _toSeverityString($severityOrdinal) {
        return self::$severities[$severityOrdinal];
    }

    public function flush() {
        $this->_write();
        fflush($this->_fileHandle);
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
        usort($messages, array("SLogger", "compareMessages"));

        // Write messages to file.
        $this::_lock($this->_lockHandle, $this->_lockFilePath);
        $e = null;
        try {
            foreach ($messages as $m ) {
                fputcsv($this->_fileHandle, $m[1]);
            }
        } catch (Exception $f) {
            $e = $f;
            // Rethrow $e after unlock. See below.
        }
        $this->_unlock($this->_lockHandle);
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
        foreach(self::$severities as $s) {
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
}

/**
 * Gathers call site information from stack trace.
 */
class SCallInfo {
    private $_file = NULL;
    private $_line = NULL;
    private $_class = NULL;
    private $_type = NULL;
    private $_function = NULL;

    public function __construct() {
        $bt = debug_backtrace();

        # Log the call site information.
        # Unroll to first call not from SLogger.php.
        while (basename($bt[0]['file']) == 'SLogger.php') {
            $last = array_shift($bt);
        }

        # Call site information straddles two levels of the call stack.
        # Save these in $info and $next.
        $info = array_shift($bt);
        $next = array_shift($bt);

        # If the call was made from a method (static or instance) get the class.
        if (isset($next['class'])) {
            $this->_class = $next['class'];
            $this->_type = $next['type'];
            $this->_function = $next['function'];
        }
        else if (
            array_search($next['function'],
                explode(' ', 'require require_once include include_once')) === FALSE
        ) {
            $this->_function = $next['function'];
        }

        $this->_file = $info['file'];
        $this->_line = $info['line'];

    }

    /**
     * Returns "file:lineno".
     */
    public function getLocation() {
        return "{$this->_file}:{$this->_line}";
    }

    /**
     * Returns "class->function()", "class::function()", "function()", or "" depending on context.
     */
    public function getContext() {
        if ($this->_class !== NULL) {
            return "{$this->_class}{$this->_type}{$this->_function}()";
        } else if ($this->_function !== NULL) {
            return "{$this->_function}()";
        } else {
            return "";
        }
    }
}
