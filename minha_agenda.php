<?php
// =========================================================
// INICIALIZAÇÃO E SEGURANÇA
// =========================================================

// Inicia a sessão para permitir o acesso aos dados da profissional
// armazenados em $_SESSION.
session_start();

// Inclui o arquivo responsável pela conexão com o banco de dados.
// O require_once evita que o mesmo arquivo seja carregado mais de uma vez.
require_once("conexao.php");

// Verifica se existe uma profissional autenticada no sistema.
// Também confirma se o tipo de usuário da sessão é "profissional".
if (
    !isset($_SESSION["usuario_id"]) ||
    ($_SESSION["usuario_tipo"] ?? "") !== "profissional"
) {
    // Se o usuário não estiver autenticado como profissional,
// redireciona para a tela de login.
    header("Location: login.php");

    // Encerra a execução para impedir que o restante da página seja acessado.
    exit();
}

// Converte o ID da profissional para inteiro.
// Esse ID será utilizado nas consultas ao banco.
$profissional_id = (int)$_SESSION["usuario_id"];

// Define o fuso horário utilizado pelo sistema.
// America/Sao_Paulo corresponde ao horário oficial de Brasília.
date_default_timezone_set("America/Sao_Paulo");

// =========================================================
// CONFIGURAÇÕES DO CALENDÁRIO
// =========================================================

// Obtém o mês atual do computador/servidor.
// O formato "m" retorna o mês com dois dígitos, por exemplo: 01, 02, 11.
$mes_atual = date("m");

// Obtém o ano atual.
// O formato "Y" retorna o ano com quatro dígitos.
$ano_atual = date("Y");

// Tenta recuperar o mês enviado pela URL.
// Caso não exista, utiliza o mês atual.
$mes = $_GET["mes"] ?? $mes_atual;

// Tenta recuperar o ano enviado pela URL.
// Caso não exista, utiliza o ano atual.
$ano = $_GET["ano"] ?? $ano_atual;

// Define qual aba da agenda deverá aparecer.
// Se nenhuma aba for informada, a aba padrão será o calendário.
$aba = $_GET["aba"] ?? "calendario";

// ---------------------------------------------------------
// VALIDAÇÃO DO MÊS
// ---------------------------------------------------------

// Converte o mês recebido para inteiro.
// Também limita o valor entre 1 e 12 para evitar meses inválidos.
$mes = str_pad(
    min(max((int)$mes, 1), 12),
    2,
    "0",
    STR_PAD_LEFT
);

// ---------------------------------------------------------
// VALIDAÇÃO DO ANO
// ---------------------------------------------------------

// Limita o ano entre 2020 e 2100.
// Isso evita valores muito fora do intervalo esperado pelo sistema.
$ano = min(max((int)$ano, 2020), 2100);

// ---------------------------------------------------------
// VALIDAÇÃO DAS ABAS
// ---------------------------------------------------------

// Lista as únicas abas permitidas na página.
$abas_validas = [
    "calendario",
    "horarios",
    "bloqueios",
    "liberar"
];

// Verifica se a aba recebida pela URL está na lista permitida.
if (!in_array($aba, $abas_validas, true)) {

    // Caso seja inválida, retorna para a aba do calendário.
    $aba = "calendario";
}

// ---------------------------------------------------------
// MENSAGENS DA PÁGINA
// ---------------------------------------------------------

// Guarda mensagens de sucesso para serem exibidas no HTML.
$mensagem_sucesso = "";

// Guarda mensagens de erro para serem exibidas no HTML.
$mensagem_erro = "";

// Guarda os agendamentos encontrados quando um bloqueio
// entra em conflito com agendamentos existentes.
$agendamentos_conflitantes = [];

// =========================================================
// FUNÇÃO AUXILIAR — BUSCAR AGENDAMENTOS CONFLITANTES
// =========================================================

/**
 * Busca agendamentos pendentes da profissional dentro do período.
 *
 * Nesta versão, a comparação considera o horário de início
 * do agendamento, sem calcular a duração do serviço.
 */
function buscarAgendamentosConflitantes(
    PDO $conexao,
    int $profissional_id,
    string $data,
    string $inicio,
    string $fim,
    string $tipo
): array {

    // Monta a consulta que procura os agendamentos
    // pertencentes à profissional e à data informada.
    $sql = "
        SELECT
            a.id,
            a.data_agendamento,
            a.horario,
            a.status,
            c.nome AS nome_cliente,
            s.nome_servico
        FROM agendamentos a

        // Relaciona o agendamento com a tabela de usuários
        // para descobrir o nome da cliente.
        LEFT JOIN usuarios c
            ON c.id = a.cliente_id

        // Relaciona o agendamento com o serviço realizado.
        LEFT JOIN servicos s
            ON s.id = a.servico_id

        WHERE a.profissional_id = :pid
          AND a.data_agendamento = :data

          // Somente agendamentos pendentes podem ser
          // considerados para cancelamento automático.
          AND a.status = 'pendente'
    ";

    // Quando o bloqueio for somente de um horário,
    // adiciona à consulta a comparação do horário inicial.
    if ($tipo === "horario") {

        $sql .= "
            AND TIME(a.horario) >= :hi
            AND TIME(a.horario) < :hf
        ";
    }

    // Ordena os agendamentos pelo horário.
    $sql .= " ORDER BY a.horario";

    // Prepara a consulta para utilização de parâmetros.
    // Isso ajuda a evitar SQL Injection.
    $consulta = $conexao->prepare($sql);

    // Define os parâmetros básicos da consulta.
    $parametros = [
        ":pid" => $profissional_id,
        ":data" => $data
    ];

    // Caso seja um bloqueio parcial, adiciona
    // o horário inicial e final aos parâmetros.
    if ($tipo === "horario") {

        $parametros[":hi"] = $inicio;
        $parametros[":hf"] = $fim;
    }

    // Executa a consulta utilizando os parâmetros definidos.
    $consulta->execute($parametros);

    // Retorna todos os agendamentos encontrados
    // no formato de array associativo.
    return $consulta->fetchAll(PDO::FETCH_ASSOC);
}

// =========================================================
// PROCESSAMENTO DOS FORMULÁRIOS
// =========================================================

// Verifica se a página recebeu uma requisição POST.
// Também confirma se foi enviada uma ação.
if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST["acao"])
) {

    // Recupera o nome da ação enviada pelo formulário.
    $acao = $_POST["acao"];

    // O try permite tratar possíveis erros das operações
    // realizadas no banco de dados.
    try {

        // =====================================================
        // SALVAR HORÁRIOS DE TRABALHO
        // =====================================================

        if ($acao === "salvar_horarios") {

            // Inicia uma transação.
            // Assim, os horários dos sete dias são tratados como
            // uma única operação.
            $conexao->beginTransaction();

            // SQL responsável por inserir ou atualizar
            // o horário de cada dia da semana.
            $sql = "
                INSERT INTO horarios (
                    profissional_id,
                    dia_semana,
                    trabalha,
                    horario_inicio,
                    ultimo_horario,
                    inicio_almoco,
                    fim_almoco
                ) VALUES (
                    :profissional_id,
                    :dia_semana,
                    :trabalha,
                    :inicio,
                    :fim,
                    :inicio_almoco,
                    :fim_almoco
                )

                // Se já existir um registro para a profissional
                // e o dia, atualiza os dados existentes.
                ON DUPLICATE KEY UPDATE
                    trabalha = VALUES(trabalha),
                    horario_inicio = VALUES(horario_inicio),
                    ultimo_horario = VALUES(ultimo_horario),
                    inicio_almoco = VALUES(inicio_almoco),
                    fim_almoco = VALUES(fim_almoco)
            ";

            // Prepara a consulta.
            $stmt = $conexao->prepare($sql);

            // Percorre os sete dias da semana.
            // No sistema, 0 representa domingo e 6 representa sábado.
            for ($dia_semana = 0; $dia_semana <= 6; $dia_semana++) {

                // Verifica se o checkbox "Trabalha" daquele dia
                // foi marcado no formulário.
                $trabalha = isset($_POST["trabalha"][$dia_semana]) ? 1 : 0;

                // Recupera o horário inicial enviado pelo formulário.
                // Caso não exista, utiliza 08:00 como padrão.
                $inicio = $_POST["horario_inicio"][$dia_semana] ?? "08:00";

                // Recupera o último horário de atendimento.
                // Caso não exista, utiliza 19:00.
                $fim = $_POST["ultimo_horario"][$dia_semana] ?? "19:00";

                // Recupera o início do intervalo de almoço.
                $inicio_almoco = $_POST["inicio_almoco"][$dia_semana] ?? "12:00";

                // Recupera o fim do intervalo de almoço.
                $fim_almoco = $_POST["fim_almoco"][$dia_semana] ?? "13:00";

                // Executa a inserção ou atualização do dia atual.
                $stmt->execute([
                    ":profissional_id" => $profissional_id,
                    ":dia_semana" => $dia_semana,
                    ":trabalha" => $trabalha,
                    ":inicio" => $inicio,
                    ":fim" => $fim,
                    ":inicio_almoco" => $inicio_almoco,
                    ":fim_almoco" => $fim_almoco
                ]);
            }

            // Confirma definitivamente a transação.
            $conexao->commit();

            // Define a mensagem que será exibida para a profissional.
            $mensagem_sucesso = "Horários atualizados com sucesso!";

            // Mantém a usuária na aba de horários.
            $aba = "horarios";
        }

        // =====================================================
        // BLOQUEAR DIA OU PERÍODO
        // =====================================================

        elseif ($acao === "bloquear_dia") {

            // Recupera a data que a profissional deseja bloquear.
            $data_bloqueio = trim($_POST["data_bloqueio"] ?? "");

            // Recupera o motivo do bloqueio.
            // Se não for informado, utiliza "Folga".
            $motivo = trim($_POST["motivo"] ?? "Folga");

            // Recupera o tipo de bloqueio.
            // O padrão é bloquear o dia inteiro.
            $tipo_bloqueio = $_POST["tipo_bloqueio"] ?? "dia_inteiro";

            // Verifica se uma data foi informada.
            if (!$data_bloqueio) {

                // Interrompe a operação informando o erro.
                throw new Exception("Informe a data.");
            }

            // Tenta criar um objeto DateTime utilizando exatamente
            // o formato esperado pelo sistema.
            $data_validada = DateTime::createFromFormat(
                "!Y-m-d",
                $data_bloqueio
            );

            // Confirma se a data realmente existe
            // e se está no formato Y-m-d.
            if (
                !$data_validada ||
                $data_validada->format("Y-m-d") !== $data_bloqueio
            ) {

                throw new Exception("A data informada é inválida.");
            }

            // -------------------------------------------------
            // DEFINIÇÃO DO INTERVALO DO BLOQUEIO
            // -------------------------------------------------

            // Verifica se o bloqueio será apenas em determinado horário.
            if ($tipo_bloqueio === "horario") {

                // Recupera o horário inicial.
                $horario_inicio = trim(
                    $_POST["horario_inicio_bloqueio"] ?? ""
                );

                // Recupera o horário final.
                $horario_fim = trim(
                    $_POST["horario_fim_bloqueio"] ?? ""
                );

                // Os dois horários são obrigatórios.
                if (!$horario_inicio || !$horario_fim) {

                    throw new Exception(
                        "Informe o horário inicial e final."
                    );
                }

                // Garante que o horário final seja posterior
                // ao horário inicial.
                if ($horario_inicio >= $horario_fim) {

                    throw new Exception(
                        "O horário final deve ser posterior ao inicial."
                    );
                }

            } else {

                // Qualquer outro tipo será tratado como
                // bloqueio do dia inteiro.
                $tipo_bloqueio = "dia_inteiro";

                // Define o início do dia.
                $horario_inicio = "00:00";

                // Define o final do dia.
                $horario_fim = "23:59";
            }

            // Procura agendamentos pendentes que estejam
            // dentro do período que será bloqueado.
            $agendamentos_encontrados = buscarAgendamentosConflitantes(
                $conexao,
                $profissional_id,
                $data_bloqueio,
                $horario_inicio,
                $horario_fim,
                $tipo_bloqueio
            );

            // Verifica se existem agendamentos conflitantes.
            if (!empty($agendamentos_encontrados)) {

                // Guarda temporariamente os dados do bloqueio na sessão.
                // Isso permite mostrar a confirmação na próxima etapa.
                $_SESSION["bloqueio_pendente"] = [
                    "data" => $data_bloqueio,
                    "inicio" => $horario_inicio,
                    "fim" => $horario_fim,
                    "motivo" => $motivo,
                    "tipo" => $tipo_bloqueio
                ];

                // Guarda os agendamentos encontrados para serem
                // exibidos na tela.
                $agendamentos_conflitantes = $agendamentos_encontrados;

                // Exibe uma mensagem avisando que existem conflitos.
                $mensagem_erro = "Existem agendamentos pendentes neste período. Verifique a lista antes de continuar.";

            } else {

                // Caso não existam conflitos, prepara o INSERT
                // responsável por registrar o bloqueio.
                $comando = $conexao->prepare("
                    INSERT INTO bloqueios_agenda (
                        profissional_id,
                        data_bloqueio,
                        horario_inicio,
                        horario_fim,
                        motivo
                    ) VALUES (
                        :pid,
                        :data,
                        :hi,
                        :hf,
                        :motivo
                    )
                ");

                // Executa o INSERT utilizando os dados informados.
                $comando->execute([
                    ":pid" => $profissional_id,
                    ":data" => $data_bloqueio,
                    ":hi" => $horario_inicio,
                    ":hf" => $horario_fim,
                    ":motivo" => $motivo
                ]);

                // Remove qualquer confirmação de bloqueio anterior.
                unset($_SESSION["bloqueio_pendente"]);

                // Informa que o bloqueio foi criado.
                $mensagem_sucesso = "Bloqueio registrado!";
            }

            // Após a operação, mantém a usuária na aba de bloqueios.
            $aba = "bloqueios";
        }

        // =====================================================
        // CONFIRMAR BLOQUEIO E CANCELAR AGENDAMENTOS
        // =====================================================

        elseif ($acao === "confirmar_bloqueio_com_cancelamento") {

            // Verifica se existe um bloqueio aguardando confirmação.
            if (empty($_SESSION["bloqueio_pendente"])) {

                throw new Exception(
                    "Não existe um bloqueio aguardando confirmação. Tente novamente."
                );
            }

            // Recupera os dados temporários do bloqueio.
            $dados_bloqueio = $_SESSION["bloqueio_pendente"];

            // Inicia uma transação.
            // Cancelamento e criação do bloqueio serão tratados juntos.
            $conexao->beginTransaction();

            try {

                // Consulta novamente os agendamentos conflitantes.
                // Isso evita confiar somente nos dados armazenados anteriormente.
                $ids_conflitantes = buscarAgendamentosConflitantes(
                    $conexao,
                    $profissional_id,
                    $dados_bloqueio["data"],
                    $dados_bloqueio["inicio"],
                    $dados_bloqueio["fim"],
                    $dados_bloqueio["tipo"]
                );

                // Se ainda existirem agendamentos pendentes,
                // eles poderão ser cancelados.
                if (!empty($ids_conflitantes)) {

                    // Prepara o UPDATE dos agendamentos.
                    $cancelar = $conexao->prepare("
                        UPDATE agendamentos
                        SET status = 'cancelado'
                        WHERE id = :id
                          AND profissional_id = :pid
                          AND status = 'pendente'
                    ");

                    // Percorre cada agendamento conflitante.
                    foreach ($ids_conflitantes as $agendamento) {

                        // Atualiza o status daquele agendamento.
                        $cancelar->execute([
                            ":id" => (int)$agendamento["id"],
                            ":pid" => $profissional_id
                        ]);
                    }
                }

                // Depois dos cancelamentos, prepara a inserção
                // do bloqueio na agenda.
                $inserir = $conexao->prepare("
                    INSERT INTO bloqueios_agenda (
                        profissional_id,
                        data_bloqueio,
                        horario_inicio,
                        horario_fim,
                        motivo
                    ) VALUES (
                        :pid,
                        :data,
                        :hi,
                        :hf,
                        :motivo
                    )
                ");

                // Registra o bloqueio.
                $inserir->execute([
                    ":pid" => $profissional_id,
                    ":data" => $dados_bloqueio["data"],
                    ":hi" => $dados_bloqueio["inicio"],
                    ":hf" => $dados_bloqueio["fim"],
                    ":motivo" => $dados_bloqueio["motivo"]
                ]);

                // Confirma todas as operações realizadas na transação.
                $conexao->commit();

                // Remove os dados temporários da sessão.
                unset($_SESSION["bloqueio_pendente"]);

                // Exibe a mensagem de sucesso.
                $mensagem_sucesso = "Bloqueio registrado e agendamentos conflitantes cancelados!";

            } catch (Exception $erro) {

                // Verifica se ainda existe uma transação aberta.
                if ($conexao->inTransaction()) {

                    // Desfaz todas as alterações realizadas.
                    $conexao->rollBack();
                }

                // Envia o erro para o catch principal.
                throw $erro;
            }

            // Mantém a aba de bloqueios aberta.
            $aba = "bloqueios";
        }

        // =====================================================
        // DESISTIR DA CONFIRMAÇÃO DO BLOQUEIO
        // =====================================================

        elseif ($acao === "cancelar_confirmacao_bloqueio") {

            // Remove o bloqueio temporário da sessão.
            unset($_SESSION["bloqueio_pendente"]);

            // Informa que os agendamentos foram mantidos.
            $mensagem_sucesso = "Operação cancelada. Os agendamentos foram mantidos.";

            // Mantém a aba de bloqueios.
            $aba = "bloqueios";
        }

        // =====================================================
        // EXCLUIR BLOQUEIO
        // =====================================================

        elseif ($acao === "excluir_bloqueio") {

            // Recupera o ID do bloqueio enviado pelo formulário.
            $bloqueio_id = (int)($_POST["bloqueio_id"] ?? 0);

            // Exclui somente o bloqueio pertencente
            // à profissional atualmente autenticada.
            $conexao->prepare("
                DELETE FROM bloqueios_agenda
                WHERE id = :id
                  AND profissional_id = :pid
            ")->execute([
                ":id" => $bloqueio_id,
                ":pid" => $profissional_id
            ]);

            // Exibe mensagem de sucesso.
            $mensagem_sucesso = "Bloqueio removido!";

            // Mantém a aba de bloqueios.
            $aba = "bloqueios";
        }

        // =====================================================
        // LIBERAR DIAS
        // =====================================================

        elseif ($acao === "liberar_dias") {

            // Recupera os dias selecionados no calendário.
            $dias_selecionados = $_POST["dias"] ?? [];

            // Impede o envio sem selecionar nenhum dia.
            if (empty($dias_selecionados)) {

                throw new Exception("Selecione pelo menos um dia.");
            }

            // Percorre todas as datas selecionadas.
            foreach ($dias_selecionados as $data) {

                // Valida a data recebida.
                $data_validada = DateTime::createFromFormat("!Y-m-d", $data);

                // Confirma se a data realmente está no formato esperado.
                if (
                    !$data_validada ||
                    $data_validada->format("Y-m-d") !== $data
                ) {

                    throw new Exception("Uma das datas selecionadas é inválida.");
                }

                // Consulta se a data já está liberada.
                $verifica = $conexao->prepare("
                    SELECT id
                    FROM liberacao_agenda
                    WHERE profissional_id = :pid
                      AND data_inicio = :dt
                      AND data_fim = :dt
                ");

                // Executa a consulta.
                $verifica->execute([
                    ":pid" => $profissional_id,
                    ":dt" => $data
                ]);

                // Somente insere se a data ainda não estiver liberada.
                if (!$verifica->fetch()) {

                    // Prepara o INSERT da liberação.
                    $ins = $conexao->prepare("
                        INSERT INTO liberacao_agenda (
                            profissional_id,
                            tipo,
                            valor,
                            data_inicio,
                            data_fim,
                            criado_em
                        ) VALUES (
                            :pid,
                            'dia',
                            :dt,
                            :dt,
                            :dt,
                            NOW()
                        )
                    ");

                    // Executa o INSERT.
                    $ins->execute([
                        ":pid" => $profissional_id,
                        ":dt" => $data
                    ]);
                }
            }

            // Exibe mensagem de sucesso.
            $mensagem_sucesso = "Dias liberados com sucesso!";

            // Mantém a aba de liberação.
            $aba = "liberar";
        }

        // =====================================================
        // REMOVER LIBERAÇÕES
        // =====================================================

        elseif ($acao === "remover_liberacao") {

            // Recupera os dias selecionados.
            $dias_selecionados = $_POST["dias"] ?? [];

            // Exige pelo menos um dia selecionado.
            if (empty($dias_selecionados)) {

                throw new Exception("Selecione pelo menos um dia.");
            }

            // Percorre os dias selecionados.
            foreach ($dias_selecionados as $data) {

                // Remove a liberação correspondente à data.
                $conexao->prepare("
                    DELETE FROM liberacao_agenda
                    WHERE profissional_id = :pid
                      AND data_inicio = :dt
                      AND data_fim = :dt
                ")->execute([
                    ":pid" => $profissional_id,
                    ":dt" => $data
                ]);
            }

            // Exibe mensagem de sucesso.
            $mensagem_sucesso = "Liberações removidas!";

            // Mantém a aba de liberação.
            $aba = "liberar";
        }

        // =====================================================
        // CANCELAR AGENDAMENTO INDIVIDUAL
        // =====================================================

        elseif ($acao === "cancelar_agendamento") {

            // Recupera o ID do agendamento.
            $agendamento_id = (int)($_POST["agendamento_id"] ?? 0);

            // Atualiza o status para cancelado.
            // A condição profissional_id garante que a profissional
            // só possa cancelar seus próprios agendamentos.
            $conexao->prepare("
                UPDATE agendamentos
                SET status = 'cancelado'
                WHERE id = :id
                  AND profissional_id = :pid
                  AND status NOT IN ('cancelado', 'concluido')
            ")->execute([
                ":id" => $agendamento_id,
                ":pid" => $profissional_id
            ]);

            // Exibe mensagem de sucesso.
            $mensagem_sucesso = "Agendamento cancelado!";

            // Recupera a aba atual ou utiliza o calendário como padrão.
            $aba = $_GET["aba"] ?? "calendario";
        }

    } catch (Exception $erro) {

        // Verifica se alguma transação ainda está aberta.
        if ($conexao->inTransaction()) {

            // Desfaz a transação para evitar alterações incompletas.
            $conexao->rollBack();
        }

        // Cria uma mensagem de erro para mostrar na página.
        $mensagem_erro = "Erro: " . $erro->getMessage();

        // -----------------------------------------------------
        // MANTÉM A ABA CORRETA APÓS UM ERRO
        // -----------------------------------------------------

        // Se o erro ocorreu ao salvar horários,
        // retorna para a aba de horários.
        if ($acao === "salvar_horarios") {

            $aba = "horarios";

        // Se o erro estiver relacionado a bloqueios,
        // retorna para a aba de bloqueios.
        } elseif (in_array($acao, [
            "bloquear_dia",
            "confirmar_bloqueio_com_cancelamento",
            "cancelar_confirmacao_bloqueio",
            "excluir_bloqueio"
        ], true)) {

            $aba = "bloqueios";

        // Se o erro estiver relacionado às liberações,
        // retorna para a aba de liberação.
        } elseif (in_array($acao, [
            "liberar_dias",
            "remover_liberacao"
        ], true)) {

            $aba = "liberar";
        }
    }
}

// =========================================================
// RECUPERAR BLOQUEIO PENDENTE
// =========================================================

// Verifica se existe um bloqueio aguardando confirmação.
// Também verifica se a usuária está na aba de bloqueios.
if (
    !empty($_SESSION["bloqueio_pendente"]) &&
    $aba === "bloqueios" &&
    empty($agendamentos_conflitantes)
) {

    // Recupera os dados armazenados na sessão.
    $dados_pendentes = $_SESSION["bloqueio_pendente"];

    try {

        // Busca novamente os agendamentos conflitantes
        // para exibi-los na página.
        $agendamentos_conflitantes = buscarAgendamentosConflitantes(
            $conexao,
            $profissional_id,
            $dados_pendentes["data"],
            $dados_pendentes["inicio"],
            $dados_pendentes["fim"],
            $dados_pendentes["tipo"]
        );

    } catch (PDOException $e) {

        // Registra o erro no log do servidor.
        // O usuário não vê detalhes técnicos do banco.
        error_log(
            "Erro ao recuperar agendamentos conflitantes: " .
            $e->getMessage()
        );
    }
}

// =========================================================
// CARREGAMENTO DOS HORÁRIOS SEMANAIS
// =========================================================

// Cria o array que armazenará os horários dos sete dias.
$horarios_dia = [];

// Percorre domingo até sábado.
for ($i = 0; $i <= 6; $i++) {

    // Define valores padrão para cada dia.
    $horarios_dia[$i] = [
        "trabalha" => 0,
        "horario_inicio" => "08:00",
        "ultimo_horario" => "19:00",
        "inicio_almoco" => "12:00",
        "fim_almoco" => "13:00"
    ];
}

try {

    // Busca os horários cadastrados pela profissional.
    $consulta = $conexao->prepare("
        SELECT
            dia_semana,
            trabalha,
            horario_inicio,
            ultimo_horario,
            inicio_almoco,
            fim_almoco
        FROM horarios
        WHERE profissional_id = :pid
    ");

    // Executa a consulta para a profissional logada.
    $consulta->execute([
        ":pid" => $profissional_id
    ]);

    // Percorre os registros encontrados.
    foreach ($consulta->fetchAll(PDO::FETCH_ASSOC) as $h) {

        // Converte o dia da semana para inteiro.
        $dia = (int)$h["dia_semana"];

        // Garante que o índice esteja entre 0 e 6.
        if ($dia >= 0 && $dia <= 6) {

            // Substitui os valores padrão pelos dados salvos.
            $horarios_dia[$dia] = [
                "trabalha" => (int)$h["trabalha"],
                "horario_inicio" => $h["horario_inicio"] ?? "08:00",
                "ultimo_horario" => $h["ultimo_horario"] ?? "19:00",
                "inicio_almoco" => $h["inicio_almoco"] ?? "12:00",
                "fim_almoco" => $h["fim_almoco"] ?? "13:00"
            ];
        }
    }

} catch (PDOException $e) {

    // Registra o erro no log sem interromper toda a página.
    error_log("Erro ao buscar horários: " . $e->getMessage());
}

// =========================================================
// CARREGAMENTO DOS AGENDAMENTOS DO MÊS
// =========================================================

// Cria um array que organizará os agendamentos pela data.
$agendamentos_por_dia = [];

try {

    // Busca os agendamentos da profissional no mês selecionado.
    $consulta = $conexao->prepare("
        SELECT
            a.id,
            a.data_agendamento,
            a.horario,
            a.status,
            c.nome AS nome_cliente,
            s.nome_servico
        FROM agendamentos a

        // Busca o nome da cliente.
        LEFT JOIN usuarios c
            ON a.cliente_id = c.id

        // Busca o nome do serviço.
        LEFT JOIN servicos s
            ON a.servico_id = s.id

        WHERE a.profissional_id = :pid

          // Filtra pelo ano escolhido no calendário.
          AND YEAR(a.data_agendamento) = :ano

          // Filtra pelo mês escolhido.
          AND MONTH(a.data_agendamento) = :mes

        // Organiza primeiro pela data e depois pelo horário.
        ORDER BY a.data_agendamento, a.horario
    ");

    // Executa a consulta.
    $consulta->execute([
        ":pid" => $profissional_id,
        ":ano" => $ano,
        ":mes" => (int)$mes
    ]);

    // Percorre todos os agendamentos encontrados.
    while ($ag = $consulta->fetch(PDO::FETCH_ASSOC)) {

        // Usa a data como chave.
        // Assim conseguimos recuperar rapidamente os agendamentos de um dia.
        $agendamentos_por_dia[$ag["data_agendamento"]][] = $ag;
    }

} catch (PDOException $e) {

    // Registra o erro no log.
    error_log("Erro ao buscar agendamentos: " . $e->getMessage());
}

// =========================================================
// CARREGAMENTO DOS BLOQUEIOS DO MÊS
// =========================================================

// Array que armazenará os bloqueios organizados por data.
$bloqueios = [];

try {

    // Busca os bloqueios da profissional no mês selecionado.
    $consulta = $conexao->prepare("
        SELECT
            id,
            data_bloqueio,
            horario_inicio,
            horario_fim,
            motivo
        FROM bloqueios_agenda
        WHERE profissional_id = :pid
          AND YEAR(data_bloqueio) = :ano
          AND MONTH(data_bloqueio) = :mes
    ");

    // Executa a consulta.
    $consulta->execute([
        ":pid" => $profissional_id,
        ":ano" => $ano,
        ":mes" => (int)$mes
    ]);

    // Percorre os bloqueios encontrados.
    while ($b = $consulta->fetch(PDO::FETCH_ASSOC)) {

        // Organiza os bloqueios usando a data como chave.
        $bloqueios[$b["data_bloqueio"]][] = $b;
    }

} catch (PDOException $e) {

    // Registra o erro no log.
    error_log("Erro ao buscar bloqueios: " . $e->getMessage());
}

// =========================================================
// CARREGAMENTO DAS LIBERAÇÕES
// =========================================================

// Guarda todos os registros da tabela liberacao_agenda.
$liberacoes = [];

// Guarda apenas as datas que estão liberadas.
$dias_liberados = [];

try {

    // Busca todas as liberações da profissional.
    $consulta = $conexao->prepare("
        SELECT *
        FROM liberacao_agenda
        WHERE profissional_id = :pid
        ORDER BY data_inicio DESC
    ");

    // Executa a consulta.
    $consulta->execute([
        ":pid" => $profissional_id
    ]);

    // Armazena todos os registros.
    $liberacoes = $consulta->fetchAll(PDO::FETCH_ASSOC);

    // Percorre cada período liberado.
    foreach ($liberacoes as $lib) {

        // Cria o objeto DateTime para a data inicial.
        $inicio = new DateTime($lib["data_inicio"]);

        // Cria o objeto DateTime para a data final.
        $fim = new DateTime($lib["data_fim"]);

        // Percorre todos os dias entre o início e o fim.
        while ($inicio <= $fim) {

            // Marca a data como liberada.
            $dias_liberados[$inicio->format("Y-m-d")] = true;

            // Avança um dia.
            $inicio->modify("+1 day");
        }
    }

} catch (PDOException $e) {

    // Registra o erro no log.
    error_log("Erro ao buscar liberações: " . $e->getMessage());
}

// =========================================================
// INFORMAÇÕES DO CALENDÁRIO
// =========================================================

// Associa o número do mês ao nome em português.
$nomes_meses = [
    "01" => "Janeiro",
    "02" => "Fevereiro",
    "03" => "Março",
    "04" => "Abril",
    "05" => "Maio",
    "06" => "Junho",
    "07" => "Julho",
    "08" => "Agosto",
    "09" => "Setembro",
    "10" => "Outubro",
    "11" => "Novembro",
    "12" => "Dezembro"
];

// Recupera o nome do mês atual.
$nome_mes = $nomes_meses[$mes] ?? "";

// Descobre em qual dia da semana começa o mês.
// O formato "w" retorna 0 para domingo até 6 para sábado.
$primeiro_dia_semana = date(
    "w",
    strtotime("$ano-$mes-01")
);

// Descobre quantos dias existem no mês selecionado.
$quantos_dias = date(
    "t",
    strtotime("$ano-$mes-01")
);

// Calcula qual será o mês anterior.
$mes_anterior = $mes == 1 ? 12 : $mes - 1;

// Calcula o ano correspondente ao mês anterior.
$ano_anterior = $mes == 1 ? $ano - 1 : $ano;

// Calcula qual será o próximo mês.
$proximo_mes = $mes == 12 ? 1 : $mes + 1;

// Calcula o ano correspondente ao próximo mês.
$ano_proximo = $mes == 12 ? $ano + 1 : $ano;

// Nomes abreviados dos dias da semana.
$nomes_dias_semana = [
    "Dom",
    "Seg",
    "Ter",
    "Qua",
    "Qui",
    "Sex",
    "Sáb"
];

// Guarda a data atual no formato utilizado pelo banco.
$hoje_data = date("Y-m-d");

// =========================================================
// VALIDAÇÃO DO DIA SELECIONADO
// =========================================================

// Recupera o dia selecionado pela URL.
$dia_selecionado = trim($_GET["dia"] ?? "");

// Tenta transformar a data recebida em objeto DateTime.
$data_selecionada_obj = DateTime::createFromFormat(
    "!Y-m-d",
    $dia_selecionado
);

// Verifica se:
// 1. a data existe;
// 2. está no formato correto;
// 3. pertence ao ano atualmente selecionado;
// 4. pertence ao mês atualmente selecionado.
if (
    !$data_selecionada_obj ||
    $data_selecionada_obj->format("Y-m-d") !== $dia_selecionado ||
    $data_selecionada_obj->format("Y") !== (string)$ano ||
    $data_selecionada_obj->format("m") !== $mes
) {

    // Se qualquer condição falhar, nenhum dia será considerado selecionado.
    $dia_selecionado = "";
}
?>

<!DOCTYPE html>
<html lang="pt-BR">

<head>

    <!-- Define a codificação para aceitar caracteres especiais do português. -->
    <meta charset="UTF-8">

    <!-- Faz a página se adaptar a diferentes tamanhos de tela. -->
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- Define o título mostrado na aba do navegador. -->
    <title>Minha Agenda</title>

    <!-- Carrega o CSS externo utilizado pelo sistema. -->
    <link rel="stylesheet" href="style.css">

</head>

<body>

<!-- =====================================================
     MENU LATERAL
     ===================================================== -->

<!-- Sidebar principal utilizada na área profissional. -->
<aside class="sidebar">

    <!-- Área que apresenta o nome do sistema e a especialidade. -->
    <div class="logo">

        <!-- Nome apresentado na identidade visual. -->
        <h1>Estética</h1>

        <!-- Mostra a especialidade armazenada na sessão.
             Se não existir, apresenta "Profissional". -->
        <p>
            <?= htmlspecialchars($_SESSION["especialidade"] ?? "Profissional") ?>
        </p>

    </div>

    <!-- Menu de navegação da profissional. -->
    <nav class="menu">

        <!-- Link para o painel principal. -->
        <a href="painel_profissional.php">
            Início
        </a>

        <!-- Link para os atendimentos. -->
        <a href="atendimentos.php">
            Atendimentos
        </a>

        <!-- Página atual, por isso recebe a classe "ativo". -->
        <a href="minha_agenda.php" class="ativo">
            Minha Agenda
        </a>

        <!-- Link para os serviços da profissional. -->
        <a href="meus_servicos.php">
            Meus Serviços
        </a>

        <!-- Separador visual do menu. -->
        <div class="separador"></div>

        <!-- Link para o perfil. -->
        <a href="meu_perfil.php">
            Meu Perfil
        </a>

    </nav>

    <!-- Link para encerrar a sessão. -->
    <a href="logout.php" class="btn-sair">
        Sair
    </a>

</aside>

<!-- =====================================================
     CONTEÚDO PRINCIPAL
     ===================================================== -->

<!-- Área principal da página. -->
<main class="main">

    <!-- Cabeçalho da página. -->
    <section class="topo">

        <div>

            <!-- Título da página. -->
            <h2>Minha Agenda</h2>

            <!-- Explicação das funcionalidades disponíveis. -->
            <p>
                Gerencie seus horários, bloqueios e períodos liberados
            </p>

        </div>

        <!-- Exibe a data atual. -->
        <div class="data">
            <?= date("l, d/m/Y") ?>
        </div>

    </section>

    <!-- =================================================
         MENSAGENS DE RETORNO
         ================================================= -->

    <!-- Exibe mensagem de sucesso quando existir. -->
    <?php if ($mensagem_sucesso): ?>

        <div class="alerta alerta-sucesso">

            <?= htmlspecialchars($mensagem_sucesso) ?>

        </div>

    <?php endif; ?>

    <!-- Exibe mensagem de erro quando existir. -->
    <?php if ($mensagem_erro): ?>

        <div class="alerta alerta-erro">

            <?= htmlspecialchars($mensagem_erro) ?>

        </div>

    <?php endif; ?>

    <!-- =================================================
         ACESSO RÁPIDO À LIBERAÇÃO
         ================================================= -->

    <div class="botoes-acao">

        <!-- Leva diretamente para a aba de liberação. -->
        <a
            href="?mes=<?= $mes ?>&ano=<?= $ano ?>&aba=liberar"
            class="btn btn-primario"
        >
            Liberar Agendamentos
        </a>

    </div>

    <!-- =================================================
         NAVEGAÇÃO ENTRE ABAS
         ================================================= -->

    <div class="abas-linha">

        <!-- Aba do calendário. -->
        <a
            href="?mes=<?= $mes ?>&ano=<?= $ano ?>&aba=calendario"
            class="aba-botao <?= $aba === 'calendario' ? 'ativa' : '' ?>"
        >
            Calendário
        </a>

        <!-- Aba dos horários semanais. -->
        <a
            href="?mes=<?= $mes ?>&ano=<?= $ano ?>&aba=horarios"
            class="aba-botao <?= $aba === 'horarios' ? 'ativa' : '' ?>"
        >
            Meus Horários
        </a>

        <!-- Aba de folgas e bloqueios. -->
        <a
            href="?mes=<?= $mes ?>&ano=<?= $ano ?>&aba=bloqueios"
            class="aba-botao <?= $aba === 'bloqueios' ? 'ativa' : '' ?>"
        >
            Folgas/Bloqueios
        </a>

        <!-- Aba de liberação da agenda. -->
        <a
            href="?mes=<?= $mes ?>&ano=<?= $ano ?>&aba=liberar"
            class="aba-botao <?= $aba === 'liberar' ? 'ativa' : '' ?>"
        >
            Liberação
        </a>

    </div>

    <!-- =================================================
         ABA 1 — CALENDÁRIO
         ================================================= -->

    <!-- A classe "visivel" é adicionada somente quando
         a aba calendário estiver selecionada. -->
    <div class="aba-conteudo <?= $aba === 'calendario' ? 'visivel' : '' ?>">

        <!-- Container principal do calendário. -->
        <div class="calendario">

            <!-- Cabeçalho com navegação entre meses. -->
            <div class="calendario-cabecalho">

                <!-- Link para o mês anterior. -->
                <a
                    href="?mes=<?= $mes_anterior ?>&ano=<?= $ano_anterior ?>&aba=calendario"
                >
                    ◀
                </a>

                <!-- Nome e ano do mês selecionado. -->
                <h3>
                    <?= $nome_mes ?> de <?= $ano ?>
                </h3>

                <!-- Link para o próximo mês. -->
                <a
                    href="?mes=<?= $proximo_mes ?>&ano=<?= $ano_proximo ?>&aba=calendario"
                >
                    ▶
                </a>

            </div>

            <!-- Grade que contém os dias do calendário. -->
            <div class="calendario-grade">

                <!-- Percorre os nomes dos dias da semana. -->
                <?php foreach ($nomes_dias_semana as $n): ?>

                    <div class="calendario-dia-nome">

                        <?= $n ?>

                    </div>

                <?php endforeach; ?>

                <!--
                    Cria espaços vazios antes do primeiro dia do mês.
                    Isso posiciona o primeiro dia na coluna correta.
                -->
                <?php for ($i = 0; $i < $primeiro_dia_semana; $i++): ?>

                    <div class="calendario-dia calendario-dia-vazio"></div>

                <?php endfor; ?>

                <?php

                // Percorre todos os dias existentes no mês.
                for ($dia = 1; $dia <= $quantos_dias; $dia++):

                    // Formata o número do dia com dois dígitos.
                    $dia_formatado = str_pad(
                        $dia,
                        2,
                        "0",
                        STR_PAD_LEFT
                    );

                    // Monta a data completa no formato Y-m-d.
                    $data = sprintf(
                        "%04d-%02d-%02d",
                        $ano,
                        $mes,
                        $dia
                    );

                    // Descobre o dia da semana correspondente.
                    $dia_sem = date("w", strtotime($data));

                    // Verifica se a data corresponde ao dia atual.
                    $hoje = $data === $hoje_data;

                    // Recupera as configurações daquele dia da semana.
                    // Caso não exista, considera que não trabalha.
                    $config_dia = $horarios_dia[$dia_sem] ?? [
                        "trabalha" => 0
                    ];

                    // Converte o valor de trabalha para inteiro.
                    $trabalha = (int)($config_dia["trabalha"] ?? 0);

                    // Verifica se existe pelo menos um bloqueio naquela data.
                    $tem_bloqueio = isset($bloqueios[$data]);

                    // Verifica se o dia foi liberado para agendamento.
                    $liberado = isset($dias_liberados[$data]);

                    // Começa sem nenhuma classe adicional.
                    $classe = "";

                    // Bloqueio possui prioridade visual.
                    if ($tem_bloqueio) {

                        $classe = "dia-bloqueado";

                    // Depois verifica se o dia foi liberado.
                    } elseif ($liberado) {

                        $classe = "dia-liberado";

                    // Caso trabalhe normalmente, marca como dia de trabalho.
                    } elseif ($trabalha === 1) {

                        $classe = "dia-trabalho";
                    }

                    // Adiciona uma classe especial para o dia atual.
                    if ($hoje) {

                        $classe .= " dia-hoje";
                    }

                ?>

                    <!--
                        Cada dia pode ser clicado para visualizar
                        seus agendamentos e bloqueios.
                    -->
                    <div
                        class="calendario-dia calendario-dia-clicavel
                        <?= trim($classe) ?>
                        <?= $dia_selecionado === $data ? 'dia-selecionado' : '' ?>"
                        role="link"
                        tabindex="0"
                        aria-label="Ver agendamentos e bloqueios de <?= date('d/m/Y', strtotime($data)) ?>"
                        data-dia-url="?mes=<?= $mes ?>&amp;ano=<?= $ano ?>&amp;aba=calendario&amp;dia=<?= $data ?>#detalhes-dia"
                    >

                        <!-- Número do dia. -->
                        <div class="calendario-dia-numero">

                            <?= $dia ?>

                        </div>

                        <!-- Mostra a etiqueta caso o dia esteja liberado. -->
                        <?php if ($liberado): ?>

                            <span class="etiqueta-liberado">
                                Liberado
                            </span>

                        <?php endif; ?>

                        <!-- Mostra informações dos bloqueios. -->
                        <?php if ($tem_bloqueio): ?>

                            <span class="etiqueta-bloqueado">
                                Bloqueado
                            </span>

                            <?php
                            // Percorre todos os bloqueios existentes naquele dia.
                            foreach ($bloqueios[$data] as $bloqueio_calendario):

                                // Obtém apenas hora e minuto do início.
                                $inicio_cal = substr(
                                    $bloqueio_calendario["horario_inicio"] ?? "00:00",
                                    0,
                                    5
                                );

                                // Obtém apenas hora e minuto do fim.
                                $fim_cal = substr(
                                    $bloqueio_calendario["horario_fim"] ?? "23:59",
                                    0,
                                    5
                                );

                                // Verifica se o bloqueio representa o dia inteiro.
                                $dia_inteiro_cal = (
                                    $inicio_cal === "00:00" &&
                                    ($fim_cal === "23:59" || $fim_cal === "00:00")
                                );

                            ?>

                                <!-- Resumo do bloqueio dentro do dia. -->
                                <small class="bloqueio-resumo-calendario">

                                    <!-- Exibe "Dia inteiro" ou o intervalo. -->
                                    <?= $dia_inteiro_cal
                                        ? "Dia inteiro"
                                        : htmlspecialchars($inicio_cal . " às " . $fim_cal)
                                    ?>

                                    <!-- Exibe o motivo quando informado. -->
                                    <?php if (!empty($bloqueio_calendario["motivo"])): ?>

                                        <span>
                                            <?= htmlspecialchars($bloqueio_calendario["motivo"]) ?>
                                        </span>

                                    <?php endif; ?>

                                </small>

                            <?php endforeach; ?>

                        <!-- Caso não esteja bloqueado, mostra o horário de trabalho. -->
                        <?php elseif ($trabalha === 1): ?>

                            <small class="horario-dia">

                                <?= substr($config_dia["horario_inicio"] ?? "08:00", 0, 5) ?>

                                -

                                <?= substr($config_dia["ultimo_horario"] ?? "19:00", 0, 5) ?>

                            </small>

                        <?php endif; ?>

                    </div>

                <?php endfor; ?>

            </div>

        </div>

        <!-- =================================================
             DETALHES DO DIA SELECIONADO
             ================================================= -->

        <!-- Só mostra esta seção quando um dia válido foi selecionado. -->
        <?php if ($dia_selecionado !== ""): ?>

            <section
                class="detalhes-dia-agenda"
                id="detalhes-dia"
            >

                <!-- Título com a data selecionada. -->
                <h3>
                    Agenda de <?= date("d/m/Y", strtotime($dia_selecionado)) ?>
                </h3>

                <!-- Nome completo do dia da semana. -->
                <p class="detalhes-dia-subtitulo">

                    <?= [
                        "Domingo",
                        "Segunda-feira",
                        "Terça-feira",
                        "Quarta-feira",
                        "Quinta-feira",
                        "Sexta-feira",
                        "Sábado"
                    ][(int)date("w", strtotime($dia_selecionado))] ?>

                </p>

                <!-- Título da lista de agendamentos. -->
                <h4>Agendamentos do dia</h4>

                <!-- Verifica se existem agendamentos naquela data. -->
                <?php if (!empty($agendamentos_por_dia[$dia_selecionado])): ?>

                    <div class="lista-detalhes-agendamentos">

                        <!-- Percorre os agendamentos da data selecionada. -->
                        <?php foreach ($agendamentos_por_dia[$dia_selecionado] as $ag_detalhe):

                            // Recupera o status do agendamento.
                            // Caso esteja vazio, considera pendente.
                            $status_detalhe = $ag_detalhe["status"] ?? "pendente";

                            // Define a classe visual padrão.
                            $status_classe = "etiqueta-pendente";

                            // Altera a classe de acordo com o status.
                            if ($status_detalhe === "concluido") {

                                $status_classe = "etiqueta-concluido";

                            } elseif ($status_detalhe === "cancelado") {

                                $status_classe = "etiqueta-cancelado";

                            } elseif ($status_detalhe === "alterado") {

                                $status_classe = "etiqueta-alterado";
                            }

                            // Traduz os status para textos amigáveis.
                            $status_texto = [
                                "pendente" => "Pendente",
                                "concluido" => "Concluído",
                                "cancelado" => "Cancelado",
                                "alterado" => "Alterado"
                            ][$status_detalhe] ?? ucfirst($status_detalhe);

                        ?>

                            <!-- Cada agendamento é apresentado como um item. -->
                            <article class="detalhe-agendamento-item">

                                <!-- Horário do atendimento. -->
                                <div class="detalhe-agendamento-horario">

                                    <?= htmlspecialchars(
                                        substr(
                                            $ag_detalhe["horario"] ?? "--:--",
                                            0,
                                            5
                                        )
                                    ) ?>

                                </div>

                                <!-- Informações da cliente e do serviço. -->
                                <div class="detalhe-agendamento-informacoes">

                                    <!-- Nome da cliente. -->
                                    <strong>
                                        <?= htmlspecialchars(
                                            $ag_detalhe["nome_cliente"] ?? "Cliente não identificada"
                                        ) ?>
                                    </strong>

                                    <!-- Nome do serviço. -->
                                    <span>
                                        Serviço:
                                        <?= htmlspecialchars(
                                            $ag_detalhe["nome_servico"] ?? "Serviço não informado"
                                        ) ?>
                                    </span>

                                    <!-- Status do agendamento. -->
                                    <span class="<?= $status_classe ?> detalhe-status">
                                        <?= htmlspecialchars($status_texto) ?>
                                    </span>

                                </div>

                                <!--
                                    Mostra o botão cancelar somente para
                                    agendamentos que ainda podem ser cancelados.
                                -->
                                <?php if (
                                    $status_detalhe !== "cancelado" &&
                                    $status_detalhe !== "concluido"
                                ): ?>

                                    <form
                                        method="POST"
                                        class="form-cancelar-detalhe"
                                        onsubmit="return confirm('Cancelar este agendamento?')"
                                    >

                                        <!-- Informa qual operação deverá ser executada. -->
                                        <input
                                            type="hidden"
                                            name="acao"
                                            value="cancelar_agendamento"
                                        >

                                        <!-- Envia o ID do agendamento. -->
                                        <input
                                            type="hidden"
                                            name="agendamento_id"
                                            value="<?= (int)$ag_detalhe["id"] ?>"
                                        >

                                        <!-- Botão que envia o formulário. -->
                                        <button
                                            type="submit"
                                            class="btn-cancelar"
                                        >
                                            Cancelar
                                        </button>

                                    </form>

                                <?php endif; ?>

                            </article>

                        <?php endforeach; ?>

                    </div>

                <?php else: ?>

                    <!-- Mensagem exibida quando não existem agendamentos. -->
                    <p class="agenda-dia-vazia">
                        Não há agendamentos registrados para esta data.
                    </p>

                <?php endif; ?>

                <!-- Título da lista de bloqueios. -->
                <h4>Bloqueios do dia</h4>

                <!-- Verifica se existem bloqueios naquela data. -->
                <?php if (!empty($bloqueios[$dia_selecionado])): ?>

                    <div class="lista-detalhes-bloqueios">

                        <!-- Percorre os bloqueios da data. -->
                        <?php foreach ($bloqueios[$dia_selecionado] as $bloqueio_detalhe):

                            // Obtém somente hora e minuto do início.
                            $inicio_detalhe = substr(
                                $bloqueio_detalhe["horario_inicio"] ?? "00:00",
                                0,
                                5
                            );

                            // Obtém somente hora e minuto do fim.
                            $fim_detalhe = substr(
                                $bloqueio_detalhe["horario_fim"] ?? "23:59",
                                0,
                                5
                            );

                            // Verifica se o bloqueio representa o dia inteiro.
                            $dia_inteiro_detalhe = (
                                $inicio_detalhe === "00:00" &&
                                ($fim_detalhe === "23:59" || $fim_detalhe === "00:00")
                            );

                        ?>

                            <!-- Item visual de um bloqueio. -->
                            <article class="detalhe-bloqueio-item">

                                <!-- Exibe o período do bloqueio. -->
                                <strong>

                                    <?= $dia_inteiro_detalhe
                                        ? "Dia inteiro"
                                        : htmlspecialchars($inicio_detalhe . " às " . $fim_detalhe)
                                    ?>

                                </strong>

                                <!-- Exibe o motivo do bloqueio. -->
                                <span>
                                    Motivo:
                                    <?= htmlspecialchars(
                                        $bloqueio_detalhe["motivo"] ?? "Não informado"
                                    ) ?>
                                </span>

                            </article>

                        <?php endforeach; ?>

                    </div>

                <?php else: ?>

                    <!-- Mensagem caso não existam bloqueios. -->
                    <p class="agenda-dia-vazia">
                        Não há bloqueios registrados para esta data.
                    </p>

                <?php endif; ?>

            </section>

        <?php endif; ?>

    </div>

    <!-- =================================================
         ABA 2 — HORÁRIOS
         ================================================= -->

    <div class="aba-conteudo <?= $aba === 'horarios' ? 'visivel' : '' ?>">

        <!-- Container do formulário de horários. -->
        <div class="form-horarios">

            <!-- Título da configuração. -->
            <h3>Meus Horários de Atendimento</h3>

            <!-- Explicação para a profissional. -->
            <p>
                Configure os dias da semana que você atende (padrão).
                Para atender um dia excepcional ou folgar apenas um dia,
                use as abas ao lado.
            </p>

            <!-- Formulário responsável por salvar os horários. -->
            <form
                method="POST"
                action="?mes=<?= $mes ?>&ano=<?= $ano ?>&aba=horarios"
            >

                <!-- Define a ação que o PHP deverá executar. -->
                <input
                    type="hidden"
                    name="acao"
                    value="salvar_horarios"
                >

                <?php

                // Nomes dos sete dias da semana.
                $nomes_dias = [
                    "Domingo",
                    "Segunda-feira",
                    "Terça-feira",
                    "Quarta-feira",
                    "Quinta-feira",
                    "Sexta-feira",
                    "Sábado"
                ];

                // Percorre todos os dias.
                foreach ($nomes_dias as $num => $nome):

                    // Recupera as configurações daquele dia.
                    $h = $horarios_dia[$num];

                    // Define se o checkbox deverá aparecer marcado.
                    $chk = (
                        (int)($h["trabalha"] ?? 0) === 1
                    ) ? "checked" : "";

                ?>

                    <!-- Linha correspondente a um dia da semana. -->
                    <div class="linha-dia">

                        <!-- Nome do dia. -->
                        <strong>
                            <?= $nome ?>:
                        </strong>

                        <!-- Checkbox que indica se trabalha naquele dia. -->
                        <label>

                            <input
                                type="checkbox"
                                name="trabalha[<?= $num ?>]"
                                value="1"
                                <?= $chk ?>
                            >

                            Trabalha

                        </label>

                        <!-- Texto do horário de entrada. -->
                        <span>Entrada:</span>

                        <!-- Campo para horário inicial. -->
                        <input
                            type="time"
                            name="horario_inicio[<?= $num ?>]"
                            value="<?= htmlspecialchars($h["horario_inicio"]) ?>"
                        >

                        <!-- Texto do último atendimento. -->
                        <span>Último atend.:</span>

                        <!-- Campo para último horário. -->
                        <input
                            type="time"
                            name="ultimo_horario[<?= $num ?>]"
                            value="<?= htmlspecialchars($h["ultimo_horario"]) ?>"
                        >

                        <!-- Texto do intervalo de almoço. -->
                        <span>Almoço:</span>

                        <!-- Início do almoço. -->
                        <input
                            type="time"
                            name="inicio_almoco[<?= $num ?>]"
                            value="<?= htmlspecialchars($h["inicio_almoco"]) ?>"
                        >

                        <!-- Separador visual. -->
                        <span>às</span>

                        <!-- Fim do almoço. -->
                        <input
                            type="time"
                            name="fim_almoco[<?= $num ?>]"
                            value="<?= htmlspecialchars($h["fim_almoco"]) ?>"
                        >

                    </div>

                <?php endforeach; ?>

                <!-- Botão que envia o formulário. -->
                <button
                    type="submit"
                    class="btn btn-primario"
                >
                    Salvar Horários Padrão
                </button>

            </form>

        </div>

    </div>

    <!-- =================================================
         ABA 3 — BLOQUEIOS
         ================================================= -->

    <div class="aba-conteudo <?= $aba === 'bloqueios' ? 'visivel' : '' ?>">

        <!-- =================================================
             AVISO DE AGENDAMENTOS CONFLITANTES
             ================================================= -->

        <!-- Só aparece quando existem agendamentos conflitantes. -->
        <?php if (!empty($agendamentos_conflitantes)): ?>

            <div
                class="alerta alerta-erro"
                style="margin-bottom: 20px;"
            >

                <!-- Título do aviso. -->
                <h3>
                    Existem agendamentos neste período!
                </h3>

                <!-- Explica o que acontecerá se confirmar. -->
                <p>
                    Se confirmar o bloqueio, os agendamentos pendentes
                    abaixo serão cancelados.
                </p>

                <!-- Lista os agendamentos conflitantes. -->
                <ul>

                    <?php foreach ($agendamentos_conflitantes as $ag): ?>

                        <li>

                            <!-- Horário do agendamento. -->
                            <strong>
                                <?= htmlspecialchars(
                                    substr($ag["horario"], 0, 5)
                                ) ?>
                            </strong>

                            —

                            <!-- Nome da cliente. -->
                            <?= htmlspecialchars(
                                $ag["nome_cliente"] ?? "Cliente não identificada"
                            ) ?>

                            —

                            <!-- Nome do serviço. -->
                            <?= htmlspecialchars(
                                $ag["nome_servico"] ?? "Serviço não informado"
                            ) ?>

                        </li>

                    <?php endforeach; ?>

                </ul>

                <!-- Pergunta de confirmação. -->
                <p>
                    Deseja realmente cancelar esses agendamentos e registrar o bloqueio?
                </p>

                <!-- Formulário de confirmação. -->
                <form
                    method="POST"
                    action="?mes=<?= $mes ?>&ano=<?= $ano ?>&aba=bloqueios"
                    style="display:flex; gap:10px; flex-wrap:wrap;"
                >

                    <!-- Define a ação de confirmação. -->
                    <input
                        type="hidden"
                        name="acao"
                        value="confirmar_bloqueio_com_cancelamento"
                    >

                    <!-- Botão que confirma o cancelamento. -->
                    <button
                        type="submit"
                        class="btn btn-perigo"
                    >
                        Confirmar e cancelar agendamentos
                    </button>

                </form>

                <!-- Formulário para desistir da confirmação. -->
                <form
                    method="POST"
                    action="?mes=<?= $mes ?>&ano=<?= $ano ?>&aba=bloqueios"
                    style="margin-top:10px;"
                >

                    <!-- Define a ação de cancelamento da confirmação. -->
                    <input
                        type="hidden"
                        name="acao"
                        value="cancelar_confirmacao_bloqueio"
                    >

                    <!-- Mantém os agendamentos existentes. -->
                    <button
                        type="submit"
                        class="btn btn-secundario"
                    >
                        Não, manter os agendamentos
                    </button>

                </form>

            </div>

        <?php endif; ?>

        <!-- =================================================
             FORMULÁRIO DE BLOQUEIO
             ================================================= -->

        <div class="form-bloquear">

            <!-- Título do formulário. -->
            <h3>
                Bloquear Dia ou Período Específico
            </h3>

            <!-- Explicação do funcionamento do bloqueio. -->
            <p>
                Bloqueia apenas ESTA data — não afeta outros dias da semana.
                Ideal para folgas, feriados ou compromissos únicos.
            </p>

            <!-- Formulário de criação do bloqueio. -->
            <form
                method="POST"
                action="?mes=<?= $mes ?>&ano=<?= $ano ?>&aba=bloqueios"
            >

                <!-- Define a ação que será executada. -->
                <input
                    type="hidden"
                    name="acao"
                    value="bloquear_dia"
                >

                <!-- Organização dos campos em grade. -->
                <div class="form-grid">

                    <!-- Campo da data. -->
                    <div class="form-grupo">

                        <label>
                            Data para bloquear *
                        </label>

                        <input
                            type="date"
                            name="data_bloqueio"
                            required
                        >

                    </div>

                    <!-- Campo que define o tipo de bloqueio. -->
                    <div class="form-grupo">

                        <label>
                            Tipo de Bloqueio
                        </label>

                        <select
                            name="tipo_bloqueio"
                            id="tipo_bloqueio"
                            onchange="alternarTipoBloqueio(this.value)"
                        >

                            <!-- Opção de bloquear o dia inteiro. -->
                            <option value="dia_inteiro">
                                Dia Inteiro
                            </option>

                            <!-- Opção de bloquear apenas um período. -->
                            <option value="horario">
                                Apenas um Horário
                            </option>

                        </select>

                    </div>

                    <!-- Campos de horário.
                         Inicialmente ficam escondidos. -->
                    <div
                        class="form-grupo"
                        id="campos_horario"
                        style="display:none;"
                    >

                        <!-- Horário inicial do bloqueio. -->
                        <label>
                            Horário de:
                        </label>

                        <input
                            type="time"
                            name="horario_inicio_bloqueio"
                        >

                        <!-- Horário final do bloqueio. -->
                        <label>
                            até:
                        </label>

                        <input
                            type="time"
                            name="horario_fim_bloqueio"
                        >

                    </div>

                    <!-- Campo do motivo do bloqueio. -->
                    <div class="form-grupo">

                        <label>
                            Motivo
                        </label>

                        <input
                            type="text"
                            name="motivo"
                            value="Folga"
                        >

                    </div>

                </div>

                <!-- Botão que envia o bloqueio. -->
                <button
                    type="submit"
                    class="btn btn-perigo"
                    style="margin-top:15px;"
                >
                    Bloquear Este Dia/Período
                </button>

            </form>

        </div>

        <!-- =================================================
             LISTA DE BLOQUEIOS REGISTRADOS
             ================================================= -->

        <!-- Só mostra a tabela quando existem bloqueios. -->
        <?php if (!empty($bloqueios)): ?>

            <div
                class="form-horarios"
                style="margin-top:20px;"
            >

                <!-- Título da tabela. -->
                <h3>
                    Bloqueios deste Mês
                </h3>

                <!-- Tabela de bloqueios. -->
                <table>

                    <!-- Cabeçalho da tabela. -->
                    <thead>

                        <tr>

                            <th>
                                Data
                            </th>

                            <th>
                                Período
                            </th>

                            <th>
                                Motivo
                            </th>

                            <th>
                                Ação
                            </th>

                        </tr>

                    </thead>

                    <!-- Corpo da tabela. -->
                    <tbody>

                        <?php

                        // Percorre cada data que possui bloqueios.
                        foreach ($bloqueios as $data_bloq => $lista):

                            // Percorre os bloqueios daquela data.
                            foreach ($lista as $b):

                                // Considera somente horas e minutos.
                                $in = substr(
                                    $b["horario_inicio"] ?? "00:00",
                                    0,
                                    5
                                );

                                // Obtém hora e minuto do fim.
                                $fi = substr(
                                    $b["horario_fim"] ?? "23:59",
                                    0,
                                    5
                                );

                                // Define o texto do período.
                                $periodo = (
                                    $in === "00:00" &&
                                    ($fi === "23:59" || $fi === "00:00")
                                )
                                    ? "Dia inteiro"
                                    : "$in às $fi";

                        ?>

                            <!-- Linha da tabela. -->
                            <tr>

                                <!-- Data formatada para o padrão brasileiro. -->
                                <td>
                                    <?= date(
                                        "d/m/Y",
                                        strtotime($data_bloq)
                                    ) ?>
                                </td>

                                <!-- Período do bloqueio. -->
                                <td>
                                    <?= htmlspecialchars($periodo) ?>
                                </td>

                                <!-- Motivo do bloqueio. -->
                                <td>
                                    <?= htmlspecialchars($b["motivo"]) ?>
                                </td>

                                <!-- Ação de exclusão. -->
                                <td>

                                    <!-- Formulário responsável pela exclusão. -->
                                    <form
                                        method="POST"
                                        style="display:inline;"
                                    >

                                        <!-- Define a ação. -->
                                        <input
                                            type="hidden"
                                            name="acao"
                                            value="excluir_bloqueio"
                                        >

                                        <!-- Envia o ID do bloqueio. -->
                                        <input
                                            type="hidden"
                                            name="bloqueio_id"
                                            value="<?= (int)$b["id"] ?>"
                                        >

                                        <!-- Botão para excluir. -->
                                        <button
                                            type="submit"
                                            class="btn-cancelar"
                                            onclick="return confirm('Excluir bloqueio?')"
                                        >
                                            Excluir
                                        </button>

                                    </form>

                                </td>

                            </tr>

                        <?php

                            endforeach;

                        endforeach;

                        ?>

                    </tbody>

                </table>

            </div>

        <?php endif; ?>

    </div>

    <!-- =================================================
         ABA 4 — LIBERAÇÃO
         ================================================= -->

    <div class="aba-conteudo <?= $aba === 'liberar' ? 'visivel' : '' ?>">

        <!-- Container principal da liberação. -->
        <div class="form-horarios">

            <!-- Título. -->
            <h3>
                Liberar Agendamentos
            </h3>

            <!-- Explicação da funcionalidade. -->
            <p>
                Selecione os dias diretamente no calendário ou use a opção
                para liberar o mês inteiro de uma vez.
            </p>

            <!-- =================================================
                 NAVEGAÇÃO ENTRE MESES
                 ================================================= -->

            <div class="navegacao-meses-liberar">

                <!-- Mês anterior. -->
                <a
                    href="?mes=<?= $mes_anterior ?>&ano=<?= $ano_anterior ?>&aba=liberar"
                    class="btn-navega"
                >
                    ◀
                </a>

                <!-- Mês atual selecionado. -->
                <strong>
                    <?= $nome_mes ?> / <?= $ano ?>
                </strong>

                <!-- Próximo mês. -->
                <a
                    href="?mes=<?= $proximo_mes ?>&ano=<?= $ano_proximo ?>&aba=liberar"
                    class="btn-navega"
                >
                    ▶
                </a>

            </div>

            <!-- =================================================
                 SELEÇÃO DO MÊS INTEIRO
                 ================================================= -->

            <div class="acao-mes-inteiro">

                <!-- Seleciona todos os dias disponíveis. -->
                <button
                    type="button"
                    id="btnSelecionarMes"
                    class="btn btn-primario"
                >
                    Selecionar Mês Inteiro
                </button>

                <!-- Desmarca todos os dias disponíveis. -->
                <button
                    type="button"
                    id="btnDesmarcarMes"
                    class="btn btn-secundario"
                >
                    Desmarcar Todos os Dias
                </button>

            </div>

            <!-- =================================================
                 CALENDÁRIO DE SELEÇÃO
                 ================================================= -->

            <!-- Formulário responsável pelas liberações. -->
            <form
                method="POST"
                action="?mes=<?= $mes ?>&ano=<?= $ano ?>&aba=liberar"
                id="formLiberaDias"
            >

                <!-- Cabeçalho dos dias da semana. -->
                <div class="calendario-liberar-cabecalho">

                    <!-- Percorre os nomes dos dias. -->
                    <?php foreach ($nomes_dias_semana as $ds): ?>

                        <div class="dia-semana-cab">

                            <?= $ds ?>

                        </div>

                    <?php endforeach; ?>

                </div>

                <!-- Grade do calendário. -->
                <div
                    class="calendario-liberar-grade"
                    id="gradeCalendario"
                >

                    <?php

                    // Cria uma data representando o primeiro dia do mês.
                    $dt = new DateTime("$ano-$mes-01");

                    // Volta até o domingo da semana em que o mês começa.
                    $dt->modify('-' . $dt->format('w') . ' days');

                    // Cria 42 células.
                    // Isso permite representar até seis semanas.
                    for ($i = 0; $i < 42; $i++):

                        // Data correspondente à célula atual.
                        $data_celula = $dt->format("Y-m-d");

                        // Número do dia.
                        $numero_dia = $dt->format("j");

                        // Número do mês da célula.
                        $mes_celula = (int)$dt->format("n");

                        // Verifica se a data já passou.
                        $passado = ($data_celula < $hoje_data);

                        // Verifica se a data já está liberada.
                        $liberado = isset($dias_liberados[$data_celula]);

                        // Verifica se a célula pertence ao mês selecionado.
                        $fora_mes = ($mes_celula !== (int)$mes);

                    ?>

                        <!-- Célula individual do calendário. -->
                        <div
                            class="celula-lib
                            <?= $fora_mes ? 'fora-mes' : '' ?>
                            <?= $passado ? 'passado' : '' ?>
                            <?= $liberado ? 'selecionado' : '' ?>"
                            data-mes-atual="<?= $mes_celula === (int)$mes ? 'sim' : 'nao' ?>"
                            data-passado="<?= $passado ? 'sim' : 'nao' ?>"
                        >

                            <!--
                                Somente dias pertencentes ao mês atual
                                recebem checkbox.
                            -->
                            <?php if (!$fora_mes): ?>

                                <label class="celula-lib-label">

                                    <!--
                                        Checkbox que envia a data selecionada
                                        para o PHP.
                                    -->
                                    <input
                                        type="checkbox"
                                        name="dias[]"
                                        value="<?= $data_celula ?>"
                                        class="check-dia-lib"
                                        <?= $liberado ? 'checked' : '' ?>
                                        <?= $passado ? 'disabled' : '' ?>
                                    >

                                    <!-- Número do dia mostrado visualmente. -->
                                    <span class="numero-lib">
                                        <?= $numero_dia ?>
                                    </span>

                                </label>

                            <?php endif; ?>

                        </div>

                    <?php

                        // Avança para o próximo dia.
                        $dt->modify("+1 day");

                    // Finaliza o loop das 42 células.
                    endfor;

                    ?>

                </div>

                <!-- Botões das ações de liberação. -->
                <div class="botoes-lib-acao">

                    <!-- Envia a ação de liberar os dias selecionados. -->
                    <button
                        type="submit"
                        name="acao"
                        value="liberar_dias"
                        class="btn btn-primario"
                    >
                        Liberar Selecionados
                    </button>

                    <!-- Envia a ação para remover liberações. -->
                    <button
                        type="submit"
                        name="acao"
                        value="remover_liberacao"
                        class="btn btn-perigo"
                    >
                        Remover Liberação
                    </button>

                </div>

            </form>

        </div>

        <!-- =================================================
             LISTA DE LIBERAÇÕES ATIVAS
             ================================================= -->

        <!-- Mostra a tabela somente quando existem liberações. -->
        <?php if (!empty($liberacoes)): ?>

            <div
                class="form-horarios"
                style="margin-top:30px;"
            >

                <!-- Título da tabela. -->
                <h3>
                    Liberações Ativas
                </h3>

                <!-- Tabela das liberações. -->
                <table>

                    <!-- Cabeçalho. -->
                    <thead>

                        <tr>

                            <th>
                                Tipo
                            </th>

                            <th>
                                Período
                            </th>

                            <th>
                                De
                            </th>

                            <th>
                                Até
                            </th>

                        </tr>

                    </thead>

                    <!-- Corpo da tabela. -->
                    <tbody>

                        <!-- Percorre as liberações cadastradas. -->
                        <?php foreach ($liberacoes as $l):

                            // Por padrão, considera o registro como "Dia".
                            $texto_tipo = "Dia";

                            // Se o tipo for "mes", apresenta "Mês".
                            if ($l["tipo"] === "mes") {

                                $texto_tipo = "Mês";

                            // Se o tipo for "semana", apresenta "Dias".
                            } elseif ($l["tipo"] === "semana") {

                                $texto_tipo = "Dias";
                            }

                        ?>

                            <!-- Linha da liberação. -->
                            <tr>

                                <!-- Tipo da liberação. -->
                                <td>
                                    <?= $texto_tipo ?>
                                </td>

                                <!-- Valor armazenado na liberação. -->
                                <td>
                                    <?= htmlspecialchars($l["valor"]) ?>
                                </td>

                                <!-- Data inicial. -->
                                <td>
                                    <?= date(
                                        "d/m/Y",
                                        strtotime($l["data_inicio"])
                                    ) ?>
                                </td>

                                <!-- Data final. -->
                                <td>
                                    <?= date(
                                        "d/m/Y",
                                        strtotime($l["data_fim"])
                                    ) ?>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php endif; ?>

    </div>

</main>

<!-- =====================================================
     JAVASCRIPT
     ===================================================== -->

<script>

// =========================================================
// ALTERNAR CAMPOS DO TIPO DE BLOQUEIO
// =========================================================

// Esta função controla a exibição dos campos
// de horário do formulário de bloqueio.
function alternarTipoBloqueio(tipo) {

    // Localiza no HTML o container dos campos de horário.
    const campos = document.getElementById("campos_horario");

    // Confirma se o elemento realmente existe.
    if (campos) {

        // Se o tipo for "horario", mostra os campos.
// Caso contrário, mantém os campos escondidos.
        campos.style.display = tipo === "horario" ? "block" : "none";
    }
}

// =========================================================
// FUNÇÕES DOS CALENDÁRIOS
// =========================================================

// Aguarda o carregamento completo do HTML antes
// de executar os comandos JavaScript.
document.addEventListener("DOMContentLoaded", function() {

    // Localiza o botão responsável por selecionar o mês inteiro.
    const btnSelecionarMes = document.getElementById("btnSelecionarMes");

    // Localiza o botão responsável por desmarcar os dias.
    const btnDesmarcarMes = document.getElementById("btnDesmarcarMes");

    // =====================================================
    // SELECIONAR MÊS INTEIRO
    // =====================================================

    // Verifica se o botão existe na página atual.
    if (btnSelecionarMes) {

        // Adiciona um evento de clique ao botão.
        btnSelecionarMes.addEventListener("click", function() {

            // Procura todos os checkboxes de dias.
            document.querySelectorAll(".check-dia-lib").forEach(function(checkbox) {

                // Não altera os dias que estão desabilitados,
                // como datas que já passaram.
                if (!checkbox.disabled) {

                    // Marca o checkbox.
                    checkbox.checked = true;

                    // Localiza a célula correspondente ao checkbox.
                    const celula = checkbox.closest(".celula-lib");

                    // Se a célula existir, adiciona a classe visual.
                    if (celula) {

                        celula.classList.add("selecionado");
                    }
                }
            });
        });
    }

    // =====================================================
    // DESMARCAR TODOS OS DIAS
    // =====================================================

    // Verifica se o botão existe.
    if (btnDesmarcarMes) {

        // Adiciona evento de clique.
        btnDesmarcarMes.addEventListener("click", function() {

            // Percorre todos os checkboxes.
            document.querySelectorAll(".check-dia-lib").forEach(function(checkbox) {

                // Ignora checkboxes desabilitados.
                if (!checkbox.disabled) {

                    // Desmarca o checkbox.
                    checkbox.checked = false;

                    // Localiza a célula correspondente.
                    const celula = checkbox.closest(".celula-lib");

                    // Remove a classe visual de selecionado.
                    if (celula) {

                        celula.classList.remove("selecionado");
                    }
                }
            });
        });
    }

    // =====================================================
    // ATUALIZAR VISUAL DA SELEÇÃO
    // =====================================================

    // Escuta mudanças nos elementos da página.
    document.addEventListener("change", function(e) {

        // Verifica se o elemento alterado é um checkbox de dia.
        if (e.target.classList.contains("check-dia-lib")) {

            // Localiza a célula daquele checkbox.
            const celula = e.target.closest(".celula-lib");

            // Se a célula existir, atualiza sua classe visual.
            if (celula) {

                // Adiciona "selecionado" quando marcado
                // e remove quando desmarcado.
                celula.classList.toggle(
                    "selecionado",
                    e.target.checked
                );
            }
        }
    });

    // =====================================================
    // ABRIR DETALHES DO DIA
    // =====================================================

    // Localiza todos os dias clicáveis do calendário principal.
    document.querySelectorAll(".calendario-dia-clicavel").forEach(function(dia) {

        // Adiciona evento de clique.
        dia.addEventListener("click", function(e) {

            // Verifica se o clique ocorreu em algum elemento
            // que deveria manter seu comportamento original.
            if (
                e.target.closest(
                    "button, a, form, input, select, textarea, label"
                )
            ) {

                // Não executa o redirecionamento do calendário.
                return;
            }

            // Redireciona para a URL armazenada no atributo data-dia-url.
            window.location.href = dia.dataset.diaUrl;
        });

        // =================================================
        // ACESSIBILIDADE PELO TECLADO
        // =================================================

        // Permite que o dia também seja aberto usando teclado.
        dia.addEventListener("keydown", function(e) {

            // Verifica se a tecla pressionada foi Enter ou Espaço.
            if (e.key === "Enter" || e.key === " ") {

                // Impede o comportamento padrão da tecla Espaço.
                e.preventDefault();

                // Abre os detalhes do dia.
                window.location.href = dia.dataset.diaUrl;
            }
        });
    });

});

</script>

</body>
</html>