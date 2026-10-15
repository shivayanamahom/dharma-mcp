<?php
declare(strict_types=1);

namespace Dharma\Component\DharmaShopMcp\Site\Tool;

\defined('_JEXEC') or die;

use Dharma\Component\DharmaShopMcp\Site\Auth\AuthResult;

final class PingTool implements ToolInterface
{
    public function getName(): string
    {
        return 'ping';
    }

    public function getDescription(): string
    {
        return 'Проверка связи с сервером Dharma Shop MCP.';
    }

    public function getRequiredScope(): string
    {
        return 'shop:read';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'message' => [
                    'type' => 'string',
                    'description' => 'Необязательное тестовое сообщение',
                ],
            ],
        ];
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function isDestructive(): bool
    {
        return false;
    }

    public function execute(array $arguments, AuthResult $auth): array
    {
        return [
            'status' => 'pong',
            'timestamp' => time(),
            'echo' => $arguments['message'] ?? null,
            'user' => $auth->user->name,
        ];
    }
}
