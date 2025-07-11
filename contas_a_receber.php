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

// Função de auditoria simplificada (caso o sistema de auditoria não esteja disponível)
if (!function_exists('logAudit')) {
    function logAudit($pdo, $userId, $action, $table, $recordId, $newData = null, $oldData = null) {
        try {
            // Verifica se a tabela de auditoria existe
            $checkTable = $pdo->query("SHOW TABLES LIKE 'audit_log'");
            if ($checkTable->rowCount() == 0) {
                // Se não existe tabela de auditoria, apenas loga no error_log
                error_log("AUDIT: User $userId performed $action on $table ID $recordId");
                return;
            }
            
            // Se existe, insere o log
            $sql = "INSERT INTO audit_log (user_id, action, table_name, record_id, old_data, new_data, created_at) 
                    VALUES (:user_id, :action, :table_name, :record_id, :old_data, :new_data, NOW())";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':user_id' => $userId,
                ':action' => $action,
                ':table_name' => $table,
                ':record_id' => $recordId,
                ':old_data' => $oldData ? json_encode($oldData) : null,
                ':new_data' => $newData ? json_encode($newData) : null
            ]);
        } catch (Exception $e) {
            // Se falhar, apenas loga no error_log
            error_log("AUDIT ERROR: " . $e->getMessage());
            error_log("AUDIT: User $userId performed $action on $table ID $recordId");
        }
    }
}

$permissionManager = initPermissions($pdo);

// Verifica se o usuário tem permissão para acessar contas a receber
$permissionManager->requirePermission('financeiro', 'view');

$isAdmin = isset($_SESSION['user']['permission']) && $_SESSION['user']['permission'] === 'Administrador';

$error = "";
$success = "";
$contas_a_receber = [];
$searchTerm = "";
$totalContas = 0;
$contasPorPagina = 20;
$paginaAtual = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($paginaAtual - 1) * $contasPorPagina;

require_once('db.php');

// ===========================================
// ENDPOINT DE TESTE DO SERVIDOR
// ===========================================
if (isset($_POST['test_server'])) {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    
    $response = [
        'success' => true,
        'message' => 'Servidor funcionando corretamente',
        'php_version' => PHP_VERSION,
        'timestamp' => date('Y-m-d H:i:s'),
        'memory_usage' => memory_get_usage(true),
        'post_data' => $_POST,
        'session_user' => $_SESSION['user']['id'] ?? 'N/A'
    ];
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit();
}

// Função para converter data brasileira para formato MySQL
function converterDataParaMySQL($data) {
    if (empty($data)) return null;
    
    // Se já está no formato Y-m-d, retorna como está
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) {
        return $data;
    }
    
    // Se está no formato d/m/Y, converte
    if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $data)) {
        $partes = explode('/', $data);
        return $partes[2] . '-' . $partes[1] . '-' . $partes[0];
    }
    
    return null;
}

// ===========================================
// PROCESSAMENTO AJAX - INCLUSÃO DE NOVA CONTA
// ===========================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['incluir_conta'])) {
    // Limpa qualquer output anterior
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    
    $response = ['success' => false];
    
    try {
        $pdo->beginTransaction();
        
        // Sanitiza e valida os dados
      $dados = [
    'nf' => 'CR-' . date('YmdHis'), // Gera número automático para conta a receber
    'cliente' => trim($_POST['cliente'] ?? ''),
    'cliente_uasg' => trim($_POST['cliente'] ?? ''), // Usa o mesmo campo para compatibilidade
    'valor_total' => !empty($_POST['valor_total']) ? str_replace(',', '.', $_POST['valor_total']) : null,
    'classificacao' => trim($_POST['tipo_receita'] ?? ''),
    'observacao' => trim($_POST['informacoes_adicionais'] ?? ''),
    'data' => $_POST['data'] ?? date('Y-m-d'),
    'data_vencimento' => $_POST['data_vencimento'] ?? null,
    'status_pagamento' => 'Não Recebido',
    'numero' => '',
    'pregao' => ''
];

// Validações obrigatórias
        if (empty($dados['cliente'])) {
            throw new Exception("Cliente é obrigatório.");
        }
        if (empty($dados['classificacao'])) {
            throw new Exception("Tipo de Receita é obrigatório.");
        }
        if (empty($dados['valor_total']) || $dados['valor_total'] <= 0) {
            throw new Exception("Valor Total é obrigatório e deve ser maior que zero.");
        }
        if (empty($dados['data_vencimento'])) {
            throw new Exception("Data de Vencimento é obrigatória.");
        }

        // Converte datas para formato MySQL
        $dados['data'] = converterDataParaMySQL($dados['data']);
        $dados['data_vencimento'] = converterDataParaMySQL($dados['data_vencimento']);


      // Insere a nova conta
$sql = "INSERT INTO vendas (nf, cliente, cliente_uasg, valor_total, classificacao, observacao, data, data_vencimento, status_pagamento, created_at) 
        VALUES (:nf, :cliente, :cliente_uasg, :valor_total, :classificacao, :observacao, :data, :data_vencimento, :status_pagamento, NOW())";
        $stmt = $pdo->prepare($sql);
        
       // Bind parameters
$stmt->bindValue(':nf', $dados['nf'], PDO::PARAM_STR);
$stmt->bindValue(':cliente', $dados['cliente'], PDO::PARAM_STR);
$stmt->bindValue(':cliente_uasg', $dados['cliente_uasg'], PDO::PARAM_STR);
$stmt->bindValue(':valor_total', $dados['valor_total'], PDO::PARAM_STR);
$stmt->bindValue(':classificacao', $dados['classificacao'], PDO::PARAM_STR);
$stmt->bindValue(':observacao', $dados['observacao'], PDO::PARAM_STR);
$stmt->bindValue(':data', $dados['data'], PDO::PARAM_STR);
$stmt->bindValue(':data_vencimento', $dados['data_vencimento'], PDO::PARAM_STR);
$stmt->bindValue(':status_pagamento', $dados['status_pagamento'], PDO::PARAM_STR);
        
        if (!$stmt->execute()) {
            $errorInfo = $stmt->errorInfo();
            throw new Exception("Erro ao inserir a conta: " . $errorInfo[2]);
        }

        $novoId = $pdo->lastInsertId();

        // Registra auditoria
        logAudit($pdo, $_SESSION['user']['id'], 'INSERT', 'vendas', $novoId, $dados);

        $pdo->commit();
        $response['success'] = true;
        $response['message'] = "Conta incluída com sucesso!";
        $response['id'] = $novoId;
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $response['error'] = $e->getMessage();
        error_log("Erro ao incluir conta: " . $e->getMessage());
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $response['error'] = "Erro de banco de dados: " . $e->getMessage();
        error_log("Erro PDO ao incluir conta: " . $e->getMessage());
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit();
}


// ===========================================
// PROCESSAMENTO AJAX - ATUALIZAÇÃO DE VENDA
// ===========================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_venda'])) {
    // Limpa qualquer output anterior
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    
    $response = ['success' => false];
    
    try {
        $pdo->beginTransaction();
        
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if (!$id) {
            throw new Exception("ID da venda inválido.");
        }

        // Busca dados antigos para auditoria
        $oldDataStmt = $pdo->prepare("SELECT * FROM vendas WHERE id = :id");
        $oldDataStmt->bindParam(':id', $id, PDO::PARAM_INT);
        $oldDataStmt->execute();
        $oldData = $oldDataStmt->fetch(PDO::FETCH_ASSOC);

        if (!$oldData) {
            throw new Exception("Venda não encontrada.");
        }

        // Sanitiza e valida os dados - BASEADO NA ESTRUTURA REAL DA TABELA VENDAS
        $dados = [
            'numero' => trim($_POST['numero'] ?? ''),
            'nf' => trim($_POST['nf'] ?? ''),
            'cliente_uasg' => trim($_POST['cliente_uasg'] ?? ''),
            'cliente' => trim($_POST['cliente'] ?? ''), // Campo que existe na tabela
            'valor_total' => !empty($_POST['valor_total']) ? str_replace(',', '.', $_POST['valor_total']) : null,
            'pregao' => trim($_POST['pregao'] ?? ''),
            'classificacao' => trim($_POST['classificacao'] ?? ''),
            'observacao' => trim($_POST['observacao'] ?? ''),
            'data' => $_POST['data'] ?? null,
            'data_vencimento' => $_POST['data_vencimento'] ?? null
        ];

        // Validações obrigatórias
        if (empty($dados['nf'])) {
            throw new Exception("Nota Fiscal é obrigatória.");
        }
        if (empty($dados['cliente_uasg'])) {
            throw new Exception("Cliente UASG é obrigatório.");
        }

        // Converte datas para formato MySQL
        $dados['data'] = converterDataParaMySQL($dados['data']);
        $dados['data_vencimento'] = converterDataParaMySQL($dados['data_vencimento']);

        // Atualiza a venda - APENAS CAMPOS QUE EXISTEM NA TABELA
        $sql = "UPDATE vendas SET 
                numero = :numero, 
                nf = :nf, 
                cliente_uasg = :cliente_uasg, 
                cliente = :cliente,
                valor_total = :valor_total, 
                pregao = :pregao, 
                classificacao = :classificacao, 
                observacao = :observacao, 
                data = :data,
                data_vencimento = :data_vencimento,
                updated_at = NOW()
                WHERE id = :id";

        $stmt = $pdo->prepare($sql);
        
        // Bind parameters com tipos específicos
        $stmt->bindValue(':numero', $dados['numero'], PDO::PARAM_STR);
        $stmt->bindValue(':nf', $dados['nf'], PDO::PARAM_STR);
        $stmt->bindValue(':cliente_uasg', $dados['cliente_uasg'], PDO::PARAM_STR);
        $stmt->bindValue(':cliente', $dados['cliente'], PDO::PARAM_STR);
        $stmt->bindValue(':valor_total', $dados['valor_total'], $dados['valor_total'] !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':pregao', $dados['pregao'], PDO::PARAM_STR);
        $stmt->bindValue(':classificacao', $dados['classificacao'], PDO::PARAM_STR);
        $stmt->bindValue(':observacao', $dados['observacao'], PDO::PARAM_STR);
        $stmt->bindValue(':data', $dados['data'], $dados['data'] !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':data_vencimento', $dados['data_vencimento'], $dados['data_vencimento'] !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        
        if (!$stmt->execute()) {
            $errorInfo = $stmt->errorInfo();
            throw new Exception("Erro ao atualizar a venda: " . $errorInfo[2]);
        }

        // Busca dados novos para auditoria
        $newDataStmt = $pdo->prepare("SELECT * FROM vendas WHERE id = :id");
        $newDataStmt->bindParam(':id', $id, PDO::PARAM_INT);
        $newDataStmt->execute();
        $newData = $newDataStmt->fetch(PDO::FETCH_ASSOC);

        // Registra auditoria
        logAudit($pdo, $_SESSION['user']['id'], 'UPDATE', 'vendas', $id, $newData, $oldData);

        $pdo->commit();
        $response['success'] = true;
        $response['message'] = "Venda atualizada com sucesso!";
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $response['error'] = $e->getMessage();
        error_log("Erro ao atualizar venda: " . $e->getMessage());
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $response['error'] = "Erro de banco de dados: " . $e->getMessage();
        error_log("Erro PDO ao atualizar venda: " . $e->getMessage());
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit();
}

// ===========================================
// PROCESSAMENTO AJAX - EXCLUSÃO DE VENDA
// ===========================================
if (isset($_POST['delete_venda_id'])) {
    // Limpa qualquer output anterior
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    
    $id = intval($_POST['delete_venda_id']);

    try {
        $pdo->beginTransaction();

        // Busca dados da venda para auditoria
        $stmt_venda = $pdo->prepare("SELECT * FROM vendas WHERE id = :id");
        $stmt_venda->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt_venda->execute();
        $venda_data = $stmt_venda->fetch(PDO::FETCH_ASSOC);

        if (!$venda_data) {
            throw new Exception("Venda não encontrada.");
        }

        // Exclui produtos da venda se a tabela existir
        try {
            $sql = "DELETE FROM venda_produtos WHERE venda_id = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            error_log("Produtos da venda $id excluídos: " . $stmt->rowCount() . " registros");
        } catch (Exception $e) {
            // Se a tabela não existir, continua
            error_log("Tabela venda_produtos não existe ou erro: " . $e->getMessage());
        }

        // Exclui a venda
        $sql = "DELETE FROM vendas WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        if ($stmt->rowCount() === 0) {
            throw new Exception("Nenhuma venda foi excluída. Verifique se o ID está correto.");
        }

        // Registra auditoria
        logAudit($pdo, $_SESSION['user']['id'], 'DELETE', 'vendas', $id, null, $venda_data);

        $pdo->commit();
        
        echo json_encode(['success' => true, 'message' => 'Venda excluída com sucesso!'], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Erro ao excluir venda: " . $e->getMessage());
        echo json_encode(['error' => 'Erro ao excluir a venda: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Erro PDO ao excluir venda: " . $e->getMessage());
        echo json_encode(['error' => 'Erro de banco de dados: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit();
}

// Endpoint AJAX para validar senha do setor financeiro
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['validate_financial_password'])) {
    $senha_inserida = $_POST['financial_password'] ?? '';
    $senha_padrao = 'Licitasis@2025'; // Senha padrão do setor financeiro
    
    if ($senha_inserida === $senha_padrao) {
        echo json_encode(['success' => true, 'message' => 'Senha validada com sucesso']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Senha incorreta para o setor financeiro']);
    }
    exit();
}

// Endpoint AJAX para atualizar status
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status']) && $permissionManager->hasPagePermission('financeiro', 'edit')) {
    $id = intval($_POST['id'] ?? 0);
    $novo_status = $_POST['status_pagamento'] ?? '';
    $senha_financeiro = $_POST['financial_password'] ?? '';

    if ($id > 0 && in_array($novo_status, ['Não Recebido', 'Recebido'])) {
        // Se está alterando para "Recebido", valida a senha do setor financeiro
        if ($novo_status === 'Recebido') {
            $senha_padrao = 'Licitasis@2025';
            if ($senha_financeiro !== $senha_padrao) {
                echo json_encode(['success' => false, 'error' => 'Senha do setor financeiro incorreta']);
                exit();
            }
        }

        try {
            // Busca dados antigos para auditoria
            $oldDataStmt = $pdo->prepare("SELECT * FROM vendas WHERE id = :id");
            $oldDataStmt->bindParam(':id', $id);
            $oldDataStmt->execute();
            $oldData = $oldDataStmt->fetch(PDO::FETCH_ASSOC);
            
            $sql = "UPDATE vendas SET status_pagamento = :status WHERE id = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':status', $novo_status);
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            
            // Busca dados novos para auditoria
            $newDataStmt = $pdo->prepare("SELECT * FROM vendas WHERE id = :id");
            $newDataStmt->bindParam(':id', $id);
            $newDataStmt->execute();
            $newData = $newDataStmt->fetch(PDO::FETCH_ASSOC);
            
            // Registra auditoria com informação adicional sobre autorização financeira
            $auditInfo = $newData;
            if ($novo_status === 'Recebido') {
                $auditInfo['financial_auth'] = 'Autorizado com senha do setor financeiro';
                $auditInfo['authorized_by'] = $_SESSION['user']['id'];
                $auditInfo['authorization_time'] = date('Y-m-d H:i:s');
            }
            
            logAudit($pdo, $_SESSION['user']['id'], 'UPDATE', 'vendas', $id, $auditInfo, $oldData);
            
            echo json_encode(['success' => true, 'message' => 'Status atualizado com sucesso']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Dados inválidos']);
    }
    exit();
}

function buscarProdutosVenda($venda_id, $pdo) {
    try {
        // Verifica se a tabela venda_produtos existe
        $checkVendaProdutos = $pdo->query("SHOW TABLES LIKE 'venda_produtos'");
        
        if ($checkVendaProdutos->rowCount() == 0) {
            error_log("Tabela venda_produtos não existe");
            return [];
        }
        
        // Verifica se a tabela produtos existe para fazer JOIN
        $checkProdutos = $pdo->query("SHOW TABLES LIKE 'produtos'");
        $temTabelaProdutos = $checkProdutos->rowCount() > 0;
        
        if ($temTabelaProdutos) {
            // Com JOIN na tabela produtos
            $sql = "SELECT vp.*, 
                           vp.quantidade,
                           vp.valor_unitario,
                           vp.valor_total,
                           vp.observacao as produto_observacao,
                           p.nome as produto_nome,
                           p.codigo as produto_codigo
                    FROM venda_produtos vp
                    LEFT JOIN produtos p ON vp.produto_id = p.id
                    WHERE vp.venda_id = :venda_id
                    ORDER BY vp.id";
        } else {
            // Sem JOIN - apenas dados da venda_produtos
            $sql = "SELECT vp.*,
                           vp.quantidade,
                           vp.valor_unitario,
                           vp.valor_total,
                           vp.observacao as produto_observacao,
                           CONCAT('Produto ID: ', vp.produto_id) as produto_nome,
                           vp.produto_id as produto_codigo
                    FROM venda_produtos vp
                    WHERE vp.venda_id = :venda_id
                    ORDER BY vp.id";
        }
        
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':venda_id', $venda_id, PDO::PARAM_INT);
        $stmt->execute();
        $produtos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        error_log("Produtos encontrados para venda $venda_id: " . count($produtos));
        if (count($produtos) > 0) {
            error_log("Primeiro produto: " . json_encode($produtos[0]));
        }
        
        return $produtos;
        
    } catch (PDOException $e) {
        error_log("Erro ao buscar produtos da venda: " . $e->getMessage());
        return [];
    }
}

// Busca com filtro de pesquisa e paginação
try {
    if (isset($_GET['search']) && !empty(trim($_GET['search']))) {
        $searchTerm = trim($_GET['search']);
        
        // Conta total de resultados
        $countSql = "SELECT COUNT(*) as total FROM vendas v
                     LEFT JOIN clientes c ON v.cliente_uasg = c.uasg
                     WHERE (v.status_pagamento = 'Não Recebido' OR v.status_pagamento IS NULL)
                     AND (v.nf LIKE :searchTerm OR c.nome_orgaos LIKE :searchTerm OR v.cliente_uasg LIKE :searchTerm)";
        $countStmt = $pdo->prepare($countSql);
        $searchParam = "%$searchTerm%";
        $countStmt->bindValue(':searchTerm', $searchParam);
        $countStmt->execute();
        $totalContas = $countStmt->fetch()['total'];
        
        // Busca contas com paginação
        $sql = "SELECT v.id, v.nf, v.cliente_uasg, c.nome_orgaos as cliente_nome, v.valor_total, v.status_pagamento, v.data_vencimento 
                FROM vendas v
                LEFT JOIN clientes c ON v.cliente_uasg = c.uasg
                WHERE (v.status_pagamento = 'Não Recebido' OR v.status_pagamento IS NULL)
                AND (v.nf LIKE :searchTerm OR c.nome_orgaos LIKE :searchTerm OR v.cliente_uasg LIKE :searchTerm)
                ORDER BY v.data_vencimento ASC, v.nf ASC
                LIMIT :limit OFFSET :offset";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':searchTerm', $searchParam);
        $stmt->bindValue(':limit', $contasPorPagina, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $contas_a_receber = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // Conta total de contas a receber
        $countStmt = $pdo->query("SELECT COUNT(*) as total FROM vendas WHERE status_pagamento = 'Não Recebido'");
        $totalContas = $countStmt->fetch()['total'];
        
        // Busca todas as contas com paginação
        $sql = "SELECT v.id, v.nf, v.cliente_uasg, c.nome_orgaos as cliente_nome, v.valor_total, v.status_pagamento, v.data_vencimento 
                FROM vendas v
                LEFT JOIN clientes c ON v.cliente_uasg = c.uasg
                WHERE v.status_pagamento = 'Não Recebido'
                ORDER BY v.data_vencimento DESC, v.nf ASC
                LIMIT :limit OFFSET :offset";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':limit', $contasPorPagina, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $contas_a_receber = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    $error = "Erro ao buscar contas a receber: " . $e->getMessage();
}

// Calcula informações de paginação
$totalPaginas = ceil($totalContas / $contasPorPagina);

// Processa requisição AJAX para dados da venda - CONSULTA CORRIGIDA COM ESTRUTURA REAL
if (isset($_GET['get_venda_id'])) {
    $venda_id = intval($_GET['get_venda_id']);
    try {
        // Consulta baseada na estrutura REAL da tabela vendas
        $sql = "SELECT v.*, 
                       -- Formatação de datas
                       DATE_FORMAT(v.data, '%Y-%m-%d') as data_iso,
                       DATE_FORMAT(v.data, '%d/%m/%Y') as data_formatada,
                       DATE_FORMAT(v.data_vencimento, '%Y-%m-%d') as data_vencimento_iso,
                       DATE_FORMAT(v.data_vencimento, '%d/%m/%Y') as data_vencimento_formatada,
                       DATE_FORMAT(v.created_at, '%d/%m/%Y %H:%i:%s') as data_cadastro_formatada,
                       DATE_FORMAT(v.updated_at, '%d/%m/%Y %H:%i:%s') as data_atualizacao_formatada,
                       
                       -- Dados do cliente via UASG (com verificação)
                       c.id as cliente_id_encontrado,
                       c.uasg as cliente_uasg_encontrado,
                       c.nome_orgaos as cliente_nome_orgaos,
                       c.cnpj as cliente_cnpj,
                       c.telefone as cliente_telefone,
                       c.email as cliente_email,
                       c.observacoes as cliente_observacoes,
                       c.endereco as cliente_endereco,
                       c.telefone2 as cliente_telefone2,
                       c.email2 as cliente_email2,
                       
                       -- Debug: verifica se encontrou cliente
                       CASE 
                           WHEN c.id IS NOT NULL THEN 'SIM' 
                           ELSE 'NAO' 
                       END as cliente_encontrado_debug
                       
                FROM vendas v
                LEFT JOIN clientes c ON TRIM(v.cliente_uasg) = TRIM(c.uasg)
                WHERE v.id = :venda_id";
        
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':venda_id', $venda_id, PDO::PARAM_INT);
        $stmt->execute();
        $venda = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($venda) {
            // Debug melhorado: Log para verificar dados
            error_log("=== DEBUG VENDA ID $venda_id ===");
            error_log("Cliente UASG na venda: '" . ($venda['cliente_uasg'] ?? 'NULL') . "'");
            error_log("Cliente nome campo: '" . ($venda['cliente'] ?? 'NULL') . "'");
            error_log("Cliente encontrado no JOIN: " . ($venda['cliente_encontrado_debug'] ?? 'NULL'));
            error_log("Cliente Nome (tabela clientes): '" . ($venda['cliente_nome_orgaos'] ?? 'NULL') . "'");
            error_log("NF: '" . ($venda['nf'] ?? 'NULL') . "'");
            error_log("Valor Total: '" . ($venda['valor_total'] ?? 'NULL') . "'");
            error_log("Data Venda: " . ($venda['data_formatada'] ?? 'NULL'));
            error_log("Data Vencimento: " . ($venda['data_vencimento_formatada'] ?? 'NULL'));
            error_log("Status Pagamento: '" . ($venda['status_pagamento'] ?? 'NULL') . "'");
            
            // Se cliente não foi encontrado, vamos investigar
            if ($venda['cliente_encontrado_debug'] === 'NAO') {
                error_log("⚠️ CLIENTE NAO ENCONTRADO - Investigando...");
                
                // Verifica se existe algum cliente na tabela
                try {
                    $debug_sql = "SELECT COUNT(*) as total FROM clientes";
                    $debug_stmt = $pdo->prepare($debug_sql);
                    $debug_stmt->execute();
                    $total_clientes = $debug_stmt->fetch(PDO::FETCH_ASSOC)['total'];
                    error_log("Total de clientes na tabela: " . $total_clientes);
                    
                    if ($total_clientes > 0) {
                        // Busca clientes com UASG similar
                        $debug_sql2 = "SELECT uasg, nome_orgaos FROM clientes WHERE uasg LIKE :uasg_like LIMIT 5";
                        $debug_stmt2 = $pdo->prepare($debug_sql2);
                        $debug_uasg = '%' . ($venda['cliente_uasg'] ?? '') . '%';
                        $debug_stmt2->bindValue(':uasg_like', $debug_uasg);
                        $debug_stmt2->execute();
                        $clientes_similares = $debug_stmt2->fetchAll(PDO::FETCH_ASSOC);
                        
                        error_log("Clientes similares encontrados:");
                        foreach ($clientes_similares as $cliente_similar) {
                            error_log("- UASG: '" . $cliente_similar['uasg'] . "' | Nome: '" . $cliente_similar['nome_orgaos'] . "'");
                        }
                        
                        // Lista alguns UASGs da tabela para comparação
                        $debug_sql3 = "SELECT DISTINCT uasg FROM clientes LIMIT 10";
                        $debug_stmt3 = $pdo->prepare($debug_sql3);
                        $debug_stmt3->execute();
                        $uasgs_existentes = $debug_stmt3->fetchAll(PDO::FETCH_COLUMN);
                        error_log("Alguns UASGs existentes na tabela clientes: " . implode(', ', $uasgs_existentes));
                    } else {
                        error_log("❌ TABELA CLIENTES ESTÁ VAZIA!");
                    }
                    
                } catch (Exception $debug_e) {
                    error_log("Erro no debug de clientes: " . $debug_e->getMessage());
                }
            }
            
            // Busca produtos da venda usando a tabela venda_produtos
            $venda['produtos'] = buscarProdutosVenda($venda_id, $pdo);
            
            echo json_encode($venda, JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode(['error' => 'Venda não encontrada']);
        }
        exit();
    } catch (PDOException $e) {
        error_log("Erro na consulta de venda ID $venda_id: " . $e->getMessage());
        echo json_encode(['error' => "Erro ao buscar detalhes da venda: " . $e->getMessage()]);
        exit();
    }
}

// Calcula total geral a receber
try {
    $sqlTotal = "SELECT SUM(valor_total) AS total_geral FROM vendas WHERE status_pagamento = 'Não Recebido'";
    $stmtTotal = $pdo->prepare($sqlTotal);
    $stmtTotal->execute();
    $totalGeralReceber = $stmtTotal->fetch(PDO::FETCH_ASSOC)['total_geral'];
} catch (PDOException $e) {
    $error = "Erro ao calcular o total de contas a receber: " . $e->getMessage();
}

// Processa mensagens de URL
if (isset($_GET['success'])) {
    $success = $_GET['success'];
}
if (isset($_GET['error'])) {
    $error = $_GET['error'];
}

// Inclui o template de header
include('includes/header_template.php');
startPage("Contas a Receber - LicitaSis", "financeiro");
?>

<style>
    /* Mantém todos os estilos CSS anteriores */
    /* Variáveis CSS */
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
        --transition: all 0.3s ease;
    }

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

    /* Container principal */
    .receivables-container {
        max-width: 1400px;
        margin: 0 auto;
    }

    /* Header da página */
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

    /* Total destacado */
    .total-card {
        background: linear-gradient(135deg, var(--success-color) 0%, #20c997 100%);
        color: white;
        padding: 1.5rem 2rem;
        border-radius: var(--radius);
        margin-bottom: 2rem;
        text-align: center;
        box-shadow: var(--shadow);
        position: relative;
        overflow: hidden;
    }

    .total-card::before {
        content: '';
        position: absolute;
        top: -50%;
        left: -50%;
        width: 100%;
        height: 100%;
        background: rgba(255,255,255,0.1);
        border-radius: 50%;
        transform: rotate(-45deg);
    }

    .total-amount {
        font-size: 2.5rem;
        font-weight: 800;
        margin-bottom: 0.5rem;
        position: relative;
        z-index: 1;
        font-family: 'Courier New', monospace;
    }

    .total-label {
        font-size: 1.1rem;
        opacity: 0.9;
        position: relative;
        z-index: 1;
    }

    /* Mensagens de feedback */
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

    /* Barra de controles */
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

    /* Informações de resultados */
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

    /* Tabela */
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
        padding: 1rem;
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
    }

    .nf-link:hover {
        color: var(--primary-color);
        text-decoration: underline;
    }

    .status-select {
        padding: 0.5rem 0.75rem;
        border-radius: var(--radius);
        border: 2px solid var(--border-color);
        font-size: 0.9rem;
        cursor: pointer;
        transition: var(--transition);
        background-color: #f9f9f9;
        font-weight: 500;
    }

    .status-select:hover, .status-select:focus {
        border-color: var(--primary-color);
        background-color: white;
        outline: none;
        box-shadow: 0 0 0 3px rgba(45, 137, 62, 0.1);
    }

    /* Paginação */
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

    /* Estado vazio */
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

    /* Modal */
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
        width: 95%;
        max-width: 1200px;
        box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        animation: slideInUp 0.3s ease;
        overflow: hidden;
    }

    @keyframes fadeIn {
        from { opacity: 0; }
        to { opacity: 1; }
    }

    @keyframes slideInUp {
        from { transform: translateY(50px); opacity: 0; }
        to { transform: translateY(0); opacity: 1; }
    }

    .modal-header {
        background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        color: white;
        padding: 1.5rem 2rem;
        position: relative;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .modal-header h3 {
        margin: 0;
        color: white;
        font-size: 1.4rem;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }

    .modal-close {
        color: white;
        font-size: 2rem;
        font-weight: bold;
        cursor: pointer;
        transition: var(--transition);
        line-height: 1;
        width: 40px;
        height: 40px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.1);
    }

    .modal-close:hover {
        transform: scale(1.1);
        background: rgba(255, 255, 255, 0.2);
    }

    .modal-body {
        padding: 2rem;
        max-height: 80vh;
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

    /* Formulário do modal */
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
        display: block;
        font-size: 0.95rem;
    }

    .form-control {
        width: 100%;
        padding: 0.875rem;
        border: 2px solid var(--border-color);
        border-radius: var(--radius);
        font-size: 1rem;
        transition: var(--transition);
        background-color: #f9f9f9;
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
        margin-bottom: 1rem;
        font-size: 1.1rem;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .modal-section-content {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 1rem;
    }

    .info-item {
        background: white;
        padding: 1rem;
        border-radius: var(--radius);
        border: 1px solid var(--border-color);
    }

    .info-item label {
        font-size: 0.85rem;
        color: var(--medium-gray);
        font-weight: 500;
        display: block;
        margin-bottom: 0.25rem;
    }

    .info-item .value {
        font-size: 1rem;
        color: var(--dark-gray);
        font-weight: 600;
    }

    .info-item .value.empty {
        color: var(--medium-gray);
        font-style: italic;
    }

    /* Produtos */
    .produtos-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 1rem;
    }

    .produtos-table th,
    .produtos-table td {
        padding: 0.75rem;
        text-align: left;
        border-bottom: 1px solid var(--border-color);
    }

    .produtos-table th {
        background: var(--primary-color);
        color: white;
        font-weight: 600;
        font-size: 0.9rem;
    }

    .produtos-table tbody tr:hover {
        background: rgba(45, 137, 62, 0.05);
    }

    .produtos-table .produto-nome {
        font-weight: 600;
    }

    .produtos-table .produto-valor {
        font-family: 'Courier New', monospace;
        font-weight: 600;
    }

    /* Status badges */
    .status-badge {
        padding: 0.4rem 0.8rem;
        border-radius: var(--radius);
        font-size: 0.85rem;
        font-weight: 600;
        display: inline-block;
        text-align: center;
        min-width: 90px;
    }

    .status-badge.status-nao-recebido {
        background: rgba(220, 53, 69, 0.1);
        color: var(--danger-color);
        border: 1px solid rgba(220, 53, 69, 0.3);
    }

    .status-badge.status-recebido {
        background: rgba(40, 167, 69, 0.1);
        color: var(--success-color);
        border: 1px solid rgba(40, 167, 69, 0.3);
    }

    /* Indicadores visuais */
    .overdue {
        background: rgba(220, 53, 69, 0.05);
        border-left: 4px solid var(--danger-color);
    }

    .due-soon {
        background: rgba(255, 193, 7, 0.05);
        border-left: 4px solid var(--warning-color);
    }

    /* Modal de Confirmação */
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

    /* Modal de Autenticação Financeira */
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

    /* Estilos específicos para o modal de inclusão */
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
}

.form-text {
    font-size: 0.85rem;
    color: var(--medium-gray);
    margin-top: 0.25rem;
    font-style: italic;
}

.resumo-conta {
    animation: fadeIn 0.3s ease;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

/* Melhoria nos campos do formulário */
#incluirModal .form-control:focus {
    border-color: var(--success-color);
    box-shadow: 0 0 0 3px rgba(40, 167, 69, 0.1);
}

#incluirModal .form-group label {
    font-weight: 600;
    color: var(--primary-color);
    margin-bottom: 0.5rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

#incluirModal .form-group label i {
    color: var(--secondary-color);
    width: 16px;
    text-align: center;
}

/* Responsividade específica para o modal */
@media (max-width: 768px) {
    #incluirModal .form-row {
        grid-template-columns: 1fr;
    }
    
    #incluirModal .input-group {
        flex-direction: column;
    }
    
    #incluirModal .input-group-prepend {
        width: 100%;
    }
    
    #incluirModal .input-group-text {
        border-radius: var(--radius) var(--radius) 0 0;
        border-right: 2px solid var(--border-color);
        justify-content: center;
    }
    
    #incluirModal .input-group input {
        border-radius: 0 0 var(--radius) var(--radius);
        border-left: 2px solid var(--border-color) !important;
        border-top: none;
    }
}

    @keyframes shake {
        0%, 20%, 40%, 60%, 80% { transform: translateX(0); }
        10%, 30%, 50%, 70%, 90% { transform: translateX(-5px); }
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

    /* Responsividade */
    @media (max-width: 1200px) {
        .receivables-container {
            padding: 1.5rem 1rem;
        }
        
        .modal-content {
            width: 98%;
            margin: 1rem auto;
        }
    }

    @media (max-width: 768px) {
        .page-header {
            padding: 1.5rem;
        }

        .page-header h1 {
            font-size: 1.8rem;
        }

        .total-amount {
            font-size: 2rem;
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

        .form-row {
            grid-template-columns: 1fr;
        }

        .modal-section-content {
            grid-template-columns: 1fr;
        }

        .modal-body {
            padding: 1.5rem;
        }

        .confirmation-buttons, .auth-buttons {
            flex-direction: column;
        }

        .confirmation-buttons button, .auth-buttons button {
            width: 100%;
        }

        table th, table td {
            padding: 0.75rem 0.5rem;
            font-size: 0.9rem;
        }

        .financial-auth-content {
            margin: 10% auto;
            width: 95%;
        }

        .financial-auth-body {
            padding: 1.5rem;
        }

        .modal-footer {
            flex-direction: column;
        }

        .modal-footer .btn {
            width: 100%;
        }
    }

    @media (max-width: 480px) {
        .page-header {
            padding: 1rem;
        }

        .page-header h1 {
            font-size: 1.5rem;
        }

        .total-amount {
            font-size: 1.8rem;
        }

        .controls-bar, .results-info, .total-card {
            padding: 1rem;
        }

        .modal-header, .financial-auth-header {
            padding: 1rem;
        }

        .modal-header h3, .financial-auth-header h3 {
            font-size: 1.2rem;
        }

        .btn {
            width: 100%;
            justify-content: center;
        }

        .confirmation-modal-content, .financial-auth-content {
            margin: 5% 1rem;
            padding: 1.5rem;
        }
    }

    /* Animações */
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

    /* Autocomplete de clientes */
.autocomplete-container {
    position: relative;
    width: 100%;
}

.suggestions-dropdown {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: white;
    border: 2px solid var(--border-color);
    border-top: none;
    border-radius: 0 0 var(--radius) var(--radius);
    max-height: 250px;
    overflow-y: auto;
    z-index: 1000;
    display: none;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

.suggestions-dropdown.show {
    display: block;
}

.suggestion-item {
    padding: 0.875rem 1rem;
    cursor: pointer;
    border-bottom: 1px solid var(--border-color);
    transition: var(--transition);
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.suggestion-item:last-child {
    border-bottom: none;
}

.suggestion-item:hover,
.suggestion-item.highlighted {
    background: var(--light-gray);
    border-left: 4px solid var(--secondary-color);
}

.suggestion-item.selected {
    background: var(--secondary-color);
    color: white;
}

.suggestion-nome {
    font-weight: 600;
    color: var(--primary-color);
    font-size: 0.95rem;
}

.suggestion-item.selected .suggestion-nome {
    color: white;
}

.suggestion-detalhes {
    font-size: 0.85rem;
    color: var(--medium-gray);
    display: flex;
    gap: 1rem;
    flex-wrap: wrap;
}

.suggestion-item.selected .suggestion-detalhes {
    color: rgba(255, 255, 255, 0.9);
}

.suggestion-detalhe {
    display: flex;
    align-items: center;
    gap: 0.25rem;
}

.suggestion-detalhe i {
    width: 12px;
    font-size: 0.8rem;
}

.suggestions-loading {
    padding: 1rem;
    text-align: center;
    color: var(--medium-gray);
    font-style: italic;
}

.suggestions-empty {
    padding: 1rem;
    text-align: center;
    color: var(--medium-gray);
    font-style: italic;
}

/* Responsividade para sugestões */
@media (max-width: 768px) {
    .suggestions-dropdown {
        max-height: 200px;
    }
    
    .suggestion-detalhes {
        flex-direction: column;
        gap: 0.25rem;
    }
}

    .table-container, .controls-bar, .results-info, .total-card {
        animation: fadeInUp 0.6s ease forwards;
    }

    .table-container { animation-delay: 0.2s; }
    .controls-bar { animation-delay: 0.05s; }
    .results-info { animation-delay: 0.15s; }
    .total-card { animation-delay: 0.1s; }

    /* Scrollbar personalizada */
    .table-responsive::-webkit-scrollbar, .modal-body::-webkit-scrollbar, .financial-auth-body::-webkit-scrollbar {
        width: 8px;
        height: 8px;
    }

    .table-responsive::-webkit-scrollbar-track, .modal-body::-webkit-scrollbar-track, .financial-auth-body::-webkit-scrollbar-track {
        background: var(--light-gray);
        border-radius: 4px;
    }

    .table-responsive::-webkit-scrollbar-thumb, .modal-body::-webkit-scrollbar-thumb, .financial-auth-body::-webkit-scrollbar-thumb {
        background: var(--medium-gray);
        border-radius: 4px;
    }

    .table-responsive::-webkit-scrollbar-thumb:hover, .modal-body::-webkit-scrollbar-thumb:hover, .financial-auth-body::-webkit-scrollbar-thumb:hover {
        background: var(--dark-gray);
    }

    /* Utilitários */
    .text-center { text-align: center; }
    .mb-0 { margin-bottom: 0; }
    .mt-1 { margin-top: 0.5rem; }
    .font-weight-bold { font-weight: 600; }

    /* Estados da tabela */
    .status-nao-recebido {
        background: rgba(220, 53, 69, 0.1);
        color: var(--danger-color);
        font-weight: 600;
    }

    .status-recebido {
        background: rgba(40, 167, 69, 0.1);
        color: var(--success-color);
        font-weight: 600;
    }

    /* Estilos para o modo de edição */
    .edit-mode {
        background: rgba(255, 193, 7, 0.1);
        border: 2px solid var(--warning-color);
    }

    .edit-mode .modal-header {
        background: linear-gradient(135deg, var(--warning-color), #ff8f00);
        color: var(--dark-gray);
    }

    .loading-overlay {
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(255, 255, 255, 0.8);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 1000;
    }

    .loading-overlay .spinner {
        width: 40px;
        height: 40px;
        border: 4px solid var(--border-color);
        border-top: 4px solid var(--secondary-color);
        border-radius: 50%;
        animation: spin 1s linear infinite;
    }
</style>
</head>
<body>

<!-- Container principal com layout padrão do sistema -->
<div class="main-content">
    <div class="container receivables-container">
    
    <!-- Header da página -->
    <div class="page-header">
        <h1><i class="fas fa-money-bill-wave"></i> Contas a Receber</h1>
        <p>Gerencie e acompanhe todas as contas pendentes de recebimento</p>
    </div>

    <!-- Card do total geral -->
    <?php if (isset($totalGeralReceber) && $totalGeralReceber > 0): ?>
        <div class="total-card">
            <div class="total-amount">
                R$ <?php echo number_format($totalGeralReceber, 2, ',', '.'); ?>
            </div>
            <div class="total-label">
                <i class="fas fa-dollar-sign"></i> Total Geral de Contas a Receber
            </div>
        </div>
    <?php endif; ?>

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

    <!-- Barra de controles -->
    <div class="controls-bar">
        <form class="search-form" action="contas_a_receber.php" method="GET">
            <input type="text" 
                   name="search" 
                   class="search-input"
                   placeholder="Pesquisar por NF, Cliente ou UASG..." 
                   value="<?php echo htmlspecialchars($searchTerm); ?>"
                   autocomplete="off">
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-search"></i> Pesquisar
            </button>
            <?php if ($searchTerm): ?>
                <a href="contas_a_receber.php" class="btn btn-secondary">
                    <i class="fas fa-times"></i> Limpar
                </a>
            <?php endif; ?>
        </form>
        
        <?php if ($permissionManager->hasPagePermission('vendas', 'create')): ?>
    <button type="button" class="btn btn-success" onclick="abrirModalIncluir()">
        <i class="fas fa-plus"></i> Incluir
    </button>
<?php endif; ?>
    </div>

    <!-- Informações de resultados -->
    <?php if ($totalContas > 0): ?>
        <div class="results-info">
            <div class="results-count">
                <?php if ($searchTerm): ?>
                    Encontradas <strong><?php echo $totalContas; ?></strong> conta(s) a receber 
                    para "<strong><?php echo htmlspecialchars($searchTerm); ?></strong>"
                <?php else: ?>
                    Total de <strong><?php echo $totalContas; ?></strong> conta(s) a receber
                <?php endif; ?>
                
                <?php if ($totalPaginas > 1): ?>
                    - Página <strong><?php echo $paginaAtual; ?></strong> de <strong><?php echo $totalPaginas; ?></strong>
                <?php endif; ?>
            </div>
            
            <?php if ($totalContas > $contasPorPagina): ?>
                <div>
                    Mostrando <?php echo ($offset + 1); ?>-<?php echo min($offset + $contasPorPagina, $totalContas); ?> 
                    de <?php echo $totalContas; ?> resultados
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Tabela de contas a receber -->
    <?php if (count($contas_a_receber) > 0): ?>
        <div class="table-container">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th><i class="fas fa-file-invoice"></i> NF</th>
                            <th><i class="fas fa-building"></i> Cliente</th>
                            <th><i class="fas fa-dollar-sign"></i> Valor Total</th>
                            <th><i class="fas fa-tasks"></i> Status</th>
                            <th><i class="fas fa-calendar"></i> Vencimento</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($contas_a_receber as $conta): 
                            $dataVencimento = new DateTime($conta['data_vencimento']);
                            $hoje = new DateTime();
                            $diasAteVencimento = $hoje->diff($dataVencimento)->days;
                            $vencido = $dataVencimento < $hoje;
                            $venceEm3Dias = !$vencido && $diasAteVencimento <= 3;
                            
                            $rowClass = '';
                            if ($vencido) $rowClass = 'overdue';
                            elseif ($venceEm3Dias) $rowClass = 'due-soon';
                        ?>
                            <tr data-id="<?php echo $conta['id']; ?>" class="<?php echo $rowClass; ?>">
                                <td>
                                    <a href="javascript:void(0);" 
                                       onclick="openModal(<?php echo $conta['id']; ?>)" 
                                       class="nf-link">
                                        <?php echo htmlspecialchars($conta['nf']); ?>
                                    </a>
                                </td>
                                <td><?php echo htmlspecialchars($conta['cliente_nome'] ?: 'Cliente não encontrado'); ?></td>
                                <td class="font-weight-bold">R$ <?php echo number_format($conta['valor_total'], 2, ',', '.'); ?></td>
                                <td>
                                    <?php if ($permissionManager->hasPagePermission('financeiro', 'edit')): ?>
                                        <select class="status-select" 
                                                data-id="<?php echo $conta['id']; ?>" 
                                                data-nf="<?php echo htmlspecialchars($conta['nf']); ?>"
                                                data-cliente="<?php echo htmlspecialchars($conta['cliente_nome'] ?: 'Cliente não encontrado'); ?>"
                                                data-valor="<?php echo number_format($conta['valor_total'], 2, ',', '.'); ?>"
                                                data-vencimento="<?php echo $dataVencimento->format('d/m/Y'); ?>">
                                            <option value="Não Recebido" <?php if ($conta['status_pagamento'] === 'Não Recebido') echo 'selected'; ?>>Não Recebido</option>
                                            <option value="Recebido" <?php if ($conta['status_pagamento'] === 'Recebido') echo 'selected'; ?>>Recebido</option>
                                        </select>
                                    <?php else: ?>
                                        <span class="status-badge <?php echo $conta['status_pagamento'] === 'Recebido' ? 'status-recebido' : 'status-nao-recebido'; ?>">
                                            <?php echo $conta['status_pagamento'] ?: 'Não Recebido'; ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo $dataVencimento->format('d/m/Y'); ?>
                                    <?php if ($vencido): ?>
                                        <span style="color: var(--danger-color); font-size: 0.8rem; display: block;">
                                            <i class="fas fa-exclamation-triangle"></i> Vencida há <?php echo $diasAteVencimento; ?> dia(s)
                                        </span>
                                    <?php elseif ($venceEm3Dias): ?>
                                        <span style="color: var(--warning-color); font-size: 0.8rem; display: block;">
                                            <i class="fas fa-clock"></i> Vence em <?php echo $diasAteVencimento; ?> dia(s)
                                        </span>
                                    <?php endif; ?>
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
            <i class="fas fa-money-bill-wave"></i>
            <h3>Nenhuma conta a receber encontrada</h3>
            <?php if ($searchTerm): ?>
                <p>Não foram encontradas contas a receber com os termos de busca "<strong><?php echo htmlspecialchars($searchTerm); ?></strong>".</p>
                <a href="contas_a_receber.php" class="btn btn-primary">
                    <i class="fas fa-list"></i> Ver Todas as Contas
                </a>
            <?php else: ?>
                <p>Parabéns! Não há contas pendentes de recebimento no momento.</p>
                <?php if ($permissionManager->hasPagePermission('vendas', 'create')): ?>
    <button type="button" class="btn btn-success" onclick="abrirModalIncluir()">
        <i class="fas fa-plus"></i> Incluir Conta
    </button>
<?php endif; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>

</div>
<!-- Modal de Inclusão de Nova Conta a Receber -->
<div id="incluirModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="incluirModalTitle">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="incluirModalTitle"><i class="fas fa-plus-circle"></i> Incluir Nova Conta a Receber</h3>
            <span class="modal-close" onclick="fecharModalIncluir()">&times;</span>
        </div>
        <div class="modal-body">
            <form id="incluirContaForm" method="POST">
                <input type="hidden" name="incluir_conta" value="1">
                
                <div class="modal-section">
                    <h4><i class="fas fa-money-bill-wave"></i> Informações da Conta</h4>
                    
                    <!-- Cliente -->
                    <div class="form-group">
    <label for="incluir_cliente">
        <i class="fas fa-building"></i> Cliente *
    </label>
    <div class="autocomplete-container">
        <input type="text" 
               id="incluir_cliente" 
               name="cliente" 
               class="form-control" 
               placeholder="Digite o nome, CNPJ ou UASG do cliente..."
               required
               autocomplete="off">
        <div id="clientes-suggestions" class="suggestions-dropdown"></div>
    </div>
    <small class="form-text text-muted">
        Digite pelo menos 2 caracteres para buscar clientes cadastrados
    </small>
</div>
                    
                    <!-- Valor e Tipo de Receita -->
                    <div class="form-row">
                        <div class="form-group">
                            <label for="incluir_valor">
                                <i class="fas fa-dollar-sign"></i> Valor Total *
                            </label>
                            <div class="input-group">
                                <div class="input-group-prepend">
                                    <span class="input-group-text" style="background: var(--light-gray); border: 2px solid var(--border-color); padding: 0.875rem 1rem; border-radius: var(--radius) 0 0 var(--radius); font-weight: 600;">R$</span>
                                </div>
                                <input type="number" 
                                       id="incluir_valor" 
                                       name="valor_total" 
                                       class="form-control" 
                                       step="0.01" 
                                       min="0.01" 
                                       placeholder="0,00"
                                       required
                                       style="border-left: none; border-radius: 0 var(--radius) var(--radius) 0;">
                            </div>
                            <small class="form-text text-muted">
                                Valor total a ser recebido
                            </small>
                        </div>
                        
                        <div class="form-group">
                            <label for="incluir_tipo_receita">
                                <i class="fas fa-tags"></i> Tipo de Receita *
                            </label>
                            <select id="incluir_tipo_receita" name="tipo_receita" class="form-control" required>
                                <option value="">Selecione o tipo de receita</option>
                                <option value="Vendas">
                                    <i class="fas fa-shopping-cart"></i> Vendas
                                </option>
                                <option value="Receitas Diversas">
                                    <i class="fas fa-coins"></i> Receitas Diversas
                                </option>
                                <option value="Estorno de Compra">
                                    <i class="fas fa-undo"></i> Estorno de Compra
                                </option>
                            </select>
                            <small class="form-text text-muted">
                                Classificação para organização e relatórios
                            </small>
                        </div>
                    </div>
                    
                    <!-- Datas -->
                    <div class="form-row">
                        <div class="form-group">
                            <label for="incluir_data">
                                <i class="fas fa-calendar"></i> Data da Transação
                            </label>
                            <input type="date" 
                                   id="incluir_data" 
                                   name="data" 
                                   class="form-control">
                            <small class="form-text text-muted">
                                Data em que a transação foi realizada (padrão: hoje)
                            </small>
                        </div>
                        
                        <div class="form-group">
                            <label for="incluir_data_vencimento">
                                <i class="fas fa-calendar-times"></i> Data de Vencimento *
                            </label>
                            <input type="date" 
                                   id="incluir_data_vencimento" 
                                   name="data_vencimento" 
                                   class="form-control" 
                                   required>
                            <small class="form-text text-muted">
                                Data limite para recebimento
                            </small>
                        </div>
                    </div>
                    
                    <!-- Informações Adicionais -->
                    <div class="form-group">
                        <label for="incluir_informacoes_adicionais">
                            <i class="fas fa-comment-alt"></i> Informações Adicionais
                        </label>
                        <textarea id="incluir_informacoes_adicionais" 
                                  name="informacoes_adicionais" 
                                  class="form-control" 
                                  rows="4" 
                                  placeholder="Observações, número da NF, dados do contrato, descrição dos serviços/produtos, forma de pagamento, etc."
                                  style="resize: vertical;"></textarea>
                        <small class="form-text text-muted">
                            Detalhes adicionais que possam ajudar na identificação e cobrança desta conta
                        </small>
                    </div>
                    
                    <!-- Resumo Visual -->
                    <div class="resumo-conta" style="background: linear-gradient(135deg, #e3f2fd, #f0f8ff); border: 2px solid var(--info-color); border-radius: var(--radius); padding: 1.5rem; margin-top: 1.5rem; display: none;" id="resumoConta">
                        <h5 style="color: var(--info-color); margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem;">
                            <i class="fas fa-clipboard-list"></i> Resumo da Conta
                        </h5>
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                            <div>
                                <strong>Cliente:</strong>
                                <div id="resumo-cliente" style="color: var(--medium-gray);">-</div>
                            </div>
                            <div>
                                <strong>Valor:</strong>
                                <div id="resumo-valor" style="color: var(--success-color); font-weight: 600;">-</div>
                            </div>
                            <div>
                                <strong>Tipo:</strong>
                                <div id="resumo-tipo" style="color: var(--medium-gray);">-</div>
                            </div>
                            <div>
                                <strong>Vencimento:</strong>
                                <div id="resumo-vencimento" style="color: var(--medium-gray);">-</div>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button type="submit" form="incluirContaForm" class="btn btn-success" id="salvarIncluirBtn">
                <i class="fas fa-save"></i> Salvar Conta a Receber
            </button>
            <button type="button" class="btn btn-secondary" onclick="fecharModalIncluir()">
                <i class="fas fa-times"></i> Cancelar
            </button>
        </div>
    </div>
</div>

<!-- Modal de Detalhes da Conta - VERSÃO ATUALIZADA COM EDIÇÃO E EXCLUSÃO -->
<div id="editModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="modalTitle"><i class="fas fa-file-invoice-dollar"></i> Detalhes Completos da Conta a Receber</h3>
            <span class="modal-close" onclick="closeModal()">&times;</span>
        </div>
        <div class="modal-body">
            <!-- Conteúdo será carregado via JavaScript -->
        </div>
        <div class="modal-footer" id="modalFooter" style="display: none;">
            <button class="btn btn-warning" onclick="editarVenda()" id="editarBtn">
                <i class="fas fa-edit"></i> Editar
            </button>
            <button class="btn btn-danger" onclick="confirmarExclusao()" id="excluirBtn">
                <i class="fas fa-trash"></i> Excluir
            </button>
            <button class="btn btn-primary" onclick="imprimirVenda()">
                <i class="fas fa-print"></i> Imprimir
            </button>
            <button class="btn btn-secondary" onclick="closeModal()">
                <i class="fas fa-times"></i> Fechar
            </button>
        </div>
    </div>
</div>

<!-- Modal de Confirmação para Recebimento -->
<div id="confirmationModal" class="confirmation-modal" role="dialog" aria-modal="true">
    <div class="confirmation-modal-content">
        <h3><i class="fas fa-exclamation-triangle"></i> Confirmar Recebimento</h3>
        <p>Deseja realmente marcar esta conta como <strong>RECEBIDA</strong>?</p>
        
        <div class="confirmation-info">
            <p><strong>NF:</strong> <span id="confirm-nf"></span></p>
            <p><strong>Cliente:</strong> <span id="confirm-cliente"></span></p>
            <p><strong>Valor:</strong> R$ <span id="confirm-valor"></span></p>
            <p><strong>Vencimento:</strong> <span id="confirm-vencimento"></span></p>
        </div>
        
        <p style="color: var(--warning-color); font-size: 0.9rem; margin-top: 1rem;">
            <i class="fas fa-info-circle"></i> Esta ação moverá a conta para "Contas Recebidas" e requer autenticação do setor financeiro.
        </p>
        
        <div class="confirmation-buttons">
            <button type="button" class="btn-cancel" onclick="closeConfirmationModal()">
                <i class="fas fa-times"></i> Cancelar
            </button>
            <button type="button" class="btn-confirm" onclick="requestFinancialAuth()">
                <i class="fas fa-check"></i> Confirmar Recebimento
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
                <p>Para marcar uma conta como recebida, é necessário inserir a senha do setor financeiro por questões de segurança.</p>
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
        </div>
    </div>
</div>

<script>
// ===========================================
// SISTEMA COMPLETO DE CONTAS A RECEBER COM EDIÇÃO E EXCLUSÃO
// JavaScript Completo - LicitaSis v2.0 ATUALIZADO
// ===========================================

// ===========================================
// VARIÁVEIS GLOBAIS
// ===========================================
let currentSelectElement = null;
let currentContaData = {};
let currentVendaId = null;
let currentVendaData = null;
let isEditingVenda = false;

// ===========================================
// FUNÇÕES DE CONTROLE DO MODAL
// ===========================================

/**
 * Abre o modal e carrega os dados completos da venda
 */
window.openModal = function(id) {
    console.log('🔍 Abrindo modal para venda ID:', id);
    
    if (!id || isNaN(id)) {
        console.error('❌ ID da venda inválido:', id);
        showToast('Erro: ID da venda inválido', 'error');
        return;
    }
    
    currentVendaId = id;
    const modal = document.getElementById("editModal");
    const modalBody = modal.querySelector('.modal-body');
    const modalFooter = document.getElementById('modalFooter');
    
    if (!modal) {
        console.error('❌ Modal não encontrado no DOM');
        showToast('Erro: Modal não encontrado', 'error');
        return;
    }
    
    // Mostra o modal
    modal.style.display = "block";
    document.body.style.overflow = 'hidden';
    
    // Mostra loading
    modalBody.innerHTML = `
        <div class="loading-spinner" style="text-align: center; padding: 3rem;">
            <div style="width: 40px; height: 40px; border: 3px solid var(--border-color); border-top: 3px solid var(--secondary-color); border-radius: 50%; animation: spin 1s linear infinite; margin: 0 auto;"></div>
            <p style="margin-top: 1rem; color: var(--medium-gray);">Carregando detalhes da venda...</p>
        </div>
    `;
    modalFooter.style.display = 'none';
    
    // Busca dados da venda
    const url = `?get_venda_id=${id}&t=${Date.now()}`;
    console.log('📡 Fazendo requisição para:', url);
    
    fetch(url, {
        method: 'GET',
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Content-Type': 'application/json'
        },
        cache: 'no-cache'
    })
        .then(response => {
            console.log('📡 Resposta recebida:', response.status, response.statusText);
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            
            return response.json();
        })
        .then(data => {
            console.log('✅ Dados da venda recebidos:', data);
            
            if (data.error) {
                throw new Error(data.error);
            }
            
            if (!data.id) {
                throw new Error('Dados da venda incompletos');
            }
            
            currentVendaData = data;
            renderVendaDetails(data);
            modalFooter.style.display = 'flex';
            
            console.log('✅ Modal renderizado com sucesso para venda:', data.nf);
        })
        .catch(error => {
            console.error('❌ Erro ao carregar venda:', error);
            modalBody.innerHTML = `
                <div style="text-align: center; padding: 3rem; color: var(--danger-color);">
                    <i class="fas fa-exclamation-triangle" style="font-size: 3rem; margin-bottom: 1rem;"></i>
                    <p style="font-size: 1.1rem; margin-bottom: 1rem;">Erro ao carregar venda</p>
                    <p style="color: var(--medium-gray); margin-bottom: 1.5rem;">${error.message}</p>
                    <div>
                        <button class="btn btn-warning" onclick="openModal(${id})" style="margin: 0.5rem;">
                            <i class="fas fa-redo"></i> Tentar Novamente
                        </button>
                        <button class="btn btn-secondary" onclick="closeModal()" style="margin: 0.5rem;">
                            <i class="fas fa-times"></i> Fechar
                        </button>
                    </div>
                </div>
            `;
            showToast('Erro ao carregar dados da venda', 'error');
        });
};

/**
 * Renderiza os detalhes completos da venda no modal - VERSÃO SIMPLIFICADA
 */
function renderVendaDetails(venda) {
    console.log('🎨 Renderizando detalhes da venda:', venda);
    
    const modalBody = document.querySelector('#editModal .modal-body');
    
    // Processa as datas corretamente
    const dataVenda = venda.data_iso || '';
    const dataVendaDisplay = venda.data_formatada || 'Não informado';
    const dataVencimento = venda.data_vencimento_iso || '';
    const dataVencimentoDisplay = venda.data_vencimento_formatada || 'Não informado';
    const dataCadastro = venda.data_cadastro_formatada || 'Não informado';
    const dataAtualizacao = venda.data_atualizacao_formatada || 'Não informado';

    // Determina status do vencimento
    let statusVencimento = '';
    let classVencimento = '';
    let textoVencimento = dataVencimentoDisplay;
    
    if (dataVencimento && dataVencimento !== '0000-00-00') {
        const hoje = new Date();
        const vencimento = new Date(dataVencimento);
        const diferenca = Math.ceil((vencimento - hoje) / (1000 * 60 * 60 * 24));
        
        if (diferenca < 0) {
            statusVencimento = `Vencido há ${Math.abs(diferenca)} dia(s)`;
            classVencimento = 'vencido';
        } else if (diferenca <= 7) {
            statusVencimento = diferenca === 0 ? 'Vence hoje!' : `Vence em ${diferenca} dia(s)`;
            classVencimento = 'proximo';
        } else {
            statusVencimento = 'Em dia';
            classVencimento = 'em-dia';
        }
        
        if (statusVencimento) {
            textoVencimento = `${dataVencimentoDisplay} - ${statusVencimento}`;
        }
    }

    modalBody.innerHTML = `
        <div class="venda-details">
            <!-- Formulário de Edição (inicialmente oculto) -->
            <form id="vendaEditForm" style="display: none;">
                <input type="hidden" name="id" value="${venda.id}">
                <input type="hidden" name="update_venda" value="1">
                
                <div class="modal-section">
                    <h4><i class="fas fa-edit"></i> Editar Informações da Venda</h4>
                    <div class="modal-section-content">
                        <div class="info-item">
                            <label>Número da Venda</label>
                            <input type="text" name="numero" class="form-control" value="${venda.numero || ''}" required>
                        </div>
                        <div class="info-item">
                            <label>Nota Fiscal *</label>
                            <input type="text" name="nf" class="form-control" value="${venda.nf || ''}" required>
                        </div>
                        <div class="info-item">
                            <label>Cliente UASG *</label>
                            <input type="text" name="cliente_uasg" class="form-control" value="${venda.cliente_uasg || ''}" required>
                        </div>
                        <div class="info-item">
                            <label>Nome do Cliente</label>
                            <input type="text" name="cliente" class="form-control" value="${venda.cliente || ''}" placeholder="Nome do cliente">
                        </div>
                        <div class="info-item">
                            <label>Valor Total</label>
                            <input type="number" name="valor_total" class="form-control" step="0.01" min="0" value="${venda.valor_total || ''}">
                        </div>
                        <div class="info-item">
                            <label>Data da Venda</label>
                            <input type="date" name="data" class="form-control" value="${dataVenda}">
                        </div>
                        <div class="info-item">
                            <label>Data de Vencimento</label>
                            <input type="date" name="data_vencimento" class="form-control" value="${dataVencimento}">
                        </div>
                        <div class="info-item">
                            <label>Pregão</label>
                            <input type="text" name="pregao" class="form-control" value="${venda.pregao || ''}">
                        </div>
                        <div class="info-item">
                            <label>Classificação</label>
                            <input type="text" name="classificacao" class="form-control" value="${venda.classificacao || ''}">
                        </div>
                        <div class="info-item" style="grid-column: 1 / -1;">
                            <label>Observações</label>
                            <textarea name="observacao" class="form-control" rows="4">${venda.observacao || ''}</textarea>
                        </div>
                    </div>
                </div>

                <div style="margin-top: 2rem; padding-top: 1.5rem; border-top: 2px solid var(--border-color); display: flex; gap: 1rem; justify-content: center; flex-wrap: wrap;">
                    <button type="submit" class="btn btn-success" id="salvarBtn">
                        <i class="fas fa-save"></i> Salvar Alterações
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="cancelarEdicao()">
                        <i class="fas fa-times"></i> Cancelar
                    </button>
                    <button type="button" class="btn btn-danger" onclick="confirmarExclusaoEdicao()">
                        <i class="fas fa-trash"></i> Excluir Venda
                    </button>
                </div>
            </form>

            <!-- Visualização Normal (inicialmente visível) -->
            <div id="vendaViewMode">
                <!-- Informações Básicas -->
                <div class="modal-section">
                    <h4><i class="fas fa-info-circle"></i> Informações Básicas</h4>
                    <div class="modal-section-content">
                        <div class="info-item">
                            <label>Número da Venda</label>
                            <div class="value">${venda.numero || 'Não informado'}</div>
                        </div>
                        <div class="info-item">
                            <label>Nota Fiscal</label>
                            <div class="value">${venda.nf || 'Não informado'}</div>
                        </div>
                        <div class="info-item">
                            <label>Cliente UASG</label>
                            <div class="value">${venda.cliente_uasg || 'Não informado'}</div>
                        </div>
                        <div class="info-item">
                            <label>Nome do Cliente</label>
                            <div class="value">${venda.cliente || 'Não informado'}</div>
                        </div>
                        <div class="info-item">
                            <label>Data da Venda</label>
                            <div class="value">${dataVendaDisplay}</div>
                        </div>
                        <div class="info-item">
                            <label>Valor Total</label>
                            <div class="value">R$ ${parseFloat(venda.valor_total || 0).toLocaleString('pt-BR', {minimumFractionDigits: 2})}</div>
                        </div>
                        <div class="info-item">
                            <label>Status de Pagamento</label>
                            <div class="value">${venda.status_pagamento || 'Não Recebido'}</div>
                        </div>
                        <div class="info-item">
                            <label>Data de Vencimento</label>
                            <div class="value vencimento ${classVencimento}">${textoVencimento}</div>
                        </div>
                    </div>
                </div>

                <!-- Informações do Cliente (da tabela clientes via UASG) -->
                <div class="modal-section">
                    <h4><i class="fas fa-building"></i> Informações Detalhadas do Cliente</h4>
                    <div class="modal-section-content">
                        <div class="info-item">
                            <label>Nome do Órgão (Cadastro)</label>
                            <div class="value">${venda.cliente_nome_orgaos || 'Não encontrado'}</div>
                        </div>
                        <div class="info-item">
                            <label>CNPJ</label>
                            <div class="value">${formatCNPJ(venda.cliente_cnpj) || 'Não informado'}</div>
                        </div>
                        <div class="info-item">
                            <label>Endereço</label>
                            <div class="value">${venda.cliente_endereco || 'Não informado'}</div>
                        </div>
                        <div class="info-item">
                            <label>Telefone Principal</label>
                            <div class="value">${formatPhone(venda.cliente_telefone) || 'Não informado'}</div>
                        </div>
                        <div class="info-item">
                            <label>Telefone Secundário</label>
                            <div class="value">${formatPhone(venda.cliente_telefone2) || 'Não informado'}</div>
                        </div>
                        <div class="info-item">
                            <label>E-mail Principal</label>
                            <div class="value">${formatEmail(venda.cliente_email) || 'Não informado'}</div>
                        </div>
                        <div class="info-item">
                            <label>E-mail Secundário</label>
                            <div class="value">${formatEmail(venda.cliente_email2) || 'Não informado'}</div>
                        </div>
                    </div>
                    
                    ${venda.cliente_observacoes ? `
                    <div style="margin-top: 1rem;">
                        <h5 style="color: var(--primary-color); margin-bottom: 0.5rem;">
                            <i class="fas fa-comment"></i> Observações do Cliente
                        </h5>
                        <div class="info-item" style="grid-column: 1 / -1;">
                            <div class="value" style="background: #fff3cd; padding: 1rem; border-radius: var(--radius); border-left: 4px solid var(--warning-color);">${venda.cliente_observacoes}</div>
                        </div>
                    </div>
                    ` : ''}
                    
                    ${!venda.cliente_nome_orgaos ? `
                    <div style="margin-top: 1rem; padding: 1rem; background: #f8d7da; border-radius: var(--radius); border-left: 4px solid var(--danger-color);">
                        <p style="margin: 0; color: #721c24; font-weight: 600;">
                            <i class="fas fa-exclamation-triangle"></i> Cliente não encontrado no cadastro
                        </p>
                        <p style="margin: 0.5rem 0 0 0; color: #721c24; font-size: 0.9rem;">
                            O UASG "${venda.cliente_uasg}" não foi encontrado na tabela de clientes. Verifique se o cliente está cadastrado corretamente.
                        </p>
                    </div>
                    ` : ''}
                </div>

                <!-- Informações Complementares -->
                <div class="modal-section">
                    <h4><i class="fas fa-cog"></i> Informações Complementares</h4>
                    <div class="modal-section-content">
                        <div class="info-item">
                            <label>Pregão</label>
                            <div class="value">${venda.pregao || 'Não informado'}</div>
                        </div>
                        <div class="info-item">
                            <label>Classificação</label>
                            <div class="value">${venda.classificacao || 'Não informado'}</div>
                        </div>
                        <div class="info-item">
                            <label>Data de Cadastro</label>
                            <div class="value">${dataCadastro}</div>
                        </div>
                        <div class="info-item">
                            <label>Última Atualização</label>
                            <div class="value">${dataAtualizacao}</div>
                        </div>
                        <div class="info-item">
                            <label>Arquivo Anexado</label>
                            <div class="value">
                                ${venda.upload ? `<a href="${venda.upload}" target="_blank" class="arquivo-link">
                                    <i class="fas fa-download"></i> Baixar Arquivo
                                </a>` : 'Nenhum arquivo'}
                            </div>
                        </div>
                        ${venda.empenho_id ? `
                        <div class="info-item">
                            <label>ID do Empenho Originário</label>
                            <div class="value">${venda.empenho_id}</div>
                        </div>
                        ` : ''}
                    </div>
                </div>

                <!-- Produtos da Venda -->
                ${venda.produtos && venda.produtos.length > 0 ? `
                <div class="modal-section">
                    <h4><i class="fas fa-boxes"></i> Produtos da Venda (${venda.produtos.length})</h4>
                    <div style="overflow-x: auto;">
                        <table class="produtos-table">
                            <thead>
                                <tr>
                                    <th>Produto</th>
                                    <th>Quantidade</th>
                                    <th>Valor Unitário</th>
                                    <th>Valor Total</th>
                                    <th>Observação</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${venda.produtos.map(produto => `
                                    <tr>
                                        <td class="produto-nome">${produto.produto_nome || produto.produto_codigo || 'Produto ID: ' + produto.produto_id}</td>
                                        <td>${produto.quantidade || 0}</td>
                                        <td class="produto-valor">R$ ${parseFloat(produto.valor_unitario || 0).toLocaleString('pt-BR', {minimumFractionDigits: 2})}</td>
                                        <td class="produto-valor">R$ ${parseFloat(produto.valor_total || 0).toLocaleString('pt-BR', {minimumFractionDigits: 2})}</td>
                                        <td>${produto.produto_observacao || produto.observacao || '-'}</td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    </div>
                    <div style="margin-top: 1rem; padding: 1rem; background: #e7f3ff; border-radius: var(--radius); border-left: 4px solid var(--info-color);">
                        <p style="margin: 0; color: var(--info-color); font-weight: 600;">
                            <i class="fas fa-info-circle"></i> Informações dos Produtos
                        </p>
                        <p style="margin: 0.5rem 0 0 0; color: var(--medium-gray); font-size: 0.9rem;">
                            Os produtos são vinculados através da tabela <strong>venda_produtos</strong> usando o ID da venda.
                        </p>
                    </div>
                </div>
                ` : `
                <div class="modal-section">
                    <h4><i class="fas fa-boxes"></i> Produtos da Venda</h4>
                    <div style="text-align: center; padding: 2rem; color: var(--medium-gray);">
                        <i class="fas fa-box-open" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                        <p>Nenhum produto encontrado para esta venda.</p>
                        <p style="font-size: 0.9rem;">Os produtos devem ser cadastrados na tabela <strong>venda_produtos</strong>.</p>
                    </div>
                </div>
                `}

                <!-- Observações da Venda -->
                ${venda.observacao && venda.observacao.trim() ? `
                <div class="modal-section">
                    <h4><i class="fas fa-comment"></i> Observações da Venda</h4>
                    <div class="modal-section-content">
                        <div class="info-item" style="grid-column: 1 / -1;">
                            <div class="value" style="background: #f0f8ff; padding: 1rem; border-radius: var(--radius); border-left: 4px solid var(--info-color); white-space: pre-wrap;">${venda.observacao}</div>
                        </div>
                    </div>
                </div>
                ` : ''}
            </div>
        </div>
    `;

    // Adiciona event listener para o formulário de edição
    const editForm = document.getElementById('vendaEditForm');
    if (editForm) {
        editForm.addEventListener('submit', salvarEdicaoVenda);
    }
    
    console.log('✅ Detalhes da venda renderizados com sucesso');
}

// ===========================================
// FUNÇÕES UTILITÁRIAS PARA RENDERIZAÇÃO
// ===========================================

/**
 * Função para formatar email
 */
function formatEmail(email) {
    if (!email) return '';
    if (email.includes('@')) {
        return `<a href="mailto:${email}" style="color: var(--secondary-color); text-decoration: none;">${email}</a>`;
    }
    return email;
}

/**
 * Função para formatar datas
 */
function formatDate(dateString) {
    if (!dateString) return '';
    try {
        const date = new Date(dateString);
        return date.toLocaleDateString('pt-BR');
    } catch (error) {
        return dateString;
    }
}

/**
 * Função para formatar data e hora
 */
function formatDateTime(dateTimeString) {
    if (!dateTimeString) return '';
    try {
        const date = new Date(dateTimeString);
        return date.toLocaleString('pt-BR');
    } catch (error) {
        return dateTimeString;
    }
}

/**
 * Função para formatar moeda
 */
function formatCurrency(value) {
    if (!value) return 'R$ 0,00';
    try {
        const numValue = parseFloat(value);
        return 'R$ ' + numValue.toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    } catch (error) {
        return 'R$ 0,00';
    }
}

/**
 * Função para formatar CNPJ
 */
function formatCNPJ(cnpj) {
    if (!cnpj) return '';
    try {
        // Remove caracteres não numéricos
        const numbers = cnpj.replace(/\D/g, '');
        
        // Verifica se tem 14 dígitos
        if (numbers.length === 14) {
            return numbers.replace(/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/, '$1.$2.$3/$4-$5');
        }
        
        return cnpj; // Retorna original se não conseguir formatar
    } catch (error) {
        return cnpj;
    }
}

/**
 * Função para formatar telefone
 */
function formatPhone(phone) {
    if (!phone) return '';
    try {
        // Remove caracteres não numéricos
        const numbers = phone.replace(/\D/g, '');
        
        // Formata baseado na quantidade de dígitos
        if (numbers.length === 11) {
            // Celular: (11) 99999-9999
            return numbers.replace(/(\d{2})(\d{5})(\d{4})/, '($1) $2-$3');
        } else if (numbers.length === 10) {
            // Fixo: (11) 9999-9999
            return numbers.replace(/(\d{2})(\d{4})(\d{4})/, '($1) $2-$3');
        } else if (numbers.length === 8) {
            // Local: 9999-9999
            return numbers.replace(/(\d{4})(\d{4})/, '$1-$2');
        }
        
        return phone; // Retorna original se não conseguir formatar
    } catch (error) {
        return phone;
    }
}

/**
 * Fecha o modal
 */
window.closeModal = function() {
    if (isEditingVenda) {
        const confirmClose = confirm(
            'Você está editando a venda.\n\n' +
            'Tem certeza que deseja fechar sem salvar as alterações?\n\n' +
            'As alterações não salvas serão perdidas.'
        );
        
        if (!confirmClose) {
            return;
        }
    }
    
    const modal = document.getElementById("editModal");
    modal.style.display = "none";
    document.body.style.overflow = 'auto';
    
    currentVendaId = null;
    currentVendaData = null;
    isEditingVenda = false;
    
    console.log('✅ Modal fechado');
};

// ===========================================
// FUNÇÕES DE EDIÇÃO DA VENDA
// ===========================================

/**
 * Ativa o modo de edição da venda
 */
function editarVenda() {
    console.log('🖊️ Ativando modo de edição da venda');
    
    const viewMode = document.getElementById('vendaViewMode');
    const editForm = document.getElementById('vendaEditForm');
    const editarBtn = document.getElementById('editarBtn');
    const modalContent = document.querySelector('#editModal .modal-content');
    
    if (viewMode) viewMode.style.display = 'none';
    if (editForm) editForm.style.display = 'block';
    if (editarBtn) editarBtn.style.display = 'none';
    if (modalContent) modalContent.classList.add('edit-mode');
    
    isEditingVenda = true;
    
    showToast('Modo de edição ativado', 'info');
}

/**
 * Cancela a edição da venda
 */
function cancelarEdicao() {
    const confirmCancel = confirm(
        'Tem certeza que deseja cancelar a edição?\n\n' +
        'Todas as alterações não salvas serão perdidas.'
    );
    
    if (confirmCancel) {
        const viewMode = document.getElementById('vendaViewMode');
        const editForm = document.getElementById('vendaEditForm');
        const editarBtn = document.getElementById('editarBtn');
        const modalContent = document.querySelector('#editModal .modal-content');
        
        if (viewMode) viewMode.style.display = 'block';
        if (editForm) editForm.style.display = 'none';
        if (editarBtn) editarBtn.style.display = 'inline-flex';
        if (modalContent) modalContent.classList.remove('edit-mode');
        
        isEditingVenda = false;
        
        showToast('Edição cancelada', 'info');
    }
}

/**
 * Salva a edição da venda
 */
function salvarEdicaoVenda(event) {
    event.preventDefault();
    
    const form = event.target;
    const formData = new FormData(form);
    const submitBtn = document.getElementById('salvarBtn');
    
    // Debug: mostra os dados sendo enviados
    console.log('📝 Dados do formulário sendo enviados:');
    for (let [key, value] of formData.entries()) {
        console.log(`${key}: "${value}"`);
    }
    
    // Validações básicas no frontend
    const nf = formData.get('nf');
    const clienteUasg = formData.get('cliente_uasg');
    
    if (!nf || !nf.trim()) {
        showToast('Nota Fiscal é obrigatória', 'error');
        return;
    }
    
    if (!clienteUasg || !clienteUasg.trim()) {
        showToast('Cliente UASG é obrigatório', 'error');
        return;
    }
    
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...';
    }
    
    // Adiciona loading overlay
    const modalBody = document.querySelector('#editModal .modal-body');
    if (modalBody) {
        const loadingOverlay = document.createElement('div');
        loadingOverlay.className = 'loading-overlay';
        loadingOverlay.innerHTML = '<div class="spinner"></div>';
        modalBody.style.position = 'relative';
        modalBody.appendChild(loadingOverlay);
    }
    
    fetch(window.location.href, {
        method: 'POST',
        body: formData,
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => {
        console.log('📡 Status da resposta:', response.status);
        console.log('📡 Headers:', response.headers.get('content-type'));
        
        // Verifica se a resposta é JSON
        const contentType = response.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            // Se não é JSON, pega o texto para debug
            return response.text().then(text => {
                console.error('❌ Resposta não é JSON:', text.substring(0, 500));
                throw new Error('Resposta do servidor não é JSON válido. Verifique os logs do servidor.');
            });
        }
        
        return response.json();
    })
    .then(data => {
        console.log('✅ Resposta JSON:', data);
        
        if (data.success) {
            showToast('Venda atualizada com sucesso!', 'success');
            
            // Remove o modo de edição
            isEditingVenda = false;
            
            // Recarrega os dados do modal
            setTimeout(() => {
                openModal(currentVendaId);
            }, 1000);
            
        } else {
            throw new Error(data.error || 'Erro desconhecido ao salvar venda');
        }
    })
    .catch(error => {
        console.error('❌ Erro ao salvar venda:', error);
        showToast('Erro ao salvar: ' + error.message, 'error');
    })
    .finally(() => {
        // Remove loading overlay
        const loadingOverlay = document.querySelector('.loading-overlay');
        if (loadingOverlay) {
            loadingOverlay.remove();
        }
        
        // Restaura botão
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fas fa-save"></i> Salvar Alterações';
        }
    });
}

/**
 * Confirma exclusão durante a edição
 */
function confirmarExclusaoEdicao() {
    if (!currentVendaData) return;
    
    const confirmMessage = 
        `⚠️ ATENÇÃO: EXCLUSÃO PERMANENTE ⚠️\n\n` +
        `Tem certeza que deseja EXCLUIR permanentemente esta venda?\n\n` +
        `NF: ${currentVendaData.nf || 'N/A'}\n` +
        `Cliente: ${currentVendaData.cliente_nome_orgaos || currentVendaData.cliente_uasg || 'N/A'}\n` +
        `Valor: R$ ${parseFloat(currentVendaData.valor_total || 0).toLocaleString('pt-BR', {minimumFractionDigits: 2})}\n\n` +
        `⚠️ Esta ação NÃO PODE ser desfeita!\n\n` +
        `Digite "CONFIRMAR" para prosseguir:`;
    
    const confirmacao = prompt(confirmMessage);
    
    if (confirmacao === 'CONFIRMAR') {
        excluirVenda();
    } else if (confirmacao !== null) {
        showToast('Exclusão cancelada - confirmação incorreta', 'warning');
    }
}

/**
 * Exclui venda
 */
function excluirVenda() {
    if (!currentVendaId) return;
    
    const excluirBtn = document.getElementById('excluirBtn');
    if (excluirBtn) {
        excluirBtn.disabled = true;
        excluirBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Excluindo...';
    }
    
    // Adiciona loading overlay
    const modalBody = document.querySelector('#editModal .modal-body');
    if (modalBody) {
        const loadingOverlay = document.createElement('div');
        loadingOverlay.className = 'loading-overlay';
        loadingOverlay.innerHTML = '<div class="spinner"></div>';
        modalBody.style.position = 'relative';
        modalBody.appendChild(loadingOverlay);
    }
    
    const formData = new FormData();
    formData.append('delete_venda_id', currentVendaId);
    
    console.log('🗑️ Excluindo venda ID:', currentVendaId);
    
    fetch(window.location.href, {
        method: 'POST',
        body: formData,
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => {
        console.log('📡 Status da resposta exclusão:', response.status);
        
        // Verifica se a resposta é JSON
        const contentType = response.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            return response.text().then(text => {
                console.error('❌ Resposta não é JSON:', text.substring(0, 500));
                throw new Error('Resposta do servidor não é JSON válido. Verifique os logs do servidor.');
            });
        }
        
        return response.json();
    })
    .then(data => {
        console.log('✅ Resposta JSON exclusão:', data);
        
        if (data.success) {
            showToast('Venda excluída com sucesso!', 'success');
            
            closeModal();
            
            setTimeout(() => {
                window.location.reload();
            }, 1500);
            
        } else {
            throw new Error(data.error || 'Erro ao excluir venda');
        }
    })
    .catch(error => {
        console.error('❌ Erro ao excluir venda:', error);
        showToast('Erro ao excluir: ' + error.message, 'error');
    })
    .finally(() => {
        // Remove loading overlay
        const loadingOverlay = document.querySelector('.loading-overlay');
        if (loadingOverlay) {
            loadingOverlay.remove();
        }
        
        // Restaura botão
        if (excluirBtn) {
            excluirBtn.disabled = false;
            excluirBtn.innerHTML = '<i class="fas fa-trash"></i> Excluir';
        }
    });
}

/**
 * Confirma exclusão (modo visualização)
 */
function confirmarExclusao() {
    if (!currentVendaData) return;
    
    const confirmMessage = 
        `⚠️ ATENÇÃO: EXCLUSÃO PERMANENTE ⚠️\n\n` +
        `Tem certeza que deseja EXCLUIR permanentemente esta venda?\n\n` +
        `NF: ${currentVendaData.nf || 'N/A'}\n` +
        `Cliente: ${currentVendaData.cliente_nome_orgaos || currentVendaData.cliente_uasg || 'N/A'}\n` +
        `Valor: R$ ${parseFloat(currentVendaData.valor_total || 0).toLocaleString('pt-BR', {minimumFractionDigits: 2})}\n\n` +
        `⚠️ Esta ação NÃO PODE ser desfeita!\n\n` +
        `Digite "CONFIRMAR" para prosseguir:`;
    
    const confirmacao = prompt(confirmMessage);
    
    if (confirmacao === 'CONFIRMAR') {
        excluirVenda();
    } else if (confirmacao !== null) {
        showToast('Exclusão cancelada - confirmação incorreta', 'warning');
    }
}

/**
 * Imprime venda
 */
function imprimirVenda() {
    if (!currentVendaId) return;
    
    const printUrl = `imprimir_venda.php?id=${currentVendaId}`;
    window.open(printUrl, '_blank', 'width=800,height=600');
}

// ===========================================
// FUNÇÕES DO SISTEMA DE AUTENTICAÇÃO FINANCEIRA
// ===========================================

function openConfirmationModal(selectElement) {
    currentSelectElement = selectElement;
    
    currentContaData = {
        id: selectElement.dataset.id,
        nf: selectElement.dataset.nf,
        cliente: selectElement.dataset.cliente,
        valor: selectElement.dataset.valor,
        vencimento: selectElement.dataset.vencimento
    };

    document.getElementById('confirm-nf').textContent = currentContaData.nf;
    document.getElementById('confirm-cliente').textContent = currentContaData.cliente;
    document.getElementById('confirm-valor').textContent = currentContaData.valor;
    document.getElementById('confirm-vencimento').textContent = currentContaData.vencimento;

    document.getElementById('confirmationModal').style.display = 'block';
    document.body.style.overflow = 'hidden';
}

window.closeConfirmationModal = function() {
    if (currentSelectElement) {
        currentSelectElement.value = 'Não Recebido';
    }
    
    document.getElementById('confirmationModal').style.display = 'none';
    document.body.style.overflow = 'auto';
    currentSelectElement = null;
    currentContaData = {};
};

window.requestFinancialAuth = function() {
    document.getElementById('confirmationModal').style.display = 'none';
    document.getElementById('financialAuthModal').style.display = 'block';
    
    // Foca no campo de senha
    setTimeout(() => {
        document.getElementById('financialPassword').focus();
    }, 300);
};

window.closeFinancialAuthModal = function() {
    document.getElementById('financialAuthModal').style.display = 'none';
    document.getElementById('financialPassword').value = '';
    document.getElementById('authError').style.display = 'none';
    document.body.style.overflow = 'auto';
    
    // Reseta o select para o valor original
    if (currentSelectElement) {
        currentSelectElement.value = 'Não Recebido';
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
    const confirmBtn = document.getElementById('confirmAuthBtn');
    const loadingSpinner = document.getElementById('authLoadingSpinner');
    const confirmIcon = document.getElementById('authConfirmIcon');
    const confirmText = document.getElementById('authConfirmText');
    const authError = document.getElementById('authError');
    
    if (!senha) {
        showAuthError('Por favor, digite a senha do setor financeiro.');
        return;
    }

    // Mostra loading
    confirmBtn.disabled = true;
    loadingSpinner.style.display = 'inline-block';
    confirmIcon.style.display = 'none';
    confirmText.textContent = 'Verificando...';
    authError.style.display = 'none';

    // Processa a atualização do status com autenticação
    updateStatusWithAuth(currentContaData.id, 'Recebido', senha);
};

function showAuthError(message) {
    const authError = document.getElementById('authError');
    const authErrorMessage = document.getElementById('authErrorMessage');
    
    authErrorMessage.textContent = message;
    authError.style.display = 'block';
    
    // Limpa o campo de senha
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

function updateStatusWithAuth(id, status, senha) {
    fetch('contas_a_receber.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({
            update_status: '1',
            id: id,
            status_pagamento: status,
            financial_password: senha
        })
    })
    .then(response => response.json())
    .then(data => {
        resetAuthButton();
        
        if (data.success) {
            // Sucesso - remove a linha da tabela
            const row = document.querySelector(`tr[data-id='${id}']`);
            if (row) {
                row.style.transition = 'all 0.5s ease';
                row.style.opacity = '0';
                row.style.transform = 'translateX(-100%)';
                setTimeout(() => row.remove(), 500);
            }
            
            closeFinancialAuthModal();
            showSuccessMessage('Conta marcada como recebida com sucesso!');
            
            // Atualiza a página se não houver mais registros
            setTimeout(() => {
                const remainingRows = document.querySelectorAll('tbody tr').length;
                if (remainingRows <= 1) {
                    window.location.reload();
                }
            }, 1000);
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
// UTILITÁRIOS
// ===========================================

/**
 * Sistema de notificações toast
 */
function showToast(message, type = 'info', duration = 4000) {
    const existingToast = document.getElementById('toast');
    if (existingToast) {
        existingToast.remove();
    }
    
    const toast = document.createElement('div');
    toast.id = 'toast';
    toast.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        z-index: 10000;
        padding: 1rem 1.5rem;
        border-radius: var(--radius);
        color: white;
        font-weight: 600;
        font-size: 0.95rem;
        max-width: 400px;
        box-shadow: 0 8px 24px rgba(0,0,0,0.2);
        transform: translateX(100%);
        transition: transform 0.3s ease;
        display: flex;
        align-items: center;
        gap: 0.75rem;
    `;
    
    let backgroundColor, icon;
    switch(type) {
        case 'success':
            backgroundColor = 'var(--success-color)';
            icon = 'fas fa-check-circle';
            break;
        case 'error':
            backgroundColor = 'var(--danger-color)';
            icon = 'fas fa-exclamation-triangle';
            break;
        case 'warning':
            backgroundColor = 'var(--warning-color)';
            icon = 'fas fa-exclamation-circle';
            break;
        default:
            backgroundColor = 'var(--info-color)';
            icon = 'fas fa-info-circle';
    }
    
    toast.style.background = backgroundColor;
    toast.innerHTML = `
        <i class="${icon}"></i>
        <span>${message}</span>
        <button onclick="this.parentElement.remove()" style="background: none; border: none; color: white; font-size: 1.2rem; cursor: pointer; padding: 0; margin-left: auto;">
            <i class="fas fa-times"></i>
        </button>
    `;
    
    document.body.appendChild(toast);
    
    setTimeout(() => {
        toast.style.transform = 'translateX(0)';
    }, 100);
    
    setTimeout(() => {
        if (toast.parentElement) {
            toast.style.transform = 'translateX(100%)';
            setTimeout(() => {
                if (toast.parentElement) {
                    toast.remove();
                }
            }, 300);
        }
    }, duration);
}

// ===========================================
// INICIALIZAÇÃO E EVENT LISTENERS
// ===========================================

document.addEventListener('DOMContentLoaded', function() {
    console.log('🚀 LicitaSis - Sistema de Contas a Receber com Edição e Exclusão carregado');
    
    // Event listener para os selects de status (apenas se o usuário tem permissão de edição)
    <?php if ($permissionManager->hasPagePermission('financeiro', 'edit')): ?>
    document.querySelectorAll('.status-select').forEach(select => {
        select.addEventListener('change', function() {
            const newStatus = this.value;
            const previousStatus = this.dataset.previousValue || 'Não Recebido';
            
            if (newStatus === 'Recebido' && previousStatus !== 'Recebido') {
                openConfirmationModal(this);
            } else if (newStatus === 'Não Recebido') {
                updateStatus(this.dataset.id, newStatus, this);
            }
            
            this.dataset.previousValue = newStatus;
        });
        
        // Inicializa o valor anterior
        select.dataset.previousValue = select.value;
    });
    <?php endif; ?>

    // Função para atualizar status diretamente (sem autenticação)
    function updateStatus(id, status, selectElement) {
        fetch('contas_a_receber.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams({
                update_status: '1',
                id: id,
                status_pagamento: status
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                selectElement.dataset.previousValue = status;
                showSuccessMessage('Status atualizado com sucesso!');
            } else {
                alert('Erro ao atualizar status: ' + (data.error || 'Erro desconhecido'));
                selectElement.value = selectElement.dataset.previousValue || 'Não Recebido';
            }
        })
        .catch(error => {
            console.error('Erro na comunicação:', error);
            alert('Erro na comunicação com o servidor.');
            selectElement.value = selectElement.dataset.previousValue || 'Não Recebido';
        });
    }

    // JavaScript específico para o modal de inclusão
document.addEventListener('DOMContentLoaded', function() {
    // Adiciona listeners para atualizar resumo em tempo real
    const camposResumo = ['incluir_cliente', 'incluir_valor', 'incluir_tipo_receita', 'incluir_data_vencimento'];
    
    camposResumo.forEach(campoId => {
        const campo = document.getElementById(campoId);
        if (campo) {
            campo.addEventListener('input', atualizarResumo);
            campo.addEventListener('change', atualizarResumo);
        }
    });
    
    function atualizarResumo() {
        const cliente = document.getElementById('incluir_cliente').value;
        const valor = document.getElementById('incluir_valor').value;
        const tipo = document.getElementById('incluir_tipo_receita').value;
        const vencimento = document.getElementById('incluir_data_vencimento').value;
        
        const resumoDiv = document.getElementById('resumoConta');
        
        // Mostra o resumo apenas se pelo menos 2 campos estiverem preenchidos
        const camposPreenchidos = [cliente, valor, tipo, vencimento].filter(campo => campo && campo.trim()).length;
        
        if (camposPreenchidos >= 2) {
            resumoDiv.style.display = 'block';
            
            document.getElementById('resumo-cliente').textContent = cliente || '-';
            document.getElementById('resumo-valor').textContent = valor ? `R$ ${parseFloat(valor).toLocaleString('pt-BR', {minimumFractionDigits: 2})}` : '-';
            document.getElementById('resumo-tipo').textContent = tipo || '-';
            
            if (vencimento) {
                const dataVenc = new Date(vencimento);
                document.getElementById('resumo-vencimento').textContent = dataVenc.toLocaleDateString('pt-BR');
            } else {
                document.getElementById('resumo-vencimento').textContent = '-';
            }
        } else {
            resumoDiv.style.display = 'none';
        }
    }
    
    // Formatação automática do valor
    const campoValor = document.getElementById('incluir_valor');
    if (campoValor) {
        campoValor.addEventListener('blur', function() {
            if (this.value) {
                this.value = parseFloat(this.value).toFixed(2);
            }
        });
    }
    
    // Validação de data de vencimento (não pode ser anterior a hoje)
    const campoVencimento = document.getElementById('incluir_data_vencimento');
    if (campoVencimento) {
        campoVencimento.addEventListener('change', function() {
            const hoje = new Date();
            const vencimento = new Date(this.value);
            
            if (vencimento < hoje) {
                const confirmar = confirm(
                    'A data de vencimento está no passado.\n\n' +
                    'Tem certeza que deseja continuar com esta data?\n\n' +
                    'Recomendamos usar uma data futura.'
                );
                
                if (!confirmar) {
                    // Sugere data para 30 dias a partir de hoje
                    const sugestao = new Date();
                    sugestao.setDate(sugestao.getDate() + 30);
                    this.value = sugestao.toISOString().split('T')[0];
                }
            }
        });
    }
});

    // Enter para confirmar senha no modal de autenticação
    document.getElementById('financialPassword').addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            confirmFinancialAuth();
        }
    });

    // Fecha modais ao clicar fora
    window.onclick = function(event) {
        const modal = document.getElementById("editModal");
        const confirmModal = document.getElementById("confirmationModal");
        const authModal = document.getElementById("financialAuthModal");
        
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

    // Tecla ESC para fechar modais
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            if (isEditingVenda) {
                cancelarEdicao();
            } else {
                closeModal();
            }
            closeConfirmationModal();
            closeFinancialAuthModal();
        }
    });

    // ===========================================
// FUNÇÕES DO MODAL DE INCLUSÃO
// ===========================================

/**
 * Abre o modal de inclusão de nova conta
 */
window.abrirModalIncluir = function() {
    console.log('📝 Abrindo modal de inclusão de conta');
    
    const modal = document.getElementById('incluirModal');
    const form = document.getElementById('incluirContaForm');
    
    if (!modal || !form) {
        console.error('❌ Modal ou formulário de inclusão não encontrado');
        showToast('Erro: Modal não encontrado', 'error');
        return;
    }
    
    // Limpa o formulário
    form.reset();
    
    // Define data padrão como hoje
    const hoje = new Date().toISOString().split('T')[0];
    document.getElementById('incluir_data').value = hoje;
    
    // Mostra o modal
    modal.style.display = 'block';
    document.body.style.overflow = 'hidden';
    
    // Foca no primeiro campo
    setTimeout(() => {
        document.getElementById('incluir_cliente').focus();
    }, 300);
};

/**
 * Fecha o modal de inclusão
 */
window.fecharModalIncluir = function() {
    const modal = document.getElementById('incluirModal');
    modal.style.display = 'none';
    document.body.style.overflow = 'auto';
    
    // Limpa sugestões de clientes
    hideSuggestions();
    
    console.log('✅ Modal de inclusão fechado');
};

/**
 * Processa a inclusão da nova conta
 */
function processarInclusaoConta(event) {
    event.preventDefault();
    
    const form = event.target;
    const formData = new FormData(form);
    const submitBtn = document.getElementById('salvarIncluirBtn');
    
    // Debug: mostra os dados sendo enviados
    console.log('📝 Dados da nova conta sendo enviados:');
    for (let [key, value] of formData.entries()) {
        console.log(`${key}: "${value}"`);
    }
    
    // Validações básicas no frontend
    const cliente = formData.get('cliente');
    const tipoReceita = formData.get('tipo_receita');
    const valorTotal = formData.get('valor_total');
    const dataVencimento = formData.get('data_vencimento');
    
    if (!cliente || !cliente.trim()) {
        showToast('Cliente é obrigatório', 'error');
        return;
    }
    
    if (!tipoReceita || !tipoReceita.trim()) {
        showToast('Tipo de Receita é obrigatório', 'error');
        return;
    }
    
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...';
    }
    
    // Adiciona loading overlay no modal
    const modalBody = document.querySelector('#incluirModal .modal-body');
    if (modalBody) {
        const loadingOverlay = document.createElement('div');
        loadingOverlay.className = 'loading-overlay';
        loadingOverlay.innerHTML = '<div class="spinner"></div>';
        modalBody.style.position = 'relative';
        modalBody.appendChild(loadingOverlay);
    }
    
    fetch(window.location.href, {
        method: 'POST',
        body: formData,
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => {
        console.log('📡 Status da resposta inclusão:', response.status);
        
        const contentType = response.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            return response.text().then(text => {
                console.error('❌ Resposta não é JSON:', text.substring(0, 500));
                throw new Error('Resposta do servidor não é JSON válido.');
            });
        }
        
        return response.json();
    })
    .then(data => {
        console.log('✅ Resposta JSON inclusão:', data);
        
        if (data.success) {
            showToast('Conta incluída com sucesso!', 'success');
            
            fecharModalIncluir();
            
            // Recarrega a página para mostrar a nova conta
            setTimeout(() => {
                window.location.reload();
            }, 1500);
            
        } else {
            throw new Error(data.error || 'Erro desconhecido ao incluir conta');
        }
    })
    .catch(error => {
        console.error('❌ Erro ao incluir conta:', error);
        showToast('Erro ao incluir: ' + error.message, 'error');
    })
    .finally(() => {
        // Remove loading overlay
        const loadingOverlay = document.querySelector('#incluirModal .loading-overlay');
        if (loadingOverlay) {
            loadingOverlay.remove();
        }
        
        // Restaura botão
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fas fa-save"></i> Salvar Conta';
        }
    });
}

// Event listener para o formulário de inclusão
document.addEventListener('DOMContentLoaded', function() {
    const incluirForm = document.getElementById('incluirContaForm');
    if (incluirForm) {
        incluirForm.addEventListener('submit', processarInclusaoConta);
    }
    
    // Fecha modal de inclusão com ESC
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            fecharModalIncluir();
        }
    });
    
    // Fecha modal ao clicar fora
    window.onclick = function(event) {
        const incluirModal = document.getElementById('incluirModal');
        if (event.target === incluirModal) {
            fecharModalIncluir();
        }
    };
});

    // Auto-foco na pesquisa
    const searchInput = document.querySelector('.search-input');
    if (searchInput && !searchInput.value) {
        searchInput.focus();
    }

    // Animação das linhas da tabela
    const tableRows = document.querySelectorAll('tbody tr');
    tableRows.forEach((row, index) => {
        row.style.opacity = '0';
        row.style.transform = 'translateY(20px)';
        setTimeout(() => {
            row.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
            row.style.opacity = '1';
            row.style.transform = 'translateY(0)';
        }, index * 50);
    });

    // ===========================================
// SISTEMA DE BUSCA DE CLIENTES COM AUTOCOMPLETE
// ===========================================

let clientesSearchTimeout;
let selectedClienteIndex = -1;
let clientesSuggestions = [];

function initClienteAutocomplete() {
    const clienteInput = document.getElementById('incluir_cliente');
    const suggestionsDropdown = document.getElementById('clientes-suggestions');
    
    if (!clienteInput || !suggestionsDropdown) return;
    
    // Event listener para digitação
    clienteInput.addEventListener('input', function(e) {
        const termo = e.target.value.trim();
        
        if (termo.length < 2) {
            hideSuggestions();
            return;
        }
        
        // Debounce para evitar muitas requisições
        clearTimeout(clientesSearchTimeout);
        clientesSearchTimeout = setTimeout(() => {
            buscarClientes(termo);
        }, 300);
    });
    
    // Event listener para teclas de navegação
    clienteInput.addEventListener('keydown', function(e) {
        const suggestionsVisible = suggestionsDropdown.classList.contains('show');
        
        if (!suggestionsVisible) return;
        
        switch(e.key) {
            case 'ArrowDown':
                e.preventDefault();
                navigateSuggestions(1);
                break;
            case 'ArrowUp':
                e.preventDefault();
                navigateSuggestions(-1);
                break;
            case 'Enter':
                e.preventDefault();
                if (selectedClienteIndex >= 0) {
                    selectCliente(clientesSuggestions[selectedClienteIndex]);
                }
                break;
            case 'Escape':
                hideSuggestions();
                break;
        }
    });
    
    // Fechar sugestões ao clicar fora
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.autocomplete-container')) {
            hideSuggestions();
        }
    });
}

function buscarClientes(termo) {
    const suggestionsDropdown = document.getElementById('clientes-suggestions');
    
    // Mostra loading
    suggestionsDropdown.innerHTML = '<div class="suggestions-loading"><i class="fas fa-spinner fa-spin"></i> Buscando clientes...</div>';
    suggestionsDropdown.classList.add('show');
    
    fetch(`buscar_clientes.php?termo=${encodeURIComponent(termo)}`)
        .then(response => {
            if (!response.ok) {
                throw new Error('Erro na requisição');
            }
            return response.json();
        })
        .then(data => {
            if (data.error) {
                throw new Error(data.error);
            }
            
            displaySuggestions(data);
        })
        .catch(error => {
            console.error('Erro na busca de clientes:', error);
            suggestionsDropdown.innerHTML = '<div class="suggestions-empty"><i class="fas fa-exclamation-triangle"></i> Erro ao buscar clientes</div>';
        });
}

function displaySuggestions(clientes) {
    const suggestionsDropdown = document.getElementById('clientes-suggestions');
    clientesSuggestions = clientes;
    selectedClienteIndex = -1;
    
    if (clientes.length === 0) {
        suggestionsDropdown.innerHTML = '<div class="suggestions-empty"><i class="fas fa-search"></i> Nenhum cliente encontrado</div>';
        return;
    }
    
    let html = '';
    clientes.forEach((cliente, index) => {
        html += `
            <div class="suggestion-item" data-index="${index}" onclick="selectCliente(clientesSuggestions[${index}])">
                <div class="suggestion-nome">${escapeHtml(cliente.nome)}</div>
                <div class="suggestion-detalhes">
                    <div class="suggestion-detalhe">
                        <i class="fas fa-hashtag"></i>
                        <span>UASG: ${escapeHtml(cliente.uasg)}</span>
                    </div>
                    ${cliente.cnpj ? `
                    <div class="suggestion-detalhe">
                        <i class="fas fa-id-card"></i>
                        <span>CNPJ: ${formatCNPJ(cliente.cnpj)}</span>
                    </div>
                    ` : ''}
                    ${cliente.telefone ? `
                    <div class="suggestion-detalhe">
                        <i class="fas fa-phone"></i>
                        <span>${escapeHtml(cliente.telefone)}</span>
                    </div>
                    ` : ''}
                </div>
            </div>
        `;
    });
    
    suggestionsDropdown.innerHTML = html;
    suggestionsDropdown.classList.add('show');
}

function navigateSuggestions(direction) {
    const suggestions = document.querySelectorAll('.suggestion-item');
    if (suggestions.length === 0) return;
    
    // Remove highlight anterior
    suggestions.forEach(item => item.classList.remove('highlighted'));
    
    // Calcula novo índice
    selectedClienteIndex += direction;
    
    if (selectedClienteIndex < 0) {
        selectedClienteIndex = suggestions.length - 1;
    } else if (selectedClienteIndex >= suggestions.length) {
        selectedClienteIndex = 0;
    }
    
    // Adiciona highlight
    suggestions[selectedClienteIndex].classList.add('highlighted');
    suggestions[selectedClienteIndex].scrollIntoView({ block: 'nearest' });
}

function selectCliente(cliente) {
    const clienteInput = document.getElementById('incluir_cliente');
    
    // Preenche o campo com o nome do cliente
    clienteInput.value = cliente.nome;
    
    // Opcional: Preencher outros campos automaticamente se existirem
    // Exemplo: se houver campo para UASG no formulário
    const uasgField = document.getElementById('incluir_uasg');
    if (uasgField) {
        uasgField.value = cliente.uasg;
    }
    
    hideSuggestions();
    
    // Atualiza o resumo se a função existir
    if (typeof atualizarResumo === 'function') {
        atualizarResumo();
    }
    
    // Foca no próximo campo
    const nextField = document.getElementById('incluir_valor') || document.getElementById('incluir_tipo_receita');
    if (nextField) {
        nextField.focus();
    }
    
    // Mostra confirmação
    showToast(`Cliente selecionado: ${cliente.nome}`, 'success');
}

function hideSuggestions() {
    const suggestionsDropdown = document.getElementById('clientes-suggestions');
    suggestionsDropdown.classList.remove('show');
    selectedClienteIndex = -1;
    clientesSuggestions = [];
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Inicializar quando o modal for aberto
const originalAbrirModalIncluir = window.abrirModalIncluir;
window.abrirModalIncluir = function() {
    originalAbrirModalIncluir();
    
    // Inicializa o autocomplete após o modal estar visível
    setTimeout(() => {
        initClienteAutocomplete();
    }, 100);
};

    // Torna as funções disponíveis globalmente
    window.editarVenda = editarVenda;
    window.cancelarEdicao = cancelarEdicao;
    window.confirmarExclusao = confirmarExclusao;
    window.confirmarExclusaoEdicao = confirmarExclusaoEdicao;
    window.imprimirVenda = imprimirVenda;
    window.showToast = showToast;
    
    console.log('✅ Sistema de Contas a Receber com funcionalidades de edição carregado com sucesso!');
});

// Cleanup quando a página é descarregada
window.addEventListener('beforeunload', function(event) {
    if (isEditingVenda) {
        const message = 'Você tem alterações não salvas. Tem certeza que deseja sair?';
        event.returnValue = message;
        return message;
    }
});

console.log('🎉 Sistema LicitaSis - Contas a Receber v2.0 ATUALIZADO com Edição e Exclusão carregado com sucesso!');
</script>

<!-- Adiciona animações CSS dinamicamente -->
<style>
    @keyframes slideInRight {
        from { transform: translateX(100%); opacity: 0; }
        to { transform: translateX(0); opacity: 1; }
    }
    @keyframes slideOutRight {
        from { transform: translateX(0); opacity: 1; }
        to { transform: translateX(100%); opacity: 0; }
    }
    .arquivo-link {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        color: var(--secondary-color);
        text-decoration: none;
        padding: 0.5rem 1rem;
        border: 1px solid var(--secondary-color);
        border-radius: var(--radius);
        transition: var(--transition);
        font-size: 0.9rem;
    }
    .arquivo-link:hover {
        background: var(--secondary-color);
        color: white;
        transform: translateY(-1px);
    }
</style>

</body>
</html>

<?php
// Finaliza o buffer de saída
ob_end_flush();
?>