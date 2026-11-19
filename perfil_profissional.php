<?php
// ============================================================
// 1. INICIAR SESSÃO E VALIDAR O ACESSO
// ============================================================

// Inicia a sessão para permitir que o sistema identifique
// qual cliente está atualmente autenticada.
session_start();

// Verifica se existe um ID de usuário na sessão.
// Também verifica se o tipo de usuário é realmente "cliente".
// Dessa forma, uma profissional ou pessoa não autenticada
// não consegue acessar o perfil de uma profissional por esta página.
if (
    !isset($_SESSION["usuario_id"]) ||
    !isset($_SESSION["usuario_tipo"]) ||
    $_SESSION["usuario_tipo"] !== "cliente"
) {
    // Redireciona usuários sem permissão para a tela de login.
    header("Location: login.php");

    // Interrompe completamente a execução da página.
    exit();
}

// Carrega o arquivo responsável pela conexão com o banco de dados.
// A variável $conexao será utilizada nas consultas SQL abaixo.
require_once("conexao.php");

// Define o fuso horário oficial utilizado pelo sistema.
// Isso evita diferenças de horário entre o servidor e o horário brasileiro.
date_default_timezone_set("America/Sao_Paulo");


// ============================================================
// 2. IDENTIFICAR A PROFISSIONAL
// ============================================================

// Recupera o ID da profissional enviado pela URL.
// Exemplo: perfil_profissional.php?id=6
//
// FILTER_VALIDATE_INT garante que o valor recebido seja
// realmente um número inteiro válido.
$profissional_id = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);

// Verifica se o ID não existe ou se possui valor inválido.
// Um ID menor ou igual a zero também não representa uma
// profissional válida no sistema.
if (!$profissional_id || $profissional_id <= 0) {

    // Caso o ID seja inválido, volta para a página de serviços.
    header("Location: servicos.php");

    // Encerra a execução para impedir consultas inválidas.
    exit();
}


// ============================================================
// 3. BUSCAR OS DADOS DO PERFIL
// ============================================================

// Monta a consulta responsável por localizar a profissional.
//
// São buscados somente os campos necessários para montar
// o perfil apresentado para a cliente.
$sql_profissional = "
    SELECT
        id,
        nome,
        especialidade,
        descricao,
        redes_sociais,
        chave_pix,
        tipo_chave_pix
    FROM usuarios
    WHERE id = :id
      AND tipo_usuario = 'profissional'
      AND status = 'ativo'
    LIMIT 1
";

// Prepara a consulta utilizando PDO.
// A preparação ajuda a proteger a consulta contra SQL Injection.
$stmt_profissional = $conexao->prepare($sql_profissional);

// Envia o ID recebido pela URL para o parâmetro :id.
// O valor é tratado como parâmetro da consulta e não
// inserido diretamente no SQL.
$stmt_profissional->execute([
    ":id" => $profissional_id
]);

// Recupera o resultado da consulta como um array associativo.
// Cada coluna poderá ser acessada pelo nome.
$profissional = $stmt_profissional->fetch(PDO::FETCH_ASSOC);

// Verifica se alguma profissional foi encontrada.
//
// Se não houver resultado, significa que o ID não corresponde
// a uma profissional ativa existente.
if (!$profissional) {

    // Volta para a página de serviços.
    header("Location: servicos.php");

    // Encerra a execução da página.
    exit();
}


// ============================================================
// 4. TRATAR OS DADOS PARA EXIBIÇÃO
// ============================================================

// Cria um mapa para transformar os códigos internos
// utilizados pelo banco em nomes que serão apresentados
// de forma mais amigável para a cliente.
$especialidades = [
    "nail" => "Unhas",
    "lash" => "Cílios",
    "eyebrow" => "Sobrancelhas",
    "cliente" => "Não informada"
];

// Busca a especialidade da profissional no mapa acima.
//
// Caso a especialidade esteja vazia ou não exista no mapa,
// utiliza "Não informada" como texto padrão.
$especialidade = $especialidades[
    $profissional["especialidade"] ?? ""
] ?? "Não informada";

// Recupera a descrição da profissional.
//
// trim() remove espaços desnecessários no começo e no final.
// O operador ?? evita erro caso o campo não exista.
$descricao = trim($profissional["descricao"] ?? "");

// Recupera as redes sociais cadastradas pela profissional.
$redes_sociais = trim($profissional["redes_sociais"] ?? "");

// Recupera a chave Pix cadastrada pela profissional.
$chave_pix = trim($profissional["chave_pix"] ?? "");

// Recupera o tipo da chave Pix, como CPF, telefone, e-mail etc.
$tipo_chave_pix = trim($profissional["tipo_chave_pix"] ?? "");


// ============================================================
// 5. BUSCAR OS SERVIÇOS DA PROFISSIONAL
// ============================================================

// Monta a consulta responsável por buscar os serviços
// vinculados à profissional selecionada.
//
// A tabela "vinculacao" faz a ligação entre a profissional
// e os serviços que ela oferece.
//
// O INNER JOIN garante que somente serviços que possuem
// uma vinculação com esta profissional sejam retornados.
$sql_servicos = "
    SELECT DISTINCT
        s.id,
        s.nome_servico,
        s.tipo_servico,
        s.descricao,
        s.preco,
        s.duracao
    FROM servicos s
    INNER JOIN vinculacao v
        ON v.servico_id = s.id
       AND v.profissional_id = :profissional_id
    WHERE s.profissional_id = :profissional_id_dono
      AND s.status = 'ativo'
    ORDER BY s.nome_servico ASC
";

// Prepara a consulta dos serviços.
$stmt_servicos = $conexao->prepare($sql_servicos);

// Executa a consulta enviando o ID da profissional
// para os dois parâmetros utilizados no SQL.
$stmt_servicos->execute([
    ":profissional_id" => $profissional_id,
    ":profissional_id_dono" => $profissional_id
]);

// Recupera todos os serviços encontrados.
// Como podem existir vários serviços, utilizamos fetchAll().
$servicos = $stmt_servicos->fetchAll(PDO::FETCH_ASSOC);


// ============================================================
// 6. FUNÇÃO PARA EXIBIR TEXTO COM SEGURANÇA
// ============================================================

// Cria uma função reutilizável para proteger textos
// vindos do banco de dados ou de outras fontes.
//
// htmlspecialchars() transforma caracteres especiais
// em entidades HTML.
//
// Isso evita que um texto cadastrado no banco seja
// interpretado como código HTML ou JavaScript pelo navegador.
function escapar($texto)
{
    return htmlspecialchars(
        (string) $texto,
        ENT_QUOTES,
        "UTF-8"
    );
}
?>

<!DOCTYPE html>
<html lang="pt-BR">

<head>

    <!-- Define a codificação utilizada pela página. -->
    <meta charset="UTF-8">

    <!-- Faz a página se adaptar corretamente a celulares e tablets. -->
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <!-- Define o título exibido na aba do navegador. -->
    <title>Perfil da Profissional | TCC Estética</title>

    <!--
        Carrega o arquivo CSS externo do sistema.
        Dessa forma, o estilo da página fica separado
        da estrutura HTML.
    -->
    <link rel="stylesheet" href="style.css">

</head>

<!--
    Classe específica da página.
    Ela permite aplicar estilos somente ao perfil
    da profissional sem interferir nas outras páginas.
-->
<body class="pagina-perfil-profissional">


    <!-- ======================================================
         7. MENU SUPERIOR
         ====================================================== -->

    <!--
        Cabeçalho principal da área da cliente.
        Contém a identidade do sistema e os links de navegação.
    -->
    <header class="cabecalho-perfil">

        <!--
            Mantém o conteúdo interno do cabeçalho organizado
            e limitado pela estrutura definida no CSS.
        -->
        <div class="conteudo-cabecalho-perfil">


            <!--
                Logo/nome do sistema.

                Ao clicar, a cliente retorna para o painel inicial.
            -->
            <a href="painel_cliente.php" class="logo-perfil">
                TCC Estética
            </a>


            <!--
                Menu principal da área da cliente.

                aria-label identifica o objetivo do menu
                para tecnologias assistivas.
            -->
            <nav class="menu-perfil" aria-label="Menu principal">

                <!-- Link para o painel inicial da cliente. -->
                <a href="painel_cliente.php">
                    Início
                </a>

                <!-- Link para o catálogo de serviços. -->
                <a href="servicos.php">
                    Serviços
                </a>

                <!-- Link para os agendamentos realizados pela cliente. -->
                <a href="meus_agendamentos.php">
                    Meus agendamentos
                </a>

                <!-- Link para o perfil da própria cliente. -->
                <a href="perfil_cliente.php">
                    Meu perfil
                </a>

                <!-- Link responsável por encerrar a sessão. -->
                <a href="logout.php">
                    Sair
                </a>

            </nav>

        </div>

    </header>


    <!-- ======================================================
         8. CONTEÚDO PRINCIPAL DO PERFIL DA PROFISSIONAL
         ====================================================== -->

    <!--
        Área principal da página.

        Dentro dela serão exibidos:
        - identificação da profissional;
        - especialidade;
        - descrição;
        - redes sociais;
        - informações de pagamento;
        - serviços oferecidos.
    -->
    <main class="conteudo-perfil-profissional">


        <!-- ==================================================
             9. CABEÇALHO DO PERFIL DA PROFISSIONAL
             ================================================== -->

        <!--
            Cartão principal contendo as informações
            de apresentação da profissional.
        -->
        <section class="cartao-perfil-profissional">


            <!--
                Link para retornar ao catálogo de serviços.
            -->
            <a href="servicos.php" class="link-voltar-perfil">
                &larr; Voltar aos serviços
            </a>


            <!--
                Área responsável por apresentar
                a identificação da profissional.
            -->
            <div class="identificacao-profissional">


                <!--
                    Mostra a primeira letra do nome da profissional
                    como um avatar provisório.

                    aria-hidden="true" informa que esse elemento
                    é apenas visual e pode ser ignorado por leitores
                    de tela.
                -->
                <div class="iniciais-profissional" aria-hidden="true">

                    <?= escapar(
                        mb_strtoupper(
                            mb_substr(
                                $profissional["nome"],
                                0,
                                1,
                                "UTF-8"
                            ),
                            "UTF-8"
                        )
                    ) ?>

                </div>


                <!--
                    Contém as informações textuais
                    da profissional.
                -->
                <div class="informacoes-profissional">


                    <!--
                        Pequeno identificador visual
                        indicando que aquela área é um perfil profissional.
                    -->
                    <p class="texto-identificacao">
                        PERFIL PROFISSIONAL
                    </p>


                    <!--
                        Exibe o nome completo da profissional.

                        A função escapar() protege o conteúdo
                        antes de colocá-lo dentro do HTML.
                    -->
                    <h1>
                        <?= escapar($profissional["nome"]) ?>
                    </h1>


                    <!--
                        Exibe a especialidade traduzida
                        para um nome amigável.
                    -->
                    <span class="especialidade-profissional">
                        <?= escapar($especialidade) ?>
                    </span>

                </div>

            </div>


            <!-- ==================================================
                 10. DESCRIÇÃO DA PROFISSIONAL
                 ================================================== -->

            <!--
                Verifica se existe uma descrição cadastrada.
            -->
            <?php if ($descricao !== ""): ?>

                <div class="secao-descricao-profissional">

                    <!-- Título da seção de apresentação. -->
                    <h2>Sobre a profissional</h2>

                    <!--
                        Exibe a descrição cadastrada.

                        escapar() protege o texto.
                        nl2br() transforma quebras de linha
                        do banco em <br> para manter a formatação.
                    -->
                    <p>
                        <?= nl2br(escapar($descricao)) ?>
                    </p>

                </div>

            <?php else: ?>

                <!--
                    Caso não exista descrição cadastrada,
                    apresenta uma mensagem alternativa.
                -->
                <div class="secao-descricao-profissional">

                    <h2>Sobre a profissional</h2>

                    <p>
                        Esta profissional ainda não cadastrou uma descrição.
                    </p>

                </div>

            <?php endif; ?>


            <!-- ==================================================
                 11. REDES SOCIAIS
                 ================================================== -->

            <!--
                Área responsável por apresentar
                as redes sociais cadastradas pela profissional.
            -->
            <div class="secao-contato-profissional">

                <!-- Título da seção. -->
                <h2>Redes sociais</h2>


                <!--
                    Verifica se a profissional cadastrou
                    alguma informação de rede social.
                -->
                <?php if ($redes_sociais !== ""): ?>

                    <!--
                        Exibe as redes sociais.

                        nl2br() preserva as quebras de linha
                        caso a profissional tenha cadastrado
                        mais de uma rede social em linhas diferentes.
                    -->
                    <p class="texto-redes-sociais">
                        <?= nl2br(escapar($redes_sociais)) ?>
                    </p>

                <?php else: ?>

                    <!--
                        Mensagem apresentada quando
                        nenhuma rede social foi cadastrada.
                    -->
                    <p>
                        Nenhuma rede social informada no momento.
                    </p>

                <?php endif; ?>

            </div>


            <!-- ==================================================
                 12. INFORMAÇÕES DE PAGAMENTO
                 ================================================== -->

            <!--
                Área destinada à exibição da chave Pix
                cadastrada pela profissional.
            -->
            <div class="secao-pix-profissional">

                <!-- Título da seção de pagamento. -->
                <h2>Informações de pagamento</h2>


                <!--
                    Verifica se existe uma chave Pix cadastrada.
                -->
                <?php if ($chave_pix !== ""): ?>


                    <!--
                        O tipo da chave é opcional.
                        Portanto, somente será exibido se
                        houver informação cadastrada.
                    -->
                    <?php if ($tipo_chave_pix !== ""): ?>

                        <p>

                            <!-- Identifica o campo para a cliente. -->
                            <strong>Tipo de chave:</strong>

                            <!-- Exibe o tipo da chave com segurança. -->
                            <?= escapar($tipo_chave_pix) ?>

                        </p>

                    <?php endif; ?>


                    <!--
                        Exibe a chave Pix cadastrada.
                    -->
                    <p>

                        <!-- Identifica o valor apresentado. -->
                        <strong>Chave Pix:</strong>

                        <!--
                            Classe utilizada pelo CSS para
                            destacar visualmente a chave.
                        -->
                        <span class="chave-pix">

                            <!--
                                Protege a chave antes da exibição.
                            -->
                            <?= escapar($chave_pix) ?>

                        </span>

                    </p>


                    <!--
                        Aviso para a cliente conferir
                        os dados antes de realizar o pagamento.
                    -->
                    <p class="aviso-pix">

                        Confira os dados antes de realizar qualquer pagamento.
                        A confirmação do pagamento é feita pela profissional.

                    </p>


                <?php else: ?>

                    <!--
                        Mensagem apresentada quando a profissional
                        ainda não possui uma chave Pix cadastrada.
                    -->
                    <p>
                        A profissional ainda não cadastrou uma chave Pix.
                    </p>

                <?php endif; ?>

            </div>

        </section>


        <!-- ==================================================
             13. LISTAGEM DOS SERVIÇOS DA PROFISSIONAL
             ================================================== -->

        <!--
            Seção que apresenta todos os serviços ativos
            vinculados à profissional.
        -->
        <section class="secao-servicos-profissional">


            <!--
                Cabeçalho da lista de serviços.
            -->
            <div class="titulo-secao-perfil">

                <div>

                    <!-- Identificação visual da categoria da seção. -->
                    <p class="texto-identificacao">
                        ATENDIMENTOS
                    </p>

                    <!-- Título principal da listagem. -->
                    <h2>
                        Serviços oferecidos
                    </h2>

                </div>

            </div>


            <!--
                Verifica se a profissional possui
                pelo menos um serviço ativo.
            -->
            <?php if (count($servicos) > 0): ?>


                <!--
                    Container que organiza os cartões
                    dos serviços através do CSS.
                -->
                <div class="lista-servicos-profissional">


                    <!--
                        Percorre todos os serviços encontrados
                        na consulta ao banco de dados.

                        Para cada serviço, será criado um cartão.
                    -->
                    <?php foreach ($servicos as $servico): ?>


                        <!--
                            Cartão individual de um serviço.
                        -->
                        <article class="cartao-servico-perfil">


                            <!--
                                Área com as informações principais
                                do serviço.
                            -->
                            <div class="informacoes-servico-perfil">


                                <!--
                                    Nome do serviço cadastrado.
                                -->
                                <h3>
                                    <?= escapar($servico["nome_servico"]) ?>
                                </h3>


                                <!--
                                    Exibe o tipo do serviço somente
                                    quando esse campo possui informação.
                                -->
                                <?php if (!empty($servico["tipo_servico"])): ?>

                                    <span class="tipo-servico-perfil">

                                        <!--
                                            Exemplo:
                                            completo, manutenção ou remoção.
                                        -->
                                        <?= escapar($servico["tipo_servico"]) ?>

                                    </span>

                                <?php endif; ?>


                                <!--
                                    Exibe a descrição somente
                                    quando o serviço possui uma.
                                -->
                                <?php if (!empty($servico["descricao"])): ?>

                                    <p>

                                        <!--
                                            Preserva as quebras de linha
                                            existentes na descrição.
                                        -->
                                        <?= nl2br(escapar($servico["descricao"])) ?>

                                    </p>

                                <?php endif; ?>

                            </div>


                            <!--
                                Área que apresenta os detalhes
                                comerciais do serviço.
                            -->
                            <div class="detalhes-servico-perfil">


                                <!--
                                    Exibe a duração cadastrada.

                                    Caso não exista duração,
                                    apresenta "A combinar".
                                -->
                                <p>

                                    <strong>Duração:</strong>

                                    <?= escapar(
                                        $servico["duracao"] ?: "A combinar"
                                    ) ?>

                                </p>


                                <!--
                                    Exibe o preço do serviço.
                                -->
                                <p>

                                    <strong>Preço:</strong>


                                    <!--
                                        Verifica se existe um preço
                                        cadastrado para o serviço.
                                    -->
                                    <?php if ($servico["preco"] !== null): ?>

                                        <!--
                                            number_format() transforma
                                            o número para o padrão brasileiro.

                                            Exemplo:
                                            1500.50 -> R$ 1.500,50
                                        -->
                                        R$ <?= number_format(
                                            (float) $servico["preco"],
                                            2,
                                            ",",
                                            "."
                                        ) ?>


                                    <?php else: ?>

                                        <!--
                                            Mensagem exibida quando
                                            o preço ainda não foi informado.
                                        -->
                                        A consultar

                                    <?php endif; ?>

                                </p>

                            </div>


                            <!--
                                Botão que leva a cliente para a agenda.

                                São enviados:
                                - o ID do serviço;
                                - o ID da profissional.

                                Dessa forma, a página agenda.php
                                consegue saber exatamente qual serviço
                                e qual profissional foram escolhidos.
                            -->
                            <a
                                class="botao-agenda-perfil"
                                href="agenda.php?servico_id=<?= (int) $servico["id"] ?>&profissional_id=<?= (int) $profissional["id"] ?>"
                            >
                                Ver agenda
                            </a>

                        </article>


                    <!--
                        Finaliza o foreach e passa para o próximo serviço.
                    -->
                    <?php endforeach; ?>


                </div>


            <?php else: ?>


                <!--
                    Caso nenhum serviço ativo tenha sido encontrado,
                    apresenta uma mensagem informativa para a cliente.
                -->
                <div class="mensagem-servicos-vazios">

                    <p>

                        Esta profissional ainda não possui serviços
                        ativos disponíveis para exibição.

                    </p>

                </div>


            <?php endif; ?>

        </section>

    </main>

</body>
</html>