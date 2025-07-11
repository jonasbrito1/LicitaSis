<?php
// Função PHP para realizar scraping da UASG no ComprasNet
function consultarUASG($codigoUasg) {
    $url = "http://comprasnet.gov.br/livre/uasg/index.htm?codigo=$codigoUasg";

    // Inicializa o cURL
    $ch = curl_init();

    // Configurações do cURL
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Desabilita a verificação SSL

    // Executa a requisição
    $response = curl_exec($ch);
    curl_close($ch);

    // Verifica se a requisição foi bem-sucedida
    if (!$response) {
        return false;
    }

    // Verificar se a página contém dados de UASG válidos
    if (strpos($response, 'Nome da Unidade Gestora') === false) {
        return false; // Se não encontrou o nome da unidade gestora, retorna erro
    }

    // Utiliza expressão regular para extrair os dados da UASG
    preg_match('/<td><b>Nome da Unidade Gestora<\/b><\/td><td>(.*?)<\/td>/', $response, $matches);
    $nomeUasg = $matches[1] ?? null;

    preg_match('/<td><b>CNPJ<\/b><\/td><td>(.*?)<\/td>/', $response, $matches);
    $cnpj = $matches[1] ?? null;

    // Outros campos podem ser extraídos conforme necessário, como telefone e email
    preg_match('/<td><b>Telefone<\/b><\/td><td>(.*?)<\/td>/', $response, $matches);
    $telefone = $matches[1] ?? null;

    preg_match('/<td><b>E-mail<\/b><\/td><td>(.*?)<\/td>/', $response, $matches);
    $email = $matches[1] ?? null;

    // Monta o array com os dados da UASG
    $dados = [
        'nomeUasg' => $nomeUasg,
        'cnpj' => $cnpj,
        'telefone' => $telefone,
        'email' => $email
    ];

    return $dados;
}

// Verifica se o código da UASG foi enviado via GET
if (isset($_GET['codigoUasg'])) {
    $codigoUasg = $_GET['codigoUasg'];
    $dadosUasg = consultarUASG($codigoUasg);

    // Retorna os dados ou um erro em formato JSON
    if ($dadosUasg) {
        echo json_encode($dadosUasg);
    } else {
        echo json_encode(['status' => 'erro', 'message' => 'UASG não encontrada ou inválida.']);
    }
}
?>
