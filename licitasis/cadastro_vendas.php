<?php 
session_start();

// Verifica se o usuário está logado
if (!isset($_SESSION['user'])) {
    header("Location: login.php"); // Se não estiver logado, redireciona para o login
    exit();
}

// Definir a variável $isAdmin com base na permissão do usuário
$isAdmin = isset($_SESSION['user']['permission']) && $_SESSION['user']['permission'] === 'Administrador';

$error = "";
$success = false;

// Conexão com o banco de dados
require_once('db.php');

// Buscar empenhos relacionados ao cliente (requisição AJAX)
if (isset($_GET['cliente_uasg'])) {
    $cliente_uasg = $_GET['cliente_uasg'];
    
    try {
        // Consulta SQL específica para buscar empenhos relacionados a esta UASG
        $sql = "SELECT id, numero FROM empenhos WHERE cliente_uasg = :cliente_uasg ORDER BY numero";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':cliente_uasg', $cliente_uasg, PDO::PARAM_STR);
        $stmt->execute();
        
        $empenhos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if ($empenhos) {
            echo json_encode($empenhos);
        } else {
            echo json_encode(['error' => 'Nenhum empenho encontrado para esta UASG']);
        }
    } catch (PDOException $e) {
        echo json_encode(['error' => 'Erro ao buscar empenhos: ' . $e->getMessage()]);
    }
    exit();
}

// Buscar detalhes do empenho selecionado e os produtos relacionados
if (isset($_GET['empenho_id'])) {
    $empenho_id = $_GET['empenho_id'];

    try {
        // Consulta SQL para buscar os detalhes do empenho
        $sql = "SELECT id, numero, cliente_uasg, cliente_nome, valor_total, valor_total_empenho, observacao, pregao
                FROM empenhos 
                WHERE id = :empenho_id";

        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':empenho_id', $empenho_id, PDO::PARAM_INT);
        $stmt->execute();

        $empenho = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($empenho) {
            // Buscar os produtos relacionados a este empenho
            $sql_produtos = "SELECT p.id, p.nome, p.codigo, ep.quantidade, ep.valor_unitario, ep.valor_total, 
                            ep.descricao_produto, p.preco_unitario, ep.produto_id
                            FROM empenho_produtos ep
                            JOIN produtos p ON ep.produto_id = p.id
                            WHERE ep.empenho_id = :empenho_id";

            $stmt_produtos = $pdo->prepare($sql_produtos);
            $stmt_produtos->bindParam(':empenho_id', $empenho_id, PDO::PARAM_INT);
            $stmt_produtos->execute();

            $produtos = $stmt_produtos->fetchAll(PDO::FETCH_ASSOC);

            // Adiciona os produtos ao empenho
            $empenho['produtos_detalhes'] = $produtos;

            echo json_encode($empenho); // Retorna os detalhes do empenho com os produtos
        } else {
            echo json_encode(['error' => 'Empenho não encontrado']);
        }
    } catch (PDOException $e) {
        echo json_encode(['error' => 'Erro ao buscar detalhes do empenho: ' . $e->getMessage()]);
    }
    exit();
}

// Funções adicionais para buscar produtos, transportadoras e clientes
function buscarProdutos() {
    global $pdo;
    $sql = "SELECT id, nome, preco_unitario, codigo FROM produtos ORDER BY nome";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function buscarTransportadoras() {
    global $pdo;
    $sql = "SELECT id, nome FROM transportadora ORDER BY nome";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function buscarEmpenhos() {
    global $pdo;
    $sql = "SELECT id, numero FROM empenhos ORDER BY numero";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function buscarClientes() {
    global $pdo;
    $sql = "SELECT id, nome_orgaos, uasg FROM clientes ORDER BY nome_orgaos";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Função para buscar produto por ID
function buscarProdutoPorId($produto_id) {
    global $pdo;
    $sql = "SELECT * FROM produtos WHERE id = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':id', $produto_id, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

$clientes = buscarClientes();

// Função para verificar duplicados
function verificarDuplicado($empenho_id) {
    global $pdo;
    $sql = "SELECT COUNT(*) FROM vendas WHERE empenho_id = :empenho_id";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':empenho_id', $empenho_id, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchColumn() > 0;
}

// Código para processar o formulário quando submetido
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        // Verificar campos obrigatórios
if (empty($_POST['cliente_uasg']) || empty($_POST['cliente']) || empty($_POST['empenho']) || 
    empty($_POST['transportadora']) || empty($_POST['data']) || empty($_POST['numero'])) {
    throw new Exception("Todos os campos marcados são obrigatórios.");
}

        // Verificar se tem pelo menos um produto
        if (empty($_POST['produto']) || !is_array($_POST['produto']) || count($_POST['produto']) == 0) {
            throw new Exception("É necessário adicionar pelo menos um produto.");
        }

        // Obter dados do formulário
        $numero = $_POST['numero'];
        $cliente_uasg = $_POST['cliente_uasg'];
        $cliente = $_POST['cliente'];
        $empenho_id = $_POST['empenho'];
        $transportadora = $_POST['transportadora'];
        
        // Formatar e validar datas
$data_venda = null;
if (!empty($_POST['data'])) {
    $data_parts = explode('/', $_POST['data']);
    if (count($data_parts) == 3) {
        $dia = (int)$data_parts[0];
        $mes = (int)$data_parts[1];
        $ano = (int)$data_parts[2];
        
        // Validar se é uma data válida usando checkdate()
        if (checkdate($mes, $dia, $ano)) {
            $data_venda = sprintf('%04d-%02d-%02d', $ano, $mes, $dia); // Formato MySQL YYYY-MM-DD
        } else {
            throw new Exception("Data de venda inválida. Por favor, verifique o formato DD/MM/AAAA.");
        }
    } else {
        throw new Exception("Formato de data incorreto. Use o formato DD/MM/AAAA.");
    }
}

// O mesmo para data de vencimento
$data_vencimento = null;
if (!empty($_POST['data_vencimento'])) {
    $venc_parts = explode('/', $_POST['data_vencimento']);
    if (count($venc_parts) == 3) {
        $dia = (int)$venc_parts[0];
        $mes = (int)$venc_parts[1];
        $ano = (int)$venc_parts[2];
        
        // Validar se é uma data válida
        if (checkdate($mes, $dia, $ano)) {
            $data_vencimento = sprintf('%04d-%02d-%02d', $ano, $mes, $dia);
        } else {
            throw new Exception("Data de vencimento inválida. Por favor, verifique o formato DD/MM/AAAA.");
        }
    } else {
        throw new Exception("Formato de data de vencimento incorreto. Use o formato DD/MM/AAAA.");
    }
}
        
        $valor_total = $_POST['valor_total_venda'] ?? 0;
        $observacao = $_POST['observacao'] ?? '';
        $pregao = $_POST['pregao'] ?? '';
        $classificacao = $_POST['classificacao'] ?? 'Pendente';
        $nf = $_POST['numero'] ?? '';

        // Obter dados dos produtos
        $produtos = $_POST['produto'];
        $quantidades = $_POST['quantidade'];
        $valores_unitarios = $_POST['valor_unitario'];
        $valores_totais = $_POST['valor_total'];
        $observacoes = isset($_POST['observacao_produto']) ? $_POST['observacao_produto'] : array_fill(0, count($produtos), '');
        
        // Iniciar transação
        $pdo->beginTransaction();
        
        // Preparar campos adicionais obrigatórios (produto, codigo_produto, valor_unitario, pregão)
        // Obter informações do primeiro produto para preencher campos obrigatórios
        $primeiro_produto_id = $produtos[0];
        $produto_info = buscarProdutoPorId($primeiro_produto_id);
        
        // Construindo strings para campos obrigatórios
        $produto_nome = $produto_info['nome'] ?? "Produto";
        $codigo_produto = $produto_info['codigo'] ?? "Código";
        $valor_unitario_produto = str_replace(',', '.', $valores_unitarios[0]);
        
       // Inserir a venda na tabela vendas incluindo apenas os campos necessários
$sql = "INSERT INTO vendas (numero, cliente_uasg, cliente, transportadora, data, data_vencimento, 
                          valor_total, observacao, pregao, classificacao, nf, empenho_id, status_pagamento) 
        VALUES (:numero, :cliente_uasg, :cliente, :transportadora, :data, :data_vencimento, 
               :valor_total, :observacao, :pregao, :classificacao, :nf, :empenho_id, :status_pagamento)";

// Preparando a consulta SQL
$stmt = $pdo->prepare($sql);

// Adicionar os parâmetros
$stmt->bindParam(':numero', $numero);
$stmt->bindParam(':cliente_uasg', $cliente_uasg);
$stmt->bindParam(':cliente', $cliente);
$stmt->bindParam(':transportadora', $transportadora);
$stmt->bindParam(':data', $data_venda);
$stmt->bindParam(':data_vencimento', $data_vencimento);
$stmt->bindParam(':valor_total', $valor_total);
$stmt->bindParam(':observacao', $observacao);
$stmt->bindParam(':pregao', $pregao);
$stmt->bindParam(':classificacao', $classificacao);
$stmt->bindParam(':nf', $nf);
$stmt->bindParam(':empenho_id', $empenho_id);

// Definir o status de pagamento como "Não Recebido" por padrão
$status_pagamento = 'Não Recebido';
$stmt->bindParam(':status_pagamento', $status_pagamento);

// Executar a consulta
$stmt->execute();
        
        // Obter o ID da venda recém-inserida
        $venda_id = $pdo->lastInsertId();
        
         $sql_produto = "INSERT INTO venda_produtos (venda_id, produto_id, quantidade, valor_unitario, valor_total, observacao) 
                       VALUES (:venda_id, :produto_id, :quantidade, :valor_unitario, :valor_total, :observacao)";
        $stmt_produto = $pdo->prepare($sql_produto);
        
        for ($i = 0; $i < count($produtos); $i++) {
            if (empty($produtos[$i])) continue; // Pular produtos não selecionados
            
            $produto_id = $produtos[$i];
            $quantidade = $quantidades[$i];
            $valor_unitario = str_replace(',', '.', $valores_unitarios[$i]);
            $valor_total = str_replace(',', '.', $valores_totais[$i]);
            $obs_produto = $observacoes[$i] ?? '';
            
            $stmt_produto->bindParam(':venda_id', $venda_id);
            $stmt_produto->bindParam(':produto_id', $produto_id);
            $stmt_produto->bindParam(':quantidade', $quantidade);
            $stmt_produto->bindParam(':valor_unitario', $valor_unitario);
            $stmt_produto->bindParam(':valor_total', $valor_total);
            $stmt_produto->bindParam(':observacao', $obs_produto);
            
            $stmt_produto->execute();
        }
        
        // Finalizar transação
        $pdo->commit();
        
        $success = true;
        

    } catch (Exception $e) {
        // Em caso de erro, reverter a transação
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
    }
}

$produtos = buscarProdutos();
$transportadoras = buscarTransportadoras();
$empenhos = buscarEmpenhos();
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cadastro de Vendas</title>
    <link rel="icon" href="../public_html/assets/images/logo_combraz.png" type="image/png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* Reset e variáveis CSS */
        :root {
            --primary-color: #2D893E;
            --primary-light: #9DCEAC;
            --primary-dark: #1e6e2d;
            --secondary-color: #00bfae;
            --secondary-dark: #009d8f;
            --danger-color: #dc3545;
            --success-color: #28a745;
            --warning-color: #ffc107;
            --light-gray: #f8f9fa;
            --medium-gray: #6c757d;
            --dark-gray: #343a40;
            --border-color: #dee2e6;
            --shadow: 0 4px 12px rgba(0,0,0,0.1);
            --shadow-hover: 0 6px 15px rgba(0,0,0,0.15);
            --radius: 12px;
            --radius-sm: 8px;
            --transition: all 0.3s ease;
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
            color: #2D893E;
            line-height: 1.6;
        }

        /* Header */
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

        /* Navigation */
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
        .container {
            max-width: 900px;
            margin: 2.5rem auto;
            padding: 2.5rem;
            background: white;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
        }

        h2 {
            text-align: center;
            margin-bottom: 2rem;
            font-size: 1.8rem;
        }

        label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: bold;
        }

        input, select, textarea {
            width: 100%;
            padding: 0.875rem;
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            margin-bottom: 1rem;
        }

        button {
            padding: 0.875rem 1.5rem;
            background: var(--secondary-color);
            color: white;
            border: none;
            border-radius: var(--radius);
            cursor: pointer;
            transition: all 0.3s;
        }

        button:hover {
            background: darken(var(--secondary-color), 10%);
        }

        /* Responsividade */
        @media (max-width: 768px) {
            .container {
                padding: 1.5rem;
                margin: 1.5rem auto;
            }

            h2 {
                font-size: 1.5rem;
            }

            button {
                width: 100%;
            }
        }

        @media (max-width: 480px) {
            .logo {
                max-width: 120px;
            }

            .container {
                padding: 1rem;
                margin: 1rem auto;
            }

            h2 {
                font-size: 1.3rem;
            }
        }
    </style>
</head>
<body>

<header>
    <a href="index.php">
        <img src="../public_html/assets/images/logo_combraz_licitasis.png" alt="Logo LicitaSis" class="logo">
    </a>
</header>

<nav>
    <div class="dropdown">
        <a href="clientes.php">Clientes</a>
        <div class="dropdown-content">
            <a href="cadastrar_clientes.php">Inserir Clientes</a>
            <a href="consultar_clientes.php">Consultar Clientes</a>
        </div>
    </div>
    <div class="dropdown">
        <a href="produtos.php">Produtos</a>
        <div class="dropdown-content">
            <a href="cadastro_produto.php">Inserir Produto</a>
            <a href="consulta_produto.php">Consultar Produtos</a>
        </div>
    </div>
    <div class="dropdown">
        <a href="empenhos.php">Empenhos</a>
        <div class="dropdown-content">
            <a href="cadastro_empenho.php">Inserir Empenho</a>
            <a href="consulta_empenho.php">Consultar Empenho</a>
        </div>
    </div>
    <div class="dropdown">
        <a href="financeiro.php">Financeiro</a>
        <div class="dropdown-content">
            <a href="contas_a_receber.php">Contas a Receber</a>
            <a href="contas_recebidas_geral.php">Contas Recebidas</a>
            <a href="contas_a_pagar.php">Contas a Pagar</a>
            <a href="contas_pagas.php">Contas Pagas</a>
            <a href="caixa.php">Caixa</a>
        </div>
    </div>
    <div class="dropdown">
        <a href="transportadoras.php">Transportadoras</a>
        <div class="dropdown-content">
            <a href="cadastro_transportadoras.php">Inserir Transportadora</a>
            <a href="consulta_transportadoras.php">Consultar Transportadora</a>
        </div>
    </div>
    <div class="dropdown">
        <a href="fornecedores.php">Fornecedores</a>
        <div class="dropdown-content">
            <a href="cadastro_fornecedores.php">Inserir Fornecedor</a>
            <a href="consulta_fornecedores.php">Consultar Fornecedor</a>
        </div>
    </div>
    <div class="dropdown">
        <a href="vendas.php">Vendas</a>
        <div class="dropdown-content">
            <a href="cadastro_vendas.php">Inserir Venda</a>
            <a href="consulta_vendas.php">Consultar Venda</a>
        </div>
    </div>
    <div class="dropdown">
        <a href="compras.php">Compras</a>
        <div class="dropdown-content">
            <a href="cadastro_compras.php">Inserir Compras</a>
            <a href="consulta_compras.php">Consultar Compras</a>
        </div>
    </div>

    <?php if ($isAdmin): ?>
        <div class="dropdown">
            <a href="usuario.php">Usuários</a>
                <div class="dropdown-content">
                    <a href="signup.php">Inserir Novo Usuário</a>
                    <a href="consulta_usuario.php">Consultar Usuário</a>
            </div>
        </div>
    <?php endif; ?>

    <!-- Exibe o link para o cadastro de funcionários apenas para administradores -->
    <?php if ($isAdmin): ?>
        <div class="dropdown">
            <a href="funcionarios.php">Funcionários</a>
                <div class="dropdown-content">
                    <a href="cadastro_funcionario.php">Inserir Novo Funcionário</a>
                    <a href="consulta_funcionario.php">Consultar Funcionário</a>
            </div>
        </div> 
    <?php endif; ?>
</nav>

<div class="container">
    <h2>Cadastro de Vendas</h2>

    <?php if ($error) { echo "<p class='error' style='color: red; text-align: center;'>$error</p>"; } ?>
    <?php if ($success) { echo "<p class='success' style='color: green; text-align: center;'>Venda cadastrada com sucesso!</p>"; } ?>

    <form action="cadastro_vendas.php" method="POST" enctype="multipart/form-data">

        <label for="cliente_uasg">UASG:</label>
        <input type="text" id="cliente_uasg" name="cliente_uasg" placeholder="Digite a UASG" list="uasg-list">
        <datalist id="uasg-list"></datalist>

        <label for="cliente">Nome do Cliente:</label>
        <input type="text" id="cliente" name="cliente" placeholder="Nome do Cliente" readonly> 

        <label for="empenho">Empenho:</label>
        <select name="empenho" id="empenho">
            <option value="">Selecione o Empenho</option>
            <?php foreach ($empenhos as $empenho): ?>
                <option value="<?php echo $empenho['id']; ?>"><?php echo $empenho['numero']; ?></option>
            <?php endforeach; ?>
        </select>

        <label for="numero">NF:</label>
        <input type="text" id="numero" name="numero" required>

        <div id="produtos-container">
            <!-- Os campos de produtos serão adicionados aqui dinamicamente -->
        </div>

        <button type="button" id="addProdutoBtn">+ Adicionar Produto</button>

        <label for="transportadora">Transportadora:</label>
        <select name="transportadora" id="transportadora">
            <option value="">Selecione a Transportadora</option>
            <?php foreach ($transportadoras as $transportadora): ?>
                <option value="<?php echo $transportadora['id']; ?>"><?php echo $transportadora['nome']; ?></option>
            <?php endforeach; ?>
        </select>

        <label for="data">Data de Venda:</label>
        <input type="text" id="data" name="data" placeholder="DD/MM/AAAA" oninput="formatarData(this)" required>

        <label for="valor_total_venda">Valor Total da Venda:</label>
        <input type="text" id="valor_total_venda" name="valor_total_venda" readonly>

        <label for="data_vencimento">Data de Vencimento:</label>
        <input type="text" id="data_vencimento" name="data_vencimento" placeholder="DD/MM/AAAA" oninput="formatarData(this)" required>

        <label for="observacao">Observação:</label>
        <textarea id="observacao" name="observacao"></textarea>

        <label for="pregao">Pregão:</label>
        <input type="text" id="pregao" name="pregao">

        <label for="classificacao">Classificação:</label>
        <select name="classificacao" id="classificacao">
            <option value="Faturada">Faturada</option>
            <option value="Comprada">Comprada</option>
            <option value="Entregue">Entregue</option>
            <option value="Liquidada">Liquidada</option>
            <option value="Pendente" selected>Pendente</option>
            <option value="Devolução">Devolução</option>
        </select>

        <div class="btn-container">
            <button type="submit">Cadastrar Venda</button>
        </div>
    </form>
</div>

<script>

// 1) Autocomplete de UASG via fetch_uasg.php
document.getElementById('cliente_uasg')
    .addEventListener('input', function onInputUASG(e) {
        const q = e.target.value.trim();
        if (q.length < 2) return; // Aguarda ao menos 2 caracteres

        fetch('fetch_uasg.php?query=' + encodeURIComponent(q))
            .then(res => res.json())
            .then(list => {
                const dl = document.getElementById('uasg-list');
                dl.innerHTML = '';
                list.forEach(c => {
                    const opt = document.createElement('option');
                    opt.value = c.uasg;
                    opt.text = `${c.uasg} – ${c.nome_orgaos}`;
                    dl.appendChild(opt);
                });
            })
            .catch(console.error);
    });

// 2) Ao escolher (change) a UASG, busca cliente e empenhos
document.getElementById('cliente_uasg')
    .addEventListener('change', function onChangeUASG(e) {
        const uasg = e.target.value.trim();
        if (!uasg) return;

        // 2a) Busca o nome completo do cliente
        fetch('get_cliente.php?uasg=' + encodeURIComponent(uasg))
            .then(res => res.json())
            .then(c => {
                if (c.nome_orgaos) {
                    document.getElementById('cliente').value = c.nome_orgaos;
                } else {
                    document.getElementById('cliente').value = '';
                    alert('Cliente não encontrado');
                }
            })
            .catch(console.error);

        // 2b) Busca os empenhos relacionados
        fetch('cadastro_vendas.php?cliente_uasg=' + encodeURIComponent(uasg))
            .then(res => res.json())
            .then(arr => {
                const sel = document.getElementById('empenho');
                sel.innerHTML = '<option value="">Selecione o Empenho</option>';
                if (arr.error) {
                    console.warn(arr.error);
                } else {
                    arr.forEach(e => {
                        const o = document.createElement('option');
                        o.value = e.id;
                        o.text = e.numero;
                        sel.appendChild(o);
                    });
                }
            })
            .catch(console.error);
    });

// Função para formatar e validar a data no formato dd/mm/aaaa
function formatarData(input) {
    let valor = input.value.replace(/\D/g, ''); // Remove todos os caracteres não numéricos
    
    if (valor.length <= 2) {
        input.value = valor;
    } else if (valor.length <= 4) {
        let dia = valor.substring(0, 2);
        if (parseInt(dia) > 31) dia = "31";
        let mes = valor.substring(2, 4);
        if (parseInt(mes) > 12) mes = "12";
        input.value = dia + "/" + mes;
    } else if (valor.length <= 8) {
        let dia = valor.substring(0, 2);
        if (parseInt(dia) > 31) dia = "31";
        let mes = valor.substring(2, 4);
        if (parseInt(mes) > 12) mes = "12";
        let ano = valor.substring(4, 8);
        input.value = dia + "/" + mes + "/" + ano;
        
        // Validação básica de data
        const dataObj = new Date(ano, mes-1, dia);
        if (dataObj.getDate() != dia || dataObj.getMonth() != mes-1 || dataObj.getFullYear() != ano) {
            input.setCustomValidity("Data inválida");
        } else {
            input.setCustomValidity("");
        }
    }
}

// Função para adicionar novos campos de produto
document.getElementById('addProdutoBtn').addEventListener('click', function() {
    var container = document.getElementById('produtos-container');

    var newProduto = document.createElement('div');
    newProduto.classList.add('produto');
    newProduto.innerHTML = `
        <label for="produto">Produto:</label>
        <select name="produto[]" required onchange="atualizaValorUnitario(this)">
            <option value="">Selecione o Produto</option>
            <?php foreach ($produtos as $produto): ?>
                <option value="<?php echo $produto['id']; ?>" data-preco="<?php echo $produto['preco_unitario']; ?>"><?php echo $produto['nome']; ?></option>
            <?php endforeach; ?>
        </select>

        <label for="quantidade">Quantidade:</label>
        <input type="number" name="quantidade[]" min="1" value="1" required oninput="calculaTotalProduto(this)">

        <label for="valor_unitario">Valor Unitário:</label>
        <input type="text" name="valor_unitario[]" min="0.01" step="0.01" value="0.00" oninput="calculaTotalProduto(this)">

        <label for="valor_total">Valor Total:</label>
        <input type="text" name="valor_total[]" readonly>

        <label for="observacao">Observação:</label>
        <input type="text" name="observacao[]">

        <button type="button" class="removeProdutoBtn" onclick="removerProduto(this)">Remover</button>
    `;
    
    container.appendChild(newProduto);
});

// Função para remover o campo do produto
function removerProduto(button) {
    // Remove o produto da interface
    var container = button.closest('div.produto');
    container.remove();

    // Atualiza o valor total da venda após a remoção
    atualizaTotalVenda();
}

// Atualiza o valor unitário, a observação e calcula o valor total quando o produto é selecionado
function atualizaValorUnitario(selectElement) {
    var precoUnitario = parseFloat(selectElement.selectedOptions[0].getAttribute('data-preco')) || 0;
    var produtoId = selectElement.value;
    var container = selectElement.closest('div');

    // Preenche o campo de valor unitário
    var valorUnitarioInput = container.querySelector('input[name="valor_unitario[]"]');
    valorUnitarioInput.value = precoUnitario.toFixed(2);

    // Atualiza o valor total do produto com base na quantidade
    var quantidadeInput = container.querySelector('input[name="quantidade[]"]');
    var valorTotalInput = container.querySelector('input[name="valor_total[]"]');
    var quantidade = parseFloat(quantidadeInput.value) || 0;
    var valorTotalProduto = quantidade * precoUnitario;
    valorTotalInput.value = valorTotalProduto.toFixed(2);

    // Busca a observação do produto
    buscarObservacaoProduto(produtoId, container);

    // Atualiza o valor total da venda
    atualizaTotalVenda();
}

// Função para buscar a observação do produto baseado no ID do produto
function buscarObservacaoProduto(produtoId, container) {
    fetch('get_observacao.php?produto_id=' + produtoId)
        .then(response => response.json())
        .then(data => {
            if (data.observacao) {
                // Preenche o campo de observação com a informação do produto
                var observacaoInput = container.querySelector('input[name="observacao[]"]');
                observacaoInput.value = data.observacao;
            } else {
                console.error("Observação não encontrada.");
            }
        })
        .catch(error => console.error("Erro ao buscar observação do produto:", error));
}

// Calcula o valor total do produto (quantidade x valor unitário) quando a quantidade ou valor unitário for alterado
function calculaTotalProduto(inputElement) {
    var container = inputElement.closest('div');
    var quantidadeInput = container.querySelector('input[name="quantidade[]"]');
    var valorUnitarioInput = container.querySelector('input[name="valor_unitario[]"]');
    var valorTotalInput = container.querySelector('input[name="valor_total[]"]');

    // Obtém os valores de quantidade e valor unitário, convertendo vírgulas para ponto se necessário
    var quantidade = parseFloat(quantidadeInput.value.replace(',', '.')) || 0;
    var precoUnitario = parseFloat(valorUnitarioInput.value.replace(',', '.')) || 0;

    // Calcula o valor total do produto
    var valorTotalProduto = quantidade * precoUnitario;
    valorTotalInput.value = valorTotalProduto.toFixed(2);

    // Atualiza o valor total da venda
    atualizaTotalVenda();
}

// Atualiza o valor total da venda somando os valores de todos os produtos
function atualizaTotalVenda() {
    var produtos = document.querySelectorAll('#produtos-container > div.produto');
    var totalVenda = 0;

    // Percorre todos os produtos e soma os valores totais
    produtos.forEach(function(produto) {
        var valorTotalInput = produto.querySelector('input[name="valor_total[]"]');
        var valorTotalProduto = parseFloat(valorTotalInput.value.replace(',', '.')) || 0;
        totalVenda += valorTotalProduto; // Soma o valor total do produto
    });

    // Atualiza o campo de valor total da venda
    document.getElementById('valor_total_venda').value = totalVenda.toFixed(2);
}

// Função para carregar dados do empenho e preencher os campos de produtos
document.getElementById('empenho').addEventListener('change', function() {
    const empenhoId = this.value;
    
    // Limpar os produtos existentes
    const produtosContainer = document.getElementById('produtos-container');
    produtosContainer.innerHTML = '';
    
    if (!empenhoId) {
        return; // Se nenhum empenho foi selecionado, não faz nada
    }
    
    // Buscar detalhes do empenho selecionado
    fetch('cadastro_vendas.php?empenho_id=' + empenhoId)
        .then(response => response.json())
        .then(empenho => {
            if (empenho.error) {
                console.warn(empenho.error);
                return;
            }
            
            console.log("Dados do empenho recebidos:", empenho);
            
            // Atualizar o campo de UASG com o valor do empenho
            document.getElementById('cliente_uasg').value = empenho.cliente_uasg;
            
            // Atualizar o nome do cliente
            document.getElementById('cliente').value = empenho.cliente_nome;
            
            // Preencher campos do empenho
            if (empenho.pregao) {
                document.getElementById('pregao').value = empenho.pregao;
            }
            
            if (empenho.observacao) {
                document.getElementById('observacao').value = empenho.observacao;
            }
            
            // Adicionar produtos do empenho ao container de produtos
            if (empenho.produtos_detalhes && empenho.produtos_detalhes.length > 0) {
                console.log("Produtos encontrados:", empenho.produtos_detalhes.length);
                
                // Para cada produto no empenho, adicionar um produto no formulário
                empenho.produtos_detalhes.forEach(produto => {
                    adicionarProdutoDoEmpenho(produto);
                });
                
                // Atualizar o valor total da venda
                atualizaTotalVenda();
            } else {
                console.log("Este empenho não possui produtos associados.");
                // Adicionar um produto vazio como fallback
                document.getElementById('addProdutoBtn').click();
            }
        })
        .catch(error => {
            console.error('Erro ao buscar detalhes do empenho:', error);
            // Em caso de erro, ainda permite adicionar produtos manualmente
            document.getElementById('addProdutoBtn').click();
        });
});

// Função para adicionar um produto do empenho ao formulário de vendas
function adicionarProdutoDoEmpenho(produto) {
    console.log("Adicionando produto:", produto);
    
    var container = document.getElementById('produtos-container');
    
    var newProduto = document.createElement('div');
    newProduto.classList.add('produto');
    
    // HTML do produto com os valores pré-preenchidos
    newProduto.innerHTML = `
        <label for="produto">Produto:</label>
        <select name="produto[]" required onchange="atualizaValorUnitario(this)">
            <option value="">Selecione o Produto</option>
            <?php foreach ($produtos as $prod): ?>
                <option value="<?php echo $prod['id']; ?>" 
                       data-preco="<?php echo $prod['preco_unitario']; ?>">
                       <?php echo $prod['nome']; ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="quantidade">Quantidade:</label>
        <input type="number" name="quantidade[]" min="1" value="${produto.quantidade}" required oninput="calculaTotalProduto(this)">

        <label for="valor_unitario">Valor Unitário:</label>
        <input type="text" name="valor_unitario[]" min="0.01" step="0.01" value="${parseFloat(produto.valor_unitario).toFixed(2)}" oninput="calculaTotalProduto(this)">

        <label for="valor_total">Valor Total:</label>
        <input type="text" name="valor_total[]" value="${parseFloat(produto.valor_total).toFixed(2)}" readonly>

        <label for="observacao">Observação:</label>
        <input type="text" name="observacao[]" value="${produto.descricao_produto || ''}">

        <button type="button" class="removeProdutoBtn" onclick="removerProduto(this)">Remover</button>
    `;
    
    container.appendChild(newProduto);
    
    // Selecionar o produto correto após adicionar ao DOM
    var selectElement = newProduto.querySelector('select[name="produto[]"]');
    for (var i = 0; i < selectElement.options.length; i++) {
        if (selectElement.options[i].value == produto.produto_id) {
            selectElement.selectedIndex = i;
            break;
        }
    }
    
    // Garantir que os valores totais sejam atualizados
    calculaTotalProduto(newProduto.querySelector('input[name="quantidade[]"]'));
}
</script>

</body>
</html>