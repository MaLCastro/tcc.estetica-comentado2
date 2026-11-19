<?php
// Abre o código PHP que será executado no servidor.
// Tudo que estiver entre <?php será processado pelo PHP antes da página ser enviada ao navegador.


// ============================================================
// 1. INICIAR SESSÃO, CONEXÃO E PROTEÇÃO CSRF
// ============================================================

// Inicia a sessão do usuário.
// A sessão permite guardar e recuperar informações como o ID,
// nome e tipo de usuário durante a navegação pelo sistema.
session_start();

// Inclui o arquivo responsável pela conexão com o banco de dados.
// O require_once impede que o mesmo arquivo seja incluído mais de uma vez.
require_once("conexao.php");

// Define o fuso horário utilizado pelo sistema.
// America/Sao_Paulo corresponde ao horário oficial utilizado no Brasil.
date_default_timezone_set("America/Sao_Paulo");

// Verifica se existe um usuário autenticado na sessão
// e se o usuário possui o tipo "profissional".
if (
    // Verifica se o ID do usuário não existe na sessão.
    !isset($_SESSION["usuario_id"]) ||

    // Verifica se o tipo do usuário não é profissional.
    ($_SESSION["usuario_tipo"] ?? "") !== "profissional"
) {
    // Caso o usuário não seja uma profissional autenticada,
    // redireciona para a tela de login.
    header("Location: login.php");

    // Interrompe imediatamente a execução do restante do arquivo.
    exit();
}

// Obtém o ID da profissional armazenado na sessão.
// O (int) converte o valor para número inteiro.
$profissional_id = (int) $_SESSION["usuario_id"];

// Verifica se ainda não existe um token CSRF para esta sessão.
if (empty($_SESSION["csrf_perfil"])) {

    // Gera um token aleatório seguro com 32 bytes
    // e transforma o resultado em uma sequência hexadecimal.
    $_SESSION["csrf_perfil"] = bin2hex(random_bytes(32));
}

// Cria uma variável vazia para armazenar mensagens de sucesso.
$mensagem_sucesso = "";

// Cria uma variável vazia para armazenar mensagens de erro.
$mensagem_erro = "";

// Indica inicialmente que não ocorreu erro na consulta do perfil.
$erroConsulta = false;

// Verifica se a URL possui ?editar=1.
// Se possuir, o formulário de edição será exibido.
$modoEdicao = ($_GET["editar"] ?? "") === "1";

// Inicializa a variável que armazenará a chave Pix preenchida no formulário.
$chave_pix_formulario = "";

// Inicializa a variável que armazenará a descrição profissional.
$descricao_formulario = "";

// Inicializa a variável que armazenará as redes sociais.
$redes_sociais_formulario = "";

// Inicializa o título do aviso.
$titulo_aviso_formulario = "";

// Inicializa a mensagem do aviso.
$mensagem_aviso_formulario = "";

// Cria um array vazio para armazenar os avisos da profissional.
$avisos_profissional = [];

// Cria um array vazio para armazenar os dados da profissional.
$usuario = [];


// ------------------------------------------------------------
// RECUPERAR MENSAGENS TEMPORÁRIAS
// ------------------------------------------------------------

// Verifica se existe uma mensagem de sucesso armazenada na sessão.
if (isset($_SESSION["perfil_flash_sucesso"])) {

    // Copia a mensagem da sessão para a variável que será exibida na página.
    $mensagem_sucesso = $_SESSION["perfil_flash_sucesso"];

    // Remove a mensagem da sessão para que ela não apareça novamente
    // depois de atualizar ou acessar outra página.
    unset($_SESSION["perfil_flash_sucesso"]);
}

// Verifica se existe uma mensagem de erro armazenada na sessão.
if (isset($_SESSION["perfil_flash_erro"])) {

    // Copia a mensagem de erro da sessão para a variável da página.
    $mensagem_erro = $_SESSION["perfil_flash_erro"];

    // Remove a mensagem depois de recuperá-la.
    unset($_SESSION["perfil_flash_erro"]);
}


// ============================================================
// 2. FUNÇÕES AUXILIARES
// ============================================================

// Cria uma função para proteger textos antes de colocá-los no HTML.
function escapar($valor)
{
    // htmlspecialchars transforma caracteres especiais em entidades HTML.
    // Isso ajuda a impedir que códigos HTML ou JavaScript enviados pelo usuário
    // sejam interpretados pelo navegador.
    return htmlspecialchars(

        // Converte o valor recebido para texto.
        (string) ($valor ?? ""),

        // ENT_QUOTES também protege aspas simples e duplas.
        ENT_QUOTES,

        // Define UTF-8 para preservar corretamente caracteres como ç e acentos.
        "UTF-8"
    );
}


// Cria uma função para contar caracteres de textos.
function contarCaracteres($texto)
{
    // Verifica se a extensão mbstring está disponível no PHP.
    return function_exists("mb_strlen")

        // Se estiver disponível, conta caracteres considerando UTF-8.
        ? mb_strlen($texto, "UTF-8")

        // Caso contrário, utiliza strlen como alternativa.
        : strlen($texto);
}


// Cria uma função para validar o token CSRF enviado pelo formulário.
function validarTokenCSRF()
{
    // Verifica se o formulário enviou o token,
    // se existe um token armazenado na sessão,
    // e se o token recebido é realmente uma string.
    return isset($_POST["csrf_token"], $_SESSION["csrf_perfil"])
        && is_string($_POST["csrf_token"])

        // hash_equals compara os dois tokens de maneira segura.
        && hash_equals(

            // Primeiro token: o armazenado na sessão.
            $_SESSION["csrf_perfil"],

            // Segundo token: o enviado pelo formulário.
            $_POST["csrf_token"]
        );
}


// ============================================================
// 3. CARREGAR DADOS DO PERFIL
// ============================================================

try {

    // Prepara uma consulta SQL para buscar os dados da profissional.
    // O prepare permite utilizar parâmetros e evita inserir diretamente
    // valores recebidos do usuário na consulta SQL.
    $consulta = $conexao->prepare("
        SELECT
            id,
            nome,
            email,
            telefone,
            especialidade,
            chave_pix,
            descricao,
            redes_sociais
        FROM usuarios
        WHERE id = :id
          AND tipo_usuario = 'profissional'
        LIMIT 1
    ");

    // Executa a consulta enviando o ID da profissional como parâmetro.
    $consulta->execute([
        ":id" => $profissional_id
    ]);

    // Obtém o primeiro resultado encontrado como array associativo.
    $usuario = $consulta->fetch(PDO::FETCH_ASSOC);

    // Verifica se nenhum usuário foi encontrado.
    if (!$usuario) {

        // Limpa todas as informações armazenadas na sessão.
        $_SESSION = [];

        // Verifica se a sessão utiliza cookies.
        if (ini_get("session.use_cookies")) {

            // Obtém as configurações atuais do cookie da sessão.
            $parametros = session_get_cookie_params();

            // Expira o cookie da sessão no navegador.
            setcookie(
                session_name(),
                "",
                time() - 42000,
                $parametros["path"],
                $parametros["domain"],
                $parametros["secure"],
                $parametros["httponly"]
            );
        }

        // Destrói completamente a sessão atual.
        session_destroy();

        // Envia a profissional novamente para a página de login.
        header("Location: login.php");

        // Interrompe a execução.
        exit();
    }

    // Carrega a chave Pix atual para o formulário.
    $chave_pix_formulario = $usuario["chave_pix"] ?? "";

    // Carrega a descrição atual para o formulário.
    $descricao_formulario = $usuario["descricao"] ?? "";

    // Carrega as redes sociais atuais para o formulário.
    $redes_sociais_formulario = $usuario["redes_sociais"] ?? "";

    // Atualiza o nome armazenado na sessão.
    // Isso faz com que outras páginas possam utilizar o nome atualizado.
    $_SESSION["usuario_nome"] = $usuario["nome"];

    // Atualiza a especialidade armazenada na sessão.
    $_SESSION["especialidade"] = $usuario["especialidade"] ?? "";


// Captura erros específicos relacionados ao banco de dados.
} catch (PDOException $e) {

    // Registra o erro técnico no log do servidor.
    // A mensagem completa não é mostrada para a profissional.
    error_log("Erro ao carregar perfil: " . $e->getMessage());

    // Define uma mensagem amigável para aparecer na página.
    $mensagem_erro = "Não foi possível carregar os dados do perfil.";

    // Informa que ocorreu um erro durante a consulta.
    $erroConsulta = true;
}


// ============================================================
// 4. PROCESSAR FORMULÁRIOS COM PROTEÇÃO CSRF
// ============================================================

// Verifica se não houve erro na consulta do perfil
// e se a requisição enviada para esta página utiliza o método POST.
if (
    !$erroConsulta &&
    $_SERVER["REQUEST_METHOD"] === "POST"
) {

    // Verifica se o token CSRF enviado pelo formulário é válido.
    if (!validarTokenCSRF()) {

        // Define o código HTTP 403, indicando acesso proibido.
        http_response_code(403);

        // Informa que a solicitação não pôde ser validada.
        $mensagem_erro =
            "Sua solicitação expirou ou não pôde ser validada. Atualize a página e tente novamente.";

    } else {

        // Obtém a ação enviada pelo formulário.
        // Caso nenhuma ação seja enviada, utiliza uma string vazia.
        $acao = $_POST["acao"] ?? "";


        // ----------------------------------------------------
        // 4.1. SALVAR INFORMAÇÕES PESSOAIS E PÚBLICAS
        // ----------------------------------------------------

        // Verifica se o formulário informou a ação "salvar".
        if ($acao === "salvar") {

            // Mantém o formulário aberto caso aconteça algum erro de validação.
            $modoEdicao = true;

            // Obtém o nome enviado e remove espaços desnecessários do início e fim.
            $nome = trim($_POST["nome"] ?? "");

            // Obtém o telefone enviado e remove espaços extras.
            $telefone = trim($_POST["telefone"] ?? "");

            // Obtém a especialidade selecionada.
            $especialidade = trim($_POST["especialidade"] ?? "");

            // Obtém a chave Pix digitada.
            $chave_pix_formulario = trim($_POST["chave_pix"] ?? "");

            // Obtém a descrição profissional.
            $descricao_formulario = trim($_POST["descricao"] ?? "");

            // Obtém as redes sociais informadas.
            $redes_sociais_formulario = trim($_POST["redes_sociais"] ?? "");

            // Define quais especialidades são aceitas pelo sistema.
            $especialidadesPermitidas = [
                "lash",
                "nail",
                "eyebrow"
            ];

            // Inicia um bloco para tratar erros de validação.
            try {

                // Verifica se nome ou especialidade estão vazios.
                if ($nome === "" || $especialidade === "") {

                    // Interrompe a validação e cria uma mensagem específica.
                    throw new Exception(
                        "Preencha o nome e selecione uma especialidade."
                    );
                }

                // Verifica se a especialidade enviada está na lista permitida.
                if (!in_array(
                    $especialidade,
                    $especialidadesPermitidas,
                    true
                )) {

                    // Impede o cadastro de uma especialidade desconhecida.
                    throw new Exception(
                        "Selecione uma especialidade válida."
                    );
                }

                // Verifica o limite de caracteres do nome e telefone.
                if (
                    contarCaracteres($nome) > 150 ||
                    contarCaracteres($telefone) > 20
                ) {

                    // Informa que algum dos campos ultrapassou o limite.
                    throw new Exception(
                        "Verifique o tamanho dos dados informados."
                    );
                }

                // Verifica se a chave Pix possui mais de 255 caracteres.
                if (contarCaracteres($chave_pix_formulario) > 255) {

                    // Interrompe a atualização se o limite for ultrapassado.
                    throw new Exception(
                        "A chave Pix deve ter até 255 caracteres."
                    );
                }

                // Verifica se a descrição possui mais de 2000 caracteres.
                if (contarCaracteres($descricao_formulario) > 2000) {

                    // Informa que a descrição ultrapassou o limite.
                    throw new Exception(
                        "A descrição deve ter até 2000 caracteres."
                    );
                }

                // Verifica se as redes sociais possuem mais de 500 caracteres.
                if (contarCaracteres($redes_sociais_formulario) > 500) {

                    // Informa que o limite foi ultrapassado.
                    throw new Exception(
                        "As redes sociais devem ter até 500 caracteres."
                    );
                }


                // Prepara o comando SQL responsável por atualizar os dados.
                $atualizacao = $conexao->prepare("
                    UPDATE usuarios
                    SET nome = :nome,
                        telefone = :telefone,
                        especialidade = :especialidade,
                        chave_pix = :chave_pix,
                        descricao = :descricao,
                        redes_sociais = :redes_sociais
                    WHERE id = :id
                      AND tipo_usuario = 'profissional'
                ");

                // Executa a atualização enviando os valores como parâmetros.
                $atualizacao->execute([
                    ":nome" => $nome,

                    ":telefone" => $telefone,

                    ":especialidade" => $especialidade,

                    // Se houver chave Pix, salva o valor.
                    // Caso esteja vazia, salva NULL no banco.
                    ":chave_pix" => $chave_pix_formulario !== ""
                        ? $chave_pix_formulario
                        : null,

                    // Se houver descrição, salva o texto.
                    // Caso esteja vazia, salva NULL.
                    ":descricao" => $descricao_formulario !== ""
                        ? $descricao_formulario
                        : null,

                    // Se houver redes sociais, salva o texto.
                    // Caso esteja vazio, salva NULL.
                    ":redes_sociais" => $redes_sociais_formulario !== ""
                        ? $redes_sociais_formulario
                        : null,

                    // Identifica qual profissional deve ser atualizada.
                    ":id" => $profissional_id
                ]);

                // Atualiza o nome armazenado na sessão.
                $_SESSION["usuario_nome"] = $nome;

                // Atualiza a especialidade armazenada na sessão.
                $_SESSION["especialidade"] = $especialidade;

                // Cria uma mensagem temporária de sucesso.
                $_SESSION["perfil_flash_sucesso"] =
                    "Informações atualizadas com sucesso!";

                // Redireciona para a página do perfil.
                // Isso evita que o formulário seja reenviado ao atualizar a página.
                header("Location: meu_perfil.php");

                // Interrompe a execução depois do redirecionamento.
                exit();

            // Captura erros gerados pelas validações acima.
            } catch (Exception $e) {

                // Registra o erro técnico no log.
                error_log(
                    "Erro ao atualizar perfil: " . $e->getMessage()
                );

                // Lista as mensagens de validação que podem ser mostradas diretamente.
                $mensagensPermitidas = [
                    "Preencha o nome e selecione uma especialidade.",
                    "Selecione uma especialidade válida.",
                    "Verifique o tamanho dos dados informados.",
                    "A chave Pix deve ter até 255 caracteres.",
                    "A descrição deve ter até 2000 caracteres.",
                    "As redes sociais devem ter até 500 caracteres."
                ];

                // Verifica se a mensagem do erro é uma das mensagens conhecidas.
                $mensagem_erro = in_array(
                    $e->getMessage(),
                    $mensagensPermitidas,
                    true
                )

                    // Se for conhecida, mostra a mensagem específica.
                    ? $e->getMessage()

                    // Caso contrário, mostra uma mensagem genérica.
                    : "Não foi possível salvar as alterações.";

                // Mantém os dados digitados no formulário
                // para que a profissional possa corrigir somente o campo necessário.
                $usuario["nome"] = $nome;

                // Mantém o telefone digitado.
                $usuario["telefone"] = $telefone;

                // Mantém a especialidade selecionada.
                $usuario["especialidade"] = $especialidade;

                // Mantém a chave Pix digitada.
                $usuario["chave_pix"] = $chave_pix_formulario;

                // Mantém a descrição digitada.
                $usuario["descricao"] = $descricao_formulario;

                // Mantém as redes sociais digitadas.
                $usuario["redes_sociais"] = $redes_sociais_formulario;
            }
        }


        // ----------------------------------------------------
        // 4.2. PUBLICAR AVISO
        // ----------------------------------------------------

        // Verifica se a ação enviada foi "publicar_aviso".
        elseif ($acao === "publicar_aviso") {

            // Obtém o título enviado pelo formulário.
            // O trim remove espaços desnecessários.
            $titulo_aviso_formulario = trim(
                $_POST["titulo"] ?? ""
            );

            // Obtém a mensagem do aviso.
            $mensagem_aviso_formulario = trim(
                $_POST["mensagem"] ?? ""
            );

            // Inicia o bloco responsável por validar e publicar o aviso.
            try {

                // Verifica se a mensagem está vazia.
                if ($mensagem_aviso_formulario === "") {

                    // O aviso não pode ser publicado sem uma mensagem.
                    throw new Exception(
                        "Escreva uma mensagem antes de publicar o aviso."
                    );
                }

                // Verifica o tamanho máximo do título.
                if (
                    contarCaracteres($titulo_aviso_formulario) > 150
                ) {

                    // Interrompe o processo se o título for muito grande.
                    throw new Exception(
                        "O título deve ter até 150 caracteres."
                    );
                }

                // Verifica o tamanho máximo da mensagem.
                if (
                    contarCaracteres($mensagem_aviso_formulario) > 5000
                ) {

                    // Interrompe o processo se a mensagem for muito grande.
                    throw new Exception(
                        "A mensagem deve ter até 5000 caracteres."
                    );
                }

                // Se o título estiver preenchido, guarda seu valor.
                // Se estiver vazio, utiliza NULL no banco.
                $tituloBanco = $titulo_aviso_formulario !== ""
                    ? $titulo_aviso_formulario
                    : null;

                // Prepara o comando para inserir um novo aviso.
                $inserirAviso = $conexao->prepare("
                    INSERT INTO avisos_profissionais
                        (profissional_id, titulo, mensagem, ativo)
                    VALUES
                        (:profissional_id, :titulo, :mensagem, 1)
                ");

                // Executa o INSERT com os valores recebidos.
                $inserirAviso->execute([
                    // Liga o aviso à profissional que está logada.
                    ":profissional_id" => $profissional_id,

                    // Envia o título, podendo ser NULL.
                    ":titulo" => $tituloBanco,

                    // Envia a mensagem do aviso.
                    ":mensagem" => $mensagem_aviso_formulario
                ]);

                // Guarda uma mensagem de sucesso na sessão.
                $_SESSION["perfil_flash_sucesso"] =
                    "Aviso publicado com sucesso!";

                // Volta para o mural de avisos depois da publicação.
                header("Location: meu_perfil.php#mural-avisos");

                // Interrompe a execução.
                exit();

            // Captura erros ocorridos durante a publicação.
            } catch (Exception $e) {

                // Registra o erro técnico no log do servidor.
                error_log(
                    "Erro ao publicar aviso: " . $e->getMessage()
                );

                // Define quais mensagens de validação podem ser exibidas.
                $mensagensPermitidas = [
                    "Escreva uma mensagem antes de publicar o aviso.",
                    "O título deve ter até 150 caracteres.",
                    "A mensagem deve ter até 5000 caracteres."
                ];

                // Se o erro for conhecido, mostra a mensagem específica.
                // Caso contrário, mostra uma mensagem genérica.
                $mensagem_erro = in_array(
                    $e->getMessage(),
                    $mensagensPermitidas,
                    true
                )
                    ? $e->getMessage()
                    : "Não foi possível publicar o aviso. Tente novamente.";
            }
        }


        // ----------------------------------------------------
        // 4.3. DESATIVAR AVISO
        // ----------------------------------------------------

        // Verifica se a ação recebida foi "desativar_aviso".
        elseif ($acao === "desativar_aviso") {

            // Tenta converter o ID recebido para um número inteiro.
            $aviso_id = filter_var(
                $_POST["aviso_id"] ?? null,
                FILTER_VALIDATE_INT
            );

            // Inicia o tratamento do processo de desativação.
            try {

                // Verifica se o ID é inválido ou menor que 1.
                if (!$aviso_id || $aviso_id < 1) {

                    // Interrompe o processo com uma mensagem de erro.
                    throw new Exception("Aviso inválido.");
                }

                // Prepara o comando para desativar o aviso.
                $desativar = $conexao->prepare("
                    UPDATE avisos_profissionais
                    SET ativo = 0
                    WHERE id = :id
                      AND profissional_id = :profissional_id
                      AND ativo = 1
                ");

                // Executa o UPDATE utilizando o ID do aviso
                // e o ID da profissional autenticada.
                $desativar->execute([
                    ":id" => $aviso_id,
                    ":profissional_id" => $profissional_id
                ]);

                // Verifica se alguma linha foi realmente alterada.
                if ($desativar->rowCount() > 0) {

                    // Informa que o aviso foi removido do mural.
                    $_SESSION["perfil_flash_sucesso"] =
                        "Aviso removido do mural das clientes.";

                } else {

                    // Informa que o aviso não existe ou já estava inativo.
                    $_SESSION["perfil_flash_erro"] =
                        "O aviso não foi encontrado ou já estava desativado.";
                }

                // Redireciona para o mural de avisos.
                header("Location: meu_perfil.php#mural-avisos");

                // Interrompe a execução.
                exit();

            // Captura erros da operação.
            } catch (Exception $e) {

                // Registra o erro técnico.
                error_log(
                    "Erro ao desativar aviso: " . $e->getMessage()
                );

                // Mostra uma mensagem específica para ID inválido
                // ou uma mensagem genérica para outros problemas.
                $mensagem_erro = $e->getMessage() === "Aviso inválido."
                    ? "Aviso inválido."
                    : "Não foi possível remover o aviso.";
            }
        }
    }
}


// ============================================================
// 5. CARREGAR AVISOS DA PROFISSIONAL
// ============================================================

// Só tenta carregar os avisos se não ocorreu erro na consulta principal.
if (!$erroConsulta) {

    // Inicia o tratamento de possíveis erros do banco.
    try {

        // Prepara a consulta que busca os avisos da profissional.
        $consultaAvisos = $conexao->prepare("
            SELECT id, titulo, mensagem, criado_em, ativo
            FROM avisos_profissionais
            WHERE profissional_id = :profissional_id
            ORDER BY criado_em DESC, id DESC
        ");

        // Executa a consulta utilizando o ID da profissional.
        $consultaAvisos->execute([
            ":profissional_id" => $profissional_id
        ]);

        // Busca todos os avisos como arrays associativos.
        $avisos_profissional = $consultaAvisos->fetchAll(
            PDO::FETCH_ASSOC
        );

    // Captura possíveis erros do banco.
    } catch (PDOException $e) {

        // Registra o erro técnico no log.
        error_log(
            "Erro ao carregar mural de avisos: " . $e->getMessage()
        );

        // Só substitui a mensagem se ainda não houver outra mensagem de erro.
        if ($mensagem_erro === "") {

            // Mostra uma mensagem amigável.
            $mensagem_erro =
                "Não foi possível carregar os avisos.";
        }
    }
}


// ============================================================
// 6. PREPARAR DADOS PARA EXIBIÇÃO
// ============================================================

// Cria um mapa entre os códigos internos do banco
// e os nomes que serão mostrados para a profissional.
$nomesEspecialidades = [

    // Código usado no banco => nome exibido na página.
    "lash" => "Cílios",

    // Código usado no banco => nome exibido na página.
    "nail" => "Unhas",

    // Código usado no banco => nome exibido na página.
    "eyebrow" => "Sobrancelhas"
];

// Obtém o nome da especialidade da profissional.
// Caso o código não exista no mapa, mostra "Não informada".
$especialidadeExibida = $nomesEspecialidades[
    $usuario["especialidade"] ?? ""
] ?? "Não informada";

?>
<!DOCTYPE html>
<!-- Informa ao navegador que o documento utiliza HTML5. -->

<html lang="pt-BR">
<!-- Define o idioma principal da página como português do Brasil. -->

<head>
    <!-- Início das configurações da página. -->

    <meta charset="UTF-8">
    <!-- Define UTF-8 para que acentos e caracteres especiais sejam exibidos corretamente. -->

    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- Faz a página se adaptar à largura da tela do dispositivo. -->

    <title>Meu Perfil | Estética</title>
    <!-- Define o título exibido na aba do navegador. -->

    <link rel="stylesheet" href="style.css">
    <!-- Importa o arquivo CSS externo utilizado pelo sistema. -->

</head>
<!-- Final das configurações do documento. -->


<body>
<!-- Início do conteúdo visual da página. -->


<!-- ============================================================
     7. MENU LATERAL
     ============================================================ -->

<aside class="sidebar">
    <!-- Cria a barra lateral de navegação da área profissional. -->

    <div class="logo">
        <!-- Agrupa o nome do sistema e a especialidade. -->

        <h1>Estética</h1>
        <!-- Exibe o nome principal do sistema. -->

        <p>
            <!-- Exibe a especialidade armazenada na sessão. -->

            <?= escapar(
                $_SESSION["especialidade"] ?? "Profissional"
            ) ?>

            <!--
            O operador ?? utiliza "Profissional" caso a especialidade
            não exista na sessão.

            A função escapar() protege o texto antes de colocá-lo no HTML.
            -->
        </p>
    </div>
    <!-- Final da área do logo. -->


    <nav class="menu">
        <!-- Início dos links principais da área profissional. -->

        <a href="painel_profissional.php">Início</a>
        <!-- Link para o painel inicial da profissional. -->

        <a href="atendimentos.php">Atendimentos</a>
        <!-- Link para a página de atendimentos. -->

        <a href="minha_agenda.php">Minha Agenda</a>
        <!-- Link para o gerenciamento da agenda. -->

        <a href="meus_servicos.php">Meus Serviços</a>
        <!-- Link para os serviços cadastrados pela profissional. -->

        <div class="separador"></div>
        <!-- Elemento visual utilizado para separar os grupos do menu. -->

        <a href="meu_perfil.php" class="ativo">Meu Perfil</a>
        <!-- Link para a página atual.
             A classe "ativo" permite destacar este item no CSS. -->

    </nav>
    <!-- Final do menu de navegação. -->


    <a href="logout.php" class="btn-sair">Sair</a>
    <!-- Link que leva ao arquivo responsável por encerrar a sessão. -->

</aside>
<!-- Final da barra lateral. -->


<!-- ============================================================
     8. CONTEÚDO PRINCIPAL
     ============================================================ -->

<main class="main">
    <!-- Define a área principal localizada ao lado da barra lateral. -->

    <div class="perfil-pagina">
        <!-- Container geral do conteúdo do perfil. -->


        <section class="perfil-cabecalho">
            <!-- Cabeçalho da página de perfil. -->

            <div>
                <!-- Agrupa título e descrição da página. -->

                <h2>Meu Perfil</h2>
                <!-- Título principal da página. -->

                <p>
                    Gerencie seus dados pessoais, informações públicas
                    e dados para pagamento.
                </p>
                <!-- Explica as funções disponíveis nesta página. -->

            </div>
            <!-- Final do bloco de título. -->


            <?php if (!$erroConsulta && !$modoEdicao): ?>
                <!--
                Mostra o botão de edição somente quando:
                1. não ocorreu erro na consulta;
                2. a página não está no modo de edição.
                -->

                <a href="meu_perfil.php?editar=1" class="perfil-botao">
                    Alterar informações pessoais
                </a>
                <!-- Link que ativa o modo de edição. -->

            <?php endif; ?>
            <!-- Final da condição do botão. -->

        </section>
        <!-- Final do cabeçalho. -->


        <!-- Mensagens de confirmação e erro. -->

        <?php if ($mensagem_sucesso !== ""): ?>
            <!-- Verifica se existe alguma mensagem de sucesso. -->

            <div class="alerta alerta-sucesso perfil-alerta">
                <!-- Cria a caixa visual para a mensagem de sucesso. -->

                <?= escapar($mensagem_sucesso) ?>
                <!-- Exibe a mensagem com proteção contra HTML/JavaScript. -->

            </div>
            <!-- Final da mensagem de sucesso. -->

        <?php endif; ?>
        <!-- Final da condição de sucesso. -->


        <?php if ($mensagem_erro !== ""): ?>
            <!-- Verifica se existe alguma mensagem de erro. -->

            <div class="alerta alerta-erro perfil-alerta">
                <!-- Cria a caixa visual da mensagem de erro. -->

                <?= escapar($mensagem_erro) ?>
                <!-- Exibe a mensagem de erro com segurança. -->

            </div>
            <!-- Final da mensagem de erro. -->

        <?php endif; ?>
        <!-- Final da condição de erro. -->


        <?php if (!$erroConsulta): ?>
            <!-- Só mostra o conteúdo do perfil se a consulta funcionou. -->


            <?php if (!$modoEdicao): ?>
                <!-- Se não estiver editando, mostra os dados normalmente. -->


                <!-- ============================================
                     VISUALIZAÇÃO DOS DADOS DO PERFIL
                     ============================================ -->

                <div class="perfil-layout">
                    <!-- Organiza os cartões do perfil. -->


                    <section class="perfil-cartao">
                        <!-- Cartão com informações pessoais. -->

                        <h3>Informações pessoais</h3>
                        <!-- Título do cartão. -->

                        <div class="perfil-dados">
                            <!-- Agrupa os dados individuais. -->

                            <div class="perfil-dado">
                                <!-- Campo do nome. -->

                                <small>Nome completo</small>
                                <!-- Identifica o campo. -->

                                <p><?= escapar($usuario["nome"] ?? "") ?></p>
                                <!-- Mostra o nome armazenado no banco. -->

                            </div>
                            <!-- Final do campo nome. -->


                            <div class="perfil-dado">
                                <!-- Campo do e-mail. -->

                                <small>E-mail cadastrado</small>
                                <!-- Identifica o campo. -->

                                <p><?= escapar($usuario["email"] ?? "") ?></p>
                                <!-- Mostra o e-mail cadastrado. -->

                            </div>
                            <!-- Final do campo e-mail. -->


                            <div class="perfil-dado">
                                <!-- Campo do telefone. -->

                                <small>Telefone cadastrado</small>
                                <!-- Identifica o telefone. -->

                                <p>
                                    <?= trim($usuario["telefone"] ?? "") !== ""
                                        ? escapar($usuario["telefone"])
                                        : "Não informado" ?>
                                </p>
                                <!--
                                Se houver telefone, ele é exibido.
                                Caso contrário, aparece "Não informado".
                                -->

                            </div>
                            <!-- Final do telefone. -->


                            <div class="perfil-dado">
                                <!-- Campo da especialidade. -->

                                <small>Especialidade</small>
                                <!-- Identifica a especialidade. -->

                                <p><?= escapar($especialidadeExibida) ?></p>
                                <!-- Mostra o nome traduzido da especialidade. -->

                            </div>
                            <!-- Final da especialidade. -->

                        </div>
                        <!-- Final dos dados pessoais. -->


                        <div class="perfil-acoes">
                            <!-- Agrupa os botões disponíveis. -->

                            <a href="meu_perfil.php?editar=1" class="perfil-botao">
                                Alterar informações pessoais
                            </a>
                            <!-- Abre o formulário de edição. -->

                        </div>
                        <!-- Final das ações. -->

                    </section>
                    <!-- Final do cartão de informações pessoais. -->


                    <section class="perfil-cartao">
                        <!-- Cartão das informações públicas. -->

                        <h3>Informações públicas do perfil</h3>
                        <!-- Título da seção. -->


                        <div class="perfil-campo-publico">
                            <!-- Área da descrição profissional. -->

                            <strong>Sobre mim e meu trabalho</strong>
                            <!-- Nome do campo público. -->


                            <?php if (trim($usuario["descricao"] ?? "") !== ""): ?>
                                <!-- Verifica se existe uma descrição cadastrada. -->

                                <p class="perfil-texto-publico">
                                    <?= escapar($usuario["descricao"]) ?>
                                </p>
                                <!-- Exibe a descrição cadastrada. -->

                            <?php else: ?>
                                <!-- Caso não exista descrição. -->

                                <p class="perfil-texto-publico">
                                    Você ainda não cadastrou uma descrição profissional.
                                </p>
                                <!-- Informa que nenhuma descrição foi cadastrada. -->

                            <?php endif; ?>
                            <!-- Final da condição da descrição. -->

                        </div>
                        <!-- Final da descrição pública. -->


                        <div class="perfil-campo-publico">
                            <!-- Área das redes sociais. -->

                            <strong>Redes sociais</strong>
                            <!-- Título do campo. -->


                            <?php if (trim($usuario["redes_sociais"] ?? "") !== ""): ?>
                                <!-- Verifica se existem redes sociais cadastradas. -->

                                <p class="perfil-redes-publicas">
                                    <?= escapar($usuario["redes_sociais"]) ?>
                                </p>
                                <!-- Exibe as redes sociais cadastradas. -->

                            <?php else: ?>
                                <!-- Caso não existam redes cadastradas. -->

                                <p class="perfil-texto-publico">
                                    Você ainda não cadastrou suas redes sociais.
                                </p>
                                <!-- Informa que não existem redes cadastradas. -->

                            <?php endif; ?>
                            <!-- Final da condição das redes sociais. -->

                        </div>
                        <!-- Final das redes sociais. -->


                        <a href="meu_perfil.php?editar=1" class="perfil-botao">
                            Editar informações públicas
                        </a>
                        <!-- Link para abrir o formulário de edição. -->

                    </section>
                    <!-- Final das informações públicas. -->


                    <section class="perfil-cartao">
                        <!-- Cartão das informações de pagamento. -->

                        <div class="perfil-pix">
                            <!-- Container específico das informações Pix. -->

                            <h3>Dados para pagamento</h3>
                            <!-- Título da área de pagamento. -->


                            <?php if (!empty($usuario["chave_pix"])): ?>
                                <!-- Verifica se existe uma chave Pix cadastrada. -->

                                <p><strong>Chave Pix cadastrada</strong></p>
                                <!-- Identifica a informação. -->

                                <p class="pix-valor">
                                    <?= escapar($usuario["chave_pix"]) ?>
                                </p>
                                <!-- Exibe a chave Pix com proteção HTML. -->

                                <small>
                                    Esta é a chave salva atualmente no seu perfil.
                                </small>
                                <!-- Explicação sobre a chave exibida. -->

                            <?php else: ?>
                                <!-- Executado quando não existe chave Pix. -->

                                <p>
                                    <strong>Nenhuma chave Pix cadastrada.</strong>
                                </p>
                                <!-- Informa que não existe chave. -->

                                <small>
                                    Para cadastrar uma chave, clique em
                                    Alterar informações pessoais.
                                </small>
                                <!-- Orienta como cadastrar uma chave. -->

                            <?php endif; ?>
                            <!-- Final da verificação da chave Pix. -->

                        </div>
                        <!-- Final do bloco Pix. -->

                    </section>
                    <!-- Final do cartão de pagamento. -->

                </div>
                <!-- Final do layout dos cartões. -->


            <?php else: ?>
                <!--
                Se $modoEdicao for verdadeiro, o formulário de edição será exibido.
                -->


                <!-- ============================================
                     FORMULÁRIO DE EDIÇÃO DO PERFIL
                     ============================================ -->

                <section class="perfil-cartao perfil-formulario">
                    <!-- Cartão que contém o formulário. -->

                    <h3>Alterar informações pessoais</h3>
                    <!-- Título do formulário. -->

                    <p style="margin-top:-10px; margin-bottom:25px;">
                        Edite os campos desejados e salve suas alterações.
                        Seu e-mail permanece inalterado.
                    </p>
                    <!-- Explicação sobre o funcionamento da edição. -->


                    <form method="POST" action="meu_perfil.php?editar=1">
                        <!--
                        Formulário enviado pelo método POST.
                        O action mantém a página no modo de edição.
                        -->


                        <!-- Token obrigatório para validar a solicitação. -->

                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= escapar($_SESSION["csrf_perfil"]) ?>"
                        >
                        <!--
                        Campo invisível que envia o token CSRF.
                        O servidor compara esse valor com o token armazenado na sessão.
                        -->


                        <input type="hidden" name="acao" value="salvar">
                        <!-- Identifica que este formulário deve executar a ação "salvar". -->


                        <div class="form-grid">
                            <!-- Container que organiza os campos do formulário. -->


                            <div class="form-grupo">
                                <!-- Grupo do nome. -->

                                <label for="nome">Nome completo *</label>
                                <!-- Texto associado ao campo de nome. -->

                                <input
                                    type="text"
                                    id="nome"
                                    name="nome"
                                    maxlength="150"
                                    required
                                    value="<?= escapar($usuario["nome"] ?? "") ?>"
                                >
                                <!--
                                Campo para alterar o nome.
                                maxlength limita a quantidade de caracteres.
                                required torna o preenchimento obrigatório.
                                value mostra o nome atual.
                                -->

                            </div>
                            <!-- Final do campo nome. -->


                            <div class="form-grupo">
                                <!-- Grupo do e-mail. -->

                                <label for="email">E-mail cadastrado</label>
                                <!-- Identificação do e-mail. -->

                                <input
                                    type="email"
                                    id="email"
                                    value="<?= escapar($usuario["email"] ?? "") ?>"
                                    readonly
                                    style="background:var(--cor-clara, #F8F0F1); opacity:0.85;"
                                >
                                <!--
                                Mostra o e-mail cadastrado.
                                readonly impede sua alteração pelo formulário.
                                -->

                                <small>
                                    O e-mail não pode ser alterado nesta versão.
                                </small>
                                <!-- Explica a limitação do sistema. -->

                            </div>
                            <!-- Final do campo e-mail. -->


                            <div class="form-grupo">
                                <!-- Grupo do telefone. -->

                                <label for="telefone">Telefone</label>
                                <!-- Identifica o campo telefone. -->

                                <input
                                    type="tel"
                                    id="telefone"
                                    name="telefone"
                                    maxlength="20"
                                    value="<?= escapar($usuario["telefone"] ?? "") ?>"
                                    placeholder="(00) 00000-0000"
                                >
                                <!-- Campo para alterar o telefone. -->

                            </div>
                            <!-- Final do telefone. -->


                            <div class="form-grupo">
                                <!-- Grupo da especialidade. -->

                                <label for="especialidade">Especialidade *</label>
                                <!-- Identifica o campo. -->

                                <select
                                    id="especialidade"
                                    name="especialidade"
                                    required
                                >
                                    <!-- Campo de seleção da especialidade. -->

                                    <option value="">Selecione...</option>
                                    <!-- Opção padrão sem valor. -->


                                    <option
                                        value="lash"
                                        <?= ($usuario["especialidade"] ?? "") === "lash"
                                            ? "selected"
                                            : "" ?>
                                    >
                                        Cílios
                                    </option>
                                    <!-- Opção para profissionais de cílios. -->


                                    <option
                                        value="nail"
                                        <?= ($usuario["especialidade"] ?? "") === "nail"
                                            ? "selected"
                                            : "" ?>
                                    >
                                        Unhas
                                    </option>
                                    <!-- Opção para profissionais de unhas. -->


                                    <option
                                        value="eyebrow"
                                        <?= ($usuario["especialidade"] ?? "") === "eyebrow"
                                            ? "selected"
                                            : "" ?>
                                    >
                                        Sobrancelhas
                                    </option>
                                    <!-- Opção para profissionais de sobrancelhas. -->

                                </select>
                                <!-- Final da seleção de especialidade. -->

                            </div>
                            <!-- Final do grupo de especialidade. -->


                            <!-- Dados para pagamento. -->

                            <div class="form-grupo linha-completa">
                                <!-- Campo ocupa a linha completa do formulário. -->

                                <hr class="perfil-separador">
                                <!-- Linha visual para separar as seções. -->

                                <h3 style="margin:18px 0 8px;">
                                    Dados para pagamento
                                </h3>
                                <!-- Título da seção Pix. -->


                                <label for="chave_pix">Chave Pix</label>
                                <!-- Identifica o campo da chave Pix. -->


                                <input
                                    type="text"
                                    id="chave_pix"
                                    name="chave_pix"
                                    maxlength="255"
                                    autocomplete="off"
                                    value="<?= escapar($chave_pix_formulario) ?>"
                                    placeholder="CPF, e-mail, telefone ou chave aleatória"
                                >
                                <!--
                                Campo para cadastrar ou alterar a chave Pix.
                                autocomplete="off" evita sugestões automáticas do navegador.
                                -->


                                <small>
                                    Para remover a chave cadastrada, deixe o campo
                                    vazio e salve as alterações.
                                </small>
                                <!-- Explica como remover uma chave existente. -->

                            </div>
                            <!-- Final da seção Pix. -->


                            <!-- Descrição pública da profissional. -->

                            <div class="form-grupo linha-completa">
                                <!-- Container da descrição pública. -->

                                <hr class="perfil-separador">
                                <!-- Separa visualmente esta seção da anterior. -->

                                <h3 style="margin:18px 0 8px;">
                                    Informações públicas do perfil
                                </h3>
                                <!-- Título das informações públicas. -->


                                <p>
                                    Apresente seu trabalho e ajude as clientes
                                    a conhecerem sua experiência e seus serviços.
                                </p>
                                <!-- Orientação sobre a descrição. -->


                                <label for="descricao">
                                    Sobre mim e meu trabalho
                                </label>
                                <!-- Identificação do campo de descrição. -->


                                <textarea
                                    id="descricao"
                                    name="descricao"
                                    maxlength="2000"
                                    rows="5"
                                    placeholder="Conte sobre sua experiência, seus serviços e sua forma de trabalhar..."
                                    style="width:100%; box-sizing:border-box; padding:12px; border:1px solid #DCC7CC; border-radius:9px; font:inherit; resize:vertical;"
                                ><?= escapar($descricao_formulario) ?></textarea>
                                <!--
                                Campo de texto para a descrição profissional.
                                maxlength limita a 2000 caracteres.
                                rows define uma altura inicial de 5 linhas.
                                -->


                                <small>
                                    Máximo de 2000 caracteres. Essa descrição
                                    poderá ser vista pelas clientes no seu perfil.
                                </small>
                                <!-- Explica o limite e a finalidade do campo. -->

                            </div>
                            <!-- Final da descrição. -->


                            <!-- Redes sociais públicas da profissional. -->

                            <div class="form-grupo linha-completa">
                                <!-- Container das redes sociais. -->

                                <label for="redes_sociais">
                                    Redes sociais
                                </label>
                                <!-- Identificação do campo. -->


                                <textarea
                                    id="redes_sociais"
                                    name="redes_sociais"
                                    maxlength="500"
                                    rows="3"
                                    placeholder="Ex.: Instagram: https://instagram.com/seuperfil"
                                    style="width:100%; box-sizing:border-box; padding:12px; border:1px solid #DCC7CC; border-radius:9px; font:inherit; resize:vertical;"
                                ><?= escapar($redes_sociais_formulario) ?></textarea>
                                <!--
                                Campo para informar redes sociais públicas.
                                O limite é de 500 caracteres.
                                -->


                                <small>
                                    Informe os links ou nomes dos seus perfis públicos.
                                    Máximo de 500 caracteres.
                                </small>
                                <!-- Orientação para preenchimento. -->

                            </div>
                            <!-- Final das redes sociais. -->

                        </div>
                        <!-- Final do grid do formulário. -->


                        <div class="perfil-acoes">
                            <!-- Área dos botões do formulário. -->

                            <button type="submit" class="perfil-botao">
                                Salvar alterações
                            </button>
                            <!-- Envia o formulário para o servidor. -->


                            <a
                                href="meu_perfil.php"
                                class="perfil-botao perfil-botao-secundario"
                            >
                                Cancelar
                            </a>
                            <!-- Volta para a visualização normal sem salvar alterações. -->

                        </div>
                        <!-- Final dos botões. -->

                    </form>
                    <!-- Final do formulário. -->

                </section>
                <!-- Final do cartão de edição. -->

            <?php endif; ?>
            <!-- Final da condição entre visualização e edição. -->


            <!-- ================================================
                 9. MURAL DE AVISOS DA PROFISSIONAL
                 ================================================ -->

            <section
                class="perfil-cartao"
                style="margin-top:28px;"
                id="mural-avisos"
            >
                <!--
                Seção responsável por criar e administrar os avisos.
                O id permite retornar diretamente para esta área.
                -->


                <h3>Meu mural de avisos</h3>
                <!-- Título do mural. -->


                <p>
                    Publique recados que aparecerão no painel das clientes
                    junto ao seu nome. O título é opcional.
                </p>
                <!-- Explica para que serve o mural. -->


                <!-- Formulário para publicar um novo aviso. -->

                <form
                    method="POST"
                    action="meu_perfil.php#mural-avisos"
                    class="perfil-mural-form"
                >
                    <!--
                    Formulário enviado pelo método POST.
                    Após o envio, a página retorna ao mural.
                    -->


                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= escapar($_SESSION["csrf_perfil"]) ?>"
                    >
                    <!-- Envia o token CSRF para proteger o formulário. -->


                    <input
                        type="hidden"
                        name="acao"
                        value="publicar_aviso"
                    >
                    <!-- Informa ao PHP que a ação é publicar um aviso. -->


                    <div>
                        <!-- Grupo do título. -->

                        <label for="titulo_aviso">Título (opcional)</label>
                        <!-- Identificação do campo título. -->

                        <input
                            type="text"
                            id="titulo_aviso"
                            name="titulo"
                            maxlength="150"
                            value="<?= escapar($titulo_aviso_formulario) ?>"
                            placeholder="Ex.: Aviso sobre horários"
                        >
                        <!-- Campo para o título do aviso. -->

                    </div>
                    <!-- Final do título. -->


                    <div>
                        <!-- Grupo da mensagem. -->

                        <label for="mensagem_aviso">Mensagem *</label>
                        <!-- Identifica o campo obrigatório. -->

                        <textarea
                            id="mensagem_aviso"
                            name="mensagem"
                            maxlength="5000"
                            required
                            placeholder="Escreva um recado para suas clientes..."
                        ><?= escapar($mensagem_aviso_formulario) ?></textarea>
                        <!--
                        Campo onde a profissional escreve o aviso.
                        A mensagem é obrigatória e possui limite de 5000 caracteres.
                        -->

                    </div>
                    <!-- Final da mensagem. -->


                    <div class="perfil-acoes">
                        <!-- Área do botão de publicação. -->

                        <button type="submit" class="perfil-botao">
                            Publicar aviso
                        </button>
                        <!-- Envia o aviso para o servidor. -->

                    </div>
                    <!-- Final das ações. -->

                </form>
                <!-- Final do formulário de aviso. -->


                <hr class="perfil-separador">
                <!-- Divide o formulário da lista de avisos. -->


                <h3>Avisos publicados</h3>
                <!-- Título da lista de avisos. -->


                <?php if (empty($avisos_profissional)): ?>
                    <!-- Verifica se não existe nenhum aviso. -->

                    <p>Você ainda não publicou avisos para suas clientes.</p>
                    <!-- Mensagem exibida quando a lista está vazia. -->

                <?php else: ?>
                    <!-- Caso existam avisos cadastrados. -->


                    <div class="perfil-lista-avisos">
                        <!-- Container que reúne todos os avisos. -->


                        <?php foreach ($avisos_profissional as $aviso): ?>
                            <!--
                            Percorre cada aviso retornado pelo banco de dados.
                            Cada aviso fica disponível na variável $aviso.
                            -->


                            <article class="perfil-aviso">
                                <!-- Representa visualmente um aviso individual. -->


                                <!-- O título só aparece quando foi preenchido. -->

                                <?php if (trim($aviso["titulo"] ?? "") !== ""): ?>
                                    <!-- Verifica se existe um título. -->

                                    <h4><?= escapar($aviso["titulo"]) ?></h4>
                                    <!-- Exibe o título com proteção HTML. -->

                                <?php endif; ?>
                                <!-- Final da condição do título. -->


                                <p class="perfil-aviso-texto">
                                    <?= escapar($aviso["mensagem"]) ?>
                                </p>
                                <!-- Exibe a mensagem do aviso. -->


                                <div class="perfil-aviso-rodape">
                                    <!-- Área inferior do aviso. -->


                                    <span>
                                        <!-- Exibe a data de publicação. -->

                                        <?php
                                        // Converte a data armazenada no banco
                                        // para um timestamp que o PHP consegue formatar.
                                        $dataAviso = strtotime(
                                            $aviso["criado_em"] ?? ""
                                        );
                                        ?>

                                        Publicado em
                                        <!-- Texto que antecede a data. -->


                                        <?= $dataAviso
                                            ? date("d/m/Y H:i", $dataAviso)
                                            : "data não disponível" ?>
                                        <!--
                                        Se a data for válida, mostra:
                                        dia/mês/ano hora:minuto.

                                        Caso contrário, mostra "data não disponível".
                                        -->

                                    </span>
                                    <!-- Final da data. -->


                                    <span class="perfil-aviso-status <?= (int) $aviso["ativo"] === 1
                                        ? ""
                                        : "inativo" ?>">
                                        <!--
                                        Define a classe visual do status.
                                        Se ativo for 1, não adiciona "inativo".
                                        Se não for 1, adiciona a classe "inativo".
                                        -->


                                        <?= (int) $aviso["ativo"] === 1
                                            ? "Visível para clientes"
                                            : "Inativo" ?>
                                        <!--
                                        Mostra "Visível para clientes" quando ativo.
                                        Caso contrário, mostra "Inativo".
                                        -->

                                    </span>
                                    <!-- Final do status. -->


                                    <!-- Só permite desativar avisos ainda ativos. -->

                                    <?php if ((int) $aviso["ativo"] === 1): ?>
                                        <!--
                                        O botão só aparece se o aviso estiver ativo.
                                        -->


                                        <form
                                            method="POST"
                                            action="meu_perfil.php#mural-avisos"
                                            onsubmit="return confirm('Deseja remover este aviso do mural das clientes?');"
                                        >
                                            <!--
                                            Formulário responsável por desativar o aviso.

                                            O método POST envia os dados sem colocá-los na URL.

                                            O onsubmit abre uma confirmação antes de continuar.
                                            -->


                                            <input
                                                type="hidden"
                                                name="csrf_token"
                                                value="<?= escapar($_SESSION["csrf_perfil"]) ?>"
                                            >
                                            <!-- Envia o token de proteção CSRF. -->


                                            <input
                                                type="hidden"
                                                name="acao"
                                                value="desativar_aviso"
                                            >
                                            <!-- Informa ao PHP que a ação é desativar o aviso. -->


                                            <input
                                                type="hidden"
                                                name="aviso_id"
                                                value="<?= (int) $aviso["id"] ?>"
                                            >
                                            <!-- Envia o ID do aviso que será desativado. -->


                                            <button
                                                type="submit"
                                                class="perfil-aviso-remover"
                                            >
                                                Remover do mural
                                            </button>
                                            <!-- Botão responsável por enviar a solicitação. -->

                                        </form>
                                        <!-- Final do formulário de remoção. -->

                                    <?php endif; ?>
                                    <!-- Final da condição que verifica se o aviso está ativo. -->


                                </div>
                                <!-- Final do rodapé do aviso. -->

                            </article>
                            <!-- Final do aviso individual. -->

                        <?php endforeach; ?>
                        <!-- Final do loop que percorre os avisos. -->


                    </div>
                    <!-- Final da lista de avisos. -->

                <?php endif; ?>
                <!-- Final da verificação da existência de avisos. -->

            </section>
            <!-- Final do mural de avisos. -->


        <?php endif; ?>
        <!-- Final da condição que verifica se não houve erro na consulta. -->


    </div>
    <!-- Final do container principal do perfil. -->

</main>
<!-- Final da área principal da página. -->


</body>
<!-- Final do conteúdo visual da página. -->

</html>
<!-- Final do documento HTML. -->