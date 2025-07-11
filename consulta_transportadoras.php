<?php 
// ===========================================
// CONSULTA DE TRANSPORTADORAS - LICITASIS v7.0
// Sistema Completo de Gestão de Licitações
// Versão Melhorada com Design Responsivo e Funcionalidades Avançadas
// ===========================================

// Evita problemas de encoding e headers
ini_set('default_charset', 'UTF-8');
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');

// Inicia buffer de output para evitar problemas com headers
ob_start();

// Configurações de erro e logging
error_reporting(E_ALL);
ini_set('display_errors', 0); // Não mostrar erros na tela em produção
ini_set('log_errors', 1);

// Configurações de sessão segura
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);
ini_set('session.cookie_samesite', 'Strict');

// Verifica se a sessão já não foi iniciada
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Verifica se o usuário está logado
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

// ===========================================
// INCLUSÃO DOS ARQUIVOS NECESSÁRIOS
// ===========================================

// Conexão com o banco de dados
include('db.php');

// Sistema de permissões
include('permissions.php');

// Sistema de auditoria
include('includes/audit.php');

// Inicialização do sistema de permissões
$permissionManager = initPermissions($pdo);
$permissionManager->checkLogin();
$permissionManager->requirePermission('transportadoras', 'view');

// Definir a variável $isAdmin com base na permissão do usuário
$isAdmin = $permissionManager->isAdmin();

// Função de auditoria simples se não existir sistema externo
if (!function_exists('logUserAction')) {
    function logUserAction($action, $table, $record_id, $old_data = null, $new_data = null) {
        global $pdo;
        
        if (!$pdo || !isset($_SESSION['user']['id'])) {
            return false;
        }
        
        try {
            // Verifica se a tabela de auditoria existe
            $check_table = $pdo->query("SHOW TABLES LIKE 'audit_log'");
            
            if ($check_table && $check_table->rowCount() > 0) {
                $sql = "INSERT INTO audit_log (user_id, action, table_name, record_id, old_values, new_values, ip_address, user_agent, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    $_SESSION['user']['id'],
                    $action,
                    $table,
                    $record_id,
                    $old_data ? json_encode($old_data) : null,
                    $new_data ? json_encode($new_data) : null,
                    $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
                ]);
                
                return true;
            }
        } catch (Exception $e) {
            error_log("Erro no log de auditoria: " . $e->getMessage());
        }
        
        return false;
    }
}

// Log da ação de consulta
logUserAction('READ', 'transportadoras_consulta', null);

// ===========================================
// INICIALIZAÇÃO DAS VARIÁVEIS
// ===========================================

$error = "";
$success = "";
$transportadoras = [];
$searchTerm = "";
$orderBy = isset($_GET['order']) ? $_GET['order'] : 'nome';
$orderDirection = isset($_GET['dir']) && $_GET['dir'] === 'desc' ? 'desc' : 'asc';

// Configuração da paginação
$itensPorPagina = 20;
$paginaAtual = isset($_GET['pagina']) ? max(1, intval($_GET['pagina'])) : 1;
$offset = ($paginaAtual - 1) * $itensPorPagina;

// ===========================================
// PROCESSAMENTO DE AÇÕES VIA AJAX
// ===========================================

// Verifica se foi feita uma requisição AJAX para pegar os dados da transportadora
if (isset($_GET['get_transportadora_id'])) {
    // Limpa qualquer output anterior
    ob_clean();
    header('Content-Type: application/json');
    
    try {
        $id = filter_input(INPUT_GET, 'get_transportadora_id', FILTER_VALIDATE_INT);
        
        if (!$id) {
            throw new Exception('ID da transportadora inválido');
        }
        
        $sql = "SELECT * FROM transportadora WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        
        $transportadora = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$transportadora) {
            throw new Exception('Transportadora não encontrada');
        }
        
        echo json_encode($transportadora);
        exit();
        
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
        exit();
    }
}

// Função para editar o registro da transportadora via AJAX
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_transportadora'])) {
    // Limpa qualquer output anterior
    ob_clean();
    header('Content-Type: application/json');
    
    try {
        // Verifica permissão para editar
        $permissionManager->requirePermission('transportadoras', 'edit');
        
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        
        if (!$id) {
            throw new Exception('ID da transportadora inválido');
        }
        
        $pdo->beginTransaction();
        
        // Busca dados antigos para auditoria
        $stmt_old = $pdo->prepare("SELECT * FROM transportadora WHERE id = ?");
        $stmt_old->execute([$id]);
        $old_data = $stmt_old->fetch(PDO::FETCH_ASSOC);
        
        if (!$old_data) {
            throw new Exception('Transportadora não encontrada');
        }
        
        // Coleta e sanitiza os dados
        $dados = [
            'codigo' => trim(filter_input(INPUT_POST, 'codigo', FILTER_SANITIZE_FULL_SPECIAL_CHARS)),
            'nome' => trim(filter_input(INPUT_POST, 'nome', FILTER_SANITIZE_FULL_SPECIAL_CHARS)),
            'cnpj' => trim(filter_input(INPUT_POST, 'cnpj', FILTER_SANITIZE_FULL_SPECIAL_CHARS)),
            'endereco' => trim(filter_input(INPUT_POST, 'endereco', FILTER_SANITIZE_FULL_SPECIAL_CHARS)),
            'telefone' => trim(filter_input(INPUT_POST, 'telefone', FILTER_SANITIZE_FULL_SPECIAL_CHARS)),
            'email' => trim(filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL)),
            'observacoes' => trim(filter_input(INPUT_POST, 'observacoes', FILTER_SANITIZE_FULL_SPECIAL_CHARS))
        ];
        
        // Validações
        if (empty($dados['nome'])) {
            throw new Exception('Nome da transportadora é obrigatório');
        }
        
        if (!empty($dados['email']) && !filter_var($dados['email'], FILTER_VALIDATE_EMAIL)) {
            throw new Exception('E-mail inválido');
        }
        
        // Verifica se código já existe (se informado)
        if (!empty($dados['codigo'])) {
            $stmt_check = $pdo->prepare("SELECT id FROM transportadora WHERE codigo = ? AND id != ?");
            $stmt_check->execute([$dados['codigo'], $id]);
            if ($stmt_check->fetch()) {
                throw new Exception('Código já existe para outra transportadora');
            }
        }
        
        // Atualiza a transportadora
        $sql = "UPDATE transportadora SET 
                codigo = :codigo,
                nome = :nome,
                cnpj = :cnpj,
                endereco = :endereco,
                telefone = :telefone,
                email = :email,
                observacoes = :observacoes,
                updated_at = NOW()
                WHERE id = :id";
        
        $stmt = $pdo->prepare($sql);
        $dados['id'] = $id;
        
        if (!$stmt->execute($dados)) {
            throw new Exception('Erro ao atualizar a transportadora no banco de dados');
        }
        
        // Log de auditoria
        logUserAction('UPDATE', 'transportadora', $id, $old_data, $dados);
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Transportadora atualizada com sucesso!'
        ]);
        exit();
        
    } catch (Exception $e) {
        if ($pdo && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
        exit();
    }
}

// Função para excluir a transportadora via AJAX
if (isset($_POST['delete_transportadora_id'])) {
    // Limpa qualquer output anterior
    ob_clean();
    header('Content-Type: application/json');
    
    try {
        // Verifica permissão para excluir
        $permissionManager->requirePermission('transportadoras', 'delete');
        
        $id = filter_input(INPUT_POST, 'delete_transportadora_id', FILTER_VALIDATE_INT);
        
        if (!$id) {
            throw new Exception('ID da transportadora inválido');
        }
        
        $pdo->beginTransaction();
        
        // Busca dados da transportadora para auditoria
        $stmt_transportadora = $pdo->prepare("SELECT * FROM transportadora WHERE id = ?");
        $stmt_transportadora->execute([$id]);
        $transportadora_data = $stmt_transportadora->fetch(PDO::FETCH_ASSOC);
        
        if (!$transportadora_data) {
            throw new Exception('Transportadora não encontrada');
        }
        
        // Verifica se a transportadora está sendo usada em outras tabelas
        $tables_to_check = ['vendas', 'pedidos', 'entregas'];
        $usage_count = 0;
        $used_in = [];
        
        foreach ($tables_to_check as $table) {
            try {
                $check_sql = "SELECT COUNT(*) FROM {$table} WHERE transportadora_id = ?";
                $stmt_check = $pdo->prepare($check_sql);
                $stmt_check->execute([$id]);
                $count = $stmt_check->fetchColumn();
                
                if ($count > 0) {
                    $usage_count += $count;
                    $used_in[] = "{$table} ({$count} registros)";
                }
            } catch (PDOException $e) {
                // Tabela pode não existir, ignora o erro
                continue;
            }
        }
        
        if ($usage_count > 0) {
            throw new Exception("Não é possível excluir esta transportadora pois ela está sendo usada em: " . implode(', ', $used_in));
        }
        
        // Log de auditoria antes da exclusão
        logUserAction('DELETE', 'transportadora', $id, $transportadora_data);
        
        // Exclui a transportadora
        $sql = "DELETE FROM transportadora WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$id]);
        
        if ($stmt->rowCount() === 0) {
            throw new Exception('Nenhuma transportadora foi excluída. Verifique se o ID está correto');
        }
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Transportadora excluída com sucesso!'
        ]);
        exit();
        
    } catch (Exception $e) {
        if ($pdo && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
        exit();
    }
}

// ===========================================
// CONSULTA PRINCIPAL COM FILTROS E PAGINAÇÃO
// ===========================================

$searchTerm = isset($_GET['search']) ? trim($_GET['search']) : '';

try {
    // Parâmetros para consulta
    $params = [];
    $whereConditions = [];
    
    // Condições de filtro
    if (!empty($searchTerm)) {
        $whereConditions[] = "(codigo LIKE :searchTerm OR nome LIKE :searchTerm OR cnpj LIKE :searchTerm OR telefone LIKE :searchTerm OR email LIKE :searchTerm)";
        $params[':searchTerm'] = "%$searchTerm%";
    }
    
    $whereClause = !empty($whereConditions) ? "WHERE " . implode(" AND ", $whereConditions) : "";
    
    // Validação do campo de ordenação
    $validOrderFields = ['codigo', 'nome', 'cnpj', 'telefone', 'email', 'created_at', 'updated_at'];
    if (!in_array($orderBy, $validOrderFields)) {
        $orderBy = 'nome';
    }
    
    $orderClause = "ORDER BY {$orderBy} {$orderDirection}";
    
    // Consulta para contar total de registros
    $sqlCount = "SELECT COUNT(*) as total FROM transportadora $whereClause";
    $stmtCount = $pdo->prepare($sqlCount);
    foreach ($params as $key => $value) {
        $stmtCount->bindValue($key, $value);
    }
    $stmtCount->execute();
    $totalRegistros = $stmtCount->fetch(PDO::FETCH_ASSOC)['total'];
    $totalPaginas = ceil($totalRegistros / $itensPorPagina);
    
    // Consulta principal
    $sql = "SELECT 
        id,
        COALESCE(codigo, '') as codigo,
        COALESCE(nome, '') as nome,
        COALESCE(cnpj, '') as cnpj,
        COALESCE(endereco, '') as endereco,
        COALESCE(telefone, '') as telefone,
        COALESCE(email, '') as email,
        COALESCE(observacoes, '') as observacoes,
        created_at,
        updated_at,
        0 as vendas_count,
        NULL as ultima_venda
        FROM transportadora 
        $whereClause
        $orderClause
        LIMIT :limit OFFSET :offset";
    
    $stmt = $pdo->prepare($sql);
    
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    
    $stmt->bindValue(':limit', $itensPorPagina, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $transportadoras = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Busca dados de vendas se a tabela existir
    if (!empty($transportadoras)) {
        try {
            // Verifica se a tabela vendas existe
            $checkVendasTable = $pdo->query("SHOW TABLES LIKE 'vendas'");
            if ($checkVendasTable && $checkVendasTable->rowCount() > 0) {
                // Verifica as colunas da tabela vendas
                $checkColumns = $pdo->query("SHOW COLUMNS FROM vendas");
                $columns = $checkColumns->fetchAll(PDO::FETCH_COLUMN);
                
                // Determina qual coluna usar para transportadora
                $transportadoraColumn = null;
                if (in_array('transportadora_id', $columns)) {
                    $transportadoraColumn = 'transportadora_id';
                } elseif (in_array('transportadora', $columns)) {
                    $transportadoraColumn = 'transportadora';
                } elseif (in_array('id_transportadora', $columns)) {
                    $transportadoraColumn = 'id_transportadora';
                }
                
                // Determina qual coluna usar para data
                $dateColumn = 'created_at'; // Padrão
                if (in_array('data_venda', $columns)) {
                    $dateColumn = 'data_venda';
                } elseif (in_array('data_criacao', $columns)) {
                    $dateColumn = 'data_criacao';
                } elseif (in_array('data', $columns)) {
                    $dateColumn = 'data';
                }
                
                if ($transportadoraColumn) {
                    // Busca dados de vendas para cada transportadora
                    foreach ($transportadoras as &$transportadora) {
                        // Conta vendas
                        $sqlVendas = "SELECT COUNT(*) as count, MAX($dateColumn) as ultima_venda 
                                    FROM vendas 
                                    WHERE $transportadoraColumn = ?";
                        $stmtVendas = $pdo->prepare($sqlVendas);
                        $stmtVendas->execute([$transportadora['id']]);
                        $vendaData = $stmtVendas->fetch(PDO::FETCH_ASSOC);
                        
                        $transportadora['vendas_count'] = $vendaData['count'] ?? 0;
                        $transportadora['ultima_venda'] = $vendaData['ultima_venda'];
                    }
                }
            }
        } catch (Exception $e) {
            // Se houver erro ao buscar dados de vendas, mantém os valores padrão
            error_log("Aviso: Não foi possível buscar dados de vendas: " . $e->getMessage());
        }
    }
    
} catch (PDOException $e) {
    $error = "Erro na consulta: " . $e->getMessage();
    $transportadoras = [];
    error_log("Erro na consulta de transportadoras: " . $e->getMessage());
}

// ===========================================
// CÁLCULO DE ESTATÍSTICAS
// ===========================================

try {
    // Total geral de transportadoras
    $sqlTotalGeral = "SELECT COUNT(*) as total FROM transportadora";
    $stmtTotalGeral = $pdo->prepare($sqlTotalGeral);
    $stmtTotalGeral->execute();
    $totalGeralTransportadoras = $stmtTotalGeral->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    
    // Inicializa variáveis
    $transportadorasAtivas = 0;
    $transportadorasInativas = 0;
    $topTransportadoras = [];
    
    // Verifica se a tabela vendas existe
    $sqlCheckTable = "SHOW TABLES LIKE 'vendas'";
    $stmtCheck = $pdo->prepare($sqlCheckTable);
    $stmtCheck->execute();
    $tableExists = $stmtCheck->rowCount() > 0;
    
    if ($tableExists) {
        try {
            // Verifica as colunas da tabela vendas
            $sqlColumns = "SHOW COLUMNS FROM vendas";
            $stmtColumns = $pdo->prepare($sqlColumns);
            $stmtColumns->execute();
            $columns = $stmtColumns->fetchAll(PDO::FETCH_COLUMN);
            
            // Determina qual coluna de data usar
            $dateColumn = 'created_at'; // Padrão
            if (in_array('data_venda', $columns)) {
                $dateColumn = 'data_venda';
            } elseif (in_array('data_criacao', $columns)) {
                $dateColumn = 'data_criacao';
            } elseif (in_array('data', $columns)) {
                $dateColumn = 'data';
            }
            
            // Verifica se existe coluna transportadora_id
            $transportadoraColumn = 'transportadora_id';
            if (!in_array('transportadora_id', $columns)) {
                // Tenta outras possibilidades
                if (in_array('transportadora', $columns)) {
                    $transportadoraColumn = 'transportadora';
                } elseif (in_array('id_transportadora', $columns)) {
                    $transportadoraColumn = 'id_transportadora';
                } else {
                    // Se não tem relação com transportadora
                    $transportadoraColumn = null;
                }
            }
            
            if ($transportadoraColumn) {
                // Transportadoras ativas (com vendas nos últimos 6 meses)
                $sqlAtivas = "SELECT COUNT(DISTINCT t.id) as ativas 
                            FROM transportadora t 
                            INNER JOIN vendas v ON t.id = v.{$transportadoraColumn} 
                            WHERE v.{$dateColumn} >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)";
                $stmtAtivas = $pdo->prepare($sqlAtivas);
                $stmtAtivas->execute();
                $transportadorasAtivas = $stmtAtivas->fetch(PDO::FETCH_ASSOC)['ativas'] ?? 0;
                
                // Transportadoras com mais vendas
                $valorColumn = 'valor_total';
                if (!in_array('valor_total', $columns)) {
                    if (in_array('valor', $columns)) {
                        $valorColumn = 'valor';
                    } elseif (in_array('total', $columns)) {
                        $valorColumn = 'total';
                    } else {
                        $valorColumn = '1'; // Conta apenas a quantidade se não tem valor
                    }
                }
                
                $sqlTopTransportadoras = "SELECT 
                                        t.nome,
                                        COUNT(v.id) as total_vendas,
                                        COALESCE(SUM(v.{$valorColumn}), 0) as valor_total_vendas
                                        FROM transportadora t
                                        LEFT JOIN vendas v ON t.id = v.{$transportadoraColumn}
                                        GROUP BY t.id, t.nome
                                        HAVING total_vendas > 0
                                        ORDER BY total_vendas DESC
                                        LIMIT 5";
                $stmtTop = $pdo->prepare($sqlTopTransportadoras);
                $stmtTop->execute();
                $topTransportadoras = $stmtTop->fetchAll(PDO::FETCH_ASSOC);
            }
        } catch (Exception $e) {
            error_log("Erro ao calcular estatísticas: " . $e->getMessage());
        }
    }
    
    // Transportadoras inativas
    $transportadorasInativas = $totalGeralTransportadoras - $transportadorasAtivas;
    
} catch (Exception $e) {
    error_log("Erro ao calcular estatísticas: " . $e->getMessage());
    $totalGeralTransportadoras = 0;
    $transportadorasAtivas = 0;
    $transportadorasInativas = 0;
    $topTransportadoras = [];
}

// Processa mensagens de sucesso/erro da URL
if (isset($_GET['success'])) {
    $success = htmlspecialchars($_GET['success'], ENT_QUOTES, 'UTF-8');
}
if (isset($_GET['error'])) {
    $error = htmlspecialchars($_GET['error'], ENT_QUOTES, 'UTF-8');
}

// Inclui o header do sistema
include('includes/header_template.php');
renderHeader("Consulta de Transportadoras - LicitaSis", "transportadoras");
?>

    <!DOCTYPE html>
    <html lang="pt-BR">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Consulta de Transportadoras - LicitaSis</title>
        <link rel="icon" href="../public_html/assets/images/logo_combraz.png" type="image/png">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
        
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
                --ativo-color: #28a745;
                --inativo-color: #6c757d;
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
            HEADER E NAVEGAÇÃO
            =========================================== */
            header {
                background: linear-gradient(135deg, var(--primary-light) 0%, var(--primary-color) 100%);
                padding: 0.8rem 0;
                text-align: center;
                box-shadow: var(--shadow);
                width: 100%;
                position: relative;
                z-index: 100;
            }

            .logo {
                max-width: 160px;
                height: auto;
                transition: var(--transition);
            }

            .logo:hover {
                transform: scale(1.05);
            }

            nav {
                background: var(--primary-color);
                padding: 0;
                text-align: center;
                box-shadow: 0 2px 5px rgba(0,0,0,0.1);
                position: relative;
                z-index: 99;
                display: flex;
                justify-content: center;
                flex-wrap: wrap;
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
                border-radius: 0 0 var(--radius-sm) var(--radius-sm);
                overflow: hidden;
            }

            .dropdown-content a {
                display: block;
                padding: 0.875rem 1.25rem;
                border-bottom: 1px solid rgba(255,255,255,0.1);
                text-align: left;
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

            .alert-error {
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
            ESTATÍSTICAS
            =========================================== */
            .stats-container {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 1rem;
                margin-bottom: 2rem;
                padding: 1.5rem;
                background: var(--light-gray);
                border-radius: var(--radius);
            }

            .stat-item {
                text-align: center;
                padding: 1rem;
                background: white;
                border-radius: var(--radius-sm);
                box-shadow: 0 2px 8px rgba(0,0,0,0.05);
                transition: var(--transition);
                position: relative;
                overflow: hidden;
            }

            .stat-item:hover {
                transform: translateY(-3px);
                box-shadow: var(--shadow);
            }

            .stat-item.ativo {
                border-left: 4px solid var(--success-color);
            }

            .stat-item.inativo {
                border-left: 4px solid var(--medium-gray);
            }

            .stat-number {
                font-size: 1.5rem;
                font-weight: 700;
                color: var(--primary-color);
            }

            .stat-label {
                font-size: 0.85rem;
                color: var(--medium-gray);
                text-transform: uppercase;
                font-weight: 600;
            }

            .stat-icon {
                position: absolute;
                top: 1rem;
                right: 1rem;
                font-size: 1.5rem;
                opacity: 0.3;
                transition: var(--transition);
            }

            .stat-item:hover .stat-icon {
                opacity: 0.8;
                transform: scale(1.2);
            }

            /* ===========================================
            FILTROS
            =========================================== */
            .filters-container {
                margin-bottom: 2rem;
                padding: 1.5rem;
                background: linear-gradient(135deg, var(--light-gray) 0%, #e9ecef 100%);
                border-radius: var(--radius);
                border: 1px solid var(--border-color);
            }

            .filters-row {
                display: grid;
                grid-template-columns: 1fr auto auto;
                gap: 1rem;
                align-items: end;
            }

            .search-group {
                display: flex;
                flex-direction: column;
                gap: 0.5rem;
            }

            .search-group label {
                font-size: 0.85rem;
                font-weight: 600;
                color: var(--medium-gray);
                text-transform: uppercase;
                display: flex;
                align-items: center;
                gap: 0.5rem;
            }

            .search-input {
                padding: 0.75rem 1rem;
                border: 2px solid var(--border-color);
                border-radius: var(--radius-sm);
                font-size: 1rem;
                transition: var(--transition);
                background: white;
            }

            .search-input:focus {
                outline: none;
                border-color: var(--secondary-color);
                box-shadow: 0 0 0 3px rgba(0, 191, 174, 0.1);
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
                color: #212529;
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

            .btn-sm {
                padding: 0.5rem 1rem;
                font-size: 0.85rem;
            }

            .btn-light {
                background: white;
                color: var(--primary-color);
                border: 2px solid var(--border-color);
            }

            .btn-light:hover {
                background: var(--light-gray);
                border-color: var(--secondary-color);
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
                cursor: pointer;
                user-select: none;
            }

            table th:hover {
                background: linear-gradient(135deg, var(--secondary-dark) 0%, var(--secondary-color) 100%);
            }

            table th i {
                margin-right: 0.5rem;
            }

            table th .sort-icon {
                margin-left: 0.5rem;
                opacity: 0.7;
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
            .transportadora-link {
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

            .transportadora-link:hover {
                color: var(--primary-color);
                background: rgba(45, 137, 62, 0.1);
                transform: scale(1.05);
                box-shadow: 0 2px 8px rgba(0, 191, 174, 0.2);
            }

            .transportadora-link i {
                font-size: 0.8rem;
            }

            .status-badge {
                display: inline-flex;
                align-items: center;
                gap: 0.5rem;
                padding: 0.25rem 0.75rem;
                border-radius: 20px;
                font-size: 0.8rem;
                font-weight: 600;
                text-transform: uppercase;
            }

            .status-badge.ativo {
                background: rgba(40, 167, 69, 0.1);
                color: var(--success-color);
                border: 1px solid var(--success-color);
            }

            .status-badge.inativo {
                background: rgba(108, 117, 125, 0.1);
                color: var(--medium-gray);
                border: 1px solid var(--medium-gray);
            }

            .vendas-count {
                display: inline-flex;
                align-items: center;
                gap: 0.5rem;
                padding: 0.25rem 0.75rem;
                border-radius: 20px;
                font-size: 0.85rem;
                font-weight: 600;
                background: rgba(0, 191, 174, 0.1);
                color: var(--secondary-color);
                border: 1px solid var(--secondary-color);
            }

            .vendas-count.zero {
                background: rgba(108, 117, 125, 0.1);
                color: var(--medium-gray);
                border: 1px solid var(--medium-gray);
            }

            /* ===========================================
            PAGINAÇÃO
            =========================================== */
            .pagination-container {
                display: flex;
                justify-content: center;
                align-items: center;
                gap: 1rem;
                margin: 2rem 0;
                padding: 1.5rem;
                background: var(--light-gray);
                border-radius: var(--radius);
            }

            .pagination {
                display: flex;
                gap: 0.5rem;
                align-items: center;
            }

            .page-btn {
                padding: 0.5rem 1rem;
                border: 2px solid var(--border-color);
                background: white;
                color: var(--primary-color);
                border-radius: var(--radius-sm);
                cursor: pointer;
                transition: var(--transition);
                text-decoration: none;
                font-weight: 600;
                min-width: 40px;
                text-align: center;
            }

            .page-btn:hover {
                border-color: var(--secondary-color);
                background: var(--secondary-color);
                color: white;
                transform: translateY(-2px);
            }

            .page-btn.active {
                background: var(--secondary-color);
                color: white;
                border-color: var(--secondary-color);
            }

            .page-btn:disabled,
            .page-btn.disabled {
                opacity: 0.5;
                cursor: not-allowed;
                transform: none;
            }

            .pagination-info {
                color: var(--medium-gray);
                font-size: 0.9rem;
                font-weight: 500;
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
                max-width: 800px;
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
            SEÇÕES DE DETALHES DO MODAL
            =========================================== */
            .transportadora-details {
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

            /* Estados de validação */
            .form-control.is-invalid {
                border-color: var(--danger-color);
                box-shadow: 0 0 0 3px rgba(220, 53, 69, 0.1);
            }

            .form-control.is-valid {
                border-color: var(--success-color);
                box-shadow: 0 0 0 3px rgba(40, 167, 69, 0.1);
            }

            /* Botões do formulário */
            .modal-buttons {
                margin-top: 2rem;
                padding-top: 1.5rem;
                border-top: 2px solid var(--border-color);
                display: flex;
                gap: 1rem;
                justify-content: center;
                flex-wrap: wrap;
            }

            /* ===========================================
            UTILITÁRIOS
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

                .filters-row {
                    grid-template-columns: 1fr;
                    gap: 1rem;
                }

                .filters-row > * {
                    width: 100%;
                }

                nav {
                    justify-content: flex-start;
                    padding: 0 1rem;
                }
                
                nav a {
                    padding: 0.75rem 0.75rem;
                    font-size: 0.9rem;
                }
                
                .dropdown-content {
                    min-width: 180px;
                }
            }

            @media (max-width: 768px) {
                .container {
                    margin: 1.5rem 1rem;
                    padding: 1.5rem;
                }

                h2 {
                    font-size: 1.75rem;
                    flex-direction: column;
                    gap: 0.5rem;
                }

                .stats-container {
                    grid-template-columns: repeat(2, 1fr);
                    gap: 0.75rem;
                }

                .pagination-container {
                    flex-direction: column;
                    gap: 1rem;
                }

                .table-container {
                    font-size: 0.85rem;
                }

                table th, table td {
                    padding: 0.75rem 0.5rem;
                }

                .transportadora-link {
                    padding: 0.25rem 0.5rem;
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

                .btn {
                    width: 100%;
                }

                .detail-grid {
                    grid-template-columns: 1fr;
                }
            }

            @media (max-width: 480px) {
                .container {
                    margin: 1rem 0.5rem;
                    padding: 1.25rem;
                }

                h2 {
                    font-size: 1.5rem;
                }

                .stats-container {
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

                .transportadora-link {
                    font-size: 0.8rem;
                    padding: 0.25rem 0.4rem;
                }

                .logo {
                    max-width: 140px;
                }
                
                nav {
                    flex-direction: column;
                    align-items: center;
                    padding: 0;
                }
                
                .dropdown {
                    width: 100%;
                }
                
                nav a {
                    width: 100%;
                    padding: 0.75rem 1rem;
                    border-bottom: 1px solid rgba(255,255,255,0.1);
                }
                
                .dropdown-content {
                    position: static;
                    box-shadow: none;
                    width: 100%;
                    display: none;
                }
                
                .dropdown-content a {
                    padding-left: 2rem;
                    background: rgba(0,0,0,0.1);
                }
            }

            @media (max-width: 360px) {
                .logo {
                    max-width: 100px;
                }
                
                .container {
                    padding: 0.875rem;
                    margin: 0.75rem 0.375rem;
                }
                
                h2 {
                    font-size: 1.2rem;
                }
                
                .modal-content {
                    padding: 1rem;
                }
                
                table {
                    font-size: 0.75rem;
                }
                
                table th, table td {
                    padding: 0.5rem 0.375rem;
                }
            }
        </style>
    </head>
    <body>



    <div class="container">
        <h2>
            <i class="fas fa-truck"></i>
            Consulta de Transportadoras
        </h2>

        <!-- ===========================================
            MENSAGENS DE FEEDBACK
            =========================================== -->
        <?php if ($error): ?>
            <div class="alert alert-error">
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

        <!-- ===========================================
            ESTATÍSTICAS
            =========================================== -->
        <div class="stats-container">
            <div class="stat-item">
                <div class="stat-number"><?php echo $totalGeralTransportadoras; ?></div>
                <div class="stat-label">Total de Transportadoras</div>
                <div class="stat-icon">
                    <i class="fas fa-truck" style="color: var(--primary-color);"></i>
                </div>
            </div>
            
            <div class="stat-item ativo">
                <div class="stat-number"><?php echo $transportadorasAtivas; ?></div>
                <div class="stat-label">Transportadoras Ativas</div>
                <div class="stat-icon">
                    <i class="fas fa-check-circle" style="color: var(--success-color);"></i>
                </div>
            </div>
            
            <div class="stat-item inativo">
                <div class="stat-number"><?php echo $transportadorasInativas; ?></div>
                <div class="stat-label">Transportadoras Inativas</div>
                <div class="stat-icon">
                    <i class="fas fa-times-circle" style="color: var(--medium-gray);"></i>
                </div>
            </div>
            
            <div class="stat-item">
                <div class="stat-number"><?php echo count($topTransportadoras); ?></div>
                <div class="stat-label">Com Vendas Registradas</div>
                <div class="stat-icon">
                    <i class="fas fa-chart-line" style="color: var(--info-color);"></i>
                </div>
            </div>
        </div>

        <!-- ===========================================
            FILTROS AVANÇADOS
            =========================================== -->
        <div class="filters-container">
            <form action="consulta_transportadoras.php" method="GET" id="filtersForm">
                <div class="filters-row">
                    <div class="search-group">
                        <label for="search">
                            <i class="fas fa-search"></i>
                            Buscar transportadora:
                        </label>
                        <input type="text" 
                            name="search" 
                            id="search" 
                            class="search-input"
                            placeholder="Digite código, nome, CNPJ, telefone ou e-mail..." 
                            value="<?php echo htmlspecialchars($searchTerm ?? ''); ?>"
                            autocomplete="off">
                    </div>
                    
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i> 
                        Pesquisar
                    </button>
                    
                    <button type="button" class="btn btn-secondary" onclick="limparFiltros()">
                        <i class="fas fa-undo"></i> 
                        Limpar
                    </button>
                </div>
            </form>
        </div>

        <!-- ===========================================
            TABELA DE TRANSPORTADORAS
            =========================================== -->
        <?php if (count($transportadoras) > 0): ?>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th onclick="ordenarTabela('codigo')" title="Clique para ordenar">
                                <i class="fas fa-barcode"></i> Código
                                <i class="fas fa-sort sort-icon"></i>
                            </th>
                            <th onclick="ordenarTabela('nome')" title="Clique para ordenar">
                                <i class="fas fa-truck"></i> Nome
                                <i class="fas fa-sort sort-icon"></i>
                            </th>
                            <th onclick="ordenarTabela('cnpj')" title="Clique para ordenar">
                                <i class="fas fa-id-card"></i> CNPJ
                                <i class="fas fa-sort sort-icon"></i>
                            </th>
                            <th onclick="ordenarTabela('telefone')" title="Clique para ordenar">
                                <i class="fas fa-phone"></i> Telefone
                                <i class="fas fa-sort sort-icon"></i>
                            </th>
                            <th>
                                <i class="fas fa-chart-bar"></i> Vendas
                            </th>
                            <th>
                                <i class="fas fa-calendar"></i> Última Venda
                            </th>
                            <th>
                                <i class="fas fa-info-circle"></i> Status
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($transportadoras as $transportadora): ?>
                            <?php 
                            $isAtiva = $transportadora['vendas_count'] > 0 && 
                                    $transportadora['ultima_venda'] && 
                                    strtotime($transportadora['ultima_venda']) > strtotime('-6 months');
                            ?>
                            <tr>
                                <td>
                                    <span class="transportadora-link" onclick="openModal(<?php echo $transportadora['id']; ?>)">
                                        <i class="fas fa-eye"></i>
                                        <?php echo htmlspecialchars($transportadora['codigo'] ?: 'N/A'); ?>
                                    </span>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($transportadora['nome']); ?></strong>
                                    <?php if (!empty($transportadora['email'])): ?>
                                        <br><small style="color: var(--medium-gray);">
                                            <i class="fas fa-envelope"></i> <?php echo htmlspecialchars($transportadora['email']); ?>
                                        </small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($transportadora['cnpj'])): ?>
                                        <strong><?php echo htmlspecialchars($transportadora['cnpj']); ?></strong>
                                    <?php else: ?>
                                        <span style="color: var(--medium-gray); font-style: italic;">Não informado</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($transportadora['telefone'])): ?>
                                        <strong><?php echo htmlspecialchars($transportadora['telefone']); ?></strong>
                                    <?php else: ?>
                                        <span style="color: var(--medium-gray); font-style: italic;">Não informado</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="vendas-count <?php echo $transportadora['vendas_count'] == 0 ? 'zero' : ''; ?>">
                                        <i class="fas fa-shopping-cart"></i>
                                        <?php echo $transportadora['vendas_count']; ?> vendas
                                    </span>
                                </td>
                                <td>
                                    <?php if (!empty($transportadora['ultima_venda'])): ?>
                                        <strong><?php echo date('d/m/Y', strtotime($transportadora['ultima_venda'])); ?></strong>
                                        <br><small style="color: var(--medium-gray);">
                                            <?php 
                                            $dias = floor((time() - strtotime($transportadora['ultima_venda'])) / 86400);
                                            echo $dias > 0 ? "há {$dias} dias" : "hoje";
                                            ?>
                                        </small>
                                    <?php else: ?>
                                        <span style="color: var(--medium-gray); font-style: italic;">Nunca</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="status-badge <?php echo $isAtiva ? 'ativo' : 'inativo'; ?>">
                                        <i class="fas fa-<?php echo $isAtiva ? 'check-circle' : 'times-circle'; ?>"></i>
                                        <?php echo $isAtiva ? 'Ativa' : 'Inativa'; ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- ===========================================
                PAGINAÇÃO
                =========================================== -->
            <?php if ($totalPaginas > 1): ?>
            <div class="pagination-container">
                <div class="pagination-info">
                    Mostrando <?php echo (($paginaAtual - 1) * $itensPorPagina + 1); ?> a 
                    <?php echo min($paginaAtual * $itensPorPagina, $totalRegistros); ?> de 
                    <?php echo $totalRegistros; ?> transportadoras
                </div>
                
                <div class="pagination">
                    <!-- Botão Anterior -->
                    <?php if ($paginaAtual > 1): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => $paginaAtual - 1])); ?>" class="page-btn">
                            <i class="fas fa-chevron-left"></i>
                        </a>
                    <?php else: ?>
                        <span class="page-btn disabled">
                            <i class="fas fa-chevron-left"></i>
                        </span>
                    <?php endif; ?>

                    <!-- Números das páginas -->
                    <?php
                    $inicio = max(1, $paginaAtual - 2);
                    $fim = min($totalPaginas, $paginaAtual + 2);
                    
                    if ($inicio > 1): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => 1])); ?>" class="page-btn">1</a>
                        <?php if ($inicio > 2): ?>
                            <span class="page-btn disabled">...</span>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php for ($i = $inicio; $i <= $fim; $i++): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => $i])); ?>" 
                        class="page-btn <?php echo $i == $paginaAtual ? 'active' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>

                    <?php if ($fim < $totalPaginas): ?>
                        <?php if ($fim < $totalPaginas - 1): ?>
                            <span class="page-btn disabled">...</span>
                        <?php endif; ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => $totalPaginas])); ?>" class="page-btn"><?php echo $totalPaginas; ?></a>
                    <?php endif; ?>

                    <!-- Botão Próximo -->
                    <?php if ($paginaAtual < $totalPaginas): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['pagina' => $paginaAtual + 1])); ?>" class="page-btn">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php else: ?>
                        <span class="page-btn disabled">
                            <i class="fas fa-chevron-right"></i>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

        <?php else: ?>
            <!-- ===========================================
                MENSAGEM SEM RESULTADOS
                =========================================== -->
            <div class="no-results">
                <i class="fas fa-search"></i>
                <p>Nenhuma transportadora encontrada.</p>
                <small>Tente ajustar os filtros ou cadastre uma nova transportadora.</small>
                <div style="margin-top: 1rem;">
                    <a href="cadastro_transportadoras.php" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Cadastrar Nova Transportadora
                    </a>
                </div>
            </div>
        <?php endif; ?>

        <!-- ===========================================
            TOP TRANSPORTADORAS (SE HOUVER)
            =========================================== -->
        <?php if (!empty($topTransportadoras)): ?>
        <div class="detail-section" style="margin-top: 2rem;">
            <div class="detail-header">
                <i class="fas fa-trophy"></i>
                Top 5 Transportadoras por Volume de Vendas
            </div>
            <div class="detail-content">
                <div style="display: grid; gap: 1rem;">
                    <?php foreach ($topTransportadoras as $index => $top): ?>
                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 1rem; background: var(--light-gray); border-radius: var(--radius-sm); border-left: 4px solid var(--secondary-color);">
                        <div style="display: flex; align-items: center; gap: 1rem;">
                            <div style="background: var(--secondary-color); color: white; width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold;">
                                <?php echo $index + 1; ?>
                            </div>
                            <div>
                                <div style="font-weight: 600; color: var(--primary-color);">
                                    <?php echo htmlspecialchars($top['nome']); ?>
                                </div>
                                <div style="font-size: 0.9rem; color: var(--medium-gray);">
                                    <?php echo $top['total_vendas']; ?> vendas realizadas
                                </div>
                            </div>
                        </div>
                        <div style="text-align: right;">
                            <div style="font-weight: 700; color: var(--success-color);">
                                R$ <?php echo number_format($top['valor_total_vendas'], 2, ',', '.'); ?>
                            </div>
                            <div style="font-size: 0.85rem; color: var(--medium-gray);">
                                Valor total em vendas
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- ===========================================
        MODAL DE DETALHES DA TRANSPORTADORA
        =========================================== -->
    <div id="transportadoraModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>
                    <i class="fas fa-truck"></i> 
                    Detalhes da Transportadora
                </h3>
                <span class="close" onclick="closeModal()">&times;</span>
            </div>
            <div class="modal-body" id="modalBody">
                <div class="loading-spinner" style="text-align: center; padding: 3rem;">
                    <div style="width: 40px; height: 40px; border: 3px solid var(--border-color); border-top: 3px solid var(--secondary-color); border-radius: 50%; animation: spin 1s linear infinite; margin: 0 auto;"></div>
                    <p style="margin-top: 1rem; color: var(--medium-gray);">Carregando detalhes...</p>
                </div>
            </div>
            <div class="modal-footer" id="modalFooter" style="display: none;">
                <button class="btn btn-warning" onclick="editarTransportadora()" id="editarBtn">
                    <i class="fas fa-edit"></i> Editar
                </button>
                <button class="btn btn-danger" onclick="confirmarExclusao()" id="excluirBtn">
                    <i class="fas fa-trash"></i> Excluir
                </button>
                <button class="btn btn-primary" onclick="imprimirTransportadora()">
                    <i class="fas fa-print"></i> Imprimir
                </button>
                <button class="btn btn-secondary" onclick="closeModal()">
                    <i class="fas fa-times"></i> Fechar
                </button>
            </div>
        </div>
    </div>

    <script>
    // ===========================================
    // SISTEMA COMPLETO DE CONSULTA DE TRANSPORTADORAS
    // JavaScript Completo - LicitaSis v7.0
    // ===========================================

    // ===========================================
    // VARIÁVEIS GLOBAIS
    // ===========================================
    let currentTransportadoraId = null;
    let currentTransportadoraData = null;
    let isEditingTransportadora = false;

    // ===========================================
    // FUNÇÕES DE CONTROLE DO MODAL
    // ===========================================

    /**
     * Abre o modal com detalhes da transportadora
     * @param {number} transportadoraId - ID da transportadora
     */
    function openModal(transportadoraId) {
        console.log('🔍 Abrindo modal para transportadora ID:', transportadoraId);
        
        currentTransportadoraId = transportadoraId;
        const modal = document.getElementById('transportadoraModal');
        const modalBody = document.getElementById('modalBody');
        const modalFooter = document.getElementById('modalFooter');
        
        // Mostra o modal
        modal.style.display = 'block';
        document.body.style.overflow = 'hidden';
        
        // Resetar estado do modal
        resetModalState();
        
        // Mostra loading
        modalBody.innerHTML = `
            <div class="loading-spinner" style="text-align: center; padding: 3rem;">
                <div style="width: 40px; height: 40px; border: 3px solid var(--border-color); border-top: 3px solid var(--secondary-color); border-radius: 50%; animation: spin 1s linear infinite; margin: 0 auto;"></div>
                <p style="margin-top: 1rem; color: var(--medium-gray);">Carregando detalhes da transportadora...</p>
            </div>
        `;
        modalFooter.style.display = 'none';
        
        // Busca dados da transportadora
        const url = `consulta_transportadoras.php?get_transportadora_id=${transportadoraId}&t=${Date.now()}`;
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
                console.log('✅ Dados da transportadora recebidos:', data);
                
                if (data.error) {
                    throw new Error(data.error);
                }
                
                currentTransportadoraData = data;
                renderTransportadoraDetails(data);
                modalFooter.style.display = 'flex';
                
                console.log('✅ Modal renderizado com sucesso para transportadora:', data.nome);
            })
            .catch(error => {
                console.error('❌ Erro ao carregar transportadora:', error);
                modalBody.innerHTML = `
                    <div style="text-align: center; padding: 3rem; color: var(--danger-color);">
                        <i class="fas fa-exclamation-triangle" style="font-size: 3rem; margin-bottom: 1rem;"></i>
                        <p style="font-size: 1.1rem; margin-bottom: 1rem;">Erro ao carregar transportadora</p>
                        <p style="color: var(--medium-gray);">${error.message}</p>
                        <button class="btn btn-warning" onclick="openModal(${transportadoraId})" style="margin: 1rem 0.5rem;">
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
     * Reseta o estado do modal
     */
    function resetModalState() {
        const editForm = document.getElementById('transportadoraEditForm');
        if (editForm) {
            editForm.style.display = 'none';
        }
        
        const viewMode = document.getElementById('transportadoraViewMode');
        if (viewMode) {
            viewMode.style.display = 'block';
        }
        
        isEditingTransportadora = false;
    }

    /**
     * Renderiza os detalhes completos da transportadora no modal
     * @param {Object} transportadora - Dados da transportadora
     */
    function renderTransportadoraDetails(transportadora) {
        console.log('🎨 Renderizando detalhes da transportadora:', transportadora);
        
        const modalBody = document.getElementById('modalBody');

        modalBody.innerHTML = `
            <div class="transportadora-details">
                <!-- Formulário de Edição (inicialmente oculto) -->
                <form id="transportadoraEditForm" style="display: none;">
                    <input type="hidden" name="id" value="${transportadora.id}">
                    <input type="hidden" name="update_transportadora" value="1">
                    
                    <div class="detail-section">
                        <div class="detail-header">
                            <i class="fas fa-edit"></i>
                            Editar Informações da Transportadora
                        </div>
                        <div class="detail-content">
                            <div class="detail-grid">
                                <div class="detail-item">
                                    <div class="detail-label">Código</div>
                                    <input type="text" name="codigo" class="form-control" value="${transportadora.codigo || ''}" placeholder="Código da transportadora">
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">Nome *</div>
                                    <input type="text" name="nome" class="form-control" value="${transportadora.nome || ''}" required placeholder="Nome da transportadora">
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">CNPJ</div>
                                    <input type="text" name="cnpj" class="form-control" value="${transportadora.cnpj || ''}" maxlength="18" placeholder="00.000.000/0000-00">
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">Telefone</div>
                                    <input type="text" name="telefone" class="form-control" value="${transportadora.telefone || ''}" placeholder="(00) 00000-0000">
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">E-mail</div>
                                    <input type="email" name="email" class="form-control" value="${transportadora.email || ''}" placeholder="email@transportadora.com">
                                </div>
                                <div class="detail-item" style="grid-column: 1 / -1;">
                                    <div class="detail-label">Endereço</div>
                                    <input type="text" name="endereco" class="form-control" value="${transportadora.endereco || ''}" placeholder="Endereço completo">
                                </div>
                                <div class="detail-item" style="grid-column: 1 / -1;">
                                    <div class="detail-label">Observações</div>
                                    <textarea name="observacoes" class="form-control" rows="4" placeholder="Observações gerais sobre a transportadora">${transportadora.observacoes || ''}</textarea>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="modal-buttons">
                        <button type="submit" class="btn btn-success" id="salvarBtn">
                            <i class="fas fa-save"></i> Salvar Alterações
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="cancelarEdicao()">
                            <i class="fas fa-times"></i> Cancelar
                        </button>
                        <button type="button" class="btn btn-danger" onclick="confirmarExclusaoEdicao()" id="excluirEdicaoBtn">
                            <i class="fas fa-trash"></i> Excluir Transportadora
                        </button>
                    </div>
                </form>

                <!-- Visualização Normal (inicialmente visível) -->
                <div id="transportadoraViewMode">
                    <!-- Informações Básicas -->
                    <div class="detail-section">
                        <div class="detail-header">
                            <i class="fas fa-info-circle"></i>
                            Informações Básicas
                        </div>
                        <div class="detail-content">
                            <div class="detail-grid">
                                <div class="detail-item">
                                    <div class="detail-label">Código</div>
                                    <div class="detail-value highlight">${transportadora.codigo || 'Não informado'}</div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">Nome da Transportadora</div>
                                    <div class="detail-value highlight">${transportadora.nome || 'N/A'}</div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">CNPJ</div>
                                    <div class="detail-value">${transportadora.cnpj || 'Não informado'}</div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">Telefone</div>
                                    <div class="detail-value">${transportadora.telefone || 'Não informado'}</div>
                                </div>
                                <div class="detail-item">
                                    <div class="detail-label">E-mail</div>
                                    <div class="detail-value">${transportadora.email || 'Não informado'}</div>
                                </div>
                                ${transportadora.endereco ? `
                                <div class="detail-item" style="grid-column: 1 / -1;">
                                    <div class="detail-label">Endereço</div>
                                    <div class="detail-value">${transportadora.endereco}</div>
                                </div>
                                ` : ''}
                            </div>
                        </div>
                    </div>

                    <!-- Observações (se houver) -->
                    ${transportadora.observacoes ? `
                    <div class="detail-section">
                        <div class="detail-header">
                            <i class="fas fa-comment-alt"></i>
                            Observações
                        </div>
                        <div class="detail-content">
                            <div class="detail-value">${transportadora.observacoes}</div>
                        </div>
                    </div>
                    ` : ''}
                </div>
            </div>
        `;

        // Adiciona event listener para o formulário de edição
        const editForm = document.getElementById('transportadoraEditForm');
        if (editForm) {
            editForm.addEventListener('submit', salvarEdicaoTransportadora);
        }

        // Adiciona máscaras para os campos
        adicionarMascaras();
        
        console.log('✅ Detalhes da transportadora renderizados com sucesso');
    }

    /**
     * Fecha o modal
     */
    function closeModal() {
        // Verifica se está em modo de edição
        const editForm = document.getElementById('transportadoraEditForm');
        const isEditing = editForm && editForm.style.display !== 'none';
        
        if (isEditing) {
            const confirmClose = confirm(
                'Você está editando a transportadora.\n\n' +
                'Tem certeza que deseja fechar sem salvar as alterações?\n\n' +
                'As alterações não salvas serão perdidas.'
            );
            
            if (!confirmClose) {
                return; // Não fecha o modal
            }
        }
        
        // Fecha o modal
        const modal = document.getElementById('transportadoraModal');
        modal.style.display = 'none';
        document.body.style.overflow = 'auto';
        
        // Limpa dados
        currentTransportadoraId = null;
        currentTransportadoraData = null;
        isEditingTransportadora = false;
        
        // Reseta o modal para o próximo uso
        resetModalState();
        
        console.log('✅ Modal fechado');
    }

    // ===========================================
    // FUNÇÕES DE EDIÇÃO DA TRANSPORTADORA
    // ===========================================

    /**
     * Ativa o modo de edição da transportadora
     */
    function editarTransportadora() {
        console.log('🖊️ Ativando modo de edição da transportadora');
        
        const viewMode = document.getElementById('transportadoraViewMode');
        const editForm = document.getElementById('transportadoraEditForm');
        const editarBtn = document.getElementById('editarBtn');
        
        if (viewMode) viewMode.style.display = 'none';
        if (editForm) editForm.style.display = 'block';
        if (editarBtn) editarBtn.style.display = 'none';
        
        isEditingTransportadora = true;
        
        showToast('Modo de edição ativado', 'info');
    }

    /**
     * Cancela a edição da transportadora
     */
    function cancelarEdicao() {
        const confirmCancel = confirm(
            'Tem certeza que deseja cancelar a edição?\n\n' +
            'Todas as alterações não salvas serão perdidas.'
        );
        
        if (confirmCancel) {
            const viewMode = document.getElementById('transportadoraViewMode');
            const editForm = document.getElementById('transportadoraEditForm');
            const editarBtn = document.getElementById('editarBtn');
            
            if (viewMode) viewMode.style.display = 'block';
            if (editForm) editForm.style.display = 'none';
            if (editarBtn) editarBtn.style.display = 'inline-flex';
            
            isEditingTransportadora = false;
            
            showToast('Edição cancelada', 'info');
        }
    }

    /**
     * Salva a edição da transportadora
     */
    function salvarEdicaoTransportadora(event) {
        event.preventDefault();
        
        const form = event.target;
        const formData = new FormData(form);
        const submitBtn = document.getElementById('salvarBtn');
        
        // Desabilita o botão e mostra loading
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...';
        }
        
        fetch('consulta_transportadoras.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showToast('Transportadora atualizada com sucesso!', 'success');
                
                // Recarrega os dados do modal
                setTimeout(() => {
                    openModal(currentTransportadoraId);
                }, 1000);
                
            } else {
                throw new Error(data.error || 'Erro ao salvar transportadora');
            }
        })
        .catch(error => {
            console.error('Erro ao salvar transportadora:', error);
            showToast('Erro ao salvar: ' + error.message, 'error');
        })
        .finally(() => {
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
        if (!currentTransportadoraData) return;
        
        const confirmMessage = 
            `⚠️ ATENÇÃO: EXCLUSÃO PERMANENTE ⚠️\n\n` +
            `Tem certeza que deseja EXCLUIR permanentemente esta transportadora?\n\n` +
            `Transportadora: ${currentTransportadoraData.nome || 'N/A'}\n` +
            `Código: ${currentTransportadoraData.codigo || 'N/A'}\n\n` +
            `⚠️ Esta ação NÃO PODE ser desfeita!\n\n` +
            `Digite "CONFIRMAR" para prosseguir:`;
        
        const confirmacao = prompt(confirmMessage);
        
        if (confirmacao === 'CONFIRMAR') {
            excluirTransportadora();
        } else if (confirmacao !== null) {
            showToast('Exclusão cancelada - confirmação incorreta', 'warning');
        }
    }

    // ===========================================
    // FUNÇÕES DE AÇÃO DA TRANSPORTADORA
    // ===========================================

    /**
     * Exclui transportadora
     */
    function excluirTransportadora() {
        if (!currentTransportadoraId) return;
        
        const excluirBtn = document.getElementById('excluirBtn') || document.getElementById('excluirEdicaoBtn');
        if (excluirBtn) {
            excluirBtn.disabled = true;
            excluirBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Excluindo...';
        }
        
        fetch('consulta_transportadoras.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `delete_transportadora_id=${currentTransportadoraId}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showToast('Transportadora excluída com sucesso!', 'success');
                
                // Fecha o modal
                closeModal();
                
                // Recarrega a página após um breve delay
                setTimeout(() => {
                    window.location.reload();
                }, 1500);
                
            } else {
                throw new Error(data.error || 'Erro ao excluir transportadora');
            }
        })
        .catch(error => {
            console.error('Erro ao excluir transportadora:', error);
            showToast('Erro ao excluir: ' + error.message, 'error');
        })
        .finally(() => {
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
        if (!currentTransportadoraData) return;
        
        const confirmMessage = 
            `⚠️ ATENÇÃO: EXCLUSÃO PERMANENTE ⚠️\n\n` +
            `Tem certeza que deseja EXCLUIR permanentemente esta transportadora?\n\n` +
            `Transportadora: ${currentTransportadoraData.nome || 'N/A'}\n` +
            `Código: ${currentTransportadoraData.codigo || 'N/A'}\n\n` +
            `⚠️ Esta ação NÃO PODE ser desfeita!\n\n` +
            `Digite "CONFIRMAR" para prosseguir:`;
        
        const confirmacao = prompt(confirmMessage);
        
        if (confirmacao === 'CONFIRMAR') {
            excluirTransportadora();
        } else if (confirmacao !== null) {
            showToast('Exclusão cancelada - confirmação incorreta', 'warning');
        }
    }

    /**
     * Imprime transportadora
     */
    function imprimirTransportadora() {
        if (!currentTransportadoraId) return;
        
        const printUrl = `imprimir_transportadora.php?id=${currentTransportadoraId}`;
        window.open(printUrl, '_blank', 'width=800,height=600');
    }

    // ===========================================
    // FUNÇÕES DE ORDENAÇÃO E FILTROS
    // ===========================================

    /**
     * Ordena a tabela por campo
     */
    function ordenarTabela(campo) {
        const urlParams = new URLSearchParams(window.location.search);
        const currentOrder = urlParams.get('order');
        const currentDir = urlParams.get('dir');
        
        let newDir = 'asc';
        if (currentOrder === campo && currentDir === 'asc') {
            newDir = 'desc';
        }
        
        urlParams.set('order', campo);
        urlParams.set('dir', newDir);
        urlParams.delete('pagina'); // Reset para primeira página
        
        window.location.search = urlParams.toString();
    }

    /**
     * Limpa todos os filtros
     */
    function limparFiltros() {
        const form = document.getElementById('filtersForm');
        if (form) {
            form.reset();
            form.submit();
        }
    }

    // ===========================================
    // UTILITÁRIOS
    // ===========================================

    /**
     * Adiciona máscaras para os campos
     */
    function adicionarMascaras() {
        // Máscara para CNPJ
        const cnpjInput = document.querySelector('input[name="cnpj"]');
        if (cnpjInput) {
            cnpjInput.addEventListener('input', function(e) {
                let value = e.target.value.replace(/\D/g, '');
                if (value.length <= 14) {
                    value = value.replace(/^(\d{2})(\d)/, '$1.$2');
                    value = value.replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3');
                    value = value.replace(/\.(\d{3})(\d)/, '.$1/$2');
                    value = value.replace(/(\d{4})(\d)/, '$1-$2');
                    e.target.value = value;
                }
            });
        }
        
        // Máscara para telefone
        const telefoneInput = document.querySelector('input[name="telefone"]');
        if (telefoneInput) {
            telefoneInput.addEventListener('input', function(e) {
                let value = e.target.value.replace(/\D/g, '');
                if (value.length <= 11) {
                    if (value.length <= 10) {
                        value = value.replace(/^(\d{2})(\d)/, '($1) $2');
                        value = value.replace(/(\d{4})(\d)/, '$1-$2');
                    } else {
                        value = value.replace(/^(\d{2})(\d)/, '($1) $2');
                        value = value.replace(/(\d{5})(\d)/, '$1-$2');
                    }
                    e.target.value = value;
                }
            });
        }
    }

    /**
     * Sistema de notificações toast
     */
    function showToast(message, type = 'info', duration = 4000) {
        // Remove toast existente se houver
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
        
        // Define cor baseada no tipo
        let backgroundColor, icon;
        switch(type) {
            case 'success':
                backgroundColor = 'var(--success-color, #28a745)';
                icon = 'fas fa-check-circle';
                break;
            case 'error':
                backgroundColor = 'var(--danger-color, #dc3545)';
                icon = 'fas fa-exclamation-triangle';
                break;
            case 'warning':
                backgroundColor = 'var(--warning-color, #ffc107)';
                icon = 'fas fa-exclamation-circle';
                break;
            default:
                backgroundColor = 'var(--info-color, #17a2b8)';
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
        
        // Anima entrada
        setTimeout(() => {
            toast.style.transform = 'translateX(0)';
        }, 100);
        
        // Remove automaticamente
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

    /**
     * Formata valor monetário
     */
    function formatarMoeda(valor) {
        return 'R$ ' + parseFloat(valor || 0).toLocaleString('pt-BR', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    /**
     * Formata data
     */
    function formatarData(data) {
        if (!data || data === '0000-00-00') return 'N/A';
        
        try {
            const date = new Date(data);
            return date.toLocaleDateString('pt-BR');
        } catch {
            return 'Data inválida';
        }
    }

    /**
     * Valida CNPJ
     */
    function validarCNPJ(cnpj) {
        cnpj = cnpj.replace(/[^\d]+/g, '');
        
        if (cnpj.length !== 14) return false;
        
        // Elimina CNPJs inválidos conhecidos
        if (/^(\d)\1+$/.test(cnpj)) return false;
        
        // Validação do dígito verificador
        let tamanho = cnpj.length - 2;
        let numeros = cnpj.substring(0, tamanho);
        let digitos = cnpj.substring(tamanho);
        let soma = 0;
        let pos = tamanho - 7;
        
        for (let i = tamanho; i >= 1; i--) {
            soma += numeros.charAt(tamanho - i) * pos--;
            if (pos < 2) pos = 9;
        }
        
        let resultado = soma % 11 < 2 ? 0 : 11 - soma % 11;
        if (resultado != digitos.charAt(0)) return false;
        
        tamanho = tamanho + 1;
        numeros = cnpj.substring(0, tamanho);
        soma = 0;
        pos = tamanho - 7;
        
        for (let i = tamanho; i >= 1; i--) {
            soma += numeros.charAt(tamanho - i) * pos--;
            if (pos < 2) pos = 9;
        }
        
        resultado = soma % 11 < 2 ? 0 : 11 - soma % 11;
        if (resultado != digitos.charAt(1)) return false;
        
        return true;
    }

    /**
     * Valida e-mail
     */
    function validarEmail(email) {
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return emailRegex.test(email);
    }

    // ===========================================
    // FUNÇÕES DE VALIDAÇÃO EM TEMPO REAL
    // ===========================================

    /**
     * Adiciona validação em tempo real aos formulários
     */
    function adicionarValidacaoTempoReal() {
        // Validação de CNPJ
        const cnpjInputs = document.querySelectorAll('input[name="cnpj"]');
        cnpjInputs.forEach(input => {
            input.addEventListener('blur', function() {
                const cnpj = this.value.trim();
                if (cnpj && !validarCNPJ(cnpj)) {
                    this.classList.add('is-invalid');
                    showToast('CNPJ inválido', 'error', 2000);
                } else {
                    this.classList.remove('is-invalid');
                    if (cnpj) this.classList.add('is-valid');
                }
            });
        });
        
        // Validação de e-mail
        const emailInputs = document.querySelectorAll('input[name="email"]');
        emailInputs.forEach(input => {
            input.addEventListener('blur', function() {
                const email = this.value.trim();
                if (email && !validarEmail(email)) {
                    this.classList.add('is-invalid');
                    showToast('E-mail inválido', 'error', 2000);
                } else {
                    this.classList.remove('is-invalid');
                    if (email) this.classList.add('is-valid');
                }
            });
        });
        
        // Validação de campos obrigatórios
        const requiredInputs = document.querySelectorAll('input[required]');
        requiredInputs.forEach(input => {
            input.addEventListener('blur', function() {
                if (!this.value.trim()) {
                    this.classList.add('is-invalid');
                } else {
                    this.classList.remove('is-invalid');
                    this.classList.add('is-valid');
                }
            });
        });
    }

    // ===========================================
    // INICIALIZAÇÃO E EVENT LISTENERS
    // ===========================================

    /**
     * Inicialização quando a página carrega
     */
    document.addEventListener('DOMContentLoaded', function() {
        console.log('🚀 LicitaSis - Sistema de Consulta de Transportadoras carregado');
        
        // Event listener para fechar modal com ESC
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                const modal = document.getElementById('transportadoraModal');
                
                if (modal && modal.style.display === 'block') {
                    closeModal();
                }
            }
        });
        
        // Event listener para clicar fora do modal
        const modal = document.getElementById('transportadoraModal');
        if (modal) {
            modal.addEventListener('click', function(event) {
                if (event.target === modal) {
                    closeModal();
                }
            });
        }
        
        // Auto-submit do formulário de filtros com delay
        const searchInput = document.getElementById('search');
        if (searchInput) {
            let searchTimeout;
            searchInput.addEventListener('input', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => {
                    const form = document.getElementById('filtersForm');
                    if (form) form.submit();
                }, 800); // Delay de 800ms
            });
        }
        
        // Inicializa tooltips se necessário
        initializeTooltips();
        
        // Adiciona indicadores visuais de ordenação
        updateSortIndicators();
        
        // Inicializa máscaras nos campos da página principal (se houver)
        initializeMasksOnPage();
        
        console.log('✅ Todos os event listeners inicializados');
    });

    /**
     * Inicializa tooltips para elementos que precisam
     */
    function initializeTooltips() {
        document.querySelectorAll('[title]').forEach(element => {
            element.addEventListener('mouseenter', function(e) {
                const tooltip = document.createElement('div');
                tooltip.className = 'tooltip';
                tooltip.textContent = this.title;
                tooltip.style.cssText = `
                    position: absolute;
                    background: rgba(0,0,0,0.8);
                    color: white;
                    padding: 0.5rem 0.75rem;
                    border-radius: 4px;
                    font-size: 0.8rem;
                    z-index: 10000;
                    pointer-events: none;
                    max-width: 200px;
                    word-wrap: break-word;
                `;
                
                document.body.appendChild(tooltip);
                
                const rect = this.getBoundingClientRect();
                tooltip.style.left = rect.left + (rect.width / 2) - (tooltip.offsetWidth / 2) + 'px';
                tooltip.style.top = rect.top - tooltip.offsetHeight - 5 + 'px';
                
                this.setAttribute('data-original-title', this.title);
                this.removeAttribute('title');
                
                this.addEventListener('mouseleave', function() {
                    tooltip.remove();
                    this.title = this.getAttribute('data-original-title');
                    this.removeAttribute('data-original-title');
                }, { once: true });
            });
        });
    }

    /**
     * Atualiza indicadores visuais de ordenação
     */
    function updateSortIndicators() {
        const urlParams = new URLSearchParams(window.location.search);
        const currentOrder = urlParams.get('order');
        const currentDir = urlParams.get('dir');
        
        // Remove indicadores anteriores
        document.querySelectorAll('.sort-icon').forEach(icon => {
            icon.className = 'fas fa-sort sort-icon';
        });
        
        // Adiciona indicador atual
        if (currentOrder) {
            const headers = document.querySelectorAll('th[onclick*="' + currentOrder + '"]');
            headers.forEach(header => {
                const icon = header.querySelector('.sort-icon');
                if (icon) {
                    icon.className = `fas fa-sort-${currentDir === 'desc' ? 'down' : 'up'} sort-icon`;
                }
            });
        }
    }

    /**
     * Inicializa máscaras nos campos da página principal
     */
    function initializeMasksOnPage() {
        // Esta função pode ser expandida para adicionar máscaras em campos de filtro
        // da página principal, se necessário
    }

    /**
     * Função para destacar termo de busca nos resultados
     */
    function highlightSearchTerm() {
        const searchTerm = document.getElementById('search').value.trim();
        if (!searchTerm) return;
        
        const tableRows = document.querySelectorAll('tbody tr');
        tableRows.forEach(row => {
            const cells = row.querySelectorAll('td');
            cells.forEach(cell => {
                const text = cell.textContent;
                if (text.toLowerCase().includes(searchTerm.toLowerCase())) {
                    const regex = new RegExp(`(${searchTerm})`, 'gi');
                    cell.innerHTML = cell.innerHTML.replace(regex, '<mark style="background: yellow; padding: 2px;">$1</mark>');
                }
            });
        });
    }

    /**
     * Função para exportar dados
     */
    function exportarDados(formato) {
        const params = new URLSearchParams(window.location.search);
        params.set('export', formato);
        
        showToast(`Iniciando exportação em formato ${formato.toUpperCase()}...`, 'info');
        
        const exportUrl = 'consulta_transportadoras.php?' + params.toString();
        
        // Cria link temporário para download
        const link = document.createElement('a');
        link.href = exportUrl;
        link.download = `transportadoras_${new Date().toISOString().split('T')[0]}.${formato}`;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        
        setTimeout(() => {
            showToast('Exportação concluída!', 'success');
        }, 2000);
    }

    /**
     * Função para imprimir relatório
     */
    function imprimirRelatorio() {
        const params = new URLSearchParams(window.location.search);
        params.set('print', '1');
        
        const printUrl = 'consulta_transportadoras.php?' + params.toString();
        window.open(printUrl, '_blank', 'width=800,height=600');
    }

    // ===========================================
    // FUNÇÕES DE ESTATÍSTICAS E DASHBOARD
    // ===========================================

    /**
     * Carrega estatísticas em tempo real
     */
    function carregarEstatisticas() {
        fetch('get_transportadoras_stats.php')
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    atualizarEstatisticasDOM(data.stats);
                }
            })
            .catch(error => {
                console.error('Erro ao carregar estatísticas:', error);
            });
    }

    /**
     * Atualiza estatísticas no DOM
     */
    function atualizarEstatisticasDOM(stats) {
        const statItems = document.querySelectorAll('.stat-item');
        
        if (statItems.length >= 4) {
            statItems[0].querySelector('.stat-number').textContent = stats.total || 0;
            statItems[1].querySelector('.stat-number').textContent = stats.ativas || 0;
            statItems[2].querySelector('.stat-number').textContent = stats.inativas || 0;
            statItems[3].querySelector('.stat-number').textContent = stats.com_venda || 0;
        }
    }

    // ===========================================
    // FUNÇÕES DE NAVEGAÇÃO E FILTROS AVANÇADOS
    // ===========================================

    /**
     * Filtra por status de transportadora
     */
    function filtrarPorStatus(status) {
        const params = new URLSearchParams(window.location.search);
        
        if (status === 'todas') {
            params.delete('status');
        } else {
            params.set('status', status);
        }
        
        params.delete('pagina'); // Reset para primeira página
        window.location.search = params.toString();
    }

    /**
     * Filtra por período de última venda
     */
    function filtrarPorPeriodo(periodo) {
        const params = new URLSearchParams(window.location.search);
        
        if (periodo === 'todos') {
            params.delete('periodo');
        } else {
            params.set('periodo', periodo);
        }
        
        params.delete('pagina'); // Reset para primeira página
        window.location.search = params.toString();
    }

    // ===========================================
    // FUNÇÕES DE INTEGRAÇÃO E SINCRONIZAÇÃO
    // ===========================================

    /**
     * Sincroniza dados com sistema externo
     */
    function sincronizarDados() {
        showToast('Iniciando sincronização...', 'info');
        
        fetch('sync_transportadoras.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ action: 'sync_all' })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showToast(`Sincronização concluída! ${data.updated} registros atualizados.`, 'success');
                setTimeout(() => window.location.reload(), 2000);
            } else {
                throw new Error(data.error || 'Erro na sincronização');
            }
        })
        .catch(error => {
            console.error('Erro na sincronização:', error);
            showToast('Erro na sincronização: ' + error.message, 'error');
        });
    }

    /**
     * Valida CEP e preenche endereço automaticamente
     */
    function validarCEP(cep, enderecoInput) {
        cep = cep.replace(/\D/g, '');
        
        if (cep.length !== 8) return;
        
        showToast('Buscando endereço...', 'info', 2000);
        
        fetch(`https://viacep.com.br/ws/${cep}/json/`)
            .then(response => response.json())
            .then(data => {
                if (data.erro) {
                    throw new Error('CEP não encontrado');
                }
                
                const endereco = `${data.logradouro}, ${data.bairro}, ${data.localidade} - ${data.uf}`;
                enderecoInput.value = endereco;
                enderecoInput.classList.add('is-valid');
                
                showToast('Endereço preenchido automaticamente!', 'success', 2000);
            })
            .catch(error => {
                console.error('Erro ao buscar CEP:', error);
                showToast('CEP não encontrado', 'warning', 2000);
            });
    }

    // ===========================================
    // FUNÇÕES DE ACESSIBILIDADE E UX
    // ===========================================

    /**
     * Ativa modo de alto contraste
     */
    function toggleAltoContraste() {
        document.body.classList.toggle('alto-contraste');
        
        const isActive = document.body.classList.contains('alto-contraste');
        localStorage.setItem('alto-contraste', isActive);
        
        showToast(
            isActive ? 'Modo alto contraste ativado' : 'Modo alto contraste desativado',
            'info'
        );
    }

    /**
     * Ajusta tamanho da fonte
     */
    function ajustarFonte(acao) {
        const body = document.body;
        const currentSize = parseFloat(getComputedStyle(body).fontSize);
        
        let newSize;
        switch(acao) {
            case 'aumentar':
                newSize = Math.min(currentSize + 2, 24);
                break;
            case 'diminuir':
                newSize = Math.max(currentSize - 2, 12);
                break;
            case 'reset':
                newSize = 16;
                break;
            default:
                return;
        }
        
        body.style.fontSize = newSize + 'px';
        localStorage.setItem('font-size', newSize);
        
        showToast(`Fonte ajustada para ${newSize}px`, 'info', 2000);
    }

    /**
     * Ativa navegação por teclado
     */
    function ativarNavegacaoTeclado() {
        document.addEventListener('keydown', function(event) {
            // Ctrl + F: Foca no campo de busca
            if (event.ctrlKey && event.key === 'f') {
                event.preventDefault();
                const searchInput = document.getElementById('search');
                if (searchInput) {
                    searchInput.focus();
                }
            }
            
            // Ctrl + N: Nova transportadora
            if (event.ctrlKey && event.key === 'n') {
                event.preventDefault();
                window.location.href = 'cadastro_transportadoras.php';
            }
            
            // Ctrl + E: Exportar dados
            if (event.ctrlKey && event.key === 'e') {
                event.preventDefault();
                exportarDados('csv');
            }
            
            // Ctrl + P: Imprimir relatório
            if (event.ctrlKey && event.key === 'p') {
                event.preventDefault();
                imprimirRelatorio();
            }
        });
    }

    // ===========================================
    // FUNÇÕES DE BACKUP E RECOVERY
    // ===========================================

    /**
     * Cria backup dos dados
     */
    function criarBackup() {
        showToast('Iniciando backup...', 'info');
        
        fetch('backup_transportadoras.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ action: 'create_backup' })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showToast('Backup criado com sucesso!', 'success');
                
                // Download do arquivo de backup
                const link = document.createElement('a');
                link.href = data.backup_url;
                link.download = data.backup_filename;
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            } else {
                throw new Error(data.error || 'Erro ao criar backup');
            }
        })
        .catch(error => {
            console.error('Erro no backup:', error);
            showToast('Erro ao criar backup: ' + error.message, 'error');
        });
    }

    // ===========================================
    // FUNÇÕES DE PERFORMANCE E CACHE
    // ===========================================

    /**
     * Cache simples para dados da transportadora
     */
    const transportadoraCache = new Map();

    /**
     * Obtém dados da transportadora com cache
     */
    function getTransportadoraWithCache(id) {
        if (transportadoraCache.has(id)) {
            return Promise.resolve(transportadoraCache.get(id));
        }
        
        return fetch(`consulta_transportadoras.php?get_transportadora_id=${id}`)
            .then(response => response.json())
            .then(data => {
                if (!data.error) {
                    transportadoraCache.set(id, data);
                }
                return data;
            });
    }

    /**
     * Limpa cache de transportadoras
     */
    function clearCache() {
        transportadoraCache.clear();
        showToast('Cache limpo com sucesso!', 'success', 2000);
    }

    // ===========================================
    // INICIALIZAÇÃO FINAL
    // ===========================================

    /**
     * Carrega configurações do usuário
     */
    function carregarConfiguracoes() {
        // Alto contraste
        const altoContraste = localStorage.getItem('alto-contraste');
        if (altoContraste === 'true') {
            document.body.classList.add('alto-contraste');
        }
        
        // Tamanho da fonte
        const fontSize = localStorage.getItem('font-size');
        if (fontSize) {
            document.body.style.fontSize = fontSize + 'px';
        }
    }

    /**
     * Inicialização quando a página está completamente carregada
     */
    window.addEventListener('load', function() {
        carregarConfiguracoes();
        ativarNavegacaoTeclado();
        
        // Carrega estatísticas periodicamente (a cada 5 minutos)
        setInterval(carregarEstatisticas, 5 * 60 * 1000);
        
        console.log('✅ Sistema de Consulta de Transportadoras LicitaSis v7.0 - Totalmente carregado!');
    });

    // ===========================================
    // EXPORT DAS FUNÇÕES PRINCIPAIS
    // ===========================================

    // Torna as funções principais disponíveis globalmente
    window.LicitaSisTransportadoras = {
        openModal,
        closeModal,
        editarTransportadora,
        confirmarExclusao,
        ordenarTabela,
        limparFiltros,
        exportarDados,
        imprimirRelatorio,
        showToast,
        validarCNPJ,
        validarEmail,
        formatarMoeda,
        formatarData
    };

    console.log('✅ JavaScript do Sistema de Consulta de Transportadoras LicitaSis v7.0 - Carregado com sucesso!');
    </script>
    </body>
    </html>