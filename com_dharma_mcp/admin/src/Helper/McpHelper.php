<?php

/**
 * Dharma MCP.
 *
 * @package    com_dharma_mcp
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

declare(strict_types=1);

namespace Dharma\Component\DharmaMcp\Administrator\Helper;

\defined('_JEXEC') or die;

use Dharma\Component\DharmaMcp\Site\Oauth\OauthServer;
use Joomla\CMS\Http\HttpFactory;
use Joomla\CMS\Language\Text;

abstract class McpHelper
{
    /** Адрес MCP-сервера, который нужно вводить в клиенте. */
    public static function serverUrl(): string
    {
        return OauthServer::mcpUrl();
    }

    /** Команда подключения Claude Code; вместо токена — подсказка, если его ещё нет под рукой. */
    public static function claudeCodeCommand(string $token = ''): string
    {
        return 'claude mcp add --transport http dharma ' . self::serverUrl()
            . ' --header "Authorization: Bearer ' . ($token !== '' ? $token : Text::_('COM_DHARMA_MCP_YOUR_TOKEN')) . '"';
    }

    /** Настройка в формате JSON для Cursor и других клиентов, читающих `mcpServers`. */
    public static function jsonConfig(string $token = ''): string
    {
        return json_encode(
            ['mcpServers' => ['dharma' => ['url' => self::serverUrl(), 'headers' => ['Authorization' => 'Bearer ' . ($token !== '' ? $token : Text::_('COM_DHARMA_MCP_YOUR_TOKEN'))]]]],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        ) ?: '';
    }

    /** Проверка запроса с токеном (команда curl) для терминала. */
    public static function curlCommand(string $token = ''): string
    {
        return "curl -s -X POST " . self::serverUrl() . " -H 'Content-Type: application/json' -H 'Authorization: Bearer "
            . ($token !== '' ? $token : Text::_('COM_DHARMA_MCP_YOUR_TOKEN')) . "' -d '{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/list\"}'";
    }

    /**
     * Самопроверка: отвечает ли сервер по своему адресу и доступны ли настройки OAuth для Claude и ChatGPT.
     *
     * @return array<int, array{title: string, ok: bool, detail: string, hint: string}>
     */
    public static function checkEndpoints(?string $mcpUrl = null, ?string $issuer = null): array
    {
        $checks = [];
        $http = HttpFactory::getHttp();

        // 1. MCP-адрес: без токена должен ответить 401 в формате JSON-RPC.
        $url = $mcpUrl ?? self::serverUrl();
        try {
            $r = $http->post($url, '{"jsonrpc":"2.0","id":1,"method":"ping"}', ['Content-Type' => 'application/json'], 8);
            $code = (int) $r->getStatusCode();
            $json = json_decode((string) $r->getBody(), true);
            $ok = $code === 401 && \is_array($json) && isset($json['error']);
            $checks[] = [
                'title' => Text::_('COM_DHARMA_MCP_CHECK_MCP'),
                'ok' => $ok,
                'detail' => $url . ' → HTTP ' . $code,
                'hint' => $ok ? '' : Text::_($code === 404 ? 'COM_DHARMA_MCP_CHECK_MCP_404' : ($code === 403 ? 'COM_DHARMA_MCP_CHECK_MCP_403' : 'COM_DHARMA_MCP_CHECK_MCP_OTHER')),
            ];
        } catch (\Throwable $e) {
            $checks[] = ['title' => Text::_('COM_DHARMA_MCP_CHECK_MCP'), 'ok' => false, 'detail' => $url . ' → ' . mb_substr($e->getMessage(), 0, 160), 'hint' => Text::_('COM_DHARMA_MCP_CHECK_UNREACHABLE')];
        }

        // 2. Настройки OAuth по стандартному адресу: нужны для Claude Desktop, claude.ai и ChatGPT.
        $meta = ($issuer ?? OauthServer::issuer()) . '/.well-known/oauth-authorization-server';
        try {
            $r = $http->get($meta, [], 8);
            $code = (int) $r->getStatusCode();
            $json = json_decode((string) $r->getBody(), true);
            $ok = $code === 200 && \is_array($json) && isset($json['issuer']);
            $checks[] = [
                'title' => Text::_('COM_DHARMA_MCP_CHECK_OAUTH'),
                'ok' => $ok,
                'detail' => $meta . ' → HTTP ' . $code,
                'hint' => $ok ? '' : Text::_('COM_DHARMA_MCP_CHECK_OAUTH_HINT'),
            ];
        } catch (\Throwable $e) {
            $checks[] = ['title' => Text::_('COM_DHARMA_MCP_CHECK_OAUTH'), 'ok' => false, 'detail' => $meta . ' → ' . mb_substr($e->getMessage(), 0, 160), 'hint' => Text::_('COM_DHARMA_MCP_CHECK_UNREACHABLE')];
        }

        // 3. Хостинг не должен отбрасывать запросы по User-Agent облачных клиентов: часть хостингов включает «защиту от ИИ-ботов»,
        // и тогда вход проходит, а следующий запрос Claude или ChatGPT к серверу получает 403 от веб-сервера.
        $blocked = [];
        $seen = [];
        foreach (['Claude-User' => 'Claude', 'openai-mcp/1.0' => 'ChatGPT'] as $agent => $label) {
            try {
                $r = $http->post($url, '{"jsonrpc":"2.0","id":1,"method":"ping"}', ['Content-Type' => 'application/json', 'User-Agent' => $agent], 8);
                $code = (int) $r->getStatusCode();
            } catch (\Throwable $e) {
                $code = 0;
            }
            $seen[] = $label . ': HTTP ' . $code;
            if ($code === 403) {
                $blocked[] = $label;
            }
        }
        $checks[] = [
            'title' => Text::_('COM_DHARMA_MCP_CHECK_BOTS'),
            'ok' => $blocked === [],
            'detail' => implode('; ', $seen),
            'hint' => $blocked === [] ? '' : Text::sprintf('COM_DHARMA_MCP_CHECK_BOTS_HINT', implode(', ', $blocked)),
        ];

        return $checks;
    }

    public static function addSubmenu(string $vName): void
    {
        // В Joomla 6 боковое меню формируется через манифест и менеджер меню
    }
}
