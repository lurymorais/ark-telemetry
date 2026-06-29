<?php
/**
 * Funções auxiliares para o sistema ARK Telemetry
 * @package ARKTelemetry
 */

/**
 * Função para logging
 * 
 * @param string $message Mensagem para log
 * @param string $level Nível do log (debug, info, warning, error)
 */
function ark_log($message, $level = 'info') {
    if (!defined('ARK_LOG_FILE') || !ARK_LOG_FILE) {
        // Fallback para error_log do PHP
        error_log("ARK Telemetry [$level]: " . $message);
        return;
    }
    
    $logDir = dirname(ARK_LOG_FILE);
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    
    $logMessage = date('Y-m-d H:i:s') . " [{$level}] " . $message . PHP_EOL;
    error_log($logMessage, 3, ARK_LOG_FILE);
}

/**
 * Resposta JSON padronizada com CORS completo
 * 
 * @param array $data Dados a serem retornados
 * @param int $status_code Código HTTP
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
 * Rate limiting simplificado usando arquivo temporário
 * 
 * @param string $identifier Identificador único (ex: NAAN, URL)
 * @param int $max_attempts Número máximo de tentativas
 * @param int $window Janela de tempo em segundos
 * @return bool True se permitido, False se excedeu limite
 */
function ark_check_rate_limit($identifier, $max_attempts = 5, $window = 3600) {
    // Em desenvolvimento, sempre permite
    if (defined('ARK_DEBUG_MODE') && ARK_DEBUG_MODE === true) {
        return true;
    }
    
    $rateFile = sys_get_temp_dir() . '/ark_rate_' . md5($identifier) . '.tmp';
    $now = time();
    
    if (file_exists($rateFile)) {
        $data = file_get_contents($rateFile);
        $attempts = (int)$data;
        
        // Verifica se o arquivo expirou (usando timestamp do arquivo)
        $fileTime = filemtime($rateFile);
        if ($now - $fileTime > $window) {
            // Reset window
            $attempts = 1;
        } else {
            $attempts++;
        }
        
        if ($attempts > $max_attempts) {
            ark_log("Rate limit exceeded for {$identifier}", 'warning');
            return false;
        }
        
        file_put_contents($rateFile, $attempts);
        touch($rateFile, $fileTime); // Mantém timestamp original
    } else {
        file_put_contents($rateFile, 1);
    }
    
    return true;
}

/**
 * Sanitiza entrada para prevenir XSS
 * 
 * @param mixed $data Dados a serem sanitizados
 * @return mixed Dados sanitizados
 */
function ark_sanitize_input($data) {
    if (is_array($data)) {
        return array_map('ark_sanitize_input', $data);
    }
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

/**
 * Valida formato do NAAN
 * 
 * @param string $naan NAAN a ser validado
 * @return bool True se válido
 */
function ark_validate_naan($naan) {
    // NAAN pode conter letras, números, underscores e dois pontos
    return preg_match('/^[A-Za-z0-9_]{2,40}$/', $naan) === 1;
}

/**
 * Valida URL
 * 
 * @param string $url URL a ser validada
 * @return bool True se válida
 */
function ark_validate_url($url) {
    return filter_var($url, FILTER_VALIDATE_URL) !== false;
}

/**
 * Obtém geolocalização por IP (simplificado)
 * 
 * @param string $ip Endereço IP
 * @return array|null Dados de localização
 */
function ark_get_geolocation($ip = null) {
    // Implementação opcional para estatísticas
    return null;
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
    ], 202); // HTTP 202 Accepted
}