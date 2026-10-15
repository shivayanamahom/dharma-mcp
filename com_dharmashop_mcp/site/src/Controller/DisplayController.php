<?php
declare(strict_types=1);

namespace Dharma\Component\DharmaShopMcp\Site\Controller;

\defined('_JEXEC') or die;

use Dharma\Component\DharmaShopMcp\Site\Audit\AuditLogger;
use Dharma\Component\DharmaShopMcp\Site\Auth\TokenAuthenticator;
use Dharma\Component\DharmaShopMcp\Site\Protocol\JsonRpcError;
use Dharma\Component\DharmaShopMcp\Site\Protocol\JsonRpcRequest;
use Dharma\Component\DharmaShopMcp\Site\Protocol\JsonRpcResponse;
use Dharma\Component\DharmaShopMcp\Site\Server\McpServer;
use Dharma\Component\DharmaShopMcp\Site\Tool\ToolRegistry;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Controller\BaseController;

class DisplayController extends BaseController
{
    public function display($cachable = false, $urlparams = [])
    {
        $app = $this->app;
        $db = Factory::getDbo();

        // Проверяем включён ли компонент
        $params = ComponentHelper::getParams('com_dharmashop_mcp');
        if ((int) $params->get('enabled', 1) !== 1) {
            $app->setHeader('Status', '503 Service Unavailable', true);
            echo (new JsonRpcResponse(null, null, new JsonRpcError(JsonRpcError::INTERNAL_ERROR, 'MCP server is disabled')))->toJson();
            $app->close();
        }

        $app->setHeader('Content-Type', 'application/json; charset=utf-8', true);

        // Читаем входное тело запроса
        $rawBody = file_get_contents('php://input');
        if (empty($rawBody)) {
            $app->setHeader('Status', '400 Bad Request', true);
            echo (new JsonRpcResponse(null, null, new JsonRpcError(JsonRpcError::PARSE_ERROR, 'Empty request body')))->toJson();
            $app->close();
        }

        try {
            $request = JsonRpcRequest::fromRaw($rawBody);
        } catch (\InvalidArgumentException $e) {
            $app->setHeader('Status', '400 Bad Request', true);
            echo (new JsonRpcResponse(null, null, new JsonRpcError(JsonRpcError::INVALID_REQUEST, $e->getMessage())))->toJson();
            $app->close();
        }

        // Авторизация
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
        if (!$authHeader && function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? null;
        }

        $clientIp = $_SERVER['REMOTE_ADDR'] ?? null;
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

        $authenticator = new TokenAuthenticator($db);
        $auth = $authenticator->authenticate($authHeader, $clientIp);

        if (!$auth->success) {
            $app->setHeader('Status', '401 Unauthorized', true);
            echo (new JsonRpcResponse($request->id, null, new JsonRpcError(JsonRpcError::UNAUTHORIZED, $auth->errorMessage ?? 'Unauthorized')))->toJson();
            $app->close();
        }

        // Диспетчеризация сервера
        $registry = new ToolRegistry();
        $logger = new AuditLogger($db);
        $server = new McpServer($registry, $logger);

        $response = $server->handle($request, $auth, $clientIp, $userAgent);

        echo $response->toJson();
        $app->close();
    }
}
