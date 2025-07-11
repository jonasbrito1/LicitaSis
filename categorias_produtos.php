<?php
session_start();

// Verifica se o usuário está logado
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

// Inclui o sistema de permissões e auditoria
include('db.php');
include('permissions.php');
include('includes/audit.php');

$permissionManager = initPermissions($pdo);

// Verifica se o usuário é administrador (corrigido)
if (!$_SESSION['user']['permission'] === 'Administrador') {
    header("Location: produtos.php");
    exit();
}

// Inicializa variáveis
$error = "";
$success = "";
$categorias = [];

// Processa adição/edição de categoria
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['add_categoria']) || isset($_POST['edit_categoria'])) {
        $nome = trim($_POST['nome']);
        $descricao = trim($_POST['descricao']);
        $id = isset($_POST['categoria_id']) ? (int)$_POST['categoria_id'] : null;

        try {
            if (empty($nome)) {
                throw new Exception("O nome da categoria é obrigatório.");
            }

            // Verifica se já existe uma categoria com este nome
            $stmt = $pdo->prepare("SELECT id FROM categorias WHERE nome = ? AND id != ?");
            $stmt->execute([$nome, $id ?? 0]);
            if ($stmt->rowCount() > 0) {
                throw new Exception("Já existe uma categoria com este nome.");
            }

            if ($id) {
                // Atualiza categoria existente
                $stmt = $pdo->prepare("UPDATE categorias SET nome = ?, descricao = ? WHERE id = ?");
                $stmt->execute([$nome, $descricao, $id]);
                $success = "Categoria atualizada com sucesso!";
                logUserAction('UPDATE', 'categorias', $id, ['nome' => $nome, 'descricao' => $descricao]);
            } else {
                // Insere nova categoria
                $stmt = $pdo->prepare("INSERT INTO categorias (nome, descricao) VALUES (?, ?)");
                $stmt->execute([$nome, $descricao]);
                $success = "Categoria criada com sucesso!";
                logUserAction('CREATE', 'categorias', $pdo->lastInsertId(), ['nome' => $nome, 'descricao' => $descricao]);
            }

        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
    
    // Processa exclusão de categoria
    if (isset($_POST['delete_categoria'])) {
        $id = (int)$_POST['categoria_id'];
        
        try {
            // Verifica se existem produtos usando esta categoria
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM produtos WHERE categoria = (SELECT nome FROM categorias WHERE id = ?)");
            $stmt->execute([$id]);
            if ($stmt->fetchColumn() > 0) {
                throw new Exception("Não é possível excluir esta categoria pois existem produtos vinculados a ela.");
            }

            $stmt = $pdo->prepare("DELETE FROM categorias WHERE id = ?");
            $stmt->execute([$id]);
            $success = "Categoria excluída com sucesso!";
            logUserAction('DELETE', 'categorias', $id);

        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

// Busca todas as categorias
try {
    $stmt = $pdo->query("
        SELECT c.*,
               COUNT(p.id) as total_produtos,
               COALESCE(SUM(p.preco_unitario), 0) as valor_total,
               MIN(p.created_at) as primeiro_produto,
               MAX(p.created_at) as ultimo_produto
        FROM categorias c
        LEFT JOIN produtos p ON p.categoria_id = c.id
        GROUP BY c.id
        ORDER BY c.nome ASC
    ");
    $categorias = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Erro ao carregar categorias: " . $e->getMessage();
}

// Inclui o template de header
include('includes/header_template.php');
renderHeader("Categorias de Produtos - LicitaSis", "produtos");
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Categorias de Produtos - LicitaSis</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* Reaproveita as variáveis CSS do produtos.php */
        :root {
            --primary-color: #2D893E;
            --primary-light: #9DCEAC;
            --secondary-color: #00bfae;
            --success-color: #28a745;
            --warning-color: #ffc107;
            --danger-color: #dc3545;
            --light-gray: #f8f9fa;
            --medium-gray: #6c757d;
            --dark-gray: #343a40;
            --border-color: #dee2e6;
            --shadow: 0 4px 12px rgba(0,0,0,0.1);
            --radius: 12px;
            --transition: all 0.3s ease;
        }

        .container {
            max-width: 1200px;
            margin: 2rem auto;
            padding: 2rem;
            background: white;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
        }

        .page-header {
            text-align: center;
            margin-bottom: 2rem;
        }

        .page-header h2 {
            color: var(--primary-color);
            font-size: 2rem;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        /* Grid de categorias */
        .categorias-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1.5rem;
            margin: 2rem 0;
        }

        .categoria-card {
            background: white;
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            padding: 1.5rem;
            transition: var(--transition);
        }

        .categoria-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow);
        }

        .categoria-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .categoria-title {
            font-size: 1.2rem;
            color: var(--primary-color);
            font-weight: 600;
        }

        .categoria-actions {
            display: flex;
            gap: 0.5rem;
        }

        .categoria-stats {
            display: flex;
            gap: 1rem;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid var(--border-color);
        }

        .stat-item {
            flex: 1;
            text-align: center;
        }

        .stat-value {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--primary-color);
        }

        .stat-label {
            font-size: 0.9rem;
            color: var(--medium-gray);
        }

        /* Formulário */
        .form-container {
            max-width: 600px;
            margin: 2rem auto;
            padding: 2rem;
            background: var(--light-gray);
            border-radius: var(--radius);
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--dark-gray);
            font-weight: 600;
        }

        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            transition: var(--transition);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(45, 137, 62, 0.2);
        }

        /* Botões */
        .btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: var(--radius);
            cursor: pointer;
            transition: var(--transition);
            font-size: 0.9rem;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-danger {
            background: var(--danger-color);
            color: white;
        }

        .btn-edit {
            background: var(--warning-color);
            color: var(--dark-gray);
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }

        /* Responsividade */
        @media (max-width: 768px) {
            .container {
                margin: 1rem;
                padding: 1rem;
            }

            .categorias-grid {
                grid-template-columns: 1fr;
            }

            .categoria-stats {
                flex-direction: column;
                gap: 0.5rem;
            }

            .stat-item {
                padding: 0.5rem 0;
            }
        }

        /* Animações */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .categoria-card {
            animation: fadeIn 0.5s ease forwards;
        }
    </style>
</head>
<body>

<div class="container">
    <div class="page-header">
        <h2>
            <i class="fas fa-tags"></i>
            Categorias de Produtos
        </h2>
        <p>Gerencie as categorias dos produtos do sistema</p>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle"></i>
            <?php echo $error; ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i>
            <?php echo $success; ?>
        </div>
    <?php endif; ?>

    <!-- Formulário de categoria -->
    <div class="form-container">
        <form id="categoriaForm" method="POST">
            <input type="hidden" name="categoria_id" id="categoria_id">
            
            <div class="form-group">
                <label for="nome">Nome da Categoria</label>
                <input type="text" class="form-control" id="nome" name="nome" required>
            </div>

            <div class="form-group">
                <label for="descricao">Descrição</label>
                <textarea class="form-control" id="descricao" name="descricao" rows="3"></textarea>
            </div>

            <div class="form-buttons">
                <button type="submit" name="add_categoria" class="btn btn-primary" id="submitBtn">
                    <i class="fas fa-plus"></i> Adicionar Categoria
                </button>
            </div>
        </form>
    </div>

    <!-- Lista de categorias -->
    <div class="categorias-grid">
        <?php foreach ($categorias as $categoria): ?>
            <div class="categoria-card">
                <div class="categoria-header">
                    <span class="categoria-title"><?php echo htmlspecialchars($categoria['nome']); ?></span>
                    <div class="categoria-actions">
                        <button class="btn btn-edit" onclick="editarCategoria(<?php echo htmlspecialchars(json_encode($categoria)); ?>)">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button class="btn btn-danger" onclick="excluirCategoria(<?php echo $categoria['id']; ?>, '<?php echo htmlspecialchars($categoria['nome']); ?>')">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </div>
                
                <p class="categoria-desc">
                    <?php echo htmlspecialchars($categoria['descricao'] ?: 'Sem descrição'); ?>
                </p>

                <div class="categoria-stats">
                    <div class="stat-item">
                        <div class="stat-value"><?php echo $categoria['total_produtos']; ?></div>
                        <div class="stat-label">Produtos</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value">
                            R$ <?php echo number_format($categoria['valor_total'] ?? 0, 2, ',', '.'); ?>
                        </div>
                        <div class="stat-label">Valor Total</div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<script>
    // Função para editar categoria
    function editarCategoria(categoria) {
        document.getElementById('categoria_id').value = categoria.id;
        document.getElementById('nome').value = categoria.nome;
        document.getElementById('descricao').value = categoria.descricao;
        
        const submitBtn = document.getElementById('submitBtn');
        submitBtn.innerHTML = '<i class="fas fa-save"></i> Atualizar Categoria';
        submitBtn.name = 'edit_categoria';
        
        // Scroll suave até o formulário
        document.querySelector('.form-container').scrollIntoView({ behavior: 'smooth' });
    }

    // Função para excluir categoria
    function excluirCategoria(id, nome) {
        if (confirm(`Deseja realmente excluir a categoria "${nome}"?`)) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="categoria_id" value="${id}">
                <input type="hidden" name="delete_categoria" value="1">
            `;
            document.body.appendChild(form);
            form.submit();
        }
    }

    // Reseta formulário quando adicionar/atualizar
    document.getElementById('categoriaForm').addEventListener('submit', function() {
        localStorage.setItem('success', 'true');
    });

    // Mostra mensagem de sucesso
    window.onload = function() {
        if (localStorage.getItem('success')) {
            localStorage.removeItem('success');
            // Scroll para o topo se houver mensagem
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
    };
</script>

</body>
</html>