<?php
session_start();
header('Content-Type: application/json');

// Verifica se o usuário está logado
if (!isset($_SESSION['user'])) {
    echo json_encode(['success' => false, 'message' => 'Usuário não autenticado']);
    exit();
}

// Inclui conexão e permissões
require_once 'db.php';
require_once 'permissions.php';
require_once 'includes/audit.php';

try {
    $permissionManager = initPermissions($pdo);

    // Verifica permissão para editar vendas
    if (!$permissionManager->hasPermission('vendas', 'edit')) {
        throw new Exception('Sem permissão para editar vendas');
    }

    // Verifica se é uma requisição POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Método não permitido');
    }

    // Obtém e valida os dados da requisição
    $venda_id = filter_input(INPUT_POST, 'venda_id', FILTER_SANITIZE_NUMBER_INT);
    $numero_nf = trim(filter_input(INPUT_POST, 'numero_nf', FILTER_SANITIZE_STRING));
    $status_pagamento = trim(filter_input(INPUT_POST, 'status_pagamento', FILTER_SANITIZE_STRING));
    $observacao = trim(filter_input(INPUT_POST, 'observacao', FILTER_SANITIZE_STRING));

    // Validações adicionais
    if (empty($venda_id)) {
        throw new Exception('ID da venda é obrigatório');
    }

    // Valida status_pagamento
    $status_permitidos = ['Pendente', 'Recebido'];
    if (!in_array($status_pagamento, $status_permitidos)) {
        throw new Exception('Status de pagamento inválido');
    }

    // Inicia a transação
    $pdo->beginTransaction();

    // Verifica se a venda existe e pertence ao cliente
    $sql_check = "SELECT v.*, c.uasg, c.nome_orgaos 
                  FROM vendas v 
                  INNER JOIN clientes c ON v.cliente_uasg = c.uasg 
                  WHERE v.id = :venda_id
                  FOR UPDATE"; // Adiciona lock na linha

    $stmt_check = $pdo->prepare($sql_check);
    $stmt_check->bindParam(':venda_id', $venda_id, PDO::PARAM_INT);
    $stmt_check->execute();
    
    $venda = $stmt_check->fetch(PDO::FETCH_ASSOC);
    
    if (!$venda) {
        throw new Exception('Venda não encontrada');
    }

    // Prepara os dados para atualização
    $update_fields = [];
    $params = [':venda_id' => $venda_id];

    // Adiciona campos apenas se foram alterados
    if ($numero_nf !== $venda['numero_nf']) {
        $update_fields[] = "numero_nf = :numero_nf";
        $params[':numero_nf'] = $numero_nf;
    }

    if ($status_pagamento !== $venda['status_pagamento']) {
        $update_fields[] = "status_pagamento = :status_pagamento";
        $params[':status_pagamento'] = $status_pagamento;
    }

    if ($observacao !== $venda['observacao']) {
        $update_fields[] = "observacao = :observacao";
        $params[':observacao'] = $observacao;
    }

    // Só atualiza se houver mudanças
    if (!empty($update_fields)) {
        $update_fields[] = "updated_at = NOW()";
        
        $sql_update = "UPDATE vendas SET " . 
                      implode(", ", $update_fields) . 
                      " WHERE id = :venda_id";

        $stmt_update = $pdo->prepare($sql_update);
        $success = $stmt_update->execute($params);

        if (!$success) {
            throw new Exception('Erro ao atualizar venda');
        }

        // Registra a ação no log de auditoria
        logUserAction('UPDATE', 'vendas', [
            'venda_id' => $venda_id,
            'numero_nf' => $numero_nf,
            'status_pagamento' => $status_pagamento,
            'cliente_uasg' => $venda['uasg'],
            'cliente_nome' => $venda['nome_orgaos']
        ]);
    }

    // Confirma a transação
    $pdo->commit();

    // Busca dados atualizados
    $sql_updated = "SELECT v.*, c.nome_orgaos 
                    FROM vendas v 
                    INNER JOIN clientes c ON v.cliente_uasg = c.uasg 
                    WHERE v.id = :venda_id";
    $stmt_updated = $pdo->prepare($sql_updated);
    $stmt_updated->execute([':venda_id' => $venda_id]);
    $updated_venda = $stmt_updated->fetch(PDO::FETCH_ASSOC);

    // Retorna sucesso
    echo json_encode([
        'success' => true,
        'message' => 'Venda atualizada com sucesso',
        'data' => [
            'venda_id' => $venda_id,
            'numero_nf' => $updated_venda['numero_nf'],
            'status_pagamento' => $updated_venda['status_pagamento'],
            'observacao' => $updated_venda['observacao'],
            'updated_at' => $updated_venda['updated_at']
        ]
    ]);

} catch (Exception $e) {
    // Reverte a transação em caso de erro
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    // Registra o erro no log
    error_log("[Erro Update Venda] " . $e->getMessage() . " - User: " . $_SESSION['user']['id']);
    
    // Retorna erro
    echo json_encode([
        'success' => false,
        'message' => 'Erro ao atualizar venda: ' . $e->getMessage()
    ]);
}
?>