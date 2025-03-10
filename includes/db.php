<?php
// db.php - Conexão com o banco de dados MySQL usando PDO

$servername = "localhost";
$username = "root"; 
$password = "";  
$dbname = "combraz";  

try {
    // Cria a conexão PDO com o banco de dados
    $pdo = new PDO("mysql:host=$servername;dbname=$dbname;charset=utf8", $username, $password);
    
    // Define o modo de erro como exceções
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Verifica se a conexão foi bem-sucedida
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}
?>
