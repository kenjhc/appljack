<?php
/**
 * EVENT DEBUG LOGGER
 * This script provides detailed logging for event processing
 * It can be included in applpass.php and cpa-event.php for debugging
 */

class EventDebugLogger {
    private $logFile;
    private $environment;
    private $basePath;

    public function __construct() {
        // Auto-detect environment
        $currentPath = __DIR__;
        $httpHost = $_SERVER['HTTP_HOST'] ?? '';

        if (strpos($httpHost, 'dev.appljack.com') !== false) {
            $this->environment = 'DEV_SERVER';
            $this->basePath = "/chroot/home/appljack/appljack.com/html/dev/";
        } elseif (strpos($httpHost, 'appljack.com') !== false && strpos($httpHost, 'dev.') === false) {
            $this->environment = 'PRODUCTION';
            $this->basePath = "/chroot/home/appljack/appljack.com/html/admin/";
        } elseif (strpos($currentPath, '/chroot/') !== false) {
            if (strpos($currentPath, "/dev/") !== false) {
                $this->environment = 'DEV_SERVER';
                $this->basePath = "/chroot/home/appljack/appljack.com/html/dev/";
            } else {
                $this->environment = 'PRODUCTION';
                $this->basePath = "/chroot/home/appljack/appljack.com/html/admin/";
            }
        } else {
            $this->environment = 'LOCAL_DEV';
            $this->basePath = __DIR__ . DIRECTORY_SEPARATOR;
        }

        $this->logFile = $this->basePath . "event_debug.log";
    }

    public function log($message, $data = null) {
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[$timestamp] [$this->environment] $message";

        if ($data !== null) {
            $logEntry .= " | DATA: " . json_encode($data);
        }

        $logEntry .= "\n";

        // Write to log file
        file_put_contents($this->logFile, $logEntry, FILE_APPEND | LOCK_EX);

        // Also output if in CLI mode for debugging
        if (php_sapi_name() === 'cli') {
            echo $logEntry;
        }
    }

    public function logEventReceived($type, $params) {
        $this->log("EVENT_RECEIVED: $type", $params);
    }

    public function logQueueWrite($type, $file, $success, $data) {
        $status = $success ? "SUCCESS" : "FAILED";
        $this->log("QUEUE_WRITE: $type - $status", [
            'file' => $file,
            'data' => $data,
            'success' => $success
        ]);
    }

    public function logDatabaseOperation($operation, $table, $data) {
        $this->log("DATABASE: $operation on $table", $data);
    }

    public function logValueCalculation($type, $feedId, $budgetType, $calculatedValue) {
        $this->log("VALUE_CALC: $type", [
            'feedId' => $feedId,
            'budgetType' => $budgetType,
            'calculatedValue' => $calculatedValue
        ]);
    }

    public function logError($message, $error) {
        $this->log("ERROR: $message", [
            'error' => $error,
            'backtrace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5)
        ]);
    }

    public function getLogContents($lines = 100) {
        if (!file_exists($this->logFile)) {
            return "Log file not found: $this->logFile";
        }

        $content = file($this->logFile);
        $recent = array_slice($content, -$lines);
        return implode("", $recent);
    }

    public function clearLog() {
        file_put_contents($this->logFile, '');
        $this->log("LOG_CLEARED");
    }
}

// Usage example:
/*
$logger = new EventDebugLogger();
$logger->logEventReceived('CPC', $_GET);
$logger->logQueueWrite('CPC', $queueFile, true, $eventData);
$logger->logValueCalculation('CPC', $feedId, $budgetType, $cpcValue);
*/