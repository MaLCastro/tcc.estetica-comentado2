<?php
// ============================================================
// 1. INICIAR SESSÃO, CONEXÃO E VALIDAR ACESSO
// ============================================================

// Inicia a sessão para permitir o acesso aos dados da cliente
// que foram armazenados durante o login.
session_start();

// Inclui o arquivo responsável pela conexão com o banco de dados.
// O require_once evita que o arquivo seja carregado mais de uma vez.
require_once("conexao.php");

// Define o fuso horário utilizado pelo sistema.
// Isso é importante para que as comparações de data e horário
// utilizem o horário correto de São Paulo.
date_default_timezone_set("America/Sao_Paulo");

// Permite o acesso somente para usuários autenticados
// que possuem o tipo de usuário "cliente".
if (
    // Verifica se existe um ID de usuário armazenado na sessão.
    !isset($_SESSION["usuario_id"]) ||

    // Verifica se o tipo de usuário da sessão realmente é cliente.
    // O operador ?? evita erro caso a variável não exista.
    ($_SESSION["usuario_tipo"] ?? "") !== "cliente"
) {
    // Caso a pessoa não seja uma cliente autenticada,
    // ela é enviada de volta para a tela de login.
    header("Location: login.php");

    // Interrompe a execução para impedir que o restante da página
    // seja acessado sem autorização.
    exit();
}

// Recupera o ID da cliente que está logada.
// O (int) garante que o valor seja tratado como número inteiro.
$cliente_id = (int) $_SESSION["usuario_id"];

// Cria um objeto com o fuso horário de São Paulo.
// Esse objeto será usado nas validações de data e horário.
$fuso_horario = new DateTimeZone("America/Sao_Paulo");

// ============================================================
// FUNÇÃO PARA PROTEGER TEXTOS EXIBIDOS NO HTML
// ============================================================

// Esta função recebe um texto e transforma caracteres especiais
// em entidades HTML, ajudando a evitar problemas de segurança,
// como a inserção de código HTML ou JavaScript na página.
function escaparCancelamento($texto)
{
    // Converte o valor recebido para texto e aplica
    // a proteção usando htmlspecialchars().
    return htmlspecialchars((string) $texto, ENT_QUOTES, "UTF-8");
}

// ============================================================
// FUNÇÃO PARA FORMATAR DATAS
// ============================================================

// Esta função recebe uma data no formato do banco de dados
// e transforma para o formato utilizado no Brasil.
function formatarDataCancelamento($data)
{
    // Garante que o valor recebido seja tratado como texto.
    $data = (string) $data;

    // Tenta transformar a data recebida em um objeto DateTime.
    // O formato esperado é Ano-Mês-Dia.
    $data_objeto = DateTime::createFromFormat("!Y-m-d", $data);

    // Verifica se a conversão falhou ou se a data resultante
    // não corresponde exatamente à data recebida.
    if (
        !$data_objeto ||
        $data_objeto->format("Y-m-d") !== $data
    ) {
        // Caso a data seja inválida ou não exista,
        // retorna uma mensagem padrão.
        return "Data não informada";
    }

    // Converte a data para o formato brasileiro Dia/Mês/Ano.
    return $data_objeto->format("d/m/Y");
}

// ============================================================
// 2. VALIDAR O ID E PREPARAR O TOKEN DE SEGURANÇA
// ============================================================

// Obtém o ID enviado pela URL.
// Exemplo: confirmar_cancelamento.php?id=15
$agendamento_id = filter_input(
    // Indica que o valor será procurado nos parâmetros GET.
    INPUT_GET,

    // Define o nome do parâmetro esperado.
    "id",

    // Valida o valor como número inteiro.
    FILTER_VALIDATE_INT
);

// Também permite recuperar o ID enviado pelo formulário.
// Isso acontece quando a cliente confirma o cancelamento.
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // Obtém o ID enviado através do método POST.
    $agendamento_id = filter_input(
        // Indica que o valor será procurado nos dados POST.
        INPUT_POST,

        // Nome do campo enviado pelo formulário.
        "agendamento_id",

        // Valida o valor como número inteiro.
        FILTER_VALIDATE_INT
    );
}

// Interrompe o fluxo caso o identificador seja inválido.
if (!$agendamento_id || $agendamento_id < 1) {

    // Guarda uma mensagem de erro na sessão.
    // Essa mensagem poderá ser mostrada na página de agendamentos.
    $_SESSION["mensagem_agendamento"] = [
        // Define o tipo da mensagem.
        "tipo" => "erro",

        // Define o texto que será exibido.
        "texto" => "O agendamento informado é inválido."
    ];

    // Retorna a cliente para a página de seus agendamentos.
    header("Location: meus_agendamentos.php");

    // Interrompe a execução.
    exit();
}

// ============================================================
// CRIAÇÃO DO TOKEN DE SEGURANÇA
// ============================================================

// Verifica se ainda não existe um token para esta operação.
if (empty($_SESSION["token_confirmar_cancelamento"])) {

    // Cria um token aleatório com 32 bytes.
    // bin2hex() transforma esses bytes em uma sequência hexadecimal.
    $_SESSION["token_confirmar_cancelamento"] = bin2hex(random_bytes(32));
}

// ============================================================
// INICIALIZAÇÃO DAS VARIÁVEIS
// ============================================================

// Cria uma variável vazia para armazenar possíveis mensagens de erro.
$erro = "";

// Inicializa o agendamento como nulo.
// Posteriormente ele receberá os dados encontrados no banco.
$agendamento = null;

// ============================================================
// 3. BUSCAR E VALIDAR O AGENDAMENTO
// ============================================================

try {

    // ========================================================
    // CONSULTA SQL DO AGENDAMENTO
    // ========================================================

    // Busca apenas o agendamento solicitado
    // e que pertence à cliente atualmente autenticada.
    $sql = "
        SELECT

            // ID do agendamento.
            a.id,

            // ID da cliente relacionada ao agendamento.
            a.cliente_id,

            // Data marcada para o atendimento.
            a.data_agendamento,

            // Horário marcado para o atendimento.
            a.horario,

            // Status atual do agendamento.
            a.status,

            // Se existir um preço final personalizado,
            // utiliza esse valor.
            // Caso contrário, utiliza o preço original do serviço.
            COALESCE(a.preco_final, s.preco) AS preco,

            // Nome do serviço relacionado ao agendamento.
            s.nome_servico AS servico,

            // Nome da profissional responsável.
            u.nome AS profissional

        // Define a tabela principal da consulta.
        FROM agendamentos AS a

        // Relaciona o agendamento com seu serviço.
        LEFT JOIN servicos AS s
            ON s.id = a.servico_id

        // Relaciona o agendamento com a profissional.
        LEFT JOIN usuarios AS u
            ON u.id = a.profissional_id

        // Procura exatamente o agendamento solicitado.
        WHERE a.id = :id

          // Esta condição é importante para segurança:
          // garante que o agendamento pertence à cliente logada.
          AND a.cliente_id = :cliente_id

        // Garante que somente um registro seja retornado.
        LIMIT 1
    ";

    // Prepara a consulta SQL antes de executá-la.
    // Isso permite utilizar parâmetros de forma segura.
    $consulta = $conexao->prepare($sql);

    // Executa a consulta substituindo os parâmetros pelos valores reais.
    $consulta->execute([
        // Substitui :id pelo ID do agendamento.
        ":id" => $agendamento_id,

        // Substitui :cliente_id pelo ID da cliente autenticada.
        ":cliente_id" => $cliente_id
    ]);

    // Recupera o resultado como um array associativo.
    $agendamento = $consulta->fetch(PDO::FETCH_ASSOC);

    // ========================================================
    // VERIFICA SE O AGENDAMENTO FOI ENCONTRADO
    // ========================================================

    // Se nenhum registro foi encontrado, a variável será falsa.
    if (!$agendamento) {

        // Guarda uma mensagem de erro na sessão.
        $_SESSION["mensagem_agendamento"] = [
            "tipo" => "erro",
            "texto" => "Agendamento não encontrado."
        ];

        // Retorna para a lista de agendamentos.
        header("Location: meus_agendamentos.php");

        // Interrompe a execução.
        exit();
    }

// Captura possíveis erros gerados pelo PDO.
} catch (PDOException $e) {

    // Registra o erro técnico no log do servidor.
    // O usuário não vê detalhes internos do banco.
    error_log(
        "Erro ao consultar agendamento para cancelamento: " .
        $e->getMessage()
    );

    // Define uma mensagem amigável para a cliente.
    $erro = "Não foi possível carregar os dados do agendamento.";
}

// ============================================================
// 4. PROCESSAR A CONFIRMAÇÃO DO CANCELAMENTO
// ============================================================

// Verifica se:
// 1. a requisição foi enviada por POST;
// 2. o botão de confirmação foi enviado;
// 3. existe um agendamento carregado.
if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST["confirmar_cancelamento"]) &&
    $agendamento
) {

    // ========================================================
    // VALIDAR TOKEN DE SEGURANÇA
    // ========================================================

    // Recupera o token enviado pelo formulário.
    // Caso não exista, utiliza uma string vazia.
    $token_recebido = $_POST["token_cancelamento"] ?? "";

    // Recupera o token armazenado na sessão.
    $token_sessao = $_SESSION["token_confirmar_cancelamento"] ?? "";

    // Verifica se os dois valores são realmente strings,
    // se o token da sessão não está vazio e se os tokens são iguais.
    if (
        !is_string($token_recebido) ||
        !is_string($token_sessao) ||
        $token_sessao === "" ||
        !hash_equals($token_sessao, $token_recebido)
    ) {

        // Se o token não for válido, o cancelamento não acontece.
        $erro = "Não foi possível validar sua solicitação. Atualize a página e tente novamente.";

    } else {

        // ====================================================
        // VERIFICAR O STATUS DO AGENDAMENTO
        // ====================================================

        // Obtém o status atual do agendamento.
        // trim() remove espaços extras.
        // strtolower() transforma o texto para letras minúsculas.
        $status_atual = strtolower(
            trim((string) $agendamento["status"])
        );

        // Define quais status ainda permitem cancelamento.
        if (!in_array(
            $status_atual,

            // Somente esses três status podem ser cancelados.
            ["pendente", "confirmado", "alterado"],

            // O terceiro parâmetro true exige comparação exata.
            true
        )) {

            // Informa que o status atual não permite cancelamento.
            $erro = "Este agendamento não pode mais ser cancelado.";

        } else {

            // =================================================
            // VALIDAR DATA E HORÁRIO
            // =================================================

            // Recupera a data diretamente do banco.
            $data_banco = (string) $agendamento["data_agendamento"];

            // Recupera o horário e remove possíveis espaços.
            // substr() pega somente os cinco primeiros caracteres,
            // normalmente no formato HH:MM.
            $horario_banco = substr(
                trim((string) $agendamento["horario"]),
                0,
                5
            );

            // Junta a data e o horário e transforma
            // o resultado em um objeto DateTime.
            $data_hora = DateTime::createFromFormat(
                "!Y-m-d H:i",

                // Junta data + espaço + horário.
                $data_banco . " " . $horario_banco,

                // Utiliza o fuso horário definido anteriormente.
                $fuso_horario
            );

            // Verifica se a data realmente foi convertida
            // e se o resultado corresponde exatamente ao valor recebido.
            $data_valida = (
                $data_hora !== false &&
                $data_hora->format("Y-m-d H:i") ===
                    $data_banco . " " . $horario_banco
            );

            // Obtém a data e hora atuais.
            $agora = new DateTime("now", $fuso_horario);

            // Não permite cancelar um horário inválido
            // ou um atendimento que já aconteceu.
            if (!$data_valida || $data_hora <= $agora) {

                // Exibe uma mensagem informando o motivo.
                $erro = "Não é possível cancelar um atendimento cujo horário já passou ou é inválido.";

            } else {

                // =================================================
                // REALIZAR O CANCELAMENTO NO BANCO
                // =================================================

                try {

                    // Obtém o motivo informado pela cliente.
                    // trim() remove espaços desnecessários.
                    //
                    // Nesta versão o motivo apenas é recebido,
                    // mas não é salvo na tabela agendamentos.
                    $motivo = trim(
                        (string) ($_POST["motivo"] ?? "")
                    );

                    // Atualiza o agendamento para o status cancelado.
                    $sql_cancelar = "
                    UPDATE agendamentos
                    SET
                        status = 'cancelado',
                        cancelado_por = 'cliente',
                        cancelamento_visualizado = 0
                    WHERE id = :id
                    AND cliente_id = :cliente_id
                    AND status IN ('pendente', 'confirmado', 'alterado')
                    ";

                    // Prepara o comando UPDATE.
                    $consulta_cancelar = $conexao->prepare($sql_cancelar);

                    // Executa a alteração utilizando parâmetros.
                    $consulta_cancelar->execute([
                        // ID do agendamento que será cancelado.
                        ":id" => $agendamento_id,

                        // Garante novamente que o agendamento pertence
                        // à cliente autenticada.
                        ":cliente_id" => $cliente_id
                    ]);

                    // Verifica se alguma linha realmente foi alterada.
                    if ($consulta_cancelar->rowCount() > 0) {

                        // =================================================
                        // CANCELAMENTO REALIZADO COM SUCESSO
                        // =================================================

                        // Guarda uma mensagem de sucesso na sessão.
                        $_SESSION["mensagem_agendamento"] = [
                            "tipo" => "sucesso",
                            "texto" => "Agendamento cancelado com sucesso."
                        ];

                        // Remove o token utilizado.
                        // Assim ele não poderá ser reutilizado.
                        unset($_SESSION["token_confirmar_cancelamento"]);

                        // Retorna para a página de agendamentos.
                        header("Location: meus_agendamentos.php");

                        // Interrompe a execução.
                        exit();

                    } else {

                        // Caso nenhuma linha tenha sido alterada,
                        // informa que o agendamento não foi atualizado.
                        $erro = "O agendamento não foi alterado. Atualize a página e confira o status.";
                    }

                // Captura erros ocorridos durante o UPDATE.
                } catch (PDOException $e) {

                    // Registra o erro técnico no log.
                    error_log(
                        "Erro ao cancelar agendamento: " .
                        $e->getMessage()
                    );

                    // Mostra uma mensagem amigável para a cliente.
                    $erro = "Ocorreu um erro ao cancelar o agendamento. Tente novamente.";
                }
            }
        }
    }
}

// ============================================================
// 5. PREPARAR OS DADOS PARA EXIBIÇÃO
// ============================================================

// Define o nome do serviço.
// Se o banco não retornar um nome, utiliza "Serviço".
$nome_servico = $agendamento["servico"] ?? "Serviço";

// Define o nome da profissional.
// Caso não exista, utiliza um texto padrão.
$nome_profissional = $agendamento["profissional"] ?? "Não informada";

// Formata a data do agendamento para o padrão brasileiro.
$data_formatada = formatarDataCancelamento(
    $agendamento["data_agendamento"] ?? ""
);

// Define o horário que será exibido.
$horario_formatado = $agendamento["horario"] ?? "--:--";

// Recupera o preço do agendamento.
// Caso não exista, o valor será null.
$preco = $agendamento["preco"] ?? null;

// Recupera novamente o status atual.
// Isso será utilizado para controlar os elementos da tela.
$status_atual = strtolower(
    trim((string) ($agendamento["status"] ?? ""))
);

// Verifica se o status permite cancelamento.
$pode_cancelar = in_array(
    $status_atual,

    // Status permitidos para cancelamento.
    ["pendente", "confirmado", "alterado"],

    // Exige comparação exata.
    true
);

// ============================================================
// VERIFICAR SE O HORÁRIO AINDA ESTÁ NO FUTURO
// ============================================================

// Só realiza essa validação se o agendamento existir
// e seu status ainda permitir cancelamento.
if ($agendamento && $pode_cancelar) {

    // Recupera novamente a data armazenada no banco.
    $data_banco = (string) $agendamento["data_agendamento"];

    // Recupera o horário e limita aos cinco primeiros caracteres.
    $horario_banco = substr(
        trim((string) $agendamento["horario"]),
        0,
        5
    );

    // Converte data e horário em um objeto DateTime.
    $data_hora = DateTime::createFromFormat(
        "!Y-m-d H:i",

        // Junta data e horário.
        $data_banco . " " . $horario_banco,

        // Utiliza o fuso horário do projeto.
        $fuso_horario
    );

    // Obtém o momento atual.
    $agora = new DateTime("now", $fuso_horario);

    // Verifica se:
    // 1. a data não existe;
    // 2. a data formatada é diferente da original;
    // 3. o horário já passou.
    if (
        !$data_hora ||
        $data_hora->format("Y-m-d H:i") !==
            $data_banco . " " . $horario_banco ||
        $data_hora <= $agora
    ) {

        // Se qualquer condição for verdadeira,
        // desativa a possibilidade de cancelamento.
        $pode_cancelar = false;
    }
}
?>

<!DOCTYPE html>
<html lang="pt-BR">

<head>

    <!-- Define a codificação utilizada pela página. -->
    <meta charset="UTF-8">

    <!-- Faz a página se adaptar a celulares e tablets. -->
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- Define o título mostrado na aba do navegador. -->
    <title>Confirmar Cancelamento | TCC Estética</title>

    <!-- Carrega o arquivo CSS externo utilizado pelo projeto. -->
    <link rel="stylesheet" href="style.css">

</head>

<!--
    Define as classes principais da página.

    "pagina-cliente" identifica que esta tela pertence
    à área da cliente.

    "pagina-confirmar-cancelamento" permite criar estilos
    específicos para esta tela no style.css.
-->
<body class="pagina-cliente pagina-confirmar-cancelamento">

    <!-- ======================================================
         CABEÇALHO DA ÁREA DA CLIENTE
         ====================================================== -->

    <!-- Cabeçalho padronizado utilizado pelas páginas da cliente. -->
    <header class="cabecalho-cliente">

        <!-- Área destinada ao nome/logo do sistema. -->
        <div class="logo-cliente">

            <!-- Nome exibido como identificação do sistema. -->
            <h1>Estética</h1>

        </div>

        <!--
            Menu principal da área da cliente.
            aria-label ajuda tecnologias assistivas a identificar
            que este elemento representa a navegação principal.
        -->
        <nav class="menu-cliente" aria-label="Navegação principal">

            <!-- Link para o painel inicial da cliente. -->
            <a href="painel_cliente.php">Início</a>

            <!-- Link para a página de serviços. -->
            <a href="servicos.php">Serviços</a>

            <!--
                Link para os agendamentos da cliente.
                A classe "ativo" identifica visualmente a página atual.
            -->
            <a
                href="meus_agendamentos.php"
                class="ativo"
            >
                Meus Agendamentos
            </a>

            <!-- Link para o perfil da cliente. -->
            <a href="perfil_cliente.php">Meu Perfil</a>

            <!-- Link responsável por encerrar a sessão. -->
            <a href="logout.php" class="menu-sair">Sair</a>

        </nav>

    </header>

    <!-- ======================================================
         CONTEÚDO PRINCIPAL
         ====================================================== -->

    <!-- Área principal que envolve o cartão de cancelamento. -->
    <main class="cancelamento-container">

        <!-- Cartão visual onde ficam as informações da confirmação. -->
        <section class="cancelamento-cartao">

            <!-- Cabeçalho visual do cartão. -->
            <div class="cancelamento-cabecalho">

                <!-- Ícone visual utilizado para chamar atenção. -->
                <div
                    class="cancelamento-icone"
                    aria-hidden="true"
                >
                    !
                </div>

                <!-- Identificação da finalidade da página. -->
                <span class="cancelamento-etiqueta">
                    CONFIRMAÇÃO DE CANCELAMENTO
                </span>

                <!-- Título principal da confirmação. -->
                <h2>Cancelar agendamento?</h2>

                <!-- Texto explicativo apresentado à cliente. -->
                <p>
                    Tem certeza de que deseja cancelar este atendimento?
                    Confira os dados antes de continuar.
                </p>

            </div>

            <!--
                Exibe os dados somente quando o agendamento
                foi encontrado corretamente no banco.
            -->
            <?php if ($agendamento): ?>

                <!-- ==================================================
                     DADOS DO AGENDAMENTO
                     ================================================== -->

                <!-- Container que organiza as informações do atendimento. -->
                <div class="cancelamento-detalhes">

                    <!-- Informação referente ao serviço. -->
                    <div class="cancelamento-dado">

                        <!-- Título do dado. -->
                        <span>Serviço</span>

                        <!--
                            Mostra o nome do serviço.
                            A função de escape protege o conteúdo antes
                            de colocá-lo dentro do HTML.
                        -->
                        <strong>
                            <?= escaparCancelamento($nome_servico) ?>
                        </strong>

                    </div>

                    <!-- Informação referente à profissional. -->
                    <div class="cancelamento-dado">

                        <!-- Título do dado. -->
                        <span>Profissional</span>

                        <!-- Nome da profissional. -->
                        <strong>
                            <?= escaparCancelamento($nome_profissional) ?>
                        </strong>

                    </div>

                    <!-- Informação referente à data. -->
                    <div class="cancelamento-dado">

                        <!-- Título do dado. -->
                        <span>Data</span>

                        <!-- Data já formatada para o padrão brasileiro. -->
                        <strong>
                            <?= escaparCancelamento($data_formatada) ?>
                        </strong>

                    </div>

                    <!-- Informação referente ao horário. -->
                    <div class="cancelamento-dado">

                        <!-- Título do dado. -->
                        <span>Horário</span>

                        <!-- Horário do atendimento. -->
                        <strong>
                            <?= escaparCancelamento($horario_formatado) ?>
                        </strong>

                    </div>

                    <!-- Informação referente ao valor. -->
                    <div class="cancelamento-dado">

                        <!-- Título do dado. -->
                        <span>Valor</span>

                        <!-- Área responsável pela apresentação do preço. -->
                        <strong>

                            <!--
                                Verifica se existe um preço.
                                Se existir, mostra o valor formatado.
                            -->
                            <?php if ($preco !== null): ?>

                                <!--
                                    number_format() transforma o número
                                    no formato monetário brasileiro.

                                    Exemplo:
                                    1500.50 -> 1.500,50
                                -->
                                R$ <?= number_format(
                                    (float) $preco,
                                    2,
                                    ",",
                                    "."
                                ) ?>

                            <?php else: ?>

                                <!-- Texto apresentado quando o preço não está definido. -->
                                A confirmar

                            <?php endif; ?>

                        </strong>

                    </div>

                </div>

                <!-- ==================================================
                     MENSAGEM DE ERRO
                     ================================================== -->

                <!--
                    Só apresenta a mensagem caso a variável $erro
                    possua algum conteúdo.
                -->
                <?php if ($erro !== ""): ?>

                    <!--
                        role="alert" informa aos recursos de acessibilidade
                        que este conteúdo representa um alerta.
                    -->
                    <div
                        class="cancelamento-mensagem cancelamento-erro"
                        role="alert"
                    >

                        <!-- Exibe a mensagem protegida contra HTML. -->
                        <?= escaparCancelamento($erro) ?>

                    </div>

                <?php endif; ?>

                <!-- ==================================================
                     FORMULÁRIO DE CANCELAMENTO
                     ================================================== -->

                <!--
                    O formulário somente aparece se o agendamento
                    ainda puder ser cancelado.
                -->
                <?php if ($pode_cancelar): ?>

                    <!--
                        O formulário utiliza POST porque realiza
                        uma alteração no banco de dados.

                        O action indica o arquivo que receberá
                        os dados enviados.
                    -->
                    <form
                        method="POST"
                        action="confirmar_cancelamento.php"
                    >

                        <!--
                            Campo oculto que envia o ID do agendamento.
                            O usuário não precisa visualizar esse campo.
                        -->
                        <input
                            type="hidden"
                            name="agendamento_id"
                            value="<?= (int) $agendamento["id"] ?>"
                        >

                        <!--
                            Campo oculto utilizado para enviar
                            o token de segurança criado na sessão.
                        -->
                        <input
                            type="hidden"
                            name="token_cancelamento"
                            value="<?= escaparCancelamento(
                                $_SESSION["token_confirmar_cancelamento"]
                            ) ?>"
                        >

                        <!-- Pergunta sobre o motivo do cancelamento. -->
                        <p class="cancelamento-pergunta">
                            Deseja informar o motivo do cancelamento?
                        </p>

                        <!-- Opções de resposta para a cliente. -->
                        <div class="cancelamento-opcoes">

                            <!-- Opção para informar o motivo. -->
                            <label class="cancelamento-opcao">

                                <!--
                                    Radio button.
                                    Somente uma das opções pode ser escolhida.
                                -->
                                <input
                                    type="radio"
                                    name="informar_motivo"
                                    value="sim"
                                    id="motivo-sim"
                                >

                                <!-- Texto da opção. -->
                                Sim

                            </label>

                            <!-- Opção para não informar o motivo. -->
                            <label class="cancelamento-opcao">

                                <!--
                                    Esta opção começa marcada.
                                    Portanto, por padrão, o motivo não é informado.
                                -->
                                <input
                                    type="radio"
                                    name="informar_motivo"
                                    value="nao"
                                    id="motivo-nao"
                                    checked
                                >

                                <!-- Texto apresentado para a opção. -->
                                Não, prefiro não informar

                            </label>

                        </div>

                        <!--
                            Campo de motivo.

                            A propriedade hidden faz com que ele
                            comece escondido.

                            O JavaScript abaixo poderá torná-lo visível
                            quando a cliente escolher "Sim".
                        -->
                        <div
                            class="cancelamento-campo"
                            id="campo-motivo"
                            hidden
                        >

                            <!-- Rótulo do campo de texto. -->
                            <label for="motivo">
                                Motivo do cancelamento (opcional)
                            </label>

                            <!--
                                Área onde a cliente pode escrever o motivo.

                                maxlength="500" limita a quantidade de caracteres
                                para evitar textos excessivamente grandes.
                            -->
                            <textarea
                                name="motivo"
                                id="motivo"
                                maxlength="500"
                                placeholder="Se desejar, conte brevemente o motivo..."
                            ></textarea>

                            <!--
                                Explica para a cliente que o motivo
                                não é armazenado nesta versão do sistema.
                            -->
                            <p class="cancelamento-observacao">
                                O motivo é opcional. Nesta versão, ele não será
                                armazenado no banco de dados.
                            </p>

                        </div>

                        <!-- ==================================================
                             BOTÕES DO FORMULÁRIO
                             ================================================== -->

                        <!-- Área que agrupa as ações disponíveis. -->
                        <div class="cancelamento-acoes">

                            <!--
                                Botão responsável por confirmar
                                definitivamente o cancelamento.
                            -->
                            <button
                                type="submit"
                                name="confirmar_cancelamento"
                                value="1"
                                class="cancelamento-botao cancelamento-botao-confirmar"
                            >
                                Sim, confirmar cancelamento
                            </button>

                            <!--
                                Link que permite sair da confirmação
                                sem realizar nenhuma alteração.
                            -->
                            <a
                                href="meus_agendamentos.php"
                                class="cancelamento-botao cancelamento-botao-voltar"
                            >
                                Não, voltar
                            </a>

                        </div>

                    </form>

                <?php else: ?>

                    <!-- ==================================================
                         AGENDAMENTO NÃO PODE SER CANCELADO
                         ================================================== -->

                    <!--
                        Esta mensagem aparece quando o agendamento
                        não está mais em uma situação que permita
                        o cancelamento.
                    -->
                    <div
                        class="cancelamento-indisponivel"
                        role="status"
                    >
                        Este agendamento não pode ser cancelado pela tela
                        de confirmação. Ele pode já ter sido cancelado,
                        concluído ou ter passado do horário.
                    </div>

                    <!-- Área do botão de retorno. -->
                    <div class="cancelamento-acoes">

                        <!-- Volta para a lista de agendamentos. -->
                        <a
                            href="meus_agendamentos.php"
                            class="cancelamento-botao cancelamento-botao-voltar"
                        >
                            Voltar aos meus agendamentos
                        </a>

                    </div>

                <?php endif; ?>

            <?php else: ?>

                <!-- ==================================================
                     AGENDAMENTO NÃO ENCONTRADO
                     ================================================== -->

                <!--
                    Apresenta uma mensagem caso o sistema não tenha
                    conseguido localizar o agendamento.
                -->
                <div
                    class="cancelamento-mensagem cancelamento-erro"
                    role="alert"
                >

                    <!--
                        Se existir uma mensagem específica em $erro,
                        ela será exibida.

                        Caso contrário, será utilizada a mensagem padrão.
                    -->
                    <?= escaparCancelamento(
                        $erro !== ""
                            ? $erro
                            : "Não foi possível localizar este agendamento."
                    ) ?>

                </div>

                <!-- Área do botão de retorno. -->
                <div class="cancelamento-acoes">

                    <!-- Retorna para os agendamentos da cliente. -->
                    <a
                        href="meus_agendamentos.php"
                        class="cancelamento-botao cancelamento-botao-voltar"
                    >
                        Voltar aos meus agendamentos
                    </a>

                </div>

            <?php endif; ?>

        </section>

    </main>

    <!-- ============================================================
         6. JAVASCRIPT — CONTROLE DO CAMPO DE MOTIVO
         ============================================================ -->

    <!-- Inicia o código JavaScript executado no navegador. -->
    <script>

        // Localiza o botão de rádio correspondente à opção "Sim".
        const opcaoSim = document.getElementById("motivo-sim");

        // Localiza o botão de rádio correspondente à opção "Não".
        const opcaoNao = document.getElementById("motivo-nao");

        // Localiza o campo que contém a área para escrever o motivo.
        const campoMotivo = document.getElementById("campo-motivo");

        // ========================================================
        // FUNÇÃO PARA MOSTRAR OU ESCONDER O CAMPO
        // ========================================================

        // Esta função verifica qual opção foi selecionada
        // e altera a visibilidade do campo de motivo.
        function atualizarCampoMotivo() {

            // Verifica se algum dos elementos necessários não foi encontrado.
            if (!opcaoSim || !opcaoNao || !campoMotivo) {

                // Encerra a função caso algum elemento não exista.
                return;
            }

            // Verifica se a opção "Sim" está marcada.
            const informarMotivo = opcaoSim.checked;

            // A propriedade hidden recebe:
            // true  = campo escondido.
            // false = campo visível.
            //
            // Como informarMotivo é true quando "Sim" está marcado,
            // o campo aparece somente nessa situação.
            campoMotivo.hidden = !informarMotivo;
        }

        // ========================================================
        // MONITORAR ALTERAÇÕES NAS OPÇÕES
        // ========================================================

        // Verifica se todos os elementos necessários existem.
        if (opcaoSim && opcaoNao && campoMotivo) {

            // Quando "Sim" for alterado, atualiza a visibilidade.
            opcaoSim.addEventListener(
                "change",
                atualizarCampoMotivo
            );

            // Quando "Não" for alterado, atualiza a visibilidade.
            opcaoNao.addEventListener(
                "change",
                atualizarCampoMotivo
            );

            // Executa a função imediatamente ao carregar a página
            // para deixar o campo no estado correto.
            atualizarCampoMotivo();
        }

    </script>

</body>
</html>