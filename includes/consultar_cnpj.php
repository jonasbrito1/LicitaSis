<?php
// Função PHP para realizar a consulta de CNPJ
function consultarCNPJ($cnpj) {
    $url = "https://www.receitaws.com.br/v1/cnpj/$cnpj";

    // Faz a requisição ao servidor externo (API da Receita Federal)
    $response = file_get_contents($url);
    
    if ($response === FALSE) {
        return false; // Retorna false se a requisição falhar
    }

    $data = json_decode($response, true); // Converte a resposta JSON para array associativo

    // Verifica se a consulta foi bem-sucedida
    if ($data['status'] === 'OK') {
        return $data;
    }

    return false;
}

// Checa se o CNPJ foi enviado via GET
if (isset($_GET['cnpj'])) {
    $cnpj = $_GET['cnpj'];
    $cnpj_info = consultarCNPJ($cnpj);
    
    // Retorna os dados ou um erro em formato JSON
    if ($cnpj_info) {
        echo json_encode($cnpj_info);
    } else {
        echo json_encode(['error' => 'CNPJ não encontrado ou inválido.']);
    }
}
?>
