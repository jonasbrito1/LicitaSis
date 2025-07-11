<?php
/**
 * Sistema de Auditoria para LicitaSis
 * Arquivo: includes/audit.php
 * 
 * Sistema para registrar ações dos usuários no sistema
 */

/**
 * Registra uma ação do usuário no log de auditoria
 */
function logUserAction($action, $table, $record_id = null, $details = null) {
    global $pdo;
    
    if (!isset($_SESSION['user'])) {
        return false;
    }
    
    try {
        // Verifica se a tabela de auditoria existe
        $stmt = $pdo->query("SHOW TABLES LIKE 'audit_log'");
        if (!$stmt->fetch()) {
            createAuditTable($pdo);
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO audit_log 
            (user_id, user_name, action, table_name, record_id, details, ip_address, user_agent, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        return $stmt->execute([
            $_SESSION['user']['id'],
            $_SESSION['user']['name'],
            $action,
            $table,
            $record_id,
            $details ? json_encode($details) : null,
            getClientIP(),
            $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ]);
    } catch (Exception $e) {
        error_log("Erro no log de auditoria: " . $e->getMessage());
        return false;
    }
}

/**
 * Cria a tabela de auditoria se não existir
 */
function createAuditTable($pdo) {
    $sql = "
        CREATE TABLE IF NOT EXISTS audit_log (
            id INT(11) AUTO_INCREMENT PRIMARY KEY,
            user_id INT(11) NOT NULL,
            user_name VARCHAR(255) NOT NULL,
            action ENUM('CREATE', 'READ', 'UPDATE', 'DELETE', 'LOGIN', 'LOGOUT', 'ACCESS_DENIED', 'PROFILE_UPDATE', 'PASSWORD_CHANGE') NOT NULL,
            table_name VARCHAR(100),
            record_id INT(11),
            details TEXT,
            ip_address VARCHAR(45),
            user_agent TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_id (user_id),
            INDEX idx_action (action),
            INDEX idx_table_name (table_name),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
    ";
    
    return $pdo->exec($sql);
}

/**
 * Obtém o IP real do cliente
 */
function getClientIP() {
    $ipKeys = ['HTTP_CF_CONNECTING_IP', 'HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR'];
    
    foreach ($ipKeys as $key) {
        if (array_key_exists($key, $_SERVER) === true) {
            foreach (explode(',', $_SERVER[$key]) as $ip) {
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                    return $ip;
                }
            }
        }
    }
    
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

/**
 * Registra tentativa de login
 */
function logLoginAttempt($email, $success, $error_message = null) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO audit_log 
            (user_id, user_name, action, table_name, details, ip_address, user_agent, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $details = [
            'email' => $email,
            'success' => $success,
            'error_message' => $error_message,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        return $stmt->execute([
            $success && isset($_SESSION['user']) ? $_SESSION['user']['id'] : null,
            $success && isset($_SESSION['user']) ? $_SESSION['user']['name'] : $email,
            $success ? 'LOGIN' : 'ACCESS_DENIED',
            'users',
            json_encode($details),
            getClientIP(),
            $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ]);
    } catch (Exception $e) {
        error_log("Erro no log de login: " . $e->getMessage());
        return false;
    }
}

/**
 * Registra logout
 */
function logLogout() {
    global $pdo;
    
    if (!isset($_SESSION['user'])) {
        return false;
    }
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO audit_log 
            (user_id, user_name, action, table_name, details, ip_address, user_agent, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $details = [
            'logout_time' => date('Y-m-d H:i:s'),
            'session_duration' => isset($_SESSION['login_time']) ? (time() - $_SESSION['login_time']) : null
        ];
        
        return $stmt->execute([
            $_SESSION['user']['id'],
            $_SESSION['user']['name'],
            'LOGOUT',
            'users',
            json_encode($details),
            getClientIP(),
            $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ]);
    } catch (Exception $e) {
        error_log("Erro no log de logout: " . $e->getMessage());
        return false;
    }
}

/**
 * Obtém o histórico de ações de um usuário
 */
function getUserAuditHistory($user_id, $limit = 50) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM audit_log 
            WHERE user_id = ? 
            ORDER BY created_at DESC 
            LIMIT ?
        ");
        $stmt->execute([$user_id, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Erro ao buscar histórico: " . $e->getMessage());
        return [];
    }
}

/**
 * Obtém estatísticas de auditoria
 */
function getAuditStats($days = 30) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            SELECT 
                action,
                COUNT(*) as count,
                COUNT(DISTINCT user_id) as unique_users
            FROM audit_log 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            GROUP BY action
            ORDER BY count DESC
        ");
        $stmt->execute([$days]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Erro ao buscar estatísticas: " . $e->getMessage());
        return [];
    }
}

/**
 * Limpa logs antigos
 */
function cleanOldLogs($days = 365) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            DELETE FROM audit_log 
            WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
        ");
        $result = $stmt->execute([$days]);
        $deletedRows = $stmt->rowCount();
        
        logUserAction('DELETE', 'audit_log', null, [
            'action' => 'cleanup_old_logs',
            'days_kept' => $days,
            'deleted_rows' => $deletedRows
        ]);
        
        return $deletedRows;
    } catch (Exception $e) {
        error_log("Erro ao limpar logs: " . $e->getMessage());
        return false;
    }
}

/**
 * Formata uma ação para exibição
 */
function formatAuditAction($action) {
    $actions = [
        'CREATE' => 'Criação',
        'READ' => 'Consulta',
        'UPDATE' => 'Atualização',
        'DELETE' => 'Exclusão',
        'LOGIN' => 'Login',
        'LOGOUT' => 'Logout',
        'ACCESS_DENIED' => 'Acesso Negado',
        'PROFILE_UPDATE' => 'Atualização de Perfil',
        'PASSWORD_CHANGE' => 'Alteração de Senha'
    ];
    
    return $actions[$action] ?? $action;
}

/**
 * Obtém ícone para uma ação
 */
function getAuditActionIcon($action) {
    $icons = [
        'CREATE' => 'fas fa-plus-circle text-success',
        'READ' => 'fas fa-eye text-info',
        'UPDATE' => 'fas fa-edit text-warning',
        'DELETE' => 'fas fa-trash text-danger',
        'LOGIN' => 'fas fa-sign-in-alt text-success',
        'LOGOUT' => 'fas fa-sign-out-alt text-secondary',
        'ACCESS_DENIED' => 'fas fa-ban text-danger',
        'PROFILE_UPDATE' => 'fas fa-user-edit text-info',
        'PASSWORD_CHANGE' => 'fas fa-key text-warning'
    ];
    
    return $icons[$action] ?? 'fas fa-question-circle text-muted';
}

/**
 * Registra acesso negado
 */
function logAccessDenied($page, $required_permission) {
    global $pdo;
    
    if (!isset($_SESSION['user'])) {
        return false;
    }
    
    $details = [
        'page' => $page,
        'required_permission' => $required_permission,
        'user_permission' => $_SESSION['user']['permission'],
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    return logUserAction('ACCESS_DENIED', 'access_control', null, $details);
}

/**
 * Atualiza último login do usuário
 */
function updateLastLogin($user_id) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
        return $stmt->execute([$user_id]);
    } catch (Exception $e) {
        error_log("Erro ao atualizar último login: " . $e->getMessage());
        return false;
    }
}

/**
 * Verifica se há tentativas de login suspeitas
 */
function checkSuspiciousActivity($email, $timeframe = 300) { // 5 minutos
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as attempts 
            FROM audit_log 
            WHERE action = 'ACCESS_DENIED' 
            AND details LIKE ? 
            AND created_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)
        ");
        $stmt->execute(['%"email":"' . $email . '"%', $timeframe]);
        $result = $stmt->fetch();
        
        return $result['attempts'] >= 5; // 5 tentativas em 5 minutos
    } catch (Exception $e) {
        error_log("Erro ao verificar atividade suspeita: " . $e->getMessage());
        return false;
    }
}

/**
 * Gera relatório de atividades
 */
function generateActivityReport($start_date, $end_date, $user_id = null) {
    global $pdo;
    
    try {
        $sql = "
            SELECT 
                al.*,
                u.name as user_full_name,
                u.permission as user_permission
            FROM audit_log al
            LEFT JOIN users u ON al.user_id = u.id
            WHERE al.created_at BETWEEN ? AND ?
        ";
        $params = [$start_date, $end_date];
        
        if ($user_id) {
            $sql .= " AND al.user_id = ?";
            $params[] = $user_id;
        }
        
        $sql .= " ORDER BY al.created_at DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Erro ao gerar relatório: " . $e->getMessage());
        return [];
    }
}
?>