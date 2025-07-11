<?php
session_start();
header('Content-Type: application/json');

// Verifica se o usuário está logado
if (!isset($_SESSION['user'])) {
    echo json_encode(['error' => 'Usuário não autenticado']);
    exit();
}

require_once('db.php');
require_once('permissions.php');
require_once('includes/audit.php');

$permissionManager = initPermissions($pdo);

// API para buscar detalhes do empenho
if (isset($_GET['action']) && $_GET['action'] === 'get_empenho_details' && isset($_GET['empenho_id'])) {
    $empenho_id = intval($_GET['empenho_id']);
    
    try {
        // Verifica permissão
        if (!$permissionManager->hasPagePermission('empenhos', 'view')) {
            throw new Exception('Sem permissão para visualizar empenhos');
        }

        // Busca dados do empenho
        $sql_empenho = "SELECT e.*, c.nome_orgaos as cliente_nome, c.cnpj as cliente_cnpj, 
                               c.email as cliente_email, c.telefone as cliente_telefone
                        FROM empenhos e
                        LEFT JOIN clientes c ON e.cliente_uasg = c.uasg
                        WHERE e.id = :empenho_id";
        
        $stmt_empenho = $pdo->prepare($sql_empenho);
        $stmt_empenho->bindParam(':empenho_id', $empenho_id, PDO::PARAM_INT);
        $stmt_empenho->execute();
        
        $empenho = $stmt_empenho->fetch(PDO::FETCH_ASSOC);
        
        if (!$empenho) {
            throw new Exception('Empenho não encontrado');
        }

        // Busca produtos do empenho com dados do catálogo
        $sql_produtos = "SELECT ep.*, p.nome as produto_nome_catalogo, p.codigo as produto_codigo,
                               p.preco_unitario as preco_catalogo
                        FROM empenho_produtos ep
                        LEFT JOIN produtos p ON ep.produto_id = p.id
                        WHERE ep.empenho_id = :empenho_id 
                        ORDER BY ep.id";
        
        $stmt_produtos = $pdo->prepare($sql_produtos);
        $stmt_produtos->bindParam(':empenho_id', $empenho_id, PDO::PARAM_INT);
        $stmt_produtos->execute();
        $produtos = $stmt_produtos->fetchAll(PDO::FETCH_ASSOC);

        // Calcula totais
        $total_itens = count($produtos);
        $valor_total = array_sum(array_column($produtos, 'valor_total'));

        // Registra acesso
        logUserAction('READ', "Visualização detalhes empenho ID: {$empenho_id}");

        echo json_encode([
            'success' => true,
            'empenho' => $empenho,
            'produtos' => $produtos,
            'estatisticas' => [
                'total_itens' => $total_itens,
                'valor_total' => $valor_total
            ]
        ]);
        
    } catch (Exception $e) {
        error_log("Erro API empenho: " . $e->getMessage());
        echo json_encode([
            'error' => $e->getMessage(),
            'debug_info' => [
                'empenho_id' => $empenho_id,
                'timestamp' => date('Y-m-d H:i:s')
            ]
        ]);
    }
    exit();
}

// API para atualizar empenho
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_empenho') {
    try {
        // Verifica permissão
        if (!$permissionManager->hasPagePermission('empenhos', 'edit')) {
            throw new Exception('Sem permissão para editar empenhos');
        }

        $empenho_id = intval($_POST['empenho_id']);
        $data = [
            'numero' => trim($_POST['numero']),
            'pregao' => trim($_POST['pregao']),
            'classificacao' => trim($_POST['classificacao']),
            'observacao' => trim($_POST['observacao'] ?? '')
        ];

        $pdo->beginTransaction();

        // Atualiza empenho
        $sql_update = "UPDATE empenhos SET 
                        numero = :numero,
                        pregao = :pregao,
                        classificacao = :classificacao,
                        observacao = :observacao,
                        updated_at = NOW()
                      WHERE id = :empenho_id";

        $stmt = $pdo->prepare($sql_update);
        $stmt->execute(array_merge($data, ['empenho_id' => $empenho_id]));

        // Remove produtos existentes
        $stmt = $pdo->prepare("DELETE FROM empenho_produtos WHERE empenho_id = ?");
        $stmt->execute([$empenho_id]);

        // Insere novos produtos
        if (!empty($_POST['produtos']) && is_array($_POST['produtos'])) {
            $sql_insert = "INSERT INTO empenho_produtos 
                          (empenho_id, produto_id, descricao_produto, quantidade, 
                           valor_unitario, valor_total)
                          VALUES (?, ?, ?, ?, ?, ?)";
            
            $stmt = $pdo->prepare($sql_insert);
            $valor_total_empenho = 0;

            foreach ($_POST['produtos'] as $produto) {
                $valor_total = $produto['quantidade'] * $produto['valor_unitario'];
                $valor_total_empenho += $valor_total;

                $stmt->execute([
                    $empenho_id,
                    $produto['produto_id'] ?? null,
                    $produto['descricao'],
                    $produto['quantidade'],
                    $produto['valor_unitario'],
                    $valor_total
                ]);
            }

            // Atualiza valor total do empenho
            $stmt = $pdo->prepare("UPDATE empenhos SET valor_total_empenho = ? WHERE id = ?");
            $stmt->execute([$valor_total_empenho, $empenho_id]);
        }

        $pdo->commit();
        logUserAction('UPDATE', "Empenho ID: {$empenho_id} atualizado");

        echo json_encode([
            'success' => true,
            'message' => 'Empenho atualizado com sucesso'
        ]);

    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Erro atualização empenho: " . $e->getMessage());
        echo json_encode([
            'error' => $e->getMessage()
        ]);
    }
    exit();
}

// API para buscar sugestões de produtos
if (isset($_GET['action']) && $_GET['action'] === 'search_produtos') {
    try {
        $query = trim($_GET['query'] ?? '');
        
        if (strlen($query) < 2) {
            echo json_encode([]);
            exit();
        }

        $sql = "SELECT id, nome, codigo, preco_unitario, observacao
                FROM produtos 
                WHERE (nome LIKE :query OR codigo LIKE :query)
                  AND ativo = 1
                ORDER BY nome 
                LIMIT 10";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':query', "%{$query}%");
        $stmt->execute();

        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));

    } catch (Exception $e) {
        error_log("Erro busca produtos: " . $e->getMessage());
        echo json_encode(['error' => 'Erro ao buscar produtos']);
    }
    exit();
}

// Se chegou aqui, requisição inválida
echo json_encode(['error' => 'Endpoint não encontrado']);