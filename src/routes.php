<?php

use App\Modem\ZteModemClient;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;

return function (App $app, array $settings) {
  /**
   * Função auxiliar para criar respostas JSON
   */
  $jsonResponse = function (Response $response, int $status, array $data): Response {
    $response->getBody()->write(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
  };

  // --- ROTA DE STATUS ---
  $app->get('/', function (Request $request, Response $response) use ($jsonResponse) {
    $data = [
      'status' => 'ok',
      'message' => 'API ZTE Modem está online.'
    ];
    return $jsonResponse($response, 200, $data);
  });

  // ===================================================================
  // ROTA DE MANUTENÇÃO (para o CRON Job)
  // ===================================================================
  // Note que a função anônima já tem acesso a $settings e $jsonResponse
  // graças ao 'use'
  $app->post('/maintenance/daily-reboot-and-cleanup', function (Request $request, Response $response) use ($settings, $jsonResponse) {

    // 1. Validar o token de segurança
    $token = $request->getQueryParams()['token'] ?? null;
    if (empty($token) || $token !== $settings['maintenance_token']) {
      return $jsonResponse($response, 401, [
        'status' => 'error',
        'message' => 'Acesso não autorizado. Token inválido ou ausente.'
      ]);
    }

    // ... (o restante da lógica desta rota permanece exatamente o mesmo) ...
    $client = null;
    try {
      $client = new ZteModemClient($settings['modem_ip'], $settings['modem_password']);
      $client->login();
      $smsCleanupResult = $client->deleteMessages('*');
      $rebootResult = $client->rebootDevice();
      return $jsonResponse($response, 200, [
        'status' => 'success',
        'message' => 'Manutenção diária executada com sucesso.',
        'details' => ['sms_cleanup' => $smsCleanupResult, 'reboot_initiated' => $rebootResult],
        'timestamp' => date('c')
      ]);
    } catch (\Exception $e) {
      return $jsonResponse($response, 503, [
        'status' => 'error',
        'message' => 'Falha ao executar a rotina de manutenção.',
        'details' => $e->getMessage(),
        'timestamp' => date('c')
      ]);
    } finally {
      if ($client) {
        $client->logout();
      }
    }
  });


  // --- GRUPO DE ROTAS DO MODEM (protegido por autenticação) ---
  $app->group('/modem', function ($group) use ($jsonResponse) {

    // --- ROTA PARA ENVIAR SMS ---
    $group->post('/sms/send', function (Request $request, Response $response) use ($jsonResponse) {
      $params = $request->getParsedBody();
      $mobileNumber = $params['mobileNumber'] ?? null;
      $message = $params['message'] ?? null;
      $messageId = $params['messageId'] ?? uniqid();

      if (empty($mobileNumber) || empty($message)) {
        return $jsonResponse($response, 400, [
          'status' => 'error',
          'message' => 'Parâmetros obrigatórios ausentes: mobileNumber, message.',
          'messageId' => $messageId
        ]);
      }

      var_dump($params);
      die();

      // A instância do cliente é obtida do middleware de autenticação
      /** @var ZteModemClient $client */
      $client = $request->getAttribute('modemClient');

      try {
        $client->sendMessage($mobileNumber, $message);

        return $jsonResponse($response, 200, [
          'status' => 'success',
          'message' => 'SMS enviado para a fila do modem.',
          'messageId' => $messageId,
          'recipient' => $mobileNumber
        ]);
      } catch (\Exception $e) {
        return $jsonResponse($response, 500, [
          'status' => 'error',
          'message' => 'Falha na comunicação com o modem.',
          'details' => $e->getMessage(),
          'messageId' => $messageId
        ]);
      }
    });

    // --- ROTA PARA REINICIAR O MODEM ---
    $group->post('/reboot', function (Request $request, Response $response) use ($jsonResponse) {
      /** @var ZteModemClient $client */
      $client = $request->getAttribute('modemClient');
      try {
        $client->rebootDevice();
        return $jsonResponse($response, 200, [
          'status' => 'success',
          'message' => 'Comando de reinicialização enviado.'
        ]);
      } catch (\Exception $e) {
        return $jsonResponse($response, 500, [
          'status' => 'error',
          'message' => 'Falha ao enviar comando de reinicialização.',
          'details' => $e->getMessage()
        ]);
      }
    });

    // --- ROTA PARA LER MENSAGENS ---
    $group->post('/sms/list', function (Request $request, Response $response) use ($jsonResponse) {
      /** @var ZteModemClient $client */
      $client = $request->getAttribute('modemClient');
      try {
        $messages = $client->getMessages();
        // Opcional: decodificar as mensagens para facilitar a leitura
        $decodedMessages = array_map(function ($msg) use ($client) {
          $msg['content_decoded'] = $client->decodeMessageBody($msg['content']);
          return $msg;
        }, $messages);

        return $jsonResponse($response, 200, [
          'status' => 'success',
          'count' => count($decodedMessages),
          'messages' => $decodedMessages
        ]);
      } catch (\Exception $e) {
        return $jsonResponse($response, 500, [
          'status' => 'error',
          'message' => 'Falha ao buscar mensagens.',
          'details' => $e->getMessage()
        ]);
      }
    });
  })->add(function (Request $request, $handler) use ($settings, $jsonResponse) {
    // --- MIDDLEWARE DE AUTENTICAÇÃO E LOGIN NO MODEM ---
    $params = $request->getParsedBody();
    $username = $params['username'] ?? null;
    $password = $params['password'] ?? null;

    // 1. Validar credenciais da API
    if ($username !== $settings['api_username'] || $password !== $settings['api_password']) {
      return $jsonResponse($handler->handle($request)->withStatus(401), 401, [
        'status' => 'error',
        'message' => 'Credenciais da API inválidas.'

      ]);
    }

    // 2. Tentar login no modem
    $client = new ZteModemClient($settings['modem_ip'], $settings['modem_password']);
    try {
      $client->login();
      // Adiciona o cliente logado ao objeto Request para ser usado nas rotas
      $request = $request->withAttribute('modemClient', $client);

      // Continua para a rota
      $response = $handler->handle($request);

      return $response;
    } catch (\Exception $e) {
      return $jsonResponse($handler->handle($request)->withStatus(503), 503, [
        'status' => 'error',
        'message' => 'Não foi possível autenticar no modem.',
        'details' => $e->getMessage()
      ]);
    } finally {
      // Garante o logout do modem no final da requisição
      if (isset($client)) {
        $client->logout();
      }
    }
  });
};
