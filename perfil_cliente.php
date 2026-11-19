<?php

// ============================================================
// 1. INICIAR SESSÃO E VALIDAR O ACESSO
// ============================================================

// Inicia a sessão para permitir que o sistema
// identifique a cliente que está atualmente logada.
session_start();

// Inclui o arquivo responsável pela conexão
// com o banco de dados utilizando PDO.
require_once("conexao.php");

// Define o fuso horário utilizado pelo sistema.
// Isso garante que datas e horários sejam calculados
// de acordo com o horário de São Paulo.
date_default_timezone_set("America/Sao_Paulo");


// ============================================================
// VERIFICAÇÃO DE AUTENTICAÇÃO
// ============================================================

// Verifica se existe um ID de usuário armazenado na sessão
// e se o tipo da conta é realmente "cliente".
if (
    !isset($_SESSION["usuario_id"]) ||
    ($_SESSION["usuario_tipo"] ?? "") !== "cliente"
) {

    // Caso o usuário não esteja autenticado como cliente,
// ele é enviado para a tela de login.
    header("Location: login.php");

    // Interrompe imediatamente a execução da página.
    exit();
}


// Recupera o ID da cliente autenticada.
// O casting para inteiro garante que o valor seja tratado
// como um número pelo PHP.
$cliente_id = (int) $_SESSION["usuario_id"];


// Verifica se a URL contém ?editar=1.
// Quando esse parâmetro existe, o formulário de edição
// dos dados pessoais é exibido.
$editar =
    isset($_GET["editar"]) &&
    $_GET["editar"] === "1";


// ============================================================
// 2. INICIALIZAR MENSAGENS E TOKEN DE SEGURANÇA
// ============================================================

// Variável utilizada para armazenar mensagens de erro.
$erro = "";

// Variável utilizada para armazenar mensagens de sucesso.
$sucesso = "";


// ============================================================
// TOKEN CSRF
// ============================================================

// Verifica se já existe um token de segurança
// armazenado na sessão.
if (empty($_SESSION["token_perfil"])) {

    // Gera um token aleatório e seguro.
// Esse token será enviado junto ao formulário
// para confirmar que a requisição veio da própria página.
    $_SESSION["token_perfil"] =
        bin2hex(random_bytes(32));
}


// ============================================================
// 3. FUNÇÃO PARA PROTEGER OS TEXTOS EXIBIDOS
// ============================================================

/*
 * Esta função recebe um valor e transforma caracteres
 * especiais em entidades HTML.
 *
 * Isso evita que informações armazenadas no banco,
 * como nome ou e-mail, sejam interpretadas como código HTML.
 *
 * ENT_QUOTES também protege aspas simples e duplas.
 *
 * UTF-8 garante compatibilidade com acentos
 * e caracteres utilizados no português.
 */
function escaparPerfil($valor)
{
    return htmlspecialchars(
        (string) $valor,
        ENT_QUOTES,
        "UTF-8"
    );
}


// ============================================================
// 4. PROCESSAR A ATUALIZAÇÃO DOS DADOS PESSOAIS
// ============================================================

// Verifica se a página recebeu uma requisição POST.
// POST é utilizado quando a cliente envia o formulário
// para salvar alterações.
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // Mantém a tela no modo de edição caso ocorra
    // algum erro durante a validação ou atualização.
    $editar = true;


    try {

        // ====================================================
        // VALIDAR TOKEN DE SEGURANÇA
        // ====================================================

        // Recupera o token enviado pelo formulário.
        // Caso não tenha sido enviado, utiliza uma string vazia.
        $token_recebido =
            $_POST["token_perfil"] ?? "";


        // Verifica duas condições:
        //
        // 1. O token precisa ser uma string.
        // 2. O token recebido precisa ser igual ao token
        //    armazenado na sessão.
        //
        // hash_equals realiza uma comparação segura
        // para evitar ataques de temporização.
        if (
            !is_string($token_recebido) ||
            !hash_equals(
                $_SESSION["token_perfil"],
                $token_recebido
            )
        ) {

            // Interrompe o processamento caso o token
            // seja inválido ou esteja ausente.
            throw new Exception(
                "Não foi possível validar o formulário. Atualize a página e tente novamente."
            );
        }


        // ====================================================
        // RECEBER OS DADOS DO FORMULÁRIO
        // ====================================================

        // trim remove espaços desnecessários no início
        // e no final do nome.
        $nome = trim(
            $_POST["nome"] ?? ""
        );


        // Recebe o e-mail e remove espaços extras.
        $email = trim(
            $_POST["email"] ?? ""
        );


        // Recebe o telefone e remove espaços extras.
        $telefone = trim(
            $_POST["telefone"] ?? ""
        );


        // ====================================================
        // VALIDAR CAMPOS OBRIGATÓRIOS
        // ====================================================

        // Nome e e-mail são obrigatórios.
        if ($nome === "" || $email === "") {

            // Exibe uma mensagem caso algum dos campos
            // obrigatórios não tenha sido preenchido.
            throw new Exception(
                "Preencha seu nome e seu e-mail."
            );
        }


        // ====================================================
        // VALIDAR E-MAIL
        // ====================================================

        // filter_var verifica se o valor possui
        // um formato válido de endereço de e-mail.
        if (
            !filter_var(
                $email,
                FILTER_VALIDATE_EMAIL
            )
        ) {

            // Interrompe caso o formato seja inválido.
            throw new Exception(
                "Informe um endereço de e-mail válido."
            );
        }


        // ====================================================
        // VALIDAR TAMANHO DOS CAMPOS
        // ====================================================

        // Verifica se os dados não ultrapassam
        // os tamanhos definidos para os campos.
        if (
            mb_strlen($nome) > 150 ||
            mb_strlen($email) > 254 ||
            mb_strlen($telefone) > 30
        ) {

            // Impede o armazenamento de informações
            // maiores do que o permitido.
            throw new Exception(
                "Um ou mais campos ultrapassaram o tamanho permitido."
            );
        }


        // ====================================================
        // VERIFICAR E-MAIL DUPLICADO
        // ====================================================

        // Procura no banco se já existe outra conta
        // utilizando o mesmo endereço de e-mail.
        $consulta_email = $conexao->prepare("
            SELECT id
            FROM usuarios
            WHERE email = :email
              AND id <> :id
            LIMIT 1
        ");


        // Envia os valores para os parâmetros da consulta.
        //
        // O parâmetro :email recebe o novo e-mail.
        // O parâmetro :id recebe o ID da cliente atual.
        //
        // A condição id <> :id impede que a própria conta
        // seja considerada como duplicada.
        $consulta_email->execute([
            ":email" => $email,
            ":id" => $cliente_id
        ]);


        // Verifica se outra conta foi encontrada.
        if (
            $consulta_email->fetch(PDO::FETCH_ASSOC)
        ) {

            // Impede o cadastro de um e-mail
            // que já pertence a outra conta.
            throw new Exception(
                "Este e-mail já está cadastrado em outra conta."
            );
        }


        // ====================================================
        // ATUALIZAR OS DADOS NO BANCO
        // ====================================================

        // Prepara a consulta responsável por atualizar
        // somente os dados pessoais da cliente.
        $atualizacao = $conexao->prepare("
            UPDATE usuarios

            SET
                nome = :nome,
                email = :email,
                telefone = :telefone

            WHERE id = :id
              AND tipo_usuario = 'cliente'
        ");


        // Envia os valores para a consulta preparada.
        $atualizacao->execute([

            // Novo nome.
            ":nome" => $nome,

            // Novo e-mail.
            ":email" => $email,

            // Novo telefone.
            ":telefone" => $telefone,

            // ID da cliente que será atualizada.
            ":id" => $cliente_id
        ]);


        // ====================================================
        // ATUALIZAR A SESSÃO
        // ====================================================

        // Atualiza o nome armazenado na sessão.
        // Assim, outras páginas do sistema passam
        // a utilizar imediatamente o nome novo.
        $_SESSION["usuario_nome"] = $nome;


        // ====================================================
        // RENOVAR TOKEN
        // ====================================================

        // Gera um novo token depois de uma atualização válida.
        // Isso dificulta a reutilização de um token antigo.
        $_SESSION["token_perfil"] =
            bin2hex(random_bytes(32));


        // ====================================================
        // MENSAGEM DE SUCESSO
        // ====================================================

        // Guarda a mensagem na sessão.
        //
        // Isso permite que ela continue disponível
        // depois do redirecionamento.
        $_SESSION["mensagem_perfil"] = [

            // Identifica o tipo da mensagem.
            "tipo" => "sucesso",

            // Texto apresentado para a cliente.
            "texto" =>
                "Seus dados foram atualizados com sucesso."
        ];


        // ====================================================
        // REDIRECIONAMENTO
        // ====================================================

        // Redireciona novamente para o perfil.
        //
        // Essa técnica evita que o navegador tente
        // reenviar o formulário caso a página seja atualizada.
        header(
            "Location: perfil_cliente.php"
        );

        // Interrompe a execução depois do redirecionamento.
        exit();


    } catch (PDOException $erro_banco) {

        // ====================================================
        // ERRO DE BANCO DE DADOS
        // ====================================================

        // Registra os detalhes técnicos no log do servidor.
        //
        // A mensagem completa não é mostrada para a cliente,
        // evitando revelar informações internas do sistema.
        error_log(
            "Erro ao atualizar perfil da cliente: " .
            $erro_banco->getMessage()
        );


        // Mensagem amigável exibida na página.
        $erro =
            "Não foi possível atualizar seus dados. Confira as informações e tente novamente.";


    } catch (Exception $erro_formulario) {

        // ====================================================
        // ERRO DE VALIDAÇÃO
        // ====================================================

        // Recupera a mensagem criada durante as validações.
        $erro =
            $erro_formulario->getMessage();
    }
}


// ============================================================
// RECUPERAR MENSAGENS APÓS O REDIRECIONAMENTO
// ============================================================

// Verifica se existe alguma mensagem armazenada na sessão.
if (isset($_SESSION["mensagem_perfil"])) {

    // Recupera a mensagem.
    $mensagem =
        $_SESSION["mensagem_perfil"];


    // Verifica se a mensagem é de sucesso.
    if (
        ($mensagem["tipo"] ?? "") === "sucesso"
    ) {

        // Copia o texto para a variável que será exibida.
        $sucesso =
            $mensagem["texto"];
    }


    // Remove a mensagem da sessão.
    //
    // Dessa forma, ela não será exibida novamente
    // na próxima atualização da página.
    unset(
        $_SESSION["mensagem_perfil"]
    );
}


// ============================================================
// 5. CARREGAR OS DADOS DA CLIENTE
// ============================================================

try {

    // ========================================================
    // CONSULTAR DADOS DA CLIENTE
    // ========================================================

    // Busca somente os dados pertencentes
    // à cliente autenticada.
    $consulta_cliente = $conexao->prepare("
        SELECT
            nome,
            email,
            telefone,
            data_cadastro

        FROM usuarios

        WHERE id = :id
          AND tipo_usuario = 'cliente'

        LIMIT 1
    ");


    // Envia o ID da cliente para a consulta.
    $consulta_cliente->execute([
        ":id" => $cliente_id
    ]);


    // Recupera os dados como array associativo.
    $cliente =
        $consulta_cliente->fetch(
            PDO::FETCH_ASSOC
        );


    // Verifica se a conta realmente foi encontrada.
    if (!$cliente) {

        // Interrompe o processamento caso a conta
        // não exista mais no banco.
        throw new Exception(
            "Não foi possível localizar sua conta."
        );
    }


} catch (Exception $erro_carregamento) {

    // Registra o problema no log do servidor.
    error_log(
        "Erro ao carregar perfil da cliente: " .
        $erro_carregamento->getMessage()
    );


    // Define o código HTTP como erro interno do servidor.
    http_response_code(500);


    // Encerra a execução mostrando uma mensagem simples.
    exit(
        "Não foi possível carregar seu perfil. Tente novamente mais tarde."
    );
}


// ============================================================
// 6. CARREGAR O RESUMO DE ATIVIDADES
// ============================================================

// Inicializa a quantidade de atendimentos concluídos.
$total_concluidos = 0;

// Inicializa a quantidade de atendimentos futuros.
$total_proximos = 0;

// Inicializa o valor total dos atendimentos concluídos.
$valor_concluidos = 0.0;


try {

    // ========================================================
    // CONTAR ATENDIMENTOS CONCLUÍDOS
    // ========================================================

    // Consulta a quantidade e o valor dos atendimentos
    // que já foram concluídos pela cliente.
    $consulta_resumo = $conexao->prepare("
        SELECT

            // COUNT conta quantos registros foram encontrados.
            COUNT(*) AS total_concluidos,

            // SUM soma os valores dos serviços concluídos.
            //
            // COALESCE(a.preco_final, s.preco, 0)
            // utiliza primeiro o preço final do agendamento.
            // Se ele não existir, utiliza o preço atual do serviço.
            // Se nenhum dos dois existir, utiliza zero.
            COALESCE(
                SUM(
                    COALESCE(
                        a.preco_final,
                        s.preco,
                        0
                    )
                ),
                0
            ) AS valor_concluidos

        FROM agendamentos AS a

        // Relaciona o agendamento com o serviço.
        LEFT JOIN servicos AS s
            ON s.id = a.servico_id

        // Filtra somente os agendamentos
        // pertencentes à cliente atual.
        WHERE a.cliente_id = :cliente_id

          // Considera somente os agendamentos concluídos.
          AND LOWER(TRIM(a.status)) = 'concluido'
    ");


    // Envia o ID da cliente para a consulta.
    $consulta_resumo->execute([
        ":cliente_id" => $cliente_id
    ]);


    // Recupera o resultado da consulta.
    $resumo =
        $consulta_resumo->fetch(
            PDO::FETCH_ASSOC
        );


    // Converte a quantidade encontrada para inteiro.
    $total_concluidos =
        (int) (
            $resumo["total_concluidos"]
            ?? 0
        );


    // Converte o valor encontrado para número decimal.
    $valor_concluidos =
        (float) (
            $resumo["valor_concluidos"]
            ?? 0
        );


    // ========================================================
    // CONTAR PRÓXIMOS ATENDIMENTOS
    // ========================================================

    // Busca a quantidade de agendamentos futuros
    // que ainda estão ativos.
    $consulta_proximos = $conexao->prepare("
        SELECT COUNT(*)

        FROM agendamentos AS a

        WHERE a.cliente_id = :cliente_id

          // Considera somente status que ainda representam
          // um atendimento ativo ou futuro.
          AND LOWER(TRIM(a.status))
              IN (
                  'pendente',
                  'confirmado',
                  'alterado'
              )

          // TIMESTAMP combina a data e o horário do agendamento.
          // A comparação com NOW() garante que somente
          // horários atuais ou futuros sejam contabilizados.
          AND TIMESTAMP(
                  a.data_agendamento,
                  a.horario
              ) >= NOW()
    ");


    // Envia o ID da cliente.
    $consulta_proximos->execute([
        ":cliente_id" => $cliente_id
    ]);


    // fetchColumn retorna diretamente o primeiro valor
    // encontrado pela consulta, que neste caso é o COUNT.
    $total_proximos =
        (int) $consulta_proximos->fetchColumn();


} catch (PDOException $erro_banco) {

    // Registra o erro sem mostrar detalhes técnicos
    // para a cliente.
    error_log(
        "Erro ao carregar resumo do perfil da cliente: " .
        $erro_banco->getMessage()
    );

    // As variáveis permanecem com seus valores padrão,
    // evitando que a página apresente dados incorretos.
}


// ============================================================
// 7. PREPARAR OS DADOS DE APRESENTAÇÃO
// ============================================================

// Remove espaços extras do nome da cliente.
$nome_exibicao =
    trim((string) $cliente["nome"]);


// ============================================================
// AVATAR PROVISÓRIO
// ============================================================

// Obtém somente a primeira letra do nome.
$inicial =
    mb_substr(
        $nome_exibicao,
        0,
        1,
        "UTF-8"
    );


// Converte a inicial para letra maiúscula.
$inicial =
    mb_strtoupper(
        $inicial,
        "UTF-8"
    );


// ============================================================
// DATA DE CADASTRO
// ============================================================

// Define uma mensagem padrão caso
// a data não esteja disponível.
$data_cadastro_exibicao =
    "Não informada";


// Verifica se existe uma data de cadastro.
if (!empty($cliente["data_cadastro"])) {

    try {

        // Converte a data do banco em um objeto DateTime.
        $data_cadastro_objeto =
            new DateTime(
                $cliente["data_cadastro"]
            );


        // Formata a data no padrão brasileiro.
        $data_cadastro_exibicao =
            $data_cadastro_objeto->format(
                "d/m/Y"
            );


    } catch (Exception $erro_data) {

        // Caso a data seja inválida,
        // mantém a mensagem alternativa.
        $data_cadastro_exibicao =
            "Não informada";
    }
}

?>

<!DOCTYPE html>
<html lang="pt-BR">

<head>

    <!-- Define a codificação dos caracteres da página. -->
    <meta charset="UTF-8">

    <!-- Permite que a página se adapte a diferentes telas. -->
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <!-- Título exibido na aba do navegador. -->
    <title>
        Meu Perfil | TCC Estética
    </title>

    <!-- Carrega o CSS externo compartilhado pelo sistema. -->
    <link
        rel="stylesheet"
        href="style.css"
    >

</head>


<!--
    Classes utilizadas para identificar que esta é uma página
    da área da cliente e especificamente da página de perfil.
-->
<body class="pagina-cliente pagina-perfil-cliente">


    <!-- ======================================================
         8. CABEÇALHO E MENU DA CLIENTE
         ====================================================== -->

    <!--
        Cabeçalho principal utilizado nas páginas
        destinadas às clientes.
    -->
    <header class="cabecalho-cliente">


        <!-- ==================================================
             LOGO
             ================================================== -->

        <div class="logo-cliente">

            <!-- Nome da marca/sistema. -->
            <h1>
                Estética
            </h1>

        </div>


        <!-- ==================================================
             MENU DE NAVEGAÇÃO
             ================================================== -->

        <!--
            aria-label identifica o objetivo do menu
            para tecnologias assistivas.
        -->
        <nav
            class="menu-cliente"
            aria-label="Navegação principal"
        >

            <!-- Link para o painel inicial. -->
            <a href="painel_cliente.php">
                Início
            </a>


            <!-- Link para o catálogo de serviços. -->
            <a href="servicos.php">
                Serviços
            </a>


            <!-- Link para os agendamentos da cliente. -->
            <a href="meus_agendamentos.php">
                Meus Agendamentos
            </a>


            <!--
                Link para o próprio perfil.
                A classe ativo destaca a página atual.
                aria-current informa que esta é a página atual.
            -->
            <a
                href="perfil_cliente.php"
                class="ativo"
                aria-current="page"
            >
                Meu Perfil
            </a>


            <!-- Link utilizado para encerrar a sessão. -->
            <a
                href="logout.php"
                class="menu-sair"
            >
                Sair
            </a>

        </nav>

    </header>


    <!-- ======================================================
         9. CONTEÚDO PRINCIPAL DO PERFIL
         ====================================================== -->

    <!--
        Área principal onde são apresentados
        os dados e informações da cliente.
    -->
    <main class="perfil-container">


        <!-- ==================================================
             INTRODUÇÃO DA PÁGINA
             ================================================== -->

        <section class="perfil-introducao">

            <!-- Pequeno identificador da seção. -->
            <span class="perfil-etiqueta">
                MINHA CONTA
            </span>


            <!-- Título principal. -->
            <h2>
                Meu Perfil
            </h2>


            <!-- Descrição da finalidade da página. -->
            <p>
                Consulte seus dados pessoais e acompanhe
                sua atividade no sistema.
            </p>

        </section>


        <!-- ==================================================
             MENSAGEM DE SUCESSO
             ================================================== -->

        <!--
            Só cria este bloco quando existe
            uma mensagem de sucesso para mostrar.
        -->
        <?php if ($sucesso !== ""): ?>

            <div
                class="perfil-mensagem perfil-sucesso"
                role="status"
            >

                <!--
                    Escapa o texto antes de colocá-lo
                    dentro do HTML.
                -->
                <?= escaparPerfil($sucesso) ?>

            </div>

        <?php endif; ?>


        <!-- ==================================================
             MENSAGEM DE ERRO
             ================================================== -->

        <!--
            Só exibe o bloco quando alguma validação
            ou operação apresentou erro.
        -->
        <?php if ($erro !== ""): ?>

            <div
                class="perfil-mensagem perfil-erro"
                role="alert"
            >

                <!-- Exibe a mensagem de erro com segurança. -->
                <?= escaparPerfil($erro) ?>

            </div>

        <?php endif; ?>


        <!-- ==================================================
             IDENTIFICAÇÃO DA CLIENTE
             ================================================== -->

        <!-- Cartão que apresenta as informações principais. -->
        <section
            class="perfil-cartao perfil-identificacao"
        >


            <!-- ==================================================
                 AVATAR
                 ================================================== -->

            <!--
                Como ainda não existe uma foto de perfil,
                a inicial do nome é utilizada como avatar.
            -->
            <div
                class="perfil-foto"
                aria-hidden="true"
            >

                <!-- Exibe a primeira letra do nome. -->
                <span>
                    <?= escaparPerfil($inicial) ?>
                </span>

            </div>


            <!-- ==================================================
                 INFORMAÇÕES BÁSICAS
                 ================================================== -->

            <div class="perfil-identificacao-texto">

                <!-- Nome da cliente. -->
                <h3>
                    <?= escaparPerfil($cliente["nome"]) ?>
                </h3>


                <!-- E-mail cadastrado. -->
                <p>
                    <?= escaparPerfil($cliente["email"]) ?>
                </p>


                <!-- Identifica o tipo da conta. -->
                <span class="perfil-tipo-conta">
                    Cliente
                </span>

            </div>

        </section>


        <!-- ==================================================
             10. DADOS PESSOAIS
             ================================================== -->

        <section
            class="perfil-cartao perfil-dados-pessoais"
        >


            <!-- ==================================================
                 TÍTULO DA SEÇÃO
                 ================================================== -->

            <div class="perfil-titulo-secao">

                <div>

                    <!-- Título da área de dados. -->
                    <h3>
                        Dados pessoais
                    </h3>


                    <!-- Descrição da seção. -->
                    <p>
                        As informações utilizadas na sua conta.
                    </p>

                </div>


                <!--
                    O botão só aparece quando a cliente
                    não está atualmente editando os dados.
                -->
                <?php if (!$editar): ?>

                    <!-- Link que ativa o modo de edição. -->
                    <a
                        href="perfil_cliente.php?editar=1"
                        class="perfil-botao"
                    >
                        Alterar informações pessoais
                    </a>

                <?php endif; ?>

            </div>


            <!-- ==================================================
                 MODO DE EDIÇÃO
                 ================================================== -->

            <?php if ($editar): ?>


                <!--
                    Formulário utilizado para alterar
                    nome, e-mail e telefone.
                -->
                <form
                    method="POST"
                    action="perfil_cliente.php"
                    class="perfil-formulario"
                >


                    <!--
                        Campo oculto que envia o token CSRF.
                        Ele será validado no PHP antes da atualização.
                    -->
                    <input
                        type="hidden"
                        name="token_perfil"
                        value="<?= escaparPerfil(
                            $_SESSION["token_perfil"]
                        ) ?>"
                    >


                    <!-- ==================================================
                         NOME
                         ================================================== -->

                    <div class="perfil-campo">

                        <!-- Rótulo do campo. -->
                        <label for="nome">
                            Nome completo
                        </label>


                        <!--
                            Campo utilizado para editar o nome.
                        -->
                        <input
                            type="text"
                            id="nome"
                            name="nome"
                            maxlength="150"
                            value="<?= escaparPerfil(
                                $cliente["nome"]
                            ) ?>"
                            autocomplete="name"
                            required
                        >

                    </div>


                    <!-- ==================================================
                         E-MAIL
                         ================================================== -->

                    <div class="perfil-campo">

                        <!-- Rótulo do campo. -->
                        <label for="email">
                            E-mail
                        </label>


                        <!--
                            Campo de e-mail.
                            O navegador também pode fazer
                            uma validação básica do formato.
                        -->
                        <input
                            type="email"
                            id="email"
                            name="email"
                            maxlength="254"
                            value="<?= escaparPerfil(
                                $cliente["email"]
                            ) ?>"
                            autocomplete="email"
                            required
                        >

                    </div>


                    <!-- ==================================================
                         TELEFONE
                         ================================================== -->

                    <div class="perfil-campo">

                        <!-- Rótulo do telefone. -->
                        <label for="telefone">
                            Telefone
                        </label>


                        <!-- Campo para alteração do telefone. -->
                        <input
                            type="tel"
                            id="telefone"
                            name="telefone"
                            maxlength="30"
                            value="<?= escaparPerfil(
                                $cliente["telefone"] ?? ""
                            ) ?>"
                            autocomplete="tel"
                        >

                    </div>


                    <!-- ==================================================
                         DATA DE CADASTRO
                         ================================================== -->

                    <div class="perfil-campo">

                        <!-- Rótulo da data. -->
                        <label for="data_cadastro">
                            Data de cadastro
                        </label>


                        <!--
                            A data de cadastro é somente para consulta.
                            Por isso, o campo utiliza readonly.
                        -->
                        <input
                            type="text"
                            id="data_cadastro"
                            value="<?= escaparPerfil(
                                $data_cadastro_exibicao
                            ) ?>"
                            readonly
                        >

                    </div>


                    <!-- ==================================================
                         BOTÕES DO FORMULÁRIO
                         ================================================== -->

                    <div class="perfil-acoes">


                        <!--
                            Envia o formulário para salvar
                            as alterações realizadas.
                        -->
                        <button
                            type="submit"
                            class="perfil-botao"
                        >
                            Salvar alterações
                        </button>


                        <!--
                            Cancela a edição e retorna
                            para a visualização normal.
                        -->
                        <a
                            href="perfil_cliente.php"
                            class="perfil-botao-secundario"
                        >
                            Cancelar edição
                        </a>

                    </div>

                </form>


            <?php else: ?>


                <!-- ==================================================
                     MODO DE VISUALIZAÇÃO
                     ================================================== -->

                <!--
                    Quando não está editando, os dados aparecem
                    apenas como informações.
                -->
                <div class="perfil-dados-grade">


                    <!-- ==================================================
                         NOME
                         ================================================== -->

                    <div class="perfil-dado">

                        <!-- Nome do campo. -->
                        <span>
                            Nome completo
                        </span>


                        <!-- Valor armazenado no banco. -->
                        <strong>
                            <?= escaparPerfil(
                                $cliente["nome"]
                            ) ?>
                        </strong>

                    </div>


                    <!-- ==================================================
                         E-MAIL
                         ================================================== -->

                    <div class="perfil-dado">

                        <!-- Nome do campo. -->
                        <span>
                            E-mail
                        </span>


                        <!-- E-mail armazenado. -->
                        <strong>
                            <?= escaparPerfil(
                                $cliente["email"]
                            ) ?>
                        </strong>

                    </div>


                    <!-- ==================================================
                         TELEFONE
                         ================================================== -->

                    <div class="perfil-dado">

                        <!-- Nome do campo. -->
                        <span>
                            Telefone
                        </span>


                        <!--
                            Se não houver telefone,
                            mostra "Não informado".
                        -->
                        <strong>

                            <?= escaparPerfil(

                                !empty(
                                    $cliente["telefone"]
                                )

                                ? $cliente["telefone"]

                                : "Não informado"

                            ) ?>

                        </strong>

                    </div>


                    <!-- ==================================================
                         DATA DE CADASTRO
                         ================================================== -->

                    <div class="perfil-dado">

                        <!-- Nome do campo. -->
                        <span>
                            Data de cadastro
                        </span>


                        <!-- Data formatada no padrão brasileiro. -->
                        <strong>
                            <?= escaparPerfil(
                                $data_cadastro_exibicao
                            ) ?>
                        </strong>

                    </div>

                </div>


            <?php endif; ?>

        </section>


        <!-- ======================================================
             11. RESUMO DA ATIVIDADE DA CLIENTE
             ====================================================== -->

        <section
            class="perfil-cartao perfil-resumo-atividades"
        >


            <!-- ==================================================
                 TÍTULO DA SEÇÃO
                 ================================================== -->

            <div class="perfil-titulo-secao">

                <!-- Título do resumo. -->
                <h3>
                    Minha atividade
                </h3>


                <!-- Explicação do conteúdo. -->
                <p>
                    Um resumo dos seus atendimentos registrados no sistema.
                </p>

            </div>


            <!-- ==================================================
                 INDICADORES
                 ================================================== -->

            <div class="perfil-indicadores">


                <!-- ==================================================
                     PRÓXIMOS ATENDIMENTOS
                     ================================================== -->

                <article class="perfil-indicador">

                    <!-- Nome do indicador. -->
                    <span>
                        Próximos atendimentos
                    </span>


                    <!-- Quantidade calculada no banco. -->
                    <strong>
                        <?= $total_proximos ?>
                    </strong>


                    <!-- Explicação do indicador. -->
                    <p>
                        Agendamentos futuros ativos.
                    </p>

                </article>


                <!-- ==================================================
                     ATENDIMENTOS CONCLUÍDOS
                     ================================================== -->

                <article class="perfil-indicador">

                    <!-- Nome do indicador. -->
                    <span>
                        Atendimentos concluídos
                    </span>


                    <!-- Quantidade de atendimentos concluídos. -->
                    <strong>
                        <?= $total_concluidos ?>
                    </strong>


                    <!-- Explicação do indicador. -->
                    <p>
                        Serviços marcados como concluídos.
                    </p>

                </article>


                <!-- ==================================================
                     VALOR DOS SERVIÇOS CONCLUÍDOS
                     ================================================== -->

                <article class="perfil-indicador">

                    <!-- Nome do indicador. -->
                    <span>
                        Valor dos serviços concluídos
                    </span>


                    <!--
                        number_format transforma o número em
                        formato monetário brasileiro.
                    -->
                    <strong>

                        R$
                        <?= number_format(
                            $valor_concluidos,
                            2,
                            ",",
                            "."
                        ) ?>

                    </strong>


                    <!-- Explicação do valor apresentado. -->
                    <p>
                        Soma dos valores registrados como concluídos.
                    </p>

                </article>

            </div>


            <!-- ==================================================
                 ATALHOS DO RESUMO
                 ================================================== -->

            <div class="perfil-acoes">


                <!--
                    Leva para a página onde a cliente
                    consegue consultar seus agendamentos.
                -->
                <a
                    href="meus_agendamentos.php"
                    class="perfil-botao"
                >
                    Ver meus agendamentos
                </a>


                <!--
                    Retorna para o painel principal da cliente.
                -->
                <a
                    href="painel_cliente.php"
                    class="perfil-botao-secundario"
                >
                    Voltar ao início
                </a>

            </div>

        </section>

    </main>


    <!-- ======================================================
         RODAPÉ
         ====================================================== -->

    <!--
        Rodapé utilizado para identificar a área
        da cliente dentro do sistema.
    -->
    <footer class="rodape-agendamentos">

        <p>
            TCC Estética — Área da Cliente
        </p>

    </footer>


</body>
</html>