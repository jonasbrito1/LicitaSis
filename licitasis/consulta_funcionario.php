<?php
session_start();

// Verifica se o usuário está logado
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

$error = "";
$success = "";
$funcionarios = [];
$searchTerm = "";

// Conexão com o banco de dados
require_once('db.php');

// Verifica se a pesquisa foi realizada
if (isset($_GET['search']) && !empty($_GET['search'])) {
    $searchTerm = $_GET['search'];

    try {
        $sql = "SELECT * FROM funcionarios WHERE nome_completo LIKE :searchTerm OR cpf LIKE :searchTerm ORDER BY nome_completo ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':searchTerm', "%$searchTerm%");
        $stmt->execute();

        $funcionarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error = "Erro na consulta: " . $e->getMessage();
    }
} else {
    try {
        $sql = "SELECT * FROM funcionarios ORDER BY nome_completo ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();

        $funcionarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error = "Erro ao buscar funcionários: " . $e->getMessage();
    }
}

// Função para buscar dados do funcionário
if (isset($_GET['get_funcionario_id'])) {
    $id = $_GET['get_funcionario_id'];
    try {
        $sql = "SELECT * FROM funcionarios WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':id', $id);
        $stmt->execute();

        $funcionario = $stmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode($funcionario);
        exit();
    } catch (PDOException $e) {
        echo json_encode(['error' => 'Erro ao buscar funcionário: ' . $e->getMessage()]);
        exit();
    }
}

// Atualizar dados do funcionário
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_funcionario'])) {
    $id = $_POST['id'];
    $nome_completo = $_POST['nome_completo'];
    $endereco = $_POST['endereco'];
    $telefone = $_POST['telefone'];
    $cargo = $_POST['cargo'];
    $pai = $_POST['pai'];
    $mae = $_POST['mae'];
    $cpf = $_POST['cpf'];
    $rg = $_POST['rg'];
    $data_nascimento = $_POST['data_nascimento'];
    $salario = $_POST['salario'];
    $sexo = $_POST['sexo'];

    try {
        $sql = "UPDATE funcionarios SET nome_completo = :nome_completo, endereco = :endereco, telefone = :telefone, cargo = :cargo, pai = :pai, mae = :mae, cpf = :cpf, rg = :rg, data_nascimento = :data_nascimento, salario = :salario, sexo = :sexo WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':nome_completo', $nome_completo);
        $stmt->bindParam(':endereco', $endereco);
        $stmt->bindParam(':telefone', $telefone);
        $stmt->bindParam(':cargo', $cargo);
        $stmt->bindParam(':pai', $pai);
        $stmt->bindParam(':mae', $mae);
        $stmt->bindParam(':cpf', $cpf);
        $stmt->bindParam(':rg', $rg);
        $stmt->bindParam(':data_nascimento', $data_nascimento);
        $stmt->bindParam(':salario', $salario);
        $stmt->bindParam(':sexo', $sexo);
        $stmt->bindParam(':id', $id);
        $stmt->execute();

        $success = "Funcionário atualizado com sucesso!";
        header("Location: consulta_funcionario.php?success=" . urlencode($success));
        exit();
    } catch (PDOException $e) {
        $error = "Erro ao atualizar funcionário: " . $e->getMessage();
    }
}

// Função para excluir funcionário
if (isset($_GET['delete_funcionario_id'])) {
    $id = $_GET['delete_funcionario_id'];
    try {
        $sql = "DELETE FROM funcionarios WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':id', $id);
        $stmt->execute();

        $success = "Funcionário excluído com sucesso!";
        header("Location: consulta_funcionario.php?success=" . urlencode($success));
        exit();
    } catch (PDOException $e) {
        $error = "Erro ao excluir funcionário: " . $e->getMessage();
    }
}

// Exibe mensagens de sucesso da URL
if (isset($_GET['success'])) {
    $success = $_GET['success'];
}

$isAdmin = isset($_SESSION['user']['permission']) && $_SESSION['user']['permission'] === 'Administrador';
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Consulta de Funcionários - LicitaSis</title>
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

        /* Mobile Menu */
        .mobile-menu-toggle {
            display: none;
            background: none;
            border: none;
            color: white;
            font-size: 1.5rem;
            padding: 0.5rem;
            cursor: pointer;
            position: absolute;
            right: 1rem;
            top: 50%;
            transform: translateY(-50%);
        }

        .nav-container {
            display: flex;
            justify-content: center;
            align-items: center;
            flex-wrap: wrap;
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

        .badge-masculino {
            background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
            color: white;
        }

        .badge-feminino {
            background: linear-gradient(135deg, #e83e8c 0%, #c2185b 100%);
            color: white;
        }

        .badge-outro {
            background: linear-gradient(135deg, var(--info-color) 0%, #117a8b 100%);
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

        .modal-content {
            background-color: white;
            margin: 3% auto;
            padding: 0;
            border-radius: var(--radius);
            box-shadow: var(--shadow-hover);
            width: 90%;
            max-width: 700px;
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
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
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
            .form-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            header {
                position: relative;
            }

            .mobile-menu-toggle {
                display: block;
            }

            .logo {
                max-width: 120px;
            }

            .nav-container {
                display: none;
                flex-direction: column;
                width: 100%;
                position: absolute;
                top: 100%;
                left: 0;
                background: var(--primary-color);
                box-shadow: 0 5px 15px rgba(0,0,0,0.2);
            }

            .nav-container.active {
                display: flex;
                animation: slideDownNav 0.3s ease;
            }

            @keyframes slideDownNav {
                from {
                    opacity: 0;
                    transform: translateY(-20px);
                }
                to {
                    opacity: 1;
                    transform: translateY(0);
                }
            }

            .dropdown {
                width: 100%;
            }

            nav a {
                padding: 0.875rem 1.5rem;
                font-size: 0.85rem;
                border-bottom: 1px solid rgba(255,255,255,0.1);
                width: 100%;
                text-align: left;
            }

            .dropdown-content {
                position: static;
                display: none;
                box-shadow: none;
                border-radius: 0;
                background: rgba(0,0,0,0.2);
            }

            .dropdown:hover .dropdown-content {
                display: block;
            }

            .dropdown-content a {
                padding-left: 2rem;
                font-size: 0.8rem;
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
                max-width: 100px;
            }

            nav a {
                padding: 0.75rem 1rem;
                font-size: 0.8rem;
            }

            .dropdown-content a {
                padding-left: 1.5rem;
                font-size: 0.75rem;
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

        /* Hover effects para mobile */
        @media (hover: none) {
            .btn:active {
                transform: scale(0.98);
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

        /* Back button */
        .back-btn {
            display: inline-block;
            margin-bottom: 1rem;
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 600;
            transition: var(--transition);
        }

        .back-btn:hover {
            color: var(--secondary-color);
            transform: translateX(-5px);
        }
    </style>
</head>
<body>
    <header>
        <a href="index.php">
            <img src="../public_html/assets/images/logo_combraz_licitasis.png" alt="Logo LicitaSis" class="logo">
        </a>
        <button class="mobile-menu-toggle" onclick="toggleMobileMenu()">
            <i class="fas fa-bars"></i>
        </button>
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
        <a href="funcionarios.php" class="back-btn">
            <i class="fas fa-arrow-left"></i> Voltar para Funcionários
        </a>

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

        <h2><i class="fas fa-users"></i> Consulta de Funcionários</h2>

        <div class="search-container">
            <form action="consulta_funcionario.php" method="GET">
                <div class="search-bar">
                    <div class="search-group">
                        <label for="search"><i class="fas fa-search"></i> Pesquisar por Nome ou CPF</label>
                        <input type="text" name="search" id="search" 
                               placeholder="Digite o nome ou CPF do funcionário" 
                               value="<?php echo htmlspecialchars($searchTerm); ?>">
                    </div>
                    <button type="submit" class="btn">
                        <i class="fas fa-search"></i> Pesquisar
                    </button>
                </div>
            </form>
            
            <?php if ($searchTerm): ?>
                <div style="margin-top: 1rem;">
                    <a href="consulta_funcionario.php" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Limpar Pesquisa
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <div class="loading" id="loading">
            <div class="spinner"></div>
            <p>Carregando dados...</p>
        </div>

        <?php if (count($funcionarios) > 0): ?>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th><i class="fas fa-user"></i> Nome</th>
                            <th><i class="fas fa-id-card"></i> CPF</th>
                            <th><i class="fas fa-briefcase"></i> Cargo</th>
                            <th><i class="fas fa-venus-mars"></i> Sexo</th>
                            <th><i class="fas fa-phone"></i> Telefone</th>
                            <th><i class="fas fa-cogs"></i> Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($funcionarios as $funcionario): ?>
                            <tr>
                                <td>
                                    <a href="javascript:void(0);" 
                                       onclick="openModal(<?php echo $funcionario['id']; ?>)">
                                        <?php echo htmlspecialchars($funcionario['nome_completo']); ?>
                                    </a>
                                </td>
                                <td><?php echo htmlspecialchars($funcionario['cpf']); ?></td>
                                <td><?php echo htmlspecialchars($funcionario['cargo']); ?></td>
                                <td>
                                    <span class="badge badge-<?php echo strtolower($funcionario['sexo']); ?>">
                                        <?php if($funcionario['sexo'] === 'Masculino'): ?>
                                            <i class="fas fa-mars"></i> M
                                        <?php elseif($funcionario['sexo'] === 'Feminino'): ?>
                                            <i class="fas fa-venus"></i> F
                                        <?php else: ?>
                                            <i class="fas fa-genderless"></i> Outro
                                        <?php endif; ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($funcionario['telefone']); ?></td>
                                <td>
                                    <button onclick="openModal(<?php echo $funcionario['id']; ?>)" 
                                            class="btn btn-primary" title="Editar funcionário">
                                        <i class="fas fa-edit"></i> Editar
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
                <h3>Nenhum funcionário encontrado</h3>
                <?php if ($searchTerm): ?>
                    <p>Tente ajustar sua pesquisa ou <a href="cadastro_funcionario.php">cadastre um novo funcionário</a>.</p>
                <?php else: ?>
                    <p>Nenhum funcionário cadastrado no sistema. <a href="cadastro_funcionario.php">Cadastre o primeiro funcionário</a>.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Modal de Edição -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-user-edit"></i> Editar Funcionário</h3>
                <span class="close" onclick="closeModal()">&times;</span>
            </div>
            <div class="modal-body">
                <form method="POST" action="consulta_funcionario.php" id="editForm">
                    <input type="hidden" name="id" id="funcionario_id">
                    
                    <div class="form-grid">
                        <div class="form-group required">
                            <label for="nome_completo"><i class="fas fa-user"></i> Nome Completo</label>
                            <input type="text" name="nome_completo" id="nome_completo" readonly required>
                        </div>

                        <div class="form-group required">
                            <label for="cargo"><i class="fas fa-briefcase"></i> Cargo</label>
                            <input type="text" name="cargo" id="cargo" readonly required>
                        </div>

                        <div class="form-group required">
                            <label for="cpf"><i class="fas fa-id-card"></i> CPF</label>
                            <input type="text" name="cpf" id="cpf" readonly required>
                        </div>

                        <div class="form-group required">
                            <label for="rg"><i class="fas fa-id-card-alt"></i> RG</label>
                            <input type="text" name="rg" id="rg" readonly required>
                        </div>

                        <div class="form-group required">
                            <label for="telefone"><i class="fas fa-phone"></i> Telefone</label>
                            <input type="text" name="telefone" id="telefone" readonly required>
                        </div>

                        <div class="form-group required">
                            <label for="data_nascimento"><i class="fas fa-calendar-alt"></i> Data de Nascimento</label>
                            <input type="date" name="data_nascimento" id="data_nascimento" readonly required>
                        </div>

                        <div class="form-group required">
                            <label for="sexo"><i class="fas fa-venus-mars"></i> Sexo</label>
                            <select name="sexo" id="sexo" disabled required>
                                <option value="Masculino">Masculino</option>
                                <option value="Feminino">Feminino</option>
                                <option value="Outro">Outro</option>
                            </select>
                        </div>

                        <div class="form-group required">
                            <label for="salario"><i class="fas fa-dollar-sign"></i> Salário</label>
                            <input type="text" name="salario" id="salario" readonly required>
                        </div>

                        <div class="form-group full-width">
                            <label for="endereco"><i class="fas fa-map-marker-alt"></i> Endereço</label>
                            <input type="text" name="endereco" id="endereco" readonly>
                        </div>

                        <div class="form-group">
                            <label for="pai"><i class="fas fa-male"></i> Nome do Pai</label>
                            <input type="text" name="pai" id="pai" readonly>
                        </div>

                        <div class="form-group">
                            <label for="mae"><i class="fas fa-female"></i> Nome da Mãe</label>
                            <input type="text" name="mae" id="mae" readonly>
                        </div>
                    </div>

                    <div class="btn-container">
                        <button type="submit" name="update_funcionario" id="saveBtn" class="btn btn-success" style="display: none;">
                            <i class="fas fa-save"></i> Salvar Alterações
                        </button>
                        <button type="button" class="btn btn-primary" id="editBtn" onclick="enableEditing()">
                            <i class="fas fa-edit"></i> Habilitar Edição
                        </button>
                        <button type="button" class="btn btn-danger" onclick="confirmDelete()">
                            <i class="fas fa-trash"></i> Excluir Funcionário
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
                    <h3 style="color: var(--danger-color); margin-bottom: 1rem;">Deseja realmente excluir este funcionário?</h3>
                    <p style="color: var(--medium-gray); margin-bottom: 2rem;">
                        Esta ação não pode ser desfeita. Todos os dados do funcionário serão permanentemente removidos.
                    </p>
                    <div class="btn-container" style="justify-content: center;">
                        <button type="button" class="btn btn-danger" onclick="deleteFuncionario()">
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
        let currentFuncionarioId = null;

        // Menu mobile
        function toggleMobileMenu() {
            const navContainer = document.getElementById('navContainer');
            const menuToggle = document.querySelector('.mobile-menu-toggle i');
            
            navContainer.classList.toggle('active');
            
            if (navContainer.classList.contains('active')) {
                menuToggle.className = 'fas fa-times';
            } else {
                menuToggle.className = 'fas fa-bars';
            }
        }

        // Função para abrir o modal de edição
        function openModal(id) {
            currentFuncionarioId = id;
            const modal = document.getElementById("editModal");
            modal.style.display = "block";
            document.body.style.overflow = "hidden";

            showLoading();

            fetch('consulta_funcionario.php?get_funcionario_id=' + id)
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        alert('Erro: ' + data.error);
                        closeModal();
                        return;
                    }
                    
                    document.getElementById('funcionario_id').value = data.id;
                    document.getElementById('nome_completo').value = data.nome_completo || '';
                    document.getElementById('cpf').value = data.cpf || '';
                    document.getElementById('rg').value = data.rg || '';
                    document.getElementById('data_nascimento').value = data.data_nascimento || '';
                    document.getElementById('telefone').value = data.telefone || '';
                    document.getElementById('salario').value = data.salario || '';
                    document.getElementById('sexo').value = data.sexo || '';
                    document.getElementById('pai').value = data.pai || '';
                    document.getElementById('mae').value = data.mae || '';
                    document.getElementById('cargo').value = data.cargo || '';
                    document.getElementById('endereco').value = data.endereco || '';
                    hideLoading();
                })
                .catch(error => {
                    console.error('Erro:', error);
                    alert('Erro ao carregar dados do funcionário');
                    hideLoading();
                    closeModal();
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

        // Função para excluir funcionário
        function deleteFuncionario() {
            if (currentFuncionarioId) {
                showLoading();
                window.location.href = 'consulta_funcionario.php?delete_funcionario_id=' + currentFuncionarioId;
            }
        }

        // Funções para fechar modais
        function closeModal() {
            const modal = document.getElementById("editModal");
            modal.style.display = "none";
            document.body.style.overflow = "auto";
            resetEditForm();
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

        // Função para mostrar loading
        function showLoading() {
            document.getElementById('loading').style.display = 'block';
        }

        // Função para esconder loading
        function hideLoading() {
            document.getElementById('loading').style.display = 'none';
        }

        // Formatação de campos
        function formatCPF(input) {
            let cpf = input.value.replace(/\D/g, '');
            if (cpf.length <= 11) {
                cpf = cpf.replace(/(\d{3})(\d)/, '$1.$2');
                cpf = cpf.replace(/(\d{3})(\d)/, '$1.$2');
                cpf = cpf.replace(/(\d{3})(\d{1,2})$/, '$1-$2');
            }
            input.value = cpf;
        }

        function formatRG(input) {
            let rg = input.value.replace(/\D/g, '');
            if (rg.length <= 9) {
                rg = rg.replace(/(\d{2})(\d)/, '$1.$2');
                rg = rg.replace(/(\d{3})(\d)/, '$1.$2');
                rg = rg.replace(/(\d{3})(\d{1,2})$/, '$1-$2');
            }
            input.value = rg;
        }

        function formatTelefone(input) {
            let telefone = input.value.replace(/\D/g, '');
            if (telefone.length <= 11) {
                telefone = telefone.replace(/(\d{2})(\d)/, '($1) $2');
                telefone = telefone.replace(/(\d{4,5})(\d{4})$/, '$1-$2');
            }
            input.value = telefone;
        }

        function formatSalario(input) {
            let valor = input.value.replace(/\D/g, '');
            if (valor.length > 0) {
                valor = valor.replace(/(\d)(\d{2})$/, '$1,$2');
                valor = valor.replace(/(?=(\d{3})+(\D))\B/g, '.');
                valor = 'R$ ' + valor;
            }
            input.value = valor;
        }

        // Fecha modais ao clicar fora
        window.onclick = function(event) {
            const editModal = document.getElementById("editModal");
            const deleteModal = document.getElementById("deleteModal");
            
            if (event.target === editModal) {
                closeModal();
            }
            if (event.target === deleteModal) {
                closeDeleteModal();
            }
        }

        // Validação do formulário
        document.getElementById('editForm').addEventListener('submit', function(e) {
            const nome = document.getElementById('nome_completo').value.trim();
            const cpf = document.getElementById('cpf').value.trim();
            const cargo = document.getElementById('cargo').value.trim();
            
            if (!nome || !cpf || !cargo) {
                e.preventDefault();
                alert('Nome, CPF e cargo são obrigatórios!');
                if (!nome) document.getElementById('nome_completo').focus();
                else if (!cpf) document.getElementById('cpf').focus();
                else document.getElementById('cargo').focus();
                return false;
            }
            
            showLoading();
        });

        // Fecha o menu móvel quando clicar fora dele
        document.addEventListener('click', function(event) {
            const navContainer = document.getElementById('navContainer');
            const menuToggle = document.querySelector('.mobile-menu-toggle');
            
            if (!navContainer.contains(event.target) && !menuToggle.contains(event.target)) {
                navContainer.classList.remove('active');
                document.querySelector('.mobile-menu-toggle i').className = 'fas fa-bars';
            }
        });

        // Fecha o menu móvel ao redimensionar a tela
        window.addEventListener('resize', function() {
            if (window.innerWidth > 768) {
                const navContainer = document.getElementById('navContainer');
                navContainer.classList.remove('active');
                document.querySelector('.mobile-menu-toggle i').className = 'fas fa-bars';
            }
        });

        // Fechar modal com ESC
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeModal();
                closeDeleteModal();
            }
        });

        // Inicialização
        document.addEventListener('DOMContentLoaded', function() {
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
            
            // Adiciona formatação aos campos quando habilitada a edição
            document.getElementById('cpf').addEventListener('input', function() {
                if (!this.hasAttribute('readonly')) {
                    formatCPF(this);
                }
            });
            
            document.getElementById('rg').addEventListener('input', function() {
                if (!this.hasAttribute('readonly')) {
                    formatRG(this);
                }
            });
            
            document.getElementById('telefone').addEventListener('input', function() {
                if (!this.hasAttribute('readonly')) {
                    formatTelefone(this);
                }
            });
            
            document.getElementById('salario').addEventListener('input', function() {
                if (!this.hasAttribute('readonly')) {
                    formatSalario(this);
                }
            });

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

            console.log('Sistema de Consulta de Funcionários carregado com sucesso!');
        });
    </script>
</body>
</html>