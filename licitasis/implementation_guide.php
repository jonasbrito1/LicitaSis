<?php
/**
 * GUIA DE IMPLEMENTAÇÃO DO SISTEMA DE PERMISSÕES
 * 
 * Este arquivo contém exemplos de como implementar o sistema de permissões
 * em diferentes páginas do sistema LicitaSis
 */

// =================================================================
// EXEMPLO 1: IMPLEMENTAÇÃO BÁSICA EM UMA PÁGINA DE CONSULTA
// =================================================================

/*
// No início de qualquer página (ex: consulta_clientes.php)
session_start();

// Inclui o sistema de permissões
include('db.php');
include('permissions.php');

// Inicializa o gerenciador de permissões
$permissionManager = initPermissions($pdo);

// Verifica se o usuário tem permissão para visualizar clientes
$permissionManager->requirePermission('clientes', 'view');

// Verifica se pode editar (para mostrar/esconder botões de edição)
$canEdit = $permissionManager->hasPagePermission('clientes', 'edit');
$canCreate = $permissionManager->hasPagePermission('clientes', 'create');
$canDelete = $permissionManager->hasPagePermission('clientes', 'delete');
*/

// =================================================================
// EXEMPLO 2: IMPLEMENTAÇÃO EM PÁGINA COM FORMULÁRIO
// =================================================================

/*
// No início da página (ex: cadastro_produto.php)
session_start();

include('db.php');
include('permissions.php');

$permissionManager = initPermissions($pdo);

// Verifica se pode criar produtos
$permissionManager->requirePermission('produtos', 'create');

// Processamento do formulário
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Verifica novamente a permissão antes de processar
    if (!$permissionManager->hasPagePermission('produtos', 'create')) {
        header("Location: access_denied.php");
        exit();
    }
    
    // Processa o formulário...
}
*/

// =================================================================
// EXEMPLO 3: IMPLEMENTAÇÃO EM PÁGINA DE EDIÇÃO
// =================================================================

/*
// No início da página (ex: editar_cliente.php)
session_start();

include('db.php');
include('permissions.php');

$permissionManager = initPermissions($pdo);

// Para usuários nível 1, redireciona para versão somente leitura
if ($permissionManager->isUserLevel1()) {
    header("Location: visualizar_cliente.php?id=" . $_GET['id']);
    exit();
}

// Verifica se pode editar
$permissionManager->requirePermission('clientes', 'edit');

// Processamento da edição
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!$permissionManager->hasPagePermission('clientes', 'edit')) {
        header("Location: access_denied.php");
        exit();
    }
    
    // Processa a edição...
}
*/

// =================================================================
// EXEMPLO 4: NAVEGAÇÃO CONDICIONAL NO HTML
// =================================================================
?>

<!-- Exemplo de como usar no HTML -->
<!DOCTYPE html>
<html>
<head>
    <title>Exemplo de Implementação</title>
</head>
<body>

<!-- Header com navegação dinâmica -->
<nav>
    <?php echo $permissionManager->generateNavigationMenu(); ?>
</nav>

<!-- Botões condicionais baseados em permissões -->
<div class="action-buttons">
    <?php if ($permissionManager->hasPagePermission('clientes', 'create')): ?>
        <a href="cadastro_cliente.php" class="btn btn-primary">
            <i class="fas fa-plus"></i> Novo Cliente
        </a>
    <?php endif; ?>
    
    <?php if ($permissionManager->hasPagePermission('clientes', 'edit')): ?>
        <a href="editar_cliente.php?id=<?php echo $cliente_id; ?>" class="btn btn-secondary">
            <i class="fas fa-edit"></i> Editar
        </a>
    <?php endif; ?>
    
    <?php if ($permissionManager->hasPagePermission('clientes', 'delete')): ?>
        <a href="excluir_cliente.php?id=<?php echo $cliente_id; ?>" class="btn btn-danger" 
           onclick="return confirm('Tem certeza que deseja excluir este cliente?')">
            <i class="fas fa-trash"></i> Excluir
        </a>
    <?php endif; ?>
</div>

<!-- Tabela com ações condicionais -->
<table class="table">
    <thead>
        <tr>
            <th>ID</th>
            <th>Nome</th>
            <th>Email</th>
            <?php if ($permissionManager->hasPagePermission('clientes', 'edit') || 
                      $permissionManager->hasPagePermission('clientes', 'delete')): ?>
                <th>Ações</th>
            <?php endif; ?>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($clientes as $cliente): ?>
        <tr>
            <td><?php echo $cliente['id']; ?></td>
            <td><?php echo htmlspecialchars($cliente['nome']); ?></td>
            <td><?php echo htmlspecialchars($cliente['email']); ?></td>
            
            <?php if ($permissionManager->hasPagePermission('clientes', 'edit') || 
                      $permissionManager->hasPagePermission('clientes', 'delete')): ?>
            <td>
                <?php if ($permissionManager->hasPagePermission('clientes', 'view')): ?>
                    <a href="visualizar_cliente.php?id=<?php echo $cliente['id']; ?>" 
                       class="btn btn-sm btn-info" title="Visualizar">
                        <i class="fas fa-eye"></i>
                    </a>
                <?php endif; ?>
                
                <?php if ($permissionManager->hasPagePermission('clientes', 'edit')): ?>
                    <a href="editar_cliente.php?id=<?php echo $cliente['id']; ?>" 
                       class="btn btn-sm btn-primary" title="Editar">
                        <i class="fas fa-edit"></i>
                    </a>
                <?php endif; ?>
                
                <?php if ($permissionManager->hasPagePermission('clientes', 'delete')): ?>
                    <a href="excluir_cliente.php?id=<?php echo $cliente['id']; ?>" 
                       class="btn btn-sm btn-danger" title="Excluir"
                       onclick="return confirm('Tem certeza que deseja excluir este cliente?')">
                        <i class="fas fa-trash"></i>
                    </a>
                <?php endif; ?>
            </td>
            <?php endif; ?>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<!-- Formulário com campos condicionais -->
<form method="POST">
    <div class="form-group">
        <label for="nome">Nome:</label>
        <?php if ($permissionManager->hasPagePermission('clientes', 'edit')): ?>
            <input type="text" id="nome" name="nome" class="form-control" 
                   value="<?php echo htmlspecialchars($cliente['nome'] ?? ''); ?>" required>
        <?php else: ?>
            <input type="text" class="form-control" 
                   value="<?php echo htmlspecialchars($cliente['nome'] ?? ''); ?>" readonly>
        <?php endif; ?>
    </div>
    
    <div class="form-group">
        <label for="email">Email:</label>
        <?php if ($permissionManager->hasPagePermission('clientes', 'edit')): ?>
            <input type="email" id="email" name="email" class="form-control" 
                   value="<?php echo htmlspecialchars($cliente['email'] ?? ''); ?>" required>
        <?php else: ?>
            <input type="email" class="form-control" 
                   value="<?php echo htmlspecialchars($cliente['email'] ?? ''); ?>" readonly>
        <?php endif; ?>
    </div>
    
    <?php if ($permissionManager->hasPagePermission('clientes', 'edit') || 
              $permissionManager->hasPagePermission('clientes', 'create')): ?>
        <button type="submit" class="btn btn-success">
            <i class="fas fa-save"></i> Salvar
        </button>
    <?php endif; ?>
</form>

</body>
</html>

<?php
// =================================================================
// EXEMPLO 5: IMPLEMENTAÇÃO EM API/AJAX
// =================================================================

/*
// Arquivo: api/clientes.php
session_start();

include('../db.php');
include('../permissions.php');

header('Content-Type: application/json');

$permissionManager = initPermissions($pdo);

// Verifica se está logado
if (!$permissionManager->user) {
    http_response_code(401);
    echo json_encode(['error' => 'Usuário não autenticado']);
    exit();
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    case 'list':
        if (!$permissionManager->hasPagePermission('clientes', 'view')) {
            http_response_code(403);
            echo json_encode(['error' => 'Sem permissão para visualizar clientes']);
            exit();
        }
        
        // Busca clientes...
        $stmt = $pdo->query("SELECT * FROM clientes");
        $clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'data' => $clientes,
            'permissions' => [
                'can_edit' => $permissionManager->hasPagePermission('clientes', 'edit'),
                'can_delete' => $permissionManager->hasPagePermission('clientes', 'delete'),
                'can_create' => $permissionManager->hasPagePermission('clientes', 'create')
            ]
        ]);
        break;
        
    case 'create':
        if (!$permissionManager->hasPagePermission('clientes', 'create')) {
            http_response_code(403);
            echo json_encode(['error' => 'Sem permissão para criar clientes']);
            exit();
        }
        
        // Cria cliente...
        break;
        
    case 'update':
        if (!$permissionManager->hasPagePermission('clientes', 'edit')) {
            http_response_code(403);
            echo json_encode(['error' => 'Sem permissão para editar clientes']);
            exit();
        }
        
        // Atualiza cliente...
        break;
        
    case 'delete':
        if (!$permissionManager->hasPagePermission('clientes', 'delete')) {
            http_response_code(403);
            echo json_encode(['error' => 'Sem permissão para excluir clientes']);
            exit();
        }
        
        // Exclui cliente...
        break;
        
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Ação inválida']);
}
*/

// =================================================================
// EXEMPLO 6: PÁGINA ESPECÍFICA PARA INVESTIDORES
// =================================================================

/*
// Arquivo: investimentos.php
session_start();

include('db.php');
include('permissions.php');

$permissionManager = initPermissions($pdo);

// Verifica se tem permissão para acessar investimentos
$permissionManager->requirePermission('investimentos', 'view');

// Para investidores, mostra apenas dados de investimento
if ($permissionManager->isInvestor()) {
    // Interface específica para investidores
    $investimentos = getInvestimentosDoUsuario($_SESSION['user']['id']);
} else {
    // Interface completa para outros usuários
    $investimentos = getTodosInvestimentos();
}
*/

// =================================================================
// EXEMPLO 7: MIDDLEWARE PARA VERIFICAÇÃO DE PERMISSÕES
// =================================================================

/*
// Arquivo: middleware/auth.php

function requirePermission($page, $action = 'view') {
    session_start();
    
    if (!isset($_SESSION['user'])) {
        header("Location: login.php");
        exit();
    }
    
    include('db.php');
    include('permissions.php');
    
    $permissionManager = new PermissionManager($pdo);
    
    if (!$permissionManager->hasPagePermission($page, $action)) {
        header("Location: access_denied.php");
        exit();
    }
    
    return $permissionManager;
}

// Uso do middleware:
// $permissionManager = requirePermission('clientes', 'edit');
*/

// =================================================================
// EXEMPLO 8: VERIFICAÇÃO DE PERMISSÕES EM JAVASCRIPT
// =================================================================
?>

<script>
// Exemplo de JavaScript para controlar interface baseado em permissões
document.addEventListener('DOMContentLoaded', function() {
    // Dados de permissão vindos do PHP
    const userPermissions = <?php echo json_encode([
        'permission_level' => $_SESSION['user']['permission'],
        'can_edit_clientes' => $permissionManager->hasPagePermission('clientes', 'edit'),
        'can_create_clientes' => $permissionManager->hasPagePermission('clientes', 'create'),
        'can_delete_clientes' => $permissionManager->hasPagePermission('clientes', 'delete'),
        'is_admin' => $permissionManager->isAdmin()
    ]); ?>;
    
    // Esconde/mostra elementos baseado nas permissões
    if (!userPermissions.can_edit_clientes) {
        document.querySelectorAll('.edit-button').forEach(btn => {
            btn.style.display = 'none';
        });
    }
    
    if (!userPermissions.can_delete_clientes) {
        document.querySelectorAll('.delete-button').forEach(btn => {
            btn.style.display = 'none';
        });
    }
    
    if (!userPermissions.can_create_clientes) {
        const createButton = document.getElementById('create-client-btn');
        if (createButton) {
            createButton.style.display = 'none';
        }
    }
    
    // Desabilita campos de formulário para usuários nível 1
    if (userPermissions.permission_level === 'Usuario_Nivel_1') {
        document.querySelectorAll('input, select, textarea').forEach(input => {
            if (!input.classList.contains('readonly-exception')) {
                input.readOnly = true;
                input.disabled = true;
            }
        });
        
        document.querySelectorAll('button[type="submit"]').forEach(btn => {
            btn.style.display = 'none';
        });
    }
});

// Função para verificar permissão antes de ações AJAX
function checkPermissionBeforeAction(action, page) {
    return fetch('api/check_permission.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            action: action,
            page: page
        })
    })
    .then(response => response.json())
    .then(data => {
        if (!data.allowed) {
            alert('Você não tem permissão para realizar esta ação.');
            return false;
        }
        return true;
    });
}

// Exemplo de uso da verificação de permissão
async function editClient(clientId) {
    const hasPermission = await checkPermissionBeforeAction('edit', 'clientes');
    if (hasPermission) {
        window.location.href = `editar_cliente.php?id=${clientId}`;
    }
}
</script>

<?php
// =================================================================
// EXEMPLO 9: ARQUIVO DE VERIFICAÇÃO DE PERMISSÕES PARA AJAX
// =================================================================

/*
// Arquivo: api/check_permission.php
session_start();

include('../db.php');
include('../permissions.php');

header('Content-Type: application/json');

if (!isset($_SESSION['user'])) {
    echo json_encode(['allowed' => false, 'message' => 'Usuário não autenticado']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';
$page = $input['page'] ?? '';

if (empty($action) || empty($page)) {
    echo json_encode(['allowed' => false, 'message' => 'Parâmetros inválidos']);
    exit();
}

$permissionManager = new PermissionManager($pdo);
$allowed = $permissionManager->hasPagePermission($page, $action);

echo json_encode([
    'allowed' => $allowed,
    'message' => $allowed ? 'Permissão concedida' : 'Permissão negada',
    'user_level' => $_SESSION['user']['permission']
]);
*/

// =================================================================
// EXEMPLO 10: TEMPLATE HEADER REUTILIZÁVEL
// =================================================================

/*
// Arquivo: includes/header.php
function renderHeader($pageTitle = "LicitaSis", $currentPage = "") {
    global $permissionManager;
    ?>
    <!DOCTYPE html>
    <html lang="pt-br">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?php echo $pageTitle; ?></title>
        <link rel="icon" href="../public_html/assets/images/logo_combraz.png" type="image/png">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
        <link rel="stylesheet" href="assets/css/style.css">
    </head>
    <body>
        <header>
            <a href="index.php">
                <img src="../public_html/assets/images/logo_combraz_licitasis.png" alt="Logo LicitaSis" class="logo">
            </a>
            
            <div class="user-info">
                <i class="fas fa-user"></i>
                <span><?php echo htmlspecialchars($_SESSION['user']['name']); ?></span>
                <span class="permission-badge">
                    <?php echo $permissionManager->getPermissionName($_SESSION['user']['permission']); ?>
                </span>
            </div>
        </header>

        <nav>
            <div class="nav-container">
                <?php echo $permissionManager->generateNavigationMenu(); ?>
            </div>
        </nav>
    <?php
}

// Uso:
// include('includes/header.php');
// renderHeader("Clientes - LicitaSis", "clientes");
*/

// =================================================================
// EXEMPLO 11: AUDITORIA DE AÇÕES
// =================================================================

/*
// Arquivo: includes/audit.php

function logUserAction($action, $table, $record_id = null, $details = null) {
    global $pdo;
    
    if (!isset($_SESSION['user'])) {
        return false;
    }
    
    $stmt = $pdo->prepare("
        INSERT INTO audit_log (user_id, user_name, action, table_name, record_id, details, ip_address, user_agent, created_at) 
        VALUES (:user_id, :user_name, :action, :table_name, :record_id, :details, :ip_address, :user_agent, NOW())
    ");
    
    return $stmt->execute([
        ':user_id' => $_SESSION['user']['id'],
        ':user_name' => $_SESSION['user']['name'],
        ':action' => $action,
        ':table_name' => $table,
        ':record_id' => $record_id,
        ':details' => $details ? json_encode($details) : null,
        ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        ':user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
    ]);
}

// Uso:
// logUserAction('CREATE', 'clientes', $cliente_id, ['nome' => $nome, 'email' => $email]);
// logUserAction('UPDATE', 'clientes', $cliente_id, ['campo_alterado' => 'email']);
// logUserAction('DELETE', 'clientes', $cliente_id);
// logUserAction('VIEW', 'clientes');
*/

// =================================================================
// EXEMPLO 12: CRIAÇÃO DA TABELA DE AUDITORIA
// =================================================================

/*
-- SQL para criar tabela de auditoria
CREATE TABLE IF NOT EXISTS audit_log (
    id INT(11) AUTO_INCREMENT PRIMARY KEY,
    user_id INT(11) NOT NULL,
    user_name VARCHAR(255) NOT NULL,
    action ENUM('CREATE', 'READ', 'UPDATE', 'DELETE', 'LOGIN', 'LOGOUT', 'ACCESS_DENIED') NOT NULL,
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
);
*/

// =================================================================
// RESUMO DE IMPLEMENTAÇÃO
// =================================================================

/*
PASSOS PARA IMPLEMENTAR O SISTEMA DE PERMISSÕES:

1. Execute o SQL de criação das tabelas (database_structure.sql)
2. Coloque o arquivo permissions.php no diretório do sistema
3. Atualize o arquivo login.php para usar o novo sistema
4. Em cada página, adicione no início:
   
   session_start();
   include('db.php');
   include('permissions.php');
   $permissionManager = initPermissions($pdo);
   $permissionManager->requirePermission('nome_da_pagina', 'acao');

5. Use verificações condicionais no HTML para mostrar/esconder elementos
6. Implemente auditoria onde necessário
7. Teste todas as permissões com diferentes tipos de usuário

NÍVEIS DE PERMISSÃO:
- Administrador: Acesso total
- Usuario_Nivel_1: Apenas visualização
- Usuario_Nivel_2: Consulta e edição (exceto usuários/funcionários)
- Investidor: Apenas investimentos

AÇÕES DISPONÍVEIS:
- view: Visualizar dados
- edit: Editar dados existentes
- create: Criar novos registros
- delete: Excluir registros
*/

?>

<!-- Fim dos exemplos -->