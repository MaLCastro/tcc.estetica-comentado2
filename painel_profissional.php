<?php

// ============================================================
// INICIAR SESSÃO E GERAR TOKEN DE SEGURANÇA
// ============================================================

// Inicia a sessão para permitir o acesso às informações
// da profissional que está autenticada no sistema.
session_start();

// Verifica se já existe um token CSRF para os formulários
// relacionados aos agendamentos.
// O token ajuda a impedir requisições indevidas aos formulários.
if (empty($_SESSION["csrf_agendamentos"])) {

    // Gera um token aleatório e seguro com 32 bytes.
    // bin2hex transforma os bytes em uma sequência hexadecimal.
    $_SESSION["csrf_agendamentos"] = bin2hex(random_bytes(32));
}


// ============================================================
// CONEXÃO COM O BANCO DE DADOS
// ============================================================

// Inclui o arquivo responsável pela conexão PDO
// com o banco de dados do sistema.
require_once("conexao.php");


// ============================================================
// VERIFICAÇÃO DE ACESSO
// ============================================================

// Verifica se existe um usuário autenticado na sessão.
if (!isset($_SESSION["usuario_id"])) {

    // Caso não exista usuário autenticado,
// redireciona para a página de login.
    header("Location: login.php");

    // Encerra a execução do PHP para impedir
    // que o restante da página seja carregado.
    exit();
}


// Verifica se o usuário autenticado é realmente uma profissional.
if (
    !isset($_SESSION["usuario_tipo"]) ||
    $_SESSION["usuario_tipo"] !== "profissional"
) {

    // Caso seja cliente ou outro tipo de usuário,
// impede o acesso ao painel profissional.
    header("Location: login.php");

    // Interrompe a execução da página.
    exit();
}


// ============================================================
// DADOS DA PROFISSIONAL
// ============================================================

// Recupera o ID da profissional armazenado na sessão.
// O casting para inteiro ajuda a garantir que o valor
// seja tratado como número.
$id_profissional = (int) $_SESSION["usuario_id"];

// Recupera o nome armazenado na sessão.
// Caso não exista, utiliza "Profissional" como valor padrão.
$nome_profissional = $_SESSION["usuario_nome"] ?? "Profissional";

// Recupera a especialidade armazenada na sessão.
// Caso não exista, informa que ela ainda não foi definida.
$especialidade_profissional =
    $_SESSION["especialidade"] ?? "Não informada";


// ============================================================
// BUSCAR DADOS ATUALIZADOS DA PROFISSIONAL
// ============================================================

try {

    // Monta a consulta que busca os principais dados
    // da profissional atualmente autenticada.
    $sql = "SELECT
                id,
                nome,
                email,
                telefone,
                tipo_usuario,
                especialidade

            FROM usuarios

            WHERE id = :id

            LIMIT 1";

    // Prepara a consulta utilizando PDO.
    $consulta = $conexao->prepare($sql);

    // Substitui o parâmetro :id pelo ID da profissional.
    // PDO::PARAM_INT informa que o valor é inteiro.
    $consulta->bindValue(
        ":id",
        $id_profissional,
        PDO::PARAM_INT
    );

    // Executa a consulta.
    $consulta->execute();

    // Recupera os dados encontrados como um array associativo.
    $profissional = $consulta->fetch(PDO::FETCH_ASSOC);


    // Verifica se a conta realmente existe no banco.
    if (!$profissional) {

        // Encerra a sessão caso a conta não seja encontrada.
        session_destroy();

        // Redireciona o usuário para o login.
        header("Location: login.php");

        // Interrompe a execução.
        exit();
    }


    // Atualiza o nome da profissional com o valor
    // mais recente armazenado no banco.
    $nome_profissional = $profissional["nome"];

    // Guarda o e-mail atual da profissional.
    $email_profissional = $profissional["email"];

    // Guarda o telefone atual da profissional.
    $telefone_profissional = $profissional["telefone"];

    // Atualiza a especialidade.
    // Se o banco não possuir uma especialidade,
    // mantém o valor que já estava na sessão.
    $especialidade_profissional =
        $profissional["especialidade"]
        ?? $especialidade_profissional;


    // Atualiza o nome na sessão.
    // Isso mantém a sessão sincronizada com o banco.
    $_SESSION["usuario_nome"] = $nome_profissional;

    // Atualiza a especialidade na sessão.
    $_SESSION["especialidade"] = $especialidade_profissional;


} catch (PDOException $erro) {

    // Registra o erro no log do servidor.
    // A mensagem técnica não é exibida para a profissional,
    // evitando expor informações internas do sistema.
    error_log(
        "Erro dados profissional: " .
        $erro->getMessage()
    );
}


// ============================================================
// DATA E HORA ATUAIS
// ============================================================

// Define o fuso horário utilizado pelo sistema.
// America/Sao_Paulo corresponde ao horário de Brasília.
date_default_timezone_set("America/Sao_Paulo");


// Guarda a data atual no formato utilizado pelo banco de dados.
$data_atual_sql = date("Y-m-d");

// Guarda a data atual no formato brasileiro para exibição.
$data_br = date("d/m/Y");


// ============================================================
// TRADUÇÃO DOS DIAS DA SEMANA
// ============================================================

// O PHP retorna os dias da semana em inglês
// quando utilizamos date("l").
// Este array traduz esses nomes para português.
$dias_semana = [

    // Domingo.
    "Sunday" => "Domingo",

    // Segunda-feira.
    "Monday" => "Segunda-feira",

    // Terça-feira.
    "Tuesday" => "Terça-feira",

    // Quarta-feira.
    "Wednesday" => "Quarta-feira",

    // Quinta-feira.
    "Thursday" => "Quinta-feira",

    // Sexta-feira.
    "Friday" => "Sexta-feira",

    // Sábado.
    "Saturday" => "Sábado"
];


// Recupera o nome do dia atual em inglês
// e procura sua tradução no array.
// Caso não encontre, mantém o nome original.
$dia_atual =
    $dias_semana[date("l")]
    ?? date("l");


// ============================================================
// RESUMO DOS AGENDAMENTOS DE HOJE
// ============================================================

// Inicializa a quantidade total de agendamentos de hoje.
$agendamentos_hoje = 0;

// Inicializa a quantidade de agendamentos pendentes.
$agendamentos_pendentes = 0;

// Inicializa a quantidade de atendimentos concluídos.
$atendimentos_concluidos = 0;

// Inicializa o valor dos atendimentos concluídos no dia.
$valor_dia = 0;

// Inicializa o próximo atendimento como inexistente.
$proximo_atendimento = null;


// ============================================================
// BUSCAR AGENDAMENTOS DO DIA
// ============================================================

try {

    // Consulta os agendamentos da profissional para a data atual.
    $sql_ag = "SELECT

                    // Horário marcado para o atendimento.
                    a.horario,

                    // Situação atual do agendamento.
                    a.status,

                    // ID do serviço escolhido.
                    a.servico_id,

                    // Nome da cliente.
                    c.nome AS cliente_nome,

                    // Telefone da cliente.
                    c.telefone AS cliente_telefone,

                    // E-mail da cliente.
                    c.email AS cliente_email,

                    // Nome do serviço.
                    s.nome_servico,

                    // Valor final do agendamento.
                    a.preco_final AS preco

                FROM agendamentos a

                // Relaciona o agendamento com a cliente.
                LEFT JOIN usuarios c
                    ON a.cliente_id = c.id

                // Relaciona o agendamento com o serviço.
                LEFT JOIN servicos s
                    ON a.servico_id = s.id

                // Seleciona somente os agendamentos
                // pertencentes à profissional atual.
                WHERE a.profissional_id = :profissional_id

                // Filtra somente a data de hoje.
                AND DATE(a.data_agendamento) = :data_hoje

                // Ignora agendamentos cancelados.
                AND LOWER(TRIM(a.status)) <> 'cancelado'

                // Organiza os horários do mais cedo para o mais tarde.
                ORDER BY a.horario ASC";


    // Prepara a consulta SQL.
    $stmt_ag = $conexao->prepare($sql_ag);

    // Envia o ID da profissional para a consulta.
    $stmt_ag->bindValue(
        ":profissional_id",
        $id_profissional,
        PDO::PARAM_INT
    );

    // Envia a data atual para a consulta.
    $stmt_ag->bindValue(
        ":data_hoje",
        $data_atual_sql
    );

    // Executa a consulta.
    $stmt_ag->execute();

    // Recupera todos os agendamentos encontrados.
    $agendamentos = $stmt_ag->fetchAll(PDO::FETCH_ASSOC);


    // Percorre cada agendamento encontrado.
    foreach ($agendamentos as $ag) {

        // Soma um ao total de agendamentos do dia.
        $agendamentos_hoje++;


        // Verifica se o atendimento está pendente.
        if ($ag["status"] === "pendente") {

            // Soma um ao total de atendimentos pendentes.
            $agendamentos_pendentes++;


            // Se ainda não existe um próximo atendimento definido,
            // utiliza este como o próximo.
            if (!$proximo_atendimento) {
                $proximo_atendimento = $ag;
            }
        }


        // Verifica se o atendimento foi concluído.
        if ($ag["status"] === "concluido") {

            // Soma um ao total de atendimentos concluídos.
            $atendimentos_concluidos++;

            // Soma o preço do atendimento ao valor do dia.
            $valor_dia += (float) $ag["preco"];
        }
    }


} catch (PDOException $e) {

    // Registra qualquer erro ocorrido na consulta.
    error_log(
        "Erro agendamentos: " .
        $e->getMessage()
    );
}


// ============================================================
// BUSCAR O PRÓXIMO AGENDAMENTO FUTURO
// ============================================================

// Recupera somente o horário atual.
// Ele será utilizado para evitar mostrar um atendimento
// que já passou no dia atual.
$data_hora_atual = date("H:i:s");


try {

    // Consulta o próximo agendamento pendente.
    $sql_proximo = "SELECT

                        // ID do agendamento.
                        a.id AS agendamento_id,

                        // Data do atendimento.
                        DATE(a.data_agendamento) AS data_agendamento,

                        // Horário do atendimento.
                        a.horario,

                        // Status atual.
                        a.status,

                        // ID do serviço.
                        a.servico_id,

                        // Dados da cliente.
                        c.nome AS cliente_nome,
                        c.telefone AS cliente_telefone,
                        c.email AS cliente_email,

                        // Nome do serviço.
                        s.nome_servico,

                        // Valor final do atendimento.
                        a.preco_final AS preco

                    FROM agendamentos a

                    // Relaciona com a cliente.
                    LEFT JOIN usuarios c
                        ON a.cliente_id = c.id

                    // Relaciona com o serviço.
                    LEFT JOIN servicos s
                        ON a.servico_id = s.id

                    WHERE a.profissional_id = :profissional_id

                      // Considera somente agendamentos pendentes.
                      AND a.status = 'pendente'

                      // A data deve ser hoje ou uma data futura.
                      AND DATE(a.data_agendamento) >= :data_hoje

                      // Quando for hoje, o horário também precisa
                      // ser igual ou posterior ao horário atual.
                      AND (
                          DATE(a.data_agendamento) > :data_hoje_futura
                          OR a.horario >= :hora_atual
                      )

                    // Ordena primeiro pela data
                    // e depois pelo horário.
                    ORDER BY
                        DATE(a.data_agendamento) ASC,
                        a.horario ASC

                    // Retorna somente o primeiro resultado.
                    LIMIT 1";


    // Prepara a consulta.
    $stmt_proximo = $conexao->prepare($sql_proximo);


    // Envia o ID da profissional.
    $stmt_proximo->bindValue(
        ":profissional_id",
        $id_profissional,
        PDO::PARAM_INT
    );


    // Envia a data atual para a primeira condição.
    $stmt_proximo->bindValue(
        ":data_hoje",
        $data_atual_sql
    );


    // Envia novamente a data atual para verificar
    // se existe uma data futura.
    $stmt_proximo->bindValue(
        ":data_hoje_futura",
        $data_atual_sql
    );


    // Envia o horário atual para impedir
    // que atendimentos já passados sejam exibidos.
    $stmt_proximo->bindValue(
        ":hora_atual",
        $data_hora_atual
    );


    // Executa a consulta.
    $stmt_proximo->execute();


    // Recupera o primeiro resultado.
    // Caso não exista, mantém o valor como null.
    $proximo_atendimento =
        $stmt_proximo->fetch(PDO::FETCH_ASSOC)
        ?: null;


} catch (PDOException $e) {

    // Registra o erro no log.
    error_log(
        "Erro ao buscar próximo agendamento: " .
        $e->getMessage()
    );

    // Garante que não exista informação inválida na variável.
    $proximo_atendimento = null;
}


// ============================================================
// HORÁRIOS DE TRABALHO
// ============================================================

// Define o horário inicial padrão da profissional.
$inicio_padrao = "08:00";

// Define o último horário em que um atendimento pode começar.
$ultimo_inicio = "17:00";

// Define o horário padrão de encerramento.
$fim_expediente = "19:00";

// Define o início padrão do intervalo de almoço.
$inicio_almoco = "12:00";

// Define o fim padrão do intervalo de almoço.
$fim_almoco = "13:00";


try {

    // Busca as configurações de horário da profissional.
    $sql_hr = "SELECT
                    horario_inicio,
                    ultimo_horario,
                    inicio_almoco,
                    fim_almoco

               FROM horarios

               WHERE profissional_id = :pid

               LIMIT 1";


    // Prepara a consulta.
    $stmt_hr = $conexao->prepare($sql_hr);


    // Envia o ID da profissional.
    $stmt_hr->bindValue(
        ":pid",
        $id_profissional,
        PDO::PARAM_INT
    );


    // Executa a consulta.
    $stmt_hr->execute();


    // Recupera os horários cadastrados.
    $hr = $stmt_hr->fetch(PDO::FETCH_ASSOC);


    // Verifica se existem configurações cadastradas.
    if ($hr) {

        // Utiliza o horário inicial salvo.
        // Caso esteja vazio, mantém o valor padrão.
        $inicio_padrao =
            $hr["horario_inicio"]
            ?? $inicio_padrao;


        // Utiliza o último horário salvo.
        $ultimo_inicio =
            $hr["ultimo_horario"]
            ?? $ultimo_inicio;


        // Recupera o início do almoço.
        $inicio_almoco =
            $hr["inicio_almoco"]
            ?? $inicio_almoco;


        // Recupera o fim do almoço.
        $fim_almoco =
            $hr["fim_almoco"]
            ?? $fim_almoco;
    }


} catch (PDOException $e) {

    // Registra qualquer erro ocorrido na consulta.
    error_log(
        "Erro horários: " .
        $e->getMessage()
    );
}


// ============================================================
// PREPARAR SAUDAÇÃO
// ============================================================

// Divide o nome completo em partes utilizando o espaço.
// O índice 0 representa o primeiro nome.
$primeiro_nome =
    explode(" ", trim($nome_profissional))[0];

?>

<!DOCTYPE html>
<html lang="pt-BR">

<head>

    <!-- Define a codificação utilizada pela página. -->
    <meta charset="UTF-8">

    <!-- Faz a página se adaptar a diferentes tamanhos de tela. -->
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <!-- Define o título exibido na aba do navegador. -->
    <title>Painel Profissional | TCC Estética</title>

    <!-- Importa o arquivo CSS principal do sistema. -->
    <link
        rel="stylesheet"
        href="style.css"
    >

    <style>

        /* ====================================================
           BOTÃO PARA CONCLUIR AGENDAMENTO
           ==================================================== */

        /* Define a aparência principal do botão. */
        .btn-concluir-agendamento {

            /* Remove a borda padrão do botão. */
            border: 0;

            /* Arredonda os cantos. */
            border-radius: 9px;

            /* Define o espaçamento interno. */
            padding: 11px 17px;

            /* Define a cor de fundo. */
            background: #704B42;

            /* Define a cor do texto. */
            color: #fff;

            /* Deixa o texto um pouco mais destacado. */
            font-weight: 600;

            /* Mostra o cursor de clique. */
            cursor: pointer;
        }


        /* Altera a cor do botão ao passar o mouse. */
        .btn-concluir-agendamento:hover {

            /* Utiliza o marrom café mais escuro. */
            background: #5A3434;
        }


        /* ====================================================
           LINK PARA TODOS OS ATENDIMENTOS
           ==================================================== */

        /* Define a aparência do link de atendimentos. */
        .btn-ver-atendimentos {

            /* Define a cor rosa forte do projeto. */
            color: #8F4054;

            /* Destaca o texto. */
            font-weight: 600;

            /* Remove o sublinhado padrão. */
            text-decoration: none;
        }


        /* Adiciona sublinhado ao passar o mouse. */
        .btn-ver-atendimentos:hover {
            text-decoration: underline;
        }


        /* ====================================================
           CARD DE PUBLICAÇÃO DE AVISO
           ==================================================== */

        /* Transforma o card em um link clicável. */
        .card-atalho-aviso {

            /* Faz o link ocupar todo o espaço do card. */
            display: block;

            /* Faz o cálculo de largura e altura
               considerar bordas e espaçamentos. */
            box-sizing: border-box;

            /* Mantém a cor definida pelo card. */
            color: inherit;

            /* Remove o sublinhado do link. */
            text-decoration: none;

            /* Mostra que o card pode ser clicado. */
            cursor: pointer;

            /* Cria uma pequena animação. */
            transition:
                transform 0.2s ease,
                box-shadow 0.2s ease;
        }


        /* Efeito visual quando o mouse passa sobre o card. */
        .card-atalho-aviso:hover {

            /* Move levemente o card para cima. */
            transform: translateY(-3px);

            /* Adiciona uma sombra suave. */
            box-shadow:
                0 6px 18px rgba(90, 52, 52, 0.12);
        }


        /* Indica foco quando o card é acessado pelo teclado. */
        .card-atalho-aviso:focus-visible {

            /* Cria um contorno ao redor do card. */
            outline: 3px solid #A85F70;

            /* Cria uma pequena distância entre o contorno e o card. */
            outline-offset: 3px;
        }


        /* Mantém as informações internas do card
           com a mesma cor herdada. */
        .card-atalho-aviso .titulo,
        .card-atalho-aviso .numero,
        .card-atalho-aviso .descricao {

            /* Utiliza a cor herdada do próprio card. */
            color: inherit;
        }

    </style>

</head>


<body>

<!-- ============================================================
     MENU LATERAL
     ============================================================ -->

<!--
    A sidebar contém a navegação principal da área profissional.
-->
<aside class="sidebar">


    <!-- ========================================================
         LOGO E ESPECIALIDADE
         ======================================================== -->

    <div class="logo">

        <!-- Nome exibido como identificação do sistema. -->
        <h1>Estética</h1>


        <!--
            Recupera a especialidade da sessão.
            Caso não exista, exibe "Profissional".
        -->
        <?php

        // Escapa o valor antes de exibi-lo no HTML.
        $especialidade =
            htmlspecialchars(
                $_SESSION["especialidade"] ?? "Profissional"
            );

        ?>

        <!-- Exibe a especialidade da profissional. -->
        <p><?= $especialidade ?></p>

    </div>


    <!-- ========================================================
         MENU DE NAVEGAÇÃO
         ======================================================== -->

    <!--
        Reúne os links para as principais páginas
        da área profissional.
    -->
    <nav class="menu">


        <!-- Link para o painel inicial. -->
        <a
            href="painel_profissional.php"
            class="<?= basename($_SERVER['PHP_SELF']) === 'painel_profissional.php' ? 'ativo' : '' ?>"
        >
            Início
        </a>


        <!-- Link para a página de atendimentos. -->
        <a
            href="atendimentos.php"
            class="<?= basename($_SERVER['PHP_SELF']) === 'atendimentos.php' ? 'ativo' : '' ?>"
        >
            Atendimentos
        </a>


        <!-- Link para a agenda da profissional. -->
        <a
            href="minha_agenda.php"
            class="<?= basename($_SERVER['PHP_SELF']) === 'minha_agenda.php' ? 'ativo' : '' ?>"
        >
            Minha Agenda
        </a>


        <!-- Link para o gerenciamento de serviços. -->
        <a
            href="meus_servicos.php"
            class="<?= basename($_SERVER['PHP_SELF']) === 'meus_servicos.php' ? 'ativo' : '' ?>"
        >
            Meus Serviços
        </a>


        <!-- Separador visual entre os grupos de opções. -->
        <div class="separador"></div>


        <!-- Link para o perfil profissional. -->
        <a
            href="meu_perfil.php"
            class="<?= basename($_SERVER['PHP_SELF']) === 'meu_perfil.php' ? 'ativo' : '' ?>"
        >
            Meu Perfil
        </a>

    </nav>


    <!--
        Link utilizado para encerrar a sessão da profissional.
    -->
    <a
        href="logout.php"
        class="btn-sair"
    >
        Sair
    </a>

</aside>


<!-- ============================================================
     CONTEÚDO PRINCIPAL
     ============================================================ -->

<!--
    A área main contém todas as informações
    específicas do painel profissional.
-->
<main class="main">


    <!-- ========================================================
         CABEÇALHO DO PAINEL
         ======================================================== -->

    <section class="topo">

        <div>

            <!--
                Exibe uma saudação utilizando somente
                o primeiro nome da profissional.
            -->
            <h2>
                Olá,
                <?php
                echo htmlspecialchars($primeiro_nome);
                ?>!
            </h2>


            <!-- Texto explicativo da página. -->
            <p>
                Aqui você pode acompanhar e administrar
                sua rotina profissional.
            </p>

        </div>


        <!-- Exibe o dia da semana e a data atual. -->
        <div class="data">

            <?php
            echo htmlspecialchars($dia_atual);
            ?>,

            <?php
            echo htmlspecialchars($data_br);
            ?>

        </div>

    </section>


    <!-- ========================================================
         INDICADORES PRINCIPAIS
         ======================================================== -->

    <section class="cards">


        <!-- ====================================================
             TOTAL DE AGENDAMENTOS DO DIA
             ==================================================== -->

        <div class="card">

            <!-- Título do indicador. -->
            <div class="titulo">
                Agendamentos de hoje
            </div>

            <!-- Número total encontrado no banco. -->
            <div class="numero">
                <?php
                echo $agendamentos_hoje;
                ?>
            </div>

            <!-- Descrição do indicador. -->
            <div class="descricao">
                atendimentos previstos
            </div>

        </div>


        <!-- ====================================================
             AGENDAMENTOS PENDENTES
             ==================================================== -->

        <div class="card">

            <!-- Título do indicador. -->
            <div class="titulo">
                Pendentes
            </div>

            <!-- Quantidade de atendimentos pendentes. -->
            <div class="numero">
                <?php
                echo $agendamentos_pendentes;
                ?>
            </div>

            <!-- Descrição do indicador. -->
            <div class="descricao">
                aguardando atendimento
            </div>

        </div>


        <!-- ====================================================
             ATENDIMENTOS CONCLUÍDOS
             ==================================================== -->

        <div class="card">

            <!-- Título do indicador. -->
            <div class="titulo">
                Atendimentos concluídos
            </div>

            <!-- Quantidade de atendimentos concluídos. -->
            <div class="numero">
                <?php
                echo $atendimentos_concluidos;
                ?>
            </div>

            <!-- Descrição do indicador. -->
            <div class="descricao">
                realizados hoje
            </div>

        </div>


        <!-- ====================================================
             ATALHO PARA PUBLICAR AVISO
             ==================================================== -->

        <!--
            Este card funciona como um link.
            Ao clicar, a profissional é direcionada
            para a área de avisos do perfil.
        -->
        <a
            href="meu_perfil.php#avisos"
            class="card card-atalho-aviso"
            aria-label="Ir para Meu Perfil e publicar um aviso para as clientes"
        >

            <!-- Título do atalho. -->
            <div class="titulo">
                Publicar aviso
            </div>

            <!-- Texto principal do atalho. -->
            <div class="numero">
                Escrever mensagem
            </div>

            <!-- Explicação da funcionalidade. -->
            <div class="descricao">
                Compartilhe informações com suas clientes.
            </div>

        </a>

    </section>


    <!-- ========================================================
         PRÓXIMO ATENDIMENTO E ROTINA
         ======================================================== -->

    <section class="grid">


        <!-- ====================================================
             PRÓXIMO ATENDIMENTO
             ==================================================== -->

        <div class="box">

            <!-- Título da seção. -->
            <h3>
                Próximo atendimento
            </h3>


            <!-- Área que contém os dados do atendimento. -->
            <div class="proximo">


                <?php if ($proximo_atendimento): ?>


                    <!-- ==================================================
                         DATA DO ATENDIMENTO
                         ================================================== -->

                    <div class="informacao">

                        <!-- Texto que identifica a informação. -->
                        Data:

                        <!--
                            Converte a data do banco,
                            normalmente YYYY-MM-DD,
                            para DD/MM/YYYY.
                        -->
                        <?php
                        echo date(
                            "d/m/Y",
                            strtotime(
                                $proximo_atendimento["data_agendamento"]
                            )
                        );
                        ?>

                    </div>


                    <!-- ==================================================
                         HORÁRIO
                         ================================================== -->

                    <div class="horario">

                        <!-- Exibe o horário do agendamento. -->
                        <?php
                        echo htmlspecialchars(
                            $proximo_atendimento["horario"]
                        );
                        ?>

                    </div>


                    <!-- ==================================================
                         CLIENTE
                         ================================================== -->

                    <div class="cliente">

                        <!-- Exibe o nome da cliente. -->
                        <?php
                        echo htmlspecialchars(
                            $proximo_atendimento["cliente_nome"]
                            ?? "Cliente não identificada"
                        );
                        ?>

                    </div>


                    <!-- ==================================================
                         SERVIÇO
                         ================================================== -->

                    <div class="informacao">

                        <!-- Identificação do serviço. -->
                        Serviço:

                        <!-- Nome do procedimento escolhido. -->
                        <?php
                        echo htmlspecialchars(
                            $proximo_atendimento["nome_servico"]
                            ?? "Não informado"
                        );
                        ?>

                    </div>


                    <!-- ==================================================
                         TELEFONE
                         ================================================== -->

                    <div class="informacao">

                        <!-- Identificação do telefone. -->
                        Telefone:

                        <!-- Telefone da cliente. -->
                        <?php
                        echo htmlspecialchars(
                            $proximo_atendimento["cliente_telefone"]
                            ?? "--"
                        );
                        ?>

                    </div>


                    <!-- ==================================================
                         E-MAIL
                         ================================================== -->

                    <div class="informacao">

                        <!-- Identificação do e-mail. -->
                        E-mail:

                        <!-- E-mail da cliente. -->
                        <?php
                        echo htmlspecialchars(
                            $proximo_atendimento["cliente_email"]
                            ?? "--"
                        );
                        ?>

                    </div>


                    <!-- ==================================================
                         STATUS
                         ================================================== -->

                    <div>

                        <!-- Indica que existe um próximo atendimento. -->
                        <span class="status">
                            Próximo atendimento
                        </span>

                    </div>


                    <!-- ==================================================
                         FORMULÁRIO PARA CONCLUIR ATENDIMENTO
                         ================================================== -->

                    <!--
                        Envia uma solicitação POST para o arquivo
                        responsável por alterar o status do agendamento.
                    -->
                    <form
                        action="atualizar_status_agendamento.php"
                        method="POST"
                        onsubmit="return confirm('Confirma que este atendimento foi realizado?');"
                        style="margin-top: 16px;"
                    >


                        <!--
                            Token CSRF utilizado para validar
                            que a requisição veio do sistema.
                        -->
                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?php
                            echo htmlspecialchars(
                                $_SESSION['csrf_agendamentos']
                            );
                            ?>"
                        >


                        <!--
                            Guarda o ID do agendamento
                            que será atualizado.
                        -->
                        <input
                            type="hidden"
                            name="agendamento_id"
                            value="<?php
                            echo (int)
                                $proximo_atendimento['agendamento_id'];
                            ?>"
                        >


                        <!--
                            Informa qual será o novo status.
                            Neste caso, o atendimento será concluído.
                        -->
                        <input
                            type="hidden"
                            name="novo_status"
                            value="concluido"
                        >


                        <!--
                            Identifica a página de origem.
                            Assim, o arquivo de atualização
                            pode retornar ao painel depois.
                        -->
                        <input
                            type="hidden"
                            name="origem"
                            value="painel_profissional.php"
                        >


                        <!-- Botão que confirma a conclusão do atendimento. -->
                        <button
                            type="submit"
                            class="btn-concluir-agendamento"
                        >
                            Concluir atendimento
                        </button>

                    </form>


                    <!-- ==================================================
                         LINK PARA TODOS OS ATENDIMENTOS
                         ================================================== -->

                    <a
                        href="atendimentos.php"
                        class="btn-ver-atendimentos"
                        style="display:inline-block; margin-top:10px;"
                    >
                        Ver todos os atendimentos
                    </a>


                <?php else: ?>


                    <!-- ==================================================
                         SEM PRÓXIMO ATENDIMENTO
                         ================================================== -->

                    <!-- Exibe um marcador quando não há horário. -->
                    <div class="horario">
                        --
                    </div>


                    <!-- Informa que não existe atendimento agendado. -->
                    <div class="cliente">
                        Nenhum atendimento agendado
                    </div>


                    <!-- Explica por que não existem dados para mostrar. -->
                    <div class="informacao">
                        Quando houver um agendamento,
                        os dados da cliente aparecerão aqui.
                    </div>


                    <!-- Campos sem dados. -->
                    <div class="informacao">
                        Serviço: --
                    </div>

                    <div class="informacao">
                        Telefone: --
                    </div>

                    <div class="informacao">
                        E-mail: --
                    </div>


                    <!-- Status informando que não há atendimento. -->
                    <div>

                        <span class="status">
                            Sem atendimento
                        </span>

                    </div>


                <?php endif; ?>

            </div>

        </div>


        <!-- ====================================================
             ROTINA DE TRABALHO
             ==================================================== -->

        <div class="box">

            <!-- Título da seção. -->
            <h3>
                Minha rotina
            </h3>


            <!-- Horário inicial da jornada. -->
            <div class="horario-item">

                <strong>
                    Início
                </strong>

                <span>
                    <?php
                    echo htmlspecialchars($inicio_padrao);
                    ?>
                </span>

            </div>


            <!-- Último horário em que um atendimento pode começar. -->
            <div class="horario-item">

                <strong>
                    Último início
                </strong>

                <span>
                    <?php
                    echo htmlspecialchars($ultimo_inicio);
                    ?>
                </span>

            </div>


            <!-- Horário de encerramento da jornada. -->
            <div class="horario-item">

                <strong>
                    Encerramento
                </strong>

                <span>
                    <?php
                    echo htmlspecialchars($fim_expediente);
                    ?>
                </span>

            </div>


            <!-- Horário de almoço. -->
            <div class="horario-item">

                <strong>
                    Almoço
                </strong>

                <span>

                    <?php
                    echo htmlspecialchars($inicio_almoco);
                    ?>

                    -

                    <?php
                    echo htmlspecialchars($fim_almoco);
                    ?>

                </span>

            </div>


            <!-- Indica onde as folgas são configuradas. -->
            <div class="horario-item">

                <strong>
                    Folgas
                </strong>

                <span>
                    Definidas em Minha Agenda
                </span>

            </div>

        </div>

    </section>


    <!-- ========================================================
         ATALHOS PARA GERENCIAMENTO
         ======================================================== -->

    <section class="box atalhos-box">

        <!-- Título dos atalhos. -->
        <h3>
            Gerenciar minha rotina
        </h3>


        <!-- Agrupa todos os atalhos. -->
        <div class="atalhos">


            <!-- ==================================================
                 MEUS SERVIÇOS
                 ================================================== -->

            <!--
                Permite alterar informações dos serviços,
                como preço, duração e disponibilidade.
            -->
            <a
                href="meus_servicos.php"
                class="atalho"
            >

                <!-- Área reservada para o ícone do atalho. -->
                <div class="icone"></div>

                <!-- Nome da funcionalidade. -->
                <strong>
                    Meus Serviços
                </strong>

                <!-- Descrição da funcionalidade. -->
                <span>
                    Altere preços, duração e disponibilidade.
                </span>

            </a>


            <!-- ==================================================
                 MEUS HORÁRIOS
                 ================================================== -->

            <!--
                Abre diretamente a aba de horários
                da página Minha Agenda.
            -->
            <a
                href="minha_agenda.php?aba=horarios"
                class="atalho"
            >

                <!-- Área reservada para o ícone. -->
                <div class="icone"></div>

                <!-- Nome da funcionalidade. -->
                <strong>
                    Meus Horários
                </strong>

                <!-- Descrição. -->
                <span>
                    Configure dias e horários de trabalho.
                </span>

            </a>


            <!-- ==================================================
                 FOLGAS E BLOQUEIOS
                 ================================================== -->

            <!--
                Abre diretamente a aba de bloqueios
                da agenda profissional.
            -->
            <a
                href="minha_agenda.php?aba=bloqueios"
                class="atalho"
            >

                <!-- Área reservada para o ícone. -->
                <div class="icone"></div>

                <!-- Nome da funcionalidade. -->
                <strong>
                    Folgas e Bloqueios
                </strong>

                <!-- Descrição. -->
                <span>
                    Bloqueie datas e períodos.
                </span>

            </a>


            <!-- ==================================================
                 MINHA AGENDA
                 ================================================== -->

            <!--
                Abre a página principal da agenda.
            -->
            <a
                href="minha_agenda.php"
                class="atalho"
            >

                <!-- Área reservada para o ícone. -->
                <div class="icone"></div>

                <!-- Nome da funcionalidade. -->
                <strong>
                    Minha Agenda
                </strong>

                <!-- Descrição. -->
                <span>
                    Visualize agendamentos, horários e folgas.
                </span>

            </a>


            <!-- ==================================================
                 HISTÓRICO
                 ================================================== -->

            <!--
                Abre a página de atendimentos,
                onde a profissional pode consultar
                os atendimentos realizados.
            -->
            <a
                href="atendimentos.php"
                class="atalho"
            >

                <!-- Área reservada para o ícone. -->
                <div class="icone"></div>

                <!-- Nome da funcionalidade. -->
                <strong>
                    Histórico
                </strong>

                <!-- Descrição. -->
                <span>
                    Consulte atendimentos realizados.
                </span>

            </a>


            <!-- ==================================================
                 MEU PERFIL
                 ================================================== -->

            <!--
                Abre o perfil profissional diretamente
                na área destinada aos avisos.
            -->
            <a
                href="meu_perfil.php#avisos"
                class="atalho"
            >

                <!-- Área reservada para o ícone. -->
                <div class="icone"></div>

                <!-- Nome da funcionalidade. -->
                <strong>
                    Meu Perfil
                </strong>

                <!-- Descrição. -->
                <span>
                    Atualize seus dados e publique avisos para as clientes.
                </span>

            </a>

        </div>

    </section>


    <!-- ========================================================
         AVISO SOBRE CONFIGURAÇÃO DOS HORÁRIOS
         ======================================================== -->

    <div class="aviso">

        <!--
            Explica de onde o sistema obtém
            os horários exibidos no painel.
        -->
        <strong>
            Configuração de horários:
        </strong>


        <!--
            Informa que os horários são carregados
            da tabela horarios quando existem.
        -->
        Os horários são carregados da tabela
        <em>horarios</em>
        quando disponíveis.


        <!-- Quebra de linha para separar as informações. -->
        <br>


        <!--
            Orienta a profissional sobre onde
            alterar seus horários e folgas.
        -->
        <em>
            Altere seus horários e folgas diretamente em Minha Agenda.
        </em>

    </div>

</main>


</body>
</html>