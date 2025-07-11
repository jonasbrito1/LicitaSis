<?php
/*
===========================================================================
SISTEMA DE CONTAS A PAGAR - LICITASIS v7.2
===========================================================================

PRINCIPAIS CORREÇÕES IMPLEMENTADAS:
- ✅ Sistema de permissões com fallback automático
- ✅ Verificação de dependências antes de incluir arquivos
- ✅ Funções de auditoria básicas se originais não existirem  
- ✅ Tratamento robusto de erros para requisições AJAX
- ✅ CORREÇÃO: Problema de truncamento ENUM -> VARCHAR
- ✅ Validação e mapeamento de status de pagamento
- ✅ Logs detalhados para diagnóstico
- ✅ Ferramentas de debug: Senha, Debug, Estrutura da Tabela
- ✅ Interface de usuário responsiva e moderna
- ✅ Autenticação do setor financeiro com senha padrão

SOLUÇÃO PARA ERRO "Data truncated for column 'status_pagamento'":
1. Conversão automática de ENUM para VARCHAR(20)
2. Mapeamento de valores de status (case-insensitive)
3. Validação rigorosa antes da inserção
4. Logs detalhados para diagnóstico
5. Verificação da estrutura da tabela via interface

DEPENDÊNCIAS OPCIONAIS:
- permissions.php (cria sistema básico se não existir)
- includes/audit.php (funções básicas criadas automaticamente)
- includes/header_template.php (sistema continua sem)

SENHA PADRÃO DO SETOR FINANCEIRO: Licitasis@2025

FERRAMENTAS DE DIAGNÓSTICO:
- Botão "Testar Senha": Valida senha localmente
- Botão "Debug": Informações detalhadas no console
- Botão "Tabela": Verifica estrutura do banco de dados

LOGS: Verifique error_log do servidor para diagnóstico detalhado
===========================================================================
*/

session_start();

// Controle de output para requisições AJAX
if (isset($_POST['update_status'])) {
    // Para requisições AJAX, inicia o buffer de output e define error reporting
    ob_start();
    error_reporting(E_ALL);
    ini_set('display_errors', 0); // Não mostra erros na saída para não quebrar o JSON
    
    // Define um error handler customizado para capturar warnings/notices
    set_error_handler(function($severity, $message, $file, $line) {
        error_log("LicitaSis - PHP Error: $message em $file linha $line");
        // Não interrompe a execução, apenas loga
        return false;
    });
}

// Verificação de sessão básica
if (!isset($_SESSION['user'])) {
    if (isset($_POST['update_status'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Usuário não autenticado']);
        exit();
    }
    header("Location: login.php");
    exit();
}

// Log do início da execução
error_log("LicitaSis - Contas a Pagar iniciado para usuário: " . ($_SESSION['user']['username'] ?? 'unknown'));

// Includes necessários na ordem correta
try {
    require_once('db.php');
    error_log("LicitaSis - Conexão com banco de dados carregada");
} catch (Exception $e) {
    error_log("LicitaSis - ERRO CRÍTICO: Não foi possível conectar com o banco de dados: " . $e->getMessage());
    
    if (isset($_POST['update_status'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Erro de conexão com banco de dados']);
        exit();
    }
    
    die("Erro de conexão com o banco de dados. Contate o administrador do sistema.");
}

// Include e inicialização do sistema de permissões
$permissionManager = null;
if (file_exists('permissions.php')) {
    include('permissions.php');
    
    // Verifica se as funções necessárias existem
    if (function_exists('initPermissions')) {
        try {
            $permissionManager = initPermissions($pdo);
            error_log("LicitaSis - Sistema de permissões inicializado com sucesso");
        } catch (Exception $e) {
            error_log("LicitaSis - Erro ao inicializar permissões: " . $e->getMessage());
            $permissionManager = null;
        }
    } else {
        error_log("LicitaSis - Função initPermissions não encontrada em permissions.php");
    }
} else {
    error_log("LicitaSis - Arquivo permissions.php não encontrado");
}

// Cria um sistema de permissões básico se não existe
if ($permissionManager === null) {
    error_log("LicitaSis - Criando sistema de permissões básico");
    
    class BasicPermissionManager {
        private $userRole;
        
        public function __construct($userRole = 'user') {
            $this->userRole = $userRole;
        }
        
        public function requirePermission($module, $action) {
            // Permite acesso básico para usuários logados
            if (!isset($_SESSION['user'])) {
                if (isset($_POST['update_status'])) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'error' => 'Acesso negado: usuário não autenticado']);
                    exit();
                }
                header("Location: login.php");
                exit();
            }
            return true;
        }
        
        public function hasPagePermission($module, $action) {
            // Verifica se o usuário tem permissão básica
            if (!isset($_SESSION['user'])) {
                return false;
            }
            
            // Permite edição para administradores ou se não há restrição específica
            $userPermission = $_SESSION['user']['permission'] ?? 'user';
            return ($userPermission === 'Administrador' || $action === 'read');
        }
    }
    
    $permissionManager = new BasicPermissionManager($_SESSION['user']['permission'] ?? 'user');
}

// Verifica permissão básica
$permissionManager->requirePermission('financeiro', 'read');

// Include de auditoria com verificação
if (file_exists('includes/audit.php')) {
    include('includes/audit.php');
    error_log("LicitaSis - Sistema de auditoria carregado");
} else {
    error_log("LicitaSis - Arquivo audit.php não encontrado, criando funções básicas");
}

// Define funções de auditoria básicas se não existirem
if (!function_exists('logAudit')) {
    function logAudit($pdo, $userId, $action, $table, $recordId, $newData = null, $oldData = null) {
        try {
            error_log("LicitaSis - Auditoria: $action em $table (ID: $recordId) por usuário $userId");
            
            // Tenta criar uma entrada de log básica no banco se existir uma tabela de auditoria
            $sql = "INSERT INTO audit_log (user_id, action, table_name, record_id, created_at) 
                    VALUES (:user_id, :action, :table_name, :record_id, NOW())";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                'user_id' => $userId,
                'action' => $action,
                'table_name' => $table,
                'record_id' => $recordId
            ]);
        } catch (Exception $e) {
            // Se falhar, apenas loga no arquivo
            error_log("LicitaSis - Erro ao registrar auditoria no banco: " . $e->getMessage());
        }
        return true;
    }
}

if (!function_exists('logUserAction')) {
    function logUserAction($action, $details = '') {
        $userId = $_SESSION['user']['id'] ?? 'unknown';
        $username = $_SESSION['user']['username'] ?? 'unknown';
        error_log("LicitaSis - Ação do usuário: $username ($userId) - $action - $details");
        return true;
    }
}

// Include de header com verificação
if (file_exists('includes/header_template.php')) {
    include('includes/header_template.php');
    if (function_exists('renderHeader')) {
        renderHeader("Contas a Pagar - LicitaSis", "financeiro");
    }
} else {
    error_log("LicitaSis - Arquivo header_template.php não encontrado");
}

// Log de ação do usuário
logUserAction('READ', 'contas_pagar_consulta');

// Definir a variável $isAdmin com base na permissão do usuário
$isAdmin = false;
if (isset($_SESSION['user']['permission'])) {
    $isAdmin = ($_SESSION['user']['permission'] === 'Administrador');
} else {
    // Fallback: verifica se o usuário tem ID 1 (geralmente admin)
    $isAdmin = (isset($_SESSION['user']['id']) && $_SESSION['user']['id'] == 1);
}

error_log("LicitaSis - Usuário admin: " . ($isAdmin ? 'SIM' : 'NÃO'));

$error = "";
$success = "";
$contas = [];
$searchTerm = "";

// Endpoint para verificar estrutura da tabela
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['check_table_structure'])) {
    if (ob_get_level()) {
        ob_clean();
    }
    header('Content-Type: application/json');
    
    try {
        $tableInfo = [];
        
        // Verifica se a tabela existe
        $result = $pdo->query("SHOW TABLES LIKE 'contas_pagar'");
        $tableInfo['table_exists'] = $result->rowCount() > 0;
        
        if ($tableInfo['table_exists']) {
            // Estrutura das colunas
            $result = $pdo->query("SHOW COLUMNS FROM contas_pagar");
            $tableInfo['columns'] = $result->fetchAll(PDO::FETCH_ASSOC);
            
            // Verifica os constraints
            try {
                $result = $pdo->query("SELECT CONSTRAINT_NAME, CONSTRAINT_TYPE 
                                      FROM information_schema.TABLE_CONSTRAINTS 
                                      WHERE TABLE_NAME = 'contas_pagar' 
                                      AND TABLE_SCHEMA = DATABASE()");
                $tableInfo['constraints'] = $result->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                $tableInfo['constraints'] = [];
            }
            
            // Conta registros
            try {
                $result = $pdo->query("SELECT COUNT(*) as total FROM contas_pagar");
                $tableInfo['record_count'] = $result->fetch(PDO::FETCH_ASSOC)['total'];
            } catch (Exception $e) {
                $tableInfo['record_count'] = 'Erro ao contar';
            }
            
            // Status únicos
            try {
                $result = $pdo->query("SELECT DISTINCT status_pagamento FROM contas_pagar");
                $tableInfo['unique_statuses'] = $result->fetchAll(PDO::FETCH_COLUMN);
            } catch (Exception $e) {
                $tableInfo['unique_statuses'] = [];
            }
        }
        
        echo json_encode($tableInfo, JSON_PRETTY_PRINT);
        
    } catch (Exception $e) {
        echo json_encode(['error' => 'Erro ao verificar estrutura: ' . $e->getMessage()]);
    }
    
    exit();
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['validate_financial_password'])) {
    // Limpa output buffer
    if (ob_get_level()) {
        ob_clean();
    }
    header('Content-Type: application/json');
    
    $senha_inserida = trim($_POST['financial_password'] ?? '');
    $senha_padrao = 'Licitasis@2025'; // Senha padrão do setor financeiro
    
    error_log("LicitaSis - Validação de senha: comprimento recebido = " . strlen($senha_inserida));
    error_log("LicitaSis - Validação de senha: comprimento esperado = " . strlen($senha_padrao));
    
    if ($senha_inserida === $senha_padrao) {
        echo json_encode(['success' => true, 'message' => 'Senha validada com sucesso']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Senha incorreta para o setor financeiro']);
    }
    exit();
} 

// Função para criar a tabela contas_pagar se não existir
function criarTabelaContasPagar($pdo) {
    try {
        // Primeiro, tenta criar a tabela com a estrutura correta
        $sql = "CREATE TABLE IF NOT EXISTS contas_pagar (
            id INT AUTO_INCREMENT PRIMARY KEY,
            compra_id INT NOT NULL,
            status_pagamento VARCHAR(20) DEFAULT 'Pendente',
            data_pagamento DATE NULL,
            observacao_pagamento TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_compra_id (compra_id),
            INDEX idx_status (status_pagamento),
            UNIQUE KEY unique_compra (compra_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $pdo->exec($sql);
        
        // Verifica se a foreign key pode ser criada (se a tabela compras existe)
        try {
            $result = $pdo->query("SHOW TABLES LIKE 'compras'");
            if ($result->rowCount() > 0) {
                // Tenta adicionar a foreign key se ela não existir
                $pdo->exec("ALTER TABLE contas_pagar 
                           ADD CONSTRAINT fk_contas_pagar_compra 
                           FOREIGN KEY (compra_id) REFERENCES compras(id) ON DELETE CASCADE");
                error_log("LicitaSis - Foreign key adicionada com sucesso");
            } else {
                error_log("LicitaSis - Tabela 'compras' não encontrada, foreign key não criada");
            }
        } catch (Exception $fkError) {
            // Foreign key pode já existir ou tabela compras pode não existir
            error_log("LicitaSis - Foreign key não criada: " . $fkError->getMessage());
        }
        
        // Adiciona constraint para validar status
        try {
            $pdo->exec("ALTER TABLE contas_pagar 
                       ADD CONSTRAINT chk_status_pagamento 
                       CHECK (status_pagamento IN ('Pendente', 'Pago', 'Concluido'))");
            error_log("LicitaSis - Constraint de status adicionada");
        } catch (Exception $constraintError) {
            // Constraint pode já existir
            error_log("LicitaSis - Constraint de status já existe ou não pôde ser criada: " . $constraintError->getMessage());
        }
        
        error_log("LicitaSis - Tabela contas_pagar verificada/criada com sucesso");
        return true;
        
    } catch (Exception $e) {
        error_log("LicitaSis - Erro ao criar/verificar tabela contas_pagar: " . $e->getMessage());
        
        // Tenta uma versão mais simples da tabela
        try {
            $simpleSql = "CREATE TABLE IF NOT EXISTS contas_pagar (
                id INT AUTO_INCREMENT PRIMARY KEY,
                compra_id INT NOT NULL,
                status_pagamento VARCHAR(20) DEFAULT 'Pendente',
                data_pagamento DATE NULL,
                observacao_pagamento TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )";
            $pdo->exec($simpleSql);
            error_log("LicitaSis - Tabela contas_pagar criada com estrutura simplificada");
            return true;
        } catch (Exception $e2) {
            error_log("LicitaSis - Erro crítico ao criar tabela: " . $e2->getMessage());
            return false;
        }
    }
}

// Função para sincronizar compras com contas a pagar
function sincronizarContasPagar($pdo) {
    try {
        // Primeiro verifica se a tabela compras existe
        $result = $pdo->query("SHOW TABLES LIKE 'compras'");
        if ($result->rowCount() == 0) {
            error_log("LicitaSis - Tabela 'compras' não encontrada, pulando sincronização");
            return false;
        }
        
        // Insere compras que não estão em contas_pagar
        $sql = "INSERT IGNORE INTO contas_pagar (compra_id, status_pagamento)
                SELECT id, 'Pendente' FROM compras
                WHERE id NOT IN (SELECT compra_id FROM contas_pagar)";
        
        $pdo->exec($sql);
        error_log("LicitaSis - Sincronização de contas a pagar realizada com sucesso");
        return true;
    } catch (Exception $e) {
        error_log("LicitaSis - Erro na sincronização de contas a pagar: " . $e->getMessage());
        return false;
    }
}

// Função para verificar e corrigir estrutura da tabela
function verificarEstruturaTabelaContasPagar($pdo) {
    try {
        // Verifica se a tabela existe
        $result = $pdo->query("SHOW TABLES LIKE 'contas_pagar'");
        if ($result->rowCount() == 0) {
            error_log("LicitaSis - Tabela contas_pagar não existe, será criada");
            return false;
        }
        
        // Verifica a estrutura da coluna status_pagamento
        $result = $pdo->query("SHOW COLUMNS FROM contas_pagar LIKE 'status_pagamento'");
        $column = $result->fetch(PDO::FETCH_ASSOC);
        
        if ($column) {
            $columnType = $column['Type'];
            error_log("LicitaSis - Tipo atual da coluna status_pagamento: $columnType");
            
            // Se for ENUM e estiver causando problemas, converte para VARCHAR
            if (strpos($columnType, 'enum') !== false) {
                error_log("LicitaSis - Convertendo coluna status_pagamento de ENUM para VARCHAR");
                $pdo->exec("ALTER TABLE contas_pagar MODIFY COLUMN status_pagamento VARCHAR(20) DEFAULT 'Pendente'");
                error_log("LicitaSis - Conversão realizada com sucesso");
            }
        }
        
        return true;
        
    } catch (Exception $e) {
        error_log("LicitaSis - Erro ao verificar estrutura da tabela: " . $e->getMessage());
        return false;
    }
}

// Tenta criar a tabela e corrigir estrutura
$tabelaCriada = criarTabelaContasPagar($pdo);
if ($tabelaCriada) {
    verificarEstruturaTabelaContasPagar($pdo);
    sincronizarContasPagar($pdo);
} else {
    error_log("LicitaSis - Continuando sem criar/sincronizar tabelas devido a erros");
}

// Endpoint AJAX para atualizar status com autenticação
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_status'])) {
    
    // Verifica permissão básica para edição
    $hasEditPermission = false;
    if ($permissionManager && method_exists($permissionManager, 'hasPagePermission')) {
        $hasEditPermission = $permissionManager->hasPagePermission('financeiro', 'edit');
    } else {
        // Fallback: permite edição para administradores
        $hasEditPermission = $isAdmin || (isset($_SESSION['user']['permission']) && $_SESSION['user']['permission'] === 'Administrador');
    }
    
    if (!$hasEditPermission) {
        if (ob_get_level()) {
            ob_clean();
        }
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Permissão negada: usuário não tem acesso de edição']);
        exit();
    }
    
    // Limpa qualquer output anterior que possa interferir no JSON
    if (ob_get_level()) {
        ob_clean();
    }
    
    // Define o content type como JSON
    header('Content-Type: application/json; charset=utf-8');
    
    // Função para retornar JSON e encerrar
    function returnJson($data) {
        // Limpa qualquer output anterior
        if (ob_get_level()) {
            ob_clean();
        }
        
        // Garante que é UTF-8 válido
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        
        if ($json === false) {
            $json = json_encode([
                'success' => false, 
                'error' => 'Erro ao codificar resposta JSON: ' . json_last_error_msg()
            ]);
        }
        
        echo $json;
        
        // Força o envio imediato
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        
        exit();
    }
    
    try {
        $conta_id = intval($_POST['conta_id'] ?? 0);
        $status_raw = $_POST['status_pagamento'] ?? '';
        $data_pagamento = !empty($_POST['data_pagamento']) ? $_POST['data_pagamento'] : null;
        $observacao = !empty($_POST['observacao_pagamento']) ? $_POST['observacao_pagamento'] : null;
        $senha_financeiro = $_POST['financial_password'] ?? '';
        
        // Limpa e valida o status
        $status_raw = trim($status_raw);
        $status_map = [
            'Pendente' => 'Pendente',
            'pendente' => 'Pendente',
            'PENDENTE' => 'Pendente',
            'Pago' => 'Pago',
            'pago' => 'Pago',
            'PAGO' => 'Pago',
            'Concluido' => 'Concluido',
            'Concluído' => 'Concluido',
            'concluido' => 'Concluido',
            'concluído' => 'Concluido',
            'CONCLUIDO' => 'Concluido',
            'CONCLUÍDO' => 'Concluido'
        ];
        
        $status = $status_map[$status_raw] ?? null;
        
        // Log para debug
        error_log("LicitaSis - Status recebido: '$status_raw', Status mapeado: '$status'");
        error_log("LicitaSis - Tentativa de atualização: ID=$conta_id, Status=$status, Senha fornecida: " . (!empty($senha_financeiro) ? 'SIM' : 'NÃO'));
        
        if ($conta_id <= 0 || !$status || !in_array($status, ['Pendente', 'Pago', 'Concluido'])) {
            error_log("LicitaSis - Dados inválidos: conta_id=$conta_id, status_raw='$status_raw', status_final='$status'");
            returnJson(['success' => false, 'error' => "Dados inválidos: Status '$status_raw' não é válido. Use: Pendente, Pago ou Concluido"]);
        }
        
        // Validação adicional antes da atualização
        if (strlen($status) > 20) {
            error_log("LicitaSis - Status muito longo: '$status' (length: " . strlen($status) . ")");
            returnJson(['success' => false, 'error' => 'Status de pagamento inválido: muito longo']);
        }
        
        if ($data_pagamento && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data_pagamento)) {
            error_log("LicitaSis - Data inválida: '$data_pagamento'");
            $data_pagamento = date('Y-m-d'); // Usa data atual como fallback
            error_log("LicitaSis - Usando data atual como fallback: '$data_pagamento'");
        }
        
        if ($observacao && strlen($observacao) > 65535) {
            $observacao = substr($observacao, 0, 65535);
            error_log("LicitaSis - Observação truncada para 65535 caracteres");
        }
        
        // Se está alterando de Pendente para Pago/Concluido, valida a senha do setor financeiro
        if (($status === 'Pago' || $status === 'Concluido')) {
            $senha_padrao = 'Licitasis@2025';
            
            if (empty($senha_financeiro)) {
                error_log("LicitaSis - Erro: Senha não fornecida para status: $status");
                returnJson(['success' => false, 'error' => 'Senha do setor financeiro é obrigatória para este status']);
            }
            
            // Remove espaços em branco da senha recebida
            $senha_financeiro = trim($senha_financeiro);
            
            if ($senha_financeiro !== $senha_padrao) {
                error_log("LicitaSis - Erro: Senha incorreta. Esperada: '$senha_padrao', Recebida: '$senha_financeiro'");
                error_log("LicitaSis - Debug: Comprimento esperado: " . strlen($senha_padrao) . ", Comprimento recebido: " . strlen($senha_financeiro));
                returnJson([
                    'success' => false, 
                    'error' => 'Senha do setor financeiro incorreta. A senha correta é: Licitasis@2025 (case-sensitive)'
                ]);
            }
            
            error_log("LicitaSis - Senha validada com sucesso para status: $status");
        }

        // Busca dados antigos para auditoria
        $oldDataStmt = $pdo->prepare("SELECT * FROM contas_pagar WHERE id = :id");
        $oldDataStmt->bindParam(':id', $conta_id, PDO::PARAM_INT);
        $oldDataStmt->execute();
        $oldData = $oldDataStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$oldData) {
            error_log("LicitaSis - Erro: Conta não encontrada com ID: $conta_id");
            returnJson(['success' => false, 'error' => 'Conta não encontrada']);
        }
        
        // Para status Pendente, limpa a data de pagamento
        if ($status === 'Pendente') {
            $data_pagamento = null;
        } else if (empty($data_pagamento)) {
            // Para outros status, usa a data atual se não fornecida
            $data_pagamento = date('Y-m-d');
        }
        
        // Atualiza o registro com preparação cuidadosa
        $sql = "UPDATE contas_pagar SET 
                status_pagamento = :status, 
                data_pagamento = :data_pagamento, 
                observacao_pagamento = :observacao,
                updated_at = CURRENT_TIMESTAMP
                WHERE id = :id";
        
        $stmt = $pdo->prepare($sql);
        
        // Bind dos parâmetros com tipos específicos
        $stmt->bindValue(':status', $status, PDO::PARAM_STR);
        $stmt->bindValue(':data_pagamento', $data_pagamento, PDO::PARAM_STR);
        $stmt->bindValue(':observacao', $observacao, PDO::PARAM_STR);
        $stmt->bindValue(':id', $conta_id, PDO::PARAM_INT);
        
        // Log dos valores antes da execução
        error_log("LicitaSis - Executando UPDATE com valores:");
        error_log("  - ID: $conta_id");
        error_log("  - Status: '$status' (length: " . strlen($status) . ")");
        error_log("  - Data pagamento: " . ($data_pagamento ?: 'NULL'));
        error_log("  - Observação: " . ($observacao ? substr($observacao, 0, 50) . '...' : 'NULL'));
        
        if (!$stmt->execute()) {
            $errorInfo = $stmt->errorInfo();
            error_log("LicitaSis - Erro SQL detalhado:");
            error_log("  - SQLSTATE: " . $errorInfo[0]);
            error_log("  - Código: " . $errorInfo[1]);
            error_log("  - Mensagem: " . $errorInfo[2]);
            
            // Tenta diagnosticar o problema específico
            if (strpos($errorInfo[2], 'truncated') !== false) {
                error_log("LicitaSis - DIAGNÓSTICO: Problema de truncamento de dados");
                error_log("  - Verifique se o valor do status está correto: '$status'");
                error_log("  - Verificando estrutura da tabela...");
                
                try {
                    $checkStmt = $pdo->query("SHOW COLUMNS FROM contas_pagar LIKE 'status_pagamento'");
                    $column = $checkStmt->fetch(PDO::FETCH_ASSOC);
                    error_log("  - Estrutura atual: " . json_encode($column));
                } catch (Exception $e) {
                    error_log("  - Erro ao verificar estrutura: " . $e->getMessage());
                }
            }
            
            returnJson(['success' => false, 'error' => 'Erro ao executar atualização no banco de dados: ' . $errorInfo[2]]);
        }
        
        error_log("LicitaSis - UPDATE executado com sucesso");
        
        // Busca dados novos para auditoria
        $newDataStmt = $pdo->prepare("SELECT * FROM contas_pagar WHERE id = :id");
        $newDataStmt->bindParam(':id', $conta_id, PDO::PARAM_INT);
        $newDataStmt->execute();
        $newData = $newDataStmt->fetch(PDO::FETCH_ASSOC);
        
        // Registra auditoria se a função existir
        if (function_exists('logAudit')) {
            try {
                $auditInfo = $newData;
                if (($status === 'Pago' || $status === 'Concluido') && !empty($senha_financeiro)) {
                    $auditInfo['financial_auth'] = 'Autorizado com senha do setor financeiro';
                    $auditInfo['authorized_by'] = $_SESSION['user']['id'] ?? 'unknown';
                    $auditInfo['authorization_time'] = date('Y-m-d H:i:s');
                }
                
                logAudit($pdo, $_SESSION['user']['id'] ?? 0, 'UPDATE', 'contas_pagar', $conta_id, $auditInfo, $oldData);
                error_log("LicitaSis - Auditoria registrada com sucesso");
            } catch (Exception $e) {
                error_log("LicitaSis - Erro ao registrar auditoria: " . $e->getMessage());
                // Não falha a operação por causa da auditoria
            }
        } else {
            error_log("LicitaSis - Função logAudit não encontrada, pulando auditoria");
        }
        
        error_log("LicitaSis - Status atualizado com sucesso: ID=$conta_id, Novo Status=$status");
        returnJson([
            'success' => true, 
            'message' => 'Status de pagamento atualizado com sucesso!',
            'new_status' => $status,
            'data_pagamento' => $data_pagamento,
            'updated_at' => date('Y-m-d H:i:s')
        ]);
        
    } catch (PDOException $e) {
        error_log("LicitaSis - Erro PDO: " . $e->getMessage());
        returnJson(['success' => false, 'error' => "Erro no banco de dados: " . $e->getMessage()]);
    } catch (Exception $e) {
        error_log("LicitaSis - Erro geral: " . $e->getMessage());
        returnJson(['success' => false, 'error' => "Erro no servidor: " . $e->getMessage()]);
    }
    
    // Se chegou até aqui sem retornar, é um erro
    returnJson(['success' => false, 'error' => 'Erro desconhecido no processamento']);
}

// Função para buscar contas com verificação robusta
function buscarContas($pdo, $searchTerm = '') {
    $contas = [];
    
    try {
        // Verifica se as tabelas necessárias existem
        $tabelas = ['contas_pagar', 'compras'];
        foreach ($tabelas as $tabela) {
            $result = $pdo->query("SHOW TABLES LIKE '$tabela'");
            if ($result->rowCount() == 0) {
                error_log("LicitaSis - Tabela '$tabela' não encontrada para busca de contas");
                return $contas;
            }
        }
        
        if (!empty($searchTerm)) {
            // Consulta com filtro de pesquisa
            $sql = "SELECT cp.*, c.fornecedor, c.numero_nf, c.valor_total, c.data, c.numero_empenho, 
                           c.link_pagamento, c.comprovante_pagamento, c.observacao as observacao_compra, c.frete
                    FROM contas_pagar cp 
                    INNER JOIN compras c ON cp.compra_id = c.id 
                    WHERE c.numero_nf LIKE :searchTerm 
                       OR c.fornecedor LIKE :searchTerm 
                       OR cp.status_pagamento LIKE :searchTerm
                    ORDER BY c.data DESC, cp.status_pagamento ASC";
            
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':searchTerm', "%$searchTerm%");
            $stmt->execute();
            $contas = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            error_log("LicitaSis - Busca realizada com termo: '$searchTerm', resultados: " . count($contas));
        } else {
            // Consulta para mostrar todas as contas a pagar
            $sql = "SELECT cp.*, c.fornecedor, c.numero_nf, c.valor_total, c.data, c.numero_empenho,
                           c.link_pagamento, c.comprovante_pagamento, c.observacao as observacao_compra, c.frete
                    FROM contas_pagar cp
                    INNER JOIN compras c ON cp.compra_id = c.id
                    ORDER BY c.data DESC, cp.status_pagamento ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute();
            $contas = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            error_log("LicitaSis - Busca geral realizada, total de contas: " . count($contas));
        }
        
    } catch (PDOException $e) {
        error_log("LicitaSis - Erro ao buscar contas: " . $e->getMessage());
        $contas = [];
    }
    
    return $contas;
}

// Busca as contas
$searchTerm = isset($_GET['search']) && !empty($_GET['search']) ? $_GET['search'] : '';
$contas = buscarContas($pdo, $searchTerm);

// Função para buscar os detalhes da conta e seus produtos
if (isset($_GET['get_conta_id'])) {
    $conta_id = $_GET['get_conta_id'];
    
    // Limpa output e define content type
    if (ob_get_level()) {
        ob_clean();
    }
    header('Content-Type: application/json');
    
    try {
        // Verifica se as tabelas necessárias existem
        $result = $pdo->query("SHOW TABLES LIKE 'contas_pagar'");
        if ($result->rowCount() == 0) {
            echo json_encode(['error' => 'Tabela de contas a pagar não encontrada']);
            exit();
        }
        
        // Busca os dados da conta a pagar e da compra
        $sql = "SELECT cp.*, c.fornecedor, c.numero_nf, c.valor_total, c.data, c.numero_empenho,
                       c.link_pagamento, c.comprovante_pagamento, c.observacao as observacao_compra, c.frete
                FROM contas_pagar cp
                INNER JOIN compras c ON cp.compra_id = c.id
                WHERE cp.id = :conta_id";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':conta_id', $conta_id, PDO::PARAM_INT);
        $stmt->execute();
        $conta = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$conta) {
            echo json_encode(['error' => 'Conta não encontrada']);
            exit();
        }
        
        // Verifica se a tabela produto_compra existe
        $tabela_existe = false;
        try {
            $pdo->query("SELECT 1 FROM produto_compra LIMIT 1");
            $tabela_existe = true;
        } catch (Exception $e) {
            $tabela_existe = false;
            error_log("LicitaSis - Tabela produto_compra não encontrada: " . $e->getMessage());
        }
        
        // Busca os produtos relacionados à compra se a tabela existir
        $produtos = [];
        if ($tabela_existe) {
            try {
                $sql_produtos = "SELECT pc.*, p.nome as produto_nome 
                                FROM produto_compra pc 
                                LEFT JOIN produtos p ON pc.produto_id = p.id 
                                WHERE pc.compra_id = :compra_id";
                $stmt_produtos = $pdo->prepare($sql_produtos);
                $stmt_produtos->bindValue(':compra_id', $conta['compra_id'], PDO::PARAM_INT);
                $stmt_produtos->execute();
                $produtos = $stmt_produtos->fetchAll(PDO::FETCH_ASSOC);
                
                error_log("LicitaSis - Produtos encontrados para compra {$conta['compra_id']}: " . count($produtos));
            } catch (Exception $e) {
                error_log("LicitaSis - Erro ao buscar produtos: " . $e->getMessage());
                $produtos = [];
            }
        }
        
        // Retorna os dados da conta e seus produtos como JSON
        echo json_encode(['conta' => $conta, 'produtos' => $produtos], JSON_UNESCAPED_UNICODE);
        exit();
        
    } catch (PDOException $e) {
        error_log("LicitaSis - Erro ao buscar detalhes da conta: " . $e->getMessage());
        echo json_encode(['error' => "Erro ao buscar detalhes da conta: " . $e->getMessage()]);
        exit();
    } catch (Exception $e) {
        error_log("LicitaSis - Erro geral ao buscar conta: " . $e->getMessage());
        echo json_encode(['error' => "Erro no servidor: " . $e->getMessage()]);
        exit();
    }
}

// Função para calcular totais com verificação de tabelas
function calcularTotais($pdo) {
    $totais = [
        'total_geral' => 0,
        'total_pendente' => 0,
        'total_pago' => 0
    ];
    
    try {
        // Verifica se as tabelas necessárias existem
        $tabelas = ['contas_pagar', 'compras'];
        foreach ($tabelas as $tabela) {
            $result = $pdo->query("SHOW TABLES LIKE '$tabela'");
            if ($result->rowCount() == 0) {
                error_log("LicitaSis - Tabela '$tabela' não encontrada para cálculo de totais");
                return $totais;
            }
        }
        
        // Total geral de contas a pagar
        $sqlTotalGeral = "SELECT SUM(c.valor_total) AS total_geral
                          FROM contas_pagar cp
                          INNER JOIN compras c ON cp.compra_id = c.id";
        $stmtTotalGeral = $pdo->prepare($sqlTotalGeral);
        $stmtTotalGeral->execute();
        $result = $stmtTotalGeral->fetch(PDO::FETCH_ASSOC);
        $totais['total_geral'] = $result['total_geral'] ?? 0;
        
        // Total de contas pendentes
        $sqlTotalPendente = "SELECT SUM(c.valor_total) AS total_pendente 
                             FROM contas_pagar cp 
                             INNER JOIN compras c ON cp.compra_id = c.id 
                             WHERE cp.status_pagamento = 'Pendente'";
        $stmtTotalPendente = $pdo->prepare($sqlTotalPendente);
        $stmtTotalPendente->execute();
        $result = $stmtTotalPendente->fetch(PDO::FETCH_ASSOC);
        $totais['total_pendente'] = $result['total_pendente'] ?? 0;

        // Total de contas pagas
        $sqlTotalPago = "SELECT SUM(c.valor_total) AS total_pago 
                         FROM contas_pagar cp 
                         INNER JOIN compras c ON cp.compra_id = c.id 
                         WHERE cp.status_pagamento IN ('Pago', 'Concluido')";
        $stmtTotalPago = $pdo->prepare($sqlTotalPago);
        $stmtTotalPago->execute();
        $result = $stmtTotalPago->fetch(PDO::FETCH_ASSOC);
        $totais['total_pago'] = $result['total_pago'] ?? 0;
        
        error_log("LicitaSis - Totais calculados: " . json_encode($totais));
        
    } catch (PDOException $e) {
        error_log("LicitaSis - Erro ao calcular totais: " . $e->getMessage());
    }
    
    return $totais;
}

// Calcula os totais
$totais = calcularTotais($pdo);
$totalGeral = $totais['total_geral'];
$totalPendente = $totais['total_pendente'];
$totalPago = $totais['total_pago'];

// Se foi uma requisição AJAX que chegou até aqui, retorna erro
if (isset($_POST['update_status'])) {
    if (ob_get_level()) {
        ob_clean();
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Requisição AJAX não processada corretamente']);
    exit();
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contas a Pagar - LicitaSis</title>
    <link rel="icon" href="../public_html/assets/images/logo_combraz.png" type="image/png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* ===========================================
           VARIÁVEIS CSS E RESET
           =========================================== */
        :root {
            --primary-color: #2D893E;
            --primary-light: #9DCEAC;
            --primary-dark: #1e6e2d;
            --secondary-color: #00bfae;
            --secondary-dark: #009d8f;
            --danger-color: #dc3545;
            --success-color: #28a745;
            --warning-color: #ffc107;
            --info-color: #17a2b8;
            --light-gray: #f8f9fa;
            --medium-gray: #6c757d;
            --dark-gray: #343a40;
            --border-color: #dee2e6;
            --shadow: 0 4px 12px rgba(0,0,0,0.1);
            --shadow-hover: 0 6px 15px rgba(0,0,0,0.15);
            --radius: 12px;
            --radius-sm: 8px;
            --transition: all 0.3s ease;
            /* Cores específicas para status */
            --pendente-color: #fd7e14;
            --pago-color: #28a745;
            --concluido-color: #17a2b8;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html, body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
            color: var(--dark-gray);
            line-height: 1.6;
        }

        /* ===========================================
           HEADER
           =========================================== */
        header {
            background: linear-gradient(135deg, var(--primary-light) 0%, var(--primary-color) 100%);
            padding: 0.5rem 0;
            text-align: center;
            box-shadow: var(--shadow);
        }

        .logo {
            max-width: 140px;
            height: auto;
            transition: var(--transition);
        }

        .logo:hover {
            transform: scale(1.05);
        }

        /* ===========================================
           NAVIGATION
           =========================================== */
        nav {
            background: var(--primary-color);
            padding: 0;
            text-align: center;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }

        nav a {
            color: white;
            padding: 0.75rem 1rem;
            text-decoration: none;
            font-size: 0.95rem;
            font-weight: 500;
            display: inline-block;
            transition: var(--transition);
            border-bottom: 3px solid transparent;
        }

        nav a:hover {
            background: rgba(255,255,255,0.1);
            border-bottom-color: var(--secondary-color);
            transform: translateY(-1px);
        }

        .dropdown {
            display: inline-block;
            position: relative;
        }

        .dropdown-content {
            display: none;
            position: absolute;
            background: var(--primary-color);
            min-width: 200px;
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
            z-index: 1000;
            border-radius: 0 0 var(--radius) var(--radius);
            overflow: hidden;
        }

        .dropdown-content a {
            display: block;
            padding: 0.875rem 1.25rem;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }

        .dropdown-content a:last-child {
            border-bottom: none;
        }

        .dropdown:hover .dropdown-content {
            display: block;
            animation: fadeInDown 0.3s ease;
        }

        @keyframes fadeInDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* ===========================================
           LAYOUT PRINCIPAL
           =========================================== */
        .container {
            max-width: 1400px;
            margin: 2.5rem auto;
            padding: 2.5rem;
            background: white;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            transition: var(--transition);
            animation: fadeIn 0.5s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .container:hover {
            box-shadow: var(--shadow-hover);
            transform: translateY(-2px);
        }

        h2 {
            text-align: center;
            color: var(--primary-color);
            margin-bottom: 2rem;
            font-size: 2rem;
            font-weight: 600;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
        }

        h2::after {
            content: '';
            position: absolute;
            bottom: -0.5rem;
            left: 50%;
            transform: translateX(-50%);
            width: 80px;
            height: 3px;
            background: var(--secondary-color);
            border-radius: 2px;
        }

        h2 i {
            color: var(--secondary-color);
            font-size: 1.8rem;
        }

        /* ===========================================
           ALERTAS E MENSAGENS
           =========================================== */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: var(--radius);
            margin-bottom: 2rem;
            font-weight: 500;
            text-align: center;
            animation: slideInDown 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
        }

        .alert-danger {
            background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
            color: #721c24;
            border: 1px solid #f5c6cb;
            border-left: 4px solid var(--danger-color);
        }

        .alert-success {
            background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
            color: #155724;
            border: 1px solid #c3e6cb;
            border-left: 4px solid var(--success-color);
        }

        @keyframes slideInDown {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        /* ===========================================
           CARDS DE RESUMO
           =========================================== */
        .summary-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .summary-card {
            background: linear-gradient(135deg, white 0%, var(--light-gray) 100%);
            border-radius: var(--radius);
            padding: 2rem;
            text-align: center;
            box-shadow: var(--shadow);
            transition: var(--transition);
            border-left: 4px solid transparent;
            position: relative;
            overflow: hidden;
        }

        .summary-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--secondary-color), var(--primary-color));
            transform: scaleX(0);
            transition: transform 0.3s ease;
        }

        .summary-card:hover {
            transform: translateY(-8px);
            box-shadow: var(--shadow-hover);
        }

        .summary-card:hover::before {
            transform: scaleX(1);
        }

        .summary-card.total {
            border-left-color: var(--info-color);
        }

        .summary-card.pendente {
            border-left-color: var(--warning-color);
        }

        .summary-card.pago {
            border-left-color: var(--success-color);
        }

        .summary-card h4 {
            font-size: 0.95rem;
            color: var(--medium-gray);
            margin-bottom: 1rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 600;
        }

        .summary-card .value {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 0.5rem;
        }

        .summary-card .icon {
            position: absolute;
            top: 1.5rem;
            right: 1.5rem;
            font-size: 2rem;
            opacity: 0.1;
            transition: var(--transition);
        }

        .summary-card:hover .icon {
            opacity: 0.3;
            transform: scale(1.1);
        }

        /* ===========================================
           BARRA DE PESQUISA
           =========================================== */
        .search-container {
            margin-bottom: 2rem;
            padding: 1.5rem;
            background: linear-gradient(135deg, var(--light-gray) 0%, #e9ecef 100%);
            border-radius: var(--radius);
            border: 1px solid var(--border-color);
        }

        .search-bar {
            display: flex;
            gap: 1rem;
            align-items: end;
        }

        .search-group {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .search-group label {
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--medium-gray);
            text-transform: uppercase;
        }

        .search-bar input {
            flex: 1;
            padding: 0.875rem 1rem;
            border: 2px solid var(--border-color);
            border-radius: var(--radius-sm);
            font-size: 1rem;
            transition: var(--transition);
            background: white;
        }

        .search-bar input:focus {
            outline: none;
            border-color: var(--secondary-color);
            box-shadow: 0 0 0 3px rgba(0, 191, 174, 0.1);
            transform: translateY(-1px);
        }

        .search-bar button {
            padding: 0.875rem 1.5rem;
            background: linear-gradient(135deg, var(--secondary-color) 0%, var(--secondary-dark) 100%);
            color: white;
            border: none;
            border-radius: var(--radius-sm);
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .search-bar button:hover {
            background: linear-gradient(135deg, var(--secondary-dark) 0%, var(--secondary-color) 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(0, 191, 174, 0.3);
        }

        /* ===========================================
           TABELA
           =========================================== */
        .table-container {
            overflow-x: auto;
            margin-bottom: 2rem;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            background: white;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: var(--radius);
            overflow: hidden;
        }

        table th, 
        table td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
            font-size: 0.95rem;
        }

        table th {
            background: linear-gradient(135deg, var(--secondary-color) 0%, var(--secondary-dark) 100%);
            color: white;
            font-weight: 600;
            position: sticky;
            top: 0;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-size: 0.85rem;
        }

        table th i {
            margin-right: 0.5rem;
        }

        table tbody tr {
            transition: var(--transition);
        }

        table tbody tr:hover {
            background: linear-gradient(135deg, var(--light-gray) 0%, #f1f3f4 100%);
            transform: scale(1.01);
        }

        table tbody tr:nth-child(even) {
            background: rgba(248, 249, 250, 0.5);
        }

        table tbody tr:nth-child(even):hover {
            background: linear-gradient(135deg, var(--light-gray) 0%, #f1f3f4 100%);
        }

        /* ===========================================
           ELEMENTOS ESPECÍFICOS DA TABELA
           =========================================== */
        .numero-nf {
            cursor: pointer;
            color: var(--secondary-color);
            font-weight: 600;
            transition: var(--transition);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 0.75rem;
            border-radius: var(--radius-sm);
            background: rgba(0, 191, 174, 0.1);
        }

        .numero-nf:hover {
            color: var(--primary-color);
            background: rgba(45, 137, 62, 0.1);
            transform: scale(1.05);
            box-shadow: 0 2px 8px rgba(0, 191, 174, 0.2);
        }

        .numero-nf i {
            font-size: 0.8rem;
        }

        /* ===========================================
           STATUS BADGES E SELECTS
           =========================================== */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-badge.pendente {
            background: rgba(253, 126, 20, 0.1);
            color: var(--pendente-color);
            border: 1px solid var(--pendente-color);
        }

        .status-badge.pago {
            background: rgba(40, 167, 69, 0.1);
            color: var(--pago-color);
            border: 1px solid var(--pago-color);
        }

        .status-badge.concluido {
            background: rgba(23, 162, 184, 0.1);
            color: var(--concluido-color);
            border: 1px solid var(--concluido-color);
        }

        .status-select {
            padding: 0.5rem 0.75rem;
            border-radius: var(--radius-sm);
            border: 2px solid var(--border-color);
            font-size: 0.9rem;
            cursor: pointer;
            transition: var(--transition);
            background-color: #f9f9f9;
            font-weight: 500;
            min-width: 120px;
        }

        .status-select:hover, .status-select:focus {
            border-color: var(--primary-color);
            background-color: white;
            outline: none;
            box-shadow: 0 0 0 3px rgba(45, 137, 62, 0.1);
        }

        .status-select.status-pendente {
            background: rgba(253, 126, 20, 0.1);
            color: var(--pendente-color);
            border-color: var(--pendente-color);
        }

        .status-select.status-pago {
            background: rgba(40, 167, 69, 0.1);
            color: var(--pago-color);
            border-color: var(--pago-color);
        }

        .status-select.status-concluido {
            background: rgba(23, 162, 184, 0.1);
            color: var(--concluido-color);
            border-color: var(--concluido-color);
        }

        /* ===========================================
           MODAL
           =========================================== */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            animation: fadeIn 0.3s ease;
            overflow-y: auto;
        }

        .modal-content {
            background-color: white;
            margin: 2% auto;
            padding: 0;
            border-radius: var(--radius);
            width: 95%;
            max-width: 1200px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
            animation: slideIn 0.3s ease;
            max-height: 95vh;
            overflow-y: auto;
        }

        @keyframes slideIn {
            from { transform: translateY(-50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .modal-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 1.5rem 2rem;
            border-radius: var(--radius) var(--radius) 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            margin: 0;
            font-size: 1.5rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .close {
            color: white;
            font-size: 2rem;
            font-weight: bold;
            cursor: pointer;
            transition: var(--transition);
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.1);
        }

        .close:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: scale(1.1);
        }

        .modal-body {
            padding: 2rem;
            max-height: 70vh;
            overflow-y: auto;
        }

        .modal-footer {
            padding: 1.5rem 2rem;
            background: var(--light-gray);
            border-radius: 0 0 var(--radius) var(--radius);
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
            flex-wrap: wrap;
        }

        /* ===========================================
           MODAL DE CONFIRMAÇÃO
           =========================================== */
        .confirmation-modal {
            display: none;
            position: fixed;
            z-index: 2000;
            left: 0; 
            top: 0;
            width: 100%; 
            height: 100%;
            background-color: rgba(0, 0, 0, 0.6);
            overflow: auto;
            animation: fadeIn 0.3s ease;
        }

        .confirmation-modal-content {
            background-color: white;
            margin: 10% auto;
            padding: 2rem;
            border-radius: var(--radius);
            box-shadow: var(--shadow-hover);
            width: 90%;
            max-width: 500px;
            position: relative;
            animation: slideInUp 0.3s ease;
            border-top: 5px solid var(--warning-color);
        }

        @keyframes slideInUp {
            from { transform: translateY(50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .confirmation-modal h3 {
            color: var(--warning-color);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .confirmation-info {
            background-color: var(--light-gray);
            padding: 1rem;
            border-radius: var(--radius);
            margin: 1rem 0;
            border-left: 4px solid var(--primary-color);
        }

        .confirmation-info p {
            margin: 0.5rem 0;
            font-size: 0.95rem;
        }

        .confirmation-info strong {
            color: var(--primary-color);
        }

        .confirmation-buttons {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 1.5rem;
        }

        /* ===========================================
           MODAL DE AUTENTICAÇÃO FINANCEIRA
           =========================================== */
        .financial-auth-modal {
            display: none;
            position: fixed;
            z-index: 3000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(8px);
            overflow: auto;
            animation: fadeIn 0.3s ease;
        }

        .financial-auth-content {
            background-color: white;
            margin: 15% auto;
            padding: 0;
            border-radius: var(--radius);
            box-shadow: 0 20px 60px rgba(0,0,0,0.4);
            width: 90%;
            max-width: 450px;
            position: relative;
            animation: slideInUp 0.3s ease;
            overflow: hidden;
            border-top: 5px solid var(--warning-color);
        }

        .financial-auth-header {
            background: linear-gradient(135deg, var(--warning-color), #ff8f00);
            color: var(--dark-gray);
            padding: 1.5rem 2rem;
            text-align: center;
            position: relative;
        }

        .financial-auth-header h3 {
            margin: 0;
            font-size: 1.3rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .financial-auth-header .security-icon {
            font-size: 1.5rem;
            color: var(--dark-gray);
        }

        .financial-auth-body {
            padding: 2rem;
        }

        .security-notice {
            background: linear-gradient(135deg, #fff3cd, #ffeaa7);
            border: 2px solid var(--warning-color);
            border-radius: var(--radius);
            padding: 1rem;
            margin-bottom: 1.5rem;
            text-align: center;
            position: relative;
        }

        .security-notice::before {
            content: '\f3ed';
            font-family: 'Font Awesome 5 Free';
            font-weight: 900;
            position: absolute;
            top: -10px;
            left: 50%;
            transform: translateX(-50%);
            background: var(--warning-color);
            color: var(--dark-gray);
            padding: 0.5rem;
            border-radius: 50%;
            font-size: 1.2rem;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .security-notice h4 {
            color: var(--dark-gray);
            margin: 0.5rem 0;
            font-size: 1rem;
            font-weight: 600;
        }

        .security-notice p {
            color: var(--dark-gray);
            margin: 0;
            font-size: 0.9rem;
            opacity: 0.8;
        }

        .password-input-group {
            position: relative;
            margin-bottom: 1.5rem;
        }

        .password-input-group label {
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 0.5rem;
            display: block;
            font-size: 0.95rem;
        }

        .password-input-wrapper {
            position: relative;
        }

        .password-input {
            width: 100%;
            padding: 1rem 3rem 1rem 1rem;
            border: 2px solid var(--border-color);
            border-radius: var(--radius);
            font-size: 1rem;
            transition: var(--transition);
            background-color: #f9f9f9;
            font-family: monospace;
            letter-spacing: 2px;
        }

        .password-input:focus {
            outline: none;
            border-color: var(--warning-color);
            box-shadow: 0 0 0 3px rgba(255, 193, 7, 0.1);
            background-color: white;
        }

        .password-toggle {
            position: absolute;
            right: 1rem;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--medium-gray);
            cursor: pointer;
            font-size: 1.1rem;
            transition: var(--transition);
        }

        .password-toggle:hover {
            color: var(--primary-color);
        }

        .auth-buttons {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 1.5rem;
        }

        .auth-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
            border-radius: var(--radius);
            padding: 0.75rem 1rem;
            margin-top: 1rem;
            font-size: 0.9rem;
            display: none;
            animation: shake 0.5s ease-in-out;
        }

        @keyframes shake {
            0%, 20%, 40%, 60%, 80% { transform: translateX(0); }
            10%, 30%, 50%, 70%, 90% { transform: translateX(-5px); }
        }

        /* ===========================================
           SEÇÕES DE DETALHES DO MODAL
           =========================================== */
        .conta-details {
            display: grid;
            gap: 2rem;
        }

        .detail-section {
            background: white;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            overflow: hidden;
        }

        .detail-header {
            background: var(--light-gray);
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--border-color);
            font-weight: 600;
            color: var(--primary-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.5rem;
        }

        .detail-content {
            padding: 1.5rem;
        }

        .detail-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
        }

        .detail-item {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .detail-label {
            font-weight: 600;
            color: var(--medium-gray);
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .detail-value {
            color: var(--dark-gray);
            font-size: 1rem;
            font-weight: 500;
        }

        .detail-value.highlight {
            color: var(--primary-color);
            font-weight: 700;
            font-size: 1.1rem;
        }

        .detail-value.money {
            color: var(--success-color);
            font-weight: 700;
            font-size: 1.1rem;
        }

        /* ===========================================
           SEÇÃO DE PRODUTOS NO MODAL
           =========================================== */
        .produtos-section {
            background: linear-gradient(135deg, var(--light-gray) 0%, #e9ecef 100%);
            border: 2px solid var(--border-color);
            border-radius: var(--radius);
            padding: 1.5rem;
            margin: 1.5rem 0;
        }

        .produto-item {
            background: white;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            padding: 1.5rem;
            margin-bottom: 1rem;
            transition: var(--transition);
        }

        .produto-item:hover {
            box-shadow: var(--shadow);
            border-color: var(--secondary-color);
            transform: translateY(-2px);
        }

        .produto-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .produto-title {
            font-weight: 600;
            color: var(--primary-color);
            font-size: 1.1rem;
        }

        /* ===========================================
           COMPROVANTE DE PAGAMENTO
           =========================================== */
        .comprovante-container {
            margin-top: 1rem;
        }

        .comprovante-link {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1rem;
            background: var(--light-gray);
            border-radius: var(--radius-sm);
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 600;
            transition: var(--transition);
            border: 2px solid var(--border-color);
        }

        .comprovante-link:hover {
            background: var(--primary-light);
            color: white;
            border-color: var(--primary-color);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(45, 137, 62, 0.2);
        }

        .comprovante-link i {
            font-size: 1.2rem;
        }

        /* ===========================================
           FORMULÁRIO DE EDIÇÃO NO MODAL
           =========================================== */
        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid var(--border-color);
            border-radius: var(--radius-sm);
            font-size: 0.95rem;
            transition: var(--transition);
            background: white;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--secondary-color);
            box-shadow: 0 0 0 3px rgba(0, 191, 174, 0.1);
        }

        .form-control:disabled {
            background: var(--light-gray);
            color: var(--medium-gray);
            cursor: not-allowed;
        }

        textarea.form-control {
            resize: vertical;
            min-height: 100px;
        }

        select.form-control {
            cursor: pointer;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        label {
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 0.5rem;
            display: block;
            font-size: 0.95rem;
        }

        /* Estados de validação */
        .form-control.is-invalid {
            border-color: var(--danger-color);
            box-shadow: 0 0 0 3px rgba(220, 53, 69, 0.1);
        }

        .form-control.is-valid {
            border-color: var(--success-color);
            box-shadow: 0 0 0 3px rgba(40, 167, 69, 0.1);
        }

        /* ===========================================
           BOTÕES
           =========================================== */
        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: var(--radius-sm);
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
            position: relative;
            overflow: hidden;
        }

        .btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s;
        }

        .btn:hover::before {
            left: 100%;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--secondary-color) 0%, var(--secondary-dark) 100%);
            color: white;
            box-shadow: 0 4px 8px rgba(0, 191, 174, 0.2);
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, var(--secondary-dark) 0%, var(--secondary-color) 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(0, 191, 174, 0.3);
        }

        .btn-success {
            background: linear-gradient(135deg, var(--success-color) 0%, #218838 100%);
            color: white;
            box-shadow: 0 4px 8px rgba(40, 167, 69, 0.2);
        }

        .btn-success:hover {
            background: linear-gradient(135deg, #218838 0%, var(--success-color) 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(40, 167, 69, 0.3);
        }

        .btn-secondary {
            background: linear-gradient(135deg, var(--medium-gray) 0%, #5a6268 100%);
            color: white;
            box-shadow: 0 4px 8px rgba(108, 117, 125, 0.2);
        }

        .btn-secondary:hover {
            background: linear-gradient(135deg, #5a6268 0%, var(--medium-gray) 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(108, 117, 125, 0.3);
        }

        .btn-warning {
            background: linear-gradient(135deg, var(--warning-color) 0%, #e0a800 100%);
            color: var(--dark-gray);
            box-shadow: 0 4px 8px rgba(255, 193, 7, 0.2);
        }

        .btn-warning:hover {
            background: linear-gradient(135deg, #e0a800 0%, var(--warning-color) 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(255, 193, 7, 0.3);
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--danger-color) 0%, #c82333 100%);
            color: white;
            box-shadow: 0 4px 8px rgba(220, 53, 69, 0.2);
        }

        .btn-danger:hover {
            background: linear-gradient(135deg, #c82333 0%, var(--danger-color) 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(220, 53, 69, 0.3);
        }

        .btn-confirm {
            background: var(--success-color);
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: var(--radius);
            cursor: pointer;
            font-weight: 600;
            transition: var(--transition);
        }

        .btn-confirm:hover {
            background: #218838;
            transform: translateY(-1px);
        }

        .btn-cancel {
            background: var(--medium-gray);
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: var(--radius);
            cursor: pointer;
            font-weight: 600;
            transition: var(--transition);
        }

        .btn-cancel:hover {
            background: #5a6268;
            transform: translateY(-1px);
        }

        .btn-auth-confirm {
            background: var(--success-color);
            color: white;
            border: none;
            padding: 0.875rem 1.5rem;
            border-radius: var(--radius);
            cursor: pointer;
            font-weight: 600;
            transition: var(--transition);
            font-size: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-auth-confirm:hover:not(:disabled) {
            background: #218838;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(40, 167, 69, 0.3);
        }

        .btn-auth-confirm:disabled {
            background: var(--medium-gray);
            cursor: not-allowed;
            opacity: 0.6;
        }

        .btn-auth-cancel {
            background: var(--danger-color);
            color: white;
            border: none;
            padding: 0.875rem 1.5rem;
            border-radius: var(--radius);
            cursor: pointer;
            font-weight: 600;
            transition: var(--transition);
            font-size: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-auth-cancel:hover {
            background: #c82333;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(220, 53, 69, 0.3);
        }

        .btn-container {
            display: flex;
            justify-content: center;
            gap: 1rem;
            margin-top: 1.5rem;
            flex-wrap: wrap;
        }

        /* ===========================================
           LOADING E UTILITÁRIOS
           =========================================== */
        .loading {
            opacity: 0.6;
            pointer-events: none;
            position: relative;
        }

        .loading::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 20px;
            height: 20px;
            border: 2px solid var(--border-color);
            border-top-color: var(--secondary-color);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        .loading-spinner {
            display: none;
            width: 20px;
            height: 20px;
            border: 2px solid transparent;
            border-top: 2px solid currentColor;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .no-results {
            text-align: center;
            color: var(--medium-gray);
            font-style: italic;
            padding: 4rem 2rem;
            font-size: 1.1rem;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 1rem;
            background: var(--light-gray);
            border-radius: var(--radius);
            border: 2px dashed var(--border-color);
        }

        .no-results i {
            font-size: 3rem;
            color: var(--secondary-color);
        }

        /* ===========================================
           RESPONSIVIDADE
           =========================================== */
        @media (max-width: 1200px) {
            .container {
                margin: 2rem 1.5rem;
                padding: 2rem;
            }

            .modal-content {
                width: 98%;
                margin: 1% auto;
            }

            .search-bar {
                flex-direction: column;
                gap: 1rem;
            }

            .search-bar > * {
                width: 100%;
            }

            .summary-cards {
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            }
        }

        @media (max-width: 768px) {
            .logo {
                max-width: 120px;
            }

            nav {
                padding: 0.5rem 0;
            }

            nav a {
                padding: 0.75rem 0.5rem;
                font-size: 0.85rem;
                margin: 0 0.25rem;
            }

            .dropdown-content {
                min-width: 160px;
            }

            .container {
                margin: 1.5rem 1rem;
                padding: 1.5rem;
            }

            h2 {
                font-size: 1.75rem;
                flex-direction: column;
                gap: 0.5rem;
            }

            .summary-cards {
                grid-template-columns: repeat(2, 1fr);
                gap: 1rem;
            }

            .form-row {
                grid-template-columns: 1fr;
            }

            .btn-container {
                flex-direction: column;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }

            .modal-content {
                width: 95%;
                margin: 5% auto;
            }

            .modal-header {
                padding: 1rem 1.5rem;
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }

            .modal-body {
                padding: 1.5rem;
            }

            .modal-footer {
                padding: 1rem 1.5rem;
                flex-direction: column;
            }

            table th, table td {
                padding: 0.75rem 0.5rem;
                font-size: 0.85rem;
            }

            .numero-nf {
                padding: 0.25rem 0.5rem;
                font-size: 0.85rem;
            }

            .financial-auth-content {
                margin: 10% auto;
                width: 95%;
            }

            .financial-auth-body {
                padding: 1.5rem;
            }

            .confirmation-buttons, .auth-buttons {
                flex-direction: column;
            }

            .confirmation-buttons button, .auth-buttons button {
                width: 100%;
            }
        }

        @media (max-width: 480px) {
            .logo {
                max-width: 100px;
            }

            nav a {
                padding: 0.625rem 0.375rem;
                font-size: 0.8rem;
                margin: 0 0.125rem;
            }

            .container {
                margin: 1rem 0.5rem;
                padding: 1.25rem;
            }

            h2 {
                font-size: 1.5rem;
            }

            .summary-cards {
                grid-template-columns: 1fr;
            }

            .modal-content {
                width: 100%;
                margin: 0;
                border-radius: 0;
                max-height: 100vh;
            }

            .modal-header {
                border-radius: 0;
            }

            table {
                font-size: 0.8rem;
            }

            table th, table td {
                padding: 0.5rem 0.25rem;
                min-width: 100px;
            }

            .numero-nf {
                font-size: 0.8rem;
                padding: 0.25rem 0.4rem;
            }
        }
    </style>
</head>
<body>

<div class="container">
    <h2>
        <i class="fas fa-credit-card"></i>
        Contas a Pagar
    </h2>

    <!-- ===========================================
         MENSAGENS DE FEEDBACK
         =========================================== -->
    <?php if ($error): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle"></i>
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i>
            <?php echo htmlspecialchars($_GET['success']); ?>
        </div>
    <?php endif; ?>

    <?php 
    // Verifica se o sistema de permissões está funcionando adequadamente
    $permissionSystemStatus = '';
    if ($permissionManager === null) {
        $permissionSystemStatus = 'Sistema de permissões usando modo básico (fallback)';
    } elseif (!method_exists($permissionManager, 'hasPagePermission')) {
        $permissionSystemStatus = 'Sistema de permissões incompleto - algumas funções podem não estar disponíveis';
    }
    
    if (!empty($permissionSystemStatus)): ?>
        <div class="alert" style="background: #fff3cd; color: #856404; border: 1px solid #ffeaa7; border-left: 4px solid #ffc107;">
            <i class="fas fa-info-circle"></i>
            <strong>Informação do Sistema:</strong> <?php echo $permissionSystemStatus; ?>
            <br><small>O sistema continuará funcionando com permissões básicas. Contate o administrador se necessário.</small>
        </div>
    <?php endif; ?>

    <?php 
    // Verifica problemas comuns na estrutura da tabela
    try {
        $result = $pdo->query("SHOW COLUMNS FROM contas_pagar LIKE 'status_pagamento'");
        $statusColumn = $result->fetch(PDO::FETCH_ASSOC);
        
        if ($statusColumn && strpos($statusColumn['Type'], 'enum') !== false): ?>
            <div class="alert" style="background: #ffe6cc; color: #cc6600; border: 1px solid #ffb366; border-left: 4px solid #ff8c00;">
                <i class="fas fa-exclamation-triangle"></i>
                <strong>Atenção - Possível Problema na Estrutura:</strong> 
                A coluna 'status_pagamento' está configurada como ENUM, o que pode causar erros de truncamento.
                <br><small>
                    <strong>Solução recomendada:</strong> Converta para VARCHAR executando: 
                    <code>ALTER TABLE contas_pagar MODIFY COLUMN status_pagamento VARCHAR(20) DEFAULT 'Pendente';</code>
                </small>
                <br><small>
                    Use o botão "Tabela" no modal de autenticação para verificar a estrutura completa.
                </small>
            </div>
        <?php endif;
    } catch (Exception $e) {
        // Ignora erro se não conseguir verificar
    }
    ?>

    <!-- ===========================================
         CARDS DE RESUMO
         =========================================== -->
    <div class="summary-cards">
        <div class="summary-card total">
            <h4>Total Geral</h4>
            <div class="value">R$ <?php echo number_format($totalGeral ?? 0, 2, ',', '.'); ?></div>
            <i class="fas fa-calculator icon"></i>
        </div>
        <div class="summary-card pendente">
            <h4>Contas Pendentes</h4>
            <div class="value">R$ <?php echo number_format($totalPendente ?? 0, 2, ',', '.'); ?></div>
            <i class="fas fa-clock icon"></i>
        </div>
        <div class="summary-card pago">
            <h4>Contas Pagas</h4>
            <div class="value">R$ <?php echo number_format($totalPago ?? 0, 2, ',', '.'); ?></div>
            <i class="fas fa-check-circle icon"></i>
        </div>
    </div>

    <!-- ===========================================
         BARRA DE PESQUISA
         =========================================== -->
    <div class="search-container">
        <form action="contas_a_pagar.php" method="GET">
            <div class="search-bar">
                <div class="search-group">
                    <label for="search">Buscar por:</label>
                    <input type="text" 
                           name="search" 
                           id="search" 
                           placeholder="Número da NF, Fornecedor ou Status..." 
                           value="<?php echo htmlspecialchars($searchTerm); ?>"
                           autocomplete="off">
                </div>
                <button type="submit">
                    <i class="fas fa-search"></i> 
                    Pesquisar
                </button>
            </div>
        </form>
    </div>

    <!-- ===========================================
         TABELA DE CONTAS A PAGAR
         =========================================== -->
    <?php if (count($contas) > 0): ?>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th><i class="fas fa-file-invoice"></i> Número da NF</th>
                        <th><i class="fas fa-building"></i> Fornecedor</th>
                        <th><i class="fas fa-dollar-sign"></i> Valor Total</th>
                        <th><i class="fas fa-calendar"></i> Data da Compra</th>
                        <th><i class="fas fa-tags"></i> Status</th>
                        <th><i class="fas fa-calendar-check"></i> Data de Pagamento</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($contas as $conta): ?>
                        <tr data-id="<?php echo $conta['id']; ?>">
                            <td>
                                <span class="numero-nf" onclick="openModal(<?php echo $conta['id']; ?>)">
                                    <i class="fas fa-eye"></i>
                                    <?php echo htmlspecialchars($conta['numero_nf']); ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($conta['fornecedor']); ?></td>
                            <td>
                                <strong style="color: var(--success-color);">
                                    R$ <?php echo number_format($conta['valor_total'], 2, ',', '.'); ?>
                                </strong>
                            </td>
                            <td><?php echo date('d/m/Y', strtotime($conta['data'])); ?></td>
                            <td>
                                <?php 
                                // Verifica se o usuário tem permissão de edição
                                $canEdit = false;
                                if ($permissionManager && method_exists($permissionManager, 'hasPagePermission')) {
                                    $canEdit = $permissionManager->hasPagePermission('financeiro', 'edit');
                                } else {
                                    // Fallback: permite edição para administradores
                                    $canEdit = $isAdmin || (isset($_SESSION['user']['permission']) && $_SESSION['user']['permission'] === 'Administrador');
                                }
                                
                                if ($canEdit): ?>
                                    <select class="status-select" 
                                            data-id="<?php echo $conta['id']; ?>" 
                                            data-nf="<?php echo htmlspecialchars($conta['numero_nf']); ?>"
                                            data-fornecedor="<?php echo htmlspecialchars($conta['fornecedor']); ?>"
                                            data-valor="<?php echo number_format($conta['valor_total'], 2, ',', '.'); ?>"
                                            data-data="<?php echo date('d/m/Y', strtotime($conta['data'])); ?>"
                                            data-status-atual="<?php echo $conta['status_pagamento']; ?>">
                                        <option value="Pendente" <?php if ($conta['status_pagamento'] === 'Pendente') echo 'selected'; ?>>Pendente</option>
                                        <option value="Pago" <?php if ($conta['status_pagamento'] === 'Pago') echo 'selected'; ?>>Pago</option>
                                        <option value="Concluido" <?php if ($conta['status_pagamento'] === 'Concluido') echo 'selected'; ?>>Concluído</option>
                                    </select>
                                <?php else: ?>
                                    <span class="status-badge <?php echo strtolower($conta['status_pagamento']); ?>">
                                        <?php
                                        $icons = [
                                            'Pendente' => 'clock',
                                            'Pago' => 'check-circle',
                                            'Concluido' => 'check-double'
                                        ];
                                        $icon = $icons[$conta['status_pagamento']] ?? 'tag';
                                        ?>
                                        <i class="fas fa-<?php echo $icon; ?>"></i>
                                        <?php echo $conta['status_pagamento']; ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php 
                                    if ($conta['data_pagamento']) {
                                        echo '<strong style="color: var(--success-color);">' . date('d/m/Y', strtotime($conta['data_pagamento'])) . '</strong>';
                                    } else {
                                        echo '<span style="color: var(--medium-gray);">-</span>';
                                    }
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <!-- ===========================================
             MENSAGEM SEM RESULTADOS
             =========================================== -->
        <div class="no-results">
            <i class="fas fa-search"></i>
            <p>Nenhuma conta a pagar encontrada.</p>
            <small>Tente ajustar os filtros ou verifique se há compras cadastradas.</small>
        </div>
    <?php endif; ?>
</div>

<!-- ===========================================
     MODAL DE DETALHES DA CONTA A PAGAR
     =========================================== -->
<div id="contaModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>
                <i class="fas fa-credit-card"></i> 
                Detalhes da Conta a Pagar
            </h3>
            <span class="close" onclick="closeModal()">&times;</span>
        </div>
        <div class="modal-body">
            <div class="loading-spinner" style="text-align: center; padding: 3rem;">
                <div style="width: 40px; height: 40px; border: 3px solid var(--border-color); border-top: 3px solid var(--secondary-color); border-radius: 50%; animation: spin 1s linear infinite; margin: 0 auto;"></div>
                <p style="margin-top: 1rem; color: var(--medium-gray);">Carregando detalhes da conta...</p>
            </div>
        </div>
        <div class="modal-footer" style="display: none;" id="modalFooter">
            <button class="btn btn-secondary" onclick="closeModal()">
                <i class="fas fa-times"></i> Fechar
            </button>
        </div>
    </div>
</div>

<!-- ===========================================
     MODAL DE CONFIRMAÇÃO
     =========================================== -->
<div id="confirmationModal" class="confirmation-modal" role="dialog" aria-modal="true">
    <div class="confirmation-modal-content">
        <h3><i class="fas fa-exclamation-triangle"></i> Confirmar Alteração de Status</h3>
        <p>Deseja realmente alterar o status desta conta?</p>
        
        <div class="confirmation-info">
            <p><strong>NF:</strong> <span id="confirm-nf"></span></p>
            <p><strong>Fornecedor:</strong> <span id="confirm-fornecedor"></span></p>
            <p><strong>Valor:</strong> R$ <span id="confirm-valor"></span></p>
            <p><strong>Status Atual:</strong> <span id="confirm-status-atual"></span></p>
            <p><strong>Novo Status:</strong> <span id="confirm-novo-status"></span></p>
        </div>
        
        <p style="color: var(--warning-color); font-size: 0.9rem; margin-top: 1rem;" id="auth-warning">
            <i class="fas fa-info-circle"></i> Esta ação requer autenticação do setor financeiro.
        </p>
        
        <div class="confirmation-buttons">
            <button type="button" class="btn-cancel" onclick="closeConfirmationModal()">
                <i class="fas fa-times"></i> Cancelar
            </button>
            <button type="button" class="btn-confirm" onclick="handleStatusConfirmation()">
                <i class="fas fa-check"></i> Confirmar
            </button>
        </div>
    </div>
</div>

<!-- ===========================================
     MODAL DE AUTENTICAÇÃO FINANCEIRA
     =========================================== -->
<div id="financialAuthModal" class="financial-auth-modal" role="dialog" aria-modal="true">
    <div class="financial-auth-content">
        <div class="financial-auth-header">
            <h3>
                <i class="fas fa-shield-alt security-icon"></i>
                Autenticação do Setor Financeiro
            </h3>
        </div>
        <div class="financial-auth-body">
            <div class="security-notice">
                <h4>Autorização Necessária</h4>
                <p>Para alterar o status de pagamento, é necessário inserir a senha do setor financeiro por questões de segurança.</p>
                
            </div>
            
            <div class="password-input-group">
                <label for="financialPassword">
                    <i class="fas fa-key"></i> Senha do Setor Financeiro
                </label>
                <div class="password-input-wrapper">
                    <input type="password" 
                           id="financialPassword" 
                           class="password-input"
                           placeholder="Digite a senha do setor financeiro"
                           autocomplete="off"
                           maxlength="50">
                    <button type="button" class="password-toggle" onclick="togglePasswordVisibility()">
                        <i class="fas fa-eye" id="passwordToggleIcon"></i>
                    </button>
                </div>
            </div>
            
            <div class="auth-error" id="authError">
                <i class="fas fa-exclamation-triangle"></i>
                <span id="authErrorMessage">Senha incorreta. Tente novamente.</span>
            </div>
            
            <div class="auth-buttons">
                <button type="button" class="btn-auth-cancel" onclick="closeFinancialAuthModal()">
                    <i class="fas fa-times"></i> Cancelar
                </button>
                <button type="button" class="btn-auth-confirm" id="confirmAuthBtn" onclick="confirmFinancialAuth()">
                    <span class="loading-spinner" id="authLoadingSpinner"></span>
                    <i class="fas fa-unlock" id="authConfirmIcon"></i>
                    <span id="authConfirmText">Autorizar</span>
                </button>
            </div>
            
            <div style="text-align: center; margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #dee2e6;">
                <button type="button" class="btn" style="font-size: 0.8rem; padding: 0.5rem 1rem; background: #f8f9fa; color: #6c757d; border: 1px solid #dee2e6; margin-right: 0.5rem;" onclick="testPassword()">
                    <i class="fas fa-vial"></i> Testar Senha
                </button>
                <button type="button" class="btn" style="font-size: 0.8rem; padding: 0.5rem 1rem; background: #e3f2fd; color: #1976d2; border: 1px solid #bbdefb; margin-right: 0.5rem;" onclick="showDebugInfo()">
                    <i class="fas fa-bug"></i> Debug
                </button>
                <button type="button" class="btn" style="font-size: 0.8rem; padding: 0.5rem 1rem; background: #fff3e0; color: #f57c00; border: 1px solid #ffcc02;" onclick="checkTableStructure()">
                    <i class="fas fa-database"></i> Tabela
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// ===========================================
// SISTEMA COMPLETO DE CONTAS A PAGAR
// JavaScript Completo - LicitaSis v7.0
// ===========================================

// ===========================================
// VARIÁVEIS GLOBAIS
// ===========================================
let currentContaId = null;
let currentContaData = null;
let currentSelectElement = null;
let pendingStatusChange = null;

// ===========================================
// FUNÇÕES DE CONTROLE DO MODAL
// ===========================================

/**
 * Abre o modal com detalhes da conta a pagar
 * @param {number} contaId - ID da conta
 */
function openModal(contaId) {
    console.log('🔍 Abrindo modal para conta ID:', contaId);
    
    currentContaId = contaId;
    const modal = document.getElementById('contaModal');
    const modalBody = modal.querySelector('.modal-body');
    const modalFooter = document.getElementById('modalFooter');
    
    // Mostra o modal
    modal.style.display = 'block';
    document.body.style.overflow = 'hidden';
    
    // Mostra loading
    modalBody.innerHTML = `
        <div class="loading-spinner" style="text-align: center; padding: 3rem;">
            <div style="width: 40px; height: 40px; border: 3px solid var(--border-color); border-top: 3px solid var(--secondary-color); border-radius: 50%; animation: spin 1s linear infinite; margin: 0 auto;"></div>
            <p style="margin-top: 1rem; color: var(--medium-gray);">Carregando detalhes da conta...</p>
        </div>
    `;
    modalFooter.style.display = 'none';
    
    // Busca dados da conta
    const url = `contas_a_pagar.php?get_conta_id=${contaId}&t=${Date.now()}`;
    console.log('📡 Fazendo requisição para:', url);
    
    fetch(url)
        .then(response => {
            console.log('📡 Resposta recebida:', response.status, response.statusText);
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            return response.json();
        })
        .then(data => {
            console.log('✅ Dados da conta recebidos:', data);
            
            if (data.error) {
                throw new Error(data.error);
            }
            
            currentContaData = data;
            renderContaDetails(data);
            modalFooter.style.display = 'flex';
            
            console.log('✅ Modal renderizado com sucesso para conta:', data.conta.numero_nf);
        })
        .catch(error => {
            console.error('❌ Erro ao carregar conta:', error);
            modalBody.innerHTML = `
                <div style="text-align: center; padding: 3rem; color: var(--danger-color);">
                    <i class="fas fa-exclamation-triangle" style="font-size: 3rem; margin-bottom: 1rem;"></i>
                    <p style="font-size: 1.1rem; margin-bottom: 1rem;">Erro ao carregar conta</p>
                    <p style="color: var(--medium-gray);">${error.message}</p>
                    <button class="btn btn-primary" onclick="openModal(${contaId})" style="margin: 1rem 0.5rem;">
                        <i class="fas fa-redo"></i> Tentar Novamente
                    </button>
                    <button class="btn btn-secondary" onclick="closeModal()">
                        <i class="fas fa-times"></i> Fechar
                    </button>
                </div>
            `;
        });
}

/**
 * Renderiza os detalhes completos da conta no modal
 * @param {Object} data - Dados da conta
 */
function renderContaDetails(data) {
    console.log('🎨 Renderizando detalhes da conta:', data);
    
    const modalBody = document.querySelector('#contaModal .modal-body');
    const conta = data.conta;
    const produtos = data.produtos || [];
    
    // Prepara datas
    const dataCompra = conta.data ? new Date(conta.data).toLocaleDateString('pt-BR') : 'N/A';
    const dataPagamento = conta.data_pagamento ? new Date(conta.data_pagamento).toLocaleDateString('pt-BR') : null;
    
    modalBody.innerHTML = `
        <div class="conta-details">
            <!-- Informações da Compra -->
            <div class="detail-section">
                <div class="detail-header">
                    <i class="fas fa-shopping-cart"></i>
                    Informações da Compra
                </div>
                <div class="detail-content">
                    <div class="detail-grid">
                        <div class="detail-item">
                            <div class="detail-label">Número da NF</div>
                            <div class="detail-value highlight">${conta.numero_nf || 'N/A'}</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Fornecedor</div>
                            <div class="detail-value highlight">${conta.fornecedor || 'N/A'}</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Data da Compra</div>
                            <div class="detail-value">${dataCompra}</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Valor Total</div>
                            <div class="detail-value money">R$ ${parseFloat(conta.valor_total || 0).toLocaleString('pt-BR', {minimumFractionDigits: 2})}</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Número de Empenho</div>
                            <div class="detail-value">${conta.numero_empenho || 'N/A'}</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Frete</div>
                            <div class="detail-value">${conta.frete ? 'R$ ' + parseFloat(conta.frete).toLocaleString('pt-BR', {minimumFractionDigits: 2}) : 'N/A'}</div>
                        </div>
                    </div>
                    
                    ${conta.observacao_compra ? `
                    <div style="margin-top: 1.5rem;">
                        <div class="detail-label">Observação da Compra</div>
                        <div class="detail-value">${conta.observacao_compra}</div>
                    </div>
                    ` : ''}
                    
                    ${conta.link_pagamento ? `
                    <div style="margin-top: 1.5rem;">
                        <div class="detail-label">Link para Pagamento</div>
                        <div class="detail-value">
                            <a href="${conta.link_pagamento}" target="_blank" class="comprovante-link">
                                <i class="fas fa-external-link-alt"></i>
                                Acessar Link de Pagamento
                            </a>
                        </div>
                    </div>
                    ` : ''}
                </div>
            </div>

            <!-- Produtos da Compra -->
            ${produtos.length > 0 ? `
            <div class="detail-section">
                <div class="detail-header">
                    <i class="fas fa-box"></i>
                    Produtos da Compra (${produtos.length})
                </div>
                <div class="detail-content">
                    <div class="produtos-section">
                        ${produtos.map((produto, index) => `
                            <div class="produto-item">
                                <div class="produto-header">
                                    <div class="produto-title">Produto ${index + 1}</div>
                                </div>
                                
                                <div class="detail-grid">
                                    <div class="detail-item">
                                        <div class="detail-label">Nome do Produto</div>
                                        <div class="detail-value">${produto.produto_nome || 'Nome não informado'}</div>
                                    </div>
                                    <div class="detail-item">
                                        <div class="detail-label">Quantidade</div>
                                        <div class="detail-value">${produto.quantidade || 0}</div>
                                    </div>
                                    <div class="detail-item">
                                        <div class="detail-label">Valor Unitário</div>
                                        <div class="detail-value money">R$ ${parseFloat(produto.valor_unitario || 0).toLocaleString('pt-BR', {minimumFractionDigits: 2})}</div>
                                    </div>
                                    <div class="detail-item">
                                        <div class="detail-label">Valor Total</div>
                                        <div class="detail-value money">R$ ${parseFloat(produto.valor_total || 0).toLocaleString('pt-BR', {minimumFractionDigits: 2})}</div>
                                    </div>
                                </div>
                            </div>
                        `).join('')}
                    </div>
                </div>
            </div>
            ` : ''}

            <!-- Informações de Pagamento -->
            <div class="detail-section">
                <div class="detail-header">
                    <i class="fas fa-credit-card"></i>
                    Informações de Pagamento
                </div>
                <div class="detail-content">
                    <div class="detail-grid">
                        <div class="detail-item">
                            <div class="detail-label">Status Atual</div>
                            <div class="detail-value">
                                <span class="status-badge ${conta.status_pagamento.toLowerCase()}">
                                    <i class="fas fa-${getStatusIcon(conta.status_pagamento)}"></i>
                                    ${conta.status_pagamento}
                                </span>
                            </div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Data de Pagamento</div>
                            <div class="detail-value">${dataPagamento || 'Não pago'}</div>
                        </div>
                    </div>
                    
                    ${conta.observacao_pagamento ? `
                    <div style="margin-top: 1.5rem;">
                        <div class="detail-label">Observação do Pagamento</div>
                        <div class="detail-value">${conta.observacao_pagamento}</div>
                    </div>
                    ` : ''}
                    
                    ${conta.comprovante_pagamento ? `
                    <div class="comprovante-container">
                        <div class="detail-label">Comprovante de Pagamento</div>
                        <a href="${conta.comprovante_pagamento}" class="comprovante-link" target="_blank">
                            <i class="fas fa-file-alt"></i>
                            Ver Comprovante
                        </a>
                    </div>
                    ` : ''}
                </div>
            </div>
        </div>
    `;
    
    console.log('✅ Detalhes da conta renderizados com sucesso');
}

/**
 * Fecha o modal
 */
function closeModal() {
    const modal = document.getElementById('contaModal');
    modal.style.display = 'none';
    document.body.style.overflow = 'auto';
    
    // Limpa dados
    currentContaId = null;
    currentContaData = null;
    
    console.log('✅ Modal fechado');
}

/**
 * Obtém ícone para status de pagamento
 */
function getStatusIcon(status) {
    const icons = {
        'Pendente': 'clock',
        'Pago': 'check-circle',
        'Concluido': 'check-double'
    };
    return icons[status] || 'tag';
}

// ===========================================
// SISTEMA DE CONFIRMAÇÃO E AUTENTICAÇÃO
// ===========================================

/**
 * Abre modal de confirmação
 */
function openConfirmationModal(selectElement, novoStatus) {
    currentSelectElement = selectElement;
    
    pendingStatusChange = {
        id: selectElement.dataset.id,
        nf: selectElement.dataset.nf,
        fornecedor: selectElement.dataset.fornecedor,
        valor: selectElement.dataset.valor,
        data: selectElement.dataset.data,
        statusAtual: selectElement.dataset.statusAtual,
        novoStatus: novoStatus
    };

    document.getElementById('confirm-nf').textContent = pendingStatusChange.nf;
    document.getElementById('confirm-fornecedor').textContent = pendingStatusChange.fornecedor;
    document.getElementById('confirm-valor').textContent = pendingStatusChange.valor;
    document.getElementById('confirm-status-atual').textContent = pendingStatusChange.statusAtual;
    document.getElementById('confirm-novo-status').textContent = pendingStatusChange.novoStatus;
    
    // Mostra/esconde aviso de autenticação
    const authWarning = document.getElementById('auth-warning');
    if (novoStatus === 'Pago' || novoStatus === 'Concluido') {
        authWarning.style.display = 'block';
    } else {
        authWarning.style.display = 'none';
    }

    document.getElementById('confirmationModal').style.display = 'block';
    document.body.style.overflow = 'hidden';
}

/**
 * Fecha modal de confirmação
 */
function closeConfirmationModal() {
    if (currentSelectElement) {
        currentSelectElement.value = currentSelectElement.dataset.statusAtual || 'Pendente';
        updateSelectStyle(currentSelectElement);
    }
    
    document.getElementById('confirmationModal').style.display = 'none';
    document.body.style.overflow = 'auto';
    currentSelectElement = null;
    pendingStatusChange = null;
}

/**
 * Processa confirmação de mudança de status
 */
function handleStatusConfirmation() {
    if (!pendingStatusChange) return;
    
    const novoStatus = pendingStatusChange.novoStatus;
    
    // Se está mudando para Pago ou Concluído, requer autenticação
    if (novoStatus === 'Pago' || novoStatus === 'Concluido') {
        document.getElementById('confirmationModal').style.display = 'none';
        document.getElementById('financialAuthModal').style.display = 'block';
        
        // Foca no campo de senha
        setTimeout(() => {
            document.getElementById('financialPassword').focus();
        }, 300);
    } else {
        // Para mudança para Pendente, não precisa de autenticação
        updateStatusDirect(pendingStatusChange.id, novoStatus);
    }
}

/**
 * Fecha modal de autenticação financeira
 */
function closeFinancialAuthModal() {
    document.getElementById('financialAuthModal').style.display = 'none';
    document.getElementById('financialPassword').value = '';
    document.getElementById('authError').style.display = 'none';
    document.body.style.overflow = 'auto';
    
    // Reseta o select para o valor original
    if (currentSelectElement) {
        currentSelectElement.value = currentSelectElement.dataset.statusAtual || 'Pendente';
        updateSelectStyle(currentSelectElement);
    }
    
    currentSelectElement = null;
    pendingStatusChange = null;
}

/**
 * Alterna visibilidade da senha
 */
function togglePasswordVisibility() {
    const passwordInput = document.getElementById('financialPassword');
    const toggleIcon = document.getElementById('passwordToggleIcon');
    
    if (passwordInput.type === 'password') {
        passwordInput.type = 'text';
        toggleIcon.className = 'fas fa-eye-slash';
    } else {
        passwordInput.type = 'password';
        toggleIcon.className = 'fas fa-eye';
    }
}

/**
 * Verifica estrutura da tabela contas_pagar
 */
function checkTableStructure() {
    console.log('🔍 Verificando estrutura da tabela...');
    
    const formData = new FormData();
    formData.append('check_table_structure', '1');
    
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        console.log('📊 === ESTRUTURA DA TABELA ===');
        console.log('Tabela existe:', data.table_exists);
        
        if (data.table_exists) {
            console.log('📋 Colunas da tabela:');
            data.columns.forEach(col => {
                console.log(`  - ${col.Field}: ${col.Type} (Default: ${col.Default}, Null: ${col.Null})`);
            });
            
            console.log('🔒 Constraints:');
            data.constraints.forEach(constraint => {
                console.log(`  - ${constraint.CONSTRAINT_NAME}: ${constraint.CONSTRAINT_TYPE}`);
            });
            
            console.log('📈 Total de registros:', data.record_count);
            console.log('🏷️ Status únicos encontrados:', data.unique_statuses);
            
            // Verifica problemas comuns
            const statusColumn = data.columns.find(col => col.Field === 'status_pagamento');
            if (statusColumn) {
                console.log('⚠️ Análise da coluna status_pagamento:');
                console.log('  - Tipo:', statusColumn.Type);
                console.log('  - Permite NULL:', statusColumn.Null);
                console.log('  - Valor padrão:', statusColumn.Default);
                
                if (statusColumn.Type.includes('enum')) {
                    console.log('  ⚠️ ATENÇÃO: Coluna é ENUM - pode causar problemas de truncamento');
                    console.log('  💡 SOLUÇÃO: Considere converter para VARCHAR(20)');
                }
            }
        }
        
        alert('📊 Estrutura da tabela verificada! Veja os detalhes no console (F12).');
    })
    .catch(error => {
        console.error('❌ Erro ao verificar estrutura:', error);
        alert('❌ Erro ao verificar estrutura da tabela. Veja o console para detalhes.');
    });
}

/**
 * Mostra informações de debug
 */
function showDebugInfo() {
    console.log('🐛 === DEBUG INFO ===');
    console.log('📍 URL atual:', window.location.href);
    console.log('🔐 Dados de sessão disponíveis:', !!window.sessionStorage);
    console.log('📋 Pending status change:', pendingStatusChange);
    
    if (pendingStatusChange) {
        console.log('📊 Detalhes da mudança pendente:');
        console.log('  - ID:', pendingStatusChange.id);
        console.log('  - Status atual:', pendingStatusChange.statusAtual);
        console.log('  - Novo status:', pendingStatusChange.novoStatus);
        console.log('  - NF:', pendingStatusChange.nf);
    }
    
    const senha = document.getElementById('financialPassword').value;
    console.log('🔑 Senha inserida (length):', senha.length);
    console.log('🔑 Senha correta esperada:', 'Licitasis@2025');
    console.log('🔑 Senha correta (length):', 'Licitasis@2025'.length);
    
    // Teste de envio de dados
    if (pendingStatusChange) {
        console.log('🧪 Testando dados que serão enviados:');
        const formData = new FormData();
        formData.append('update_status', '1');
        formData.append('conta_id', pendingStatusChange.id);
        formData.append('status_pagamento', pendingStatusChange.novoStatus);
        formData.append('data_pagamento', new Date().toISOString().split('T')[0]);
        formData.append('observacao_pagamento', 'Teste de debug');
        formData.append('financial_password', senha);
        
        console.log('📦 FormData que seria enviado:');
        for (let [key, value] of formData.entries()) {
            console.log(`  - ${key}: "${value}" (${typeof value}, length: ${value.length})`);
        }
    }
    
    alert('ℹ️ Informações de debug enviadas para o console. Pressione F12 para ver os logs detalhados.');
}

/**
 * Testa a senha do setor financeiro
 */
function testPassword() {
    const senha = document.getElementById('financialPassword').value.trim();
    const senhaCorreta = 'Licitasis@2025';
    
    if (!senha) {
        showAuthError('Digite a senha para testar.');
        return;
    }
    
    console.log('🧪 Testando senha...');
    console.log('Senha digitada:', `"${senha}"`);
    console.log('Senha esperada:', `"${senhaCorreta}"`);
    console.log('Comprimento digitado:', senha.length);
    console.log('Comprimento esperado:', senhaCorreta.length);
    console.log('São iguais?', senha === senhaCorreta);
    
    if (senha === senhaCorreta) {
        const authError = document.getElementById('authError');
        authError.style.background = '#d4edda';
        authError.style.color = '#155724';
        authError.style.border = '1px solid #c3e6cb';
        document.getElementById('authErrorMessage').innerHTML = '<i class="fas fa-check-circle"></i> Senha correta! Pode prosseguir com a autorização.';
        authError.style.display = 'block';
        
        setTimeout(() => {
            authError.style.display = 'none';
        }, 3000);
    } else {
        showAuthError(`Senha incorreta. Verifique: deve ser exatamente "Licitasis@2025"`);
    }
}

/**
 * Confirma autenticação financeira
 */
function confirmFinancialAuth() {
    const senha = document.getElementById('financialPassword').value.trim();
    const confirmBtn = document.getElementById('confirmAuthBtn');
    const loadingSpinner = document.getElementById('authLoadingSpinner');
    const confirmIcon = document.getElementById('authConfirmIcon');
    const confirmText = document.getElementById('authConfirmText');
    const authError = document.getElementById('authError');
    
    console.log('🔐 Tentativa de autenticação financeira');
    
    if (!senha) {
        showAuthError('Por favor, digite a senha do setor financeiro.');
        return;
    }
    
    // Verifica se a senha está correta localmente primeiro
    const senhaCorreta = 'Licitasis@2025';
    if (senha !== senhaCorreta) {
        showAuthError('Senha incorreta. A senha deve ser: Licitasis@2025');
        return;
    }

    // Mostra loading
    confirmBtn.disabled = true;
    loadingSpinner.style.display = 'inline-block';
    confirmIcon.style.display = 'none';
    confirmText.textContent = 'Verificando...';
    authError.style.display = 'none';

    console.log('🔐 Senha validada localmente, enviando para servidor...');

    // Atualiza status com autenticação
    updateStatusWithAuth(pendingStatusChange.id, pendingStatusChange.novoStatus, senha);
}

/**
 * Mostra erro de autenticação
 */
function showAuthError(message) {
    const authError = document.getElementById('authError');
    const authErrorMessage = document.getElementById('authErrorMessage');
    
    authErrorMessage.textContent = message;
    authError.style.display = 'block';
    
    // Limpa o campo de senha
    document.getElementById('financialPassword').value = '';
    document.getElementById('financialPassword').focus();
}

/**
 * Reseta botão de confirmação
 */
function resetAuthButton() {
    const confirmBtn = document.getElementById('confirmAuthBtn');
    const loadingSpinner = document.getElementById('authLoadingSpinner');
    const confirmIcon = document.getElementById('authConfirmIcon');
    const confirmText = document.getElementById('authConfirmText');
    
    confirmBtn.disabled = false;
    loadingSpinner.style.display = 'none';
    confirmIcon.style.display = 'inline-block';
    confirmText.textContent = 'Autorizar';
}

// ===========================================
// FUNÇÕES DE ATUALIZAÇÃO DE STATUS
// ===========================================

/**
 * Atualiza status com autenticação
 */
function updateStatusWithAuth(id, status, senha) {
    const data = new Date().toISOString().split('T')[0];
    
    console.log('🔐 Iniciando atualização com autenticação:', {id, status, senha});
    
    const formData = new FormData();
    formData.append('update_status', '1');
    formData.append('conta_id', id);
    formData.append('status_pagamento', status);
    formData.append('data_pagamento', status === 'Pendente' ? '' : data);
    formData.append('observacao_pagamento', `Status alterado para ${status} em ${new Date().toLocaleDateString('pt-BR')}`);
    formData.append('financial_password', senha);
    
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => {
        console.log('📡 Resposta recebida:', response.status);
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }
        return response.text();
    })
    .then(responseText => {
        console.log('📄 Resposta do servidor:', responseText);
        
        let data;
        try {
            data = JSON.parse(responseText);
        } catch (e) {
            console.error('❌ Erro ao fazer parse do JSON:', e);
            console.error('📄 Resposta recebida:', responseText);
            throw new Error('Resposta do servidor não é um JSON válido');
        }
        
        resetAuthButton();
        
        if (data.success) {
            console.log('✅ Status atualizado com sucesso');
            closeFinancialAuthModal();
            updateSelectValue(currentSelectElement, status);
            showSuccessMessage('Status de pagamento atualizado com sucesso!');
            
            // Atualiza a página após 2 segundos para refletir as mudanças
            setTimeout(() => {
                window.location.reload();
            }, 2000);
        } else {
            console.error('❌ Erro do servidor:', data.error);
            showAuthError(data.error || 'Erro ao processar solicitação');
        }
    })
    .catch(error => {
        resetAuthButton();
        console.error('❌ Erro na comunicação:', error);
        showAuthError(`Erro na comunicação: ${error.message}`);
    });
}

/**
 * Atualiza status diretamente (sem autenticação)
 */
function updateStatusDirect(id, status) {
    console.log('🔄 Iniciando atualização direta:', {id, status});
    
    const formData = new FormData();
    formData.append('update_status', '1');
    formData.append('conta_id', id);
    formData.append('status_pagamento', status);
    formData.append('data_pagamento', '');
    formData.append('observacao_pagamento', status === 'Pendente' ? 'Status alterado para Pendente' : '');
    
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => {
        console.log('📡 Resposta recebida:', response.status);
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }
        return response.text();
    })
    .then(responseText => {
        console.log('📄 Resposta do servidor:', responseText);
        
        let data;
        try {
            data = JSON.parse(responseText);
        } catch (e) {
            console.error('❌ Erro ao fazer parse do JSON:', e);
            console.error('📄 Resposta recebida:', responseText);
            throw new Error('Resposta do servidor não é um JSON válido');
        }
        
        if (data.success) {
            console.log('✅ Status atualizado com sucesso');
            closeConfirmationModal();
            updateSelectValue(currentSelectElement, status);
            showSuccessMessage('Status de pagamento atualizado com sucesso!');
            
            // Atualiza a página após 2 segundos para refletir as mudanças
            setTimeout(() => {
                window.location.reload();
            }, 2000);
        } else {
            console.error('❌ Erro do servidor:', data.error);
            alert('Erro ao atualizar status: ' + (data.error || 'Erro desconhecido'));
            if (currentSelectElement) {
                currentSelectElement.value = currentSelectElement.dataset.statusAtual || 'Pendente';
                updateSelectStyle(currentSelectElement);
            }
        }
    })
    .catch(error => {
        console.error('❌ Erro na comunicação:', error);
        alert(`Erro na comunicação: ${error.message}`);
        if (currentSelectElement) {
            currentSelectElement.value = currentSelectElement.dataset.statusAtual || 'Pendente';
            updateSelectStyle(currentSelectElement);
        }
    });
}

/**
 * Atualiza valor do select e estilo
 */
function updateSelectValue(selectElement, newStatus) {
    if (selectElement) {
        selectElement.value = newStatus;
        selectElement.dataset.statusAtual = newStatus;
        updateSelectStyle(selectElement);
    }
}

/**
 * Atualiza estilo visual do select baseado no status
 */
function updateSelectStyle(selectElement) {
    if (!selectElement) return;
    
    // Remove classes existentes
    selectElement.className = 'status-select';
    
    // Adiciona classe baseada no status
    const status = selectElement.value.toLowerCase();
    selectElement.classList.add(`status-${status}`);
}

/**
 * Mostra mensagem de sucesso
 */
function showSuccessMessage(message) {
    const alertDiv = document.createElement('div');
    alertDiv.className = 'alert alert-success';
    alertDiv.innerHTML = `<i class="fas fa-check-circle"></i> ${message}`;
    alertDiv.style.position = 'fixed';
    alertDiv.style.top = '20px';
    alertDiv.style.right = '20px';
    alertDiv.style.zIndex = '4000';
    alertDiv.style.minWidth = '300px';
    alertDiv.style.animation = 'slideInRight 0.3s ease';
    
    document.body.appendChild(alertDiv);
    
    setTimeout(() => {
        alertDiv.style.animation = 'slideOutRight 0.3s ease';
        setTimeout(() => {
            if (alertDiv.parentNode) {
                alertDiv.parentNode.removeChild(alertDiv);
            }
        }, 300);
    }, 3000);
}

// ===========================================
// INICIALIZAÇÃO E EVENT LISTENERS
// ===========================================

/**
 * Inicialização quando a página carrega
 */
document.addEventListener('DOMContentLoaded', function() {
    console.log('🚀 LicitaSis - Sistema de Contas a Pagar carregado');
    console.log('🔑 INFORMAÇÕES IMPORTANTES:');
    console.log('   - Senha do setor financeiro: Licitasis@2025');
    console.log('   - Case-sensitive (maiúsculas e minúsculas importam)');
    console.log('   - Use o botão "Testar Senha" em caso de problemas');
    console.log('   - Use o botão "Debug" para diagnóstico detalhado');
    console.log('🌐 URL base:', window.location.href);
    console.log('📊 Permissões de edição:', document.querySelectorAll('.status-select').length > 0 ? 'SIM' : 'NÃO');
    console.log('👤 Usuário logado:', <?php echo json_encode($_SESSION['user']['username'] ?? 'N/A'); ?>);
    console.log('🛡️ Nível de permissão:', <?php echo json_encode($_SESSION['user']['permission'] ?? 'N/A'); ?>);
    console.log('🔧 Sistema de permissões:', <?php echo $permissionManager ? '"Carregado"' : '"Básico (fallback)"'; ?>);
    
    // Event listener para fechar modal com ESC
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            closeModal();
            closeConfirmationModal();
            closeFinancialAuthModal();
        }
    });
    
    // Event listener para clicar fora do modal
    window.onclick = function(event) {
        const modal = document.getElementById('contaModal');
        const confirmModal = document.getElementById('confirmationModal');
        const authModal = document.getElementById('financialAuthModal');
        
        if (event.target === modal) {
            closeModal();
        }
        if (event.target === confirmModal) {
            closeConfirmationModal();
        }
        if (event.target === authModal) {
            closeFinancialAuthModal();
        }
    };
    
    // Auto-submit do formulário de pesquisa com delay
    const searchInput = document.getElementById('search');
    if (searchInput) {
        let searchTimeout;
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                const form = this.closest('form');
                if (form) form.submit();
            }, 800); // Delay de 800ms
        });
    }
    
    // Event listeners para os selects de status (apenas se o usuário tem permissão de edição)
    const statusSelects = document.querySelectorAll('.status-select');
    if (statusSelects.length > 0) {
        console.log('✅ Permissões de edição detectadas, ativando event listeners para selects de status');
        
        statusSelects.forEach(select => {
            // Aplica estilo inicial
            updateSelectStyle(select);
            
            select.addEventListener('change', function() {
                const novoStatus = this.value;
                const statusAtual = this.dataset.statusAtual || 'Pendente';
                
                if (novoStatus !== statusAtual) {
                    openConfirmationModal(this, novoStatus);
                }
            });
        });
    } else {
        console.log('ℹ️ Sem permissões de edição ou sem contas para editar');
    }
    
    // Enter para confirmar senha no modal de autenticação
    const passwordInput = document.getElementById('financialPassword');
    if (passwordInput) {
        passwordInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                confirmFinancialAuth();
            }
        });
    }
    
    // Adiciona hover effects nos cards de resumo
    const summaryCards = document.querySelectorAll('.summary-card');
    summaryCards.forEach(card => {
        card.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-8px) scale(1.02)';
        });
        
        card.addEventListener('mouseleave', function() {
            this.style.transform = 'translateY(0) scale(1)';
        });
    });
    
    // Animação das linhas da tabela
    const tableRows = document.querySelectorAll('tbody tr');
    tableRows.forEach((row, index) => {
        row.style.opacity = '0';
        row.style.transform = 'translateY(20px)';
        setTimeout(() => {
            row.style.transition = 'all 0.3s ease';
            row.style.opacity = '1';
            row.style.transform = 'translateY(0)';
        }, index * 50);
    });
    
    // Efeito de entrada nos cards de resumo
    const cards = document.querySelectorAll('.summary-card');
    cards.forEach((card, index) => {
        card.style.opacity = '0';
        card.style.transform = 'translateY(30px)';
        setTimeout(() => {
            card.style.transition = 'all 0.4s ease';
            card.style.opacity = '1';
            card.style.transform = 'translateY(0)';
        }, index * 100 + 200);
    });
    
    // Adiciona animações CSS dinamicamente
    const style = document.createElement('style');
    style.textContent = `
        @keyframes slideInRight {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        @keyframes slideOutRight {
            from { transform: translateX(0); opacity: 1; }
            to { transform: translateX(100%); opacity: 0; }
        }
    `;
    document.head.appendChild(style);
    
    console.log('✅ Todos os event listeners inicializados');
    console.log('✅ Sistema de autenticação financeira ativo');
    console.log('🔑 SENHA PADRÃO DO SETOR FINANCEIRO: Licitasis@2025');
    console.log('ℹ️  Use exatamente esta senha (case-sensitive) para autorizar mudanças de status');
});
</script>

<?php
// Verifica se é uma requisição AJAX que não deveria chegar até aqui
if (isset($_POST['update_status'])) {
    // Se chegou até aqui, houve um problema - retorna erro JSON
    if (ob_get_level()) {
        ob_clean();
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Processamento AJAX incompleto - verifique logs do servidor']);
    exit();
}

// Log de conclusão bem-sucedida
error_log("LicitaSis - Página de Contas a Pagar carregada com sucesso");
error_log("LicitaSis - Permissões ativas: " . ($permissionManager ? 'Sistema completo' : 'Sistema básico'));
error_log("LicitaSis - Total de contas exibidas: " . count($contas));
?>

</body>
</html>