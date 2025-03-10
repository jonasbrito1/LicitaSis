<?php
session_start();
session_unset();  // Remove todas as variáveis de sessão
session_destroy();  // Destrói a sessão
header("Location: ../includes/login.php");  // Redireciona para o login
exit();
?>
