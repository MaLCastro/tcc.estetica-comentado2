<?php

// ============================================================
// POPULAR O BANCO DE DADOS - TCC ESTÉTICA
// ============================================================

// Define o fuso horário utilizado pelo sistema.
// Dessa forma, as datas geradas pelo PHP seguem o horário
// de Brasília, evitando diferenças de horário no banco.
date_default_timezone_set("America/Sao_Paulo");

// Carrega o arquivo responsável pela conexão PDO
// com o banco de dados.
require_once("conexao.php");


// ============================================================
// INICIAR TRANSAÇÃO
// ============================================================

// O try permite capturar possíveis erros durante
// a execução das operações no banco.
try {

    // Inicia uma transação no banco de dados.
    //
    // A transação permite que todas as alterações sejam
    // confirmadas somente se todo o processo terminar corretamente.
    //
    // Caso aconteça algum erro, podemos utilizar rollback()
    // para desfazer as alterações realizadas.
    $conexao->beginTransaction();


    // ========================================================
    // 1. CONFIGURAÇÃO DAS PROFISSIONAIS
    // ========================================================

    // Define as profissionais que serão utilizadas
    // na geração dos dados de teste.
    //
    // A chave do array representa o ID da profissional
    // existente na tabela "usuarios".
    //
    // O valor representa o nome utilizado apenas
    // para identificação durante a execução.
    $profissionais = [
        1 => "Maria Eduarda Louredo De Castro",
        5 => "Débora Lins",
        6 => "Rayssa Santos"
    ];


    // ========================================================
    // 2. CADASTRO DAS CLIENTES FICTÍCIAS
    // ========================================================

    // Lista de nomes fictícios utilizados para criar
    // clientes de teste no sistema.
    //
    // Esses dados servem para permitir que o sistema
    // seja testado com vários agendamentos e clientes.
    $nomesClientes = [
        "Ana Clara Martins",
        "Beatriz Oliveira",
        "Camila Rodrigues",
        "Carolina Ferreira",
        "Daniela Santos",
        "Eduarda Almeida",
        "Fernanda Costa",
        "Gabriela Souza",
        "Helena Ribeiro",
        "Isabela Lima",
        "Juliana Carvalho",
        "Larissa Mendes",
        "Letícia Barbosa",
        "Luana Pereira",
        "Mariana Gomes",
        "Natália Fernandes",
        "Nicole Moreira",
        "Patrícia Rocha",
        "Rafaela Dias",
        "Rebeca Cardoso",
        "Sabrina Nunes",
        "Vitória Teixeira",
        "Yasmin Lopes",
        "Amanda Monteiro"
    ];

    // Array que armazenará os IDs das clientes.
    //
    // Esses IDs serão utilizados posteriormente
    // para criar os agendamentos.
    $clientes = [];


    // --------------------------------------------------------
    // PREPARAR CONSULTA PARA VERIFICAR CLIENTES
    // --------------------------------------------------------

    // Prepara uma consulta que verifica se determinado
    // e-mail já está cadastrado na tabela "usuarios".
    //
    // O LIMIT 1 informa ao banco que precisamos apenas
    // do primeiro registro encontrado.
    $consultaCliente = $conexao->prepare("
        SELECT id, tipo_usuario
        FROM usuarios
        WHERE email = :email
        LIMIT 1
    ");


    // --------------------------------------------------------
    // PREPARAR CONSULTA PARA INSERIR CLIENTES
    // --------------------------------------------------------

    // Prepara a consulta responsável por inserir
    // uma nova cliente na tabela "usuarios".
    //
    // O tipo_usuario e a especialidade são definidos
    // diretamente como "cliente".
    $inserirCliente = $conexao->prepare("
        INSERT INTO usuarios (
            nome,
            email,
            senha,
            telefone,
            tipo_usuario,
            especialidade,
            status
        ) VALUES (
            :nome,
            :email,
            :senha,
            :telefone,
            'cliente',
            'cliente',
            'ativo'
        )
    ");


    // --------------------------------------------------------
    // CRIAR SENHA PARA AS CLIENTES DE TESTE
    // --------------------------------------------------------

    // Cria uma senha padrão para as contas fictícias.
    //
    // password_hash() transforma a senha original
    // em um hash seguro antes de armazená-la no banco.
    //
    // A senha utilizada para teste é:
    // Teste@123
    $senhaHash = password_hash(
        "Teste@123",
        PASSWORD_DEFAULT
    );


    // --------------------------------------------------------
    // PERCORRER A LISTA DE CLIENTES
    // --------------------------------------------------------

    // Percorre cada nome existente no array de clientes.
    //
    // $indice contém a posição do nome no array.
    // $nome contém o nome da cliente.
    foreach ($nomesClientes as $indice => $nome) {


        // Cria um número começando em 1 para facilitar
        // a criação dos e-mails fictícios.
        //
        // Como os arrays começam normalmente em 0,
        // somamos 1 ao índice.
        $numero = $indice + 1;


        // Cria um e-mail exclusivo para cada cliente.
        //
        // Exemplo:
        // cliente.teste1@exemplo.com
        // cliente.teste2@exemplo.com
        // cliente.teste3@exemplo.com
        $email = "cliente.teste" . $numero . "@exemplo.com";


        // Verifica se o e-mail já existe no banco.
        $consultaCliente->execute([
            ":email" => $email
        ]);


        // Recupera o cliente encontrado, caso exista.
        //
        // Se nenhum registro for encontrado,
        // o resultado será false.
        $clienteExistente = $consultaCliente->fetch(PDO::FETCH_ASSOC);


        // Verifica se o e-mail já estava cadastrado.
        if ($clienteExistente) {


            // Confere se o e-mail existente pertence realmente
            // a uma conta de cliente.
            //
            // Isso impede que o script reutilize um e-mail
            // que pertença a uma profissional.
            if ($clienteExistente["tipo_usuario"] !== "cliente") {

                // Interrompe o processo informando o conflito.
                throw new Exception(
                    "O e-mail " . $email . " pertence a outro tipo de usuário."
                );
            }


            // Caso a cliente já exista, reutiliza o ID dela.
            //
            // Isso evita criar registros duplicados.
            $clienteId = (int)$clienteExistente["id"];


        } else {


            // ------------------------------------------------
            // GERAR TELEFONE FICTÍCIO
            // ------------------------------------------------

            // Cria um telefone de teste utilizando o número
            // da cliente como parte do valor.
            //
            // str_pad() completa o número com zeros à esquerda
            // até atingir sete caracteres.
            $telefone = "1999" . str_pad(
                (string)$numero,
                7,
                "0",
                STR_PAD_LEFT
            );


            // ------------------------------------------------
            // INSERIR NOVA CLIENTE
            // ------------------------------------------------

            // Executa a inserção da nova cliente.
            $inserirCliente->execute([
                ":nome" => $nome,
                ":email" => $email,
                ":senha" => $senhaHash,
                ":telefone" => $telefone
            ]);


            // Recupera o ID gerado automaticamente
            // pelo banco para a nova cliente.
            $clienteId = (int)$conexao->lastInsertId();
        }


        // Adiciona o ID da cliente ao array.
        //
        // Esse array será utilizado posteriormente
        // na criação dos agendamentos.
        $clientes[] = $clienteId;
    }


    // ========================================================
    // 3. BUSCAR OS SERVIÇOS VINCULADOS ÀS PROFISSIONAIS
    // ========================================================

    // Consulta que busca os serviços oferecidos por cada profissional.
    //
    // A tabela "vinculacao" relaciona o serviço à profissional.
    //
    // Também são recuperados os valores personalizados,
    // caso existam.
    $sqlServicos = "

        SELECT

            // ID do serviço.
            s.id,

            // Nome do serviço.
            s.nome_servico,

            // Tipo do serviço.
            s.tipo_servico,

            // Preço padrão cadastrado no serviço.
            s.preco,

            // Duração padrão do serviço.
            s.duracao,

            // Preço personalizado definido na vinculação.
            v.preco_customizado,

            // Duração personalizada definida na vinculação.
            v.duracao_customizada

        FROM vinculacao v

        // Relaciona a tabela de vinculação
        // com a tabela de serviços.
        INNER JOIN servicos s

            // Liga o serviço da vinculação
            // ao ID do serviço.
            ON s.id = v.servico_id

        WHERE v.profissional_id = :profissional_id

        // Garante que o serviço também pertença
        // à profissional selecionada.
        AND s.profissional_id = :profissional_id

        // Somente serviços ativos serão utilizados.
        AND s.status = 'ativo'

        // Ordena os serviços pelo ID.
        ORDER BY s.id
    ";


    // Prepara a consulta dos serviços.
    $buscarServicos = $conexao->prepare($sqlServicos);


    // Cria um array onde cada profissional terá
    // sua própria lista de serviços.
    //
    // Estrutura esperada:
    //
    // $servicosPorProfissional[1] = serviços da profissional 1
    // $servicosPorProfissional[5] = serviços da profissional 5
    // $servicosPorProfissional[6] = serviços da profissional 6
    $servicosPorProfissional = [];


    // Percorre todas as profissionais configuradas
    // no início do arquivo.
    foreach ($profissionais as $profissionalId => $nomeProfissional) {


        // Executa a consulta enviando o ID da profissional.
        $buscarServicos->execute([
            ":profissional_id" => $profissionalId
        ]);


        // Recupera todos os serviços encontrados
        // para aquela profissional.
        $servicos = $buscarServicos->fetchAll(PDO::FETCH_ASSOC);


        // Verifica se a profissional possui
        // pelo menos um serviço ativo.
        if (empty($servicos)) {

            // Interrompe a execução caso não exista serviço.
            throw new Exception(
                "Nenhum serviço ativo encontrado para " . $nomeProfissional
            );
        }


        // Guarda os serviços encontrados dentro do array
        // utilizando o ID da profissional como chave.
        $servicosPorProfissional[$profissionalId] = $servicos;
    }


    // ========================================================
    // 4. VERIFICAR SE OS AGENDAMENTOS JÁ FORAM CRIADOS
    // ========================================================

    // Inicializa o contador de agendamentos encontrados.
    //
    // Esse controle impede que o script gere os mesmos
    // agendamentos novamente em uma segunda execução.
    $totalAgendamentosTeste = 0;


    // Prepara uma consulta que conta quantos agendamentos
    // pertencem a determinada cliente.
    $verificarAgendamentos = $conexao->prepare("
        SELECT COUNT(*)
        FROM agendamentos
        WHERE cliente_id = :cliente_id
    ");


    // Percorre todas as clientes de teste.
    foreach ($clientes as $clienteId) {


        // Conta os agendamentos existentes para a cliente atual.
        $verificarAgendamentos->execute([
            ":cliente_id" => $clienteId
        ]);


        // Soma a quantidade encontrada ao total geral.
        $totalAgendamentosTeste += (int)$verificarAgendamentos->fetchColumn();
    }


    // ========================================================
    // 5. INSERIR OS AGENDAMENTOS
    // ========================================================

    // Inicializa o contador de novos agendamentos.
    $quantidadeAgendamentos = 0;


    // Só cria os dados se nenhuma das clientes
    // possuir agendamentos.
    //
    // Isso evita duplicações quando o arquivo
    // for executado novamente.
    if ($totalAgendamentosTeste === 0) {


        // ----------------------------------------------------
        // HORÁRIOS DE TESTE
        // ----------------------------------------------------

        // Lista de horários utilizados para distribuir
        // os agendamentos fictícios.
        $horariosTeste = [
            "09:00:00",
            "11:30:00",
            "14:00:00",
            "16:00:00"
        ];


        // ----------------------------------------------------
        // PREPARAR INSERÇÃO DOS AGENDAMENTOS
        // ----------------------------------------------------

        // Prepara a consulta responsável por inserir
        // os agendamentos na tabela.
        //
        // Os parâmetros são utilizados para evitar
        // inserir valores diretamente no SQL.
        $inserirAgendamento = $conexao->prepare("
            INSERT INTO agendamentos (
                cliente_id,
                profissional_id,
                servico_id,
                data_agendamento,
                ultimo_servico,
                horario,
                nail_art_inclusa,
                preco_final,
                sinal_pago,
                status,
                agendamento_para_outro,
                nome_pessoa,
                valor_sinal,
                cancelado_por,
                cancelamento_visualizado
            ) VALUES (
                :cliente_id,
                :profissional_id,
                :servico_id,
                :data_agendamento,
                NULL,
                :horario,
                :nail_art_inclusa,
                :preco_final,
                :sinal_pago,
                :status,
                0,
                NULL,
                :valor_sinal,
                :cancelado_por,
                0
            )
        ");


        // ----------------------------------------------------
        // DEFINIR DATA DE REFERÊNCIA
        // ----------------------------------------------------

        // Cria um objeto DateTime representando o dia atual.
        //
        // A palavra "today" faz com que o horário seja
        // considerado como o início do dia.
        $dataBase = new DateTime("today");


        // Índice utilizado para diferenciar as profissionais
        // durante a distribuição dos dados.
        $indiceProfissional = 0;


        // Percorre as profissionais cadastradas
        // no array de configuração.
        foreach ($profissionais as $profissionalId => $nomeProfissional) {


            // Recupera os serviços disponíveis para
            // a profissional atual.
            $listaServicos =
                $servicosPorProfissional[$profissionalId];


            // Cada profissional receberá 24 agendamentos.
            //
            // Índices de 0 a 23 representam os 24 registros.
            for ($i = 0; $i < 24; $i++) {


                // ------------------------------------------------
                // DEFINIR SE O AGENDAMENTO É PASSADO OU FUTURO
                // ------------------------------------------------

                // Os primeiros 12 registros são considerados
                // atendimentos passados.
                //
                // Os 12 restantes são considerados futuros.
                $passado = ($i < 12);


                // Cria um índice de data.
                //
                // Para registros passados:
                // 0 até 11
                //
                // Para registros futuros:
                // 0 até 11 novamente.
                $indiceData = $passado ? $i : $i - 12;


                // ------------------------------------------------
                // CALCULAR DISTÂNCIA ENTRE AS DATAS
                // ------------------------------------------------

                // Cada atendimento recebe uma distância
                // de quatro dias em relação ao anterior.
                //
                // O valor 2 garante que o primeiro registro
                // fique pelo menos dois dias distante da data atual.
                $deslocamento = 2 + ($indiceData * 4);


                // Cria uma cópia da data base.
                //
                // clone é utilizado para que a data original
                // continue preservada para os próximos registros.
                $data = clone $dataBase;


                // ------------------------------------------------
                // DATAS DOS ATENDIMENTOS PASSADOS
                // ------------------------------------------------

                if ($passado) {


                    // Volta a quantidade de dias calculada.
                    $data->modify("-" . $deslocamento . " days");


                    // Verifica se a data caiu em um domingo.
                    //
                    // format("w") retorna:
                    //
                    // 0 = domingo
                    // 1 = segunda
                    // ...
                    // 6 = sábado
                    while ((int)$data->format("w") === 0) {

                        // Caso seja domingo, volta mais um dia
                        // até encontrar um dia diferente de domingo.
                        $data->modify("-1 day");
                    }


                } else {


                    // ------------------------------------------------
                    // DATAS DOS ATENDIMENTOS FUTUROS
                    // ------------------------------------------------

                    // Avança a data conforme o deslocamento calculado.
                    $data->modify("+" . $deslocamento . " days");


                    // Evita que o atendimento seja criado em domingo.
                    while ((int)$data->format("w") === 0) {

                        // Avança um dia até sair do domingo.
                        $data->modify("+1 day");
                    }
                }


                // Converte o objeto DateTime para o formato
                // aceito pelo banco de dados.
                //
                // Formato:
                // Ano-Mês-Dia
                //
                // Exemplo:
                // 2026-11-18
                $dataAgendamento = $data->format("Y-m-d");


                // ------------------------------------------------
                // DEFINIR HORÁRIO
                // ------------------------------------------------

                // Seleciona um dos horários de teste.
                //
                // O operador % faz o índice voltar para o início
                // quando chegar ao final do array.
                //
                // Dessa forma, os horários ficam alternados.
                $horario = $horariosTeste[$indiceData % 4];


                // ------------------------------------------------
                // DEFINIR SERVIÇO
                // ------------------------------------------------

                // Seleciona um dos serviços vinculados
                // à profissional atual.
                //
                // O operador % também permite repetir a lista
                // quando todos os serviços já tiverem sido utilizados.
                $servico =
                    $listaServicos[
                        $indiceData % count($listaServicos)
                    ];


                // ------------------------------------------------
                // DEFINIR PREÇO
                // ------------------------------------------------

                // Primeiro utiliza o preço personalizado
                // definido na tabela de vinculação.
                $preco = (float)$servico["preco_customizado"];


                // Se não houver preço personalizado válido,
                // utiliza o preço padrão do serviço.
                if ($preco <= 0) {
                    $preco = (float)$servico["preco"];
                }


                // Confere se o preço encontrado é válido.
                if ($preco <= 0) {

                    // Interrompe o processo caso o serviço
                    // não tenha um preço válido.
                    throw new Exception(
                        "Preço inválido no serviço " .
                        $servico["nome_servico"]
                    );
                }


                // ------------------------------------------------
                // CALCULAR SINAL
                // ------------------------------------------------

                // O sistema utiliza um sinal correspondente
                // a 30% do valor total do serviço.
                //
                // round() limita o resultado para duas casas decimais.
                $valorSinal = round($preco * 0.30, 2);


                // ------------------------------------------------
                // DEFINIR STATUS DO AGENDAMENTO
                // ------------------------------------------------

                // Verifica se o atendimento é passado.
                if ($passado) {


                    // Define quatro dos doze atendimentos passados
                    // como cancelados.
                    //
                    // Os índices escolhidos são:
                    // 2, 5, 8 e 11.
                    $cancelado = in_array(
                        $indiceData,
                        [2, 5, 8, 11],
                        true
                    );


                    // Se estiver na lista de cancelados,
                    // recebe "cancelado".
                    //
                    // Caso contrário, recebe "concluido".
                    $status = $cancelado
                        ? "cancelado"
                        : "concluido";


                } else {


                    // Para os atendimentos futuros,
                    // alterna entre "pendente" e "confirmado".
                    //
                    // Índices pares:
                    // pendente
                    //
                    // Índices ímpares:
                    // confirmado
                    $status = ($indiceData % 2 === 0)
                        ? "pendente"
                        : "confirmado";
                }


                // ------------------------------------------------
                // DEFINIR PAGAMENTO DO SINAL
                // ------------------------------------------------

                // Considera que o sinal foi pago
                // para agendamentos confirmados ou concluídos.
                //
                // Para os demais status, o sinal permanece
                // como não pago.
                $sinalPago = in_array(
                    $status,
                    ["confirmado", "concluido"],
                    true
                ) ? 1 : 0;


                // ------------------------------------------------
                // IDENTIFICAR NAIL ART
                // ------------------------------------------------

                // Verifica se o nome do serviço contém
                // o texto "nail art".
                //
                // stripos() faz a busca sem diferenciar
                // letras maiúsculas e minúsculas.
                $nailArt = (
                    stripos(
                        $servico["nome_servico"],
                        "nail art"
                    ) !== false
                ) ? "sim" : "não";


                // ------------------------------------------------
                // DEFINIR RESPONSÁVEL PELO CANCELAMENTO
                // ------------------------------------------------

                // Inicialmente nenhum responsável é definido.
                $canceladoPor = null;


                // Só existe responsável caso o status
                // seja realmente "cancelado".
                if ($status === "cancelado") {


                    // Alterna entre cancelamento feito pela cliente
                    // e cancelamento feito pela profissional.
                    //
                    // Índices pares:
                    // cliente
                    //
                    // Índices ímpares:
                    // profissional
                    $canceladoPor = ($indiceData % 2 === 0)
                        ? "cliente"
                        : "profissional";
                }


                // ------------------------------------------------
                // DISTRIBUIR CLIENTES
                // ------------------------------------------------

                // Calcula qual cliente será responsável
                // pelo agendamento atual.
                //
                // A fórmula utiliza:
                // - índice da data;
                // - índice da profissional;
                // - diferença entre passado e futuro.
                //
                // O operador % garante que o resultado
                // permaneça dentro dos índices existentes
                // no array $clientes.
                $indiceCliente = (
                    $indiceData * 2 +
                    $indiceProfissional * 8 +
                    ($passado ? 0 : 12)
                ) % count($clientes);


                // Recupera o ID da cliente calculada.
                $clienteId = $clientes[$indiceCliente];


                // ------------------------------------------------
                // INSERIR AGENDAMENTO
                // ------------------------------------------------

                // Executa a inserção do agendamento utilizando
                // os valores calculados anteriormente.
                $inserirAgendamento->execute([

                    // ID da cliente.
                    ":cliente_id" => $clienteId,

                    // ID da profissional.
                    ":profissional_id" => $profissionalId,

                    // ID do serviço escolhido.
                    ":servico_id" => $servico["id"],

                    // Data do atendimento.
                    ":data_agendamento" => $dataAgendamento,

                    // Horário do atendimento.
                    ":horario" => $horario,

                    // Indica se possui Nail Art.
                    ":nail_art_inclusa" => $nailArt,

                    // Valor final do serviço.
                    ":preco_final" => $preco,

                    // Indica se o sinal já foi pago.
                    ":sinal_pago" => $sinalPago,

                    // Status do agendamento.
                    ":status" => $status,

                    // Valor correspondente aos 30% de sinal.
                    ":valor_sinal" => $valorSinal,

                    // Responsável pelo cancelamento, caso exista.
                    ":cancelado_por" => $canceladoPor
                ]);


                // Incrementa o contador de agendamentos
                // criados nesta execução.
                $quantidadeAgendamentos++;
            }


            // Incrementa o índice da profissional.
            //
            // Esse valor participa da distribuição
            // das clientes entre as profissionais.
            $indiceProfissional++;
        }


    } else {


        // ----------------------------------------------------
        // AGENDAMENTOS JÁ EXISTENTES
        // ----------------------------------------------------

        // Caso já existam agendamentos para as clientes de teste,
        // nenhuma nova inserção será realizada.
        //
        // Isso evita que a execução do arquivo gere duplicações.
        $mensagemAgendamentos =
            "Os agendamentos de teste já existem. Nenhum novo registro foi inserido.";
    }


    // ========================================================
    // 6. CONFIRMAR A TRANSAÇÃO
    // ========================================================

    // Confirma definitivamente todas as alterações realizadas
    // durante a transação.
    //
    // Sem o commit(), as operações poderiam não ser
    // persistidas no banco dependendo da configuração.
    $conexao->commit();


    // ========================================================
    // 7. EXIBIR O RESULTADO DA EXECUÇÃO
    // ========================================================

    // Exibe um título informando que o processo terminou.
    echo "<h2>População do banco concluída!</h2>";


    // Exibe a quantidade de clientes fictícias
    // criadas ou reutilizadas.
    echo "<p>Clientes fictícias cadastradas ou reutilizadas: "
        . count($clientes) . "</p>";


    // Informa a quantidade de profissionais utilizadas
    // na geração dos dados.
    echo "<p>Profissionais preservadas: 3</p>";


    // Verifica se novos agendamentos foram realmente criados.
    if ($totalAgendamentosTeste === 0) {


        // Exibe a quantidade de agendamentos inseridos.
        echo "<p>Novos agendamentos inseridos: "
            . $quantidadeAgendamentos . "</p>";


        // Exibe a quantidade esperada de atendimentos passados.
        //
        // São 12 atendimentos passados para cada uma
        // das 3 profissionais.
        echo "<p>Atendimentos passados: 36</p>";


        // Exibe a quantidade esperada de atendimentos futuros.
        //
        // São 12 atendimentos futuros para cada uma
        // das 3 profissionais.
        echo "<p>Atendimentos futuros: 36</p>";


    } else {


        // Caso os dados já existissem, apresenta a mensagem
        // armazenada anteriormente.
        //
        // htmlspecialchars() protege o texto antes de
        // inseri-lo no HTML.
        echo "<p>" .
            htmlspecialchars(
                $mensagemAgendamentos,
                ENT_QUOTES,
                "UTF-8"
            ) .
            "</p>";
    }


    // Exibe a senha padrão utilizada pelas contas fictícias.
    echo "<p>Senha padrão das clientes de teste: Teste@123</p>";


} catch (Exception $erro) {


    // ========================================================
    // 8. TRATAMENTO DE ERRO
    // ========================================================

    // Verifica se existe uma transação aberta.
    if ($conexao->inTransaction()) {

        // Desfaz todas as alterações realizadas
        // desde o início da transação.
        //
        // Isso evita que o banco fique parcialmente
        // preenchido caso alguma etapa apresente erro.
        $conexao->rollBack();
    }


    // Exibe uma mensagem informando que ocorreu um erro.
    echo "<h2>Erro ao popular o banco de dados</h2>";


    // Exibe a mensagem específica do erro.
    //
    // htmlspecialchars() impede que uma mensagem
    // contendo caracteres especiais seja interpretada
    // como código HTML.
    echo "<p>" .
        htmlspecialchars(
            $erro->getMessage(),
            ENT_QUOTES,
            "UTF-8"
        ) .
        "</p>";
}