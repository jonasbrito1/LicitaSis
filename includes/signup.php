<?php
session_start(); // Inicia a sessão

// Inclui os arquivos de conexão com o banco e funções auxiliares
include('../includes/db.php');  
include('../includes/function.php');

// Inicializa as variáveis de erro e sucesso
$error = "";
$success = "";
$createdEmail = "";
$createdPassword = "";
$createdPermission = "";

// Função para gerar senha aleatória
function generateRandomPassword() {
    $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    $password = '';
    for ($i = 0; $i < 12; $i++) {
        $randomIndex = rand(0, strlen($characters) - 1);
        $password .= $characters[$randomIndex];
    }
    return $password;
}

// Verifica se o formulário foi enviado
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $permission = trim($_POST['permission']);

    // Verifica se os campos não estão vazios
    if (empty($name) || empty($email) || empty($permission)) {
        $error = "Todos os campos são obrigatórios!";
    } 
    // Verifica se a permissão selecionada é válida
    elseif (!in_array($permission, ['Administrador', 'Financeiro', 'Faturamento', 'Acompanhamento'])) {
        $error = "Permissão inválida!";
    } 
    // Verifica se o e-mail já está registrado
    else {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
        $stmt->bindParam(':email', $email);
        $stmt->execute();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            $error = "Este e-mail já está registrado!";
        } else {
            // Gera uma senha aleatória
            $generatedPassword = generateRandomPassword();
            // Hash da senha antes de armazenar
            $hashedPassword = password_hash($generatedPassword, PASSWORD_BCRYPT);

            // Insere o novo usuário no banco de dados
            $sql = "INSERT INTO users (name, email, password, permission) VALUES (:name, :email, :password, :permission)";
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(':name', $name);
            $stmt->bindParam(':email', $email);
            $stmt->bindParam(':password', $hashedPassword);
            $stmt->bindParam(':permission', $permission);

            if ($stmt->execute()) {
                $success = "Cadastro realizado com sucesso!";
                $createdEmail = $email;
                $createdPassword = $generatedPassword;
                $createdPermission = $permission;
            } else {
                $error = "Erro ao realizar o cadastro!";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cadastro de usuários</title>

    <!-- Estilo CSS -->
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
            margin: 0;
            padding: 0;
        }

        header {
            background-color: rgb(157, 206, 173);
            padding: 10px 0;
            text-align: center;
            color: white;
            width: 100%;
            box-sizing: border-box;
        }

        .logo {
            max-width: 200px;
            height: auto;
        }

        .container {
            max-width: 500px;
            margin: 50px auto;
            background-color: #D9D9D9;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            color: #2D893E;
            box-sizing: border-box;
        }

        h2 {
            text-align: center;
            color: #2D893E;
            margin-bottom: 30px;
        }

        label {
            display: block;
            margin-bottom: 10px;
            font-weight: bold;
        }

        input, select {
            width: 100%;
            padding: 12px;
            margin-bottom: 20px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 16px;
            box-sizing: border-box;
        }

        button {
            width: 100%;
            padding: 12px;
            background-color: #00bfae;
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            cursor: pointer;
        }

        button:hover {
            background-color: #009d8f;
        }

        .error, .success {
            text-align: center;
            font-size: 16px;
        }

        .error {
            color: red;
        }

        .success {
            color: green;
        }

        .logo-container {
            text-align: center;
            margin-top: 20px;
        }

        .login-link {
            text-align: center;
            margin-top: 20px;
        }

        .login-link a {
            color: #00bfae;
            text-decoration: none;
            font-size: 16px;
        }

        .login-link a:hover {
            color: #009d8f;
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .modal-content {
            background-color: white;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
            width: 300px;
        }

        .btn-copy {
            background-color: #007bff;
            color: white;
            padding: 10px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }

        .btn-close {
            background-color: red;
            color: white;
            padding: 10px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
    </style>
</head>
<body>

<header>
    <!-- Logo ComBraz centralizada -->
    <img src="../public_html/assets/images/logo_combraz_licitasis.png" alt="Logo ComBraz" class="logo">
</header>

<div class="container">
    <?php if ($error) { echo "<p class='error'>$error</p>"; } ?>
    <?php if ($success) { echo "<p class='success'>$success</p>"; } ?>

    <h2>Cadastro de usuários</h2>

    <!-- Formulário de cadastro -->
    <form action="signup.php" method="POST">
        <label for="name">Nome:</label>
        <input type="text" id="name" name="name" required>

        <label for="email">Email:</label>
        <input type="email" id="email" name="email" required>

        <label for="permission">Permissão:</label>
        <select id="permission" name="permission" required>
            <option value="Selecione">Selecione a permissão do usuário</option>
            <option value="Administrador">Administrador</option>
            <option value="Financeiro">Financeiro</option>
            <option value="Faturamento">Faturamento</option>
            <option value="Acompanhamento">Acompanhamento</option>
        </select>

        <button type="submit">Cadastrar</button>
    </form>

    <div class="logo-container">
        <img src="../public_html/assets/images/Logo_novo.png" alt="Logo ComBraz" class="logo">
    </div>

    <!-- Link para a página de login -->
    <div class="login-link">
        <a href="login.php">Ir para página de login</a>
    </div>
</div>

<?php if ($success): ?>
<div class="modal" id="modal">
    <div class="modal-content">
        <p><strong>Conta Criada!</strong></p>
        <p>Email: <span id="user-email"><?php echo $createdEmail; ?></span></p>
        <p>Permissão: <span id="user-permission"><?php echo $createdPermission; ?></span></p>
        <p>Senha: <span id="user-password"><?php echo $createdPassword; ?></span></p>
        <button class="btn-copy" onclick="copyToClipboard()">Copiar</button>
        <button class="btn-close" onclick="closeModal()">Fechar</button>
    </div>
</div>
<script>
    document.getElementById('modal').style.display = 'flex';

    function copyToClipboard() {
        navigator.clipboard.writeText("Email: " + document.getElementById("user-email").innerText + "\nSenha: " + document.getElementById("user-password").innerText);
        alert("Dados copiados!");
    }

    function closeModal() {
        document.getElementById('modal').style.display = 'none';
        window.location.href = 'signup.php'; // Redireciona para resetar a página de cadastro
    }
</script>

<?php endif; ?>

</body>
</html>
