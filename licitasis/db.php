<?php
// db.php - Conexão com o banco de dados MySQL usando PDO

$servername = "db";  // Nome do serviço no docker-compose
$username = "root";  
$password = "root";  // Senha definida no docker-compose
$dbname = "combraz";  // Nome do banco de dados definido no docker-compose

try {
    // Cria a conexão PDO com o banco de dados
    $pdo = new PDO("mysql:host=$servername;dbname=$dbname;charset=utf8", $username, $password);
    
    // Define o modo de erro como exceções
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Conexão falhou: " . $e->getMessage());
}
?>
