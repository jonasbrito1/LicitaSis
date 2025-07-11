<?php
// Função para verificar se o usuário existe no banco de dados
function checkLogin($username, $password, $pdo) {
    // Prepara a consulta SQL para procurar o usuário no banco de dados
    $sql = "SELECT * FROM users WHERE username = :username LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':username', $username);
    $stmt->execute();
    
    // Verifica se um usuário com esse nome existe
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Se o usuário existir, verifica se a senha corresponde
    if ($user) {
        // Verifica a senha, se for correta
        if (password_verify($password, $user['password'])) {
            return $user; // Retorna os dados do usuário
        }
    }
    return false; // Retorna falso caso o login falhe
}
?>
