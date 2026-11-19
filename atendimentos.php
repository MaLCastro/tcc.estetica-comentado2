<?php
// ============================================================
// 1. INICIAR A SESSÃO E VALIDAR O ACESSO
// ============================================================

// Inicia a sessão para permitir o acesso aos dados da profissional autenticada.
session_start();

// Inclui o arquivo responsável pela conexão PDO com o banco de dados.
require_once("conexao.php");

// Verifica se existe um usuário autenticado e se ele possui o tipo profissional.
if (
    !isset($_SESSION["usuario_id"]) || // Verifica se o ID do usuário está definido na sessão.
    ($_SESSION["usuario_tipo"] ?? "") !== "profissional" // Confirma que o usuário é uma profissional.
) {
    // Redireciona usuários que não possuem autorização para a página de login.
    header("Location: login.php");

    // Interrompe a execução para impedir que o restante da página seja carregado.
    exit();
}

// Verifica se já existe um token CSRF para os formulários desta página.
if (empty($_SESSION["csrf_agendamentos"])) {

    // Cria um token aleatório e seguro para proteger os formulários contra requisições indevidas.
    $_SESSION["csrf_agendamentos"] = bin2hex(random_bytes(32));
}

// Define o fuso horário utilizado pelos horários e datas do sistema.
date_default_timezone_set("America/Sao_Paulo");


// ============================================================
// 2. DEFINIR OS FILTROS
// ============================================================

// Tenta obter o mês enviado pela URL e transforma o valor em número inteiro.
$mes = filter_input(INPUT_GET, "mes", FILTER_VALIDATE_INT);

// Se o mês não foi informado ou é inválido, utiliza o mês atual.
$mes = ($mes !== false && $mes !== null) ? $mes : (int)date("m");

// Garante que o mês fique entre janeiro (1) e dezembro (12).
$mes = max(1, min(12, $mes));

// Tenta obter o ano enviado pela URL e transforma o valor em número inteiro.
$ano = filter_input(INPUT_GET, "ano", FILTER_VALIDATE_INT);

// Se o ano não foi informado ou é inválido, utiliza o ano atual.
$ano = ($ano !== false && $ano !== null) ? $ano : (int)date("Y");

// Limita o ano entre 2020 e o próximo ano.
$ano = max(2020, min((int)date("Y") + 1, $ano));

// Define os status que podem ser utilizados como filtro na página.
$statusPermitidos = [
    "pendente",   // Agendamento aguardando atendimento.
    "concluido",  // Atendimento já realizado.
    "cancelado",  // Agendamento cancelado.
    "alterado"    // Agendamento que sofreu alguma alteração.
];

// Obtém o filtro de status enviado pela URL.
$filtroStatus = $_GET["status"] ?? "";

// Verifica se o status informado realmente está entre os permitidos.
if (!in_array($filtroStatus, $statusPermitidos, true)) {

    // Caso seja inválido, remove o filtro para evitar valores inesperados.
    $filtroStatus = "";
}

// Verifica se a URL possui hoje=1 para ativar o filtro de agendamentos do dia.
$somenteHoje = isset($_GET["hoje"]) && $_GET["hoje"] === "1";

// Obtém a data atual no formato utilizado pelo banco de dados.
$dataHoje = date("Y-m-d");

// Obtém o ID da profissional autenticada na sessão.
$profissional_id = (int)$_SESSION["usuario_id"];

// Monta a data correspondente ao primeiro dia do mês selecionado.
$dataInicio = sprintf("%04d-%02d-01", $ano, $mes);

// Obtém a data correspondente ao último dia do mês selecionado.
$dataFim = date("Y-m-t", strtotime($dataInicio));

// Calcula a data do mês anterior para o botão de navegação.
$dataMesAnterior = strtotime("-1 month", strtotime($dataInicio));

// Calcula a data do próximo mês para o botão de navegação.
$dataProximoMes = strtotime("+1 month", strtotime($dataInicio));


// ============================================================
// 3. FUNÇÕES AUXILIARES
// ============================================================

// Cria uma função para formatar valores monetários no padrão brasileiro.
function formatarDinheiro($valor)
{
    // Verifica se o valor está vazio ou não foi informado.
    if ($valor === null || $valor === "") {

        // Retorna três traços quando não existe valor disponível.
        return "---";
    }

    // Converte o valor para número e apresenta duas casas decimais.
    // A vírgula representa os centavos e o ponto representa os milhares.
    return "R$ " . number_format((float)$valor, 2, ",", ".");
}

// Cria uma função para transformar o status do banco em um texto mais amigável.
function etiquetaStatus($status)
{
    // Retorna uma descrição correspondente a cada status conhecido.
    return match ($status) {
        "pendente"   => "Pendente",
        "confirmado" => "Confirmado",
        "concluido"  => "Concluído",
        "cancelado"  => "Cancelado",
        "alterado"   => "Alterado",

        // Para outros valores, utiliza o próprio status com a primeira letra maiúscula.
        default      => ucfirst((string)$status)
    };
}


// ============================================================
// 4. CONSULTAR OS AGENDAMENTOS
// ============================================================

// Indica inicialmente que não ocorreu erro na consulta.
$erroConsulta = false;

// Cria um array vazio que posteriormente receberá os agendamentos encontrados.
$atendimentos = [];

// Inicializa os valores utilizados nos cartões de resumo.
$resumo = [
    "total" => 0,       // Quantidade total de agendamentos.
    "concluido" => 0,   // Quantidade de atendimentos concluídos.
    "pendente" => 0,    // Quantidade de atendimentos pendentes.
    "cancelado" => 0,   // Quantidade de atendimentos cancelados.
    "alterado" => 0,    // Quantidade de atendimentos alterados.
    "confirmado" => 0   // Quantidade de atendimentos confirmados.
];

// Inicia o bloco que poderá capturar erros relacionados ao banco de dados.
try {

    // Monta a consulta SQL principal dos atendimentos da profissional.
    $sql = "
        SELECT

            // Identificador único do agendamento.
            a.id,

            // Data em que o atendimento foi marcado.
            a.data_agendamento,

            // Horário marcado para o atendimento.
            a.horario,

            // Situação atual do agendamento.
            a.status,

            // Valor final registrado para o atendimento.
            a.preco_final,

            // Nome da cliente obtido através da tabela usuarios.
            u.nome AS nome_cliente,

            // Telefone da cliente obtido através da tabela usuarios.
            u.telefone AS telefone_cliente,

            // Nome do serviço obtido através da tabela servicos.
            s.nome_servico,

            // Tipo do serviço obtido através da tabela servicos.
            s.tipo_servico

        FROM agendamentos a

        // Relaciona o agendamento com a cliente.
        // LEFT JOIN permite manter o agendamento mesmo se a cliente não existir.
        LEFT JOIN usuarios u
            ON u.id = a.cliente_id

        // Relaciona o agendamento com o serviço.
        // LEFT JOIN evita esconder o agendamento caso o serviço tenha sido removido.
        LEFT JOIN servicos s
            ON s.id = a.servico_id

        // Garante que somente os agendamentos da profissional logada sejam exibidos.
        WHERE a.profissional_id = :profissional_id
    ";

    // Cria os parâmetros que serão enviados de forma segura para o PDO.
    $parametros = [
        // Conecta o parâmetro ao ID da profissional autenticada.
        ":profissional_id" => (int) $profissional_id
    ];

    // Verifica se a profissional escolheu visualizar somente os agendamentos de hoje.
    if ($somenteHoje) {

        // Adiciona à consulta a condição para selecionar somente a data atual.
        $sql .= " AND a.data_agendamento = :data_hoje";

        // Informa ao PDO qual é a data atual.
        $parametros[":data_hoje"] = $dataHoje;

    } else {

        // Caso o filtro "hoje" não esteja ativo,
        // limita os resultados ao mês selecionado.
        $sql .= "
            AND a.data_agendamento BETWEEN :data_inicio AND :data_fim
        ";

        // Define o primeiro dia do período pesquisado.
        $parametros[":data_inicio"] = $dataInicio;

        // Define o último dia do período pesquisado.
        $parametros[":data_fim"] = $dataFim;
    }

    // Verifica se existe algum filtro específico de status.
    if ($filtroStatus !== "") {

        // Adiciona a condição de status à consulta.
        $sql .= " AND a.status = :status";

        // Informa ao PDO qual status deve ser pesquisado.
        $parametros[":status"] = $filtroStatus;
    }

    // Organiza os resultados colocando primeiro as datas mais recentes
    // e, dentro da mesma data, os horários mais recentes.
    $sql .= "
        ORDER BY
            a.data_agendamento DESC,
            a.horario DESC
    ";

    // Prepara a consulta SQL antes de executá-la.
    $stmt = $conexao->prepare($sql);

    // Executa a consulta utilizando os parâmetros definidos anteriormente.
    $stmt->execute($parametros);

    // Obtém todos os resultados como arrays associativos.
    $atendimentos = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {

    // Registra detalhes técnicos do erro no arquivo de log do servidor.
    error_log("Erro ao consultar agendamentos: " . $e->getMessage());

    // Informa ao restante da página que ocorreu um erro na consulta.
    $erroConsulta = true;
}

// Verifica se a consulta principal foi executada sem erro.
if (!$erroConsulta) {

    // Inicia uma nova tentativa de consulta para gerar o resumo.
    try {

        // Consulta a quantidade de agendamentos agrupada por status.
        $sqlResumo = "
            SELECT

                // Retorna o status de cada grupo.
                a.status,

                // Conta quantos registros existem em cada status.
                COUNT(*) AS quantidade

            FROM agendamentos a

            WHERE

                // Considera somente os agendamentos da profissional atual.
                a.profissional_id = :profissional_id

                // Limita o resultado ao mês selecionado.
                AND a.data_agendamento BETWEEN :data_inicio AND :data_fim

            // Agrupa os resultados de acordo com o status.
            GROUP BY a.status
        ";

        // Prepara a consulta do resumo.
        $stmtResumo = $conexao->prepare($sqlResumo);

        // Executa a consulta passando os parâmetros necessários.
        $stmtResumo->execute([
            // ID da profissional autenticada.
            ":profissional_id" => (int) $profissional_id,

            // Primeiro dia do mês.
            ":data_inicio" => $dataInicio,

            // Último dia do mês.
            ":data_fim" => $dataFim
        ]);

        // Percorre cada grupo de status retornado pelo banco.
        while ($linhaResumo = $stmtResumo->fetch(PDO::FETCH_ASSOC)) {

            // Armazena o nome do status atual.
            $statusResumo = $linhaResumo["status"];

            // Converte a quantidade retornada para inteiro.
            $quantidade = (int) $linhaResumo["quantidade"];

            // Soma a quantidade ao total geral.
            $resumo["total"] += $quantidade;

            // Verifica se o status retornado existe no array de resumo.
            if (array_key_exists($statusResumo, $resumo)) {

                // Adiciona a quantidade ao cartão correspondente.
                $resumo[$statusResumo] += $quantidade;
            }
        }

    } catch (PDOException $e) {

        // Registra o erro no log sem apagar os atendimentos já consultados.
        error_log("Erro ao carregar resumo dos atendimentos: " . $e->getMessage());
    }
}


// ============================================================
// 4.1. CONTROLAR AS NOTIFICAÇÕES DE CANCELAMENTO
// ============================================================

// Inicializa o contador de cancelamentos ainda não visualizados.
$novosCancelamentos = 0;

// Inicia o bloco responsável pelo controle das notificações.
try {

    // Verifica se a consulta principal funcionou
    // e se a profissional está visualizando o filtro de cancelados.
    if (!$erroConsulta && $filtroStatus === "cancelado") {

        // Atualiza os cancelamentos feitos por clientes,
        // marcando-os como visualizados.
        $sqlVisualizar = "
            UPDATE agendamentos
            SET cancelamento_visualizado = 1
            WHERE profissional_id = :profissional_id
              AND status = 'cancelado'
              AND cancelado_por = 'cliente'
              AND cancelamento_visualizado = 0
        ";

        // Define o ID da profissional que receberá a atualização.
        $parametrosVisualizar = [
            ":profissional_id" => $profissional_id
        ];

        // Verifica se a página está utilizando o filtro "hoje".
        if ($somenteHoje) {

            // Limita a atualização aos cancelamentos da data atual.
            $sqlVisualizar .= "
                AND data_agendamento = :data_hoje
            ";

            // Passa a data atual para a consulta.
            $parametrosVisualizar[":data_hoje"] = $dataHoje;

        } else {

            // Caso contrário, limita ao período do mês selecionado.
            $sqlVisualizar .= "
                AND data_agendamento BETWEEN :data_inicio AND :data_fim
            ";

            // Define o início do período.
            $parametrosVisualizar[":data_inicio"] = $dataInicio;

            // Define o final do período.
            $parametrosVisualizar[":data_fim"] = $dataFim;
        }

        // Prepara a consulta responsável por marcar os cancelamentos como visualizados.
        $stmtVisualizar = $conexao->prepare($sqlVisualizar);

        // Executa a atualização com os parâmetros definidos.
        $stmtVisualizar->execute($parametrosVisualizar);
    }

    // Consulta a quantidade de cancelamentos feitos por clientes
    // que ainda não foram visualizados pela profissional.
    $sqlNotificacoes = "
        SELECT COUNT(*)
        FROM agendamentos
        WHERE profissional_id = :profissional_id
          AND status = 'cancelado'
          AND cancelado_por = 'cliente'
          AND cancelamento_visualizado = 0
    ";

    // Prepara a consulta da quantidade de notificações.
    $stmtNotificacoes = $conexao->prepare($sqlNotificacoes);

    // Executa a consulta passando o ID da profissional.
    $stmtNotificacoes->execute([
        ":profissional_id" => $profissional_id
    ]);

    // Obtém o número de cancelamentos ainda não visualizados.
    $novosCancelamentos = (int) $stmtNotificacoes->fetchColumn();

} catch (PDOException $e) {

    // Registra o erro no log sem impedir o funcionamento da página.
    error_log(
        "Erro ao consultar notificações de cancelamento: " .
        $e->getMessage()
    );

    // Em caso de erro, mantém o contador como zero.
    $novosCancelamentos = 0;
}


// ============================================================
// 5. PREPARAR OS TEXTOS DA PÁGINA
// ============================================================

// Cria um array com os nomes dos meses em português.
$meses = [
    1 => "Janeiro",
    2 => "Fevereiro",
    3 => "Março",
    4 => "Abril",
    5 => "Maio",
    6 => "Junho",
    7 => "Julho",
    8 => "Agosto",
    9 => "Setembro",
    10 => "Outubro",
    11 => "Novembro",
    12 => "Dezembro"
];

// Obtém da URL o resultado retornado após alguma ação.
$resultado = $_GET["resultado"] ?? "";

// Define as mensagens que podem ser exibidas depois das ações.
$mensagens = [
    "concluido" => "Atendimento concluído com sucesso.",
    "cancelado" => "Agendamento cancelado com sucesso.",
    "nao_alterado" => "O agendamento não foi alterado. Ele pode já ter sido atualizado.",
    "erro" => "Não foi possível atualizar o agendamento. Tente novamente."
];

// Seleciona a mensagem correspondente ao resultado recebido.
$mensagemResultado = $mensagens[$resultado] ?? "";

// Define quais resultados devem ser considerados como operações realizadas com sucesso.
$sucesso = in_array($resultado, ["concluido", "cancelado"], true);
?>

<!DOCTYPE html>
<html lang="pt-BR">

<head>

    <!-- Define a codificação utilizada pela página. -->
    <meta charset="UTF-8">

    <!-- Faz a página se adaptar a diferentes tamanhos de tela. -->
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- Define o título exibido na aba do navegador. -->
    <title>Atendimentos | TCC Estética</title>

    <!-- Importa o arquivo CSS principal do sistema. -->
    <link rel="stylesheet" href="style.css">

    <!-- Mantém o contador de cancelamentos na mesma linha do texto. -->
    <style>

        /* Organiza o conteúdo do botão de cancelados horizontalmente. */
        .btn-filtro-cancelados {
            /* Define a direção dos elementos como linha. */
            flex-direction: row !important;

            /* Centraliza os elementos verticalmente. */
            align-items: center;

            /* Centraliza os elementos horizontalmente. */
            justify-content: center;

            /* Define o espaço entre o texto e o contador. */
            gap: 7px;

            /* Impede que o texto seja quebrado em várias linhas. */
            white-space: nowrap;
        }

        /* Estiliza o pequeno contador de cancelamentos. */
        .contador-cancelamentos {

            /* Permite utilizar flexbox dentro do contador. */
            display: inline-flex;

            /* Impede que o contador diminua de tamanho. */
            flex: 0 0 auto;

            /* Centraliza o número verticalmente. */
            align-items: center;

            /* Centraliza o número horizontalmente. */
            justify-content: center;

            /* Define uma largura mínima para o contador. */
            min-width: 19px;

            /* Define a altura do contador. */
            height: 19px;

            /* Cria espaço interno horizontal. */
            padding: 0 5px;

            /* Remove margens externas. */
            margin: 0;

            /* Deixa o contador com formato arredondado. */
            border-radius: 50px;

            /* Define a cor de fundo do contador. */
            background-color: #C74455;

            /* Define a cor branca do número. */
            color: #FFFFFF;

            /* Define o tamanho da fonte do contador. */
            font-size: 11px;

            /* Deixa o número em negrito. */
            font-weight: 700;

            /* Controla a altura da linha do texto. */
            line-height: 1;

            /* Alinha verticalmente o contador com o texto. */
            vertical-align: middle;
        }
    </style>
</head>

<body>

<!-- ============================================================
     6. MENU LATERAL
     ============================================================ -->

<!-- Cria a barra lateral de navegação da profissional. -->
<aside class="sidebar">

    <!-- Área que apresenta o nome do sistema e a especialidade. -->
    <div class="logo">

        <!-- Nome principal apresentado no menu lateral. -->
        <h1>Estética</h1>

        <!-- Exibe a especialidade armazenada na sessão. -->
        <p>
            <?= htmlspecialchars(
                $_SESSION["especialidade"] ?? "Profissional",
                ENT_QUOTES,
                "UTF-8"
            ) ?>
        </p>
    </div>

    <!-- Menu de navegação entre as páginas da área profissional. -->
    <nav class="menu">

        <!-- Link para o painel inicial da profissional. -->
        <a href="painel_profissional.php">Início</a>

        <!-- Link da página atual, marcada com a classe ativo. -->
        <a href="atendimentos.php" class="ativo">Atendimentos</a>

        <!-- Link para a agenda da profissional. -->
        <a href="minha_agenda.php">Minha Agenda</a>

        <!-- Link para os serviços cadastrados pela profissional. -->
        <a href="meus_servicos.php">Meus Serviços</a>

        <!-- Separador visual entre os grupos do menu. -->
        <div class="separador"></div>

        <!-- Link para o perfil da profissional. -->
        <a href="meu_perfil.php">Meu Perfil</a>
    </nav>

    <!-- Link utilizado para encerrar a sessão da profissional. -->
    <a href="logout.php" class="btn-sair">Sair</a>
</aside>

<!-- ============================================================
     7. CONTEÚDO PRINCIPAL
     ============================================================ -->

<!-- Container responsável pelo conteúdo localizado ao lado da sidebar. -->
<div class="conteudo-profissional">

    <!-- Área principal da página de atendimentos. -->
    <main class="pagina-atendimentos">

        <!-- Cabeçalho da página. -->
        <section class="cabecalho-pagina">

            <!-- Agrupa o título e a descrição. -->
            <div>

                <!-- Título principal da página. -->
                <h1>Atendimentos</h1>

                <!-- Explica ao usuário a finalidade desta tela. -->
                <p>
                    Consulte os agendamentos do mês e acompanhe a situação
                    de cada atendimento.
                </p>
            </div>
        </section>

        <!-- Mensagens de retorno das ações realizadas. -->
        <?php if ($mensagemResultado !== ""): ?>

            <!-- Exibe uma mensagem de sucesso ou erro. -->
            <div
                class="mensagem-retorno <?= $sucesso ? 'mensagem-sucesso' : 'mensagem-erro' ?>"
                role="status"
            >

                <!-- Escapa a mensagem antes de exibi-la no HTML. -->
                <?= htmlspecialchars($mensagemResultado, ENT_QUOTES, "UTF-8") ?>
            </div>

        <?php endif; ?>

        <!-- Exibe uma mensagem quando houve erro na consulta ao banco. -->
        <?php if ($erroConsulta): ?>

            <!-- Define a mensagem como um alerta para tecnologias assistivas. -->
            <div class="mensagem-erro" role="alert">

                <!-- Informa que os atendimentos não puderam ser carregados. -->
                Não foi possível carregar os atendimentos. Verifique o registro
                de erros do servidor para identificar a causa.
            </div>

        <?php endif; ?>

        <!-- ========================================================
             8. NAVEGAÇÃO ENTRE OS MESES
             ======================================================== -->

        <!-- Seção responsável por trocar o mês exibido. -->
        <section class="navegacao-mes">

            <!-- Link que leva para o mês anterior. -->
            <a
                class="btn-mes"
                href="atendimentos.php?mes=<?= date('n', $dataMesAnterior) ?>&ano=<?= date('Y', $dataMesAnterior) ?><?= $filtroStatus !== '' ? '&status=' . urlencode($filtroStatus) : '' ?>"
                aria-label="Consultar mês anterior"
            >

                <!-- Texto e seta do botão anterior. -->
                &larr; Mês anterior
            </a>

            <!-- Exibe o mês e o ano atualmente selecionados. -->
            <h2>

                <!-- Exibe o nome do mês em português. -->
                <?= htmlspecialchars($meses[$mes], ENT_QUOTES, "UTF-8") ?>

                <!-- Exibe o ano selecionado. -->
                de <?= $ano ?>
            </h2>

            <!-- Link que leva para o próximo mês. -->
            <a
                class="btn-mes"
                href="atendimentos.php?mes=<?= date('n', $dataProximoMes) ?>&ano=<?= date('Y', $dataProximoMes) ?><?= $filtroStatus !== '' ? '&status=' . urlencode($filtroStatus) : '' ?>"
                aria-label="Consultar próximo mês"
            >

                <!-- Texto e seta do botão seguinte. -->
                Próximo mês &rarr;
            </a>
        </section>

        <!-- ========================================================
             9. RESUMO DO MÊS
             ======================================================== -->

        <!-- Área que apresenta os cartões de resumo dos atendimentos. -->
        <section class="resumo-atendimentos">

            <!-- Cartão que apresenta o total de atendimentos. -->
            <article class="cartao-resumo">

                <!-- Nome da informação apresentada no cartão. -->
                <span class="titulo-resumo">Total de atendimentos</span>

                <!-- Quantidade total calculada pelo PHP. -->
                <strong><?= $resumo["total"] ?></strong>
            </article>

            <!-- Cartão com a quantidade de atendimentos concluídos. -->
            <article class="cartao-resumo">

                <!-- Título do indicador. -->
                <span class="titulo-resumo">Concluídos</span>

                <!-- Quantidade de atendimentos concluídos. -->
                <strong><?= $resumo["concluido"] ?></strong>
            </article>

            <!-- Cartão com a quantidade de atendimentos pendentes. -->
            <article class="cartao-resumo">

                <!-- Título do indicador. -->
                <span class="titulo-resumo">Pendentes</span>

                <!-- Quantidade de atendimentos pendentes. -->
                <strong><?= $resumo["pendente"] ?></strong>
            </article>

            <!-- Cartão com a quantidade de atendimentos cancelados. -->
            <article class="cartao-resumo">

                <!-- Título do indicador. -->
                <span class="titulo-resumo">Cancelados</span>

                <!-- Quantidade de atendimentos cancelados. -->
                <strong><?= $resumo["cancelado"] ?></strong>
            </article>

            <!-- Cartão com a quantidade de atendimentos alterados. -->
            <article class="cartao-resumo">

                <!-- Título do indicador. -->
                <span class="titulo-resumo">Alterados</span>

                <!-- Quantidade de atendimentos alterados. -->
                <strong><?= $resumo["alterado"] ?></strong>
            </article>
        </section>

        <!-- ========================================================
             10. FILTROS
             ======================================================== -->

        <!-- Seção responsável pelos filtros dos atendimentos. -->
        <section class="filtros-atendimentos">

            <!-- Título da área de filtros. -->
            <h2>Filtrar atendimentos</h2>

            <!-- Agrupa todos os botões de filtro. -->
            <div class="botoes-filtro">

                <!-- Link que remove os filtros e mostra todos os registros do mês. -->
                <a
                    href="atendimentos.php?mes=<?= $mes ?>&ano=<?= $ano ?>"
                    class="btn-filtro <?= ($filtroStatus === '' && !$somenteHoje) ? 'ativo' : '' ?>"
                >
                    Todos
                </a>

                <!-- Link que mostra somente os atendimentos pendentes. -->
                <a
                    href="atendimentos.php?mes=<?= $mes ?>&ano=<?= $ano ?>&status=pendente"
                    class="btn-filtro <?= ($filtroStatus === 'pendente' && !$somenteHoje) ? 'ativo' : '' ?>"
                >
                    Pendentes
                </a>

                <!-- Link que mostra somente os atendimentos concluídos. -->
                <a
                    href="atendimentos.php?mes=<?= $mes ?>&ano=<?= $ano ?>&status=concluido"
                    class="btn-filtro <?= ($filtroStatus === 'concluido' && !$somenteHoje) ? 'ativo' : '' ?>"
                >
                    Concluídos
                </a>

                <!-- Botão utilizado para visualizar os atendimentos cancelados. -->
                <a
                    href="atendimentos.php?mes=<?= $mes ?>&ano=<?= $ano ?>&status=cancelado"
                    class="btn-filtro btn-filtro-cancelados <?= ($filtroStatus === 'cancelado' && !$somenteHoje) ? 'ativo' : '' ?>"
                >

                    <!-- Texto do filtro de cancelados. -->
                    <span>Cancelados</span>

                    <!-- Só cria o contador quando existem cancelamentos não visualizados. -->
                    <?php if ($novosCancelamentos > 0): ?>

                        <!-- Mostra a quantidade de novos cancelamentos. -->
                        <span
                            class="contador-cancelamentos"
                            aria-label="<?= $novosCancelamentos ?> novos cancelamentos"
                            title="<?= $novosCancelamentos ?> novos cancelamentos"
                        >

                            <!-- Limita a exibição a 99+ quando o número for muito alto. -->
                            <?= $novosCancelamentos > 99 ? "99+" : $novosCancelamentos ?>
                        </span>

                    <?php endif; ?>
                </a>

                <!-- Link que mostra os atendimentos alterados. -->
                <a
                    href="atendimentos.php?mes=<?= $mes ?>&ano=<?= $ano ?>&status=alterado"
                    class="btn-filtro <?= ($filtroStatus === 'alterado' && !$somenteHoje) ? 'ativo' : '' ?>"
                >
                    Alterados
                </a>

                <!-- Botão que ativa a visualização dos agendamentos de hoje. -->
                <a
                    href="atendimentos.php?hoje=1<?= $filtroStatus !== '' ? '&status=' . urlencode($filtroStatus) : '' ?>"
                    class="btn-ver-hoje <?= $somenteHoje ? 'ativo' : '' ?>"
                    aria-pressed="<?= $somenteHoje ? 'true' : 'false' ?>"
                >

                    <!-- Texto exibido no botão. -->
                    Ver agendamentos de hoje
                </a>
            </div>
        </section>

        <!-- ========================================================
             11. LISTA DE ATENDIMENTOS
             ======================================================== -->

        <!-- Seção que apresenta a tabela de atendimentos. -->
        <section class="tabela-atendimentos">

            <!-- Cabeçalho da tabela. -->
            <div class="titulo-tabela">

                <!-- Altera o título dependendo do filtro selecionado. -->
                <h2>
                    <?= $somenteHoje ? "Agendamentos de hoje" : "Lista de atendimentos" ?>
                </h2>

                <!-- Mostra a quantidade de registros encontrados. -->
                <p>

                    <!-- Conta quantos atendimentos existem no resultado atual. -->
                    <?= count($atendimentos) ?>

                    <!-- Texto complementar da quantidade. -->
                    registro(s) encontrado(s)

                    <!-- Exibe uma descrição diferente para o filtro de hoje. -->
                    <?= $somenteHoje
                        ? "para hoje."
                        : "para " . htmlspecialchars($meses[$mes], ENT_QUOTES, "UTF-8") . " de " . $ano . "." ?>
                </p>
            </div>

            <!-- Container que permite adaptar a tabela em telas menores. -->
            <div class="tabela-responsiva">

                <!-- Inicia a tabela de atendimentos. -->
                <table>

                    <!-- Cabeçalho da tabela. -->
                    <thead>

                        <!-- Linha que contém os nomes das colunas. -->
                        <tr>

                            <!-- Coluna da data do atendimento. -->
                            <th>Data</th>

                            <!-- Coluna do horário. -->
                            <th>Horário</th>

                            <!-- Coluna da cliente. -->
                            <th>Cliente</th>

                            <!-- Coluna do serviço realizado. -->
                            <th>Serviço</th>

                            <!-- Coluna do preço final. -->
                            <th>Valor</th>

                            <!-- Coluna da situação do agendamento. -->
                            <th>Status</th>

                            <!-- Coluna das ações disponíveis. -->
                            <th>Ações</th>
                        </tr>
                    </thead>

                    <!-- Corpo da tabela. -->
                    <tbody>

                        <!-- Verifica se existem atendimentos para exibir. -->
                        <?php if (!empty($atendimentos)): ?>

                            <!-- Percorre cada atendimento retornado pelo banco. -->
                            <?php foreach ($atendimentos as $atendimento): ?>

                                <!-- Cria uma linha para cada atendimento. -->
                                <tr>

                                    <!-- Coluna da data. -->
                                    <td>

                                        <!-- Converte a data do banco para o formato brasileiro. -->
                                        <?= date(
                                            "d/m/Y",
                                            strtotime($atendimento["data_agendamento"])
                                        ) ?>
                                    </td>

                                    <!-- Coluna do horário. -->
                                    <td>

                                        <!-- Exibe somente hora e minuto. -->
                                        <?= htmlspecialchars(
                                            substr((string)$atendimento["horario"], 0, 5),
                                            ENT_QUOTES,
                                            "UTF-8"
                                        ) ?>
                                    </td>

                                    <!-- Coluna das informações da cliente. -->
                                    <td>

                                        <!-- Exibe o nome da cliente em destaque. -->
                                        <strong>
                                            <?= htmlspecialchars(
                                                $atendimento["nome_cliente"] ?? "",
                                                ENT_QUOTES,
                                                "UTF-8"
                                            ) ?>
                                        </strong>

                                        <!-- Verifica se a cliente possui telefone cadastrado. -->
                                        <?php if (!empty($atendimento["telefone_cliente"])): ?>

                                            <!-- Exibe o telefone em tamanho menor. -->
                                            <small class="telefone-cliente">
                                                <?= htmlspecialchars(
                                                    $atendimento["telefone_cliente"],
                                                    ENT_QUOTES,
                                                    "UTF-8"
                                                ) ?>
                                            </small>

                                        <?php endif; ?>
                                    </td>

                                    <!-- Coluna com as informações do serviço. -->
                                    <td>

                                        <!-- Exibe o nome do serviço. -->
                                        <strong>
                                            <?= htmlspecialchars(
                                                $atendimento["nome_servico"] ?? "",
                                                ENT_QUOTES,
                                                "UTF-8"
                                            ) ?>
                                        </strong>

                                        <!-- Verifica se existe um tipo de serviço cadastrado. -->
                                        <?php if (!empty($atendimento["tipo_servico"])): ?>

                                            <!-- Exibe o tipo do serviço abaixo do nome. -->
                                            <small class="tipo-servico">
                                                <?= htmlspecialchars(
                                                    ucfirst($atendimento["tipo_servico"]),
                                                    ENT_QUOTES,
                                                    "UTF-8"
                                                ) ?>
                                            </small>

                                        <?php endif; ?>
                                    </td>

                                    <!-- Coluna do valor do atendimento. -->
                                    <td>

                                        <!-- Formata o preço no padrão monetário brasileiro. -->
                                        <?= formatarDinheiro($atendimento["preco_final"]) ?>
                                    </td>

                                    <!-- Coluna que apresenta o status. -->
                                    <td>

                                        <!-- Cria uma classe CSS específica para cada status. -->
                                        <span class="status-atendimento status-<?= htmlspecialchars(
                                            $atendimento["status"],
                                            ENT_QUOTES,
                                            "UTF-8"
                                        ) ?>">

                                            <!-- Exibe o status convertido para uma descrição amigável. -->
                                            <?= htmlspecialchars(
                                                etiquetaStatus($atendimento["status"]),
                                                ENT_QUOTES,
                                                "UTF-8"
                                            ) ?>
                                        </span>
                                    </td>

                                    <!-- Coluna com as ações possíveis para o agendamento. -->
                                    <td class="acoes-atendimento">

                                        <!-- Verifica se o agendamento ainda está pendente. -->
                                        <?php if ($atendimento["status"] === "pendente"): ?>

                                            <!-- Formulário responsável por concluir o atendimento. -->
                                            <form
                                                action="atualizar_status_agendamento.php"
                                                method="POST"
                                                onsubmit="return confirm('Deseja marcar este atendimento como concluído?');"
                                            >

                                                <!-- Token de segurança contra ataques CSRF. -->
                                                <input
                                                    type="hidden"
                                                    name="csrf_token"
                                                    value="<?= htmlspecialchars(
                                                        $_SESSION["csrf_agendamentos"],
                                                        ENT_QUOTES,
                                                        "UTF-8"
                                                    ) ?>"
                                                >

                                                <!-- Identifica qual agendamento será atualizado. -->
                                                <input
                                                    type="hidden"
                                                    name="agendamento_id"
                                                    value="<?= (int)$atendimento["id"] ?>"
                                                >

                                                <!-- Define o novo status como concluído. -->
                                                <input
                                                    type="hidden"
                                                    name="novo_status"
                                                    value="concluido"
                                                >

                                                <!-- Informa ao arquivo de processamento de onde veio a ação. -->
                                                <input
                                                    type="hidden"
                                                    name="origem"
                                                    value="atendimentos.php"
                                                >

                                                <!-- Botão que envia o formulário para concluir o atendimento. -->
                                                <button
                                                    type="submit"
                                                    class="btn-concluir-agendamento"
                                                >
                                                    Concluir
                                                </button>
                                            </form>

                                            <!-- Formulário responsável por cancelar o agendamento. -->
                                            <form
                                                action="atualizar_status_agendamento.php"
                                                method="POST"
                                                onsubmit="return confirm('Deseja realmente cancelar este agendamento?');"
                                            >

                                                <!-- Token de segurança contra requisições falsificadas. -->
                                                <input
                                                    type="hidden"
                                                    name="csrf_token"
                                                    value="<?= htmlspecialchars(
                                                        $_SESSION["csrf_agendamentos"],
                                                        ENT_QUOTES,
                                                        "UTF-8"
                                                    ) ?>"
                                                >

                                                <!-- Informa qual agendamento será cancelado. -->
                                                <input
                                                    type="hidden"
                                                    name="agendamento_id"
                                                    value="<?= (int)$atendimento["id"] ?>"
                                                >

                                                <!-- Define o novo status como cancelado. -->
                                                <input
                                                    type="hidden"
                                                    name="novo_status"
                                                    value="cancelado"
                                                >

                                                <!-- Informa ao processamento a página de origem. -->
                                                <input
                                                    type="hidden"
                                                    name="origem"
                                                    value="atendimentos.php"
                                                >

                                                <!-- Botão que envia a solicitação de cancelamento. -->
                                                <button
                                                    type="submit"
                                                    class="btn-cancelar-agendamento"
                                                >
                                                    Cancelar
                                                </button>
                                            </form>

                                        <?php else: ?>

                                            <!-- Exibe quando o status atual não permite nenhuma ação. -->
                                            <span class="sem-acoes">
                                                Sem ações disponíveis
                                            </span>

                                        <?php endif; ?>
                                    </td>
                                </tr>

                            <!-- Finaliza o loop de atendimentos. -->
                            <?php endforeach; ?>

                        <?php else: ?>

                            <!-- Linha exibida quando não existem registros. -->
                            <tr>

                                <!-- Ocupa as sete colunas da tabela com uma única mensagem. -->
                                <td colspan="7" class="sem-atendimentos">

                                    <!-- Mensagem diferente para o filtro de hoje. -->
                                    <?= $somenteHoje
                                        ? "Nenhum agendamento encontrado para hoje e para o filtro selecionado."
                                        : "Nenhum atendimento encontrado para este mês e filtro." ?>
                                </td>
                            </tr>

                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

    </main>
</div>

</body>
</html>