<?php
session_start();

// Verifica se o usuário está logado
if (!isset($_SESSION['user'])) {
    header("Location: login.php"); // Se não estiver logado, redireciona para o login
    exit();
}

$error = "";
$success = "";

// Verifica se o formulário foi enviado para cadastrar o produto
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Recebe os dados do formulário
    $codigo = $_POST['codigo'];
    $nome = $_POST['nome'];
    $und = $_POST['und'];
    $fornecedor = $_POST['fornecedor'];
    $observacao = $_POST['observacao'];
    
    // Processamento da imagem
    if (isset($_FILES['imagem']) && $_FILES['imagem']['error'] == 0) {
        // Definindo as variáveis
        $imagemTmp = $_FILES['imagem']['tmp_name'];
        $imagemNome = $_FILES['imagem']['name'];
        $imagemExtensao = pathinfo($imagemNome, PATHINFO_EXTENSION);
        $imagemNovoNome = uniqid() . "." . $imagemExtensao;
        $imagemDest = "../uploads/" . $imagemNovoNome; // Diretório onde as imagens serão salvas

        // Verificar se a extensão da imagem é válida
        $extensoesPermitidas = ["jpg", "jpeg", "png", "gif"];
        if (!in_array(strtolower($imagemExtensao), $extensoesPermitidas)) {
            $error = "Somente imagens JPG, JPEG, PNG e GIF são permitidas.";
        } else {
            // Move a imagem para o diretório de uploads
            if (move_uploaded_file($imagemTmp, $imagemDest)) {
                // Inclui a conexão com o banco de dados
                include('../includes/db.php');

                // Realiza o cadastro do produto no banco de dados
                try {
                    $sql = "INSERT INTO produtos (codigo, nome, und, fornecedor, imagem, observacao) 
                            VALUES (:codigo, :nome, :und, :fornecedor, :imagem, :observacao)";
                    $stmt = $pdo->prepare($sql);
                    $stmt->bindParam(':codigo', $codigo);
                    $stmt->bindParam(':nome', $nome);
                    $stmt->bindParam(':und', $und);
                    $stmt->bindParam(':fornecedor', $fornecedor);
                    $stmt->bindParam(':imagem', $imagemDest); // Armazena o caminho da imagem
                    $stmt->bindParam(':observacao', $observacao);

                    if ($stmt->execute()) {
                        $success = "Produto cadastrado com sucesso!";
                    } else {
                        $error = "Erro ao cadastrar o produto.";
                    }
                } catch (PDOException $e) {
                    $error = "Erro na consulta: " . $e->getMessage();
                }
            } else {
                $error = "Erro ao fazer upload da imagem.";
            }
        }
    } else {
        $error = "Por favor, selecione uma imagem.";
    }
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cadastro de Produto - ComBraz</title>
    <style>
        /* Estilos gerais para a página */
        html, body {
            height: 100%;
            margin: 0;
            overflow: auto;
        }

        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
        }

        header {
            background-color: rgb(157, 206, 173);
            padding: 10px 0;
            text-align: center;
        }

        nav {
            background-color: #2D893E;
            padding: 10px;
            text-align: center;
        }

        nav a {
            color: white;
            padding: 10px 20px;
            text-decoration: none;
            font-size: 16px;
            margin: 0 10px;
            border-radius: 5px;
        }

        nav a:hover {
            background-color: #009d8f;
        }

        .logo {
            max-width: 180px;
            height: auto;
        }

        .container {
            max-width: 900px; /* Largura do container ajustada */
            margin: 50px auto;
            background-color:rgb(215, 212, 212);
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(240, 240, 240, 0.1);
            color: #2D893E;
            height: auto;
            overflow-y: auto;
        }

        h2 {
            text-align: center;
            color: #2D893E;
            margin-bottom: 20px;
            font-size: 1.8em;
        }

        label {
            display: block;
            font-weight: bold;
            margin-bottom: 5px;
        }

        input, textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: 16px;
            margin-bottom: 15px;
        }

        .btn-container {
            display: flex;
            justify-content: space-between;
            margin-top: 20px;
        }

        button {
            flex: 1;
            padding: 12px;
            background-color: #00bfae;
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            cursor: pointer;
            margin: 0 10px;
        }

        button:hover {
            background-color: #009d8f;
        }

        /* Responsividade */
        @media (max-width: 768px) {
            .btn-container {
                flex-direction: column;
            }

            button {
                margin: 10px 0;
                width: 100%;
            }
        }

        .content-footer {
            display: flex;
            justify-content: center;
            margin-top: 20px;
        }

        .content-footer a {
            background-color: #00bfae;
            color: white;
            padding: 12px 30px;
            text-decoration: none;
            font-size: 16px;
            border-radius: 5px;
            transition: background-color 0.3s ease;
            text-align: center;
        }

        .content-footer a:hover {
            background-color: #009d8f;
        }

        /* Imagem Preview */
        #imagemPreview {
            width: 100px;
            height: 100px;
            object-fit: cover;
            margin-top: 10px;
        }
    </style>
</head>
<body>

<header>
    <img src="../public_html/assets/images/logo_combraz_licitasis.png" alt="Logo LicitaSis" class="logo">
</header>

<nav>
    <a href="sistema.php">Início</a>
    <a href="clientes.php">Clientes</a>
    <a href="produtos.php">Produtos</a>
    <a href="empenhos.php">Empenhos</a>
    <a href="financeiro.php">Financeiro</a>
    <a href="transportadoras.php">Transportadoras</a>
    <a href="fornecedores.php">Fornecedores</a>
    <a href="vendas.php">Vendas</a>
</nav>

<div class="container">
    <h2>Cadastro de Produto</h2>

    <?php if ($error) { echo "<p class='error' style='color: red; text-align: center;'>$error</p>"; } ?>

    <form action="cadastro_produto.php" method="POST" enctype="multipart/form-data">
        <label for="codigo">Código:</label>
        <input type="text" id="codigo" name="codigo" required>

        <label for="nome">Nome do Produto:</label>
        <input type="text" id="nome" name="nome" required>

        <label for="und">Unidade de Medida:</label>
        <input type="text" id="und" name="und" required>

        <label for="fornecedor">Fornecedor:</label>
        <input type="text" id="fornecedor" name="fornecedor" required>

        <label for="imagem">Imagem:</label>
        <input type="file" id="imagem" name="imagem" accept="image/*" onchange="previewImagem(event)" required>

        <img id="imagemPreview" alt="Imagem Preview" style="display: none;" />

        <label for="observacao">Observação:</label>
        <textarea id="observacao" name="observacao"></textarea>

        <div class="btn-container">
            <button type="submit">Cadastrar Produto</button>
            <button type="reset">Limpar</button>
        </div>
    </form>

    <div class="content-footer">
        <a href="consulta_produto.php">Ir para página de Consulta de Produtos</a>
    </div>
</div>

<script>
    // Função para exibir o preview da imagem antes do envio
    function previewImagem(event) {
        var imagem = document.getElementById('imagemPreview');
        imagem.src = URL.createObjectURL(event.target.files[0]);
        imagem.style.display = 'block';  // Mostra a imagem após a seleção
    }
</script>

</body>
</html>
