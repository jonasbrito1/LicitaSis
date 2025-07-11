<?php
require_once('db.php');

// Verifica se o CPF foi passado
if (isset($_GET['cpf'])) {
    $cpf = $_GET['cpf'];

    try {
        // Primeiro, tenta encontrar o CPF no banco de dados
        $sql = "SELECT nome_completo, pai, mae, rg, endereco, data_nascimento, sexo FROM funcionarios WHERE cpf = :cpf LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':cpf', $cpf, PDO::PARAM_STR);
        $stmt->execute();
        $funcionario = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($funcionario) {
            // Se encontrar no banco, retorna os dados
            echo json_encode($funcionario);
        } else {
            // Caso o CPF não esteja no banco, consulta a API externa
            $url = "https://www.receitaws.com.br/v1/cpf/{$cpf}";
            $response = file_get_contents($url);  // Faz a requisição à API pública
            $data = json_decode($response, true);

            // Verifica se a resposta é válida
            if (isset($data['nome'])) {
                // Se a API retornar os dados, mostra os resultados
                echo json_encode([
                    'nome_completo' => $data['nome'],
                    'pai' => '',  // Não há informação sobre o pai na API pública
                    'mae' => '',  // Não há informação sobre a mãe na API pública
                    'rg' => '',  // Não há informação sobre o RG na API pública
                    'endereco' => '',  // Não há informação sobre o endereço na API pública
                    'data_nascimento' => $data['nascimento'],  // A data de nascimento
                    'sexo' => '',  // Não há informação sobre o sexo na API pública
                ]);
            } else {
                // Se não encontrar o CPF na API pública
                echo json_encode(['error' => 'CPF não encontrado']);
            }
        }
    } catch (Exception $e) {
        echo json_encode(['error' => 'Erro ao buscar CPF']);
    }
}
?>
