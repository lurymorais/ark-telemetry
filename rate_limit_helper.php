<?php
/**
 * Rate Limit Helper com Backoff Exponencial (sem bloqueio)
 * Apenas aumenta o tempo de espera a cada falha, nunca bloqueia permanentemente
 */

class RateLimitHelper {
    private $pdo;
    private $table = 'ark_rate_limits';
    
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
     * Calcula o tempo de espera baseado no número de tentativas (backoff exponencial)
     * Fórmula: min(2^attempts * base_window, max_wait)
     * 
     * @param int $attempts Número de tentativas (começa em 1)
     * @param int $base_window Janela base em segundos (ex: 60)
     * @param int $max_wait Espera máxima em segundos (ex: 86400 = 24h)
     * @return int Segundos de espera
     */
    private function calculateWaitTime($attempts, $base_window = 60, $max_wait = 86400) {
        // Para tentativas normais (1, 2, 3, 4, 5...)
        // 2^attempts * base_window, mas limitado a max_wait
        $wait = pow(2, $attempts - 1) * $base_window;
        return min($wait, $max_wait);
    }
    
    /**
     * Verifica se a ação é permitida
     * Retorna: ['allowed' => bool, 'wait_seconds' => int, 'message' => string]
     */
    public function check($identifier, $action, $base_window = 60) {
        $stmt = $this->pdo->prepare("
            SELECT attempts, next_allowed_at 
            FROM ark_rate_limits 
            WHERE identifier = ? AND action = ?
        ");
        $stmt->execute([$identifier, $action]);
        $record = $stmt->fetch();
        
        // Sem registro - permitido
        if (!$record) {
            return ['allowed' => true, 'wait_seconds' => 0, 'message' => '', 'attempts' => 0];
        }
        
        // Verifica se ainda está no tempo de espera
        if ($record['next_allowed_at'] && strtotime($record['next_allowed_at']) > time()) {
            $remaining = strtotime($record['next_allowed_at']) - time();
            $waitMinutes = ceil($remaining / 60);
            
            return [
                'allowed' => false, 
                'wait_seconds' => $remaining,
                'wait_minutes' => $waitMinutes,
                'message' => "Muitas tentativas. Aguarde {$waitMinutes} minutos antes de tentar novamente.",
                'attempts' => $record['attempts']
            ];
        }
        
        return ['allowed' => true, 'wait_seconds' => 0, 'message' => '', 'attempts' => $record['attempts']];
    }
    
    /**
     * Registra uma tentativa (apenas falhas aumentam o contador)
     * Sucesso: reseta o contador
     * Falha: incrementa tentativas e atualiza próximo tempo permitido
     * Calcula o tempo de espera baseado nas tentativas atuais + 1
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
     * Obtém número de tentativas atuais
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
     * Limpa registros antigos (executar em cron)
     */
    public function cleanOldRecords($hours = 24) {
        $stmt = $this->pdo->prepare("
            DELETE FROM ark_rate_limits 
            WHERE last_attempt < DATE_SUB(NOW(), INTERVAL ? HOUR)
        ");
        $stmt->execute([$hours]);
    }
    
    /**
     * Obtém estatísticas para debug
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