<?php
session_start();
require_once('db.php');

// Inclui sistema de permissões se existir
if (file_exists('permissions.php')) {
    include('permissions.php');
    $permissionManager = initPermissions($pdo);
} else {
    // Fallback se não há sistema de permissões
    $permissionManager = null;
}

// Inclui sistema de auditoria se existir
if (file_exists('includes/audit.php')) {
    include('includes/audit.php');
}

// Verifica se o usuário está logado
if (!isset($_SESSION['user'])) {
    echo json_encode(['success' => false, 'message' => 'Usuário não logado']);
    exit();
}

// Função para registrar ação (se auditoria estiver disponível)
function logAction($action, $details = '') {
    if (function_exists('logUserAction')) {
        logUserAction($action, $details);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $empenho_id = $_POST['empenho_id'] ?? '';
    
    // Validação básica
    if (empty($action) || empty($empenho_id)) {
        echo json_encode(['success' => false, 'message' => 'Ação ou ID do empenho não fornecidos']);
        exit();
    }
    
    if (!is_numeric($empenho_id)) {
        echo json_encode(['success' => false, 'message' => 'ID do empenho deve ser numérico']);
        exit();
    }
    
    if ($action === 'update') {
        // Verifica permissão de edição
        if ($permissionManager && !$permissionManager->hasPagePermission('empenhos', 'edit')) {
            echo json_encode(['success' => false, 'message' => 'Sem permissão para editar empenhos']);
            exit();
        }
        
        try {
            $pdo->beginTransaction();
            
            // Captura e valida dados do formulário
            $numero = trim($_POST['numero'] ?? '');
            $classificacao = trim($_POST['classificacao'] ?? '');
            $pregao = trim($_POST['pregao'] ?? '');
            $item = trim($_POST['item'] ?? '');
            $prioridade = trim($_POST['prioridade'] ?? 'Normal');
            $data = $_POST['data'] ?? null;
            $valor_total_empenho = floatval($_POST['valor_total_empenho'] ?? 0);
            $produto = trim($_POST['produto'] ?? '');
            $produto2 = trim($_POST['produto2'] ?? '');
            $observacao = trim($_POST['observacao'] ?? '');
            
            // Validações
            if (empty($numero)) {
                throw new Exception('Número do empenho é obrigatório');
            }
            
            if (empty($classificacao)) {
                throw new Exception('Classificação é obrigatória');
            }
            
            if ($valor_total_empenho < 0) {
                throw new Exception('Valor total não pode ser negativo');
            }
            
            // Verifica se o empenho existe
            $sql_check = "SELECT id, numero FROM empenhos WHERE id = :empenho_id";
            $stmt_check = $pdo->prepare($sql_check);
            $stmt_check->bindParam(':empenho_id', $empenho_id, PDO::PARAM_INT);
            $stmt_check->execute();
            $empenho_existente = $stmt_check->fetch(PDO::FETCH_ASSOC);
            
            if (!$empenho_existente) {
                throw new Exception('Empenho não encontrado');
            }
            
            // Verifica duplicação de número (exceto o próprio empenho)
            $sql_duplicate = "SELECT id FROM empenhos WHERE numero = :numero AND id != :empenho_id LIMIT 1";
            $stmt_duplicate = $pdo->prepare($sql_duplicate);
            $stmt_duplicate->bindParam(':numero', $numero);
            $stmt_duplicate->bindParam(':empenho_id', $empenho_id, PDO::PARAM_INT);
            $stmt_duplicate->execute();
            
            if ($stmt_duplicate->fetch()) {
                throw new Exception("Já existe outro empenho com o número '{$numero}'");
            }
            
            // Atualiza a tabela empenhos
            $sql_update = "UPDATE empenhos SET 
                            numero = :numero,
                            classificacao = :classificacao,
                            pregao = :pregao,
                            item = :item,
                            prioridade = :prioridade,
                            data = :data,
                            valor_total_empenho = :valor_total_empenho,
                            produto = :produto,
                            produto2 = :produto2,
                            observacao = :observacao,
                            updated_at = NOW()
                            WHERE id = :empenho_id";
            
            $stmt_update = $pdo->prepare($sql_update);
            $stmt_update->execute([
                ':numero' => $numero,
                ':classificacao' => $classificacao,
                ':pregao' => $pregao,
                ':item' => $item,
                ':prioridade' => $prioridade,
                ':data' => $data ?: null,
                ':valor_total_empenho' => $valor_total_empenho,
                ':produto' => $produto,
                ':produto2' => $produto2,
                ':observacao' => $observacao,
                ':empenho_id' => $empenho_id
            ]);
            
            $pdo->commit();
            
            // Log da ação
            logAction('UPDATE', "empenho_id: {$empenho_id}, numero: {$numero}");
            
            echo json_encode([
                'success' => true, 
                'message' => 'Empenho atualizado com sucesso!',
                'empenho_id' => $empenho_id
            ]);
            
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("Erro ao atualizar empenho {$empenho_id}: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        
    } elseif ($action === 'delete') {
        // Verifica permissão de exclusão
        if ($permissionManager && !$permissionManager->hasPagePermission('empenhos', 'delete')) {
            echo json_encode(['success' => false, 'message' => 'Sem permissão para excluir empenhos']);
            exit();
        }
        
        try {
            $pdo->beginTransaction();
            
            // Verifica se o empenho existe
            $sql_check = "SELECT numero, cliente_nome FROM empenhos WHERE id = :empenho_id";
            $stmt_check = $pdo->prepare($sql_check);
            $stmt_check->bindParam(':empenho_id', $empenho_id, PDO::PARAM_INT);
            $stmt_check->execute();
            $empenho_info = $stmt_check->fetch(PDO::FETCH_ASSOC);
            
            if (!$empenho_info) {
                throw new Exception('Empenho não encontrado');
            }
            
            // Conta produtos relacionados
            $sql_count_produtos = "SELECT COUNT(*) as total FROM empenho_produtos WHERE empenho_id = :empenho_id";
            $stmt_count = $pdo->prepare($sql_count_produtos);
            $stmt_count->bindParam(':empenho_id', $empenho_id, PDO::PARAM_INT);
            $stmt_count->execute();
            $total_produtos = $stmt_count->fetch(PDO::FETCH_ASSOC)['total'];
            
            // Exclui produtos relacionados primeiro
            if ($total_produtos > 0) {
                $sql_delete_produtos = "DELETE FROM empenho_produtos WHERE empenho_id = :empenho_id";
                $stmt_delete_produtos = $pdo->prepare($sql_delete_produtos);
                $stmt_delete_produtos->bindParam(':empenho_id', $empenho_id, PDO::PARAM_INT);
                $stmt_delete_produtos->execute();
            }
            
            // Exclui vendas relacionadas se existirem
            try {
                $sql_delete_vendas = "DELETE FROM vendas WHERE empenho_id = :empenho_id";
                $stmt_delete_vendas = $pdo->prepare($sql_delete_vendas);
                $stmt_delete_vendas->bindParam(':empenho_id', $empenho_id, PDO::PARAM_INT);
                $stmt_delete_vendas->execute();
            } catch (Exception $e) {
                // Tabela vendas pode não existir ou não ter relacionamento
                error_log("Aviso: Não foi possível excluir vendas relacionadas: " . $e->getMessage());
            }
            
            // Exclui o empenho
            $sql_delete_empenho = "DELETE FROM empenhos WHERE id = :empenho_id";
            $stmt_delete_empenho = $pdo->prepare($sql_delete_empenho);
            $stmt_delete_empenho->bindParam(':empenho_id', $empenho_id, PDO::PARAM_INT);
            $stmt_delete_empenho->execute();
            
            $pdo->commit();
            
            // Log da ação
            logAction('DELETE', "empenho_id: {$empenho_id}, numero: {$empenho_info['numero']}, cliente: {$empenho_info['cliente_nome']}, produtos_excluidos: {$total_produtos}");
            
            echo json_encode([
                'success' => true, 
                'message' => 'Empenho excluído com sucesso!',
                'produtos_excluidos' => $total_produtos
            ]);
            
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("Erro ao excluir empenho {$empenho_id}: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        
    } elseif ($action === 'get_info') {
        // Ação para buscar informações completas do empenho
        try {
            $sql_info = "SELECT e.*, 
                                c.nome_orgaos as cliente_nome_completo,
                                c.cnpj as cliente_cnpj,
                                c.email as cliente_email,
                                c.telefone as cliente_telefone
                         FROM empenhos e
                         LEFT JOIN clientes c ON e.cliente_uasg = c.uasg
                         WHERE e.id = :empenho_id";
            
            $stmt_info = $pdo->prepare($sql_info);
            $stmt_info->bindParam(':empenho_id', $empenho_id, PDO::PARAM_INT);
            $stmt_info->execute();
            $empenho = $stmt_info->fetch(PDO::FETCH_ASSOC);
            
            if (!$empenho) {
                throw new Exception('Empenho não encontrado');
            }
            
            // Busca produtos relacionados
            $sql_produtos = "SELECT ep.*, 
                                    p.nome as produto_nome,
                                    p.codigo as produto_codigo,
                                    p.observacao as produto_observacao
                             FROM empenho_produtos ep
                             LEFT JOIN produtos p ON ep.produto_id = p.id
                             WHERE ep.empenho_id = :empenho_id
                             ORDER BY ep.id";
            
            $stmt_produtos = $pdo->prepare($sql_produtos);
            $stmt_produtos->bindParam(':empenho_id', $empenho_id, PDO::PARAM_INT);
            $stmt_produtos->execute();
            $produtos = $stmt_produtos->fetchAll(PDO::FETCH_ASSOC);
            
            $empenho['produtos'] = $produtos;
            
            echo json_encode([
                'success' => true,
                'empenho' => $empenho,
                'total_produtos' => count($produtos)
            ]);
            
        } catch (Exception $e) {
            error_log("Erro ao buscar informações do empenho {$empenho_id}: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        
    } else {
        echo json_encode(['success' => false, 'message' => 'Ação não reconhecida']);
    }
    
} else {
    echo json_encode(['success' => false, 'message' => 'Método não permitido']);
}
?>