<?php
/*
 * delete_venda.php
 * Sistema de exclusão de vendas - LicitaSis
 * Exclui venda e seus produtos associados
 */

session_start();

// Define o header como JSON para todas as respostas
header('Content-Type: application/json; charset=utf-8');

// Função para retornar resposta JSON e encerrar
function returnJson($success, $message, $data = null) {
    $response = [
        'success' => $success,
        'message' => $message,
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    if ($data !== null) {
        $response['data'] = $data;
    }
    
    echo json_encode($response);
    exit();
}

// Verifica se é uma requisição POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    returnJson(false, 'Método não permitido. Use POST.');
}

// Verifica autenticação
if (!isset($_SESSION['user'])) {
    returnJson(false, 'Usuário não autenticado. Faça login novamente.');
}

// Inclui arquivos necessários
try {
    include('db.php');
    include('permissions.php');
    include('includes/audit.php');
} catch (Exception $e) {
    error_log("Erro ao incluir arquivos: " . $e->getMessage());
    returnJson(false, 'Erro interno do sistema. Contate o administrador.');
}

// Verifica conexão com banco
if (!isset($pdo) || !$pdo) {
    error_log("Conexão com banco não estabelecida");
    returnJson(false, 'Erro de conexão com banco de dados.');
}

try {
    // Inicializa sistema de permissões
    $permissionManager = initPermissions($pdo);
    
    // Verifica permissão para deletar vendas
    if (!$permissionManager->hasPagePermission('vendas', 'delete')) {
        returnJson(false, 'Você não tem permissão para excluir vendas.');
    }
    
} catch (Exception $e) {
    error_log("Erro no sistema de permissões: " . $e->getMessage());
    returnJson(false, 'Erro ao verificar permissões.');
}

// Validação dos dados recebidos
try {
    // Debug: registra dados recebidos
    error_log("DELETE VENDA - Dados recebidos: " . print_r($_POST, true));
    
    // Verifica se a ação é delete
    if (!isset($_POST['action']) || $_POST['action'] !== 'delete') {
        $action = $_POST['action'] ?? 'não informado';
        returnJson(false, "Ação inválida. Esperado: 'delete', Recebido: '{$action}'");
    }
    
    // Verifica se o ID da venda foi informado
    if (!isset($_POST['venda_id']) || empty($_POST['venda_id'])) {
        returnJson(false, 'ID da venda é obrigatório.');
    }
    
    // Converte e valida o ID
    $venda_id = filter_var($_POST['venda_id'], FILTER_VALIDATE_INT);
    if ($venda_id === false || $venda_id <= 0) {
        returnJson(false, "ID da venda inválido: " . $_POST['venda_id']);
    }
    
    error_log("DELETE VENDA - Processando exclusão da venda ID: {$venda_id}");
    
} catch (Exception $e) {
    error_log("Erro na validação: " . $e->getMessage());
    returnJson(false, 'Erro na validação dos dados: ' . $e->getMessage());
}

// Processo de exclusão
try {
    // Inicia transação
    $pdo->beginTransaction();
    error_log("DELETE VENDA - Transação iniciada para venda ID: {$venda_id}");
    
    // 1. Busca dados da venda para validação e auditoria
    $sql_venda = "SELECT 
                    id, 
                    numero, 
                    nf, 
                    cliente_uasg, 
                    valor_total,
                    status_pagamento,
                    data,
                    created_at
                  FROM vendas 
                  WHERE id = :venda_id";
    
    $stmt_venda = $pdo->prepare($sql_venda);
    $stmt_venda->bindParam(':venda_id', $venda_id, PDO::PARAM_INT);
    $stmt_venda->execute();
    $venda_data = $stmt_venda->fetch(PDO::FETCH_ASSOC);
    
    // Verifica se a venda existe
    if (!$venda_data) {
        $pdo->rollback();
        returnJson(false, "Venda não encontrada com ID: {$venda_id}");
    }
    
    error_log("DELETE VENDA - Venda encontrada: " . print_r($venda_data, true));
    
    // 2. Busca dados do cliente para auditoria
    $cliente_nome = 'N/A';
    if (!empty($venda_data['cliente_uasg'])) {
        try {
            $sql_cliente = "SELECT nome_orgaos FROM clientes WHERE uasg = :uasg LIMIT 1";
            $stmt_cliente = $pdo->prepare($sql_cliente);
            $stmt_cliente->bindParam(':uasg', $venda_data['cliente_uasg']);
            $stmt_cliente->execute();
            $cliente_data = $stmt_cliente->fetch(PDO::FETCH_ASSOC);
            if ($cliente_data) {
                $cliente_nome = $cliente_data['nome_orgaos'];
            }
        } catch (Exception $e) {
            error_log("Erro ao buscar cliente: " . $e->getMessage());
        }
    }
    
    // 3. Verifica se existe tabela venda_produtos e busca produtos associados
    $produtos_deletados = 0;
    try {
        $sql_check_vp = "SHOW TABLES LIKE 'venda_produtos'";
        $stmt_check_vp = $pdo->prepare($sql_check_vp);
        $stmt_check_vp->execute();
        $vp_exists = $stmt_check_vp->rowCount() > 0;
        
        if ($vp_exists) {
            // Primeiro, conta quantos produtos serão deletados
            $sql_count_produtos = "SELECT COUNT(*) as total FROM venda_produtos WHERE venda_id = :venda_id";
            $stmt_count = $pdo->prepare($sql_count_produtos);
            $stmt_count->bindParam(':venda_id', $venda_id, PDO::PARAM_INT);
            $stmt_count->execute();
            $count_result = $stmt_count->fetch(PDO::FETCH_ASSOC);
            $produtos_associados = $count_result['total'] ?? 0;
            
            error_log("DELETE VENDA - Produtos associados encontrados: {$produtos_associados}");
            
            // Exclui os produtos da venda
            if ($produtos_associados > 0) {
                $sql_delete_produtos = "DELETE FROM venda_produtos WHERE venda_id = :venda_id";
                $stmt_delete_produtos = $pdo->prepare($sql_delete_produtos);
                $stmt_delete_produtos->bindParam(':venda_id', $venda_id, PDO::PARAM_INT);
                
                if ($stmt_delete_produtos->execute()) {
                    $produtos_deletados = $stmt_delete_produtos->rowCount();
                    error_log("DELETE VENDA - Produtos deletados: {$produtos_deletados}");
                } else {
                    throw new Exception("Falha ao executar exclusão dos produtos");
                }
            }
        }
    } catch (Exception $e) {
        error_log("Erro ao processar produtos: " . $e->getMessage());
        // Não falha o processo se houver erro com produtos
    }
    
    // 4. Exclui a venda principal
    $sql_delete_venda = "DELETE FROM vendas WHERE id = :venda_id";
    $stmt_delete_venda = $pdo->prepare($sql_delete_venda);
    $stmt_delete_venda->bindParam(':venda_id', $venda_id, PDO::PARAM_INT);
    
    if (!$stmt_delete_venda->execute()) {
        $pdo->rollback();
        $errorInfo = $stmt_delete_venda->errorInfo();
        error_log("DELETE VENDA - Erro SQL: " . print_r($errorInfo, true));
        returnJson(false, "Erro ao executar exclusão da venda: " . $errorInfo[2]);
    }
    
    $rows_affected = $stmt_delete_venda->rowCount();
    error_log("DELETE VENDA - Venda deletada. Rows affected: {$rows_affected}");
    
    if ($rows_affected === 0) {
        $pdo->rollback();
        returnJson(false, 'Venda não pôde ser excluída. Verifique se ela ainda existe.');
    }
    
    // 5. Registra a ação na auditoria
    try {
        logUserAction('DELETE', 'vendas', [
            'venda_id' => $venda_id,
            'numero' => $venda_data['numero'] ?? '',
            'nf' => $venda_data['nf'] ?? '',
            'cliente_uasg' => $venda_data['cliente_uasg'] ?? '',
            'cliente_nome' => $cliente_nome,
            'valor_total' => $venda_data['valor_total'] ?? 0,
            'status_pagamento' => $venda_data['status_pagamento'] ?? '',
            'produtos_deletados' => $produtos_deletados,
            'user_id' => $_SESSION['user']['id'] ?? 'N/A',
            'user_name' => $_SESSION['user']['nome'] ?? 'N/A'
        ]);
        error_log("DELETE VENDA - Auditoria registrada");
    } catch (Exception $e) {
        error_log("Erro ao registrar auditoria: " . $e->getMessage());
        // Não falha o processo se houver erro na auditoria
    }
    
    // 6. Confirma todas as operações
    $pdo->commit();
    error_log("DELETE VENDA - Transação confirmada com sucesso");
    
    // Prepara dados de retorno
    $response_data = [
        'venda_id' => $venda_id,
        'numero' => $venda_data['numero'] ?? '',
        'nf' => $venda_data['nf'] ?? '',
        'cliente_nome' => $cliente_nome,
        'valor_total' => $venda_data['valor_total'] ?? 0,
        'produtos_deletados' => $produtos_deletados,
        'rows_affected' => $rows_affected
    ];
    
    $message = "Venda excluída com sucesso!";
    if ($produtos_deletados > 0) {
        $message .= " (incluindo {$produtos_deletados} produto(s) associado(s))";
    }
    
    returnJson(true, $message, $response_data);
    
} catch (PDOException $e) {
    // Erro de banco de dados
    if ($pdo->inTransaction()) {
        $pdo->rollback();
    }
    
    $error_message = "Erro de banco de dados: " . $e->getMessage();
    error_log("DELETE VENDA - PDO Error: " . $error_message);
    
    // Não expor detalhes do banco em produção
    $user_message = "Erro ao excluir venda. Contate o administrador.";
    if (defined('DEBUG') && DEBUG === true) {
        $user_message = $error_message;
    }
    
    returnJson(false, $user_message);
    
} catch (Exception $e) {
    // Erro geral
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollback();
    }
    
    $error_message = "Erro geral: " . $e->getMessage();
    error_log("DELETE VENDA - General Error: " . $error_message);
    
    returnJson(false, $error_message);
}

// Este ponto nunca deve ser alcançado devido aos returnJson() acima
error_log("DELETE VENDA - Fim inesperado do script");
returnJson(false, 'Erro inesperado no processamento.');
?>