<?php

/**
 * Dharma MCP.
 *
 * @package    com_dharma_mcp
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

declare(strict_types=1);

namespace Dharma\Component\DharmaMcp\Site\Controller;

\defined('_JEXEC') or die;

use Dharma\Component\DharmaMcp\Site\Audit\AuditLogger;
use Dharma\Component\DharmaMcp\Site\Auth\TokenAuthenticator;
use Dharma\Component\DharmaMcp\Site\Oauth\OauthServer;
use Dharma\Component\DharmaMcp\Site\Protocol\JsonRpcError;
use Dharma\Component\DharmaMcp\Site\Protocol\JsonRpcRequest;
use Dharma\Component\DharmaMcp\Site\Protocol\JsonRpcResponse;
use Dharma\Component\DharmaMcp\Site\Server\McpServer;
use Dharma\Component\DharmaMcp\Site\Support\Confirmations;
use Dharma\Component\DharmaMcp\Site\Tool\ToolRegistry;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

/**
 * Единственная точка входа MCP (Streamable HTTP, JSON-RPC 2.0 поверх POST).
 * Порядок: включён ли сервер → HTTPS → метод → токен → лимит запросов → разбор тела → вызов.
 */
class DisplayController extends BaseController
{
    /** Joomla угадывает имя компонента по пространству имён (com_dharmamcp), поэтому оно задано явно. */
    protected $option = 'com_dharma_mcp';

    /** Сколько вызовов допускается в одном пакетном запросе (иначе пакет обходил бы лимит запросов в минуту). */
    private const MAX_BATCH = 50;

    public function display($cachable = false, $urlparams = [])
    {
        // Страница согласия OAuth — обычный HTML-вид Joomla, остальное — JSON-эндпоинт MCP.
        if ($this->input->get('view') === 'authorize') {
            return parent::display($cachable, $urlparams);
        }

        // Ответ — строго JSON. Предупреждения PHP и любой посторонний вывод (расширения, display_errors хостинга)
        // не должны попасть в тело: показ ошибок выключаем, вывод собираем в буфер и отбрасываем при отправке.
        @ini_set('display_errors', '0');
        ob_start();
        $this->bufferLevel = ob_get_level();

        $app = $this->app;
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $params = ComponentHelper::getParams('com_dharma_mcp');

        // CORS: браузерным клиентам MCP (например, MCP Inspector) нужны предзапрос и видимость WWW-Authenticate.
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept, MCP-Protocol-Version, Mcp-Session-Id');
        header('Access-Control-Expose-Headers: WWW-Authenticate');
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) === 'OPTIONS') {
            $this->send(204, null);
        }

        if ((int) $params->get('enabled', 1) !== 1) {
            $this->send(503, JsonRpcResponse::error(null, new JsonRpcError(JsonRpcError::INTERNAL_ERROR, 'MCP server is disabled')));
        }

        if ((int) $params->get('require_https', 1) === 1 && !$app->isSslConnection()) {
            $this->send(403, JsonRpcResponse::error(null, new JsonRpcError(JsonRpcError::FORBIDDEN, 'HTTPS is required')));
        }

        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
            header('Allow: POST');
            $this->send(405, JsonRpcResponse::error(null, new JsonRpcError(JsonRpcError::INVALID_REQUEST, 'Use POST with a JSON-RPC 2.0 body')));
        }

        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
        if (!$authHeader && \function_exists('apache_request_headers')) {
            $headers = array_change_key_case(apache_request_headers() ?: [], CASE_LOWER);
            $authHeader = $headers['authorization'] ?? null;
        }
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $userAgent = isset($_SERVER['HTTP_USER_AGENT']) ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 255) : null;

        $auth = (new TokenAuthenticator($db))->authenticate($authHeader, $ip);
        if (!$auth->success) {
            $code = $auth->errorCode > 0 ? $auth->errorCode : 401;
            $this->logRejected($db, $authHeader ? 'auth/rejected' : 'auth/no-token', (string) $auth->errorMessage, $ip, $userAgent);
            if ($code === 401) {
                // Клиентам MCP: где искать настройки авторизации (RFC 9728), чтобы начать вход через OAuth.
                $challenge = 'Bearer';
                if ((int) $params->get('oauth_enabled', 1) === 1) {
                    $challenge .= ' resource_metadata="' . OauthServer::resourceMetadataUrl(OauthServer::requestedMcpPath()) . '"';
                }
                header('WWW-Authenticate: ' . $challenge);
            }
            $this->send(
                $code,
                JsonRpcResponse::error(null, new JsonRpcError($code === 403 ? JsonRpcError::FORBIDDEN : JsonRpcError::UNAUTHORIZED, (string) $auth->errorMessage))
            );
        }

        $limit = (int) $params->get('rate_limit_minute', 60);
        $used = $limit > 0 ? $this->requestsLastMinute($db, (int) $auth->token->id) : 0;
        if ($limit > 0 && $used >= $limit) {
            header('Retry-After: 30');
            $this->send(429, JsonRpcResponse::error(null, new JsonRpcError(JsonRpcError::RATE_LIMITED, 'Too many requests, retry later')));
        }

        $raw = file_get_contents('php://input');
        $payload = \is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : null;
        if (!\is_array($payload) || $payload === []) {
            $this->send(400, JsonRpcResponse::error(null, new JsonRpcError(JsonRpcError::PARSE_ERROR, 'Empty or invalid JSON body')));
        }

        // Пакет считается по числу вызовов в нём: каждый попадает в журнал и в счёт лимита.
        $isBatch = array_is_list($payload);
        $items = $isBatch ? $payload : [$payload];
        if (\count($items) > self::MAX_BATCH) {
            $this->send(400, JsonRpcResponse::error(null, new JsonRpcError(JsonRpcError::INVALID_REQUEST, 'Too many requests in one batch (up to ' . self::MAX_BATCH . ')')));
        }
        if ($limit > 0 && $used + \count($items) > $limit) {
            header('Retry-After: 30');
            $this->send(429, JsonRpcResponse::error(null, new JsonRpcError(JsonRpcError::RATE_LIMITED, 'Too many requests, retry later')));
        }

        $logger = new AuditLogger($db);
        if (random_int(1, 50) === 1) {
            $logger->purge((int) $params->get('log_retention_days', 30), (int) $params->get('log_max_rows', 20000));
            (new OauthServer($db))->purge();
        }

        $server = new McpServer(ToolRegistry::build($app), $logger, new Confirmations($db), $params, $db, $app);

        // Пакетный запрос (массив) поддержан для совместимости со старыми клиентами.
        $responses = [];

        foreach ($items as $item) {
            try {
                $request = JsonRpcRequest::fromRaw(json_encode($item) ?: '');
            } catch (\InvalidArgumentException $e) {
                $responses[] = JsonRpcResponse::error(null, new JsonRpcError(JsonRpcError::INVALID_REQUEST, $e->getMessage()));
                continue;
            }
            $response = $server->handle($request, $auth, $ip, $userAgent);
            if ($response !== null) {
                $responses[] = $response;
            }
        }

        if ($responses === []) {
            $this->send(202, null);
        }

        $app->setHeader('Content-Type', 'application/json; charset=utf-8', true);
        if (!$isBatch) {
            $this->send(200, $responses[0]);
        }

        $this->discardStrayOutput();
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        echo '[' . implode(',', array_map(static fn (JsonRpcResponse $r): string => $r->toJson(), $responses)) . ']';
        $app->close();
    }

    /** Уровень буфера вывода, открытый контроллером. */
    private int $bufferLevel = 0;

    /** Отбрасывает всё, что кроме нашего ответа попало в вывод (предупреждения, HTML расширений). */
    private function discardStrayOutput(): void
    {
        while ($this->bufferLevel > 0 && ob_get_level() >= $this->bufferLevel) {
            ob_end_clean();
        }
    }

    /** Отправляет ответ с настоящим HTTP-кодом и завершает приложение. */
    private function send(int $httpCode, ?JsonRpcResponse $response): never
    {
        $this->discardStrayOutput();
        http_response_code($httpCode);
        header('Cache-Control: no-store');
        if ($response !== null) {
            header('Content-Type: application/json; charset=utf-8');
            echo $response->toJson();
        }
        $this->app->close();
        exit;
    }

    private function requestsLastMinute(DatabaseInterface $db, int $tokenId): int
    {
        $since = Factory::getDate('-1 minute')->toSql();
        $query = $db->createQuery()
            ->select('COUNT(*)')
            ->from($db->quoteName('#__dharma_mcp_logs'))
            ->where($db->quoteName('token_id') . ' = :token')
            ->where($db->quoteName('created') . ' >= :since')
            ->bind(':token', $tokenId, ParameterType::INTEGER)
            ->bind(':since', $since);

        return (int) $db->setQuery($query)->loadResult();
    }

    /**
     * Запрос без действительного токена попадает в журнал, чтобы владелец видел, что клиент до сайта дошёл, но не вошёл.
     * С одного адреса пишется не больше пяти записей в минуту: журнал не должен забиваться сканерами.
     */
    private function logRejected(DatabaseInterface $db, string $method, string $message, ?string $ip, ?string $userAgent): void
    {
        try {
            $since = \Joomla\CMS\Factory::getDate('-1 minute')->toSql();
            $recent = (int) $db->setQuery(
                $db->createQuery()->select('COUNT(*)')->from($db->quoteName('#__dharma_mcp_logs'))
                    ->where($db->quoteName('method') . ' LIKE ' . $db->quote('auth/%'))
                    ->where($db->quoteName('ip') . ' = ' . $db->quote((string) $ip))
                    ->where($db->quoteName('created') . ' > ' . $db->quote($since))
            )->loadResult();

            if ($recent < 5) {
                (new AuditLogger($db))->log(null, null, $method, null, null, 'error', 0, $message, $ip, $userAgent);
            }
        } catch (\Throwable) {
            // журнал не должен мешать ответу
        }
    }
}
