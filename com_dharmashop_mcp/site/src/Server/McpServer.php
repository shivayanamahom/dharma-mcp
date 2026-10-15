<?php
declare(strict_types=1);

namespace Dharma\Component\DharmaShopMcp\Site\Server;

\defined('_JEXEC') or die;

use Dharma\Component\DharmaShopMcp\Site\Auth\AuthResult;
use Dharma\Component\DharmaShopMcp\Site\Audit\AuditLogger;
use Dharma\Component\DharmaShopMcp\Site\Protocol\JsonRpcError;
use Dharma\Component\DharmaShopMcp\Site\Protocol\JsonRpcRequest;
use Dharma\Component\DharmaShopMcp\Site\Protocol\JsonRpcResponse;
use Dharma\Component\DharmaShopMcp\Site\Tool\ToolRegistry;
use Joomla\CMS\Component\ComponentHelper;

final class McpServer
{
    public function __construct(
        private readonly ToolRegistry $registry,
        private readonly AuditLogger $logger
    ) {}

    public function handle(JsonRpcRequest $request, AuthResult $auth, ?string $clientIp = null, ?string $userAgent = null): JsonRpcResponse
    {
        $startTime = microtime(true);
        $method = $request->method;
        $toolName = null;
        $arguments = null;
        $status = 'ok';
        $errorMessage = null;

        $params = ComponentHelper::getParams('com_dharmashop_mcp');
        $serverMode = (string) $params->get('server_mode', 'drafts');

        try {
            $result = match ($method) {
                'initialize' => $this->handleInitialize($request),
                'ping' => ['status' => 'pong'],
                'tools/list' => ['tools' => $this->registry->getToolsListForAuth($auth, $serverMode)],
                'tools/call' => $this->handleToolCall($request, $auth, $serverMode, $toolName, $arguments),
                default => throw new \InvalidArgumentException('Method not found: ' . $method, JsonRpcError::METHOD_NOT_FOUND),
            };

            $response = JsonRpcResponse::success($request->id, $result);
        } catch (\InvalidArgumentException $e) {
            $status = 'error';
            $errorMessage = $e->getMessage();
            $code = $e->getCode() ?: JsonRpcError::INTERNAL_ERROR;
            $response = JsonRpcResponse::error($request->id, new JsonRpcError($code, $errorMessage));
        } catch (\Throwable $e) {
            $status = 'error';
            $errorMessage = $e->getMessage();
            $response = JsonRpcResponse::error($request->id, new JsonRpcError(JsonRpcError::INTERNAL_ERROR, 'Internal error: ' . $errorMessage));
        } finally {
            $durationMs = (int) round((microtime(true) - $startTime) * 1000);
            $this->logger->log(
                $auth->token?->id ? (int) $auth->token->id : null,
                $auth->user?->id ? (int) $auth->user->id : null,
                $method,
                $toolName,
                $arguments,
                $status,
                $durationMs,
                $errorMessage,
                $clientIp,
                $userAgent
            );
        }

        return $response;
    }

    private function handleInitialize(JsonRpcRequest $request): array
    {
        return [
            'protocolVersion' => '2024-11-05',
            'serverInfo' => [
                'name' => 'dharma-shop-mcp',
                'version' => '0.1.0',
            ],
            'capabilities' => [
                'tools' => [
                    'listChanged' => false,
                ],
            ],
        ];
    }

    private function handleToolCall(JsonRpcRequest $request, AuthResult $auth, string $serverMode, ?string &$toolName, ?array &$arguments): array
    {
        $toolName = (string) ($request->params['name'] ?? '');
        $arguments = is_array($request->params['arguments'] ?? null) ? $request->params['arguments'] : [];

        $tool = $this->registry->get($toolName);
        if (!$tool) {
            throw new \InvalidArgumentException('Tool not found: ' . $toolName, JsonRpcError::INVALID_PARAMS);
        }

        if (!$auth->hasScope($tool->getRequiredScope())) {
            throw new \InvalidArgumentException('Missing required scope: ' . $tool->getRequiredScope(), JsonRpcError::FORBIDDEN);
        }

        if ($serverMode === 'read_only' && !$tool->isReadOnly()) {
            throw new \InvalidArgumentException('Server is in read-only mode', JsonRpcError::FORBIDDEN);
        }

        $toolResult = $tool->execute($arguments, $auth);

        return [
            'content' => [
                [
                    'type' => 'text',
                    'text' => json_encode($toolResult, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                ],
            ],
        ];
    }
}
