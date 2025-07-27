<?php
session_start();
ob_start();

if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

// Inclui o sistema de permissões e auditoria
include('db.php');
include('permissions.php');

// ===========================================
// FUNÇÃO DE AUDITORIA COMPLETA
// ===========================================
if (!function_exists('logAudit')) {
    function logAudit($pdo, $userId, $action, $table, $recordId, $newData = null, $oldData = null) {
        try {
            // Verifica se a tabela de auditoria existe
            $checkTable = $pdo->query("SHOW TABLES LIKE 'audit_log'");
            if ($checkTable->rowCount() == 0) {
                // Cria tabela de auditoria se não existir
                $createAuditTable = "
                CREATE TABLE audit_log (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    action VARCHAR(50) NOT NULL,
                    table_name VARCHAR(100) NOT NULL,
                    record_id INT NOT NULL,
                    old_data JSON NULL,
                    new_data JSON NULL,
                    ip_address VARCHAR(45) NULL,
                    user_agent TEXT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_user_id (user_id),
                    INDEX idx_table_record (table_name, record_id),
                    INDEX idx_action (action),
                    INDEX idx_created_at (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                ";
                $pdo->exec($createAuditTable);
                error_log("✅ Tabela audit_log criada");
            }
            
            // Insere o log de auditoria
            $sql = "INSERT INTO audit_log (user_id, action, table_name, record_id, old_data, new_data, ip_address, user_agent, created_at) 
                    VALUES (:user_id, :action, :table_name, :record_id, :old_data, :new_data, :ip_address, :user_agent, NOW())";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':user_id' => $userId,
                ':action' => $action,
                ':table_name' => $table,
                ':record_id' => $recordId,
                ':old_data' => $oldData ? json_encode($oldData, JSON_UNESCAPED_UNICODE) : null,
                ':new_data' => $newData ? json_encode($newData, JSON_UNESCAPED_UNICODE) : null,
                ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                ':user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);
            
        } catch (Exception $e) {
            // Se falhar, apenas loga no error_log para não quebrar o fluxo principal
            error_log("AUDIT ERROR: " . $e->getMessage());
            error_log("AUDIT: User $userId performed $action on $table ID $recordId");
        }
    }
}

// ===========================================
// FUNÇÃO PARA BUSCAR TODOS OS FORNECEDORES
// ===========================================
function buscarFornecedoresSelect($pdo) {
    try {
        $sql = "SELECT id, nome, cnpj, cpf, tipo_pessoa, email, telefone 
                FROM fornecedores 
                WHERE nome IS NOT NULL AND nome != ''
                ORDER BY nome ASC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $fornecedores = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        error_log("✅ Encontrados " . count($fornecedores) . " fornecedores para o select");
        return $fornecedores;
        
    } catch (PDOException $e) {
        error_log("❌ Erro ao buscar fornecedores para select: " . $e->getMessage());
        return [];
    }
}

// Buscar fornecedores para o select
$fornecedoresSelect = buscarFornecedoresSelect($pdo);

// ===========================================
// FUNÇÃO PARA BUSCAR PRODUTOS DA COMPRA
// ===========================================
if (!function_exists('buscarProdutosCompra')) {
    function buscarProdutosCompra($compraId, $pdo) {
        try {
            $sql = "SELECT nome, quantidade, valor_unitario, valor_total FROM produtos_compra WHERE compra_id = :compra_id ORDER BY id";
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':compra_id', $compraId, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Erro ao buscar produtos da compra: " . $e->getMessage());
            return [];
        }
    }
}

// ===========================================
// VERIFICAÇÃO E CRIAÇÃO DA ESTRUTURA DA TABELA
// ===========================================
try {
    $checkTable = $pdo->query("SHOW TABLES LIKE 'contas_pagar'");
    if ($checkTable->rowCount() == 0) {
        error_log("❌ Tabela contas_pagar não existe! Criando...");
        
        $createTable = "
        CREATE TABLE contas_pagar (
            id INT AUTO_INCREMENT PRIMARY KEY,
            compra_id INT NULL,
            fornecedor_nome VARCHAR(255) NULL,
            numero_nf VARCHAR(100) NOT NULL,
            data_compra DATE NOT NULL,
            numero_empenho VARCHAR(100) NULL,
            data_vencimento DATE NULL,
            valor_total DECIMAL(12,2) NOT NULL,
            observacao TEXT NULL,
            status_pagamento ENUM('Pendente', 'Pago', 'Concluido') DEFAULT 'Pendente',
            tipo_despesa VARCHAR(50) DEFAULT 'Compras',
            data_pagamento DATE NULL,
            observacao_pagamento TEXT NULL,
            informacoes_adicionais TEXT NULL,
            comprovante_pagamento VARCHAR(500) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_compra_id (compra_id),
            INDEX idx_status (status_pagamento),
            INDEX idx_data_compra (data_compra),
            INDEX idx_fornecedor (fornecedor_nome),
            INDEX idx_numero_nf (numero_nf),
            FOREIGN KEY (compra_id) REFERENCES compras(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";
        
        $pdo->exec($createTable);
        error_log("✅ Tabela contas_pagar criada com sucesso!");
    } else {
        // Verifica se todas as colunas necessárias existem
        $columns = $pdo->query("SHOW COLUMNS FROM contas_pagar")->fetchAll(PDO::FETCH_COLUMN);
        $requiredColumns = [
            'id', 'compra_id', 'fornecedor_nome', 'numero_nf', 'data_compra', 
            'data_vencimento', 'valor_total', 'observacao', 
            'status_pagamento', 'tipo_despesa', 'data_pagamento', 
            'observacao_pagamento', 'informacoes_adicionais', 'comprovante_pagamento',
            'created_at', 'updated_at'
        ];
        
        foreach ($requiredColumns as $column) {
            if (!in_array($column, $columns)) {
                error_log("⚠️ Coluna '$column' não existe, adicionando...");
                
                switch ($column) {
                    case 'fornecedor_nome':
                        $pdo->exec("ALTER TABLE contas_pagar ADD COLUMN fornecedor_nome VARCHAR(255) NULL");
                        break;
                    case 'data_vencimento':
                        $pdo->exec("ALTER TABLE contas_pagar ADD COLUMN data_vencimento DATE NULL");
                        break;
                    case 'informacoes_adicionais':
                        $pdo->exec("ALTER TABLE contas_pagar ADD COLUMN informacoes_adicionais TEXT NULL");
                        break;
                    case 'updated_at':
                        $pdo->exec("ALTER TABLE contas_pagar ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
                        break;
                }
            }
        }
    }
} catch (Exception $e) {
    error_log("❌ Erro ao verificar/criar tabela: " . $e->getMessage());
}

// ===========================================
// INICIALIZAÇÃO DO SISTEMA DE PERMISSÕES
// ===========================================
$permissionManager = initPermissions($pdo);

// Verifica se o usuário tem permissão para acessar contas a pagar
$permissionManager->requirePermission('financeiro', 'view');

$isAdmin = isset($_SESSION['user']['permission']) && $_SESSION['user']['permission'] === 'Administrador';

// ===========================================
// ENDPOINT AJAX PARA BUSCAR FORNECEDORES (CORRIGIDO E COMPLETO)
// ===========================================
if (isset($_GET['search_fornecedores'])) {
    // Limpa qualquer output anterior
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    
    $termo = trim($_GET['termo'] ?? '');
    $limite = isset($_GET['limite']) ? min(50, max(5, intval($_GET['limite']))) : 20;
    
    error_log("🔍 Buscando fornecedores com termo: '$termo', limite: $limite");
    
    try {
        $fornecedores = [];
        
        if (empty($termo)) {
            // Se não há termo, retorna fornecedores mais recentes
            $sql = "SELECT id, nome, cnpj, cpf, tipo_pessoa, email, telefone 
                    FROM fornecedores 
                    WHERE nome IS NOT NULL AND nome != ''
                    ORDER BY nome ASC 
                    LIMIT :limite";
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
        } else {
            // Busca fornecedores que contenham o termo no nome, CNPJ ou CPF
            $sql = "SELECT id, nome, cnpj, cpf, tipo_pessoa, email, telefone 
                    FROM fornecedores 
                    WHERE (nome LIKE :termo OR cnpj LIKE :termo OR cpf LIKE :termo)
                    AND nome IS NOT NULL AND nome != ''
                    ORDER BY 
                        CASE 
                            WHEN nome LIKE :termo_inicio THEN 1
                            WHEN nome LIKE :termo THEN 2
                            WHEN cnpj LIKE :termo_inicio THEN 3
                            WHEN cpf LIKE :termo_inicio THEN 4
                            ELSE 5
                        END,
                        nome ASC 
                    LIMIT :limite";
            $stmt = $pdo->prepare($sql);
            $termoLike = "%$termo%";
            $termoInicio = "$termo%";
            $stmt->bindValue(':termo', $termoLike, PDO::PARAM_STR);
            $stmt->bindValue(':termo_inicio', $termoInicio, PDO::PARAM_STR);
            $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
        }
        
        $stmt->execute();
        $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        error_log("📦 Encontrados " . count($resultados) . " fornecedores");
        
        // Formatar dados para o frontend
        foreach ($resultados as $fornecedor) {
            $documento = '';
            $documentoNumero = '';
            
            if ($fornecedor['tipo_pessoa'] === 'PJ' && !empty($fornecedor['cnpj'])) {
                $documento = 'CNPJ: ' . $fornecedor['cnpj'];
                $documentoNumero = $fornecedor['cnpj'];
            } elseif ($fornecedor['tipo_pessoa'] === 'PF' && !empty($fornecedor['cpf'])) {
                $documento = 'CPF: ' . $fornecedor['cpf'];
                $documentoNumero = $fornecedor['cpf'];
            }
            
            $fornecedores[] = [
                'id' => $fornecedor['id'],
                'nome' => $fornecedor['nome'],
                'documento' => $documento,
                'documento_numero' => $documentoNumero,
                'tipo_pessoa' => $fornecedor['tipo_pessoa'],
                'email' => $fornecedor['email'] ?? '',
                'telefone' => $fornecedor['telefone'] ?? '',
                'texto_completo' => $fornecedor['nome'] . 
                    ($documento ? ' - ' . $documento : '')
            ];
        }
        
        echo json_encode([
            'success' => true,
            'fornecedores' => $fornecedores,
            'total' => count($fornecedores),
            'termo_busca' => $termo
        ], JSON_UNESCAPED_UNICODE);
        
    } catch (PDOException $e) {
        error_log("❌ Erro ao buscar fornecedores: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'error' => "Erro ao buscar fornecedores: " . $e->getMessage(),
            'fornecedores' => []
        ], JSON_UNESCAPED_UNICODE);
    }
    exit();
}

// ===========================================
// INICIALIZAÇÃO DE VARIÁVEIS COM PESQUISA
// ===========================================
$error = "";
$success = "";
$contas_a_pagar = [];

// INICIALIZAÇÃO DE VARIÁVEIS CRÍTICAS
$orderBy = isset($_GET['order']) ? trim($_GET['order']) : 'c.data';
$orderDirection = isset($_GET['dir']) && strtoupper($_GET['dir']) === 'DESC' ? 'DESC' : 'ASC';

// Lista dos campos válidos para ordenação (segurança)
$validOrderFields = [
    'c.numero_nf', 'c.fornecedor', 'c.valor_total', 'c.data',
    'cp.status_pagamento', 'cp.data_pagamento', 'cp.tipo_despesa'
];

if (!in_array($orderBy, $validOrderFields)) {
    $orderBy = 'c.data';
}

// CONFIGURAÇÃO DE FILTROS E PAGINAÇÃO AVANÇADA
$itensPorPagina = isset($_GET['items_per_page']) ? max(10, min(100, intval($_GET['items_per_page']))) : 20;
$paginaAtual = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($paginaAtual - 1) * $itensPorPagina;

// Definir tipos de despesa
$tiposDespesa = [
    'Compras' => 'Compras',
    'Servicos' => 'Serviços',
    'Manutencao' => 'Manutenção',
    'Consultoria' => 'Consultoria',
    'Equipamentos' => 'Equipamentos',
    'Material_Escritorio' => 'Material de Escritório',
    'Limpeza' => 'Limpeza',
    'Seguranca' => 'Segurança',
    'Pro_Labore' => 'Pró-Labore',
    'Outros' => 'Outros'
];

// Inicialização de variáveis de totais
$totalGeralPagar = 0;
$totalPendente = 0;
$totalPago = 0;
$totalContas = 0;
$totalPaginas = 1;

// Parâmetros de filtro COM PESQUISA INCLUÍDA
$filtros = [
    'search' => isset($_GET['search']) ? trim($_GET['search']) : '',
    'numero_nf' => isset($_GET['numero_nf']) ? trim($_GET['numero_nf']) : '',
    'fornecedor' => isset($_GET['fornecedor']) ? trim($_GET['fornecedor']) : '',
    'status' => isset($_GET['status']) ? trim($_GET['status']) : '',
    'tipo_despesa' => isset($_GET['tipo_despesa']) ? trim($_GET['tipo_despesa']) : '',
    'valor_min' => isset($_GET['valor_min']) ? floatval($_GET['valor_min']) : null,
    'valor_max' => isset($_GET['valor_max']) ? floatval($_GET['valor_max']) : null,
    'data_inicio' => isset($_GET['data_inicio']) ? trim($_GET['data_inicio']) : '',
    'data_fim' => isset($_GET['data_fim']) ? trim($_GET['data_fim']) : '',
    'data_pagamento_inicio' => isset($_GET['data_pagamento_inicio']) ? trim($_GET['data_pagamento_inicio']) : '',
    'data_pagamento_fim' => isset($_GET['data_pagamento_fim']) ? trim($_GET['data_pagamento_fim']) : ''
];

// ===========================================
// PROCESSAMENTO AJAX - BUSCA DE DETALHES DA CONTA
// ===========================================
if (isset($_GET['get_conta_id'])) {
    header('Content-Type: application/json; charset=utf-8');
    
    $conta_id = intval($_GET['get_conta_id']);
    
    if ($conta_id <= 0) {
        echo json_encode(['error' => 'ID de conta inválido']);
        exit();
    }
    
    try {
        $sql = "SELECT cp.*,
                       COALESCE(c.numero_nf, cp.numero_nf) as numero_nf,
                       COALESCE(c.fornecedor, cp.fornecedor_nome) as fornecedor,
                       COALESCE(c.valor_total, cp.valor_total) as valor_total,
                       COALESCE(c.data, cp.data_compra) as data,
                       COALESCE(c.numero_empenho, cp.numero_empenho) as numero_empenho,
                       c.link_pagamento,
                       COALESCE(c.comprovante_pagamento, cp.comprovante_pagamento) as comprovante_pagamento,
                       COALESCE(c.observacao, cp.observacao) as observacao,
                       c.frete,
                       CASE WHEN cp.compra_id IS NULL THEN 'Conta Direta' ELSE 'Compra' END as tipo_origem
                FROM contas_pagar cp
                LEFT JOIN compras c ON cp.compra_id = c.id
                WHERE cp.id = :conta_id";
        
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':conta_id', $conta_id, PDO::PARAM_INT);
        $stmt->execute();
        $conta = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$conta) {
            echo json_encode(['error' => 'Conta não encontrada']);
            exit();
        }
        
        // Busca produtos se for uma compra
        if ($conta['compra_id']) {
            $conta['produtos'] = buscarProdutosCompra($conta['compra_id'], $pdo);
        } else {
            $conta['produtos'] = []; // Contas diretas não têm produtos
        }
        
        echo json_encode($conta, JSON_UNESCAPED_UNICODE);
        exit();
        
    } catch (PDOException $e) {
        error_log("Erro ao buscar detalhes da conta: " . $e->getMessage());
        echo json_encode(['error' => "Erro ao buscar detalhes da conta: " . $e->getMessage()]);
        exit();
    }
}

// ===========================================
// PROCESSAMENTO AJAX - EDIÇÃO DE CONTA DIRETA
// ===========================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['editar_conta_direta'])) {
    header('Content-Type: application/json; charset=utf-8');
    
    $response = ['success' => false];
    $contaId = intval($_POST['conta_id'] ?? 0);
    
    if ($contaId <= 0) {
        $response['error'] = 'ID da conta inválido';
        echo json_encode($response);
        exit();
    }
    
    try {
        $pdo->beginTransaction();
        
        // Busca dados atuais para auditoria
        $sqlSelect = "SELECT * FROM contas_pagar WHERE id = :id";
        $stmtSelect = $pdo->prepare($sqlSelect);
        $stmtSelect->bindValue(':id', $contaId, PDO::PARAM_INT);
        $stmtSelect->execute();
        $dadosAntigos = $stmtSelect->fetch(PDO::FETCH_ASSOC);
        
        if (!$dadosAntigos) {
            throw new Exception('Conta não encontrada');
        }
        
        // Verifica se é conta direta (não pode editar contas vinculadas a compras)
        if ($dadosAntigos['compra_id']) {
            throw new Exception('Não é possível editar contas vinculadas a compras através desta interface');
        }
        
        // Sanitiza e valida os novos dados
        $dados = [
            'fornecedor_nome' => trim($_POST['fornecedor_nome'] ?? ''),
            'numero_nf' => trim($_POST['numero_nf'] ?? ''),
            'data_compra' => $_POST['data_compra'] ?? date('Y-m-d'),
            'data_vencimento' => !empty($_POST['data_vencimento']) ? $_POST['data_vencimento'] : null,
            'valor_total' => !empty($_POST['valor_total']) ? str_replace(',', '.', $_POST['valor_total']) : null,
            'tipo_despesa' => trim($_POST['tipo_despesa'] ?? 'Compras'),
            'observacao' => trim($_POST['observacao'] ?? ''),
            'informacoes_adicionais' => trim($_POST['informacoes_adicionais'] ?? '')
        ];

        // Validações obrigatórias
        if (empty($dados['fornecedor_nome'])) {
            throw new Exception("Nome do fornecedor é obrigatório.");
        }
        if (empty($dados['numero_nf'])) {
            throw new Exception("Número da NF é obrigatório.");
        }
        if (empty($dados['valor_total']) || $dados['valor_total'] <= 0) {
            throw new Exception("Valor total é obrigatório e deve ser maior que zero.");
        }

        // Validação de tipos permitidos
        $tiposValidos = array_keys($tiposDespesa);
        if (!in_array($dados['tipo_despesa'], $tiposValidos)) {
            throw new Exception("Tipo de despesa inválido.");
        }

        // Verifica se já existe uma NF com o mesmo número para o mesmo fornecedor (exceto a atual)
        $sqlCheck = "SELECT id FROM contas_pagar WHERE numero_nf = :numero_nf AND fornecedor_nome = :fornecedor_nome AND id != :id";
        $stmtCheck = $pdo->prepare($sqlCheck);
        $stmtCheck->execute([
            ':numero_nf' => $dados['numero_nf'],
            ':fornecedor_nome' => $dados['fornecedor_nome'],
            ':id' => $contaId
        ]);
        
        if ($stmtCheck->fetch()) {
            throw new Exception("Já existe uma conta a pagar para este fornecedor com o mesmo número de NF.");
        }

        // Processa upload de comprovante se enviado
        $comprovanteNome = $dadosAntigos['comprovante_pagamento']; // Mantém o atual
        if (isset($_FILES['comprovante_pagamento']) && $_FILES['comprovante_pagamento']['error'] === UPLOAD_ERR_OK) {
            $arquivo = $_FILES['comprovante_pagamento'];
            $extensoesPermitidas = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'];
            $extensao = strtolower(pathinfo($arquivo['name'], PATHINFO_EXTENSION));
            
            if (!in_array($extensao, $extensoesPermitidas)) {
                throw new Exception("Formato de arquivo não permitido. Use: PDF, JPG, PNG, DOC, DOCX");
            }
            
            if ($arquivo['size'] > 10485760) { // 10MB
                throw new Exception("Arquivo muito grande. Máximo 10MB permitido.");
            }
            
            // Cria diretório se não existir
            $uploadDir = 'uploads/comprovantes/';
            if (!is_dir($uploadDir)) {
                if (!mkdir($uploadDir, 0755, true)) {
                    throw new Exception("Erro ao criar diretório de upload.");
                }
            }
            
            // Remove arquivo anterior se existir
            if ($dadosAntigos['comprovante_pagamento'] && file_exists($dadosAntigos['comprovante_pagamento'])) {
                unlink($dadosAntigos['comprovante_pagamento']);
            }
            
            // Nome único para o arquivo
            $comprovanteNome = 'comprovante_' . date('YmdHis') . '_' . uniqid() . '.' . $extensao;
            $caminhoCompleto = $uploadDir . $comprovanteNome;
            
            if (!move_uploaded_file($arquivo['tmp_name'], $caminhoCompleto)) {
                throw new Exception("Erro ao fazer upload do comprovante.");
            }
            
            $comprovanteNome = $caminhoCompleto;
        }

        // Atualiza a conta
        $sql = "UPDATE contas_pagar SET 
                    fornecedor_nome = :fornecedor_nome,
                    numero_nf = :numero_nf,
                    data_compra = :data_compra,
                    data_vencimento = :data_vencimento,
                    valor_total = :valor_total,
                    tipo_despesa = :tipo_despesa,
                    observacao = :observacao,
                    informacoes_adicionais = :informacoes_adicionais,
                    comprovante_pagamento = :comprovante_pagamento,
                    updated_at = NOW()
                WHERE id = :id";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':fornecedor_nome' => $dados['fornecedor_nome'],
            ':numero_nf' => $dados['numero_nf'],
            ':data_compra' => $dados['data_compra'],
            ':data_vencimento' => $dados['data_vencimento'],
            ':valor_total' => $dados['valor_total'],
            ':tipo_despesa' => $dados['tipo_despesa'],
            ':observacao' => $dados['observacao'],
            ':informacoes_adicionais' => $dados['informacoes_adicionais'],
            ':comprovante_pagamento' => $comprovanteNome,
            ':id' => $contaId
        ]);

        // Log de auditoria
        logAudit($pdo, $_SESSION['user']['id'], 'UPDATE', 'contas_pagar', $contaId, $dados, $dadosAntigos);

        $pdo->commit();
        
        $response['success'] = true;
        $response['message'] = "Conta atualizada com sucesso!";
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        
        // Remove arquivo se foi feito upload mas houve erro
        if (isset($caminhoCompleto) && file_exists($caminhoCompleto)) {
            unlink($caminhoCompleto);
        }
        
        $response['error'] = $e->getMessage();
        error_log("Erro ao editar conta: " . $e->getMessage());
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit();
}

// ===========================================
// PROCESSAMENTO AJAX - EXCLUSÃO DE CONTA (CORRIGIDO)
// ===========================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['excluir_conta'])) {
    header('Content-Type: application/json; charset=utf-8');
    
    $response = ['success' => false];
    $contaId = intval($_POST['conta_id'] ?? 0);
    $senhaFinanceiro = trim($_POST['financial_password'] ?? '');
    
    // Validação da senha do setor financeiro
    $senhaCorreta = 'Licitasis@2025';
    if ($senhaFinanceiro !== $senhaCorreta) {
        $response['error'] = 'Senha do setor financeiro incorreta';
        echo json_encode($response);
        exit();
    }
    
    if ($contaId <= 0) {
        $response['error'] = 'ID da conta inválido';
        echo json_encode($response);
        exit();
    }
    
    try {
        $pdo->beginTransaction();
        
        // Busca dados da conta para auditoria
        $sqlSelect = "SELECT * FROM contas_pagar WHERE id = :id";
        $stmtSelect = $pdo->prepare($sqlSelect);
        $stmtSelect->bindValue(':id', $contaId, PDO::PARAM_INT);
        $stmtSelect->execute();
        $conta = $stmtSelect->fetch(PDO::FETCH_ASSOC);
        
        if (!$conta) {
            throw new Exception('Conta não encontrada');
        }
        
        // Verifica se é conta direta (não pode excluir contas vinculadas a compras)
        if ($conta['compra_id']) {
            throw new Exception('Não é possível excluir contas vinculadas a compras. Exclua a compra correspondente.');
        }
        
        // Remove comprovante se existir
        if ($conta['comprovante_pagamento'] && file_exists($conta['comprovante_pagamento'])) {
            unlink($conta['comprovante_pagamento']);
        }
        
        // Exclui a conta
        $sqlDelete = "DELETE FROM contas_pagar WHERE id = :id";
        $stmtDelete = $pdo->prepare($sqlDelete);
        $stmtDelete->bindValue(':id', $contaId, PDO::PARAM_INT);
        $stmtDelete->execute();
        
        // Log de auditoria
        logAudit($pdo, $_SESSION['user']['id'], 'DELETE', 'contas_pagar', $contaId, null, $conta);
        
        $pdo->commit();
        
        $response['success'] = true;
        $response['message'] = "Conta excluída com sucesso!";
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        
        $response['error'] = $e->getMessage();
        error_log("Erro ao excluir conta: " . $e->getMessage());
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit();
}

// ===========================================
// PROCESSAMENTO AJAX - ATUALIZAÇÃO DE STATUS (CORRIGIDO)
// ===========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    header('Content-Type: application/json; charset=utf-8');
    
    $contaId = intval($_POST['id']);
    $statusPagamento = trim($_POST['status_pagamento']);
    $dataPagamento = trim($_POST['data_pagamento']);
    $observacaoPagamento = trim($_POST['observacao_pagamento']);
    $senhaFinanceiro = trim($_POST['financial_password'] ?? '');
    $tipoDespesa = trim($_POST['tipo_despesa'] ?? 'Compras');
    $informacoesAdicionais = trim($_POST['informacoes_adicionais'] ?? '');
    
    // Validação da senha do setor financeiro para status Pago/Concluído
    $senhaCorreta = 'Licitasis@2025';
    if (($statusPagamento === 'Pago' || $statusPagamento === 'Concluido') && $senhaFinanceiro !== $senhaCorreta) {
        echo json_encode(['error' => 'Senha do setor financeiro incorreta']);
        exit();
    }
    
    // Validações básicas
    if ($contaId <= 0) {
        echo json_encode(['error' => 'ID de conta inválido']);
        exit();
    }
    
    if (!in_array($statusPagamento, ['Pendente', 'Pago', 'Concluido'])) {
        echo json_encode(['error' => 'Status de pagamento inválido']);
        exit();
    }
    
    try {
        $pdo->beginTransaction();
        
        // Busca dados atuais da conta para auditoria
        $sqlSelect = "SELECT * FROM contas_pagar WHERE id = :id";
        $stmtSelect = $pdo->prepare($sqlSelect);
        $stmtSelect->bindValue(':id', $contaId, PDO::PARAM_INT);
        $stmtSelect->execute();
        $dadosAntigos = $stmtSelect->fetch(PDO::FETCH_ASSOC);
        
        if (!$dadosAntigos) {
            throw new Exception('Conta a pagar não encontrada');
        }
        
        // Processa upload de comprovante se enviado
        $comprovanteNome = $dadosAntigos['comprovante_pagamento']; // Mantém o atual
        if (isset($_FILES['comprovante_upload']) && $_FILES['comprovante_upload']['error'] === UPLOAD_ERR_OK) {
            $arquivo = $_FILES['comprovante_upload'];
            $extensoesPermitidas = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'];
            $extensao = strtolower(pathinfo($arquivo['name'], PATHINFO_EXTENSION));
            
            if (!in_array($extensao, $extensoesPermitidas)) {
                throw new Exception("Formato de arquivo não permitido. Use: PDF, JPG, PNG, DOC, DOCX");
            }
            
            if ($arquivo['size'] > 10485760) { // 10MB
                throw new Exception("Arquivo muito grande. Máximo 10MB permitido.");
            }
            
            // Cria diretório se não existir
            $uploadDir = 'uploads/comprovantes/';
            if (!is_dir($uploadDir)) {
                if (!mkdir($uploadDir, 0755, true)) {
                    throw new Exception("Erro ao criar diretório de upload.");
                }
            }
            
            // Remove arquivo anterior se existir
            if ($dadosAntigos['comprovante_pagamento'] && file_exists($dadosAntigos['comprovante_pagamento'])) {
                unlink($dadosAntigos['comprovante_pagamento']);
            }
            
            // Nome único para o arquivo
            $comprovanteNome = 'comprovante_' . date('YmdHis') . '_' . uniqid() . '.' . $extensao;
            $caminhoCompleto = $uploadDir . $comprovanteNome;
            
            if (!move_uploaded_file($arquivo['tmp_name'], $caminhoCompleto)) {
                throw new Exception("Erro ao fazer upload do comprovante.");
            }
            
            $comprovanteNome = $caminhoCompleto;
        }
        
        // Atualiza a conta
        $sql = "UPDATE contas_pagar SET 
                    status_pagamento = :status_pagamento, 
                    data_pagamento = :data_pagamento,
                    observacao_pagamento = :observacao_pagamento,
                    tipo_despesa = :tipo_despesa,
                    informacoes_adicionais = :informacoes_adicionais,
                    comprovante_pagamento = :comprovante_pagamento,
                    updated_at = NOW()
                WHERE id = :id";
        
        $stmt = $pdo->prepare($sql);
        
        $stmt->execute([
            ':status_pagamento' => $statusPagamento,
            ':data_pagamento' => $dataPagamento ? $dataPagamento : null,
            ':observacao_pagamento' => $observacaoPagamento,
            ':tipo_despesa' => $tipoDespesa,
            ':informacoes_adicionais' => $informacoesAdicionais,
            ':comprovante_pagamento' => $comprovanteNome,
            ':id' => $contaId
        ]);
        
        // Log de auditoria
        $dadosNovos = [
            'status_pagamento' => $statusPagamento,
            'data_pagamento' => $dataPagamento,
            'tipo_despesa' => $tipoDespesa,
            'informacoes_adicionais' => $informacoesAdicionais
        ];
        
        logAudit($pdo, $_SESSION['user']['id'], 'UPDATE', 'contas_pagar', $contaId, $dadosNovos, $dadosAntigos);
        
        $pdo->commit();
        echo json_encode(['success' => true]);
        exit();
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        
        // Remove arquivo se foi feito upload mas houve erro
        if (isset($caminhoCompleto) && file_exists($caminhoCompleto)) {
            unlink($caminhoCompleto);
        }
        
        error_log("Erro ao atualizar status: " . $e->getMessage());
        echo json_encode(['error' => $e->getMessage()]);
        exit();
    }
}

// ===========================================
// PROCESSAMENTO AJAX - ATUALIZAÇÃO DE TIPO DE DESPESA
// ===========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_tipo_despesa'])) {
    header('Content-Type: application/json; charset=utf-8');
    
    $contaId = intval($_POST['conta_id']);
    $tipoDespesa = trim($_POST['tipo_despesa']);
    
    // Validações
    if ($contaId <= 0) {
        echo json_encode(['error' => 'ID de conta inválido']);
        exit();
    }
    
    $tiposValidos = array_keys($tiposDespesa);
    if (!in_array($tipoDespesa, $tiposValidos)) {
        echo json_encode(['error' => 'Tipo de despesa inválido']);
        exit();
    }
    
    try {
        $sql = "UPDATE contas_pagar SET tipo_despesa = :tipo_despesa, updated_at = NOW() WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':tipo_despesa' => $tipoDespesa, ':id' => $contaId]);
        
        // Log de auditoria
        logAudit($pdo, $_SESSION['user']['id'], 'UPDATE', 'contas_pagar', $contaId, ['tipo_despesa' => $tipoDespesa]);
        
        echo json_encode(['success' => true]);
        exit();
        
    } catch (PDOException $e) {
        error_log("Erro ao atualizar tipo de despesa: " . $e->getMessage());
        echo json_encode(['error' => 'Erro ao atualizar tipo de despesa: ' . $e->getMessage()]);
        exit();
    }
}

// ===========================================
// PROCESSAMENTO AJAX - INCLUSÃO DE CONTA A PAGAR DIRETA
// ===========================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['incluir_conta_direta'])) {
    error_log("🔄 Iniciando processamento de conta direta");
    error_log("📋 Dados POST recebidos: " . print_r($_POST, true));
    error_log("📁 Arquivos recebidos: " . print_r($_FILES, true));
    
    // Limpa qualquer output anterior
    if (ob_get_level()) {
        ob_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    
    $response = ['success' => false];
    
    try {
        $pdo->beginTransaction();
        error_log("✅ Transação iniciada");
        
        // Sanitiza e valida os dados
        $dados = [
            'fornecedor_nome' => trim($_POST['fornecedor_nome'] ?? ''),
            'numero_nf' => trim($_POST['numero_nf'] ?? ''),
            'data_compra' => $_POST['data_compra'] ?? date('Y-m-d'),
            'data_vencimento' => !empty($_POST['data_vencimento']) ? $_POST['data_vencimento'] : null,
            'valor_total' => !empty($_POST['valor_total']) ? str_replace(',', '.', $_POST['valor_total']) : null,
            'tipo_despesa' => trim($_POST['tipo_despesa'] ?? 'Compras'),
            'observacao' => trim($_POST['observacao'] ?? ''),
            'informacoes_adicionais' => trim($_POST['informacoes_adicionais'] ?? ''),
            'status_pagamento' => 'Pendente',
            'compra_id' => null
        ];

        error_log("📊 Dados processados: " . print_r($dados, true));

        // Validações obrigatórias
        if (empty($dados['fornecedor_nome'])) {
            throw new Exception("Nome do fornecedor é obrigatório.");
        }
        if (empty($dados['numero_nf'])) {
            throw new Exception("Número da NF é obrigatório.");
        }
        if (empty($dados['tipo_despesa'])) {
            throw new Exception("Tipo de despesa é obrigatório.");
        }
        if (empty($dados['valor_total']) || $dados['valor_total'] <= 0) {
            throw new Exception("Valor total é obrigatório e deve ser maior que zero.");
        }

        // Validação de tipos permitidos
        $tiposValidos = array_keys($tiposDespesa);
        if (!in_array($dados['tipo_despesa'], $tiposValidos)) {
            throw new Exception("Tipo de despesa inválido.");
        }

        // Validação de data
        if (!empty($dados['data_compra'])) {
            $dataCompraObj = DateTime::createFromFormat('Y-m-d', $dados['data_compra']);
            if (!$dataCompraObj) {
                throw new Exception("Data da compra inválida.");
            }
        }

        // Validação de data de vencimento
        if (!empty($dados['data_vencimento'])) {
            $dataVencimentoObj = DateTime::createFromFormat('Y-m-d', $dados['data_vencimento']);
            if (!$dataVencimentoObj) {
                throw new Exception("Data de vencimento inválida.");
            }
        }

        // Validação de valor
        if (!is_numeric($dados['valor_total'])) {
            throw new Exception("Valor total deve ser um número válido.");
        }

        error_log("✅ Validações básicas passaram");

        // Verifica se já existe uma NF com o mesmo número para o mesmo fornecedor
        $sqlCheck = "SELECT id FROM contas_pagar WHERE numero_nf = :numero_nf AND fornecedor_nome = :fornecedor_nome";
        $stmtCheck = $pdo->prepare($sqlCheck);
        $stmtCheck->execute([
            ':numero_nf' => $dados['numero_nf'],
            ':fornecedor_nome' => $dados['fornecedor_nome']
        ]);
        
        if ($stmtCheck->fetch()) {
            throw new Exception("Já existe uma conta a pagar para este fornecedor com o mesmo número de NF.");
        }

        // Processa upload do comprovante se enviado
        $comprovanteNome = null;
        if (isset($_FILES['comprovante_pagamento']) && $_FILES['comprovante_pagamento']['error'] === UPLOAD_ERR_OK) {
            error_log("📎 Processando upload de comprovante");
            
            $arquivo = $_FILES['comprovante_pagamento'];
            $extensoesPermitidas = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'];
            $extensao = strtolower(pathinfo($arquivo['name'], PATHINFO_EXTENSION));
            
            if (!in_array($extensao, $extensoesPermitidas)) {
                throw new Exception("Formato de arquivo não permitido. Use: PDF, JPG, PNG, DOC, DOCX");
            }
            
            if ($arquivo['size'] > 10485760) { // 10MB
                throw new Exception("Arquivo muito grande. Máximo 10MB permitido.");
            }
            
            // Cria diretório se não existir
            $uploadDir = 'uploads/comprovantes/';
            if (!is_dir($uploadDir)) {
                if (!mkdir($uploadDir, 0755, true)) {
                    throw new Exception("Erro ao criar diretório de upload.");
                }
            }
            
            // Nome único para o arquivo
            $comprovanteNome = 'comprovante_' . date('YmdHis') . '_' . uniqid() . '.' . $extensao;
            $caminhoCompleto = $uploadDir . $comprovanteNome;
            
            if (!move_uploaded_file($arquivo['tmp_name'], $caminhoCompleto)) {
                throw new Exception("Erro ao fazer upload do comprovante.");
            }
            
            $dados['comprovante_pagamento'] = $caminhoCompleto;
            error_log("✅ Upload de comprovante concluído: " . $caminhoCompleto);
        }

        // Insere a nova conta a pagar direta
        $sql = "INSERT INTO contas_pagar (
                    compra_id, fornecedor_nome, numero_nf, data_compra, 
                    data_vencimento, valor_total, observacao, status_pagamento, 
                    tipo_despesa, informacoes_adicionais, comprovante_pagamento, created_at
                ) VALUES (
                    :compra_id, :fornecedor_nome, :numero_nf, :data_compra, 
                    :data_vencimento, :valor_total, :observacao, :status_pagamento, 
                    :tipo_despesa, :informacoes_adicionais, :comprovante_pagamento, NOW()
                )";
        
        error_log("📝 SQL preparado: " . $sql);
        
        $stmt = $pdo->prepare($sql);
        
        // Bind parameters com validação
        $bindParams = [
            ':compra_id' => null,
            ':fornecedor_nome' => $dados['fornecedor_nome'],
            ':numero_nf' => $dados['numero_nf'],
            ':data_compra' => $dados['data_compra'],
            ':data_vencimento' => $dados['data_vencimento'],
            ':valor_total' => $dados['valor_total'],
            ':observacao' => $dados['observacao'],
            ':status_pagamento' => $dados['status_pagamento'],
            ':tipo_despesa' => $dados['tipo_despesa'],
            ':informacoes_adicionais' => $dados['informacoes_adicionais'],
            ':comprovante_pagamento' => $dados['comprovante_pagamento'] ?? null
        ];
        
        foreach ($bindParams as $param => $value) {
            if ($param === ':compra_id') {
                $stmt->bindValue($param, $value, PDO::PARAM_NULL);
            } elseif ($param === ':valor_total') {
                $stmt->bindValue($param, $value, PDO::PARAM_STR);
            } else {
                $stmt->bindValue($param, $value, $value === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            }
            error_log("🔗 Bind {$param}: " . var_export($value, true));
        }
        
        if (!$stmt->execute()) {
            $errorInfo = $stmt->errorInfo();
            error_log("❌ Erro na execução SQL: " . print_r($errorInfo, true));
            throw new Exception("Erro ao inserir a conta: " . ($errorInfo[2] ?? 'Erro desconhecido'));
        }

        $novoId = $pdo->lastInsertId();
        
        if (!$novoId) {
            throw new Exception("Erro ao obter ID da nova conta inserida");
        }
        
        error_log("✅ Conta inserida com ID: " . $novoId);

        // Registra auditoria
        logAudit($pdo, $_SESSION['user']['id'], 'INSERT', 'contas_pagar', $novoId, $dados);
        error_log("✅ Auditoria registrada");

        $pdo->commit();
        error_log("✅ Transação commitada");
        
        $response['success'] = true;
        $response['message'] = "Conta a pagar criada com sucesso!";
        $response['id'] = $novoId;
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
            error_log("🔄 Transação revertida");
        }
        
        // Remove arquivo se foi feito upload mas houve erro
        if (isset($caminhoCompleto) && file_exists($caminhoCompleto)) {
            unlink($caminhoCompleto);
            error_log("🗑️ Arquivo de upload removido devido ao erro");
        }
        
        $response['error'] = $e->getMessage();
        error_log("❌ Erro ao incluir conta a pagar direta: " . $e->getMessage());
        error_log("❌ Stack trace: " . $e->getTraceAsString());
        
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
            error_log("🔄 Transação revertida (PDO)");
        }
        
        // Remove arquivo se foi feito upload mas houve erro
        if (isset($caminhoCompleto) && file_exists($caminhoCompleto)) {
            unlink($caminhoCompleto);
        }
        
        $response['error'] = "Erro de banco de dados: " . $e->getMessage();
        error_log("❌ Erro PDO ao incluir conta a pagar direta: " . $e->getMessage());
    }

    error_log("📤 Enviando resposta: " . json_encode($response));
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit();
}

// ===========================================
// CONSULTAS PRINCIPAIS COM PESQUISA (SEÇÃO COMPLETA)
// ===========================================
try {
    // Parâmetros para consulta
    $params = [];
    $whereConditions = ['(cp.status_pagamento IN (\'Pendente\', \'Pago\', \'Concluido\') OR cp.status_pagamento IS NULL)'];
    
    // CONDIÇÃO PRINCIPAL: Busca geral com PESQUISA COMPLETA
    if (!empty($filtros['search'])) {
        $whereConditions[] = "(
            c.numero_nf LIKE :search OR 
            c.fornecedor LIKE :search OR 
            cp.status_pagamento LIKE :search OR 
            cp.tipo_despesa LIKE :search OR 
            c.numero_empenho LIKE :search OR 
            cp.fornecedor_nome LIKE :search OR 
            cp.numero_nf LIKE :search OR
            CAST(c.valor_total AS CHAR) LIKE :search OR
            CAST(cp.valor_total AS CHAR) LIKE :search
        )";
        $params[':search'] = "%{$filtros['search']}%";
    }
    
    // Construção das condições de filtro existentes
    if (!empty($filtros['numero_nf'])) {
        $whereConditions[] = "(c.numero_nf LIKE :numero_nf OR cp.numero_nf LIKE :numero_nf)";
        $params[':numero_nf'] = "%{$filtros['numero_nf']}%";
    }

    if (!empty($filtros['fornecedor'])) {
        $whereConditions[] = "(c.fornecedor LIKE :fornecedor OR cp.fornecedor_nome LIKE :fornecedor)";
        $params[':fornecedor'] = "%{$filtros['fornecedor']}%";
    }

    if (!empty($filtros['status'])) {
        $whereConditions[] = "cp.status_pagamento = :status";
        $params[':status'] = $filtros['status'];
    }

    if (!empty($filtros['tipo_despesa'])) {
        $whereConditions[] = "cp.tipo_despesa = :tipo_despesa";
        $params[':tipo_despesa'] = $filtros['tipo_despesa'];
    }

    // Filtro por valor
    if ($filtros['valor_min'] !== null) {
        $whereConditions[] = "(c.valor_total >= :valor_min OR cp.valor_total >= :valor_min)";
        $params[':valor_min'] = $filtros['valor_min'];
    }

    if ($filtros['valor_max'] !== null) {
        $whereConditions[] = "(c.valor_total <= :valor_max OR cp.valor_total <= :valor_max)";
        $params[':valor_max'] = $filtros['valor_max'];
    }

    // Filtro por data da compra
    if (!empty($filtros['data_inicio'])) {
        $whereConditions[] = "(DATE(c.data) >= :data_inicio OR DATE(cp.data_compra) >= :data_inicio)";
        $params[':data_inicio'] = $filtros['data_inicio'];
    }

    if (!empty($filtros['data_fim'])) {
        $whereConditions[] = "(DATE(c.data) <= :data_fim OR DATE(cp.data_compra) <= :data_fim)";
        $params[':data_fim'] = $filtros['data_fim'];
    }

    // Filtro por data de pagamento
    if (!empty($filtros['data_pagamento_inicio'])) {
        $whereConditions[] = "DATE(cp.data_pagamento) >= :data_pagamento_inicio";
        $params[':data_pagamento_inicio'] = $filtros['data_pagamento_inicio'];
    }

    if (!empty($filtros['data_pagamento_fim'])) {
        $whereConditions[] = "DATE(cp.data_pagamento) <= :data_pagamento_fim";
        $params[':data_pagamento_fim'] = $filtros['data_pagamento_fim'];
    }

    $whereClause = "WHERE " . implode(" AND ", $whereConditions);

    // Consulta para contar total de registros (incluindo contas diretas)
    $sqlCount = "SELECT COUNT(*) as total FROM contas_pagar cp
                 LEFT JOIN compras c ON cp.compra_id = c.id 
                 $whereClause";
    $stmtCount = $pdo->prepare($sqlCount);
    foreach ($params as $key => $value) {
        $stmtCount->bindValue($key, $value);
    }
    $stmtCount->execute();
    $totalContas = $stmtCount->fetch(PDO::FETCH_ASSOC)['total'];
    $totalPaginas = max(1, ceil($totalContas / $itensPorPagina)); // Evita divisão por zero

    $sql = "SELECT cp.id, cp.status_pagamento, cp.data_pagamento, cp.observacao_pagamento,
               cp.tipo_despesa, cp.informacoes_adicionais, cp.comprovante_pagamento as cp_comprovante,
               COALESCE(c.id, 0) as compra_id,
               COALESCE(c.numero_nf, cp.numero_nf) as numero_nf,
               COALESCE(c.fornecedor, cp.fornecedor_nome) as fornecedor,
               COALESCE(c.valor_total, cp.valor_total) as valor_total,
               COALESCE(c.data, cp.data_compra) as data,
               c.link_pagamento, 
               COALESCE(c.comprovante_pagamento, cp.comprovante_pagamento) as comprovante_pagamento,
               COALESCE(c.observacao, cp.observacao) as observacao,
               c.frete,
               CASE WHEN cp.compra_id IS NULL THEN 'Conta Direta' ELSE 'Compra' END as tipo_origem
        FROM contas_pagar cp
        LEFT JOIN compras c ON cp.compra_id = c.id
        $whereClause
        ORDER BY $orderBy $orderDirection
        LIMIT :limit OFFSET :offset";
    
    $stmt = $pdo->prepare($sql);
    
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    
    $stmt->bindValue(':limit', $itensPorPagina, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $contas_a_pagar = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error = "Erro na consulta: " . $e->getMessage();
    $contas_a_pagar = [];
    error_log("Erro na consulta principal: " . $e->getMessage());
}

// ===========================================
// CÁLCULOS DE TOTAIS
// ===========================================
try {
    $sqlTotalGeral = "SELECT SUM(COALESCE(c.valor_total, cp.valor_total)) AS total_geral 
                      FROM contas_pagar cp
                      LEFT JOIN compras c ON cp.compra_id = c.id
                      WHERE cp.status_pagamento IN ('Pendente', 'Pago', 'Concluido')";
    $stmtTotalGeral = $pdo->prepare($sqlTotalGeral);
    $stmtTotalGeral->execute();
    $totalGeralPagar = $stmtTotalGeral->fetch(PDO::FETCH_ASSOC)['total_geral'] ?? 0;
    
    $sqlTotalPendente = "SELECT SUM(COALESCE(c.valor_total, cp.valor_total)) AS total_pendente 
                         FROM contas_pagar cp
                         LEFT JOIN compras c ON cp.compra_id = c.id
                         WHERE cp.status_pagamento = 'Pendente'";
    $stmtTotalPendente = $pdo->prepare($sqlTotalPendente);
    $stmtTotalPendente->execute();
    $totalPendente = $stmtTotalPendente->fetch(PDO::FETCH_ASSOC)['total_pendente'] ?? 0;
    
    $sqlTotalPago = "SELECT SUM(COALESCE(c.valor_total, cp.valor_total)) AS total_pago 
                     FROM contas_pagar cp
                     LEFT JOIN compras c ON cp.compra_id = c.id
                     WHERE cp.status_pagamento IN ('Pago', 'Concluido')";
    $stmtTotalPago = $pdo->prepare($sqlTotalPago);
    $stmtTotalPago->execute();
    $totalPago = $stmtTotalPago->fetch(PDO::FETCH_ASSOC)['total_pago'] ?? 0;
    
} catch (PDOException $e) {
    $error = "Erro ao calcular os totais de contas a pagar: " . $e->getMessage();
    error_log("Erro ao calcular totais: " . $e->getMessage());
}

// ===========================================
// PROCESSAMENTO DE MENSAGENS DA URL
// ===========================================
if (isset($_GET['success'])) {
    $success = htmlspecialchars($_GET['success']);
}
if (isset($_GET['error'])) {
    $error = htmlspecialchars($_GET['error']);
}

// ===========================================
// INCLUSÃO DO TEMPLATE E INICIALIZAÇÃO DA PÁGINA
// ===========================================
include('includes/header_template.php');
startPage("Contas a Pagar - LicitaSis", "financeiro");

// ===========================================
// LOGGING DE CONCLUSÃO E INFORMAÇÕES DO SISTEMA
// ===========================================
error_log("✅ Sistema de Contas a Pagar com Pesquisa - Processamento PHP concluído");
error_log("📊 Total de contas carregadas: " . count($contas_a_pagar));
error_log("💰 Total geral a pagar: R$ " . number_format($totalGeralPagar, 2, ',', '.'));
error_log("🔍 Termo de pesquisa: " . ($filtros['search'] ?: 'Nenhum'));
error_log("🔐 Sistema de autenticação financeira: ATIVO");
error_log("📁 Diretório de uploads: " . (is_dir('uploads/comprovantes/') ? 'EXISTE' : 'NÃO EXISTE'));
error_log("👤 Usuário logado: " . ($_SESSION['user']['nome'] ?? 'N/A'));
error_log("🎯 Permissões ativas: " . ($permissionManager ? 'Sistema completo' : 'Sistema básico'));
?>
<style>
/* ===========================================
   VARIÁVEIS CSS GLOBAIS
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
    --shadow: 0 2px 10px rgba(0,0,0,0.1);
    --shadow-hover: 0 4px 20px rgba(0,0,0,0.15);
    --radius: 8px;
    --radius-sm: 4px;
    --transition: all 0.3s ease;
    
    /* Cores específicas para status */
    --pendente-color: #fd7e14;
    --pago-color: #28a745;
    --concluido-color: #17a2b8;
}

/* ===========================================
   RESET E ESTILOS BASE
   =========================================== */
* {
    margin: 0; 
    padding: 0; 
    box-sizing: border-box;
}

body {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
    color: var(--dark-gray);
    min-height: 100vh;
    line-height: 1.6;
}

/* ===========================================
   LAYOUT PRINCIPAL
   =========================================== */
.payables-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 1rem;
}

.main-content {
    min-height: 100vh;
    padding: 2rem 0;
}

/* ===========================================
   HEADER DA PÁGINA
   =========================================== */
.page-header {
    background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-light) 100%);
    color: white;
    padding: 2rem;
    border-radius: var(--radius);
    margin-bottom: 2rem;
    text-align: center;
    position: relative;
    overflow: hidden;
}

.page-header::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -50%;
    width: 100%;
    height: 100%;
    background: rgba(255,255,255,0.1);
    border-radius: 50%;
    transform: rotate(45deg);
}

.page-header h1 {
    font-size: 2.2rem;
    font-weight: 700;
    margin-bottom: 0.5rem;
    color: white;
    text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
    position: relative;
    z-index: 1;
}

.page-header p {
    font-size: 1.1rem;
    opacity: 0.9;
    margin: 0;
    position: relative;
    z-index: 1;
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
    font-family: 'Courier New', monospace;
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
   MENSAGENS DE FEEDBACK
   =========================================== */
.alert {
    padding: 1rem 1.5rem;
    border-radius: var(--radius);
    margin-bottom: 1.5rem;
    font-weight: 500;
    display: flex;
    align-items: center;
    gap: 0.75rem;
    animation: slideInDown 0.3s ease;
}

.alert-danger {
    background: #f8d7da;
    color: #721c24;
    border: 1px solid #f5c6cb;
}

.alert-success {
    background: #d4edda;
    color: #155724;
    border: 1px solid #c3e6cb;
}

@keyframes slideInDown {
    from { transform: translateY(-20px); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
}

/* ===========================================
   BARRA DE CONTROLES
   =========================================== */
.controls-bar {
    background: white;
    padding: 1.5rem;
    border-radius: var(--radius);
    box-shadow: var(--shadow);
    margin-bottom: 2rem;
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
    align-items: center;
    justify-content: space-between;
}

.search-form {
    display: flex;
    gap: 1rem;
    align-items: center;
    flex: 1;
    min-width: 300px;
}

.search-input {
    flex: 1;
    padding: 0.875rem 1rem;
    border: 2px solid var(--border-color);
    border-radius: var(--radius);
    font-size: 1rem;
    transition: var(--transition);
    background-color: #f9f9f9;
}

.search-input:focus {
    outline: none;
    border-color: var(--secondary-color);
    box-shadow: 0 0 0 3px rgba(0, 191, 174, 0.1);
    background-color: white;
}

/* ===========================================
   BOTÕES
   =========================================== */
.btn {
    padding: 0.875rem 1.5rem;
    border: none;
    border-radius: var(--radius);
    font-size: 1rem;
    font-weight: 600;
    cursor: pointer;
    transition: var(--transition);
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    white-space: nowrap;
}

.btn-primary {
    background: var(--secondary-color);
    color: white;
}

.btn-primary:hover {
    background: var(--secondary-dark);
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 191, 174, 0.3);
}

.btn-secondary {
    background: var(--medium-gray);
    color: white;
}

.btn-secondary:hover {
    background: #5a6268;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(108, 117, 125, 0.3);
}

.btn-success {
    background: var(--success-color);
    color: white;
}

.btn-success:hover {
    background: #218838;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(40, 167, 69, 0.3);
}

.btn-warning {
    background: var(--warning-color);
    color: var(--dark-gray);
}

.btn-warning:hover {
    background: #e0a800;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(255, 193, 7, 0.3);
}

.btn-danger {
    background: var(--danger-color);
    color: white;
}

.btn-danger:hover {
    background: #c82333;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(220, 53, 69, 0.3);
}

.btn-novo-item {
    background: linear-gradient(135deg, var(--success-color) 0%, #20c997 100%);
    color: white;
    padding: 1rem 2rem;
    font-size: 1.1rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border-radius: var(--radius);
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 0.75rem;
    box-shadow: 0 6px 20px rgba(40, 167, 69, 0.3);
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
    border: none;
    cursor: pointer;
}

.btn-novo-item:hover {
    background: linear-gradient(135deg, #20c997 0%, var(--success-color) 100%);
    transform: translateY(-3px) scale(1.05);
    box-shadow: 0 10px 30px rgba(40, 167, 69, 0.4);
    text-decoration: none;
    color: white;
}

/* ===========================================
   INFORMAÇÕES DE RESULTADOS
   =========================================== */
.results-info {
    background: white;
    padding: 1rem 1.5rem;
    border-radius: var(--radius);
    box-shadow: var(--shadow);
    margin-bottom: 1.5rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 1rem;
}

.results-count {
    color: var(--medium-gray);
    font-weight: 500;
}

.results-count strong {
    color: var(--primary-color);
}

/* ===========================================
   TABELA
   =========================================== */
.table-container {
    background: white;
    border-radius: var(--radius);
    box-shadow: var(--shadow);
    overflow: hidden;
    margin-bottom: 2rem;
}

.table-responsive {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
}

table th, table td {
    padding: 0.875rem;
    text-align: left;
    border-bottom: 1px solid var(--border-color);
    vertical-align: middle;
}

table th {
    background: var(--secondary-color);
    color: white;
    font-weight: 600;
    position: sticky;
    top: 0;
    z-index: 10;
    font-size: 0.9rem;
}

table th a {
    color: white;
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    transition: var(--transition);
}

table th a:hover {
    color: #e6f3ff;
}

table tbody tr {
    transition: var(--transition);
}

table tbody tr:hover {
    background: var(--light-gray);
}

.nf-link {
    color: var(--secondary-color);
    text-decoration: none;
    font-weight: 600;
    transition: var(--transition);
    cursor: pointer;
}

.nf-link:hover {
    color: var(--primary-color);
    text-decoration: underline;
}

/* ===========================================
   SELECTS E STATUS
   =========================================== */
.status-select, .tipo-despesa-select {
    padding: 0.5rem 0.75rem;
    border-radius: var(--radius);
    border: 2px solid var(--border-color);
    font-size: 0.85rem;
    cursor: pointer;
    transition: var(--transition);
    background-color: #f9f9f9;
    font-weight: 500;
    min-width: 110px;
}

.status-select:hover, .status-select:focus,
.tipo-despesa-select:hover, .tipo-despesa-select:focus {
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

.status-badge {
    padding: 0.4rem 0.8rem;
    border-radius: var(--radius);
    font-size: 0.8rem;
    font-weight: 600;
    display: inline-block;
    text-align: center;
    min-width: 90px;
}

.status-badge.status-pendente {
    background: rgba(253, 126, 20, 0.1);
    color: var(--pendente-color);
    border: 1px solid rgba(253, 126, 20, 0.3);
}

.status-badge.status-pago {
    background: rgba(40, 167, 69, 0.1);
    color: var(--pago-color);
    border: 1px solid rgba(40, 167, 69, 0.3);
}

.status-badge.status-concluido {
    background: rgba(23, 162, 184, 0.1);
    color: var(--concluido-color);
    border: 1px solid rgba(23, 162, 184, 0.3);
}

.badge-secondary {
    font-size: 0.75rem;
    font-weight: 500;
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    padding: 0.3rem 0.6rem;
    border-radius: var(--radius);
}

/* Estilos para loading de selects */
.tipo-despesa-select:disabled,
.status-select:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    background-color: var(--light-gray);
}

/* ===========================================
   PAGINAÇÃO
   =========================================== */
.pagination-container {
    display: flex;
    justify-content: center;
    margin-top: 2rem;
}

.pagination {
    display: flex;
    list-style: none;
    padding: 0;
    margin: 0;
    gap: 0.25rem;
    align-items: center;
}

.pagination a, .pagination span {
    padding: 0.75rem 1rem;
    text-decoration: none;
    color: var(--medium-gray);
    border: 1px solid var(--border-color);
    border-radius: var(--radius);
    transition: var(--transition);
    font-weight: 500;
}

.pagination a:hover {
    background: var(--secondary-color);
    color: white;
    border-color: var(--secondary-color);
}

.pagination .current {
    background: var(--primary-color);
    color: white;
    border-color: var(--primary-color);
}

.pagination .disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

/* ===========================================
   ESTADO VAZIO
   =========================================== */
.empty-state {
    text-align: center;
    padding: 4rem 2rem;
    color: var(--medium-gray);
    background: white;
    border-radius: var(--radius);
    box-shadow: var(--shadow);
}

.empty-state i {
    font-size: 4rem;
    margin-bottom: 1.5rem;
    color: var(--border-color);
}

.empty-state h3 {
    color: var(--dark-gray);
    margin-bottom: 1rem;
}

/* ===========================================
   MODAIS BASE
   =========================================== */
.modal {
    display: none;
    position: fixed;
    z-index: 1000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.6);
    backdrop-filter: blur(5px);
    overflow-y: auto;
    animation: fadeIn 0.3s ease;
}

.modal-content {
    background: white;
    margin: 2rem auto;
    padding: 0;
    border-radius: var(--radius);
    width: 90%;
    max-width: 1000px;
    box-shadow: 0 20px 60px rgba(0,0,0,0.3);
    animation: slideInUp 0.3s ease;
    overflow: hidden;
}

.modal-header {
    background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
    color: white;
    padding: 1.5rem 2rem;
    position: relative;
}

.modal-header h3 {
    margin: 0;
    color: white;
    font-size: 1.4rem;
    font-weight: 600;
}

.modal-close {
    color: white;
    float: right;
    font-size: 2rem;
    font-weight: bold;
    cursor: pointer;
    transition: var(--transition);
    position: absolute;
    right: 1.5rem;
    top: 50%;
    transform: translateY(-50%);
    line-height: 1;
}

.modal-close:hover {
    transform: translateY(-50%) scale(1.1);
    color: #ffdddd;
}

.modal-body {
    padding: 2rem;
    max-height: 70vh;
    overflow-y: auto;
}

.modal-footer {
    padding: 1.5rem 2rem;
    background: var(--light-gray);
    border-top: 1px solid var(--border-color);
    display: flex;
    justify-content: flex-end;
    gap: 1rem;
    flex-wrap: wrap;
}

/* ===========================================
   SEÇÕES DE DETALHES
   =========================================== */
.detail-section {
    background: white;
    border: 1px solid var(--border-color);
    border-radius: var(--radius);
    overflow: hidden;
    margin-bottom: 1.5rem;
}

.detail-header {
    background: var(--light-gray);
    padding: 1rem 1.5rem;
    border-bottom: 1px solid var(--border-color);
    font-weight: 600;
    color: var(--primary-color);
    display: flex;
    align-items: center;
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
    margin: 5% auto;
    padding: 0;
    border-radius: var(--radius);
    box-shadow: 0 20px 60px rgba(0,0,0,0.4);
    width: 90%;
    max-width: 600px;
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

.password-input-group,
.payment-date-group,
.form-group {
    position: relative;
    margin-bottom: 1.5rem;
}

.password-input-group label,
.payment-date-group label,
.form-group label {
    font-weight: 600;
    color: var(--primary-color);
    margin-bottom: 0.5rem;
    display: block;
    font-size: 0.95rem;
}

.password-input-wrapper {
    position: relative;
}

.password-input,
.form-control {
    width: 100%;
    padding: 1rem;
    border: 2px solid var(--border-color);
    border-radius: var(--radius);
    font-size: 1rem;
    transition: var(--transition);
    background-color: #f9f9f9;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
}

.password-input {
    padding: 1rem 3rem 1rem 1rem;
    font-family: monospace;
    letter-spacing: 2px;
}

.password-input:focus,
.form-control:focus {
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

/* ===========================================
   MODAL DE EXCLUSÃO
   =========================================== */
.delete-modal {
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

.delete-modal-content {
    background-color: white;
    margin: 10% auto;
    padding: 0;
    border-radius: var(--radius);
    box-shadow: 0 20px 60px rgba(0,0,0,0.4);
    width: 90%;
    max-width: 500px;
    position: relative;
    animation: slideInUp 0.3s ease;
    overflow: hidden;
    border-top: 5px solid var(--danger-color);
}

.delete-modal-header {
    background: linear-gradient(135deg, var(--danger-color), #c82333);
    color: white;
    padding: 1.5rem 2rem;
    text-align: center;
    position: relative;
}

.delete-modal-header h3 {
    margin: 0;
    font-size: 1.3rem;
    font-weight: 700;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
}

.delete-modal-body {
    padding: 2rem;
}

.delete-warning {
    background: linear-gradient(135deg, #f8d7da, #f5c6cb);
    border: 2px solid var(--danger-color);
    border-radius: var(--radius);
    padding: 1rem;
    margin-bottom: 1.5rem;
    text-align: center;
    position: relative;
}

.delete-warning h4 {
    color: var(--danger-color);
    margin: 0.5rem 0;
    font-size: 1rem;
    font-weight: 600;
}

.delete-info {
    background: var(--light-gray);
    padding: 1rem;
    border-radius: var(--radius);
    margin: 1rem 0;
    border-left: 4px solid var(--warning-color);
}

.delete-info h5 {
    color: var(--warning-color);
    margin-bottom: 0.5rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.delete-info div {
    margin: 0.25rem 0;
    font-size: 0.9rem;
}

.delete-password-group {
    position: relative;
    margin-bottom: 1.5rem;
}

.delete-password-group label {
    font-weight: 600;
    color: var(--danger-color);
    margin-bottom: 0.5rem;
    display: block;
    font-size: 0.95rem;
}

.delete-password-wrapper {
    position: relative;
}

.delete-password-input {
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

.delete-password-input:focus {
    outline: none;
    border-color: var(--danger-color);
    box-shadow: 0 0 0 3px rgba(220, 53, 69, 0.1);
    background-color: white;
}

.delete-password-toggle {
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

.delete-password-toggle:hover {
    color: var(--danger-color);
}

.delete-error {
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

.delete-buttons {
    display: flex;
    gap: 1rem;
    justify-content: flex-end;
    margin-top: 1.5rem;
}

.btn-delete-cancel {
    background: var(--medium-gray);
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

.btn-delete-cancel:hover {
    background: #5a6268;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(108, 117, 125, 0.3);
}

.btn-delete-confirm {
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

.btn-delete-confirm:hover:not(:disabled) {
    background: #c82333;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(220, 53, 69, 0.3);
}

.btn-delete-confirm:disabled {
    background: var(--medium-gray);
    cursor: not-allowed;
    opacity: 0.6;
}

/* ===========================================
   FORMULÁRIOS
   =========================================== */
.form-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 1.5rem;
    margin-bottom: 1.5rem;
}

.form-group {
    margin-bottom: 1.5rem;
}

.form-group label {
    font-weight: 600;
    color: var(--primary-color);
    margin-bottom: 0.5rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.95rem;
}

.form-group label i {
    color: var(--secondary-color);
    width: 16px;
    text-align: center;
}

.form-control:focus {
    outline: none;
    border-color: var(--secondary-color);
    box-shadow: 0 0 0 3px rgba(0, 191, 174, 0.1);
    background-color: white;
}

.form-control[readonly] {
    background: var(--light-gray);
    color: var(--medium-gray);
    cursor: not-allowed;
}

/* Input groups */
.input-group {
    display: flex;
    width: 100%;
}

.input-group-prepend {
    display: flex;
}

.input-group-text {
    display: flex;
    align-items: center;
    white-space: nowrap;
    color: var(--dark-gray);
    background-color: var(--light-gray);
    border: 2px solid var(--border-color);
    border-right: none;
    border-radius: var(--radius) 0 0 var(--radius);
    padding: 0.875rem 1rem;
    font-weight: 600;
    font-size: 1rem;
}

.input-group .form-control {
    border-radius: 0 var(--radius) var(--radius) 0;
    border-left: none;
}

.input-group .form-control:focus {
    border-left: 2px solid var(--secondary-color);
}

.form-text {
    font-size: 0.85rem;
    color: var(--medium-gray);
    margin-top: 0.5rem;
    display: flex;
    align-items: center;
    gap: 0.25rem;
    font-style: italic;
}

/* Seções do modal */
.modal-section {
    margin-bottom: 2rem;
    padding: 1.5rem;
    background: var(--light-gray);
    border-radius: var(--radius);
    border-left: 4px solid var(--primary-color);
}

.modal-section h4 {
    color: var(--primary-color);
    margin-bottom: 1.5rem;
    font-size: 1.1rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding-bottom: 1rem;
    border-bottom: 2px solid var(--border-color);
}

/* ===========================================
   SISTEMA DE AUTOCOMPLETE DE FORNECEDORES
   =========================================== */
.fornecedor-autocomplete-container {
    position: relative;
    width: 100%;
}

.fornecedor-input {
    position: relative;
    z-index: 1;
}

.fornecedor-input:focus {
    border-color: var(--secondary-color) !important;
    box-shadow: 0 0 0 3px rgba(0, 191, 174, 0.1) !important;
}

.fornecedor-dropdown {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: white;
    border: 2px solid var(--secondary-color);
    border-top: none;
    border-radius: 0 0 var(--radius) var(--radius);
    box-shadow: 0 8px 24px rgba(0, 191, 174, 0.15);
    z-index: 1000;
    max-height: 400px;
    overflow: hidden;
    animation: slideDownIn 0.2s ease;
}
/* Melhorias visuais para o autocomplete de fornecedores */
.fornecedor-autocomplete-input.loading {
    background-image: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
    background-size: 200% 100%;
    animation: loading 1.5s infinite;
}

.fornecedor-autocomplete-input.success-state {
    border-color: var(--success-color);
    background: rgba(40, 167, 69, 0.05);
}

.fornecedor-autocomplete-input.error-state {
    border-color: var(--danger-color);
    background: rgba(220, 53, 69, 0.05);
}

.fornecedor-autocomplete-input.warning-state {
    border-color: var(--warning-color);
    background: rgba(255, 193, 7, 0.05);
}

/* Melhorias na estrutura do dropdown */
.suggestion-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.25rem;
}

.suggestion-details {
    display: flex;
    gap: 1rem;
    flex-wrap: wrap;
    margin-top: 0.25rem;
}

.tipo-badge {
    padding: 0.2rem 0.5rem;
    border-radius: var(--radius-sm);
    font-size: 0.7rem;
    font-weight: 600;
}

.tipo-badge.pj {
    background: rgba(0, 191, 174, 0.1);
    color: var(--secondary-color);
}

.tipo-badge.pf {
    background: rgba(45, 137, 62, 0.1);
    color: var(--primary-color);
}

.no-fornecedores {
    text-align: center;
    padding: 2rem 1rem;
    color: var(--medium-gray);
    font-style: italic;
}

.no-fornecedores .suggestion-main {
    font-size: 1.1rem;
    margin-bottom: 0.5rem;
}

@keyframes loading {
    0% { background-position: 200% 0; }
    100% { background-position: -200% 0; }
}

.dropdown-header {
    background: linear-gradient(135deg, var(--secondary-color), var(--primary-color));
    color: white;
    padding: 0.75rem 1rem;
    font-weight: 600;
    font-size: 0.9rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    justify-content: space-between;
}

.dropdown-header i {
    color: white;
}

.loading-text {
    font-size: 0.8rem;
    opacity: 0.9;
}

.dropdown-content {
    max-height: 320px;
    overflow-y: auto;
    border-bottom: 1px solid var(--border-color);
}

.dropdown-content::-webkit-scrollbar {
    width: 6px;
}

.dropdown-content::-webkit-scrollbar-track {
    background: var(--light-gray);
}

.dropdown-content::-webkit-scrollbar-thumb {
    background: var(--medium-gray);
    border-radius: 3px;
}

.fornecedor-option {
    padding: 1rem;
    border-bottom: 1px solid var(--border-color);
    cursor: pointer;
    transition: var(--transition);
    background: white;
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.fornecedor-option:hover {
    background: var(--light-gray);
    border-left: 4px solid var(--secondary-color);
    padding-left: calc(1rem - 4px);
}

.fornecedor-option:last-child {
    border-bottom: none;
}

.fornecedor-option.highlighted {
    background: rgba(0, 191, 174, 0.1);
    border-left: 4px solid var(--secondary-color);
    padding-left: calc(1rem - 4px);
}

.fornecedor-nome {
    font-weight: 600;
    color: var(--primary-color);
    font-size: 1rem;
}

.fornecedor-documento {
    font-size: 0.85rem;
    color: var(--medium-gray);
    font-family: 'Courier New', monospace;
}

.fornecedor-contato {
    font-size: 0.8rem;
    color: var(--medium-gray);
    display: flex;
    gap: 1rem;
    flex-wrap: wrap;
}

.dropdown-footer {
    background: var(--light-gray);
    padding: 0.5rem 1rem;
    border-top: 1px solid var(--border-color);
    text-align: center;
}

.dropdown-footer small {
    font-size: 0.8rem;
    color: var(--medium-gray);
}

.fornecedor-info-selected {
    margin-top: 1rem;
    animation: fadeInUp 0.3s ease;
}

.fornecedor-card {
    background: linear-gradient(135deg, rgba(40, 167, 69, 0.1), rgba(32, 201, 151, 0.1));
    border: 2px solid var(--success-color);
    border-radius: var(--radius);
    overflow: hidden;
}

.fornecedor-header {
    background: var(--success-color);
    color: white;
    padding: 0.75rem 1rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    justify-content: space-between;
}

.btn-limpar-fornecedor {
    background: none;
    border: none;
    color: white;
    cursor: pointer;
    padding: 0.25rem;
    border-radius: var(--radius-sm);
    transition: var(--transition);
    font-size: 0.9rem;
}

.btn-limpar-fornecedor:hover {
    background: rgba(255, 255, 255, 0.2);
    transform: scale(1.1);
}

.fornecedor-details {
    padding: 1rem;
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.fornecedor-details > div {
    font-size: 0.9rem;
    display: flex;
    gap: 0.5rem;
}

.fornecedor-details strong {
    color: var(--primary-color);
    min-width: 80px;
}

.no-fornecedores {
    padding: 2rem 1rem;
    text-align: center;
    color: var(--medium-gray);
    font-style: italic;
}

.no-fornecedores i {
    font-size: 2rem;
    margin-bottom: 1rem;
    color: var(--border-color);
    display: block;
}

/* Estados especiais do input */
.fornecedor-input.has-selection {
    border-color: var(--success-color);
    background: rgba(40, 167, 69, 0.05);
}

.fornecedor-input.loading {
    background-image: url('data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjAiIGhlaWdodD0iMjAiIHZpZXdCb3g9IjAgMCAyMCAyMCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KICA8Y2lyY2xlIGN4PSIxMCIgY3k9IjEwIiByPSI4IiBzdHJva2U9IiNjY2MiIHN0cm9rZS13aWR0aD0iMiIgZmlsbD0ibm9uZSIgc3Ryb2tlLWRhc2hhcnJheT0iMTAgNSI+CiAgICA8YW5pbWF0ZVRyYW5zZm9ybSBhdHRyaWJ1dGVOYW1lPSJ0cmFuc2Zvcm0iIHR5cGU9InJvdGF0ZSIgdmFsdWVzPSIwIDEwIDEwOzM2MCAxMCAxMCIgZHVyPSIxcyIgcmVwZWF0Q291bnQ9ImluZGVmaW5pdGUiLz4KICA8L2NpcmNsZT4KPC9zdmc+');
    background-repeat: no-repeat;
    background-position: right 12px center;
    background-size: 20px 20px;
}

/* ===========================================
   RESUMO DA CONTA
   =========================================== */
.resumo-conta {
    animation: fadeIn 0.3s ease;
    box-shadow: 0 4px 12px rgba(255, 193, 7, 0.2);
}

/* ===========================================
   INDICADORES VISUAIS PARA URGÊNCIA
   =========================================== */
.urgent-payment {
    background: rgba(220, 53, 69, 0.05);
    border-left: 4px solid var(--danger-color);
}

.due-soon {
    background: rgba(255, 193, 7, 0.05);
    border-left: 4px solid var(--warning-color);
}

/* ===========================================
   LOADING E SPINNERS
   =========================================== */
.loading-spinner {
    display: none;
    width: 20px;
    height: 20px;
    border: 2px solid transparent;
    border-top: 2px solid currentColor;
    border-radius: 50%;
    animation: spin 1s linear infinite;
}

.loading-overlay {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(255, 255, 255, 0.9);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 1000;
    border-radius: var(--radius);
}

.loading-overlay .spinner {
    width: 40px;
    height: 40px;
    border: 4px solid var(--border-color);
    border-top: 4px solid var(--secondary-color);
    border-radius: 50%;
    animation: spin 1s linear infinite;
}

/* ===========================================
   ANIMAÇÕES
   =========================================== */
@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

@keyframes slideInUp {
    from { transform: translateY(50px); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
}

@keyframes slideDownIn {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(30px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes shake {
    0%, 20%, 40%, 60%, 80% { transform: translateX(0); }
    10%, 30%, 50%, 70%, 90% { transform: translateX(-5px); }
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

.table-container, .controls-bar, .results-info, .summary-cards {
    animation: fadeInUp 0.6s ease forwards;
}

.table-container { animation-delay: 0.2s; }
.controls-bar { animation-delay: 0.05s; }
.results-info { animation-delay: 0.15s; }
.summary-cards { animation-delay: 0.1s; }

/* ===========================================
   SCROLLBARS PERSONALIZADAS
   =========================================== */
.table-responsive::-webkit-scrollbar, 
.modal-body::-webkit-scrollbar, 
.financial-auth-body::-webkit-scrollbar {
    width: 8px;
    height: 8px;
}

.table-responsive::-webkit-scrollbar-track, 
.modal-body::-webkit-scrollbar-track, 
.financial-auth-body::-webkit-scrollbar-track {
    background: var(--light-gray);
    border-radius: 4px;
}

.table-responsive::-webkit-scrollbar-thumb, 
.modal-body::-webkit-scrollbar-thumb, 
.financial-auth-body::-webkit-scrollbar-thumb {
    background: var(--medium-gray);
    border-radius: 4px;
}

.table-responsive::-webkit-scrollbar-thumb:hover, 
.modal-body::-webkit-scrollbar-thumb:hover, 
.financial-auth-body::-webkit-scrollbar-thumb:hover {
    background: var(--dark-gray);
}

/* ===========================================
   CLASSES UTILITÁRIAS
   =========================================== */
.text-center { text-align: center; }
.font-weight-bold { font-weight: 600; }

/* ===========================================
   RESPONSIVIDADE
   =========================================== */
@media (max-width: 1200px) {
    .payables-container {
        padding: 1.5rem 1rem;
    }
}

@media (max-width: 768px) {
    .page-header {
        padding: 1.5rem;
    }

    .page-header h1 {
        font-size: 1.8rem;
    }

    .summary-cards {
        grid-template-columns: repeat(2, 1fr);
        gap: 1rem;
    }

    .controls-bar {
        flex-direction: column;
        align-items: stretch;
    }

    .search-form {
        min-width: auto;
    }

    .results-info {
        flex-direction: column;
        text-align: center;
    }

    .modal-content {
        margin: 1rem;
        width: calc(100% - 2rem);
    }

    .modal-body {
        padding: 1.5rem;
    }

    .confirmation-buttons, .auth-buttons, .delete-buttons {
        flex-direction: column;
    }

    .confirmation-buttons button, .auth-buttons button, .delete-buttons button {
        width: 100%;
    }

    table th, table td {
        padding: 0.5rem 0.25rem;
        font-size: 0.8rem;
    }

    .delete-modal-content {
        margin: 10% auto;
        width: 95%;
    }

    .delete-modal-body {
        padding: 1.5rem;
    }

    .tipo-despesa-select,
    .status-select {
        min-width: 90px;
        font-size: 0.75rem;
        padding: 0.4rem 0.5rem;
    }

    .form-row {
        grid-template-columns: 1fr;
    }

    .fornecedor-dropdown {
        max-height: 300px;
    }
    
    .dropdown-content {
        max-height: 220px;
    }
    
    .fornecedor-option {
        padding: 0.75rem;
    }
    
    .fornecedor-details {
        padding: 0.75rem;
    }
    
    .fornecedor-contato {
        flex-direction: column;
        gap: 0.25rem;
    }
}

@media (max-width: 480px) {
    .page-header {
        padding: 1rem;
    }

    .page-header h1 {
        font-size: 1.5rem;
    }

    .summary-cards {
        grid-template-columns: 1fr;
    }

    .controls-bar, .results-info {
        padding: 1rem;
    }

    .btn {
        width: 100%;
        justify-content: center;
    }

    table th, table td {
        padding: 0.4rem 0.2rem;
        font-size: 0.75rem;
    }

    .tipo-despesa-select,
    .status-select {
        min-width: 80px;
        font-size: 0.7rem;
        padding: 0.3rem 0.4rem;
    }
}

/* ===========================================
   MODO ESCURO (OPCIONAL)
   =========================================== */
@media (prefers-color-scheme: dark) {
    .table-responsive::-webkit-scrollbar-track, 
    .modal-body::-webkit-scrollbar-track, 
    .financial-auth-body::-webkit-scrollbar-track {
        background: #2d3748;
    }

    .table-responsive::-webkit-scrollbar-thumb, 
    .modal-body::-webkit-scrollbar-thumb, 
    .financial-auth-body::-webkit-scrollbar-thumb {
        background: #4a5568;
    }
}

/* ===========================================
   PRINT STYLES
   =========================================== */
@media print {
    .controls-bar,
    .pagination-container,
    .btn,
    .modal {
        display: none !important;
    }

    .page-header {
        background: white !important;
        color: black !important;
        -webkit-print-color-adjust: exact;
    }

    .summary-card {
        box-shadow: none !important;
        border: 1px solid #ccc !important;
    }

    table {
        font-size: 12px !important;
    }
}

#conta_fornecedor_nome {
    pointer-events: auto !important;
    user-select: text !important;
    -webkit-user-select: text !important;
    position: relative !important;
    z-index: 1 !important;
}

.fornecedor-autocomplete-container {
    position: relative;
    z-index: 1;
}
</style>
<body>

<!-- Container principal com layout padrão do sistema -->
<div class="main-content">
    <div class="container payables-container">
    
    <!-- Header da página -->
    <div class="page-header">
        <h1><i class="fas fa-credit-card"></i> Contas a Pagar</h1>
        <p>Gerencie e acompanhe todas as contas pendentes de pagamento</p>
    </div>

    <!-- Cards de resumo -->
    <div class="summary-cards">
        <div class="summary-card total">
            <h4>Total Geral</h4>
            <div class="value">R$ <?php echo number_format($totalGeralPagar, 2, ',', '.'); ?></div>
            <i class="fas fa-calculator icon"></i>
        </div>
        <div class="summary-card pendente">
            <h4>Contas Pendentes</h4>
            <div class="value">R$ <?php echo number_format($totalPendente, 2, ',', '.'); ?></div>
            <i class="fas fa-clock icon"></i>
        </div>
        <div class="summary-card pago">
            <h4>Contas Pagas</h4>
            <div class="value">R$ <?php echo number_format($totalPago, 2, ',', '.'); ?></div>
            <i class="fas fa-check-circle icon"></i>
        </div>
    </div>

    <!-- Mensagens de feedback -->
    <?php if ($error): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle"></i>
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i>
            <?php echo htmlspecialchars($success); ?>
        </div>
    <?php endif; ?>

    <!-- Barra de controles com pesquisa -->
    <div class="controls-bar">
        <form class="search-form" action="contas_a_pagar.php" method="GET">
            <input type="text" 
                   name="search" 
                   class="search-input"
                   placeholder="Pesquisar por NF, Fornecedor, UASG ou valor..." 
                   value="<?php echo htmlspecialchars($filtros['search']); ?>"
                   autocomplete="off">
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-search"></i> Pesquisar
            </button>
            <?php if (!empty($filtros['search'])): ?>
                <a href="contas_a_pagar.php" class="btn btn-secondary">
                    <i class="fas fa-times"></i> Limpar
                </a>
            <?php endif; ?>
            
            <!-- Campos ocultos para manter ordenação -->
            <input type="hidden" name="order" value="<?php echo htmlspecialchars($orderBy); ?>">
            <input type="hidden" name="dir" value="<?php echo htmlspecialchars($orderDirection); ?>">
        </form>
        
        <?php if ($permissionManager->hasPagePermission('compras', 'create')): ?>
        <button type="button" class="btn-novo-item" onclick="showNewItemModal()" style="padding: 0.875rem 1.5rem; margin: 0;">
            <i class="fas fa-plus-circle"></i>
            <span>Nova Compra / Conta</span>
        </button>
        <?php endif; ?>
    </div>

    <!-- Informações de resultados -->
    <?php if ($totalContas > 0): ?>
        <div class="results-info">
            <div class="results-count">
                <?php 
                $filtrosAtivos = array_filter($filtros, function($value) {
                    return $value !== '' && $value !== null;
                });
                ?>
                
                <?php if (count($filtrosAtivos) > 0): ?>
                    Encontradas <strong><?php echo $totalContas; ?></strong> conta(s) a pagar 
                    com os filtros aplicados
                    <?php if ($filtros['search']): ?>
                        para "<strong><?php echo htmlspecialchars($filtros['search']); ?></strong>"
                    <?php endif; ?>
                <?php else: ?>
                    Total de <strong><?php echo $totalContas; ?></strong> conta(s) a pagar
                <?php endif; ?>
                
                <?php if ($totalPaginas > 1): ?>
                    - Página <strong><?php echo $paginaAtual; ?></strong> de <strong><?php echo $totalPaginas; ?></strong>
                <?php endif; ?>
            </div>
            
            <div class="results-details">
                Mostrando <?php echo (($paginaAtual - 1) * $itensPorPagina + 1); ?> a 
                <?php echo min($paginaAtual * $itensPorPagina, $totalContas); ?> de 
                <?php echo $totalContas; ?> resultados
                
                <?php if (count($filtrosAtivos) > 0): ?>
                    <br><small style="color: var(--medium-gray);">
                        <i class="fas fa-filter"></i>
                        <?php echo count($filtrosAtivos); ?> filtro(s) aplicado(s)
                        <button type="button" onclick="limparFiltros()" style="background: none; border: none; color: var(--secondary-color); cursor: pointer; text-decoration: underline; margin-left: 0.5rem;">
                            Limpar filtros
                        </button>
                    </small>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Tabela de contas a pagar -->
    <?php if (count($contas_a_pagar) > 0): ?>
        <div class="table-container">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['order' => 'c.numero_nf', 'dir' => ($orderBy == 'c.numero_nf' && $orderDirection == 'ASC') ? 'DESC' : 'ASC'])); ?>">
                                    <i class="fas fa-file-invoice"></i> NF 
                                    <?php if ($orderBy == 'c.numero_nf'): ?>
                                        <i class="fas fa-sort-<?php echo $orderDirection == 'ASC' ? 'up' : 'down'; ?>"></i>
                                    <?php endif; ?>
                                </a>
                            </th>
                            <th>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['order' => 'c.fornecedor', 'dir' => ($orderBy == 'c.fornecedor' && $orderDirection == 'ASC') ? 'DESC' : 'ASC'])); ?>">
                                    <i class="fas fa-building"></i> Fornecedor
                                    <?php if ($orderBy == 'c.fornecedor'): ?>
                                        <i class="fas fa-sort-<?php echo $orderDirection == 'ASC' ? 'up' : 'down'; ?>"></i>
                                    <?php endif; ?>
                                </a>
                            </th>
                            <th>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['order' => 'c.valor_total', 'dir' => ($orderBy == 'c.valor_total' && $orderDirection == 'ASC') ? 'DESC' : 'ASC'])); ?>">
                                    <i class="fas fa-dollar-sign"></i> Valor
                                    <?php if ($orderBy == 'c.valor_total'): ?>
                                        <i class="fas fa-sort-<?php echo $orderDirection == 'ASC' ? 'up' : 'down'; ?>"></i>
                                    <?php endif; ?>
                                </a>
                            </th>
                            <th>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['order' => 'c.data', 'dir' => ($orderBy == 'c.data' && $orderDirection == 'ASC') ? 'DESC' : 'ASC'])); ?>">
                                    <i class="fas fa-calendar"></i> Data
                                    <?php if ($orderBy == 'c.data'): ?>
                                        <i class="fas fa-sort-<?php echo $orderDirection == 'ASC' ? 'up' : 'down'; ?>"></i>
                                    <?php endif; ?>
                                </a>
                            </th>
                            <th>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['order' => 'cp.tipo_despesa', 'dir' => ($orderBy == 'cp.tipo_despesa' && $orderDirection == 'ASC') ? 'DESC' : 'ASC'])); ?>">
                                    <i class="fas fa-tags"></i> Tipo
                                    <?php if ($orderBy == 'cp.tipo_despesa'): ?>
                                        <i class="fas fa-sort-<?php echo $orderDirection == 'ASC' ? 'up' : 'down'; ?>"></i>
                                    <?php endif; ?>
                                </a>
                            </th>
                            <th>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['order' => 'cp.status_pagamento', 'dir' => ($orderBy == 'cp.status_pagamento' && $orderDirection == 'ASC') ? 'DESC' : 'ASC'])); ?>">
                                    <i class="fas fa-tasks"></i> Status
                                    <?php if ($orderBy == 'cp.status_pagamento'): ?>
                                        <i class="fas fa-sort-<?php echo $orderDirection == 'ASC' ? 'up' : 'down'; ?>"></i>
                                    <?php endif; ?>
                                </a>
                            </th>
                            <th><i class="fas fa-calendar-check"></i> Vencimento</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($contas_a_pagar as $conta): 
                            $dataCompra = new DateTime($conta['data']);
                            $hoje = new DateTime();
                            $diasAteVencimento = $hoje->diff($dataCompra)->days;
                            
                            $rowClass = '';
                            if ($conta['status_pagamento'] === 'Pendente' && $diasAteVencimento > 30) {
                                $rowClass = 'urgent-payment';
                            } elseif ($conta['status_pagamento'] === 'Pendente' && $diasAteVencimento > 15) {
                                $rowClass = 'due-soon';
                            }
                        ?>
                            <tr data-id="<?php echo $conta['id']; ?>" class="<?php echo $rowClass; ?>">
                                <td>
                                    <span class="nf-link" onclick="openModal(<?php echo $conta['id']; ?>)">
                                        <i class="fas fa-eye"></i>
                                        <?php echo htmlspecialchars($conta['numero_nf']); ?>
                                        <?php if ($conta['tipo_origem'] === 'Conta Direta'): ?>
                                            <br><small style="color: var(--info-color); font-size: 0.7rem;">
                                                <i class="fas fa-bolt"></i> Conta Direta
                                            </small>
                                        <?php endif; ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($conta['fornecedor']); ?></td>
                                <td class="font-weight-bold">R$ <?php echo number_format($conta['valor_total'], 2, ',', '.'); ?></td>
                                <td><?php echo $dataCompra->format('d/m/Y'); ?></td>
                                <td>
                                    <?php if ($permissionManager->hasPagePermission('financeiro', 'edit')): ?>
                                        <select class="tipo-despesa-select" data-id="<?php echo $conta['id']; ?>">
                                            <?php foreach ($tiposDespesa as $key => $value): ?>
                                                <option value="<?php echo htmlspecialchars($key); ?>" 
                                                        <?php if (($conta['tipo_despesa'] ?? 'Compras') === $key) echo 'selected'; ?>>
                                                    <?php echo htmlspecialchars($value); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php else: ?>
                                        <span class="badge-secondary" style="background-color: var(--info-color); color: white;">
                                            <i class="fas fa-tag"></i>
                                            <?php echo htmlspecialchars($tiposDespesa[$conta['tipo_despesa'] ?? 'Compras'] ?? 'Compras'); ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($permissionManager->hasPagePermission('financeiro', 'edit')): ?>
                                        <select class="status-select" 
                                                data-id="<?php echo $conta['id']; ?>" 
                                                data-nf="<?php echo htmlspecialchars($conta['numero_nf']); ?>"
                                                data-fornecedor="<?php echo htmlspecialchars($conta['fornecedor']); ?>"
                                                data-valor="<?php echo number_format($conta['valor_total'], 2, ',', '.'); ?>"
                                                data-data="<?php echo $dataCompra->format('d/m/Y'); ?>"
                                                data-status-atual="<?php echo $conta['status_pagamento']; ?>">
                                            <option value="Pendente" <?php if ($conta['status_pagamento'] === 'Pendente') echo 'selected'; ?>>Pendente</option>
                                            <option value="Pago" <?php if ($conta['status_pagamento'] === 'Pago') echo 'selected'; ?>>Pago</option>
                                            <option value="Concluido" <?php if ($conta['status_pagamento'] === 'Concluido') echo 'selected'; ?>>Concluído</option>
                                        </select>
                                    <?php else: ?>
                                        <span class="status-badge <?php echo 'status-' . strtolower($conta['status_pagamento']); ?>">
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
                                            $dataPagamento = new DateTime($conta['data_pagamento']);
                                            $hoje = new DateTime();
                                            $isToday = $dataPagamento->format('Y-m-d') === $hoje->format('Y-m-d');
                                            
                                            echo '<strong style="color: var(--success-color);">' . $dataPagamento->format('d/m/Y') . '</strong>';
                                            
                                            if ($isToday) {
                                                echo '<br><small style="color: var(--info-color); font-size: 0.7rem;"><i class="fas fa-clock"></i> Hoje</small>';
                                            }
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
        </div>

        <!-- Paginação -->
        <?php if ($totalPaginas > 1): ?>
            <div class="pagination-container">
                <div class="pagination">
                    <?php if ($paginaAtual > 1): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>">
                            <i class="fas fa-angle-double-left"></i> Primeira
                        </a>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $paginaAtual - 1])); ?>">
                            <i class="fas fa-angle-left"></i> Anterior
                        </a>
                    <?php else: ?>
                        <span class="disabled">
                            <i class="fas fa-angle-double-left"></i> Primeira
                        </span>
                        <span class="disabled">
                            <i class="fas fa-angle-left"></i> Anterior
                        </span>
                    <?php endif; ?>

                    <?php
                    $inicio = max(1, $paginaAtual - 2);
                    $fim = min($totalPaginas, $paginaAtual + 2);
                    
                    if ($inicio > 1): ?>
                        <span>...</span>
                    <?php endif;
                    
                    for ($i = $inicio; $i <= $fim; $i++): ?>
                        <?php if ($i == $paginaAtual): ?>
                            <span class="current"><?php echo $i; ?></span>
                        <?php else: ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endif; ?>
                    <?php endfor;
                    
                    if ($fim < $totalPaginas): ?>
                        <span>...</span>
                    <?php endif; ?>

                    <?php if ($paginaAtual < $totalPaginas): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $paginaAtual + 1])); ?>">
                            Próxima <i class="fas fa-angle-right"></i>
                        </a>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $totalPaginas])); ?>">
                            Última <i class="fas fa-angle-double-right"></i>
                        </a>
                    <?php else: ?>
                        <span class="disabled">
                            Próxima <i class="fas fa-angle-right"></i>
                        </span>
                        <span class="disabled">
                            Última <i class="fas fa-angle-double-right"></i>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

    <?php else: ?>
        <!-- Estado vazio -->
        <div class="empty-state">
            <i class="fas fa-credit-card"></i>
            <h3>Nenhuma conta a pagar encontrada</h3>
            <?php if (!empty($filtros['search'])): ?>
                <p>Não foram encontradas contas a pagar com os termos de busca "<strong><?php echo htmlspecialchars($filtros['search']); ?></strong>".</p>
                <a href="contas_a_pagar.php" class="btn btn-primary">
                    <i class="fas fa-list"></i> Ver Todas as Contas
                </a>
            <?php else: ?>
                <p>Parabéns! Não há contas pendentes de pagamento no momento.</p>
                <?php if ($permissionManager->hasPagePermission('compras', 'create')): ?>
                    <a href="cadastro_compras.php" class="btn btn-success">
                        <i class="fas fa-plus"></i> Registrar Nova Compra
                    </a>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>

</div>

<!-- Modal de Detalhes da Conta -->
<div id="contaModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="modalTitle"><i class="fas fa-credit-card"></i> Detalhes da Conta a Pagar</h3>
            <span class="modal-close" onclick="closeModal()">&times;</span>
        </div>
        <div class="modal-body">
            <div class="loading-spinner" style="text-align: center; padding: 3rem;">
                <div style="width: 40px; height: 40px; border: 3px solid var(--border-color); border-top: 3px solid var(--secondary-color); border-radius: 50%; animation: spin 1s linear infinite; margin: 0 auto;"></div>
                <p style="margin-top: 1rem; color: var(--medium-gray);">Carregando detalhes da conta...</p>
            </div>
        </div>
    </div>
</div>

<!-- Modal de Confirmação para Pagamento -->
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

<!-- Modal de Autenticação Financeira -->
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

            <div class="payment-date-group" id="paymentDateGroup" style="display: none;">
                <label for="paymentDate">
                    <i class="fas fa-calendar-alt"></i> Data do Pagamento
                </label>
                <input type="date" 
                       id="paymentDate" 
                       class="form-control">
                <small style="color: var(--medium-gray); font-size: 0.85rem; margin-top: 0.5rem; display: block;">
                    <i class="fas fa-info-circle"></i> Se não informada, será usada a data atual
                </small>
            </div>

            <div class="form-group" id="tipoDespesaGroup" style="display: none;">
                <label for="tipoDespesa">
                    <i class="fas fa-tags"></i> Tipo de Despesa
                </label>
                <select id="tipoDespesa" class="form-control">
                    <?php foreach ($tiposDespesa as $key => $value): ?>
                        <option value="<?php echo htmlspecialchars($key); ?>"><?php echo htmlspecialchars($value); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" id="informacoesAdicionaisGroup" style="display: none;">
                <label for="informacoesAdicionais">
                    <i class="fas fa-info-circle"></i> Informações Adicionais
                </label>
                <textarea id="informacoesAdicionais" 
                          class="form-control" 
                          rows="3" 
                          placeholder="Informações adicionais sobre o pagamento..."
                          style="resize: vertical;"></textarea>
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
        </div>
    </div>
</div>

<!-- Modal de Seleção de Tipo de Cadastro -->
<div id="newItemModal" class="modal" role="dialog" aria-modal="true">
    <div class="modal-content" style="max-width: 600px;">
        <div class="modal-header" style="background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));">
            <h3><i class="fas fa-plus-circle"></i> Selecione o Tipo de Cadastro</h3>
            <span class="modal-close" onclick="closeNewItemModal()">&times;</span>
        </div>
        <div class="modal-body" style="padding: 3rem 2rem;">
            <p style="text-align: center; font-size: 1.1rem; color: var(--dark-gray); margin-bottom: 2rem;">
                Escolha como deseja cadastrar a nova conta a pagar:
            </p>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; margin-bottom: 2rem;">
                <div style="background: linear-gradient(135deg, var(--light-gray) 0%, #e9ecef 100%); padding: 2rem; border-radius: var(--radius); border: 2px solid var(--border-color); text-align: center; cursor: pointer; transition: var(--transition);" onclick="goToCadastroCompras()" onmouseover="this.style.borderColor='var(--primary-color)'; this.style.transform='translateY(-5px)'" onmouseout="this.style.borderColor='var(--border-color)'; this.style.transform='translateY(0)'">
                    <i class="fas fa-shopping-cart" style="font-size: 3rem; color: var(--primary-color); margin-bottom: 1rem;"></i>
                    <h4 style="color: var(--primary-color); margin-bottom: 1rem;">Cadastro de Compras</h4>
                    <p style="font-size: 0.9rem; color: var(--medium-gray);">Cadastro completo com produtos, fornecedores e todas as informações detalhadas de compra</p>
                    <div style="margin-top: 1rem;">
                        <span style="background: var(--primary-color); color: white; padding: 0.5rem 1rem; border-radius: var(--radius-sm); font-size: 0.8rem;">
                            <i class="fas fa-check"></i> Completo
                        </span>
                    </div>
                </div>
                
                <div style="background: linear-gradient(135deg, var(--light-gray) 0%, #e9ecef 100%); padding: 2rem; border-radius: var(--radius); border: 2px solid var(--border-color); text-align: center; cursor: pointer; transition: var(--transition);" onclick="abrirModalContaDireta()" onmouseover="this.style.borderColor='var(--secondary-color)'; this.style.transform='translateY(-5px)'" onmouseout="this.style.borderColor='var(--border-color)'; this.style.transform='translateY(0)'">
                    <i class="fas fa-file-invoice-dollar" style="font-size: 3rem; color: var(--secondary-color); margin-bottom: 1rem;"></i>
                    <h4 style="color: var(--secondary-color); margin-bottom: 1rem;">Conta a Pagar Direta</h4>
                    <p style="font-size: 0.9rem; color: var(--medium-gray);">Cadastro direto e rápido apenas com informações básicas da conta a ser paga</p>
                    <div style="margin-top: 1rem;">
                        <span style="background: var(--secondary-color); color: white; padding: 0.5rem 1rem; border-radius: var(--radius-sm); font-size: 0.8rem;">
                            <i class="fas fa-bolt"></i> Rápido
                        </span>
                    </div>
                </div>
            </div>
            
            <div style="text-align: center;">
                <button type="button" class="btn btn-secondary" onclick="closeNewItemModal()" style="min-width: 120px;">
                    <i class="fas fa-times"></i> Cancelar
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal de Inclusão de Conta a Pagar Direta -->
<div id="contaDiretaModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="contaDiretaModalTitle">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="contaDiretaModalTitle"><i class="fas fa-file-invoice-dollar"></i> Nova Conta a Pagar Direta</h3>
            <span class="modal-close" onclick="fecharModalContaDireta()">&times;</span>
        </div>
        <div class="modal-body">
            <form id="contaDiretaForm" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="incluir_conta_direta" value="1">
                
                <div class="modal-section">
                    <h4><i class="fas fa-file-invoice-dollar"></i> Informações da Conta</h4>
                    
                    <!-- Fornecedor e NF -->
                    <div class="form-row">
                        <!-- Campo Fornecedor com Autocomplete -->
                        <div class="form-group">
                            <label for="<!-- Campo Fornecedor com Select -->
<div class="form-group">
    <label for="conta_fornecedor_select">
        <i class="fas fa-building"></i> Fornecedor *
    </label>
    <select id="conta_fornecedor_select" 
            name="fornecedor_nome" 
            class="form-control" 
            required
            onchange="handleFornecedorChange()">
        <option value="">Selecione um fornecedor...</option>
        <?php foreach ($fornecedoresSelect as $fornecedor): ?>
            <?php 
                $documento = '';
                if ($fornecedor['tipo_pessoa'] === 'PJ' && !empty($fornecedor['cnpj'])) {
                    $documento = ' - CNPJ: ' . $fornecedor['cnpj'];
                } elseif ($fornecedor['tipo_pessoa'] === 'PF' && !empty($fornecedor['cpf'])) {
                    $documento = ' - CPF: ' . $fornecedor['cpf'];
                }
            ?>
            <option value="<?php echo htmlspecialchars($fornecedor['nome']); ?>"
                    data-id="<?php echo $fornecedor['id']; ?>"
                    data-nome="<?php echo htmlspecialchars($fornecedor['nome']); ?>"
                    data-documento="<?php echo htmlspecialchars($documento); ?>"
                    data-tipo="<?php echo htmlspecialchars($fornecedor['tipo_pessoa'] ?? ''); ?>"
                    data-email="<?php echo htmlspecialchars($fornecedor['email'] ?? ''); ?>"
                    data-telefone="<?php echo htmlspecialchars($fornecedor['telefone'] ?? ''); ?>">
                <?php echo htmlspecialchars($fornecedor['nome'] . $documento); ?>
            </option>
        <?php endforeach; ?>
    </select>
    
    <!-- Campos ocultos para armazenar dados do fornecedor -->
    <input type="hidden" id="fornecedor_id_selecionado" name="fornecedor_id" value="">
    <input type="hidden" id="fornecedor_documento" name="fornecedor_documento" value="">
    <input type="hidden" id="fornecedor_tipo_pessoa" name="fornecedor_tipo_pessoa" value="">
    
    <small class="form-text">
        <i class="fas fa-lightbulb"></i>
        Selecione o fornecedor da lista de fornecedores cadastrados.
        <?php if (count($fornecedoresSelect) === 0): ?>
            <br><span style="color: var(--warning-color);">
                <i class="fas fa-exclamation-triangle"></i> 
                Nenhum fornecedor cadastrado. <a href="cadastro_fornecedores.php" target="_blank">Cadastre um fornecedor</a> primeiro.
            </span>
        <?php endif; ?>
    </small>
</div>

<!-- Informações do fornecedor selecionado -->
<div id="fornecedor-info-selected" class="fornecedor-info-selected" style="display: none; margin-top: 1rem;">
    <div class="fornecedor-card">
        <div class="fornecedor-header" style="background: var(--success-color); color: white; padding: 0.75rem 1rem; display: flex; align-items: center; gap: 0.5rem; justify-content: space-between;">
            <div>
                <i class="fas fa-check-circle"></i>
                <strong>Fornecedor Selecionado</strong>
            </div>
            <button type="button" class="btn-limpar-fornecedor" onclick="limparFornecedorSelecionado()" title="Limpar seleção" style="background: none; border: none; color: white; cursor: pointer; padding: 0.25rem; border-radius: var(--radius-sm); transition: var(--transition);">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="fornecedor-details" style="padding: 1rem; display: flex; flex-direction: column; gap: 0.5rem;">
            <div><strong>Nome:</strong> <span id="selected-nome">-</span></div>
            <div><strong>Documento:</strong> <span id="selected-documento">-</span></div>
            <div id="selected-contato" style="display: none;">
                <strong>Contato:</strong> 
                <span id="selected-email">-</span>
                <span id="selected-telefone-separator" style="display: none;"> | </span>
                <span id="selected-telefone">-</span>
            </div>
        </div>
    </div>
</div>
                            
                            <small class="form-text">
                                <i class="fas fa-lightbulb"></i>
                                Nome completo da empresa ou pessoa física fornecedora. 
                                <strong>Clique no campo ou digite para ver sugestões dos fornecedores cadastrados.</strong>
                            </small>
                        </div>
                                
                        <div class="form-group">
                            <label for="conta_numero_nf">
                                <i class="fas fa-file-invoice"></i> Número da NF *
                            </label>
                            <input type="text" 
                                   id="conta_numero_nf" 
                                   name="numero_nf" 
                                   class="form-control" 
                                   placeholder="Digite o número da nota fiscal..."
                                   required>
                            <small class="form-text">
                                Número da nota fiscal ou documento equivalente
                            </small>
                        </div>
                    </div>
                    
                    <!-- Valor e Empenho -->
                    <div class="form-row">
                        <div class="form-group">
                            <label for="conta_valor_total">
                                <i class="fas fa-dollar-sign"></i> Valor Total *
                            </label>
                            <div class="input-group">
                                <div class="input-group-prepend">
                                    <span class="input-group-text">R$</span>
                                </div>
                                <input type="number" 
                                       id="conta_valor_total"
                                       name="valor_total" 
                                       class="form-control valor-input" 
                                       step="0.01" 
                                       min="0.01" 
                                       required
                                       placeholder="0,00">
                            </div>
                            <small class="form-text">
                                Valor total a ser pago ao fornecedor
                            </small>
                        </div>
                    </div>
                    
                    <!-- Datas -->
                    <div class="form-row">
                        <div class="form-group">
                            <label for="conta_data_compra">
                                <i class="fas fa-calendar"></i> Data
                            </label>
                            <input type="date" 
                                   id="conta_data_compra" 
                                   name="data_compra" 
                                   class="form-control">
                            <small class="form-text">
                                Data em que a compra/serviço foi realizado (padrão: hoje)
                            </small>
                        </div>
                        
                        <div class="form-group">
                            <label for="conta_data_vencimento">
                                <i class="fas fa-calendar-times"></i> Data de Vencimento
                            </label>
                            <input type="date" 
                                   id="conta_data_vencimento" 
                                   name="data_vencimento" 
                                   class="form-control">
                            <small class="form-text">
                                Data limite para pagamento (opcional)
                            </small>
                        </div>
                    </div>
                    
                    <!-- Tipo de Despesa -->
                    <div class="form-group">
                        <label for="conta_tipo_despesa">
                            <i class="fas fa-tags"></i> Tipo de Despesa *
                        </label>
                        <select id="conta_tipo_despesa" name="tipo_despesa" class="form-control" required>
                            <option value="">Selecione o tipo de despesa</option>
                            <option value="Compras">Compras</option>
                            <option value="Servicos">Serviços</option>
                            <option value="Manutencao">Manutenção</option>
                            <option value="Consultoria">Consultoria</option>
                            <option value="Equipamentos">Equipamentos</option>
                            <option value="Material_Escritorio">Material de Escritório</option>
                            <option value="Limpeza">Limpeza</option>
                            <option value="Seguranca">Segurança</option>
                            <option value="Pro_Labore">Pró-Labore</option>
                            <option value="Outros">Outros</option>
                        </select>
                        <small class="form-text">
                            Classificação para organização e relatórios
                        </small>
                    </div>
                    
                    <!-- Observações -->
                    <div class="form-group">
                        <label for="conta_observacoes">
                            <i class="fas fa-comment-alt"></i> Observações
                        </label>
                        <textarea id="conta_observacoes" 
                                  name="observacao" 
                                  class="form-control" 
                                  rows="4" 
                                  placeholder="Informações adicionais sobre a conta, detalhes do serviço/produto, forma de pagamento, condições especiais, etc."
                                  style="resize: vertical;"></textarea>
                        <small class="form-text">
                            Detalhes adicionais que possam ajudar na identificação e pagamento desta conta
                        </small>
                    </div>
                    
                    <!-- Resumo Visual -->
                    <div class="resumo-conta" style="background: linear-gradient(135deg, #fff3cd, #ffeaa7); border: 2px solid var(--warning-color); border-radius: var(--radius); padding: 1.5rem; margin-top: 1.5rem; display: none;" id="resumoContaDireta">
                        <h5 style="color: var(--warning-color); margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem;">
                            <i class="fas fa-clipboard-list"></i> Resumo da Conta a Pagar
                        </h5>
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                            <div>
                                <strong>Fornecedor:</strong>
                                <div id="resumo-conta-fornecedor" style="color: var(--medium-gray);">-</div>
                            </div>
                            <div>
                                <strong>NF:</strong>
                                <div id="resumo-conta-nf" style="color: var(--medium-gray);">-</div>
                            </div>
                            <div>
                                <strong>Valor:</strong>
                                <div id="resumo-conta-valor" style="color: var(--danger-color); font-weight: 600;">-</div>
                            </div>
                            <div>
                                <strong>Tipo:</strong>
                                <div id="resumo-conta-tipo" style="color: var(--medium-gray);">-</div>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button type="submit" form="contaDiretaForm" class="btn btn-success" id="salvarContaDiretaBtn">
                <i class="fas fa-save"></i> Salvar Conta a Pagar
            </button>
            <button type="button" class="btn btn-secondary" onclick="fecharModalContaDireta()">
                <i class="fas fa-times"></i> Cancelar
            </button>
        </div>
    </div>
</div>

<!-- Modal de Exclusão Simplificado -->
<div id="deleteModal" class="delete-modal" role="dialog" aria-modal="true">
    <div class="delete-modal-content">
        <div class="delete-modal-header">
            <h3>
                <i class="fas fa-exclamation-triangle"></i>
                Confirmar Exclusão da Conta
            </h3>
        </div>
        <div class="delete-modal-body">
            <div class="delete-warning">
                <h4>⚠️ Ação Irreversível</h4>
                <p>Esta conta será <strong>permanentemente excluída</strong> do sistema.</p>
            </div>
            
            <div class="delete-info">
                <h5>
                    <i class="fas fa-file-invoice"></i> Conta a ser excluída:
                </h5>
                <div><strong>NF:</strong> <span id="deleteNF">-</span></div>
                <div><strong>Fornecedor:</strong> <span id="deleteFornecedor">-</span></div>
                <div><strong>Valor:</strong> <span id="deleteValor">-</span></div>
            </div>
            
            <div class="delete-password-group">
                <label for="deletePassword">
                    <i class="fas fa-key"></i> Senha do Setor Financeiro *
                </label>
                <div class="delete-password-wrapper">
                    <input type="password" 
                           id="deletePassword" 
                           class="delete-password-input"
                           placeholder="Digite a senha para autorizar a exclusão"
                           autocomplete="off"
                           required>
                    <button type="button" class="delete-password-toggle" onclick="toggleDeletePassword()">
                        <i class="fas fa-eye" id="deletePasswordIcon"></i>
                    </button>
                </div>
                <small style="color: var(--medium-gray); font-size: 0.8rem; margin-top: 0.5rem; display: block;">
                    <i class="fas fa-info-circle"></i> Senha necessária para autorizar exclusões por segurança
                </small>
            </div>
            
            <div class="delete-error" id="deleteError">
                <i class="fas fa-exclamation-triangle"></i>
                <span id="deleteErrorMessage">Erro na validação.</span>
            </div>
            
            <div class="delete-buttons">
                <button type="button" class="btn-delete-cancel" onclick="closeDeleteModal()">
                    <i class="fas fa-times"></i> Cancelar
                </button>
                <button type="button" class="btn-delete-confirm" id="confirmDeleteBtn" onclick="confirmDelete()" disabled>
                    <span class="loading-spinner" id="deleteLoadingSpinner" style="display: none;"></span>
                    <i class="fas fa-trash" id="deleteConfirmIcon"></i>
                    <span id="deleteConfirmText">Confirmar Exclusão</span>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// ===========================================
// VARIÁVEIS GLOBAIS
// ===========================================
let currentSelectElement = null;
let currentContaData = {};
let currentContaId = null;
let advancedFiltersVisible = false;

// ===========================================
// FUNÇÃO PARA NOTIFICAÇÕES TOAST
// ===========================================
function showToast(message, type = 'info') {
    const toast = document.createElement('div');
    toast.className = `alert alert-${type === 'error' ? 'danger' : type === 'success' ? 'success' : 'info'}`;
    toast.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        z-index: 9999;
        min-width: 300px;
        padding: 1rem;
        border-radius: 8px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        animation: slideInRight 0.3s ease;
    `;
    
    const icon = type === 'error' ? 'exclamation-circle' : type === 'success' ? 'check-circle' : 'info-circle';
    toast.innerHTML = `<i class="fas fa-${icon}"></i> ${message}`;
    
    document.body.appendChild(toast);
    
    setTimeout(() => {
        try {
            if (toast && toast.parentNode && document.body.contains(toast)) {
                toast.style.animation = 'slideOutRight 0.3s ease';
                setTimeout(() => {
                    try {
                        if (toast && toast.parentNode && document.body.contains(toast)) {
                            toast.parentNode.removeChild(toast);
                        }
                    } catch (error) {
                        console.warn('Erro ao remover toast:', error);
                        if (toast && document.body.contains(toast)) {
                            document.body.removeChild(toast);
                        }
                    }
                }, 300);
            }
        } catch (error) {
            console.warn('Erro na animação do toast:', error);
        }
    }, 4000);
}

// ===========================================
// SISTEMA DE AUTOCOMPLETE DE FORNECEDORES SIMPLIFICADO
// ===========================================

function initFornecedorAutocomplete() {
    const fornecedorInput = document.getElementById('conta_fornecedor_nome');
    if (!fornecedorInput) return;
    
    const container = fornecedorInput.closest('.form-group');
    let suggestionsDiv = container.querySelector('.fornecedor-suggestions');
    
    if (!suggestionsDiv) {
        suggestionsDiv = document.createElement('div');
        suggestionsDiv.className = 'autocomplete-suggestions fornecedor-suggestions';
        suggestionsDiv.style.display = 'none';
        container.appendChild(suggestionsDiv);
    }

    let debounceTimer = null;

    fornecedorInput.addEventListener('input', function() {
        const query = this.value.trim();
        
        clearTimeout(debounceTimer);

        if (query.length < 2) {
            suggestionsDiv.style.display = 'none';
            limparFornecedorSelecionado();
            return;
        }

        debounceTimer = setTimeout(() => {
            fetchFornecedorSuggestions(query, suggestionsDiv);
        }, 300);
    });

    // Fecha sugestões ao clicar fora
    document.addEventListener('click', function(e) {
        if (!fornecedorInput.contains(e.target) && !suggestionsDiv.contains(e.target)) {
            suggestionsDiv.style.display = 'none';
        }
    });

    // Navegação por teclado
    fornecedorInput.addEventListener('keydown', function(e) {
        const suggestions = suggestionsDiv.querySelectorAll('.autocomplete-suggestion');
        let activeIndex = Array.from(suggestions).findIndex(s => s.classList.contains('active'));

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            if (activeIndex < suggestions.length - 1) {
                if (activeIndex >= 0) suggestions[activeIndex].classList.remove('active');
                suggestions[activeIndex + 1].classList.add('active');
            }
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            if (activeIndex > 0) {
                suggestions[activeIndex].classList.remove('active');
                suggestions[activeIndex - 1].classList.add('active');
            }
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (activeIndex >= 0) {
                selectFornecedorSuggestion(suggestions[activeIndex]);
            }
        } else if (e.key === 'Escape') {
            suggestionsDiv.style.display = 'none';
        }
    });
}

function fetchFornecedorSuggestions(query, suggestionsDiv) {
    suggestionsDiv.innerHTML = '<div class="autocomplete-suggestion">🔍 Buscando fornecedores...</div>';
    suggestionsDiv.style.display = 'block';

    const url = new URL(window.location.href);
    url.search = '';
    url.searchParams.set('search_fornecedores', '1');
    url.searchParams.set('termo', query);
    url.searchParams.set('limite', '20');

    fetch(url.toString())
        .then(response => {
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            return response.json();
        })
        .then(data => {
            if (data.success) {
                displayFornecedorSuggestions(data.fornecedores, suggestionsDiv);
            } else {
                suggestionsDiv.innerHTML = `<div class="autocomplete-suggestion">❌ ${data.error}</div>`;
            }
        })
        .catch(error => {
            console.error('Erro ao buscar fornecedores:', error);
            suggestionsDiv.innerHTML = '<div class="autocomplete-suggestion">❌ Erro ao conectar com o servidor</div>';
        });
}

function displayFornecedorSuggestions(fornecedores, suggestionsDiv) {
    if (!fornecedores || fornecedores.length === 0) {
        suggestionsDiv.innerHTML = '<div class="autocomplete-suggestion">📭 Nenhum fornecedor encontrado</div>';
        return;
    }

    const html = fornecedores.map(fornecedor => {
        return `
            <div class="autocomplete-suggestion fornecedor-suggestion" 
                 onclick="selectFornecedorSuggestion(this)"
                 data-id="${fornecedor.id}"
                 data-nome="${fornecedor.nome}"
                 data-documento="${fornecedor.documento_numero || ''}"
                 data-tipo="${fornecedor.tipo_pessoa || ''}">
                
                <div class="suggestion-main">${fornecedor.nome}</div>
                <div class="suggestion-secondary">${fornecedor.documento || 'Documento não informado'}</div>
                ${fornecedor.email || fornecedor.telefone ? 
                    `<div class="suggestion-details">
                        ${fornecedor.email ? `📧 ${fornecedor.email}` : ''}
                        ${fornecedor.telefone ? ` 📞 ${fornecedor.telefone}` : ''}
                    </div>` : ''
                }
            </div>
        `;
    }).join('');

    suggestionsDiv.innerHTML = html;
    suggestionsDiv.style.display = 'block';
}

function selectFornecedorSuggestion(element) {
    const fornecedorInput = document.getElementById('conta_fornecedor_nome');
    const fornecedorIdInput = document.getElementById('fornecedor_id_selecionado');
    const fornecedorDocumentoInput = document.getElementById('fornecedor_documento');
    const fornecedorTipoPessoaInput = document.getElementById('fornecedor_tipo_pessoa');
    const infoSelected = document.getElementById('fornecedor-info-selected');
    const suggestionsDiv = element.closest('.fornecedor-suggestions');

    // Pega os dados do elemento selecionado
    const id = element.getAttribute('data-id');
    const nome = element.getAttribute('data-nome');
    const documento = element.getAttribute('data-documento');
    const tipo = element.getAttribute('data-tipo');

    // Preenche os campos
    if (fornecedorInput) fornecedorInput.value = nome;
    if (fornecedorIdInput) fornecedorIdInput.value = id;
    if (fornecedorDocumentoInput) fornecedorDocumentoInput.value = documento;
    if (fornecedorTipoPessoaInput) fornecedorTipoPessoaInput.value = tipo;
    
    // Efeito visual de sucesso
    if (fornecedorInput) {
        fornecedorInput.classList.add('success-state');
        setTimeout(() => fornecedorInput.classList.remove('success-state'), 2000);
    }

    // Mostra informações do fornecedor selecionado
    if (infoSelected) {
        const selectedNome = document.getElementById('selected-nome');
        const selectedDocumento = document.getElementById('selected-documento');
        
        if (selectedNome) selectedNome.textContent = nome;
        if (selectedDocumento) selectedDocumento.textContent = documento || 'Não informado';
        
        infoSelected.style.display = 'block';
    }

    suggestionsDiv.style.display = 'none';
    atualizarResumoContaDireta();
    
    // Foca no próximo campo
    setTimeout(() => {
        const campoNF = document.getElementById('conta_numero_nf');
        if (campoNF) campoNF.focus();
    }, 100);
    
    showToast(`✅ Fornecedor selecionado: ${nome}`, 'success');
}

// Função para limpar fornecedor selecionado
window.limparFornecedorSelecionado = function() {
    const input = document.getElementById('conta_fornecedor_nome');
    const fornecedorIdInput = document.getElementById('fornecedor_id_selecionado');
    const fornecedorDocumentoInput = document.getElementById('fornecedor_documento');
    const fornecedorTipoPessoaInput = document.getElementById('fornecedor_tipo_pessoa');
    const infoSelected = document.getElementById('fornecedor-info-selected');
    
    if (input) {
        input.value = '';
        input.classList.remove('has-selection', 'success-state', 'warning-state', 'error-state');
        input.focus();
    }
    
    if (fornecedorIdInput) fornecedorIdInput.value = '';
    if (fornecedorDocumentoInput) fornecedorDocumentoInput.value = '';
    if (fornecedorTipoPessoaInput) fornecedorTipoPessoaInput.value = '';
    if (infoSelected) infoSelected.style.display = 'none';
    
    atualizarResumoContaDireta();
};

// ===========================================
// FUNÇÕES GLOBAIS DO MODAL PRINCIPAL
// ===========================================
window.openModal = function(id) {
    currentContaId = id;
    const modal = document.getElementById("contaModal");
    const modalBody = modal.querySelector('.modal-body');

    modal.style.display = "block";
    document.body.style.overflow = 'hidden';

    // Mostra loading
    modalBody.innerHTML = `
        <div class="loading-spinner" style="text-align: center; padding: 3rem;">
            <div style="width: 40px; height: 40px; border: 3px solid var(--border-color); border-top: 3px solid var(--secondary-color); border-radius: 50%; animation: spin 1s linear infinite; margin: 0 auto;"></div>
            <p style="margin-top: 1rem; color: var(--medium-gray);">Carregando detalhes da conta...</p>
        </div>
    `;

    fetch('contas_a_pagar.php?get_conta_id=' + id)
        .then(response => response.json())
        .then(data => {
            if(data.error){
                showToast('Erro: ' + data.error, 'error');
                closeModal();
                return;
            }
            renderContaDetails(data);
        })
        .catch(error => {
            console.error('Erro ao carregar dados:', error);
            showToast('Erro ao carregar os detalhes da conta a pagar.', 'error');
            closeModal();
        });
};

window.closeModal = function() {
    const modal = document.getElementById("contaModal");
    modal.style.display = "none";
    document.body.style.overflow = 'auto';
    currentContaId = null;
};

// ===========================================
// FUNÇÃO PARA RENDERIZAR DETALHES DA CONTA
// ===========================================
function renderContaDetails(conta) {
    const modalBody = document.querySelector('#contaModal .modal-body');
    
    // Prepara datas
    const dataCompra = conta.data ? new Date(conta.data).toLocaleDateString('pt-BR') : '';
    const dataCompraISO = conta.data ? conta.data : '';
    const dataVencimento = conta.data_vencimento ? conta.data_vencimento : '';
    const dataPagamento = conta.data_pagamento ? new Date(conta.data_pagamento).toLocaleDateString('pt-BR') : null;
    
    // Verifica se é conta direta (sem compra_id)
    const isContaDireta = !conta.compra_id || conta.tipo_origem === 'Conta Direta';
    
    // PERMISSÕES - Verificar se usuário pode editar
    const canEdit = <?php echo $permissionManager->hasPagePermission('financeiro', 'edit') ? 'true' : 'false'; ?>;
    const canDelete = <?php echo $permissionManager->hasPagePermission('financeiro', 'delete') ? 'true' : 'false'; ?>;
    
    modalBody.innerHTML = `
        <!-- Modo Visualização -->
        <div id="viewMode">
            <div class="detail-section">
                <div class="detail-header">
                    <i class="fas fa-shopping-cart"></i>
                    Informações da ${isContaDireta ? 'Conta Direta' : 'Compra'}
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
                            <div class="detail-value">${dataCompra || 'N/A'}</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Valor Total</div>
                            <div class="detail-value money">R$ ${parseFloat(conta.valor_total || 0).toLocaleString('pt-BR', {minimumFractionDigits: 2})}</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Data de Vencimento</div>
                            <div class="detail-value">${conta.data_vencimento ? new Date(conta.data_vencimento).toLocaleDateString('pt-BR') : 'N/A'}</div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Tipo de Despesa</div>
                            <div class="detail-value">${conta.tipo_despesa || 'Compras'}</div>
                        </div>
                        ${!isContaDireta ? `
                        <div class="detail-item">
                            <div class="detail-label">Frete</div>
                            <div class="detail-value">${conta.frete ? 'R$ ' + parseFloat(conta.frete).toLocaleString('pt-BR', {minimumFractionDigits: 2}) : 'N/A'}</div>
                        </div>
                        ` : ''}
                    </div>
                    
                    ${conta.observacao ? `
                    <div style="margin-top: 1.5rem;">
                        <div class="detail-label">Observação</div>
                        <div class="detail-value">${conta.observacao}</div>
                    </div>
                    ` : ''}
                    
                    ${conta.informacoes_adicionais ? `
                    <div style="margin-top: 1.5rem;">
                        <div class="detail-label">Informações Adicionais</div>
                        <div class="detail-value">${conta.informacoes_adicionais}</div>
                    </div>
                    ` : ''}
                </div>
            </div>

            ${conta.produtos && conta.produtos.length > 0 ? `
            <div class="detail-section">
                <div class="detail-header">
                    <i class="fas fa-box"></i>
                    Produtos da Compra (${conta.produtos.length})
                </div>
                <div class="detail-content">
                    ${conta.produtos.map((produto, index) => `
                        <div style="background: var(--light-gray); padding: 1rem; border-radius: var(--radius); margin-bottom: 1rem;">
                            <div style="font-weight: 600; color: var(--primary-color); margin-bottom: 0.5rem;">Produto ${index + 1}</div>
                            
                            <div class="detail-grid">
                                <div class="detail-item">
                                    <div class="detail-label">Nome do Produto</div>
                                    <div class="detail-value">${produto.nome || 'Nome não informado'}</div>
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
            ` : ''}

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
                                <span class="status-badge status-${conta.status_pagamento ? conta.status_pagamento.toLowerCase() : 'pendente'}">
                                    <i class="fas fa-${getStatusIcon(conta.status_pagamento)}"></i>
                                    ${conta.status_pagamento || 'Pendente'}
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
                </div>
            </div>

            <!-- FOOTER COM BOTÕES DE AÇÃO -->
            <div class="modal-footer" style="margin-top: 2rem; padding: 1.5rem; background: var(--light-gray); border-top: 1px solid var(--border-color); border-radius: 0 0 var(--radius) var(--radius); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
                <div style="display: flex; align-items: center; gap: 0.5rem;">
                    ${!isContaDireta ? `
                        <span class="badge" style="background: var(--info-color); color: white; padding: 0.5rem 1rem; font-size: 0.85rem; border-radius: var(--radius);">
                            <i class="fas fa-link"></i> Vinculada à Compra
                        </span>
                    ` : `
                        <span class="badge" style="background: var(--secondary-color); color: white; padding: 0.5rem 1rem; font-size: 0.85rem; border-radius: var(--radius);">
                            <i class="fas fa-bolt"></i> Conta Direta
                        </span>
                    `}
                </div>
                
                <div style="display: flex; gap: 1rem; align-items: center;">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">
                        <i class="fas fa-times"></i> Fechar
                    </button>
                    
                    ${isContaDireta && canEdit ? `
                        <button type="button" class="btn btn-warning" onclick="enableEditMode()" title="Editar conta">
                            <i class="fas fa-edit"></i> Editar
                        </button>
                    ` : ''}
                    
                    ${isContaDireta && canDelete ? `
                        <button type="button" class="btn btn-danger" onclick="deleteAccount()" title="Excluir conta">
                            <i class="fas fa-trash"></i> Excluir
                        </button>
                    ` : ''}
                </div>
            </div>
        </div>

        <!-- Modo Edição -->
        <div id="editMode" style="display: none;">
            <form id="editAccountForm" enctype="multipart/form-data">
                <input type="hidden" name="editar_conta_direta" value="1">
                <input type="hidden" name="conta_id" value="${conta.id}">
                <input type="hidden" name="last_modified" value="${conta.updated_at || conta.created_at}">
                
                <div class="detail-section">
                    <div class="detail-header">
                        <i class="fas fa-edit"></i>
                        Editando Conta Direta
                    </div>
                    <div class="detail-content">
                        <div class="form-row">
                            <div class="form-group">
                                <label>
                                    <i class="fas fa-building"></i> Nome do Fornecedor *
                                </label>
                                <input type="text" name="fornecedor_nome" class="form-control" 
                                       value="${conta.fornecedor || ''}" required>
                            </div>
                            
                            <div class="form-group">
                                <label>
                                    <i class="fas fa-file-invoice"></i> Número da NF *
                                </label>
                                <input type="text" name="numero_nf" class="form-control" 
                                       value="${conta.numero_nf || ''}" required>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label>
                                    <i class="fas fa-dollar-sign"></i> Valor Total *
                                </label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text">R$</span>
                                    </div>
                                    <input type="number" name="valor_total" class="form-control" 
                                           step="0.01" min="0.01" value="${conta.valor_total || ''}" required>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label>
                                    <i class="fas fa-calendar"></i> Data da Compra
                                </label>
                                <input type="date" name="data_compra" class="form-control" 
                                       value="${dataCompraISO}">
                            </div>
                            
                            <div class="form-group">
                                <label>
                                    <i class="fas fa-calendar-times"></i> Data de Vencimento
                                </label>
                                <input type="date" name="data_vencimento" class="form-control" 
                                       value="${dataVencimento}">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label>
                                <i class="fas fa-tags"></i> Tipo de Despesa *
                            </label>
                            <select name="tipo_despesa" class="form-control" required>
                                <option value="Compras" ${(conta.tipo_despesa || 'Compras') === 'Compras' ? 'selected' : ''}>Compras</option>
                                <option value="Servicos" ${conta.tipo_despesa === 'Servicos' ? 'selected' : ''}>Serviços</option>
                                <option value="Manutencao" ${conta.tipo_despesa === 'Manutencao' ? 'selected' : ''}>Manutenção</option>
                                <option value="Consultoria" ${conta.tipo_despesa === 'Consultoria' ? 'selected' : ''}>Consultoria</option>
                                <option value="Equipamentos" ${conta.tipo_despesa === 'Equipamentos' ? 'selected' : ''}>Equipamentos</option>
                                <option value="Material_Escritorio" ${conta.tipo_despesa === 'Material_Escritorio' ? 'selected' : ''}>Material de Escritório</option>
                                <option value="Limpeza" ${conta.tipo_despesa === 'Limpeza' ? 'selected' : ''}>Limpeza</option>
                                <option value="Seguranca" ${conta.tipo_despesa === 'Seguranca' ? 'selected' : ''}>Segurança</option>
                                <option value="Pro_Labore" ${conta.tipo_despesa === 'Pro_Labore' ? 'selected' : ''}>Pró-Labore</option>
                                <option value="Outros" ${conta.tipo_despesa === 'Outros' ? 'selected' : ''}>Outros</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>
                                <i class="fas fa-comment-alt"></i> Observações
                            </label>
                            <textarea name="observacao" class="form-control" rows="3" 
                                      placeholder="Informações sobre a conta...">${conta.observacao || ''}</textarea>
                        </div>
                        
                        <div class="form-group">
                            <label>
                                <i class="fas fa-info-circle"></i> Informações Adicionais
                            </label>
                            <textarea name="informacoes_adicionais" class="form-control" rows="3" 
                                      placeholder="Informações adicionais...">${conta.informacoes_adicionais || ''}</textarea>
                        </div>
                    </div>
                </div>
                
                <!-- FOOTER COM BOTÕES DE AÇÃO - MODO EDIÇÃO -->
                <div class="modal-footer" style="margin-top: 2rem; padding: 1.5rem; background: var(--light-gray); border-top: 1px solid var(--border-color); border-radius: 0 0 var(--radius) var(--radius); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
                    <div style="display: flex; align-items: center; gap: 0.5rem;">
                        <span class="badge" style="background: var(--warning-color); color: var(--dark-gray); padding: 0.5rem 1rem; font-size: 0.85rem; border-radius: var(--radius);">
                            <i class="fas fa-edit"></i> Modo Edição
                        </span>
                    </div>
                    
                    <div style="display: flex; gap: 1rem; align-items: center;">
                        <button type="button" class="btn btn-secondary" onclick="cancelEdit()">
                            <i class="fas fa-times"></i> Cancelar
                        </button>
                        
                        <button type="submit" class="btn btn-success" id="saveAccountBtn">
                            <i class="fas fa-save"></i> Salvar Alterações
                        </button>
                    </div>
                </div>
            </form>
        </div>
    `;
    
    // Adiciona event listener para o formulário de edição
    const editForm = document.getElementById('editAccountForm');
    if (editForm) {
        editForm.addEventListener('submit', handleEditSubmit);
    }
}

function getStatusIcon(status) {
    const icons = {
        'Pendente': 'clock',
        'Pago': 'check-circle',
        'Concluido': 'check-double'
    };
    return icons[status] || 'tag';
}

// ===========================================
// FUNÇÕES DE EDIÇÃO
// ===========================================
function enableEditMode() {
    document.getElementById('viewMode').style.display = 'none';
    document.getElementById('editMode').style.display = 'block';
    
    // Atualiza título do modal
    document.querySelector('#contaModal .modal-header h3').innerHTML = 
        '<i class="fas fa-edit"></i> Editar Conta a Pagar';
}

function cancelEdit() {
    document.getElementById('editMode').style.display = 'none';
    document.getElementById('viewMode').style.display = 'block';
    
    // Restaura título do modal
    document.querySelector('#contaModal .modal-header h3').innerHTML = 
        '<i class="fas fa-credit-card"></i> Detalhes da Conta a Pagar';
}

function handleEditSubmit(event) {
    event.preventDefault();
    
    const form = event.target;
    const formData = new FormData(form);
    const saveBtn = document.getElementById('saveAccountBtn');
    
    // Validações básicas
    const fornecedor = formData.get('fornecedor_nome').trim();
    const numeroNf = formData.get('numero_nf').trim();
    const valorTotal = formData.get('valor_total');
    
    if (!fornecedor) {
        showToast('Nome do fornecedor é obrigatório', 'error');
        return;
    }
    
    if (!numeroNf) {
        showToast('Número da NF é obrigatório', 'error');
        return;
    }
    
    if (!valorTotal || parseFloat(valorTotal) <= 0) {
        showToast('Valor total deve ser maior que zero', 'error');
        return;
    }
    
    // Loading
    saveBtn.disabled = true;
    saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...';
    
    fetch('contas_a_pagar.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast('Conta atualizada com sucesso!', 'success');
            closeModal();
            
            setTimeout(() => {
                window.location.reload();
            }, 1500);
        } else {
            throw new Error(data.error || 'Erro ao atualizar conta');
        }
    })
    .catch(error => {
        console.error('Erro:', error);
        showToast('Erro ao atualizar: ' + error.message, 'error');
    })
    .finally(() => {
        saveBtn.disabled = false;
        saveBtn.innerHTML = '<i class="fas fa-save"></i> Salvar Alterações';
    });
}

// ===========================================
// FUNÇÃO DE EXCLUSÃO
// ===========================================
function deleteAccount() {
    if (!currentContaId) {
        showToast('Erro: ID da conta não encontrado', 'error');
        return;
    }

    fetch('contas_a_pagar.php?get_conta_id=' + currentContaId)
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                showToast('Erro ao buscar dados da conta: ' + data.error, 'error');
                return;
            }

            document.getElementById('deleteNF').textContent = data.numero_nf || '-';
            document.getElementById('deleteFornecedor').textContent = data.fornecedor || '-';
            document.getElementById('deleteValor').textContent = data.valor_total ? 
                'R$ ' + parseFloat(data.valor_total).toLocaleString('pt-BR', {minimumFractionDigits: 2}) : '-';

            const modal = document.getElementById('deleteModal');
            modal.style.display = 'block';
            document.body.style.overflow = 'hidden';

            document.getElementById('deletePassword').value = '';
            document.getElementById('deleteError').style.display = 'none';
            document.getElementById('confirmDeleteBtn').disabled = true;

            setTimeout(() => {
                document.getElementById('deletePassword').focus();
            }, 300);
        })
        .catch(error => {
            console.error('Erro ao buscar dados da conta:', error);
            showToast('Erro ao carregar dados da conta', 'error');
        });
}

function closeDeleteModal() {
    const modal = document.getElementById('deleteModal');
    modal.style.display = 'none';
    document.body.style.overflow = 'auto';
    
    document.getElementById('deletePassword').value = '';
    document.getElementById('deleteError').style.display = 'none';
    document.getElementById('confirmDeleteBtn').disabled = true;
}

function toggleDeletePassword() {
    const passwordInput = document.getElementById('deletePassword');
    const toggleIcon = document.getElementById('deletePasswordIcon');
    
    if (passwordInput.type === 'password') {
        passwordInput.type = 'text';
        toggleIcon.className = 'fas fa-eye-slash';
    } else {
        passwordInput.type = 'password';
        toggleIcon.className = 'fas fa-eye';
    }
}

function showDeleteError(message) {
    const deleteError = document.getElementById('deleteError');
    const deleteErrorMessage = document.getElementById('deleteErrorMessage');
    
    deleteErrorMessage.textContent = message;
    deleteError.style.display = 'block';
    
    document.getElementById('deletePassword').value = '';
    document.getElementById('deletePassword').focus();
    document.getElementById('confirmDeleteBtn').disabled = true;
}

function confirmDelete() {
    const senha = document.getElementById('deletePassword').value.trim();
    const confirmBtn = document.getElementById('confirmDeleteBtn');
    const loadingSpinner = document.getElementById('deleteLoadingSpinner');
    const confirmIcon = document.getElementById('deleteConfirmIcon');
    const confirmText = document.getElementById('deleteConfirmText');
    
    if (!senha) {
        showDeleteError('Por favor, digite a senha do setor financeiro.');
        return;
    }

    const senhaCorreta = 'Licitasis@2025';
    if (senha !== senhaCorreta) {
        showDeleteError('Senha incorreta. A senha deve ser: Licitasis@2025');
        return;
    }

    confirmBtn.disabled = true;
    loadingSpinner.style.display = 'inline-block';
    confirmIcon.style.display = 'none';
    confirmText.textContent = 'Excluindo...';

    const formData = new FormData();
    formData.append('excluir_conta', '1');
    formData.append('conta_id', currentContaId);
    formData.append('financial_password', senha);

    fetch('contas_a_pagar.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            closeDeleteModal();
            closeModal();
            showToast('Conta excluída com sucesso!', 'success');
            
            setTimeout(() => {
                window.location.reload();
            }, 1500);
        } else {
            throw new Error(data.error || 'Erro ao excluir conta');
        }
    })
    .catch(error => {
        console.error('Erro:', error);
        showDeleteError(error.message);
    })
    .finally(() => {
        confirmBtn.disabled = false;
        loadingSpinner.style.display = 'none';
        confirmIcon.style.display = 'inline-block';
        confirmText.textContent = 'Confirmar Exclusão';
    });
}

// ===========================================
// FUNÇÕES DE STATUS E AUTENTICAÇÃO
// ===========================================
window.closeConfirmationModal = function() {
    if (currentSelectElement) {
        currentSelectElement.value = currentSelectElement.dataset.statusAtual || 'Pendente';
        updateSelectStyle(currentSelectElement);
    }
    
    document.getElementById('confirmationModal').style.display = 'none';
    document.body.style.overflow = 'auto';
    currentSelectElement = null;
    currentContaData = {};
};

window.handleStatusConfirmation = function() {
    if (!currentContaData.novoStatus) return;
    
    const novoStatus = currentContaData.novoStatus;
    
    if (novoStatus === 'Pago' || novoStatus === 'Concluido') {
        document.getElementById('confirmationModal').style.display = 'none';
        document.getElementById('financialAuthModal').style.display = 'block';
        
        setTimeout(() => {
            document.getElementById('financialPassword').focus();
        }, 300);
    } else {
        updateStatusDirect(currentContaData.id, novoStatus);
    }
};

window.closeFinancialAuthModal = function() {
    document.getElementById('financialAuthModal').style.display = 'none';
    document.getElementById('financialPassword').value = '';
    document.getElementById('paymentDate').value = '';
    document.getElementById('tipoDespesa').selectedIndex = 0;
    document.getElementById('informacoesAdicionais').value = '';
    document.getElementById('authError').style.display = 'none';
    document.getElementById('paymentDateGroup').style.display = 'none';
    document.getElementById('tipoDespesaGroup').style.display = 'none';
    document.getElementById('informacoesAdicionaisGroup').style.display = 'none';
    document.body.style.overflow = 'auto';
    
    if (currentSelectElement) {
        currentSelectElement.value = currentSelectElement.dataset.statusAtual || 'Pendente';
        updateSelectStyle(currentSelectElement);
    }
    
    currentSelectElement = null;
    currentContaData = {};
};

window.togglePasswordVisibility = function() {
    const passwordInput = document.getElementById('financialPassword');
    const toggleIcon = document.getElementById('passwordToggleIcon');
    
    if (passwordInput.type === 'password') {
        passwordInput.type = 'text';
        toggleIcon.className = 'fas fa-eye-slash';
    } else {
        passwordInput.type = 'password';
        toggleIcon.className = 'fas fa-eye';
    }
};

window.confirmFinancialAuth = function() {
    const senha = document.getElementById('financialPassword').value.trim();
    const dataPagamento = document.getElementById('paymentDate').value;
    const tipoDespesa = document.getElementById('tipoDespesa').value;
    const informacoesAdicionais = document.getElementById('informacoesAdicionais').value.trim();
    const confirmBtn = document.getElementById('confirmAuthBtn');
    const loadingSpinner = document.getElementById('authLoadingSpinner');
    const confirmIcon = document.getElementById('authConfirmIcon');
    const confirmText = document.getElementById('authConfirmText');
    const authError = document.getElementById('authError');
    
    if (!senha) {
        showAuthError('Por favor, digite a senha do setor financeiro.');
        return;
    }

    const senhaCorreta = 'Licitasis@2025';
    if (senha !== senhaCorreta) {
        showAuthError('Senha incorreta. A senha deve ser: Licitasis@2025 (case-sensitive)');
        return;
    }

    confirmBtn.disabled = true;
    loadingSpinner.style.display = 'inline-block';
    confirmIcon.style.display = 'none';
    confirmText.textContent = 'Verificando...';
    authError.style.display = 'none';

    updateStatusWithAuth(currentContaData.id, currentContaData.novoStatus, senha, dataPagamento, tipoDespesa, informacoesAdicionais);
};

function openConfirmationModal(selectElement, novoStatus) {
    currentSelectElement = selectElement;
    
    currentContaData = {
        id: selectElement.dataset.id,
        nf: selectElement.dataset.nf,
        fornecedor: selectElement.dataset.fornecedor,
        valor: selectElement.dataset.valor,
        data: selectElement.dataset.data,
        statusAtual: selectElement.dataset.statusAtual,
        novoStatus: novoStatus
    };

    document.getElementById('confirm-nf').textContent = currentContaData.nf;
    document.getElementById('confirm-fornecedor').textContent = currentContaData.fornecedor;
    document.getElementById('confirm-valor').textContent = currentContaData.valor;
    document.getElementById('confirm-status-atual').textContent = currentContaData.statusAtual;
    document.getElementById('confirm-novo-status').textContent = currentContaData.novoStatus;

    if (novoStatus === 'Pago' || novoStatus === 'Concluido') {
        document.getElementById('confirmationModal').style.display = 'none';
        document.getElementById('financialAuthModal').style.display = 'block';
        
        document.getElementById('paymentDateGroup').style.display = 'block';
        document.getElementById('tipoDespesaGroup').style.display = 'block';
        document.getElementById('informacoesAdicionaisGroup').style.display = 'block';
        
        document.getElementById('paymentDate').value = new Date().toISOString().split('T')[0];
        
        setTimeout(() => {
            document.getElementById('financialPassword').focus();
        }, 300);
    } else {
        document.getElementById('confirmationModal').style.display = 'block';
        document.body.style.overflow = 'hidden';
    }
}

function showAuthError(message) {
    const authError = document.getElementById('authError');
    const authErrorMessage = document.getElementById('authErrorMessage');
    
    authErrorMessage.textContent = message;
    authError.style.display = 'block';
    
    document.getElementById('financialPassword').value = '';
    document.getElementById('financialPassword').focus();
}

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

function updateStatusWithAuth(id, status, senha, dataPagamento, tipoDespesa, informacoesAdicionais) {
    const formData = new FormData();
    formData.append('update_status', '1');
    formData.append('id', id);
    formData.append('status_pagamento', status);
    formData.append('data_pagamento', status === 'Pendente' ? '' : dataPagamento);
    formData.append('tipo_despesa', tipoDespesa);
    formData.append('informacoes_adicionais', informacoesAdicionais);
    formData.append('observacao_pagamento', `Status alterado para ${status}`);
    formData.append('financial_password', senha);

    fetch('contas_a_pagar.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        resetAuthButton();
        
        if (data.success) {
            closeFinancialAuthModal();
            updateSelectValue(currentSelectElement, status);
            
            const row = document.querySelector(`tr[data-id='${id}']`);
            if (row && status !== 'Pendente') {
                const dataCells = row.querySelectorAll('td');
                const dataCell = dataCells[dataCells.length - 1];
                if (dataCell) {
                    const dataFormatadaBR = dataPagamento ? new Date(dataPagamento).toLocaleDateString('pt-BR') : new Date().toLocaleDateString('pt-BR');
                    const isToday = dataFormatadaBR === new Date().toLocaleDateString('pt-BR');
                    
                    dataCell.innerHTML = `<strong style="color: var(--success-color);">${dataFormatadaBR}</strong>`;
                    if (isToday) {
                        dataCell.innerHTML += '<br><small style="color: var(--info-color); font-size: 0.75rem;"><i class="fas fa-clock"></i> Hoje</small>';
                    }
                }
            }
            
            showSuccessMessage(`Status atualizado para ${status} com data ${dataPagamento ? new Date(dataPagamento).toLocaleDateString('pt-BR') : new Date().toLocaleDateString('pt-BR')}!`);
            
            setTimeout(() => {
                window.location.reload();
            }, 2000);
        } else {
            showAuthError(data.error || 'Erro ao processar solicitação');
        }
    })
    .catch(error => {
        resetAuthButton();
        console.error('Erro na comunicação:', error);
        showAuthError('Erro na comunicação com o servidor.');
    });
}

function updateStatusDirect(id, status) {
    fetch('contas_a_pagar.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({
            update_status: '1',
            id: id,
            status_pagamento: status,
            data_pagamento: '',
            observacao_pagamento: status === 'Pendente' ? 'Status alterado para Pendente' : ''
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            closeConfirmationModal();
            updateSelectValue(currentSelectElement, status);
            showSuccessMessage('Status de pagamento atualizado com sucesso!');
            
            setTimeout(() => {
                window.location.reload();
            }, 2000);
        } else {
            showToast('Erro ao atualizar status: ' + (data.error || 'Erro desconhecido'), 'error');
            if (currentSelectElement) {
                currentSelectElement.value = currentSelectElement.dataset.statusAtual || 'Pendente';
                updateSelectStyle(currentSelectElement);
            }
        }
    })
    .catch(error => {
        console.error('Erro na comunicação:', error);
        showToast('Erro na comunicação com o servidor.', 'error');
        if (currentSelectElement) {
            currentSelectElement.value = currentSelectElement.dataset.statusAtual || 'Pendente';
            updateSelectStyle(currentSelectElement);
        }
    });
}

function updateSelectValue(selectElement, newStatus) {
    if (selectElement) {
        selectElement.value = newStatus;
        selectElement.dataset.statusAtual = newStatus;
        updateSelectStyle(selectElement);
    }
}

function updateSelectStyle(selectElement) {
    if (!selectElement) return;
    
    selectElement.className = 'status-select';
    
    const status = selectElement.value.toLowerCase();
    selectElement.classList.add(`status-${status}`);
}

function showSuccessMessage(message) {
    showToast(message, 'success');
}

// ===========================================
// MODAL DE SELEÇÃO DE CADASTRO
// ===========================================
function showNewItemModal() {
    const modal = document.getElementById('newItemModal');
    if (modal) {
        modal.style.display = 'block';
        document.body.style.overflow = 'hidden';
    }
}

function closeNewItemModal() {
    const modal = document.getElementById('newItemModal');
    if (modal) {
        modal.style.display = 'none';
        document.body.style.overflow = 'auto';
    }
}

function goToCadastroCompras() {
    window.location.href = 'cadastro_compras.php';
}

// ===========================================
// MODAL DE CONTA DIRETA
// ===========================================
function abrirModalContaDireta() {
    console.log('📝 Abrindo modal de conta a pagar direta');
    
    closeNewItemModal();
    
    const modal = document.getElementById('contaDiretaModal');
    const form = document.getElementById('contaDiretaForm');
    
    if (!modal || !form) {
        console.error('❌ Modal ou formulário de conta direta não encontrado');
        showToast('Erro: Modal não encontrado', 'error');
        return;
    }
    
    form.reset();
    limparFornecedorSelecionado();
    
    const hoje = new Date().toISOString().split('T')[0];
    const campoDataCompra = document.getElementById('conta_data_compra');
    if (campoDataCompra) {
        campoDataCompra.value = hoje;
    }
    
    const resumo = document.getElementById('resumoContaDireta');
    if (resumo) {
        resumo.style.display = 'none';
    }
    
    modal.style.display = 'block';
    document.body.style.overflow = 'hidden';
    
    // Configurar autocomplete imediatamente
    initFornecedorAutocomplete();
    
    // Focar no campo após pequeno delay
    setTimeout(() => {
        const campoFornecedor = document.getElementById('conta_fornecedor_nome');
        if (campoFornecedor) {
            campoFornecedor.focus();
        }
    }, 100);
}

function fecharModalContaDireta() {
    const modal = document.getElementById('contaDiretaModal');
    if (modal) {
        modal.style.display = 'none';
        document.body.style.overflow = 'auto';
        
        const resumo = document.getElementById('resumoContaDireta');
        if (resumo) {
            resumo.style.display = 'none';
        }
        
        console.log('✅ Modal de conta direta fechado');
    }
}

function attachContaDiretaListeners() {
    const form = document.getElementById('contaDiretaForm');
    if (form && !form.hasAttribute('data-listener-attached')) {
        form.addEventListener('submit', processarContaDireta);
        form.setAttribute('data-listener-attached', 'true');
        console.log('✅ Event listener do formulário conta direta anexado');
    }
    
    // Configura autocomplete de fornecedor
    initFornecedorAutocomplete();
    
    // Event listeners para campos de resumo
    const camposResumo = ['conta_fornecedor_nome', 'conta_numero_nf', 'conta_valor_total', 'conta_tipo_despesa'];
    camposResumo.forEach(campoId => {
        const campo = document.getElementById(campoId);
        if (campo && !campo.hasAttribute('data-resume-listener')) {
            campo.addEventListener('input', atualizarResumoContaDireta);
            campo.addEventListener('change', atualizarResumoContaDireta);
            campo.setAttribute('data-resume-listener', 'true');
        }
    });
    
    // Formatação de valor
    const campoValor = document.getElementById('conta_valor_total');
    if (campoValor && !campoValor.hasAttribute('data-format-listener')) {
        campoValor.addEventListener('blur', function() {
            if (this.value) {
                const valor = parseFloat(this.value.replace(',', '.'));
                if (!isNaN(valor)) {
                    this.value = valor.toFixed(2);
                }
            }
        });
        campoValor.setAttribute('data-format-listener', 'true');
    }
    
    console.log('✅ Event listeners configurados');
}

function processarContaDireta(event) {
    event.preventDefault();
    
    console.log('🔄 Iniciando processamento da conta direta');
    
    const form = event.target;
    const formData = new FormData(form);
    
    console.log('📋 Dados do formulário:');
    for (let [key, value] of formData.entries()) {
        console.log(`  ${key}: "${value}"`);
    }
    
    const dados = {
        fornecedor: formData.get('fornecedor_nome')?.trim(),
        numeroNf: formData.get('numero_nf')?.trim(),
        tipoDespesa: formData.get('tipo_despesa')?.trim(),
        valorTotal: formData.get('valor_total')
    };
    
    console.log('🔍 Dados validados:', dados);
    
    if (!dados.fornecedor) {
        console.error('❌ Fornecedor vazio');
        showToast('Nome do fornecedor é obrigatório', 'error');
        return;
    }
    
    if (!dados.numeroNf) {
        console.error('❌ Número NF vazio');
        showToast('Número da NF é obrigatório', 'error');
        return;
    }
    
    if (!dados.tipoDespesa) {
        console.error('❌ Tipo despesa vazio');
        showToast('Tipo de despesa é obrigatório', 'error');
        return;
    }
    
    if (!dados.valorTotal || parseFloat(dados.valorTotal) <= 0) {
        console.error('❌ Valor inválido:', dados.valorTotal);
        showToast('Valor total é obrigatório e deve ser maior que zero', 'error');
        return;
    }
    
    const submitBtn = document.getElementById('salvarContaDiretaBtn');
    
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...';
    }
    
    console.log('📡 Enviando dados para o servidor...');
    
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => {
        console.log('📨 Resposta recebida:', response.status, response.statusText);
        
        const contentType = response.headers.get('content-type');
        console.log('📋 Content-Type:', contentType);
        
        if (!contentType || !contentType.includes('application/json')) {
            return response.text().then(text => {
                console.error('❌ Resposta não é JSON:', text.substring(0, 1000));
                throw new Error('Resposta do servidor não é JSON válido. Verifique se não há erros PHP.');
            });
        }
        
        return response.json();
    })
    .then(data => {
        console.log('✅ Dados JSON recebidos:', data);
        
        if (data.success) {
            console.log('🎉 Conta criada com sucesso:', data.id);
            showToast('Conta a pagar criada com sucesso!', 'success');
            
            fecharModalContaDireta();
            
            setTimeout(() => {
                window.location.reload();
            }, 1500);
            
        } else {
            console.error('❌ Erro retornado pelo servidor:', data.error);
            throw new Error(data.error || 'Erro desconhecido ao criar conta');
        }
    })
    .catch(error => {
        console.error('💥 Erro durante o processamento:', error);
        showToast('Erro ao criar conta: ' + error.message, 'error');
    })
    .finally(() => {
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fas fa-save"></i> Salvar Conta a Pagar';
        }
    });
}

function atualizarResumoContaDireta() {
    const fornecedor = document.getElementById('conta_fornecedor_nome')?.value || '';
    const numeroNf = document.getElementById('conta_numero_nf')?.value || '';
    const valor = document.getElementById('conta_valor_total')?.value || '';
    const tipo = document.getElementById('conta_tipo_despesa')?.value || '';
    
    const resumoDiv = document.getElementById('resumoContaDireta');
    
    if (!resumoDiv) return;
    
    const camposPreenchidos = [fornecedor, numeroNf, valor, tipo].filter(campo => campo && campo.trim()).length;
    
    if (camposPreenchidos >= 2) {
        resumoDiv.style.display = 'block';
        
        const resumoFornecedor = document.getElementById('resumo-conta-fornecedor');
        const resumoNf = document.getElementById('resumo-conta-nf');
        const resumoValor = document.getElementById('resumo-conta-valor');
        const resumoTipo = document.getElementById('resumo-conta-tipo');
        
        if (resumoFornecedor) resumoFornecedor.textContent = fornecedor || '-';
        if (resumoNf) resumoNf.textContent = numeroNf || '-';
        if (resumoValor) resumoValor.textContent = valor ? `R$ ${parseFloat(valor).toLocaleString('pt-BR', {minimumFractionDigits: 2})}` : '-';
        
        if (resumoTipo) {
            const tipoSelect = document.getElementById('conta_tipo_despesa');
            const tipoTexto = tipo && tipoSelect ? tipoSelect.options[tipoSelect.selectedIndex].text : '-';
            resumoTipo.textContent = tipoTexto;
        }
    } else {
        resumoDiv.style.display = 'none';
    }
}

// ===========================================
// FILTROS AVANÇADOS
// ===========================================
function toggleAdvancedFilters() {
    const filtersContent = document.getElementById('filtersContent');
    const toggleIcon = document.getElementById('toggleIcon');
    const toggleText = document.getElementById('toggleText');
    
    advancedFiltersVisible = !advancedFiltersVisible;
    
    if (advancedFiltersVisible) {
        filtersContent.classList.remove('collapsed');
        toggleIcon.className = 'fas fa-chevron-up';
        toggleText.textContent = 'Ocultar Filtros Avançados';
    } else {
        filtersContent.classList.add('collapsed');
        toggleIcon.className = 'fas fa-chevron-down';
        toggleText.textContent = 'Mostrar Filtros Avançados';
    }
}

function limparFiltros() {
    const url = new URL(window.location.href);
    const params = new URLSearchParams();
    
    if (url.searchParams.get('items_per_page')) {
        params.set('items_per_page', url.searchParams.get('items_per_page'));
    }
    
    window.location.href = url.pathname + (params.toString() ? '?' + params.toString() : '');
}

// ===========================================
// INICIALIZAÇÃO DO SISTEMA
// ===========================================
document.addEventListener('DOMContentLoaded', function() {
    console.log('🚀 Sistema de Contas a Pagar carregado com sucesso!');
    console.log('🔑 Senha do setor financeiro: Licitasis@2025');

    // Event listener para os selects de status (apenas se o usuário tem permissão de edição)
    <?php if ($permissionManager->hasPagePermission('financeiro', 'edit')): ?>
    const statusSelects = document.querySelectorAll('.status-select');
    if (statusSelects.length > 0) {
        console.log('✅ Permissões de edição detectadas, ativando event listeners para selects de status');
        
        statusSelects.forEach(select => {
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
    
    // Event listener para os selects de tipo de despesa
    const tipoSelects = document.querySelectorAll('.tipo-despesa-select');
    if (tipoSelects.length > 0) {
        console.log('✅ Ativando event listeners para selects de tipo de despesa');
        
        tipoSelects.forEach(select => {
            select.addEventListener('change', function() {
                const contaId = this.dataset.id;
                const novoTipo = this.value;
                const selectOriginal = this;
                const valorOriginal = selectOriginal.dataset.originalValue || selectOriginal.querySelector('option[selected]')?.value || 'Compras';
                
                if (confirm(`Deseja realmente alterar o tipo de despesa para "${this.options[this.selectedIndex].text}"?`)) {
                    selectOriginal.style.opacity = '0.6';
                    selectOriginal.disabled = true;
                    
                    fetch('contas_a_pagar.php', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                        body: new URLSearchParams({
                            update_tipo_despesa: '1',
                            conta_id: contaId,
                            tipo_despesa: novoTipo
                        })
                    })
                    .then(response => response.json())
                    .then(data => {
                        selectOriginal.style.opacity = '1';
                        selectOriginal.disabled = false;
                        
                        if (data.success) {
                            selectOriginal.dataset.originalValue = novoTipo;
                            showSuccessMessage('Tipo de despesa atualizado com sucesso!');
                            
                            selectOriginal.style.background = '#d4edda';
                            selectOriginal.style.borderColor = '#28a745';
                            setTimeout(() => {
                                selectOriginal.style.background = '';
                                selectOriginal.style.borderColor = '';
                            }, 2000);
                        } else {
                            showToast('Erro ao atualizar tipo de despesa: ' + (data.error || 'Erro desconhecido'), 'error');
                            selectOriginal.value = valorOriginal;
                        }
                    })
                    .catch(error => {
                        selectOriginal.style.opacity = '1';
                        selectOriginal.disabled = false;
                        console.error('Erro na comunicação:', error);
                        showToast('Erro na comunicação com o servidor.', 'error');
                        selectOriginal.value = valorOriginal;
                    });
                } else {
                    selectOriginal.value = valorOriginal;
                }
            });
            
            select.dataset.originalValue = select.value;
        });
    }
    <?php endif; ?>

    // Enter para confirmar senha no modal de autenticação
    const financialPasswordField = document.getElementById('financialPassword');
    if (financialPasswordField) {
        financialPasswordField.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                confirmFinancialAuth();
            }
        });
    }

    // Event listeners para modais - fechar com ESC
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            closeModal();
            closeConfirmationModal();
            closeFinancialAuthModal();
            fecharModalContaDireta();
            closeNewItemModal();
            closeDeleteModal();
        }
    });

    // Event listeners para fechar modais clicando fora
    window.addEventListener('click', function(event) {
        const modal = document.getElementById('contaModal');
        const confirmModal = document.getElementById('confirmationModal');
        const authModal = document.getElementById('financialAuthModal');
        const contaDiretaModal = document.getElementById('contaDiretaModal');
        const newItemModal = document.getElementById('newItemModal');
        const deleteModal = document.getElementById('deleteModal');
        
        if (event.target === modal) {
            closeModal();
        }
        if (event.target === confirmModal) {
            closeConfirmationModal();
        }
        if (event.target === authModal) {
            closeFinancialAuthModal();
        }
        if (event.target === contaDiretaModal) {
            fecharModalContaDireta();
        }
        if (event.target === newItemModal) {
            closeNewItemModal();
        }
        if (event.target === deleteModal) {
            closeDeleteModal();
        }
    });

    console.log('✅ Sistema de Contas a Pagar completamente inicializado');
});

// ===========================================
// CSS PARA ANIMAÇÕES
// ===========================================
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
    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }
    @keyframes fadeIn {
        from { opacity: 0; }
        to { opacity: 1; }
    }
    @keyframes slideInUp {
        from { transform: translateY(50px); opacity: 0; }
        to { transform: translateY(0); opacity: 1; }
    }
    
    /* Estilos simplificados para autocomplete de fornecedores */
    .fornecedor-suggestions {
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        background: white;
        border: 2px solid var(--border-color);
        border-top: none;
        border-radius: 0 0 var(--radius-sm) var(--radius-sm);
        max-height: 200px;
        overflow-y: auto;
        z-index: 1000;
        box-shadow: var(--shadow);
        display: none;
    }

    .fornecedor-suggestion {
        padding: 0.75rem 1rem;
        cursor: pointer;
        border-bottom: 1px solid var(--border-color);
        transition: var(--transition);
        display: flex;
        flex-direction: column;
        gap: 0.25rem;
    }

    .fornecedor-suggestion:hover,
    .fornecedor-suggestion.active {
        background: var(--light-gray);
        border-left: 4px solid var(--secondary-color);
    }

    .fornecedor-suggestion:last-child {
        border-bottom: none;
    }

    .suggestion-main {
        font-weight: 600;
        color: var(--primary-color);
    }

    .suggestion-secondary {
        font-size: 0.85rem;
        color: var(--medium-gray);
    }

    .suggestion-details {
        font-size: 0.8rem;
        color: var(--medium-gray);
    }
    
    /* Estados de input */
    .form-control.success-state {
        border-color: var(--success-color);
        background: rgba(40, 167, 69, 0.05);
    }

    .form-control.error-state {
        border-color: var(--danger-color);
        background: rgba(220, 53, 69, 0.05);
    }

    .form-control.warning-state {
        border-color: var(--warning-color);
        background: rgba(255, 193, 7, 0.05);
    }
`;
document.head.appendChild(style);

// ===========================================
// LOG FINAL DE INICIALIZAÇÃO
// ===========================================
console.log('🚀 Sistema de Contas a Pagar LicitaSis carregado:', {
    versao: '3.0 Simplificado',
    funcionalidades: [
        '✅ Autocomplete de fornecedores simplificado',
        '✅ Busca em tempo real com debounce',
        '✅ Navegação por teclado',
        '✅ Seleção de fornecedores',
        '✅ Validação de campos',
        '✅ Integração com modal de conta direta',
        '✅ Sistema de notificações',
        '✅ Gerenciamento de status',
        '✅ Autenticação financeira',
        '✅ Sistema de exclusão',
        '✅ Filtros avançados'
    ],
    principais_melhorias: [
        '🔧 Código simplificado e mais limpo',
        '🔧 Seguindo padrão do cadastro de empenho',
        '🔧 Menos dependências e complexidade',
        '🔧 Performance otimizada',
        '🔧 Manutenibilidade melhorada',
        '🔧 Error handling robusto'
    ],
    estado: 'Completamente funcional',
    compatibilidade: 'Todos os navegadores modernos'
});
</script>
</html>

<?php
// Log de conclusão bem-sucedida
error_log("LicitaSis - Página de Contas a Pagar carregada com sucesso");
error_log("LicitaSis - Permissões ativas: " . ($permissionManager ? 'Sistema completo' : 'Sistema básico'));
error_log("LicitaSis - Total de contas exibidas: " . count($contas_a_pagar));
error_log("LicitaSis - Total geral a pagar: R$ " . number_format($totalGeralPagar, 2, ',', '.'));
error_log("LicitaSis - Sistema de autenticação financeira: ATIVO");
?>