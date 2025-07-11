<?php
// ===========================================
// CONVERTER EMPENHO PARA VENDA - LICITASIS
// Sistema Completo de Gestão de Licitações
// Versão: 1.0 - Conversão de Empenhos para Vendas
// ===========================================

session_start();
if (!isset($_SESSION['user'])) {
    echo json_encode(['error' => 'Usuário não autenticado']);
    exit();
}

// Includes necessários
include('db.php');
include('permissions.php');
include('includes/audit.php');

// Inicialização do sistema de permissões
$permissionManager = initPermissions($pdo);

// Verifica permissões
try {
    $permissionManager->requirePermission('vendas', 'create');
    $permissionManager->requirePermission('empenhos', 'edit');
} catch (Exception $e) {
    echo json_encode(['error' => 'Sem permissão para realizar esta operação: ' . $e->getMessage()]);
    exit();
}

// Registra ação
logUserAction('CREATE', 'empenho_to_venda_conversion');

// Resposta JSON
header('Content-Type: application/json');

// Verifica se é POST e tem o ID do empenho
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'Método não permitido. Use POST.']);
    exit();
}

if (!isset($_POST['empenho_id'])) {
    echo json_encode(['error' => 'ID do empenho não fornecido']);
    exit();
}

$empenho_id = filter_input(INPUT_POST, 'empenho_id', FILTER_VALIDATE_INT);
if (!$empenho_id || $empenho_id <= 0) {
    echo json_encode(['error' => 'ID do empenho inválido']);
    exit();
}

try {
    // Inicia transação
    $pdo->beginTransaction();
    
    // 1. Busca dados completos do empenho
    $sql_empenho = "SELECT 
                        e.*,
                        c.nome_orgaos as cliente_nome_completo,
                        c.cnpj as cliente_cnpj_completo,
                        c.endereco as cliente_endereco,
                        c.telefone as cliente_telefone,
                        c.email as cliente_email
                    FROM empenhos e 
                    LEFT JOIN clientes c ON e.cliente_uasg = c.uasg 
                    WHERE e.id = :empenho_id";
    
    $stmt_empenho = $pdo->prepare($sql_empenho);
    $stmt_empenho->bindParam(':empenho_id', $empenho_id, PDO::PARAM_INT);
    $stmt_empenho->execute();
    
    $empenho = $stmt_empenho->fetch(PDO::FETCH_ASSOC);
    
    if (!$empenho) {
        throw new Exception("Empenho não encontrado com ID: " . $empenho_id);
    }
    
    // Verifica se o empenho já foi convertido
    $sql_check = "SELECT id, numero FROM vendas WHERE empenho_id = :empenho_id";
    $stmt_check = $pdo->prepare($sql_check);
    $stmt_check->bindParam(':empenho_id', $empenho_id, PDO::PARAM_INT);
    $stmt_check->execute();
    
    $venda_existente = $stmt_check->fetch(PDO::FETCH_ASSOC);
    if ($venda_existente) {
        throw new Exception("Este empenho já foi convertido na venda: " . $venda_existente['numero']);
    }
    
    // Verifica se empenho já está marcado como vendido
    if ($empenho['classificacao'] === 'Vendido') {
        throw new Exception("Este empenho já está marcado como 'Vendido'. Verifique se não foi convertido anteriormente.");
    }
    
    // 2. Busca produtos do empenho
    $sql_produtos = "SELECT 
                        ep.*,
                        p.nome as produto_nome,
                        p.codigo as produto_codigo,
                        p.categoria as produto_categoria,
                        p.unidade as produto_unidade,
                        p.custo_total as produto_custo,
                        p.preco_unitario as produto_preco_unitario,
                        p.preco_venda as produto_preco_venda
                     FROM empenho_produtos ep 
                     LEFT JOIN produtos p ON ep.produto_id = p.id 
                     WHERE ep.empenho_id = :empenho_id
                     ORDER BY ep.id";
    
    $stmt_produtos = $pdo->prepare($sql_produtos);
    $stmt_produtos->bindParam(':empenho_id', $empenho_id, PDO::PARAM_INT);
    $stmt_produtos->execute();
    
    $produtos_empenho = $stmt_produtos->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($produtos_empenho)) {
        throw new Exception("Empenho não possui produtos cadastrados. Não é possível converter em venda sem produtos.");
    }
    
    // 3. Prepara dados para a venda
    $numero_base = 'V-' . ($empenho['numero'] ?: 'E' . $empenho_id) . '-' . date('Ymd');
    $cliente_nome = $empenho['cliente_nome'] ?: $empenho['cliente_nome_completo'] ?: 'Cliente não identificado';
    $valor_total_venda = floatval($empenho['valor_total_empenho'] ?: $empenho['valor_total'] ?: 0);
    
    // Verifica se o valor total é válido
    if ($valor_total_venda <= 0) {
        // Calcula valor total baseado nos produtos
        $valor_calculado = 0;
        foreach ($produtos_empenho as $produto) {
            $quantidade = floatval($produto['quantidade'] ?: 1);
            $valor_unitario = floatval($produto['valor_unitario'] ?: 0);
            $valor_calculado += ($quantidade * $valor_unitario);
        }
        $valor_total_venda = $valor_calculado;
    }
    
    // Gera número único se já existir
    $sql_check_numero = "SELECT id FROM vendas WHERE numero = :numero";
    $stmt_check_numero = $pdo->prepare($sql_check_numero);
    $counter = 1;
    $numero_final = $numero_base;
    
    do {
        $stmt_check_numero->bindParam(':numero', $numero_final);
        $stmt_check_numero->execute();
        
        if ($stmt_check_numero->rowCount() > 0) {
            $numero_final = $numero_base . '-' . $counter;
            $counter++;
        } else {
            break;
        }
    } while ($counter < 100); // Evita loop infinito
    
    if ($counter >= 100) {
        throw new Exception("Não foi possível gerar um número único para a venda");
    }
    
    // 4. Insere a venda
    $sql_venda = "INSERT INTO vendas (
                    numero,
                    cliente_uasg,
                    cliente,
                    transportadora,
                    observacao,
                    pregao,
                    data,
                    valor_total,
                    empenho_id,
                    status_pagamento,
                    classificacao,
                    created_at
                  ) VALUES (
                    :numero,
                    :cliente_uasg,
                    :cliente,
                    :transportadora,
                    :observacao,
                    :pregao,
                    :data,
                    :valor_total,
                    :empenho_id,
                    'Não Recebido',
                    'Vendido',
                    NOW()
                  )";
    
    $stmt_venda = $pdo->prepare($sql_venda);
    
    // Prepara observação detalhada
    $observacao_base = 'Venda gerada automaticamente do empenho #' . ($empenho['numero'] ?: $empenho_id);
    if (!empty($empenho['observacao'])) {
        $observacao_base .= ' | Obs. original: ' . $empenho['observacao'];
    }
    $observacao_base .= ' | Convertido em: ' . date('d/m/Y H:i:s');
    
    $dados_venda = [
        ':numero' => $numero_final,
        ':cliente_uasg' => $empenho['cliente_uasg'],
        ':cliente' => $cliente_nome,
        ':transportadora' => '', // Será preenchido posteriormente se necessário
        ':observacao' => $observacao_base,
        ':pregao' => $empenho['pregao'],
        ':data' => $empenho['data'] ?: date('Y-m-d'),
        ':valor_total' => $valor_total_venda,
        ':empenho_id' => $empenho_id
    ];
    
    if (!$stmt_venda->execute($dados_venda)) {
        throw new Exception("Erro ao inserir venda no banco de dados");
    }
    
    $venda_id = $pdo->lastInsertId();
    
    if (!$venda_id) {
        throw new Exception("Erro ao obter ID da venda criada");
    }
    
    // 5. Insere os produtos da venda
    $total_produtos_inseridos = 0;
    $valor_total_produtos = 0;
    
    foreach ($produtos_empenho as $produto) {
        $sql_venda_produto = "INSERT INTO venda_produtos (
                                venda_id,
                                produto_id,
                                quantidade,
                                valor_unitario,
                                valor_total,
                                observacao,
                                created_at
                              ) VALUES (
                                :venda_id,
                                :produto_id,
                                :quantidade,
                                :valor_unitario,
                                :valor_total,
                                :observacao,
                                NOW()
                              )";
        
        $stmt_venda_produto = $pdo->prepare($sql_venda_produto);
        
        // Calcula valores do produto
        $quantidade = intval($produto['quantidade'] ?: 1);
        $valor_unitario = floatval($produto['valor_unitario'] ?: 0);
        $valor_total_produto = $quantidade * $valor_unitario;
        $valor_total_produtos += $valor_total_produto;
        
        // Prepara observação do produto
        $obs_produto = 'Produto convertido do empenho #' . ($empenho['numero'] ?: $empenho_id);
        if (!empty($produto['descricao_produto'])) {
            $obs_produto .= ' | Desc: ' . $produto['descricao_produto'];
        }
        
        $dados_produto = [
            ':venda_id' => $venda_id,
            ':produto_id' => $produto['produto_id'] ?: 0,
            ':quantidade' => $quantidade,
            ':valor_unitario' => $valor_unitario,
            ':valor_total' => $valor_total_produto,
            ':observacao' => $obs_produto
        ];
        
        if (!$stmt_venda_produto->execute($dados_produto)) {
            throw new Exception("Erro ao inserir produto da venda: " . $produto['produto_nome']);
        }
        
        $total_produtos_inseridos++;
    }
    
    // 6. Atualiza valor total da venda se necessário
    if (abs($valor_total_venda - $valor_total_produtos) > 0.01) {
        $sql_update_valor = "UPDATE vendas SET valor_total = :valor_total WHERE id = :venda_id";
        $stmt_update_valor = $pdo->prepare($sql_update_valor);
        $stmt_update_valor->execute([
            ':valor_total' => $valor_total_produtos,
            ':venda_id' => $venda_id
        ]);
        $valor_total_venda = $valor_total_produtos;
    }
    
    // 7. Atualiza classificação do empenho para "Vendido"
    $sql_update_empenho = "UPDATE empenhos 
                           SET classificacao = 'Vendido',
                               observacao = CONCAT(
                                   COALESCE(observacao, ''), 
                                   CASE 
                                       WHEN COALESCE(observacao, '') = '' THEN ''
                                       ELSE ' | '
                                   END,
                                   'Convertido em venda #', :numero_venda, ' em ', NOW()
                               )
                           WHERE id = :empenho_id";
    
    $stmt_update_empenho = $pdo->prepare($sql_update_empenho);
    if (!$stmt_update_empenho->execute([
        ':numero_venda' => $numero_final,
        ':empenho_id' => $empenho_id
    ])) {
        throw new Exception("Erro ao atualizar classificação do empenho");
    }
    
    // 8. Registra auditoria detalhada
    $audit_data = [
        'empenho_id' => $empenho_id,
        'empenho_numero' => $empenho['numero'],
        'venda_id' => $venda_id,
        'venda_numero' => $numero_final,
        'cliente_uasg' => $empenho['cliente_uasg'],
        'cliente_nome' => $cliente_nome,
        'valor_total_original' => $empenho['valor_total_empenho'] ?: $empenho['valor_total'],
        'valor_total_final' => $valor_total_venda,
        'produtos_convertidos' => $total_produtos_inseridos,
        'data_conversao' => date('Y-m-d H:i:s'),
        'usuario_id' => $_SESSION['user']['id'] ?? 0,
        'usuario_nome' => $_SESSION['user']['name'] ?? 'Sistema'
    ];
    
    logUserAction('CONVERT', 'empenho_to_venda', $empenho_id, $audit_data);
    
    // Commit da transação
    $pdo->commit();
    
    // 9. Retorna sucesso com dados completos
    echo json_encode([
        'success' => true,
        'message' => 'Empenho convertido em venda com sucesso!',
        'data' => [
            'venda_id' => $venda_id,
            'venda_numero' => $numero_final,
            'empenho_id' => $empenho_id,
            'empenho_numero' => $empenho['numero'] ?: 'E' . $empenho_id,
            'cliente_nome' => $cliente_nome,
            'cliente_uasg' => $empenho['cliente_uasg'],
            'valor_total' => $valor_total_venda,
            'produtos_convertidos' => $total_produtos_inseridos,
            'data_conversao' => date('d/m/Y H:i:s'),
            'redirect_url' => 'vendas_cliente_detalhes.php?cliente_uasg=' . urlencode($empenho['cliente_uasg'])
        ]
    ]);
    
} catch (Exception $e) {
    // Rollback da transação em caso de erro
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    error_log("ERRO na conversão empenho->venda: " . $e->getMessage() . " | Empenho ID: " . $empenho_id);
    
    echo json_encode([
        'error' => $e->getMessage(),
        'debug' => [
            'empenho_id' => $empenho_id,
            'error_file' => $e->getFile(),
            'error_line' => $e->getLine(),
            'timestamp' => date('Y-m-d H:i:s')
        ]
    ]);
    
} catch (PDOException $e) {
    // Rollback da transação em caso de erro de banco
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    error_log("ERRO PDO na conversão: " . $e->getMessage() . " | Empenho ID: " . $empenho_id);
    
    echo json_encode([
        'error' => 'Erro no banco de dados: ' . $e->getMessage(),
        'debug' => [
            'empenho_id' => $empenho_id,
            'sql_error_code' => $e->getCode(),
            'timestamp' => date('Y-m-d H:i:s')
        ]
    ]);
}
?>