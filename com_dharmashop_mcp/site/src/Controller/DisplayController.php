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
            http_response_code(503);
            $app->setHeader('status', 503, true);
            $app->setHeader('Content-Type', 'application/json; charset=utf-8', true);
            echo (new JsonRpcResponse(null, null, new JsonRpcError(JsonRpcError::INTERNAL_ERROR, 'MCP server is disabled')))->toJson();
            $app->close();
        }

        // Читаем входное тело запроса
        $rawBody = file_get_contents('php://input');
        if ($rawBody === false || trim($rawBody) === '') {
            http_response_code(400);
            $app->setHeader('status', 400, true);
            $app->setHeader('Content-Type', 'application/json; charset=utf-8', true);
            echo (new JsonRpcResponse(null, null, new JsonRpcError(JsonRpcError::PARSE_ERROR, 'Empty request body')))->toJson();
            $app->close();
        }

        try {
            $request = JsonRpcRequest::fromRaw($rawBody);
        } catch (\InvalidArgumentException $e) {
            http_response_code(400);
            $app->setHeader('status', 400, true);
            $app->setHeader('Content-Type', 'application/json; charset=utf-8', true);
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
            $httpCode = $auth->errorCode > 0 ? $auth->errorCode : 401;
            http_response_code($httpCode);
            $app->setHeader('status', $httpCode, true);
            $app->setHeader('Content-Type', 'application/json; charset=utf-8', true);
            if ($httpCode === 401) {
                header('WWW-Authenticate: Bearer');
                $app->setHeader('WWW-Authenticate', 'Bearer', true);
            }
            $rpcCode = $httpCode === 403 ? JsonRpcError::FORBIDDEN : JsonRpcError::UNAUTHORIZED;
            echo (new JsonRpcResponse($request->id, null, new JsonRpcError($rpcCode, $auth->errorMessage ?? 'Unauthorized')))->toJson();
            $app->close();
        }

        // Диспетчеризация сервера
        $registry = new ToolRegistry();
        $logger = new AuditLogger($db);
        $server = new McpServer($registry, $logger);

        // Уведомления JSON-RPC не требуют ответа: отвечаем HTTP 202 Accepted без тела
        if ($request->isNotification()) {
            $server->handle($request, $auth, $clientIp, $userAgent);
            http_response_code(202);
            $app->setHeader('status', 202, true);
            $app->close();
        }

        $response = $server->handle($request, $auth, $clientIp, $userAgent);

        if ($response->error !== null) {
            if ($response->error->code === JsonRpcError::FORBIDDEN) {
                http_response_code(403);
                $app->setHeader('status', 403, true);
            } elseif ($response->error->code === JsonRpcError::UNAUTHORIZED) {
                http_response_code(401);
                $app->setHeader('status', 401, true);
                header('WWW-Authenticate: Bearer');
                $app->setHeader('WWW-Authenticate', 'Bearer', true);
            } elseif ($response->error->code === JsonRpcError::INVALID_REQUEST || $response->error->code === JsonRpcError::PARSE_ERROR) {
                http_response_code(400);
                $app->setHeader('status', 400, true);
            }
        }

        $app->setHeader('Content-Type', 'application/json; charset=utf-8', true);
        echo $response->toJson();
        $app->close();
    }
}
