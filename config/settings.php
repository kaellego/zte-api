<?php

// config/settings.php

// Este arquivo retorna um array com todas as configurações da aplicação.
// Mantenha informações sensíveis aqui.
return [
  'api_username' => 'admin',
  'api_password' => 'admin',

  'modem_ip'     => '192.168.1.1',
  'modem_password' => 'admin',

  // Token secreto para a rota de manutenção via CRON.
  // É ESSENCIAL que você mude este valor para uma string longa e aleatória!
  'maintenance_token' => 'admin'
];
