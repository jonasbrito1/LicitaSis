<?php
session_start();

// Verifica se o usuário está logado
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

// Inicializa a variável $isAdmin com base na permissão do usuário
$isAdmin = isset($_SESSION['user']['permission']) && $_SESSION['user']['permission'] === 'Administrador';

// Conexão com o banco de dados
require_once('db.php');

// Inclui o PHPMailer para redefinição de senha
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'vendor/autoload.php';

$error = "";
$success = "";
$users = [];
$searchTerm = "";

// Função para gerar senha aleatória
function generateRandomPassword() {
    $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!@#$%';
    $password = '';
    for ($i = 0; $i < 12; $i++) {
        $randomIndex = rand(0, strlen($characters) - 1);
        $password .= $characters[$randomIndex];
    }
    return $password;
}

// Função para enviar email com nova senha
function sendPasswordEmail($email, $name, $newPassword) {
    try {
        $mail = new PHPMailer(true);

        // Configuração do servidor SMTP
        $mail->isSMTP();
        $mail->Host = 'mail.combraz.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'jon@combraz.com';
        $mail->Password = '^V$[k]2r^0(9';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        // Remetente e destinatário
        $mail->setFrom('jon@combraz.com', 'LicitaSis');
        $mail->addAddress($email, $name);

        // Conteúdo do e-mail
        $mail->isHTML(true);
        $mail->Subject = 'Nova Senha - LicitaSis';
        $mail->Body = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; background: #f9f9f9; padding: 20px;'>
                <div style='background: linear-gradient(135deg, #2D893E 0%, #4CAC74 100%); padding: 30px; text-align: center; border-radius: 10px 10px 0 0;'>
                    <h1 style='color: white; margin: 0; font-size: 24px;'>🔑 LicitaSis</h1>
                    <p style='color: #e8f5e8; margin: 10px 0 0 0;'>Redefinição de Senha</p>
                </div>
                <div style='background: white; padding: 30px; border-radius: 0 0 10px 10px; box-shadow: 0 5px 15px rgba(0,0,0,0.1);'>
                    <h2 style='color: #2D893E; margin-bottom: 20px;'>Olá, $name!</h2>
                    <p style='color: #666; line-height: 1.6; margin-bottom: 25px;'>
                        Sua senha foi redefinida com sucesso no sistema LicitaSis. 
                        Sua nova senha de acesso é:
                    </p>
                    <div style='background: #f8f9fa; padding: 20px; border-radius: 8px; border-left: 4px solid #2D893E; margin-bottom: 25px; text-align: center;'>
                        <p style='margin: 0; color: #333; font-size: 18px; font-weight: bold; letter-spacing: 2px;'>$newPassword</p>
                    </div>
                    <div style='text-align: center; margin: 30px 0;'>
                        <a href='https://www.combraz.com/includes/login.php' 
                           style='background: linear-gradient(135deg, #00bfae 0%, #009d8f 100%); 
                                  color: white; 
                                  padding: 15px 30px; 
                                  text-decoration: none; 
                                  border-radius: 8px; 
                                  font-weight: bold; 
                                  display: inline-block;
                                  box-shadow: 0 4px 15px rgba(0,191,174,0.3);'>
                            Acessar Sistema
                        </a>
                    </div>
                    <p style='color: #888; font-size: 12px; line-height: 1.4; margin-top: 30px; border-top: 1px solid #eee; padding-top: 20px;'>
                        Por segurança, recomendamos que você altere sua senha após o primeiro acesso.
                        <br>Este é um e-mail automático, não responda.
                    </p>
                </div>
            </div>
        ";

        return $mail->send();
    } catch (Exception $e) {
        return false;
    }
}

// Verifica se a pesquisa foi realizada
if (isset($_GET['search']) && !empty($_GET['search'])) {
    $searchTerm = $_GET['search'];
    
    try {
        $sql = "SELECT id, name, email, permission FROM users WHERE name LIKE :searchTerm OR email LIKE :searchTerm ORDER BY name ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':searchTerm', "%$searchTerm%");
        $stmt->execute();
        
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error = "Erro na consulta: " . $e->getMessage();
    }
} else {
    try {
        $sql = "SELECT id, name, email, permission FROM users ORDER BY name ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error = "Erro ao buscar usuários: " . $e->getMessage();
    }
}

// Função para buscar dados do usuário
if (isset($_GET['get_user_id'])) {
    $id = $_GET['get_user_id'];
    try {
        $sql = "SELECT * FROM users WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode($user);
        exit();
    } catch (PDOException $e) {
        echo json_encode(['error' => 'Erro ao buscar usuário: ' . $e->getMessage()]);
        exit();
    }
}

// Atualizar dados do usuário
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_user'])) {
    $id = $_POST['id'];
    $name = $_POST['name'];
    $email = $_POST['email'];
    $permission = $_POST['permission'];

    try {
        $sql = "UPDATE users SET name = :name, email = :email, permission = :permission WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':permission', $permission);
        $stmt->bindParam(':id', $id);
        $stmt->execute();

        $success = "Usuário atualizado com sucesso!";
        header("Location: consulta_usuario.php?success=" . urlencode($success));
        exit();
    } catch (PDOException $e) {
        $error = "Erro ao atualizar usuário: " . $e->getMessage();
    }
}

// Redefinir senha do usuário
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['reset_password'])) {
    $id = $_POST['user_id'];
    $passwordOption = $_POST['password_reset_option'];
    $customPassword = isset($_POST['custom_new_password']) ? trim($_POST['custom_new_password']) : '';

    try {
        // Busca dados do usuário
        $sql = "SELECT name, email FROM users WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            // Define a nova senha
            if ($passwordOption === 'custom' && !empty($customPassword) && strlen($customPassword) >= 6) {
                $newPassword = $customPassword;
            } else {
                $newPassword = generateRandomPassword();
            }

            // Hash da nova senha
            $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);

            // Atualiza a senha no banco
            $sql = "UPDATE users SET password = :password WHERE id = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(':password', $hashedPassword);
            $stmt->bindParam(':id', $id);
            $stmt->execute();

            // Envia email com nova senha
            if (sendPasswordEmail($user['email'], $user['name'], $newPassword)) {
                $success = "Senha redefinida e enviada por e-mail com sucesso!";
            } else {
                $success = "Senha redefinida com sucesso, mas falha ao enviar e-mail.";
            }

            header("Location: consulta_usuario.php?success=" . urlencode($success));
            exit();
        } else {
            $error = "Usuário não encontrado!";
        }
    } catch (PDOException $e) {
        $error = "Erro ao redefinir senha: " . $e->getMessage();
    }
}

// Função para excluir usuário
if (isset($_GET['delete_user_id'])) {
    $id = $_GET['delete_user_id'];
    try {
        $sql = "DELETE FROM users WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':id', $id);
        $stmt->execute();

        $success = "Usuário excluído com sucesso!";
        header("Location: consulta_usuario.php?success=" . urlencode($success));
        exit();
    } catch (PDOException $e) {
        $error = "Erro ao excluir usuário: " . $e->getMessage();
    }
}

// Exibe mensagens de sucesso da URL
if (isset($_GET['success'])) {
    $success = $_GET['success'];
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Consulta de Usuários - LicitaSis</title>
    <link rel="icon" href="../public_html/assets/images/logo_combraz.png" type="image/png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" />
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

         /* Header */
        header {
            background: linear-gradient(135deg, var(--primary-light) 0%, var(--primary-color) 100%);
            padding: 0.5rem 0;
            text-align: center;
            box-shadow: var(--shadow);
            position: relative;
        }

        .logo {
            max-width: 140px;
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
            border-radius: 0 0 var(--radius) var(--radius);
            overflow: hidden;
        }

        .dropdown-content a {
            display: block;
            padding: 0.875rem 1.25rem;
            border-bottom: 1px solid rgba(255,255,255,0.1);
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

        /* Container principal */
        .container {
            max-width: 1200px;
            margin: 2.5rem auto;
            padding: 2.5rem;
            background: white;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            transition: var(--transition);
        }

        .container:hover {
            box-shadow: var(--shadow-hover);
            transform: translateY(-2px);
        }

        h2 {
            text-align: center;
            color: var(--primary-color);
            margin-bottom: 2rem;
            font-size: 1.8rem;
            font-weight: 600;
            position: relative;
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

        /* Mensagens de erro e sucesso */
        .alert {
            padding: 1rem;
            margin-bottom: 1.5rem;
            border-radius: var(--radius-sm);
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            animation: slideInDown 0.5s ease;
        }

        .alert-error {
            background-color: rgba(220, 53, 69, 0.1);
            color: var(--danger-color);
            border-left: 4px solid var(--danger-color);
        }

        .alert-success {
            background-color: rgba(40, 167, 69, 0.1);
            color: var(--success-color);
            border-left: 4px solid var(--success-color);
        }

        @keyframes slideInDown {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Barra de pesquisa */
        .search-container {
            background: var(--light-gray);
            padding: 1.5rem;
            border-radius: var(--radius);
            margin-bottom: 2rem;
            border: 1px solid var(--border-color);
        }

        .search-bar {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            justify-content: center;
            align-items: end;
        }

        .search-group {
            flex: 1 1 300px;
            max-width: 400px;
        }

        .search-bar label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--dark-gray);
        }

        .search-bar input[type="text"] {
            width: 100%;
            padding: 0.875rem 1rem;
            border: 2px solid var(--border-color);
            border-radius: var(--radius-sm);
            font-size: 1rem;
            transition: var(--transition);
            background-color: white;
        }

        .search-bar input[type="text"]:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(45, 137, 62, 0.2);
        }

        .search-bar button {
            padding: 0.875rem 1.5rem;
            background: linear-gradient(135deg, var(--secondary-color) 0%, var(--secondary-dark) 100%);
            color: white;
            border: none;
            border-radius: var(--radius-sm);
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            min-width: 120px;
            box-shadow: 0 4px 8px rgba(0, 191, 174, 0.2);
        }

        .search-bar button:hover {
            background: linear-gradient(135deg, var(--secondary-dark) 0%, var(--secondary-color) 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(0, 191, 174, 0.3);
        }

        .btn-secondary {
            background: linear-gradient(135deg, var(--medium-gray) 0%, var(--dark-gray) 100%);
            box-shadow: 0 4px 8px rgba(108, 117, 125, 0.2);
        }

        .btn-secondary:hover {
            background: linear-gradient(135deg, var(--dark-gray) 0%, var(--medium-gray) 100%);
            box-shadow: 0 6px 12px rgba(108, 117, 125, 0.3);
        }

        /* Tabela de resultados */
        .table-container {
            overflow-x: auto;
            margin-top: 1.5rem;
            border-radius: var(--radius-sm);
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            background: white;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.95rem;
        }

        table th, table td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
            vertical-align: middle;
        }

        table th {
            background: linear-gradient(135deg, var(--secondary-color) 0%, var(--secondary-dark) 100%);
            color: white;
            font-weight: 600;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        table tr:last-child td {
            border-bottom: none;
        }

        table tr:hover {
            background-color: rgba(0, 191, 174, 0.05);
            transform: translateX(3px);
            transition: var(--transition);
        }

        table a {
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 500;
            transition: var(--transition);
            display: inline-block;
        }

        table a:hover {
            color: var(--secondary-color);
            transform: translateY(-1px);
        }

        .badge {
            padding: 0.4rem 0.8rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            text-align: center;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
        }

        .badge-admin {
            background: linear-gradient(135deg, var(--danger-color) 0%, #c82333 100%);
            color: white;
        }

        .badge-user {
            background: linear-gradient(135deg, var(--success-color) 0%, #20c997 100%);
            color: white;
        }

        /* Botões de ação */
        .btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: var(--radius-sm);
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.3rem;
            text-decoration: none;
            margin-right: 0.5rem;
            margin-bottom: 0.3rem;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 100%);
            color: white;
            box-shadow: 0 2px 6px rgba(45, 137, 62, 0.2);
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary-color) 100%);
            transform: translateY(-1px);
            box-shadow: 0 4px 10px rgba(45, 137, 62, 0.3);
        }

        .btn-warning {
            background: linear-gradient(135deg, var(--warning-color) 0%, #e0a800 100%);
            color: #333;
            box-shadow: 0 2px 6px rgba(255, 193, 7, 0.2);
        }

        .btn-warning:hover {
            background: linear-gradient(135deg, #e0a800 0%, var(--warning-color) 100%);
            transform: translateY(-1px);
            box-shadow: 0 4px 10px rgba(255, 193, 7, 0.3);
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--danger-color) 0%, #c82333 100%);
            color: white;
            box-shadow: 0 2px 6px rgba(220, 53, 69, 0.2);
        }

        .btn-danger:hover {
            background: linear-gradient(135deg, #c82333 0%, var(--danger-color) 100%);
            transform: translateY(-1px);
            box-shadow: 0 4px 10px rgba(220, 53, 69, 0.3);
        }

        .btn-success {
            background: linear-gradient(135deg, var(--success-color) 0%, #1e7e34 100%);
            color: white;
            box-shadow: 0 2px 6px rgba(40, 167, 69, 0.2);
        }

        .btn-success:hover {
            background: linear-gradient(135deg, #1e7e34 0%, var(--success-color) 100%);
            transform: translateY(-1px);
            box-shadow: 0 4px 10px rgba(40, 167, 69, 0.3);
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            overflow: auto;
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .modal-content {
            background-color: white;
            margin: 3% auto;
            padding: 0;
            border-radius: var(--radius);
            box-shadow: var(--shadow-hover);
            width: 90%;
            max-width: 600px;
            position: relative;
            animation: slideIn 0.3s ease;
            max-height: 90vh;
            overflow-y: auto;
        }

        @keyframes slideIn {
            from { transform: translateY(-50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .modal-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 100%);
            color: white;
            padding: 1.5rem;
            border-radius: var(--radius) var(--radius) 0 0;
            position: relative;
        }

        .modal-header h3 {
            margin: 0;
            font-size: 1.3rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .close {
            position: absolute;
            top: 1rem;
            right: 1.5rem;
            color: white;
            font-size: 1.8rem;
            font-weight: bold;
            cursor: pointer;
            transition: var(--transition);
        }

        .close:hover {
            transform: scale(1.1);
            opacity: 0.8;
        }

        .modal-body {
            padding: 2rem;
        }

        /* Formulários */
        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--primary-color);
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 0.875rem 1rem;
            border: 2px solid var(--border-color);
            border-radius: var(--radius-sm);
            font-size: 1rem;
            transition: var(--transition);
            background-color: white;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(45, 137, 62, 0.2);
        }

        .form-group input:disabled,
        .form-group select:disabled,
        .form-group input[readonly] {
            background-color: var(--light-gray);
            color: var(--medium-gray);
            cursor: not-allowed;
        }

        /* Grid para campos duplos */
        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        /* Opções de senha */
        .password-options {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .password-option {
            position: relative;
        }

        .password-option input[type="radio"] {
            position: absolute;
            opacity: 0;
            width: 0;
            height: 0;
        }

        .password-option label {
            display: block;
            padding: 1rem;
            border: 2px solid var(--border-color);
            border-radius: var(--radius-sm);
            cursor: pointer;
            transition: var(--transition);
            text-align: center;
            background: white;
            margin-bottom: 0;
            font-weight: 500;
        }

        .password-option input[type="radio"]:checked + label {
            border-color: var(--secondary-color);
            background: linear-gradient(135deg, var(--secondary-color) 0%, var(--secondary-dark) 100%);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 191, 174, 0.3);
        }

        .custom-password-field {
            display: none;
            animation: slideDown 0.3s ease;
        }

        .custom-password-field.show {
            display: block;
        }

        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Info box */
        .info-box {
            background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
            padding: 1rem;
            border-radius: var(--radius-sm);
            border-left: 4px solid var(--info-color);
            margin: 1rem 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .info-box p {
            margin: 0;
            color: #0277bd;
            font-size: 0.9rem;
            font-weight: 500;
        }

        /* Button Container */
        .btn-container {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 2rem;
            flex-wrap: wrap;
        }

        .btn-container .btn {
            margin: 0;
            min-width: 120px;
        }

        /* Sem resultados */
        .no-results {
            text-align: center;
            padding: 3rem;
            color: var(--medium-gray);
        }

        .no-results i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
            color: var(--secondary-color);
        }

        .no-results h3 {
            margin-bottom: 1rem;
            color: var(--dark-gray);
        }

        .no-results p {
            font-size: 1rem;
        }

        .no-results a {
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 600;
        }

        .no-results a:hover {
            color: var(--secondary-color);
            text-decoration: underline;
        }

        /* Loading */
        .loading {
            display: none;
            text-align: center;
            margin: 2rem 0;
        }

        .spinner {
            border: 3px solid var(--light-gray);
            border-top: 3px solid var(--secondary-color);
            border-radius: 50%;
            width: 30px;
            height: 30px;
            animation: spin 1s linear infinite;
            margin: 0 auto;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Responsividade */
        @media (max-width: 1200px) {
            .container {
                margin: 2rem 1.5rem;
                padding: 2rem;
            }
        }

        @media (max-width: 992px) {
            .grid-2 {
                grid-template-columns: 1fr;
            }

            .password-options {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
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

            .container {
                padding: 1.5rem;
                margin: 1.5rem 1rem;
            }

            h2 {
                font-size: 1.5rem;
            }

            .search-bar {
                flex-direction: column;
                align-items: stretch;
            }

            .search-group {
                max-width: none;
            }

            .search-bar button {
                width: 100%;
                margin-left: 0;
            }

            .modal-content {
                width: 95%;
                margin: 5% auto;
            }

            .modal-body {
                padding: 1.5rem;
            }

            .btn-container {
                flex-direction: column;
            }

            table th,
            table td {
                padding: 0.75rem 0.5rem;
                font-size: 0.9rem;
            }

            .btn {
                padding: 0.4rem 0.8rem;
                font-size: 0.8rem;
                margin-right: 0.3rem;
            }
        }

        @media (max-width: 480px) {
            header {
                padding: 0.6rem 0;
            }

            .logo {
                max-width: 120px;
            }

            .container {
                padding: 1rem;
                margin: 1rem 0.5rem;
                border-radius: var(--radius-sm);
            }

            h2 {
                font-size: 1.3rem;
                margin-bottom: 1.5rem;
            }

            .search-container {
                padding: 1rem;
            }

            .modal-content {
                width: 98%;
                margin: 2% auto;
            }

            .modal-header {
                padding: 1rem;
            }

            .modal-body {
                padding: 1rem;
            }

            table th,
            table td {
                padding: 0.5rem 0.3rem;
                font-size: 0.8rem;
            }

            .btn {
                padding: 0.35rem 0.6rem;
                font-size: 0.75rem;
                min-width: auto;
            }

            .btn-container .btn {
                min-width: auto;
                flex: 1;
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

            .modal-header h3 {
                font-size: 1.1rem;
            }

            .btn {
                padding: 0.3rem 0.5rem;
                font-size: 0.7rem;
            }
        }

        /* Animações de entrada */
        .fade-in {
            animation: fadeInUp 0.6s ease;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Efeitos hover melhorados */
        .btn:active {
            transform: translateY(1px) scale(0.98);
        }

        .form-group input:hover:not(:disabled):not([readonly]),
        .form-group select:hover:not(:disabled) {
            border-color: var(--secondary-color);
        }

        /* Estilo para campos obrigatórios */
        .form-group.required label::after {
            content: ' *';
            color: var(--danger-color);
        }

        /* Tooltip style */
        .tooltip {
            position: relative;
            cursor: help;
        }

        .tooltip::after {
            content: attr(data-tooltip);
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            background: var(--dark-gray);
            color: white;
            padding: 0.5rem;
            border-radius: var(--radius-sm);
            font-size: 0.8rem;
            white-space: nowrap;
            opacity: 0;
            pointer-events: none;
            transition: var(--transition);
            z-index: 1001;
        }

        .tooltip:hover::after {
            opacity: 1;
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

    <div class="container fade-in">
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

        <h2><i class="fas fa-users"></i> Consulta de Usuários</h2>

        <div class="search-container">
            <form action="consulta_usuario.php" method="GET">
                <div class="search-bar">
                    <div class="search-group">
                        <label for="search"><i class="fas fa-search"></i> Pesquisar por Nome ou E-mail</label>
                        <input type="text" name="search" id="search" 
                               placeholder="Digite o nome ou e-mail do usuário" 
                               value="<?php echo htmlspecialchars($searchTerm); ?>">
                    </div>
                    <button type="submit" class="btn">
                        <i class="fas fa-search"></i> Pesquisar
                    </button>
                </div>
            </form>
            
            <?php if ($searchTerm): ?>
                <div style="margin-top: 1rem;">
                    <a href="consulta_usuario.php" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Limpar Pesquisa
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <div class="loading" id="loading">
            <div class="spinner"></div>
            <p>Carregando dados...</p>
        </div>

        <?php if (count($users) > 0): ?>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th><i class="fas fa-user"></i> Nome</th>
                            <th><i class="fas fa-envelope"></i> E-mail</th>
                            <th><i class="fas fa-shield-alt"></i> Permissão</th>
                            <th><i class="fas fa-cogs"></i> Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                            <tr>
                                <td>
                                    <a href="javascript:void(0);" 
                                       onclick="openModal(<?php echo $user['id']; ?>)">
                                        <?php echo htmlspecialchars($user['name']); ?>
                                    </a>
                                </td>
                                <td><?php echo htmlspecialchars($user['email']); ?></td>
                                <td>
                                    <span class="badge <?php echo $user['permission'] === 'Administrador' ? 'badge-admin' : 'badge-user'; ?>">
                                        <?php if($user['permission'] === 'Administrador'): ?>
                                            <i class="fas fa-crown"></i> Admin
                                        <?php else: ?>
                                            <i class="fas fa-user"></i> User
                                        <?php endif; ?>
                                    </span>
                                </td>
                                <td>
                                    <button onclick="openModal(<?php echo $user['id']; ?>)" 
                                            class="btn btn-primary tooltip"
                                            data-tooltip="Editar usuário">
                                        <i class="fas fa-edit"></i> Editar
                                    </button>
                                    <button onclick="openPasswordModal(<?php echo $user['id']; ?>)" 
                                            class="btn btn-warning tooltip"
                                            data-tooltip="Redefinir senha">
                                        <i class="fas fa-key"></i> Senha
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="no-results">
                <i class="fas fa-search"></i>
                <h3>Nenhum usuário encontrado</h3>
                <?php if ($searchTerm): ?>
                    <p>Tente ajustar sua pesquisa ou <a href="signup.php">cadastre um novo usuário</a>.</p>
                <?php else: ?>
                    <p>Nenhum usuário cadastrado no sistema. <a href="signup.php">Cadastre o primeiro usuário</a>.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Modal de Edição -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-user-edit"></i> Editar Usuário</h3>
                <span class="close" onclick="closeModal()">&times;</span>
            </div>
            <div class="modal-body">
                <form method="POST" action="consulta_usuario.php" id="editForm">
                    <input type="hidden" name="id" id="user_id">
                    
                    <div class="form-group required">
                        <label for="name"><i class="fas fa-user"></i> Nome</label>
                        <input type="text" name="name" id="name" readonly required>
                    </div>

                    <div class="form-group required">
                        <label for="email"><i class="fas fa-envelope"></i> E-mail</label>
                        <input type="email" name="email" id="email" readonly required>
                    </div>

                    <div class="form-group required">
                        <label for="permission"><i class="fas fa-shield-alt"></i> Permissão</label>
                        <select name="permission" id="permission" disabled required>
                            <option value="Administrador"><i class="fas fa-crown"></i> Administrador</option>
                            <option value="User"><i class="fas fa-user"></i> Usuário</option>
                        </select>
                    </div>

                    <div class="btn-container">
                        <button type="submit" name="update_user" id="saveBtn" class="btn btn-success" style="display: none;">
                            <i class="fas fa-save"></i> Salvar Alterações
                        </button>
                        <button type="button" class="btn btn-primary" id="editBtn" onclick="enableEditing()">
                            <i class="fas fa-edit"></i> Habilitar Edição
                        </button>
                        <button type="button" class="btn btn-danger" onclick="confirmDelete()">
                            <i class="fas fa-trash"></i> Excluir Usuário
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal de Redefinição de Senha -->
    <div id="passwordModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-key"></i> Redefinir Senha</h3>
                <span class="close" onclick="closePasswordModal()">&times;</span>
            </div>
            <div class="modal-body">
                <form method="POST" action="consulta_usuario.php" id="passwordForm">
                    <input type="hidden" name="user_id" id="password_user_id">
                    
                    <div class="form-group">
                        <p><strong><i class="fas fa-user"></i> Usuário:</strong> <span id="password_user_name"></span></p>
                        <p><strong><i class="fas fa-envelope"></i> E-mail:</strong> <span id="password_user_email"></span></p>
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-key"></i> Opção de Nova Senha</label>
                        <div class="password-options">
                            <div class="password-option">
                                <input type="radio" id="random_password_reset" name="password_reset_option" value="random" checked>
                                <label for="random_password_reset">
                                    <i class="fas fa-dice"></i> Gerar Aleatória
                                </label>
                            </div>
                            <div class="password-option">
                                <input type="radio" id="custom_password_reset" name="password_reset_option" value="custom">
                                <label for="custom_password_reset">
                                    <i class="fas fa-edit"></i> Senha Personalizada
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="form-group custom-password-field" id="customPasswordResetField">
                        <label for="custom_new_password"><i class="fas fa-lock"></i> Digite a Nova Senha (mínimo 6 caracteres)</label>
                        <input type="password" id="custom_new_password" name="custom_new_password" 
                               placeholder="Digite uma senha segura" minlength="6">
                    </div>

                    <div class="info-box">
                        <i class="fas fa-info-circle"></i>
                        <p><strong>Importante:</strong> A nova senha será enviada automaticamente por e-mail para o usuário.</p>
                    </div>

                    <div class="btn-container">
                        <button type="submit" name="reset_password" class="btn btn-warning">
                            <i class="fas fa-sync-alt"></i> Redefinir Senha
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="closePasswordModal()">
                            <i class="fas fa-times"></i> Cancelar
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal de Confirmação de Exclusão -->
    <div id="deleteModal" class="modal">
        <div class="modal-content" style="max-width: 450px;">
            <div class="modal-header">
                <h3><i class="fas fa-exclamation-triangle"></i> Confirmar Exclusão</h3>
                <span class="close" onclick="closeDeleteModal()">&times;</span>
            </div>
            <div class="modal-body">
                <div style="text-align: center; padding: 1rem;">
                    <div style="font-size: 4rem; color: var(--danger-color); margin-bottom: 1rem;">
                        <i class="fas fa-trash-alt"></i>
                    </div>
                    <h3 style="color: var(--danger-color); margin-bottom: 1rem;">Deseja realmente excluir este usuário?</h3>
                    <p style="color: var(--medium-gray); margin-bottom: 2rem;">
                        Esta ação não pode ser desfeita. Todos os dados do usuário serão permanentemente removidos.
                    </p>
                    <div class="btn-container" style="justify-content: center;">
                        <button type="button" class="btn btn-danger" onclick="deleteUser()">
                            <i class="fas fa-check"></i> Sim, Excluir
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="closeDeleteModal()">
                            <i class="fas fa-times"></i> Cancelar
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        let currentUserId = null;

        // Função para abrir o modal de edição
        function openModal(id) {
            currentUserId = id;
            const modal = document.getElementById("editModal");
            modal.style.display = "block";
            document.body.style.overflow = "hidden";

            showLoading();

            fetch('consulta_usuario.php?get_user_id=' + id)
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        alert('Erro: ' + data.error);
                        closeModal();
                        return;
                    }
                    
                    document.getElementById('user_id').value = data.id;
                    document.getElementById('name').value = data.name;
                    document.getElementById('email').value = data.email;
                    document.getElementById('permission').value = data.permission;
                    hideLoading();
                })
                .catch(error => {
                    console.error('Erro:', error);
                    alert('Erro ao carregar dados do usuário');
                    hideLoading();
                    closeModal();
                });
        }

        // Função para abrir o modal de redefinição de senha
        function openPasswordModal(id) {
            currentUserId = id;
            const modal = document.getElementById("passwordModal");
            modal.style.display = "block";
            document.body.style.overflow = "hidden";

            showLoading();

            fetch('consulta_usuario.php?get_user_id=' + id)
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        alert('Erro: ' + data.error);
                        closePasswordModal();
                        return;
                    }
                    
                    document.getElementById('password_user_id').value = data.id;
                    document.getElementById('password_user_name').textContent = data.name;
                    document.getElementById('password_user_email').textContent = data.email;
                    hideLoading();
                })
                .catch(error => {
                    console.error('Erro:', error);
                    alert('Erro ao carregar dados do usuário');
                    hideLoading();
                    closePasswordModal();
                });
        }

        // Função para habilitar edição
        function enableEditing() {
            const inputs = document.querySelectorAll('#editModal input:not([type="hidden"]), #editModal select');
            inputs.forEach(input => {
                input.removeAttribute('readonly');
                input.removeAttribute('disabled');
            });
            
            document.getElementById('saveBtn').style.display = 'inline-flex';
            document.getElementById('editBtn').style.display = 'none';
        }

        // Função para desabilitar edição
        function disableEditing() {
            const inputs = document.querySelectorAll('#editModal input:not([type="hidden"]), #editModal select');
            inputs.forEach(input => {
                if (input.type !== 'hidden') {
                    input.setAttribute('readonly', 'readonly');
                    input.setAttribute('disabled', 'disabled');
                }
            });
            
            document.getElementById('saveBtn').style.display = 'none';
            document.getElementById('editBtn').style.display = 'inline-flex';
        }

        // Função para confirmar exclusão
        function confirmDelete() {
            closeModal();
            const deleteModal = document.getElementById("deleteModal");
            deleteModal.style.display = "block";
        }

        // Função para excluir usuário
        function deleteUser() {
            if (currentUserId) {
                showLoading();
                window.location.href = 'consulta_usuario.php?delete_user_id=' + currentUserId;
            }
        }

        // Funções para fechar modais
        function closeModal() {
            const modal = document.getElementById("editModal");
            modal.style.display = "none";
            document.body.style.overflow = "auto";
            resetEditForm();
        }

        function closePasswordModal() {
            const modal = document.getElementById("passwordModal");
            modal.style.display = "none";
            document.body.style.overflow = "auto";
            resetPasswordForm();
        }

        function closeDeleteModal() {
            const modal = document.getElementById("deleteModal");
            modal.style.display = "none";
            document.body.style.overflow = "auto";
        }

        // Função para resetar o formulário de edição
        function resetEditForm() {
            disableEditing();
            document.getElementById('editForm').reset();
        }

        // Função para resetar o formulário de senha
        function resetPasswordForm() {
            document.getElementById('random_password_reset').checked = true;
            document.getElementById('custom_new_password').value = '';
            toggleCustomPasswordResetField();
            document.getElementById('passwordForm').reset();
        }

        // Controla a exibição do campo de senha personalizada
        function toggleCustomPasswordResetField() {
            const customOption = document.getElementById('custom_password_reset');
            const customField = document.getElementById('customPasswordResetField');
            const customPasswordInput = document.getElementById('custom_new_password');
            
            if (customOption.checked) {
                customField.classList.add('show');
                customPasswordInput.required = true;
                customPasswordInput.focus();
            } else {
                customField.classList.remove('show');
                customPasswordInput.required = false;
                customPasswordInput.value = '';
            }
        }

        // Event listeners para as opções de senha
        document.getElementById('random_password_reset').addEventListener('change', toggleCustomPasswordResetField);
        document.getElementById('custom_password_reset').addEventListener('change', toggleCustomPasswordResetField);

        // Função para mostrar loading
        function showLoading() {
            document.getElementById('loading').style.display = 'block';
        }

        // Função para esconder loading
        function hideLoading() {
            document.getElementById('loading').style.display = 'none';
        }

        // Fecha modais ao clicar fora
        window.onclick = function(event) {
            const editModal = document.getElementById("editModal");
            const passwordModal = document.getElementById("passwordModal");
            const deleteModal = document.getElementById("deleteModal");
            
            if (event.target === editModal) {
                closeModal();
            }
            if (event.target === passwordModal) {
                closePasswordModal();
            }
            if (event.target === deleteModal) {
                closeDeleteModal();
            }
        }

        // Validação do formulário de senha
        document.getElementById('passwordForm').addEventListener('submit', function(e) {
            const customOption = document.getElementById('custom_password_reset');
            const customPassword = document.getElementById('custom_new_password');
            
            if (customOption.checked && customPassword.value.length < 6) {
                e.preventDefault();
                alert('A senha personalizada deve ter pelo menos 6 caracteres!');
                customPassword.focus();
                return false;
            }
            
            if (customOption.checked && customPassword.value.length > 0) {
                if (!confirm('Tem certeza que deseja definir uma senha personalizada? A senha será enviada por e-mail para o usuário.')) {
                    e.preventDefault();
                    return false;
                }
            }
            
            showLoading();
        });

        // Validação do formulário de edição
        document.getElementById('editForm').addEventListener('submit', function(e) {
            const name = document.getElementById('name').value.trim();
            const email = document.getElementById('email').value.trim();
            
            if (!name || !email) {
                e.preventDefault();
                alert('Nome e e-mail são obrigatórios!');
                if (!name) document.getElementById('name').focus();
                else document.getElementById('email').focus();
                return false;
            }
            
            // Validação básica de e-mail
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(email)) {
                e.preventDefault();
                alert('Por favor, insira um e-mail válido!');
                document.getElementById('email').focus();
                return false;
            }
            
            showLoading();
        });

        // Fechar modal com ESC
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeModal();
                closePasswordModal();
                closeDeleteModal();
            }
        });

        // Inicializa o estado correto dos campos
        document.addEventListener('DOMContentLoaded', function() {
            toggleCustomPasswordResetField();
            hideLoading();
            
            // Remove mensagens após 5 segundos
            setTimeout(function() {
                const messages = document.querySelectorAll('.alert');
                messages.forEach(function(message) {
                    message.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                    message.style.opacity = '0';
                    message.style.transform = 'translateY(-20px)';
                    setTimeout(function() {
                        if (message.parentNode) {
                            message.remove();
                        }
                    }, 500);
                });
            }, 5000);
            
            // Adiciona efeitos de hover nas linhas da tabela
            const tableRows = document.querySelectorAll('tbody tr');
            tableRows.forEach(row => {
                row.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateX(5px)';
                    this.style.transition = 'all 0.3s ease';
                    this.style.boxShadow = '0 2px 8px rgba(0,0,0,0.1)';
                });
                
                row.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateX(0)';
                    this.style.boxShadow = 'none';
                });
            });
            
            // Adiciona animação aos botões
            const buttons = document.querySelectorAll('.btn');
            buttons.forEach(button => {
                button.addEventListener('mousedown', function() {
                    this.style.transform = 'scale(0.95)';
                });
                
                button.addEventListener('mouseup', function() {
                    this.style.transform = 'scale(1)';
                });
                
                button.addEventListener('mouseleave', function() {
                    this.style.transform = 'scale(1)';
                });
            });
            
            // Adiciona validação em tempo real para o campo de senha personalizada
            const customPasswordInput = document.getElementById('custom_new_password');
            customPasswordInput.addEventListener('input', function() {
                const value = this.value;
                const minLength = 6;
                
                // Remove classes anteriores
                this.classList.remove('valid', 'invalid');
                
                if (value.length >= minLength) {
                    this.classList.add('valid');
                    this.style.borderColor = 'var(--success-color)';
                } else if (value.length > 0) {
                    this.classList.add('invalid');
                    this.style.borderColor = 'var(--danger-color)';
                } else {
                    this.style.borderColor = 'var(--border-color)';
                }
            });
            
            // Adiciona validação em tempo real para os campos do formulário de edição
            const nameInput = document.getElementById('name');
            const emailInput = document.getElementById('email');
            
            nameInput.addEventListener('input', function() {
                if (this.value.trim().length < 2) {
                    this.style.borderColor = 'var(--danger-color)';
                } else {
                    this.style.borderColor = 'var(--success-color)';
                }
            });
            
            emailInput.addEventListener('input', function() {
                const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (emailRegex.test(this.value)) {
                    this.style.borderColor = 'var(--success-color)';
                } else if (this.value.length > 0) {
                    this.style.borderColor = 'var(--danger-color)';
                } else {
                    this.style.borderColor = 'var(--border-color)';
                }
            });
        });

        // Função para pesquisa em tempo real (opcional)
        let searchTimeout;
        document.getElementById('search').addEventListener('input', function() {
            clearTimeout(searchTimeout);
            const searchTerm = this.value;
            
            if (searchTerm.length >= 3) {
                searchTimeout = setTimeout(function() {
                    // Aqui você pode implementar busca em tempo real via AJAX se desejar
                    // Para manter simples, mantemos a pesquisa via form submit
                }, 500);
            }
        });

        // Adiciona suporte a touch para dispositivos móveis
        if ('ontouchstart' in window) {
            document.body.classList.add('touch-device');
        }

        // Adiciona indicador de carregamento nos formulários
        const forms = document.querySelectorAll('form');
        forms.forEach(form => {
            form.addEventListener('submit', function() {
                const submitBtn = this.querySelector('button[type="submit"]');
                if (submitBtn) {
                    const originalText = submitBtn.innerHTML;
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processando...';
                    submitBtn.disabled = true;
                    
                    // Restaura o botão após 5 segundos como fallback
                    setTimeout(() => {
                        submitBtn.innerHTML = originalText;
                        submitBtn.disabled = false;
                    }, 5000);
                }
            });
        });

        // Adiciona confirmação antes de sair da página se houver alterações não salvas
        let formChanged = false;
        
        document.getElementById('editForm').addEventListener('change', function() {
            formChanged = true;
        });
        
        window.addEventListener('beforeunload', function(e) {
            if (formChanged) {
                e.preventDefault();
                e.returnValue = '';
                return 'Você tem alterações não salvas. Tem certeza que deseja sair?';
            }
        });
        
        document.getElementById('editForm').addEventListener('submit', function() {
            formChanged = false;
        });

        // Funcionalidade de notificação toast (opcional)
        function showToast(message, type = 'info') {
            const toast = document.createElement('div');
            toast.className = `toast toast-${type}`;
            toast.innerHTML = `
                <i class="fas fa-${type === 'success' ? 'check' : type === 'error' ? 'exclamation' : 'info'}-circle"></i>
                ${message}
            `;
            
            toast.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                background: var(--${type === 'success' ? 'success' : type === 'error' ? 'danger' : 'info'}-color);
                color: white;
                padding: 1rem;
                border-radius: var(--radius-sm);
                box-shadow: var(--shadow);
                z-index: 9999;
                animation: slideInRight 0.3s ease;
                max-width: 300px;
                word-wrap: break-word;
            `;
            
            document.body.appendChild(toast);
            
            setTimeout(() => {
                toast.style.animation = 'slideOutRight 0.3s ease';
                setTimeout(() => {
                    if (toast.parentNode) {
                        toast.remove();
                    }
                }, 300);
            }, 3000);
        }

        // Adiciona animações CSS para os toasts
        const style = document.createElement('style');
        style.textContent = `
            @keyframes slideInRight {
                from { transform: translateX(100%); opacity: 0; }
                to { transform: translateX(0); opacity: 1; }
            }
            
            @keyframes slideOutRight {
                from { transform: translateX(0); opacity: 1; }
                to { transform: translateX(100%); opacity: 0; }
            }
            
            .form-group input.valid {
                box-shadow: 0 0 0 2px rgba(40, 167, 69, 0.2);
            }
            
            .form-group input.invalid {
                box-shadow: 0 0 0 2px rgba(220, 53, 69, 0.2);
            }
            
            .touch-device .btn:hover {
                transform: none;
            }
            
            .touch-device .btn:active {
                transform: scale(0.95);
            }
        `;
        document.head.appendChild(style);

        // Melhora a acessibilidade
        document.addEventListener('keydown', function(e) {
            // Navegação com Tab melhorada
            if (e.key === 'Tab') {
                const focusableElements = document.querySelectorAll(
                    'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
                );
                const firstElement = focusableElements[0];
                const lastElement = focusableElements[focusableElements.length - 1];
                
                if (e.shiftKey && document.activeElement === firstElement) {
                    e.preventDefault();
                    lastElement.focus();
                } else if (!e.shiftKey && document.activeElement === lastElement) {
                    e.preventDefault();
                    firstElement.focus();
                }
            }
            
            // Enter para abrir modal
            if (e.key === 'Enter' && e.target.tagName === 'A' && e.target.onclick) {
                e.preventDefault();
                e.target.onclick();
            }
        });

        // Adiciona indicadores visuais para usuários com teclado
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Tab') {
                document.body.classList.add('keyboard-navigation');
            }
        });

        document.addEventListener('mousedown', function() {
            document.body.classList.remove('keyboard-navigation');
        });

        // Adiciona estilo para navegação por teclado
        const keyboardStyle = document.createElement('style');
        keyboardStyle.textContent = `
            .keyboard-navigation *:focus {
                outline: 2px solid var(--secondary-color) !important;
                outline-offset: 2px !important;
            }
        `;
        document.head.appendChild(keyboardStyle);

        console.log('Sistema de Consulta de Usuários carregado com sucesso!');
    </script>
</body>
</html>