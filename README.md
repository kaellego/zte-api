# API de Gerenciamento para Modem ZTE

> Uma API RESTful desenvolvida com Slim Framework para interagir com modems ZTE, permitindo o envio de SMS e a execução de rotinas de manutenção automatizadas.

Este projeto fornece uma interface limpa e segura para controlar funcionalidades de um modem ZTE via HTTP, com uma arquitetura de aplicação profissional, escalável e de fácil manutenção.

## ✨ Funcionalidades

- **Envio de SMS**: Endpoint para enviar mensagens de texto através do chip inserido no modem.
- **Manutenção Automatizada**: Endpoint seguro para reiniciar o modem e limpar todas as mensagens SMS, ideal para ser acionado por tarefas CRON.
- **Arquitetura Moderna**: Utiliza o micro-framework Slim, injeção de dependências (PHP-DI) e uma estrutura de pastas organizada.
- **Configuração Centralizada**: Todas as configurações, incluindo credenciais e tokens, são mantidas separadas da lógica da aplicação.
- **Segurança**: As rotas são protegidas por autenticação (usuário/senha para ações manuais e token secreto para automação).

## 🛠️ Pré-requisitos

Antes de começar, garanta que você tenha os seguintes softwares instalados:

- **PHP 8.0 ou superior**
- **Composer** (Gerenciador de dependências para PHP)
- Um modem ZTE compatível e acessível na rede.

## 🚀 Instalação

Siga os passos abaixo para configurar o projeto em seu ambiente local.

1.  **Clone o repositório:**
    ```bash
    git clone [https://github.com/kaellego/zte-api.git](https://github.com/kaellego/zte-api.git)
    cd zte-api
    ```

2.  **Instale as dependências:**
    Execute o Composer para instalar as bibliotecas necessárias (Slim, PHP-DI, etc.).
    ```bash
    composer install
    ```

## ⚙️ Configuração

A configuração da aplicação é feita no arquivo `config/app.php`. É altamente recomendável que você nunca versione este arquivo com credenciais reais em um repositório público.

1.  **Abra o arquivo de configuração:**
    `config/app.php`

2.  **Ajuste os parâmetros necessários:**

    - `modem.ip`: O endereço IP do seu modem na rede local.
    - `modem.password`: A senha de administrador do modem.
    - `api.username`: O nome de usuário que você deseja usar para proteger o endpoint de envio de SMS.
    - `api.password`: A senha para o usuário da API.
    - `maintenance.token`: **(MUITO IMPORTANTE)** Um token secreto, longo e aleatório para proteger o endpoint de manutenção. **Altere o valor padrão!**

    Exemplo do arquivo `config/app.php`:
    ```php
    <?php
    return [
        // ...
        'modem' => [
            'ip' => '192.168.1.1',
            'password' => 'admin'
        ],
        'api' => [
            'username' => 'meu_usuario_api',
            'password' => 'MinhaSenha@Forte123'
        ],
        'maintenance' => [
            'token' => 'b7a3c9e1f8d2g5h4k6j7l9m1n3p5o8q'
        ]
    ];
    ```

## ▶️ Executando a Aplicação

Este projeto inclui um script no `composer.json` para facilitar a inicialização.

- **Para iniciar o servidor:**
  ```bash
  composer start
  ```

- A aplicação estará disponível em `http://localhost:8080`.

## 🔌 Endpoints da API

Abaixo estão os detalhes de como interagir com os endpoints da API.

---

### Enviar SMS

Envia uma mensagem de texto para um número de telefone específico.

- **URL**: `/api/sms/send`
- **Método**: `POST`
- **Autenticação**: Corpo da requisição (username/password)

**Parâmetros do Corpo (JSON):**

| Parâmetro      | Tipo   | Obrigatório | Descrição                                  |
| -------------- | ------ | ----------- | ------------------------------------------ |
| `username`     | string | Sim         | Usuário da API definido em `config/app.php`. |
| `password`     | string | Sim         | Senha da API definida em `config/app.php`.   |
| `mobileNumber` | string | Sim         | Número de destino (ex: `5562999998888`).   |
| `message`      | string | Sim         | O conteúdo da mensagem de texto.           |

**Exemplo de Requisição (cURL):**
```bash
curl -X POST http://localhost:8080/api/sms/send \
-H "Content-Type: application/json" \
-d '{
    "username": "meu_usuario_api",
    "password": "MinhaSenha@Forte123",
    "mobileNumber": "5562999998888",
    "message": "Olá, esta é uma mensagem de teste!"
}'
```

---

### Manutenção Diária (CRON)

Endpoint projetado para ser chamado por uma tarefa agendada (CRON). Ele limpa todas as mensagens SMS e reinicia o modem.

- **URL**: `/api/maintenance/reboot-and-cleanup`
- **Método**: `POST`
- **Autenticação**: Query Parameter (token)

**Parâmetros de Query:**

| Parâmetro | Tipo   | Obrigatório | Descrição                                        |
| --------- | ------ | ----------- | ------------------------------------------------ |
| `token`   | string | Sim         | Token secreto definido em `config/app.php`. |

**Exemplo de Tarefa CRON (Linux):**
Abra seu `crontab` com `crontab -e` e adicione a linha abaixo para executar a tarefa todos os dias à meia-noite.

```crontab
# Reinicia o modem ZTE e limpa os SMS todos os dias à meia-noite.
0 0 * * * curl -s -X POST "http://localhost:8080/api/maintenance/reboot-and-cleanup?token=b7a3c9e1f8d2g5h4k6j7l9m1n3p5o8q" > /dev/null 2>&1
```

## 📂 Estrutura do Projeto

A estrutura de pastas foi projetada para separar responsabilidades e facilitar a manutenção.

```
.
├── app/          # Contém a lógica principal da aplicação (classes, helpers)
├── bootstrap/    # Scripts de inicialização da aplicação e do contêiner DI
├── config/       # Arquivos de configuração
├── public/       # Ponto de entrada público (index.php)
├── routes/       # Definição das rotas da API e Web
└── vendor/       # Dependências do Composer
```

## ⚠️ Segurança

- **Nunca exponha suas credenciais ou tokens em repositórios públicos.** Utilize o arquivo `.gitignore` para ignorar seus arquivos de configuração locais.
- **O `maintenance.token` deve ser tratado como uma senha.** Garanta que ele seja longo, complexo e conhecido apenas pela sua aplicação e pelo seu serviço de CRON.
- **Evite expor esta API diretamente à internet.** Se necessário, coloque-a atrás de um firewall, VPN ou utilize outros mecanismos de segurança de rede.

## 📄 Licença

Este projeto é distribuído sob a Licença MIT.
