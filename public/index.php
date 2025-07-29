<?php

use Slim\Factory\AppFactory;

// Autoloader do Composer
require __DIR__ . '/../vendor/autoload.php';

// Carrega as configurações do arquivo separado
$settings = require __DIR__ . '/../config/settings.php';

// Cria a instância da aplicação Slim
$app = AppFactory::create();

// Adiciona middleware para parsear o corpo da requisição (JSON, form-data, etc)
$app->addBodyParsingMiddleware();

// Adiciona middleware de roteamento
$app->addRoutingMiddleware();

// Adiciona middleware de tratamento de erros (mostra detalhes em ambiente de dev)
$app->addErrorMiddleware(true, true, true);

// Carrega as definições de rotas do arquivo separado
$routes = require __DIR__ . '/../src/routes.php';

// ALTERE ESTA LINHA: Passe $settings como um segundo argumento
$routes($app, $settings);

// Executa a aplicação
$app->run();
