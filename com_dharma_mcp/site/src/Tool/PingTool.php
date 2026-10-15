<?php

/**
 * Dharma MCP.
 *
 * @package    com_dharma_mcp
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

declare(strict_types=1);

namespace Dharma\Component\DharmaMcp\Site\Tool;

\defined('_JEXEC') or die;

final class PingTool extends AbstractTool
{
    public function getName(): string
    {
        return 'ping';
    }

    public function getPack(): string
    {
        return 'core';
    }

    public function getTitle(): string
    {
        return 'Ping';
    }

    public function getDescription(): string
    {
        return 'Check the connection to the Dharma MCP server. Returns the authenticated Joomla user, the scopes of the token '
            . 'and the tool packs that are installed. Use it first to see what this connection is allowed to do.';
    }

    public function getAccess(): Access
    {
        return Access::Read;
    }

    public function getInputSchema(): array
    {
        return self::schema(['message' => self::str('Optional text that is echoed back.')]);
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        return [
            'status' => 'pong',
            'time' => gmdate('c'),
            'echo' => isset($arguments['message']) ? (string) $arguments['message'] : null,
            'server_version' => \Dharma\Component\DharmaMcp\Site\Server\McpServer::version(),
            'server_mode' => $context->serverMode,
            'user' => ['id' => (int) $context->user()->id, 'username' => $context->user()->username],
            'scopes' => $context->auth->scopes,
        ];
    }
}
