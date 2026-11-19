<?php

// ============================================================
// INICIAR SESSÃO E CONEXÃO
// ============================================================

// Inicia a sessão para permitir o acesso aos dados da cliente logada.
session_start();

// Inclui o arquivo responsável pela conexão com o banco de dados.
require_once("conexao.php");

// Define o fuso horário utilizado pelo sistema.
date_default_timezone_set("America/Sao_Paulo");


// ============================================================
// VERIFICAÇÃO DE SEGURANÇA
// ============================================================

// Verifica se existe um usuário autenticado na sessão.
// Caso não exista, a pessoa é redirecionada para o login.
if (!isset($_SESSION["usuario_id"])) {
    header("Location: login.php");
    exit();
}

// Verifica se o usuário autenticado realmente possui o tipo "cliente".
// Isso impede que profissionais acessem a área destinada às clientes.
if (
    !isset($_SESSION["usuario_tipo"]) ||
    $_SESSION["usuario_tipo"] !== "cliente"
) {
    header("Location: login.php");
    exit();
}


// ============================================================
// DADOS DA CLIENTE
// ============================================================

// Recupera o ID da cliente armazenado na sessão.
// O casting para inteiro ajuda a garantir que o valor seja tratado como número.
$cliente_id = (int) $_SESSION["usuario_id"];

// Recupera o nome da cliente armazenado na sessão.
// Caso o nome não exista, utiliza "Cliente" como valor padrão.
$nome_cliente = $_SESSION["usuario_nome"] ?? "Cliente";

// Divide o nome completo em partes utilizando o espaço como separador.
$partes_nome = explode(" ", trim($nome_cliente));

// Recupera somente o primeiro nome para utilizar na saudação.
$primeiro_nome = $partes_nome[0] ?? "Cliente";


// ============================================================
// DEFINIR A SEÇÃO ATUAL
// ============================================================

// Verifica se foi enviada uma seção pela URL.
// Caso não exista, a página inicia na seção "inicio".
$secao = $_GET["secao"] ?? "inicio";

// Define quais seções podem ser acessadas dentro deste painel.
$secoes_permitidas = [
    "inicio",
    "servicos",
    "agendamentos",
    "fidelidade"
];

// Verifica se a seção recebida está na lista permitida.
// Caso seja inválida, retorna para a seção inicial.
if (!in_array($secao, $secoes_permitidas, true)) {
    $secao = "inicio";
}


// ============================================================
// CATEGORIAS DOS SERVIÇOS
// ============================================================

// Recupera a categoria enviada pela URL.
// Caso nenhuma categoria tenha sido selecionada, utiliza uma string vazia.
$categoria = $_GET["categoria"] ?? "";

// Define as categorias de serviços permitidas pelo sistema.
$categorias_permitidas = [
    "nail",
    "lash",
    "eyebrow"
];

// Verifica se a categoria informada é válida.
// Caso contrário, limpa o valor para impedir categorias inexistentes.
if (!in_array($categoria, $categorias_permitidas, true)) {
    $categoria = "";
}

// Cria um mapa entre o código da especialidade e seu nome para exibição.
$nomes_categorias = [
    "nail" => "Unhas",
    "lash" => "Cílios",
    "eyebrow" => "Sobrancelhas"
];


// ============================================================
// BUSCAR DADOS ATUAIS DA CLIENTE
// ============================================================

// Cria valores iniciais para evitar que as variáveis fiquem inexistentes.
$cliente = [
    "nome" => $nome_cliente,
    "email" => "",
    "telefone" => ""
];

try {

    // Monta a consulta para buscar os dados atuais da cliente.
    // O filtro pelo tipo_usuario garante que o ID pertença a uma cliente.
    $sql_cliente = "SELECT nome, email, telefone
                    FROM usuarios
                    WHERE id = :id
                      AND tipo_usuario = 'cliente'
                    LIMIT 1";

    // Prepara a consulta utilizando PDO.
    $stmt_cliente = $conexao->prepare($sql_cliente);

    // Substitui o parâmetro :id pelo ID da cliente.
    $stmt_cliente->bindValue(
        ":id",
        $cliente_id,
        PDO::PARAM_INT
    );

    // Executa a consulta.
    $stmt_cliente->execute();

    // Recupera os dados encontrados como um array associativo.
    $dados_cliente = $stmt_cliente->fetch(PDO::FETCH_ASSOC);

    // Verifica se a cliente foi encontrada.
    if ($dados_cliente) {

        // Substitui os valores iniciais pelos dados reais do banco.
        $cliente = $dados_cliente;

        // Atualiza também o nome armazenado na sessão.
        $_SESSION["usuario_nome"] = $cliente["nome"];

        // Divide novamente o nome atualizado em partes.
        $partes_nome = explode(" ", trim($cliente["nome"]));

        // Recupera somente o primeiro nome.
        $primeiro_nome = $partes_nome[0] ?? "Cliente";
    }

} catch (PDOException $erro) {

    // Registra o erro no log do servidor sem exibir informações técnicas para a cliente.
    error_log(
        "Erro ao buscar dados da cliente: " .
        $erro->getMessage()
    );
}


// ============================================================
// BUSCAR O PRÓXIMO AGENDAMENTO
// ============================================================

// Inicializa a variável sem nenhum agendamento encontrado.
$proximo_agendamento = null;

try {

    // Consulta o próximo agendamento pendente da cliente.
    $sql_proximo = "SELECT
                        a.id,
                        a.data_agendamento,
                        a.horario,
                        a.status,
                        s.nome_servico,
                        s.preco,
                        s.duracao,
                        u.nome AS profissional_nome,
                        u.especialidade
                    FROM agendamentos a

                    LEFT JOIN servicos s
                        ON a.servico_id = s.id

                    LEFT JOIN usuarios u
                        ON a.profissional_id = u.id

                    WHERE a.cliente_id = :cliente_id
                      AND a.data_agendamento >= CURDATE()
                      AND a.status = 'pendente'

                    ORDER BY
                        a.data_agendamento ASC,
                        a.horario ASC

                    LIMIT 1";

    // Prepara a consulta SQL.
    $stmt_proximo = $conexao->prepare($sql_proximo);

    // Informa qual cliente deve ser pesquisada.
    $stmt_proximo->bindValue(
        ":cliente_id",
        $cliente_id,
        PDO::PARAM_INT
    );

    // Executa a consulta.
    $stmt_proximo->execute();

    // Recupera somente o primeiro resultado,
    // que será o próximo atendimento encontrado.
    $proximo_agendamento = $stmt_proximo->fetch(
        PDO::FETCH_ASSOC
    );

} catch (PDOException $erro) {

    // Registra qualquer erro ocorrido durante a consulta.
    error_log(
        "Erro ao buscar próximo agendamento: " .
        $erro->getMessage()
    );
}


// ============================================================
// BUSCAR TODOS OS AGENDAMENTOS DA CLIENTE
// ============================================================

// Inicializa a lista de agendamentos como um array vazio.
$agendamentos_cliente = [];

try {

    // Busca todos os agendamentos pertencentes à cliente.
    // Também busca informações do serviço e da profissional.
    $sql_agendamentos = "SELECT
                            a.id,
                            a.data_agendamento,
                            a.horario,
                            a.status,
                            s.nome_servico,
                            s.preco,
                            s.duracao,
                            u.nome AS profissional_nome,
                            u.especialidade
                         FROM agendamentos a

                         LEFT JOIN servicos s
                            ON a.servico_id = s.id

                         LEFT JOIN usuarios u
                            ON a.profissional_id = u.id

                         WHERE a.cliente_id = :cliente_id

                         ORDER BY
                            a.data_agendamento DESC,
                            a.horario DESC";

    // Prepara a consulta.
    $stmt_agendamentos = $conexao->prepare(
        $sql_agendamentos
    );

    // Envia o ID da cliente para o parâmetro da consulta.
    $stmt_agendamentos->bindValue(
        ":cliente_id",
        $cliente_id,
        PDO::PARAM_INT
    );

    // Executa a consulta.
    $stmt_agendamentos->execute();

    // Recupera todos os registros encontrados.
    $agendamentos_cliente = $stmt_agendamentos->fetchAll(
        PDO::FETCH_ASSOC
    );

} catch (PDOException $erro) {

    // Registra o erro no log do servidor.
    error_log(
        "Erro ao buscar agendamentos da cliente: " .
        $erro->getMessage()
    );
}


// ============================================================
// BUSCAR PROGRESSO DO PROGRAMA DE FIDELIDADE
// ============================================================

// Inicializa a lista de profissionais.
$fidelidade_profissionais = [];

try {

    // Busca as profissionais ativas e conta quantos atendimentos
    // concluídos a cliente realizou com cada uma delas.
    $sql_fidelidade = "SELECT
                            u.id AS profissional_id,
                            u.nome AS profissional_nome,
                            u.especialidade,
                            COUNT(a.id) AS total_atendimentos

                       FROM usuarios u

                       LEFT JOIN agendamentos a
                           ON a.profissional_id = u.id
                           AND a.cliente_id = :cliente_id
                           AND a.status = 'concluido'

                       WHERE u.tipo_usuario = 'profissional'
                         AND u.status = 'ativo'

                       GROUP BY
                           u.id,
                           u.nome,
                           u.especialidade

                       ORDER BY u.nome ASC";

    // Prepara a consulta.
    $stmt_fidelidade = $conexao->prepare(
        $sql_fidelidade
    );

    // Informa qual cliente deve ser considerada na contagem.
    $stmt_fidelidade->bindValue(
        ":cliente_id",
        $cliente_id,
        PDO::PARAM_INT
    );

    // Executa a consulta.
    $stmt_fidelidade->execute();

    // Recupera todas as profissionais e suas respectivas contagens.
    $fidelidade_profissionais = $stmt_fidelidade->fetchAll(
        PDO::FETCH_ASSOC
    );

} catch (PDOException $erro) {

    // Registra o erro sem mostrar detalhes técnicos para a cliente.
    error_log(
        "Erro ao buscar fidelidade da cliente: " .
        $erro->getMessage()
    );
}


// ============================================================
// BUSCAR SERVIÇOS DISPONÍVEIS
// ============================================================

// Inicializa a lista de serviços.
$servicos = [];

try {

    // Busca os serviços ativos e relaciona cada serviço
    // às profissionais que estão vinculadas a ele.
    $sql_servicos = "SELECT
                        s.id,
                        s.nome_servico,
                        s.tipo_servico,
                        s.descricao,
                        s.preco,
                        s.duracao,
                        s.status,

                        u.id AS profissional_id,
                        u.nome AS profissional_nome,
                        u.especialidade AS profissional_especialidade

                     FROM servicos s

                     INNER JOIN vinculacao v
                        ON v.servico_id = s.id

                     INNER JOIN usuarios u
                        ON u.id = v.profissional_id

                     WHERE s.status = 'ativo'
                       AND u.tipo_usuario = 'profissional'
                       AND u.status = 'ativo'

                     ORDER BY
                        s.tipo_servico ASC,
                        s.nome_servico ASC";

    // Prepara a consulta.
    $stmt_servicos = $conexao->prepare($sql_servicos);

    // Executa a consulta.
    $stmt_servicos->execute();

    // Recupera todos os serviços encontrados.
    $servicos = $stmt_servicos->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $erro) {

    // Registra o erro no log.
    error_log(
        "Erro ao buscar serviços: " .
        $erro->getMessage()
    );
}


// ============================================================
// FILTRAR SERVIÇOS POR CATEGORIA
// ============================================================

// Cria uma lista vazia para armazenar os serviços filtrados.
$servicos_categoria = [];

// Só realiza o filtro quando uma categoria foi selecionada.
if ($categoria !== "") {

    // Percorre todos os serviços encontrados no banco.
    foreach ($servicos as $servico) {

        // Verifica se a especialidade da profissional
        // corresponde à categoria selecionada.
        if (
            $servico["profissional_especialidade"] === $categoria
        ) {

            // Adiciona o serviço correspondente à lista filtrada.
            $servicos_categoria[] = $servico;
        }
    }
}


// ============================================================
// BUSCAR AVISOS PUBLICADOS PELAS PROFISSIONAIS
// ============================================================

// Inicializa a lista de avisos.
$avisos_profissionais = [];

try {

    // Busca os avisos ativos publicados pelas profissionais.
    // Somente avisos criados nas últimas 72 horas são exibidos.
    $sql_avisos = "SELECT
                        a.id,
                        a.titulo,
                        a.mensagem,
                        a.criado_em,
                        u.nome AS profissional_nome

                   FROM avisos_profissionais a

                   INNER JOIN usuarios u
                       ON u.id = a.profissional_id

                   WHERE a.ativo = 1
                    AND a.criado_em > DATE_SUB(NOW(), INTERVAL 72 HOUR)
                    AND u.tipo_usuario = 'profissional'
                    AND u.status = 'ativo'

                   ORDER BY
                       a.criado_em DESC,
                       a.id DESC";

    // Prepara a consulta.
    $stmt_avisos = $conexao->prepare($sql_avisos);

    // Executa a consulta.
    $stmt_avisos->execute();

    // Recupera os avisos mais recentes primeiro.
    $avisos_profissionais = $stmt_avisos->fetchAll(
        PDO::FETCH_ASSOC
    );

} catch (PDOException $erro) {

    // Registra o erro sem exibir informações técnicas.
    error_log(
        "Erro ao buscar avisos das profissionais: " .
        $erro->getMessage()
    );
}


// ============================================================
// DATA ATUAL
// ============================================================

// Obtém a data atual no formato utilizado pela interface.
$data_atual = date("d/m/Y");

?>

<!DOCTYPE html>
<html lang="pt-BR">

<head>

    <!-- Define a codificação para permitir caracteres como ç e acentos. -->
    <meta charset="UTF-8">

    <!-- Faz a página se adaptar a diferentes tamanhos de tela. -->
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- Define o título mostrado na aba do navegador. -->
    <title>Painel do Cliente | TCC Estética</title>

    <!-- Importa o arquivo CSS externo utilizado pelo sistema. -->
    <link rel="stylesheet" href="style.css">

</head>

<body class="pagina-cliente">


<!-- ============================================================
     CABEÇALHO DO PAINEL
     ============================================================ -->

<!-- Cabeçalho principal da área da cliente. -->
<header class="cabecalho-cliente">

    <!-- Área destinada ao nome/logo do sistema. -->
    <div class="logo-cliente">

        <!-- Nome apresentado no cabeçalho. -->
        <h1>Estética</h1>

    </div>


    <!-- Menu principal de navegação da cliente. -->
    <nav class="menu-cliente">

        <!-- Link para o painel inicial. -->
        <a
            href="painel_cliente.php"
            class="<?= $secao === 'inicio' ? 'ativo' : '' ?>"
        >
            Início
        </a>

        <!-- Link para a página de serviços. -->
        <a href="servicos.php">
            Serviços
        </a>

        <!-- Link para os agendamentos da cliente. -->
        <a href="meus_agendamentos.php">
            Meus Agendamentos
        </a>

        <!-- Link para o perfil da cliente. -->
        <a href="perfil_cliente.php">
            Meu Perfil
        </a>

        <!-- Link utilizado para encerrar a sessão. -->
        <a href="logout.php" class="menu-sair">
            Sair
        </a>

    </nav>

</header>


<!-- ============================================================
     CONTEÚDO PRINCIPAL
     ============================================================ -->

<!-- Área onde as diferentes seções do painel são exibidas. -->
<main class="conteudo-cliente">


<?php if ($secao === "inicio"): ?>

    <!-- ========================================================
         SAUDAÇÃO E DATA
         ======================================================== -->

    <!-- Área superior da página inicial. -->
    <section class="topo-cliente">

        <div>

            <!-- Saudação personalizada utilizando o primeiro nome. -->
            <h2>
                Bem-vinda,
                <?= htmlspecialchars(
                    $primeiro_nome,
                    ENT_QUOTES,
                    "UTF-8"
                ) ?>!
            </h2>

            <!-- Explica as principais funções disponíveis no painel. -->
            <p>
                Aqui você pode acompanhar seus atendimentos,
                conhecer nossos procedimentos e encontrar horários disponíveis.
            </p>

        </div>


        <!-- Mostra a data atual. -->
        <div class="data-cliente">

            <?= htmlspecialchars(
                $data_atual,
                ENT_QUOTES,
                "UTF-8"
            ) ?>

        </div>

    </section>


    <!-- ========================================================
         CARDS PRINCIPAIS
         ======================================================== -->

    <!-- Área que agrupa os principais cards do painel. -->
    <section class="cards-cliente">


        <!-- ====================================================
             CARD DE FIDELIDADE
             ==================================================== -->

        <!-- Card clicável que direciona para a fidelidade. -->
        <a
            href="painel_cliente.php?secao=fidelidade"
            class="card-cliente card-fidelidade card-clicavel"
        >

            <!-- Identificação do tipo de benefício. -->
            <span class="card-etiqueta">
                BENEFÍCIO
            </span>

            <!-- Título do card. -->
            <h3>
                Programa de Fidelidade
            </h3>

            <!-- Explicação da regra de fidelidade. -->
            <p>
                A cada 10 atendimentos realizados com uma profissional,
                você ganha 15% de desconto no próximo procedimento,
                conforme as regras do programa.
            </p>

            <!-- Texto que informa que o card pode ser acessado. -->
            <span class="card-detalhe">
                Clique para acompanhar seu progresso com cada profissional.
            </span>

        </a>


        <!-- ====================================================
             CARD DO PRÓXIMO ATENDIMENTO
             ==================================================== -->

        <!-- Card que direciona para os agendamentos da cliente. -->
        <a
            href="meus_agendamentos.php"
            class="card-cliente card-atendimento card-clicavel"
        >

            <!-- Identificação do conteúdo do card. -->
            <span class="card-etiqueta">
                SEU ATENDIMENTO
            </span>


            <?php if ($proximo_agendamento): ?>

                <!-- Título exibido quando existe um próximo atendimento. -->
                <h3>
                    Próximo atendimento
                </h3>

                <!-- Exibe o nome do serviço agendado. -->
                <p class="destaque-card">
                    <?= htmlspecialchars(
                        $proximo_agendamento["nome_servico"] ?? "Serviço",
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>
                </p>

                <!-- Exibe a data do atendimento. -->
                <p>
                    <strong>Data:</strong>

                    <?= !empty($proximo_agendamento["data_agendamento"])
                        ? date(
                            "d/m/Y",
                            strtotime(
                                $proximo_agendamento["data_agendamento"]
                            )
                        )
                        : "--" ?>
                </p>

                <!-- Exibe o horário do atendimento. -->
                <p>
                    <strong>Horário:</strong>

                    <?= htmlspecialchars(
                        $proximo_agendamento["horario"] ?? "--",
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>
                </p>

                <!-- Exibe o nome da profissional responsável. -->
                <p>
                    <strong>Profissional:</strong>

                    <?= htmlspecialchars(
                        $proximo_agendamento["profissional_nome"] ?? "--",
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>
                </p>

                <!-- Indica que o card permite acessar os agendamentos. -->
                <span class="link-card">
                    Ver meus agendamentos
                </span>


            <?php else: ?>

                <!-- Título exibido quando não existe atendimento futuro. -->
                <h3>
                    Nenhum horário marcado
                </h3>

                <!-- Mensagem informativa para a cliente. -->
                <p>
                    Você ainda não possui um atendimento futuro
                    com status pendente.
                </p>

                <!-- Direciona a cliente para conhecer os serviços. -->
                <span class="link-card">
                    Conhecer nossos serviços
                </span>

            <?php endif; ?>

        </a>


        <!-- ====================================================
             CARD 3 — MURAL DE AVISOS
             ==================================================== -->

        <!-- Card responsável por apresentar os avisos das profissionais. -->
        <article class="card-cliente card-aviso">

            <!-- Título do mural. -->
            <h3>
                Mural de avisos
            </h3>


            <?php if (!empty($avisos_profissionais)): ?>

                <!-- Lista que agrupa os avisos encontrados. -->
                <div class="lista-avisos-cliente">

                    <!-- Percorre cada aviso encontrado no banco. -->
                    <?php foreach ($avisos_profissionais as $aviso): ?>

                        <!-- Card individual de um aviso. -->
                        <div class="aviso-item">

                            <!-- Exibe a mensagem publicada. -->
                            <!-- htmlspecialchars protege contra inserção de HTML indevido. -->
                            <!-- nl2br preserva as quebras de linha da mensagem. -->
                            <p class="aviso-mensagem">
                                <?= nl2br(
                                    htmlspecialchars(
                                        $aviso["mensagem"] ?? "",
                                        ENT_QUOTES,
                                        "UTF-8"
                                    )
                                ) ?>
                            </p>

                            <!-- Identifica a profissional responsável pelo aviso. -->
                            <p class="aviso-autor">
                                Por
                                <?= htmlspecialchars(
                                    $aviso["profissional_nome"] ?? "Profissional",
                                    ENT_QUOTES,
                                    "UTF-8"
                                ) ?>
                            </p>

                        </div>

                    <?php endforeach; ?>

                </div>


            <?php else: ?>

                <!-- Mensagem exibida quando não existem avisos ativos. -->
                <p class="card-detalhe">
                    Nenhum aviso publicado no momento.
                </p>

            <?php endif; ?>

        </article>


    </section>


    <!-- ========================================================
         ACESSO RÁPIDO
         ======================================================== -->

    <!-- Área com atalhos para as principais funcionalidades. -->
    <section class="acesso-rapido">

        <!-- Título da seção. -->
        <div class="titulo-secao">

            <!-- Pequeno identificador visual da seção. -->
            <span>
                ACESSO RÁPIDO
            </span>

            <!-- Pergunta apresentada para a cliente. -->
            <h2>
                O que você deseja fazer?
            </h2>

        </div>


        <!-- Agrupa os atalhos disponíveis. -->
        <div class="atalhos-cliente">

            <!-- Atalho para conhecer os serviços. -->
            <a
                href="servicos.php"
                class="atalho-cliente"
            >

                <!-- Título do atalho. -->
                <h3>
                    Conhecer os serviços
                </h3>

                <!-- Descrição da funcionalidade. -->
                <p>
                    Veja unhas, cílios e sobrancelhas
                    e conheça nossos procedimentos.
                </p>

            </a>


            <!-- Atalho para os agendamentos. -->
            <a
                href="meus_agendamentos.php"
                class="atalho-cliente"
            >

                <!-- Título do atalho. -->
                <h3>
                    Meus agendamentos
                </h3>

                <!-- Descrição da funcionalidade. -->
                <p>
                    Consulte seus horários e acompanhe seus atendimentos.
                </p>

            </a>

        </div>

    </section>


<?php elseif ($secao === "fidelidade"): ?>

    <!-- ========================================================
         DETALHES DA FIDELIDADE
         ======================================================== -->

    <!-- Cabeçalho da seção de fidelidade. -->
    <section class="topo-secao-cliente">

        <div>

            <!-- Identificação da seção. -->
            <span class="etiqueta-cliente">
                BENEFÍCIOS
            </span>

            <!-- Título da seção. -->
            <h2>
                Minha fidelidade
            </h2>

            <!-- Explicação da funcionalidade. -->
            <p>
                Acompanhe seus atendimentos concluídos com cada profissional.
            </p>

        </div>

    </section>


    <!-- Lista dos progressos de fidelidade. -->
    <section class="lista-fidelidade">

        <?php if (count($fidelidade_profissionais) > 0): ?>

            <!-- Percorre cada profissional encontrada. -->
            <?php foreach (
                $fidelidade_profissionais as $profissional
            ): ?>

                <?php

                // Converte a quantidade de atendimentos para inteiro.
                $total = (int) $profissional["total_atendimentos"];

                // Obtém a posição dentro do ciclo atual de dez atendimentos.
                // Exemplo: 13 atendimentos = progresso 3.
                $progresso = $total % 10;

                // Calcula a porcentagem correspondente ao progresso.
                $porcentagem = ($progresso / 10) * 100;

                // Traduz o código da especialidade para um nome amigável.
                $nome_especialidade =
                    $nomes_categorias[$profissional["especialidade"]]
                    ?? "Estética";

                ?>


                <!-- Card de progresso da profissional. -->
                <article class="card-cliente card-progresso-fidelidade">

                    <!-- Mostra a especialidade da profissional. -->
                    <span class="card-etiqueta">
                        <?= htmlspecialchars(
                            $nome_especialidade,
                            ENT_QUOTES,
                            "UTF-8"
                        ) ?>
                    </span>

                    <!-- Mostra o nome da profissional. -->
                    <h3>
                        <?= htmlspecialchars(
                            $profissional["profissional_nome"],
                            ENT_QUOTES,
                            "UTF-8"
                        ) ?>
                    </h3>

                    <!-- Mostra a quantidade de atendimentos do ciclo atual. -->
                    <p>
                        <strong>
                            <?= $progresso ?>/10 atendimentos
                        </strong>

                        concluídos no ciclo atual.
                    </p>


                    <!-- Barra visual do progresso. -->
                    <div
                        class="barra-fidelidade"
                        role="progressbar"
                        aria-valuemin="0"
                        aria-valuemax="10"
                        aria-valuenow="<?= $progresso ?>"
                    >

                        <!-- Parte preenchida da barra. -->
                        <div
                            class="barra-fidelidade-progresso"
                            style="width: <?= $porcentagem ?>%;"
                        ></div>

                    </div>


                    <?php if ($total > 0 && $progresso === 0): ?>

                        <!-- Mensagem exibida quando um ciclo foi completado. -->
                        <p>
                            Você completou um ciclo de dez atendimentos!
                        </p>

                    <?php else: ?>

                        <!-- Calcula quantos atendimentos ainda faltam. -->
                        <p>
                            Faltam <?= 10 - $progresso ?>
                            atendimento(s) para completar o ciclo.
                        </p>

                    <?php endif; ?>


                    <!-- Mostra o total histórico de atendimentos concluídos. -->
                    <p class="card-detalhe">

                        Total de atendimentos concluídos com esta profissional:

                        <?= $total ?>.

                    </p>

                </article>

            <?php endforeach; ?>


        <?php else: ?>

            <!-- Mensagem exibida quando não existem profissionais ativas. -->
            <div class="sem-agendamentos">

                <!-- Título da mensagem. -->
                <h3>
                    Nenhuma profissional ativa encontrada
                </h3>

                <!-- Explicação para a cliente. -->
                <p>
                    Não foi possível encontrar profissionais ativas
                    para exibir sua fidelidade.
                </p>

            </div>

        <?php endif; ?>


        <!-- Botão para retornar ao painel principal. -->
        <a
            href="painel_cliente.php"
            class="btn-principal-cliente"
        >
            Voltar ao painel
        </a>

    </section>


<?php elseif ($secao === "servicos"): ?>

    <!-- ========================================================
         TELA DE SERVIÇOS
         ======================================================== -->

    <!-- Cabeçalho da área de serviços. -->
    <section class="topo-secao-cliente">

        <div>

            <!-- Identificação da seção. -->
            <span class="etiqueta-cliente">
                PROCEDIMENTOS
            </span>

            <!-- Título principal. -->
            <h2>
                Conheça nossos serviços
            </h2>

            <!-- Explicação da funcionalidade. -->
            <p>
                Escolha uma categoria para conhecer os procedimentos
                e as profissionais responsáveis.
            </p>

        </div>

    </section>


    <!-- ========================================================
         CATEGORIAS DE SERVIÇOS
         ======================================================== -->

    <!-- Agrupa as categorias disponíveis. -->
    <section class="categorias-servicos">


        <!-- Categoria de unhas. -->
        <a
            href="servicos.php?categoria=nail"
            class="categoria-servico <?= $categoria === 'nail' ? 'ativo' : '' ?>"
        >

            <!-- Nome curto da categoria. -->
            <span>
                UNHAS
            </span>

            <!-- Nome da especialidade. -->
            <h3>
                Nail Designer
            </h3>

            <!-- Descrição. -->
            <p>
                Conheça os procedimentos para unhas.
            </p>

        </a>


        <!-- Categoria de cílios. -->
        <a
            href="servicos.php?categoria=lash"
            class="categoria-servico <?= $categoria === 'lash' ? 'ativo' : '' ?>"
        >

            <!-- Nome curto da categoria. -->
            <span>
                CÍLIOS
            </span>

            <!-- Nome da especialidade. -->
            <h3>
                Lash Designer
            </h3>

            <!-- Descrição. -->
            <p>
                Conheça nossas opções para cílios.
            </p>

        </a>


        <!-- Categoria de sobrancelhas. -->
        <a
            href="servicos.php?categoria=eyebrow"
            class="categoria-servico <?= $categoria === 'eyebrow' ? 'ativo' : '' ?>"
        >

            <!-- Nome curto da categoria. -->
            <span>
                SOBRANCELHAS
            </span>

            <!-- Nome da especialidade. -->
            <h3>
                Designer de Sobrancelhas
            </h3>

            <!-- Descrição. -->
            <p>
                Veja os procedimentos para sobrancelhas.
            </p>

        </a>

    </section>


    <?php if ($categoria !== ""): ?>

        <?php

        // Recupera o nome da primeira profissional encontrada na categoria.
        // Esse valor é utilizado como responsável pela categoria.
        $profissional_categoria =
            $servicos_categoria[0]["profissional_nome"] ?? null;

        ?>


        <!-- Conteúdo específico da categoria selecionada. -->
        <section class="categoria-conteudo">

            <!-- Cabeçalho da categoria. -->
            <div class="categoria-cabecalho">

                <div>

                    <!-- Exibe o nome da categoria em letras maiúsculas. -->
                    <span class="etiqueta-cliente">
                        <?= htmlspecialchars(
                            strtoupper($nomes_categorias[$categoria]),
                            ENT_QUOTES,
                            "UTF-8"
                        ) ?>
                    </span>

                    <!-- Título dos procedimentos. -->
                    <h2>
                        Procedimentos disponíveis
                    </h2>

                </div>


                <?php if ($profissional_categoria): ?>

                    <!-- Identificação da profissional responsável. -->
                    <div class="profissional-responsavel">

                        <!-- Texto explicativo. -->
                        <span>
                            PROFISSIONAL RESPONSÁVEL
                        </span>

                        <!-- Nome da profissional. -->
                        <strong>
                            <?= htmlspecialchars(
                                $profissional_categoria,
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>
                        </strong>

                    </div>

                <?php endif; ?>

            </div>


            <?php if (count($servicos_categoria) > 0): ?>

                <!-- Grade que organiza os serviços em cards. -->
                <div class="grade-servicos-cliente">


                    <!-- Percorre todos os serviços da categoria. -->
                    <?php foreach ($servicos_categoria as $servico): ?>

                        <!-- Card individual do serviço. -->
                        <article class="servico-card">

                            <!-- Conteúdo interno do card. -->
                            <div class="servico-card-conteudo">

                                <!-- Exibe o tipo do serviço. -->
                                <span class="servico-tipo">
                                    <?= htmlspecialchars(
                                        ucfirst($servico["tipo_servico"]),
                                        ENT_QUOTES,
                                        "UTF-8"
                                    ) ?>
                                </span>


                                <!-- Nome do serviço. -->
                                <h3>
                                    <?= htmlspecialchars(
                                        $servico["nome_servico"],
                                        ENT_QUOTES,
                                        "UTF-8"
                                    ) ?>
                                </h3>


                                <!-- Descrição do procedimento. -->
                                <p class="servico-descricao">

                                    <?= htmlspecialchars(
                                        $servico["descricao"]
                                            ?? "Procedimento realizado com cuidado e atenção.",
                                        ENT_QUOTES,
                                        "UTF-8"
                                    ) ?>

                                </p>


                                <!-- Informação sobre a duração. -->
                                <div class="servico-informacao">

                                    <strong>
                                        Duração
                                    </strong>

                                    <span>
                                        <?= htmlspecialchars(
                                            $servico["duracao"] ?? "--",
                                            ENT_QUOTES,
                                            "UTF-8"
                                        ) ?>
                                    </span>

                                </div>


                                <!-- Informação sobre o valor. -->
                                <div class="servico-informacao">

                                    <strong>
                                        Valor
                                    </strong>

                                    <span class="servico-preco">

                                        R$

                                        <?= number_format(
                                            (float) $servico["preco"],
                                            2,
                                            ",",
                                            "."
                                        ) ?>

                                    </span>

                                </div>


                                <!-- Informação sobre a profissional. -->
                                <div class="servico-profissional">

                                    <span>
                                        Profissional
                                    </span>

                                    <strong>
                                        <?= htmlspecialchars(
                                            $servico["profissional_nome"],
                                            ENT_QUOTES,
                                            "UTF-8"
                                        ) ?>
                                    </strong>

                                </div>


                                <!-- Link que leva a cliente para os horários disponíveis. -->
                                <a
                                    href="agenda.php?servico_id=<?= (int) $servico["id"] ?>"
                                    class="btn-horarios"
                                >
                                    Ver horários disponíveis
                                </a>

                            </div>

                        </article>

                    <?php endforeach; ?>

                </div>


            <?php else: ?>

                <!-- Mensagem exibida quando não existem serviços cadastrados. -->
                <div class="sem-servicos">

                    <h3>
                        Nenhum procedimento disponível
                    </h3>

                    <p>
                        Ainda não existem procedimentos cadastrados
                        para esta categoria.
                    </p>

                </div>

            <?php endif; ?>

        </section>


    <?php else: ?>

        <!-- Mensagem exibida antes da escolha de uma categoria. -->
        <section class="mensagem-servicos">

            <!-- Título da mensagem. -->
            <h2>
                Escolha uma categoria
            </h2>

            <!-- Orientação para a cliente. -->
            <p>
                Selecione entre unhas, cílios ou sobrancelhas
                para visualizar os procedimentos disponíveis.
            </p>

        </section>

    <?php endif; ?>


<?php elseif ($secao === "agendamentos"): ?>

    <!-- ========================================================
         TELA DE AGENDAMENTOS
         ======================================================== -->

    <!-- Cabeçalho da seção de agendamentos. -->
    <section class="topo-secao-cliente">

        <div>

            <!-- Identificação da seção. -->
            <span class="etiqueta-cliente">
                MINHA AGENDA
            </span>

            <!-- Título. -->
            <h2>
                Meus Agendamentos
            </h2>

            <!-- Explicação da funcionalidade. -->
            <p>
                Consulte seus atendimentos anteriores e futuros.
            </p>

        </div>

    </section>


    <!-- Lista de agendamentos da cliente. -->
    <section class="lista-agendamentos">


        <?php if (count($agendamentos_cliente) > 0): ?>

            <!-- Percorre todos os agendamentos encontrados. -->
            <?php foreach ($agendamentos_cliente as $agendamento): ?>

                <!-- Card individual do agendamento. -->
                <article class="agendamento-card">


                    <!-- ====================================================
                         DATA E HORÁRIO
                         ==================================================== -->

                    <div class="agendamento-data">

                        <!-- Identifica o campo. -->
                        <span>
                            DATA
                        </span>

                        <!-- Converte a data do banco para o formato brasileiro. -->
                        <strong>

                            <?= !empty($agendamento["data_agendamento"])
                                ? date(
                                    "d/m/Y",
                                    strtotime(
                                        $agendamento["data_agendamento"]
                                    )
                                )
                                : "--" ?>

                        </strong>

                        <!-- Exibe o horário. -->
                        <small>
                            <?= htmlspecialchars(
                                $agendamento["horario"] ?? "--",
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>
                        </small>

                    </div>


                    <!-- ====================================================
                         INFORMAÇÕES DO AGENDAMENTO
                         ==================================================== -->

                    <div class="agendamento-informacoes">

                        <!-- Identifica que as informações são sobre procedimento. -->
                        <span class="card-etiqueta">
                            PROCEDIMENTO
                        </span>

                        <!-- Nome do serviço. -->
                        <h3>
                            <?= htmlspecialchars(
                                $agendamento["nome_servico"] ?? "--",
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>
                        </h3>


                        <!-- Nome da profissional. -->
                        <p>
                            <strong>
                                Profissional:
                            </strong>

                            <?= htmlspecialchars(
                                $agendamento["profissional_nome"] ?? "--",
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>
                        </p>


                        <!-- Duração do serviço. -->
                        <p>
                            <strong>
                                Duração:
                            </strong>

                            <?= htmlspecialchars(
                                $agendamento["duracao"] ?? "--",
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>
                        </p>

                    </div>


                    <!-- ====================================================
                         STATUS DO AGENDAMENTO
                         ==================================================== -->

                    <div class="agendamento-status">

                        <?php

                        // Define inicialmente a classe utilizada para o status pendente.
                        $classe_status = "status-pendente";

                        // Caso o status seja concluído, utiliza a classe correspondente.
                        if ($agendamento["status"] === "concluido") {

                            $classe_status = "status-concluido";

                        // Caso o status seja cancelado, utiliza a classe correspondente.
                        } elseif ($agendamento["status"] === "cancelado") {

                            $classe_status = "status-cancelado";

                        // Caso o agendamento tenha sido alterado,
                        // utiliza a classe específica.
                        } elseif ($agendamento["status"] === "alterado") {

                            $classe_status = "status-alterado";
                        }

                        ?>


                        <!-- Exibe o status utilizando a classe CSS definida acima. -->
                        <span class="<?= $classe_status ?>">

                            <?= htmlspecialchars(
                                ucfirst(
                                    $agendamento["status"] ?? "pendente"
                                ),
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>

                        </span>

                    </div>

                </article>

            <?php endforeach; ?>


        <?php else: ?>

            <!-- Mensagem exibida quando a cliente não possui agendamentos. -->
            <div class="sem-agendamentos">

                <!-- Título da mensagem. -->
                <h3>
                    Você ainda não possui agendamentos
                </h3>

                <!-- Orientação para a cliente. -->
                <p>
                    Conheça nossos serviços e escolha um procedimento
                    para encontrar um horário disponível.
                </p>

                <!-- Botão para acessar os serviços. -->
                <a
                    href="servicos.php"
                    class="btn-principal-cliente"
                >
                    Conhecer serviços
                </a>

            </div>

        <?php endif; ?>

    </section>

<?php endif; ?>


</main>


<!-- ============================================================
     RODAPÉ
     ============================================================ -->

<!-- Rodapé da área da cliente. -->
<footer class="rodape-cliente">

    <!-- Identificação do sistema. -->
    <p>
        TCC Estética — Área da Cliente
    </p>

</footer>


</body>
</html>