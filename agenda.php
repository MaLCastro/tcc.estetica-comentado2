<?php
// ============================================================
// INICIAR SESSÃO, CONEXÃO E VALIDAR ACESSO
// ============================================================

// Inicia a sessão para permitir o acesso aos dados da cliente autenticada.
session_start();

// Carrega o arquivo responsável pela conexão com o banco de dados.
require_once("conexao.php");

// Permite o acesso somente para clientes autenticadas.
if (!isset($_SESSION["usuario_id"]) || ($_SESSION["usuario_tipo"] ?? "") !== "cliente") {
    // Redireciona usuários sem autorização para a página de login.
    header("Location: login.php");
    exit();
}

// Recebe e valida os identificadores enviados pela página de serviços.
$servico_id = filter_input(INPUT_GET, "servico_id", FILTER_VALIDATE_INT);
$profissional_id = filter_input(INPUT_GET, "profissional_id", FILTER_VALIDATE_INT);
$data_recebida = $_GET["data"] ?? date("Y-m-d");

// Define o fuso horário utilizado pelo sistema.
date_default_timezone_set("America/Sao_Paulo");

// Armazena a data atual para comparar com as datas escolhidas.
$data_hoje = date("Y-m-d");

// Inicializa as variáveis utilizadas durante o carregamento da agenda.
$erro = "";
$servico = null;
$profissional = null;
$horarios_dia = [];
$status_dia = "";

// ============================================================
// FUNÇÕES AUXILIARES DE FORMATAÇÃO E NAVEGAÇÃO
// ============================================================

// Protege textos exibidos no HTML contra interpretação de código.
function escapar($valor)
{
    return htmlspecialchars((string)$valor, ENT_QUOTES, "UTF-8");
}

// Converte durações cadastradas em minutos, aceitando formatos comuns.
function converterDuracaoMinutos($duracao)
{
    // Converte o valor recebido para texto e remove espaços extras.
    $texto = trim((string)$duracao);

    // Se o valor já estiver somente em minutos, retorna o número diretamente.
    if (ctype_digit($texto)) {
        return (int)$texto;
    }

    // Identifica durações no formato de horas e minutos.
    if (preg_match('/^(\d+)\s*h(?:\s*(\d+)\s*(?:min)?)?$/u', mb_strtolower($texto), $partes)) {
        return ((int)$partes[1] * 60) + (isset($partes[2]) ? (int)$partes[2] : 0);
    }

    // Identifica durações informadas somente em minutos.
    if (preg_match('/^(\d+)\s*min$/u', mb_strtolower($texto), $partes)) {
        return (int)$partes[1];
    }

    // Identifica durações no formato horas:minutos.
    if (preg_match('/^(\d+):(\d+)$/', $texto, $partes)) {
        return ((int)$partes[1] * 60) + (int)$partes[2];
    }

    // Retorna zero caso a duração não esteja em nenhum formato reconhecido.
    return 0;
}

// Apresenta a duração em um formato simples para a cliente.
function formatarDuracaoAmigavel($minutos)
{
    // Calcula a quantidade de horas existentes na duração.
    $horas = intdiv((int)$minutos, 60);

    // Calcula os minutos restantes depois das horas completas.
    $minutos_restantes = (int)$minutos % 60;

    // Exibe horas e minutos quando ambos possuem valor.
    if ($horas > 0 && $minutos_restantes > 0) {
        return "{$horas}h{$minutos_restantes}min";
    }

    // Exibe somente as horas quando não existem minutos restantes.
    if ($horas > 0) {
        return "{$horas}h";
    }

    // Exibe somente os minutos quando a duração é inferior a uma hora.
    return "{$minutos_restantes}min";
}

// Monta os links mantendo o serviço, a profissional e a data escolhida.
function urlAgenda($servico_id, $profissional_id, $data)
{
    // Constrói a URL da agenda utilizando os parâmetros necessários.
    return "agenda.php?" . http_build_query([
        "servico_id" => $servico_id,
        "profissional_id" => $profissional_id,
        "data" => $data
    ]);
}

// Valida datas recebidas pela URL para impedir datas inexistentes.
function dataValida($valor)
{
    // Verifica se o valor é texto e segue o formato YYYY-MM-DD.
    if (!is_string($valor) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor)) {
        return false;
    }

    // Cria um objeto de data para confirmar se a data realmente existe.
    $data = DateTime::createFromFormat("!Y-m-d", $valor);

    // Confirma se a data criada corresponde exatamente ao valor recebido.
    return $data && $data->format("Y-m-d") === $valor;
}

// ============================================================
// VALIDAR A DATA E DEFINIR O MÊS DO CALENDÁRIO
// ============================================================

// Se a data recebida for inválida, utiliza a data atual.
if (!dataValida($data_recebida)) {
    $data_recebida = $data_hoje;
}

// Não permite abrir uma data selecionada anterior ao dia atual.
$data_selecionada = ($data_recebida < $data_hoje) ? $data_hoje : $data_recebida;

// Cria o objeto correspondente à data selecionada.
$data_obj_selecionada = new DateTime($data_selecionada);

// Define o primeiro dia do mês da data selecionada.
$mes_obj = new DateTime($data_obj_selecionada->format("Y-m-01"));

// Cria o objeto referente ao mês anterior.
$mes_anterior_obj = (clone $mes_obj)->modify("-1 month");

// Cria o objeto referente ao mês seguinte.
$mes_seguinte_obj = (clone $mes_obj)->modify("+1 month");

// Obtém o número do mês e o ano para montar o calendário.
$mes = (int)$mes_obj->format("m");
$ano = (int)$mes_obj->format("Y");

// Lista os nomes dos meses utilizados na apresentação do calendário.
$nome_mes = [
    1 => "Janeiro", 2 => "Fevereiro", 3 => "Março", 4 => "Abril",
    5 => "Maio", 6 => "Junho", 7 => "Julho", 8 => "Agosto",
    9 => "Setembro", 10 => "Outubro", 11 => "Novembro", 12 => "Dezembro"
];

// ============================================================
// CARREGAR O SERVIÇO E OS DADOS DA PROFISSIONAL
// ============================================================

// Verifica se os identificadores necessários foram recebidos.
if (!$servico_id || !$profissional_id) {
    // Informa que o serviço ou a profissional não foi identificado.
    $erro = "Serviço ou profissional não informado.";
} else {
    try {
        // Usa a vinculação para confirmar que o serviço pertence à profissional.
        $consulta = $conexao->prepare("
            SELECT
                s.id,
                s.nome_servico,
                s.duracao,
                s.preco,
                v.duracao_customizada,
                v.preco_customizado,
                u.nome AS profissional_nome
            FROM servicos s
            INNER JOIN vinculacao v
                ON v.servico_id = s.id
               AND v.profissional_id = :pid
            INNER JOIN usuarios u ON u.id = v.profissional_id
            WHERE s.id = :sid
              AND s.status = 'ativo'
              AND u.status = 'ativo'
            LIMIT 1
        ");

        // Executa a consulta utilizando o serviço e a profissional selecionados.
        $consulta->execute([":sid" => $servico_id, ":pid" => $profissional_id]);

        // Recupera os dados encontrados para o serviço.
        $servico = $consulta->fetch(PDO::FETCH_ASSOC);

        // Verifica se o serviço foi encontrado e está disponível para a profissional.
        if (!$servico) {
            $erro = "Serviço indisponível para esta profissional.";
        } else {
            // Prioriza preço e duração personalizados, quando cadastrados.
            $duracao_texto = !empty($servico["duracao_customizada"])
                ? $servico["duracao_customizada"]
                : $servico["duracao"];

            // Converte a duração do serviço para minutos.
            $duracao_servico = converterDuracaoMinutos($duracao_texto);

            // Formata a duração para ser apresentada na página.
            $duracao_exibicao = formatarDuracaoAmigavel($duracao_servico);

            // Define o preço personalizado quando existir; caso contrário, utiliza o preço original.
            $preco_final = ($servico["preco_customizado"] !== null && $servico["preco_customizado"] !== "")
                ? (float)$servico["preco_customizado"]
                : (float)$servico["preco"];

            // Armazena o nome da profissional responsável pelo serviço.
            $profissional = $servico["profissional_nome"];

            // Verifica se a duração encontrada é válida.
            if ($duracao_servico <= 0) {
                $erro = "A duração deste serviço precisa ser conferida pela profissional.";
            }
        }
    } catch (Throwable $e) {
        // Registra o erro técnico no log do servidor.
        error_log("Erro ao carregar serviço na agenda: " . $e->getMessage());

        // Exibe uma mensagem genérica para a cliente.
        $erro = "Não foi possível carregar os dados do serviço. Tente novamente.";
    }
}

// ============================================================
// MONTAR AS CÉLULAS DO CALENDÁRIO MENSAL
// ============================================================

// Inicializa a lista que armazenará os dias exibidos no calendário.
$dias_calendario = [];

// Monta o calendário somente quando o serviço foi carregado sem erros.
if (!$erro && $servico) {
    // Cria espaços antes do primeiro dia para alinhar domingo a sábado.
    $primeiro_dia_mes = new DateTime(sprintf("%04d-%02d-01", $ano, $mes));

    // Obtém o dia da semana em que o mês começa.
    $dia_semana_inicio = (int)$primeiro_dia_mes->format("w");

    // Obtém a quantidade total de dias existentes no mês.
    $quantidade_dias = (int)$primeiro_dia_mes->format("t");

    // Adiciona espaços antes do primeiro dia do mês.
    for ($i = 0; $i < $dia_semana_inicio; $i++) {
        $dias_calendario[] = null;
    }

    // Adiciona todos os dias do mês ao calendário.
    for ($dia = 1; $dia <= $quantidade_dias; $dia++) {
        $dias_calendario[] = $dia;
    }

    // Completa a última semana para manter a grade alinhada.
    while (count($dias_calendario) % 7 !== 0) {
        $dias_calendario[] = null;
    }

    // Consulta os horários somente para a data selecionada.
    $dia_semana_selecionado = (int)$data_obj_selecionada->format("w");

    try {
        // Verifica se a profissional liberou a data para agendamentos.
        $consulta = $conexao->prepare("
            SELECT COUNT(*)
            FROM liberacao_agenda
            WHERE profissional_id = :pid
              AND :data_inicio >= data_inicio
              AND :data_fim <= data_fim
        ");

        // Executa a consulta utilizando a profissional e a data selecionada.
        $consulta->execute([
            ":pid" => $profissional_id,
            ":data_inicio" => $data_selecionada,
            ":data_fim" => $data_selecionada
        ]);

        // Define se existe pelo menos uma liberação válida para a data.
        $liberada = (int)$consulta->fetchColumn() > 0;

        // Carrega a jornada configurada para o dia da semana selecionado.
        $consulta = $conexao->prepare("
            SELECT trabalha, horario_inicio, ultimo_horario, inicio_almoco, fim_almoco
            FROM horarios
            WHERE profissional_id = :pid AND dia_semana = :dia
            LIMIT 1
        ");

        // Executa a consulta referente ao dia da semana selecionado.
        $consulta->execute([
            ":pid" => $profissional_id,
            ":dia" => $dia_semana_selecionado
        ]);

        // Recupera a configuração de horário da profissional.
        $jornada = $consulta->fetch(PDO::FETCH_ASSOC);

        // Verifica se a data escolhida já passou.
        if ($data_selecionada < $data_hoje) {
            $status_dia = "Escolha uma data de hoje em diante.";

        // Verifica se a profissional liberou a data para agendamento.
        } elseif (!$liberada) {
            $status_dia = "Esta data ainda não foi liberada para agendamentos.";

        // Verifica se a profissional trabalha no dia selecionado.
        } elseif (!$jornada || (int)$jornada["trabalha"] !== 1) {
            $status_dia = "A profissional não atende neste dia.";

        // Verifica se os horários de trabalho foram configurados.
        } elseif (empty($jornada["horario_inicio"]) || empty($jornada["ultimo_horario"])) {
            $status_dia = "Os horários de atendimento ainda não foram configurados.";
        } else {
            // Gera opções respeitando a duração do serviço.
            $inicio_jornada = new DateTime($data_selecionada . " " . $jornada["horario_inicio"]);

            // Define o horário final da jornada.
            $fim_jornada = new DateTime($data_selecionada . " " . $jornada["ultimo_horario"]);

            // Define o intervalo utilizado para gerar novos horários.
            $passo_minutos = 30;

            // Obtém o horário atual para bloquear horários que já passaram.
            $agora = new DateTime();

            // Define o início do intervalo de almoço, quando configurado.
            $inicio_almoco = !empty($jornada["inicio_almoco"])
                ? new DateTime($data_selecionada . " " . $jornada["inicio_almoco"])
                : null;

            // Define o fim do intervalo de almoço, quando configurado.
            $fim_almoco = !empty($jornada["fim_almoco"])
                ? new DateTime($data_selecionada . " " . $jornada["fim_almoco"])
                : null;

            // Busca todos os agendamentos não cancelados da profissional nesse dia.
            $consulta = $conexao->prepare("
                SELECT a.horario, s.duracao
                FROM agendamentos a
                LEFT JOIN servicos s ON s.id = a.servico_id
                WHERE a.profissional_id = :pid
                  AND a.data_agendamento = :data
                  AND a.status <> 'cancelado'
            ");

            // Executa a consulta dos agendamentos existentes.
            $consulta->execute([
                ":pid" => $profissional_id,
                ":data" => $data_selecionada
            ]);

            // Armazena os agendamentos encontrados.
            $agendamentos_existentes = $consulta->fetchAll(PDO::FETCH_ASSOC);

            // Busca os bloqueios de horário do dia selecionado.
            $consulta = $conexao->prepare("
                SELECT horario_inicio, horario_fim
                FROM bloqueios_agenda
                WHERE profissional_id = :pid
                  AND data_bloqueio = :data
            ");

            // Executa a consulta dos bloqueios cadastrados.
            $consulta->execute([
                ":pid" => $profissional_id,
                ":data" => $data_selecionada
            ]);

            // Armazena os períodos bloqueados encontrados.
            $bloqueios = $consulta->fetchAll(PDO::FETCH_ASSOC);

            // Avança de 30 em 30 minutos para permitir encaixes entre serviços.
            $cursor = clone $inicio_jornada;

            // Continua gerando horários até atingir o final da jornada.
            while (true) {
                // Calcula o horário em que o serviço terminaria.
                $fim_opcao = (clone $cursor)->modify("+{$duracao_servico} minutes");

                // O atendimento precisa terminar até o limite da jornada.
                if ($fim_opcao > $fim_jornada) {
                    break;
                }

                // Converte o horário atual em texto para apresentação.
                $horario_texto = $cursor->format("H:i");

                // Inicialmente considera o horário como disponível.
                $ocupado = false;

                // Não permite horários que já passaram no dia atual.
                if ($data_selecionada === $data_hoje && $cursor <= $agora) {
                    $ocupado = true;
                }

                // Impede que o serviço atravesse o intervalo de almoço.
                if ($inicio_almoco && $fim_almoco && $cursor < $fim_almoco && $fim_opcao > $inicio_almoco) {
                    $ocupado = true;
                }

                // Impede sobreposição com qualquer agendamento existente.
                foreach ($agendamentos_existentes as $agendamento) {
                    // Cria o horário inicial do agendamento existente.
                    $inicio_existente = new DateTime($data_selecionada . " " . $agendamento["horario"]);

                    // Converte a duração do agendamento existente para minutos.
                    $duracao_existente = converterDuracaoMinutos($agendamento["duracao"] ?? "");

                    // Utiliza 30 minutos como duração padrão quando não houver duração válida.
                    if ($duracao_existente <= 0) {
                        $duracao_existente = 30;
                    }

                    // Calcula o horário final do agendamento existente.
                    $fim_existente = (clone $inicio_existente)->modify("+{$duracao_existente} minutes");

                    // Verifica se o novo horário se sobrepõe ao agendamento existente.
                    if ($cursor < $fim_existente && $fim_opcao > $inicio_existente) {
                        $ocupado = true;
                        break;
                    }
                }

                // Impede sobreposição com bloqueios e folgas da profissional.
                foreach ($bloqueios as $bloqueio) {
                    // Define o início do período bloqueado.
                    $inicio_bloqueio = new DateTime($data_selecionada . " " . $bloqueio["horario_inicio"]);

                    // Define o final do período bloqueado.
                    $fim_bloqueio = new DateTime($data_selecionada . " " . $bloqueio["horario_fim"]);

                    // Verifica se o horário gerado entra em conflito com o bloqueio.
                    if ($cursor < $fim_bloqueio && $fim_opcao > $inicio_bloqueio) {
                        $ocupado = true;
                        break;
                    }
                }

                // Só mostra opções realmente disponíveis para seleção.
                if (!$ocupado) {
                    // Adiciona o horário disponível e o link para a confirmação.
                    $horarios_dia[] = [
                        "horario" => $horario_texto,
                        "link" => "confirmar_agendamento.php?" . http_build_query([
                            "servico_id" => $servico_id,
                            "profissional_id" => $profissional_id,
                            "data" => $data_selecionada,
                            "horario" => $horario_texto
                        ])
                    ];
                }

                // Avança o cursor para o próximo intervalo de 30 minutos.
                $cursor->modify("+{$passo_minutos} minutes");
            }

            // Informa quando nenhum horário ficou disponível após as verificações.
            if (empty($horarios_dia)) {
                $status_dia = "Não há horários disponíveis para esta data.";
            }
        }
    } catch (Throwable $e) {
        // Registra o erro técnico ocorrido durante a consulta da disponibilidade.
        error_log("Erro ao carregar disponibilidade da agenda: " . $e->getMessage());

        // Exibe uma mensagem amigável para a cliente.
        $status_dia = "Não foi possível consultar os horários desta data.";
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <!-- Define a codificação utilizada pela página. -->
    <meta charset="UTF-8">

    <!-- Ajusta a página para diferentes tamanhos de tela. -->
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- Define o título exibido na aba do navegador. -->
    <title>Escolher Horário | TCC Estética</title>

    <!-- Carrega o arquivo CSS externo utilizado pelo sistema. -->
    <link rel="stylesheet" href="style.css">
</head>

<!-- Define as classes utilizadas para identificar a página da cliente e da agenda. -->
<body class="pagina-cliente pagina-agenda">

    <!-- Cabeçalho compartilhado com as páginas da cliente. -->
    <header class="cabecalho-cliente">

        <!-- Exibe a identificação do sistema. -->
        <div class="logo-cliente"><h1>Estética</h1></div>

        <!-- Menu principal de navegação da área da cliente. -->
        <nav class="menu-cliente">

            <!-- Link para o painel principal da cliente. -->
            <a href="painel_cliente.php">Início</a>

            <!-- Link para o catálogo de serviços, marcado como página ativa. -->
            <a href="servicos.php" class="ativo">Serviços</a>

            <!-- Link para os agendamentos realizados pela cliente. -->
            <a href="meus_agendamentos.php">Meus Agendamentos</a>

            <!-- Link para encerrar a sessão. -->
            <a href="logout.php" class="menu-sair">Sair</a>
        </nav>
    </header>

    <!-- Conteúdo principal da página de escolha de horário. -->
    <main class="agenda-container">

        <?php if ($erro): ?>

            <!-- Apresenta erros de parâmetro ou de carregamento sem quebrar a página. -->
            <section class="agenda-cabecalho">

                <!-- Título apresentado quando existe algum erro. -->
                <h2>Escolha seu horário</h2>

                <!-- Exibe a mensagem de erro protegida contra código HTML. -->
                <div class="agenda-mensagem agenda-mensagem-erro">
                    <?= escapar($erro) ?>
                </div>

                <!-- Permite retornar ao catálogo de serviços. -->
                <a href="servicos.php" class="botao-voltar">Voltar aos serviços</a>
            </section>

        <?php else: ?>

            <!-- Mantém os dados principais do serviço e da profissional em destaque. -->
            <section class="agenda-cabecalho">

                <!-- Área com o título e a explicação da agenda. -->
                <div class="agenda-titulo">

                    <!-- Identificação visual da etapa do sistema. -->
                    <span class="agenda-label">AGENDAMENTO</span>

                    <!-- Título principal da página. -->
                    <h2>Escolha seu horário</h2>

                    <!-- Orientação para a cliente selecionar uma data. -->
                    <p>Selecione uma data e confira os horários disponíveis.</p>
                </div>

                <!-- Resumo das informações do serviço selecionado. -->
                <div class="agenda-resumo">

                    <!-- Identificação da profissional responsável pelo serviço. -->
                    <div class="agenda-resumo-profissional">

                        <!-- Exibe a primeira letra do nome da profissional como avatar. -->
                        <span class="agenda-avatar" aria-hidden="true"><?= escapar(mb_substr($profissional, 0, 1, "UTF-8")) ?></span>

                        <div>
                            <!-- Legenda que identifica o nome da profissional. -->
                            <span class="agenda-resumo-legenda">PROFISSIONAL</span>

                            <!-- Exibe o nome da profissional. -->
                            <strong><?= escapar($profissional) ?></strong>
                        </div>
                    </div>

                    <!-- Identificação do serviço selecionado. -->
                    <div class="agenda-resumo-servico">

                        <!-- Legenda do serviço. -->
                        <span class="agenda-resumo-legenda">SERVIÇO</span>

                        <!-- Nome do serviço selecionado. -->
                        <strong><?= escapar($servico["nome_servico"]) ?></strong>
                    </div>

                    <!-- Exibe duração e valor do serviço. -->
                    <div class="agenda-resumo-detalhes">

                        <!-- Informação referente à duração. -->
                        <div class="agenda-detalhe">
                            <span>Duração</span>
                            <strong><?= escapar($duracao_exibicao) ?></strong>
                        </div>

                        <!-- Informação referente ao preço final. -->
                        <div class="agenda-detalhe">
                            <span>Valor do serviço</span>
                            <strong>R$ <?= number_format($preco_final, 2, ",", ".") ?></strong>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Calendário mensal com navegação por setas. -->
            <section class="agenda-calendario">

                <!-- Cabeçalho do calendário. -->
                <div class="agenda-calendario-topo">

                    <!-- Identificação da seção de disponibilidade. -->
                    <div>
                        <span class="agenda-label">DISPONIBILIDADE</span>
                        <h3>Selecione uma data</h3>
                    </div>

                    <!-- Controles para navegar entre os meses. -->
                    <div class="agenda-navegacao-mes">

                        <!-- Permite voltar um mês somente quando não for o mês atual. -->
                        <?php if ($mes_obj->format("Y-m") > date("Y-m")): ?>

                            <!-- Link para o mês anterior. -->
                            <a class="agenda-seta" aria-label="Mês anterior"
                               href="<?= escapar(urlAgenda($servico_id, $profissional_id, $mes_anterior_obj->format("Y-m-01"))) ?>">
                                <span aria-hidden="true">&#8249;</span>
                            </a>

                        <?php else: ?>

                            <!-- Exibe a seta anterior desativada no mês atual. -->
                            <span class="agenda-seta desativada" aria-hidden="true">&#8249;</span>

                        <?php endif; ?>

                        <!-- Exibe o mês e o ano atualmente selecionados. -->
                        <strong><?= escapar($nome_mes[$mes]) ?> de <?= $ano ?></strong>

                        <!-- Link para avançar para o próximo mês. -->
                        <a class="agenda-seta" aria-label="Próximo mês"
                           href="<?= escapar(urlAgenda($servico_id, $profissional_id, $mes_seguinte_obj->format("Y-m-01"))) ?>">
                            <span aria-hidden="true">&#8250;</span>
                        </a>
                    </div>
                </div>

                <!-- Grade responsável pela exibição dos dias do calendário. -->
                <div class="agenda-calendario-grade">

                    <!-- Exibe os nomes dos dias da semana. -->
                    <?php foreach (["Dom", "Seg", "Ter", "Qua", "Qui", "Sex", "Sáb"] as $nome_dia): ?>

                        <!-- Nome individual do dia da semana. -->
                        <div class="agenda-dia-semana"><?= escapar($nome_dia) ?></div>

                    <?php endforeach; ?>

                    <!-- Percorre todos os espaços e dias montados no calendário. -->
                    <?php foreach ($dias_calendario as $dia_numero): ?>

                        <!-- Exibe um espaço quando a posição não representa um dia do mês. -->
                        <?php if ($dia_numero === null): ?>

                            <div class="agenda-dia-vazio" aria-hidden="true"></div>

                        <?php else: ?>

                            <?php
                            // Identifica a data e o estado visual de cada dia.

                            // Monta a data completa correspondente ao número do dia.
                            $data_dia = sprintf("%04d-%02d-%02d", $ano, $mes, $dia_numero);

                            // Verifica se o dia já passou.
                            $dia_passado = $data_dia < $data_hoje;

                            // Verifica se o dia corresponde à data selecionada.
                            $dia_selecionado = $data_dia === $data_selecionada;

                            // Inicializa as classes visuais utilizadas pelo dia.
                            $classes_dia = ["agenda-dia-calendario"];

                            // Adiciona a classe para dias que já passaram.
                            if ($dia_passado) {
                                $classes_dia[] = "dia-passado";
                            }

                            // Adiciona a classe para o dia atualmente selecionado.
                            if ($dia_selecionado) {
                                $classes_dia[] = "dia-selecionado";
                            }
                            ?>

                            <!-- Link que permite selecionar o dia correspondente. -->
                            <a class="<?= escapar(implode(" ", $classes_dia)) ?>"
                               href="<?= escapar(urlAgenda($servico_id, $profissional_id, $data_dia)) ?>"
                               aria-label="<?= escapar($dia_numero . " de " . $nome_mes[$mes]) ?>"
                               <?= $dia_selecionado ? 'aria-current="date"' : '' ?>>

                                <!-- Número do dia exibido no calendário. -->
                                <span><?= (int)$dia_numero ?></span>

                                <!-- Indica visualmente o dia atualmente selecionado. -->
                                <?php if ($dia_selecionado): ?>
                                    <small>Selecionado</small>
                                <?php endif; ?>

                            </a>
                        <?php endif; ?>

                    <?php endforeach; ?>
                </div>

                <!-- Legenda das marcações utilizadas no calendário. -->
                <div class="agenda-legenda">
                    <span><i class="legenda-ponto ponto-selecionado"></i> Data selecionada</span>
                    <span><i class="legenda-ponto ponto-passado"></i> Data passada</span>
                </div>
            </section>

            <!-- Mostra apenas os horários referentes à data escolhida. -->
            <section class="agenda-horarios">

                <!-- Cabeçalho da seção de horários. -->
                <div class="agenda-horarios-topo">

                    <!-- Exibe a identificação e a data selecionada. -->
                    <div>
                        <span class="agenda-label">HORÁRIOS</span>
                        <h3><?= escapar($data_obj_selecionada->format("d/m/Y")) ?></h3>
                    </div>

                    <!-- Orientação para a seleção do horário. -->
                    <span class="agenda-horarios-ajuda">Selecione um horário para continuar</span>
                </div>

                <!-- Exibe uma mensagem quando não há disponibilidade. -->
                <?php if ($status_dia !== ""): ?>
                    <div class="agenda-mensagem agenda-mensagem-info"><?= escapar($status_dia) ?></div>
                <?php endif; ?>

                <!-- Exibe os horários quando existem opções disponíveis. -->
                <?php if (!empty($horarios_dia)): ?>

                    <!-- Grade com as opções de horários. -->
                    <div class="agenda-horarios-grade">

                        <!-- Percorre todos os horários disponíveis. -->
                        <?php foreach ($horarios_dia as $horario): ?>

                            <!-- Link que leva para a confirmação do agendamento. -->
                            <a class="agenda-horario-opcao" href="<?= escapar($horario["link"]) ?>">
                                <?= escapar($horario["horario"]) ?>
                            </a>

                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <!-- Mantém o caminho de retorno para o catálogo de serviços. -->
            <div class="agenda-rodape">

                <!-- Link para retornar à lista de serviços. -->
                <a href="servicos.php" class="botao-voltar">Voltar aos serviços</a>

                <!-- Informa que ainda existe uma etapa antes da confirmação final. -->
                <p>O horário será confirmado após a próxima etapa do agendamento.</p>
            </div>

        <?php endif; ?>
    </main>
</body>
</html>