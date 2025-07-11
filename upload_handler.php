<?php
// ===========================================
// ARQUIVO: upload_handler.php
// Gerencia upload de arquivos do empenho
// ===========================================
?>
<?php
session_start();

if (!isset($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autorizado']);
    exit();
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['upload'])) {
    $file = $_FILES['upload'];
    
    // Validações
    $allowedExtensions = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];
    $maxFileSize = 10 * 1024 * 1024; // 10MB
    
    $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['error' => 'Erro no upload do arquivo']);
        exit();
    }
    
    if (!in_array($fileExtension, $allowedExtensions)) {
        echo json_encode(['error' => 'Formato de arquivo não permitido']);
        exit();
    }
    
    if ($file['size'] > $maxFileSize) {
        echo json_encode(['error' => 'Arquivo muito grande (máx. 10MB)']);
        exit();
    }
    
    // Diretório de upload
    $uploadDir = 'uploads/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }
    
    // Nome único para o arquivo
    $fileName = time() . '_' . uniqid() . '.' . $fileExtension;
    $targetPath = $uploadDir . $fileName;
    
    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        echo json_encode([
            'success' => true,
            'filename' => $fileName,
            'original_name' => $file['name'],
            'size' => $file['size'],
            'url' => $targetPath
        ]);
    } else {
        echo json_encode(['error' => 'Falha ao salvar arquivo']);
    }
} else {
    echo json_encode(['error' => 'Nenhum arquivo enviado']);
}
?>