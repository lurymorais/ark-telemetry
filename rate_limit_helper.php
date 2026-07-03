<?php
/**
 * Rate Limit Helper with Exponential Backoff
 * Only increases wait time with each failure, never permanently blocks
 * 
 * @package ARKTelemetry
 * @version 3.1.0.0
 */

class RateLimitHelper {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->ensureTable();
    }
    
    private function ensureTable() {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS ark_rate_limits (
                id INT AUTO_INCREMENT PRIMARY KEY,
                identifier VARCHAR(100) NOT NULL,
                action VARCHAR(50) NOT NULL,
                attempts INT DEFAULT 1,
                first_attempt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                last_attempt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                next_allowed_at TIMESTAMP NULL,
                INDEX idx_identifier (identifier),
                INDEX idx_next_allowed (next_allowed_at),
                UNIQUE KEY uk_identifier_action (identifier, action)
            )
        ");
    }
    
    /**
     * Calculates wait time based on number of attempts (exponential backoff)
     * Formula: min(2^attempts * base_window, max_wait)
     * 
     * @param int $attempts Number of attempts (starts at 1)
     * @param int $base_window Base window in seconds (e.g., 60)
     * @param int $max_wait Maximum wait in seconds (e.g., 86400 = 24h)
     * @return int Wait seconds
     */
    private function calculateWaitTime($attempts, $base_window = 60, $max_wait = 86400) {
        $wait = pow(2, $attempts - 1) * $base_window;
        return min($wait, $max_wait);
    }
    
    /**
     * Checks if the action is allowed
     * Returns: ['allowed' => bool, 'wait_seconds' => int, 'message' => string]
     */
    public function check($identifier, $action, $base_window = 60) {
        $stmt = $this->pdo->prepare("
            SELECT attempts, next_allowed_at 
            FROM ark_rate_limits 
            WHERE identifier = ? AND action = ?
        ");
        $stmt->execute([$identifier, $action]);
        $record = $stmt->fetch();
        
        if (!$record) {
            return ['allowed' => true, 'wait_seconds' => 0, 'message' => '', 'attempts' => 0];
        }
        
        if ($record['next_allowed_at'] && strtotime($record['next_allowed_at']) > time()) {
            $remaining = strtotime($record['next_allowed_at']) - time();
            $waitMinutes = ceil($remaining / 60);
            
            return [
                'allowed' => false, 
                'wait_seconds' => $remaining,
                'wait_minutes' => $waitMinutes,
                'message' => "Too many attempts. Please wait {$waitMinutes} minutes before trying again.",
                'attempts' => $record['attempts']
            ];
        }
        
        return ['allowed' => true, 'wait_seconds' => 0, 'message' => '', 'attempts' => $record['attempts']];
    }
    
    /**
     * Records an attempt (only failures increment counter)
     * Success: resets counter
     * Failure: increments attempts and updates next allowed time
     */
    public function recordAttempt($identifier, $action, $success = true, $base_window = 60) {
        if ($success) {
            $stmt = $this->pdo->prepare("
                DELETE FROM ark_rate_limits 
                WHERE identifier = ? AND action = ?
            ");
            $stmt->execute([$identifier, $action]);
            return;
        }
        
        $stmt = $this->pdo->prepare("
            INSERT INTO ark_rate_limits (identifier, action, attempts, last_attempt, next_allowed_at)
            VALUES (?, ?, 1, NOW(), NULL)
            ON DUPLICATE KEY UPDATE
                attempts = attempts + 1,
                last_attempt = NOW(),
                next_allowed_at = DATE_ADD(NOW(), INTERVAL ? SECOND)
        ");
        
        $currentAttempts = $this->getAttempts($identifier, $action);
        $waitSeconds = $this->calculateWaitTime($currentAttempts + 1, $base_window);
        
        $stmt->execute([$identifier, $action, $waitSeconds]);
    }
    
    /**
     * Gets current number of attempts
     */
    private function getAttempts($identifier, $action) {
        $stmt = $this->pdo->prepare("
            SELECT attempts FROM ark_rate_limits 
            WHERE identifier = ? AND action = ?
        ");
        $stmt->execute([$identifier, $action]);
        $record = $stmt->fetch();
        return $record ? $record['attempts'] : 0;
    }
    
    /**
     * Cleans old records (run in cron)
     */
    public function cleanOldRecords($hours = 24) {
        $stmt = $this->pdo->prepare("
            DELETE FROM ark_rate_limits 
            WHERE last_attempt < DATE_SUB(NOW(), INTERVAL ? HOUR)
        ");
        $stmt->execute([$hours]);
    }
    
    /**
     * Gets statistics for debugging
     */
    public function getStats($identifier, $action) {
        $stmt = $this->pdo->prepare("
            SELECT attempts, next_allowed_at, last_attempt 
            FROM ark_rate_limits 
            WHERE identifier = ? AND action = ?
        ");
        $stmt->execute([$identifier, $action]);
        return $stmt->fetch();
    }
}