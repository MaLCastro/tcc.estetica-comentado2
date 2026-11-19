<?php
// ============================================================
// 1. INICIAR SESSÃO, CONEXÃO E VALIDAR ACESSO
// ============================================================

// Inicia a sessão para acessar os dados da cliente autenticada.
session_start();

// Carrega o arquivo responsável pela conexão PDO com o banco de dados.
require_once("conexao.php");

// Define o fuso horário utilizado pelo sistema.
date_default_timezone_set("America/Sao_Paulo");

// Permite acesso somente para usuários autenticados como clientes.
if (
    !isset($_SESSION["usuario_id"]) ||
    ($_SESSION["usuario_tipo"] ?? "") !== "cliente"
) {
    // Redireciona usuários que não possuem permissão para a tela de login.
    header("Location: login.php");

    // Interrompe a execução da página após o redirecionamento.
    exit();
}

// Recupera o ID da cliente armazenado na sessão.
$cliente_id = (int) $_SESSION["usuario_id"];

// Recupera o nome da cliente para personalizar a página.
$nome_cliente = $_SESSION["usuario_nome"] ?? "Cliente";

// Inicializa a lista que armazenará os próximos agendamentos.
$proximos_agendamentos = [];

// Inicializa a lista que armazenará o histórico de atendimentos.
$historico_agendamentos = [];

// Indica inicialmente que não ocorreu erro na consulta dos agendamentos.
$erro_agendamentos = false;

// Define o fuso horário utilizado nas comparações de datas e horários.
$fuso_horario = new DateTimeZone("America/Sao_Paulo");

// ============================================================
// 2. CRIAR TOKEN DE SEGURANÇA PARA CANCELAMENTO
// ============================================================

// Verifica se a sessão ainda não possui um token de cancelamento.
if (empty($_SESSION["token_cancelamento"])) {

    // Gera um token aleatório para validar solicitações de cancelamento.
    $_SESSION["token_cancelamento"] = bin2hex(random_bytes(32));
}

// ============================================================
// 3. PROCESSAR O CANCELAMENTO DO AGENDAMENTO
// ============================================================

// Inicializa a variável que armazenará a mensagem de retorno da operação.
$mensagem_agendamento = null;

// Verifica se a requisição foi enviada pelo método POST
// e se corresponde a uma solicitação de cancelamento.
if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST["cancelar_agendamento"])
) {
    // Recupera o token enviado pelo formulário de cancelamento.
    $token_recebido = $_POST["token_cancelamento"] ?? "";

    // Compara o token recebido com o token armazenado na sessão.
    if (
        !is_string($token_recebido) ||
        !hash_equals($_SESSION["token_cancelamento"], $token_recebido)
    ) {
        // Armazena uma mensagem de erro na sessão para ser exibida após o redirecionamento.
        $_SESSION["mensagem_agendamento"] = [
            "tipo" => "erro",
            "texto" => "Não foi possível validar a solicitação. Atualize a página e tente novamente."
        ];

        // Retorna para a página de agendamentos.
        header("Location: meus_agendamentos.php");

        // Interrompe a execução após o redirecionamento.
        exit();
    }

    // Recupera e valida o ID do agendamento enviado.
    $agendamento_id = filter_input(
        INPUT_POST,
        "agendamento_id",
        FILTER_VALIDATE_INT
    );

    // Verifica se o identificador recebido é válido.
    if (!$agendamento_id || $agendamento_id < 1) {
        // Armazena uma mensagem informando que o agendamento é inválido.
        $_SESSION["mensagem_agendamento"] = [
            "tipo" => "erro",
            "texto" => "O agendamento informado é inválido."
        ];

        // Retorna para a página de agendamentos.
        header("Location: meus_agendamentos.php");

        // Interrompe a execução.
        exit();
    }

    try {
        // Consulta o agendamento para confirmar que ele pertence à cliente autenticada.
        $sql_validar = "
            SELECT
                id,
                data_agendamento,
                horario,
                status
            FROM agendamentos
            WHERE id = :id
              AND cliente_id = :cliente_id
            LIMIT 1
        ";

        // Prepara a consulta utilizando a conexão PDO.
        $consulta_validar = $conexao->prepare($sql_validar);

        // Executa a consulta utilizando os parâmetros informados.
        $consulta_validar->execute([
            ":id" => $agendamento_id,
            ":cliente_id" => $cliente_id
        ]);

        // Recupera o registro encontrado.
        $registro = $consulta_validar->fetch(PDO::FETCH_ASSOC);

        // Verifica se o agendamento foi encontrado e pertence à cliente.
        if (!$registro) {
            // Armazena uma mensagem informando que o agendamento não foi localizado.
            $_SESSION["mensagem_agendamento"] = [
                "tipo" => "erro",
                "texto" => "Agendamento não encontrado."
            ];

            // Retorna para a página de agendamentos.
            header("Location: meus_agendamentos.php");

            // Interrompe a execução.
            exit();
        }

        // Normaliza o status atual removendo espaços e convertendo para letras minúsculas.
        $status_atual = strtolower(
            trim((string) $registro["status"])
        );

        // Verifica se o status permite que a cliente faça o cancelamento.
        if (!in_array(
            $status_atual,
            ["pendente", "confirmado", "alterado"],
            true
        )) {
            // Armazena uma mensagem informando que o cancelamento não é permitido.
            $_SESSION["mensagem_agendamento"] = [
                "tipo" => "erro",
                "texto" => "Este agendamento não pode mais ser cancelado."
            ];

            // Retorna para a página de agendamentos.
            header("Location: meus_agendamentos.php");

            // Interrompe a execução.
            exit();
        }

        // Recupera a data do agendamento diretamente do banco de dados.
        $data_banco = (string) $registro["data_agendamento"];

        // Recupera o horário e mantém somente hora e minuto.
        $horario_banco = substr(
            trim((string) $registro["horario"]),
            0,
            5
        );

        // Cria um objeto de data e hora utilizando a data e o horário do atendimento.
        $data_hora_agendamento = DateTime::createFromFormat(
            "!Y-m-d H:i",
            $data_banco . " " . $horario_banco,
            $fuso_horario
        );

        // Verifica se a data e o horário formam uma combinação válida.
        $data_valida = (
            $data_hora_agendamento !== false &&
            $data_hora_agendamento->format("Y-m-d H:i") ===
                $data_banco . " " . $horario_banco
        );

        // Obtém a data e o horário atuais para comparação.
        $agora = new DateTime("now", $fuso_horario);

        // Impede o cancelamento quando o horário já passou ou é inválido.
        if (
            !$data_valida ||
            $data_hora_agendamento <= $agora
        ) {
            // Armazena uma mensagem informando que o cancelamento não pode ser realizado.
            $_SESSION["mensagem_agendamento"] = [
                "tipo" => "erro",
                "texto" => "Não é possível cancelar um atendimento cujo horário já passou ou é inválido."
            ];

            // Retorna para a página de agendamentos.
            header("Location: meus_agendamentos.php");

            // Interrompe a execução.
            exit();
        }

        // Monta a consulta responsável por alterar o status do agendamento.
        $sql_cancelar = "
            UPDATE agendamentos
            SET status = 'cancelado'
            WHERE id = :id
              AND cliente_id = :cliente_id
              AND status IN ('pendente', 'confirmado', 'alterado')
        ";

        // Prepara a consulta de atualização.
        $consulta_cancelar = $conexao->prepare($sql_cancelar);

        // Executa a atualização utilizando o ID do agendamento e da cliente.
        $consulta_cancelar->execute([
            ":id" => $agendamento_id,
            ":cliente_id" => $cliente_id
        ]);

        // Verifica se alguma linha foi realmente alterada.
        if ($consulta_cancelar->rowCount() > 0) {
            // Armazena uma mensagem de sucesso.
            $_SESSION["mensagem_agendamento"] = [
                "tipo" => "sucesso",
                "texto" => "Agendamento cancelado com sucesso."
            ];
        } else {
            // Armazena uma mensagem informando que a alteração não ocorreu.
            $_SESSION["mensagem_agendamento"] = [
                "tipo" => "erro",
                "texto" => "O agendamento não foi alterado. Atualize a página e tente novamente."
            ];
        }

    } catch (PDOException $erro) {
        // Registra o erro técnico no log do servidor.
        error_log(
            "Erro ao cancelar agendamento: " .
            $erro->getMessage()
        );

        // Exibe para a cliente apenas uma mensagem genérica de erro.
        $_SESSION["mensagem_agendamento"] = [
            "tipo" => "erro",
            "texto" => "Ocorreu um erro ao cancelar o agendamento. Tente novamente."
        ];
    }

    // Redireciona para atualizar a página e evitar o reenvio do formulário.
    header("Location: meus_agendamentos.php");

    // Interrompe a execução.
    exit();
}

// Recupera a mensagem armazenada na sessão após o cancelamento.
$mensagem_agendamento = $_SESSION["mensagem_agendamento"] ?? null;

// Remove a mensagem da sessão depois de recuperá-la.
unset($_SESSION["mensagem_agendamento"]);

// ============================================================
// 4. BUSCAR OS AGENDAMENTOS DA CLIENTE
// ============================================================

// Obtém a data e o horário atuais utilizando o fuso definido anteriormente.
$data_hora_atual = new DateTime("now", $fuso_horario);

try {
    // Consulta os agendamentos da cliente juntamente com os dados
    // do serviço e da profissional.
    
$sql_agendamentos = "
SELECT
    a.id,
    a.data_agendamento AS data,
    a.horario,
    a.status,
    COALESCE(a.preco_final, s.preco) AS preco,
    s.nome_servico AS servico,
    u.nome AS profissional,
    u.chave_pix AS chave_pix,
    u.tipo_chave_pix AS tipo_chave_pix
FROM agendamentos AS a
LEFT JOIN servicos AS s
    ON s.id = a.servico_id
LEFT JOIN usuarios AS u
    ON u.id = a.profissional_id
WHERE a.cliente_id = :cliente_id
ORDER BY a.data_agendamento ASC, a.horario ASC
";


    // Prepara a consulta utilizando a conexão PDO.
    $consulta_agendamentos = $conexao->prepare($sql_agendamentos);

    // Executa a consulta buscando somente os registros da cliente autenticada.
    $consulta_agendamentos->execute([
        ":cliente_id" => $cliente_id
    ]);

    // Recupera todos os registros retornados pela consulta.
    $agendamentos = $consulta_agendamentos->fetchAll(PDO::FETCH_ASSOC);

    // Percorre todos os agendamentos encontrados.
    foreach ($agendamentos as $agendamento) {
        // Normaliza o status atual para facilitar as comparações.
        $status_atual = strtolower(
            trim((string) ($agendamento["status"] ?? ""))
        );

        // Recupera a data armazenada no registro.
        $data = trim((string) ($agendamento["data"] ?? ""));

        // Recupera o horário mantendo somente hora e minuto.
        $horario = substr(
            trim((string) ($agendamento["horario"] ?? "")),
            0,
            5
        );

        // Cria um objeto DateTime com a data e o horário do atendimento.
        $data_hora_agendamento = DateTime::createFromFormat(
            "!Y-m-d H:i",
            $data . " " . $horario,
            $fuso_horario
        );

        // Confere se a data criada corresponde exatamente ao valor recebido.
        $data_valida = (
            $data_hora_agendamento !== false &&
            $data_hora_agendamento->format("Y-m-d H:i") ===
                $data . " " . $horario
        );

        // Verifica se o atendimento está concluído ou cancelado.
        if (in_array(
            $status_atual,
            ["concluido", "cancelado"],
            true
        )) {
            // Adiciona o registro diretamente ao histórico.
            $historico_agendamentos[] = $agendamento;

            // Continua para o próximo registro.
            continue;
        }

        // Verifica se o atendimento ainda é futuro ou está no momento atual.
        if (
            $data_valida &&
            $data_hora_agendamento >= $data_hora_atual
        ) {
            // Adiciona o registro à lista de próximos agendamentos.
            $proximos_agendamentos[] = $agendamento;
        } else {
            // Registros passados ou com data inválida são enviados para o histórico.
            $historico_agendamentos[] = $agendamento;
        }
    }

    // Ordena os próximos agendamentos do mais próximo para o mais distante.
    usort($proximos_agendamentos, function ($a, $b) {
        // Monta a data completa do primeiro registro.
        $data_a = ($a["data"] ?? "") . " " . ($a["horario"] ?? "");

        // Monta a data completa do segundo registro.
        $data_b = ($b["data"] ?? "") . " " . ($b["horario"] ?? "");

        // Compara as datas em ordem crescente.
        return strcmp($data_a, $data_b);
    });

    // Ordena o histórico do registro mais recente para o mais antigo.
    usort($historico_agendamentos, function ($a, $b) {
        // Monta a data completa do primeiro registro.
        $data_a = ($a["data"] ?? "") . " " . ($a["horario"] ?? "");

        // Monta a data completa do segundo registro.
        $data_b = ($b["data"] ?? "") . " " . ($b["horario"] ?? "");

        // Compara as datas em ordem decrescente.
        return strcmp($data_b, $data_a);
    });

} catch (PDOException $erro) {
    // Registra no log o erro ocorrido durante a consulta.
    error_log(
        "Erro ao carregar agendamentos da cliente: " .
        $erro->getMessage()
    );

    // Informa ao restante da página que ocorreu uma falha na consulta.
    $erro_agendamentos = true;
}

// ============================================================
// 5. FUNÇÕES DE FORMATAÇÃO E SEGURANÇA
// ============================================================

// Cria uma função para proteger textos exibidos no HTML.
function escaparTexto($texto)
{
    // Converte caracteres especiais para entidades HTML.
    return htmlspecialchars(
        (string) $texto,
        ENT_QUOTES,
        "UTF-8"
    );
}

// Cria uma função para transformar a data do banco
// no formato utilizado na interface do sistema.
function formatarDataAgendamento($data)
{
    // Garante que o valor recebido seja tratado como texto.
    $data = (string) $data;

    // Converte a data do formato do banco para um objeto DateTime.
    $data_objeto = DateTime::createFromFormat("!Y-m-d", $data);

    // Verifica se a data foi criada corretamente.
    if (
        !$data_objeto ||
        $data_objeto->format("Y-m-d") !== $data
    ) {
        // Retorna uma mensagem caso a data seja inválida.
        return "Data não informada";
    }

    // Retorna a data no padrão brasileiro.
    return $data_objeto->format("d/m/Y");
}

// Cria uma função para transformar o status do banco
// em um texto amigável e uma classe CSS.
function apresentarStatus($status)
{
    // Normaliza o status recebido para facilitar as comparações.
    $status = strtolower(trim((string) $status));

    // Define os textos que serão apresentados para cada status.
    $status_conhecidos = [
        "pendente" => "Pendente",
        "confirmado" => "Confirmado",
        "concluido" => "Concluído",
        "cancelado" => "Cancelado",
        "alterado" => "Alterado"
    ];

    // Define as classes CSS correspondentes a cada status.
    $classes_status = [
        "pendente" => "status-pendente",
        "confirmado" => "status-confirmado",
        "concluido" => "status-concluido",
        "cancelado" => "status-cancelado",
        "alterado" => "status-alterado"
    ];

    // Retorna o texto e a classe visual correspondentes ao status.
    return [
        "texto" => $status_conhecidos[$status] ?? "Em análise",
        "classe" => $classes_status[$status] ?? "status-indefinido"
    ];
}
?>

<!DOCTYPE html>
<!-- Informa ao navegador que o documento utiliza HTML5. -->

<html lang="pt-BR">
<!-- Define o português do Brasil como idioma principal da página. -->

<head>
    <!-- Início das configurações da página. -->

    <!-- Define a codificação utilizada pelo documento. -->
    <meta charset="UTF-8">

    <!-- Permite que o layout se adapte a diferentes tamanhos de tela. -->
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- Define o título apresentado na aba do navegador. -->
    <title>Meus Agendamentos | TCC Estética</title>

    <!-- Carrega o arquivo CSS externo compartilhado pelo projeto. -->
    <link rel="stylesheet" href="style.css">
</head>

<body class="pagina-cliente pagina-meus-agendamentos">
    <!--
        Corpo da página.

        As classes identificam a área da cliente
        e permitem aplicar estilos específicos à página
        de meus agendamentos.
    -->

    <!-- ====================================================== -->
    <!-- 6. CABEÇALHO E NAVEGAÇÃO DA CLIENTE -->
    <!-- ====================================================== -->

    <header class="cabecalho-cliente">
        <!-- Cabeçalho principal da área da cliente. -->

        <div class="logo-cliente">
            <!-- Área destinada à identificação visual do sistema. -->

            <h1>Estética</h1>
            <!-- Nome apresentado no cabeçalho. -->

        </div>

        <!-- Mantém o menu padrão da área da cliente. -->
        <nav class="menu-cliente" aria-label="Navegação principal">
            <!--
                Menu principal da cliente.

                O atributo aria-label identifica a finalidade
                do menu para tecnologias assistivas.
            -->

            <a href="painel_cliente.php">Início</a>
            <!-- Link que direciona para o painel inicial da cliente. -->

            <a href="servicos.php">Serviços</a>
            <!-- Link que direciona para a página de serviços. -->

            <a
                href="meus_agendamentos.php"
                class="ativo"
                aria-current="page"
            >Meus Agendamentos</a>
            <!--
                Link da página atual.

                A classe "ativo" permite destacar visualmente
                a página selecionada no menu.

                aria-current informa que este é o item correspondente
                à página atualmente aberta.
            -->

            <a href="perfil_cliente.php">Meu Perfil</a>
            <!-- Link que direciona para o perfil da cliente. -->

            <a href="logout.php" class="menu-sair">Sair</a>
            <!-- Link utilizado para encerrar a sessão da cliente. -->

        </nav>
    </header>

    <!-- ====================================================== -->
    <!-- 7. CONTEÚDO PRINCIPAL -->
    <!-- ====================================================== -->

    <main class="meus-agendamentos-container">
        <!--
            Área principal da página.

            Dentro dela são apresentados os próximos agendamentos,
            o histórico e as mensagens relacionadas às operações.
        -->

        <!-- Apresenta o resultado da última operação. -->
        <?php if ($mensagem_agendamento): ?>
            <!--
                Verifica se existe uma mensagem gerada
                por alguma operação de cancelamento.
            -->

            <div
                class="mensagem-agendamento <?= $mensagem_agendamento["tipo"] === "sucesso" ? "mensagem-sucesso" : "mensagem-erro" ?>"
                role="status"
            >
                <!--
                    Define a classe da mensagem conforme o resultado.

                    "mensagem-sucesso" é utilizada quando a operação
                    foi realizada corretamente.

                    "mensagem-erro" é utilizada quando ocorreu uma falha.
                -->

                <?= escaparTexto($mensagem_agendamento["texto"]) ?>
                <!-- Exibe a mensagem protegendo o conteúdo contra HTML. -->

            </div>

        <?php endif; ?>
        <!-- Final da verificação da mensagem. -->

        <!-- Apresentação da página. -->
        <section class="agendamentos-introducao">
            <!-- Área introdutória da página de agendamentos. -->

            <span class="agendamentos-etiqueta">ÁREA DA CLIENTE</span>
            <!-- Identificação da área de acesso da cliente. -->

            <h2>Meus Agendamentos</h2>
            <!-- Título principal da página. -->

            <p>
                Olá, <?= escaparTexto($nome_cliente) ?>!
                Consulte seus próximos atendimentos e confira
                o histórico dos serviços realizados.
            </p>
            <!--
                Apresenta uma mensagem personalizada utilizando
                o nome armazenado na sessão da cliente.
            -->

        </section>

        <?php if ($erro_agendamentos): ?>
            <!--
                Verifica se ocorreu algum erro durante a consulta
                dos agendamentos no banco de dados.
            -->

            <!-- Apresenta uma mensagem se a consulta falhar. -->
            <div class="agendamentos-vazio" role="alert">
                <!-- Área visual destinada à mensagem de erro. -->

                <h4>Não foi possível carregar seus agendamentos</h4>
                <!-- Título apresentado quando ocorre falha na consulta. -->

                <p>
                    Ocorreu um problema ao consultar os registros.
                    Atualize a página ou tente novamente mais tarde.
                </p>
                <!-- Orientação apresentada para a cliente. -->

            </div>

        <?php else: ?>
            <!--
                Caso não tenha ocorrido erro,
                continua exibindo os agendamentos encontrados.
            -->

            <!-- ============================================== -->
            <!-- 8. PRÓXIMOS AGENDAMENTOS -->
            <!-- ============================================== -->

            <section
                class="secao-agendamentos"
                id="proximos-agendamentos"
            >
                <!--
                    Seção responsável por apresentar os atendimentos
                    que ainda estão previstos para acontecer.
                -->

                <div class="agendamentos-titulo-secao">
                    <!-- Cabeçalho da seção de próximos agendamentos. -->

                    <div>
                        <!-- Agrupa o título e a descrição da seção. -->

                        <h3>Próximos agendamentos</h3>
                        <!-- Título da seção. -->

                        <p>
                            Confira os detalhes dos seus próximos atendimentos.
                        </p>
                        <!-- Descrição da seção. -->

                    </div>

                    <!-- Contador atualizado conforme os registros encontrados. -->
                    <span class="agendamentos-contador">
                        <?= count($proximos_agendamentos) ?>
                    </span>
                    <!--
                        Conta a quantidade de próximos agendamentos
                        encontrados para a cliente.
                    -->

                </div>

                <?php if (!empty($proximos_agendamentos)): ?>
                    <!--
                        Verifica se existe pelo menos um próximo agendamento.
                    -->

                    <div class="lista-agendamentos">
                        <!-- Container que organiza os cartões de agendamento. -->

                        <?php foreach ($proximos_agendamentos as $agendamento): ?>
                            <!--
                                Percorre cada próximo agendamento.

                                A variável $agendamento representa
                                o registro atual durante o loop.
                            -->

                            <!-- Cartão individual do próximo atendimento. -->
                            <article class="cartao-agendamento">
                                <!--
                                    Cada agendamento é apresentado
                                    como um cartão independente.
                                -->

                                <div class="agendamento-data">
                                    <!-- Área que apresenta a data e o horário. -->

                                    <span>Data do atendimento</span>
                                    <!-- Identificação da data apresentada. -->

                                    <strong>
                                        <?= escaparTexto(
                                            formatarDataAgendamento(
                                                $agendamento["data"]
                                            )
                                        ) ?>
                                    </strong>
                                    <!--
                                        Formata a data recebida do banco
                                        para o padrão brasileiro.
                                    -->

                                    <span class="agendamento-horario">
                                        <?= escaparTexto(
                                            $agendamento["horario"] ?? "--:--"
                                        ) ?>
                                    </span>
                                    <!--
                                        Apresenta o horário do atendimento.

                                        Caso o valor não exista,
                                        apresenta "--:--".
                                    -->

                                </div>

                                <div class="agendamento-detalhes">
                                    <!-- Área que apresenta as informações do atendimento. -->

                                    <h4>
                                        <?= escaparTexto(
                                            $agendamento["servico"] ?? "Serviço"
                                        ) ?>
                                    </h4>
                                    <!-- Apresenta o nome do serviço agendado. -->

                                    <p>
                                        <strong>Profissional:</strong>
                                        <?= escaparTexto(
                                            $agendamento["profissional"] ??
                                            "Não informada"
                                        ) ?>
                                    </p>
                                    <!-- Apresenta o nome da profissional responsável. -->

                                    <p>
                                        <strong>Valor:</strong>
                                        <!-- Identifica o valor do serviço. -->

                                        <?php if ($agendamento["preco"] !== null): ?>
                                            <!--
                                                Verifica se existe um preço
                                                definido para o agendamento.
                                            -->

                                            R$
                                            <!-- Símbolo utilizado para representar o Real brasileiro. -->

                                            <?= number_format(
                                                (float) $agendamento["preco"],
                                                2,
                                                ",",
                                                "."
                                            ) ?>
                                            <!--
                                                Formata o valor com duas casas decimais
                                                e separadores no padrão brasileiro.
                                            -->

                                        <?php else: ?>
                                            <!-- Executado quando o preço não está definido. -->

                                            A confirmar
                                            <!-- Indica que o valor ainda precisa ser confirmado. -->

                                        <?php endif; ?>
                                        <!-- Final da verificação do preço. -->

                                    </p>
                                    </p>

                                    <!-- ================================================== -->
                                    <!-- DADOS PIX DA PROFISSIONAL -->
                                    <!-- ================================================== -->

                                    <?php if (!empty(trim((string) ($agendamento["chave_pix"] ?? "")))): ?>
                                        <!--
                                            Verifica se a profissional possui
                                            uma chave Pix cadastrada.

                                            trim() remove espaços adicionais.
                                            empty() verifica se existe algum conteúdo.
                                        -->

                                        <div class="agendamento-pix">
                                            <!-- Área destinada às informações de pagamento via Pix. -->

                                            <p>
                                                <strong>Pagamento via Pix</strong>
                                            </p>
                                            <!-- Identifica a forma de pagamento apresentada. -->

                                            <p>
                                                <strong>Tipo de chave:</strong>
                                                <?= escaparTexto(
                                                    !empty($agendamento["tipo_chave_pix"])
                                                        ? $agendamento["tipo_chave_pix"]
                                                        : "Não informado"
                                                ) ?>
                                            </p>
                                            <!--
                                                Apresenta o tipo da chave Pix.

                                                Caso o tipo não esteja informado,
                                                exibe "Não informado".
                                            -->

                                            <p>
                                                <strong>Chave Pix:</strong>
                                                <span class="chave-pix">
                                                    <?= escaparTexto($agendamento["chave_pix"]) ?>
                                                </span>
                                            </p>
                                            <!-- Exibe a chave Pix cadastrada pela profissional. -->

                                        </div>

                                    <?php endif; ?>
                                    <!-- Final da verificação da chave Pix. -->

                                    <!-- Exibe o status atual do atendimento. -->
                                    <?php
                                    // Converte o status armazenado no banco
                                    // para o texto e a classe visual correspondentes.
                                    $status = apresentarStatus(
                                        $agendamento["status"] ?? ""
                                    );
                                    ?>

                                    <span class="agendamento-status <?= escaparTexto($status["classe"]) ?>">
                                        <?= escaparTexto($status["texto"]) ?>
                                    </span>
                                    <!--
                                        Exibe o status do agendamento
                                        utilizando a classe CSS correspondente.
                                    -->

                                </div>

                                <!-- Mostra o botão somente para status canceláveis. -->
                                <?php
                                // Recupera e normaliza o status atual
                                // para verificar se o cancelamento é permitido.
                                $status_botao = strtolower(
                                    trim((string) ($agendamento["status"] ?? ""))
                                );
                                ?>

                                <?php if (in_array(
                                    $status_botao,
                                    ["pendente", "confirmado", "alterado"],
                                    true
                                )): ?>
                                    <!--
                                        O botão de cancelamento aparece somente
                                        para os status que ainda permitem essa ação.
                                    -->

                                    <div class="agendamento-acoes">
                                        <!-- Área destinada às ações disponíveis. -->

                                        <!--
                                            Encaminha a cliente para a tela de confirmação.
                                            O ID identifica o agendamento selecionado.
                                        -->
                                        <a
                                            href="confirmar_cancelamento.php?id=<?= (int) $agendamento["id"] ?>"
                                            class="btn-cancelar-agendamento"
                                        >
                                            Cancelar agendamento
                                        </a>
                                        <!-- Link utilizado para iniciar o cancelamento. -->

                                    </div>

                                <?php endif; ?>
                                <!-- Final da condição dos status canceláveis. -->

                            </article>
                            <!-- Final do cartão do agendamento. -->

                        <?php endforeach; ?>
                        <!-- Final do loop dos próximos agendamentos. -->

                    </div>

                <?php else: ?>
                    <!--
                        Executado quando não existe nenhum
                        próximo agendamento para a cliente.
                    -->

                    <!-- Mensagem apresentada quando não há próximos atendimentos. -->
                    <div class="agendamentos-vazio">
                        <!-- Área visual para representar uma lista vazia. -->

                        <h4>Nenhum próximo agendamento</h4>
                        <!-- Título apresentado quando não existem próximos atendimentos. -->

                        <p>
                            Quando você realizar um agendamento,
                            os detalhes aparecerão nesta seção.
                        </p>
                        <!-- Explica à cliente quando os registros aparecerão. -->

                        <a href="servicos.php" class="agendamentos-botao">
                            Conhecer serviços
                        </a>
                        <!-- Link para a página de serviços disponíveis. -->

                    </div>

                <?php endif; ?>
                <!-- Final da verificação dos próximos agendamentos. -->

            </section>
                        <!-- ============================================== -->
            <!-- 9. HISTÓRICO DE ATENDIMENTOS -->
            <!-- ============================================== -->

            <section
                class="secao-agendamentos"
                id="historico-agendamentos"
            >
                <!--
                    Seção responsável por apresentar os atendimentos
                    anteriores da cliente e seus respectivos status.
                -->

                <div class="agendamentos-titulo-secao">
                    <!-- Cabeçalho da seção histórica. -->

                    <div>
                        <!-- Agrupa o título e a descrição da seção. -->

                        <h3>Histórico de atendimentos</h3>
                        <!-- Título da seção de histórico. -->

                        <p>
                            Consulte os atendimentos anteriores e seus status.
                        </p>
                        <!-- Descrição das informações apresentadas. -->

                    </div>

                    <!-- Contador dos registros do histórico. -->
                    <span class="agendamentos-contador">
                        <?= count($historico_agendamentos) ?>
                    </span>
                    <!--
                        Conta a quantidade de registros
                        existentes no histórico da cliente.
                    -->

                </div>

                <?php if (!empty($historico_agendamentos)): ?>
                    <!--
                        Verifica se existe pelo menos um registro
                        no histórico de atendimentos.
                    -->

                    <div class="lista-agendamentos">
                        <!-- Container que organiza os cartões históricos. -->

                        <?php foreach ($historico_agendamentos as $agendamento): ?>
                            <!--
                                Percorre cada registro existente no histórico.

                                A variável $agendamento representa
                                o registro atual do loop.
                            -->

                            <!-- Cartão individual do histórico. -->
                            <article class="cartao-agendamento cartao-historico">
                                <!--
                                    O cartão utiliza a classe geral de agendamento
                                    e uma classe adicional para permitir
                                    estilos específicos do histórico.
                                -->

                                <div class="agendamento-data">
                                    <!-- Área que apresenta a data e o horário do atendimento. -->

                                    <span>Data do atendimento</span>
                                    <!-- Identificação da informação apresentada. -->

                                    <strong>
                                        <?= escaparTexto(
                                            formatarDataAgendamento(
                                                $agendamento["data"]
                                            )
                                        ) ?>
                                    </strong>
                                    <!--
                                        Converte a data armazenada no banco
                                        para o formato brasileiro.
                                    -->

                                    <span class="agendamento-horario">
                                        <?= escaparTexto(
                                            $agendamento["horario"] ?? "--:--"
                                        ) ?>
                                    </span>
                                    <!--
                                        Exibe o horário do atendimento.

                                        Caso não exista um horário,
                                        apresenta "--:--".
                                    -->

                                </div>

                                <div class="agendamento-detalhes">
                                    <!-- Área que apresenta os detalhes do atendimento. -->

                                    <h4>
                                        <?= escaparTexto(
                                            $agendamento["servico"] ?? "Serviço"
                                        ) ?>
                                    </h4>
                                    <!-- Apresenta o nome do serviço realizado. -->

                                    <p>
                                        <strong>Profissional:</strong>
                                        <?= escaparTexto(
                                            $agendamento["profissional"] ??
                                            "Não informada"
                                        ) ?>
                                    </p>
                                    <!-- Apresenta o nome da profissional responsável. -->

                                    <p>
    <strong>Valor:</strong>
    <!-- Identifica o valor do atendimento. -->

    <?php if ($agendamento["preco"] !== null): ?>
        <!--
            Verifica se o atendimento possui
            um valor definido.
        -->

        R$
        <!-- Símbolo da moeda brasileira. -->

        <?= number_format(
            (float) $agendamento["preco"],
            2,
            ",",
            "."
        ) ?>
        <!--
            Formata o valor utilizando duas casas decimais
            e o padrão brasileiro.
        -->

    <?php else: ?>
        <!-- Executado quando o preço não está definido. -->

        A confirmar
        <!-- Informa que o valor ainda precisa ser confirmado. -->

    <?php endif; ?>
    <!-- Final da verificação do preço. -->

</p>

<!-- ================================================== -->
<!-- DADOS PIX DA PROFISSIONAL -->
<!-- ================================================== -->

<?php if (!empty($agendamento["chave_pix"])): ?>
    <!--
        Verifica se existe uma chave Pix cadastrada
        para a profissional responsável pelo atendimento.
    -->

    <div class="agendamento-pix">
        <!-- Área destinada às informações do Pix. -->

        <p>
            <strong>Pagamento via Pix</strong>
        </p>
        <!-- Identifica a forma de pagamento. -->

        <p>
            <strong>Tipo de chave:</strong>
            <?= escaparTexto(
                !empty($agendamento["tipo_chave_pix"])
                    ? $agendamento["tipo_chave_pix"]
                    : "Não informado"
            ) ?>
        </p>
        <!--
            Apresenta o tipo de chave Pix.

            Caso o tipo não esteja cadastrado,
            apresenta "Não informado".
        -->

        <p>
            <strong>Chave Pix:</strong>

            <span class="chave-pix">
                <?= escaparTexto($agendamento["chave_pix"]) ?>
            </span>
        </p>
        <!-- Apresenta a chave Pix da profissional. -->

    </div>

<?php endif; ?>
<!-- Final da verificação da chave Pix. -->

<!-- Exibe o status atual do atendimento. -->
<?php
// Converte o status armazenado no banco
// para um texto amigável e uma classe CSS.
$status = apresentarStatus(
    $agendamento["status"] ?? ""
);
?>

<span class="agendamento-status <?= escaparTexto($status["classe"]) ?>">
    <?= escaparTexto($status["texto"]) ?>
</span>
<!--
    Exibe visualmente o status do atendimento
    utilizando a classe CSS correspondente.
-->

                                </div>
                                <!-- Final dos detalhes do atendimento. -->

                            </article>
                            <!-- Final do cartão histórico. -->

                        <?php endforeach; ?>
                        <!-- Final do loop do histórico. -->

                    </div>

                <?php else: ?>
                    <!--
                        Executado quando a cliente ainda não possui
                        nenhum registro no histórico.
                    -->

                    <!-- Mensagem apresentada quando o histórico está vazio. -->
                    <div class="agendamentos-vazio">
                        <!-- Área visual que representa a ausência de registros. -->

                        <h4>Nenhum registro no histórico</h4>
                        <!-- Título apresentado quando o histórico está vazio. -->

                        <p>
                            Seus atendimentos anteriores aparecerão aqui
                            quando houver registros correspondentes.
                        </p>
                        <!-- Explica quando os registros históricos serão apresentados. -->

                    </div>

                <?php endif; ?>
                <!-- Final da verificação do histórico. -->

            </section>
            <!-- Final da seção de histórico. -->

        <?php endif; ?>
        <!-- Final da condição de erro dos agendamentos. -->

    </main>
    <!-- Final do conteúdo principal da página. -->

    <!-- Rodapé discreto da área da cliente. -->
    <footer class="rodape-agendamentos">
        <!-- Rodapé da página de agendamentos. -->

        <p>TCC Estética — Área da Cliente</p>
        <!-- Identificação do sistema no rodapé. -->

    </footer>
    <!-- Final do rodapé. -->

</body>
<!-- Final do corpo da página. -->

</html>
<!-- Final do documento HTML. -->