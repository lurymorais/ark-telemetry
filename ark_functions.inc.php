<?php
/**
 * Helper functions for ARK Telemetry system
 * @package ARKTelemetry
 * @version 3.1.1.0
 */

/**
 * Log messages to PHP error log
 */
function ark_log($message, $level = 'info') {
    error_log("[ARK-Telemetry] [{$level}] " . $message);
}

/**
 * Standardized JSON response with CORS
 * 
 * @param array $data Data to return
 * @param int $status_code HTTP status code
 */
function ark_json_response($data, $status_code = 200) {
    http_response_code($status_code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/**
 * Simplified rate limiting using temporary file
 * 
 * @param string $identifier Unique identifier (e.g., NAAN, URL)
 * @param int $max_attempts Maximum number of attempts
 * @param int $window Time window in seconds
 * @return bool True if allowed, False if limit exceeded
 */
function ark_check_rate_limit($identifier, $max_attempts = 5, $window = 3600) {
    if (defined('ARK_DEBUG_MODE') && ARK_DEBUG_MODE === true) {
        return true;
    }
    
    $rateFile = sys_get_temp_dir() . '/ark_rate_' . md5($identifier) . '.tmp';
    $now = time();
    
    if (file_exists($rateFile)) {
        $data = file_get_contents($rateFile);
        $attempts = (int)$data;
        
        $fileTime = filemtime($rateFile);
        if ($now - $fileTime > $window) {
            $attempts = 1;
        } else {
            $attempts++;
        }
        
        if ($attempts > $max_attempts) {
            ark_log("Rate limit exceeded for {$identifier}", 'warning');
            return false;
        }
        
        file_put_contents($rateFile, $attempts);
        touch($rateFile, $fileTime);
    } else {
        file_put_contents($rateFile, 1);
    }
    
    return true;
}

/**
 * Sanitizes input to prevent XSS
 * 
 * @param mixed $data Data to sanitize
 * @return mixed Sanitized data
 */
function ark_sanitize_input($data) {
    if (is_array($data)) {
        return array_map('ark_sanitize_input', $data);
    }
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

/**
 * Validates NAAN format
 * 
 * @param string $naan NAAN to validate
 * @return bool True if valid
 */
function ark_validate_naan($naan) {
    return preg_match('/^[A-Za-z0-9_]{2,40}$/', $naan) === 1;
}

/**
 * Validates URL
 * 
 * @param string $url URL to validate
 * @return bool True if valid
 */
function ark_validate_url($url) {
    return filter_var($url, FILTER_VALIDATE_URL) !== false;
}

/**
 * Queue response helper - Returns a standardized response for queued requests
 * 
 * @param string $requestId Unique request identifier
 * @param int $assignedTimestamp Unix timestamp when request should execute
 * @param string $humanTime Human-readable execution time
 * @param int $waitSeconds Seconds to wait until execution
 * @return void Outputs JSON and exits
 */
function ark_queue_response($requestId, $assignedTimestamp, $humanTime, $waitSeconds)
{
    ark_json_response([
        'status' => 'queued',
        'request_id' => $requestId,
        'assigned_timestamp' => $assignedTimestamp,
        'human_time' => $humanTime,
        'wait_seconds' => $waitSeconds,
        'message' => 'Your request has been queued. It will be processed at ' . $humanTime
    ], 202);
}