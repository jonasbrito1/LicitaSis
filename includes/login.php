<?php
session_start(); // Inicia a sessão

// Inclui os arquivos db.php e function.php
include('../includes/db.php');  
include('../includes/function.php');

// Variáveis de erro e sucesso
$error = "";
$success = "";

// Verifica se o formulário foi enviado
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = $_POST['email'];
    $password = $_POST['password'];

    // Verifica se o email existe no banco de dados
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = :email LIMIT 1");
    $stmt->bindParam(':email', $email);
    $stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        // Se o email existir, verifica a senha
        if (password_verify($password, $user['password'])) {
            // Se a senha for válida, inicia a sessão
            $_SESSION['user'] = [
                'name' => $user['name'],
                'role' => $user['role'], // Garantir que 'role' esteja sendo atribuído
            ];
            header("Location: sistema.php"); // Redireciona para o sistema
            exit();
        } else {
            $error = "Senha incorreta!";
        }
    } else {
        $error = "Usuário não encontrado!";
    }
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - LicitaSis</title>

    <!-- Estilo CSS -->
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
            margin: 0;
            padding: 0;
        }

        header {
            background-color: rgb(157, 206, 173); /* Fundo verde claro */
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

        input {
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

    <h2>Login</h2>

    <!-- Formulário de login -->
    <form action="login.php" method="POST">
        <label for="email">Email:</label>
        <input type="email" id="email" name="email" required>
        
        <label for="password">Senha:</label>
        <input type="password" id="password" name="password" required>
        
        <button type="submit">Entrar</button>
    </form>

    <div class="logo-container">
        <img src="../public_html/assets/images/Logo_novo.png" alt="Logo ComBraz" class="logo">
    </div>

</div>

</body>
</html>
