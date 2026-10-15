<?php

/**
 * Dharma MCP.
 *
 * @package    com_dharma_mcp
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

declare(strict_types=1);

namespace Dharma\Component\DharmaMcp\Site\Oauth;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\User;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

/**
 * Сервер авторизации OAuth 2.1 для MCP-клиентов (Claude, ChatGPT и др.):
 * динамическая регистрация клиентов (RFC 7591), код авторизации с обязательным PKCE (S256),
 * короткоживущие токены доступа и вращаемые токены обновления. Клиенты публичные (без секрета).
 * Выданный токен доступа — обычная строка в #__dharma_mcp_tokens, поэтому дальше работает прежняя проверка.
 */
final class OauthServer
{
    public const ACCESS_TTL = 3600;

    public const REFRESH_TTL = 2592000;

    public const CODE_TTL = 120;

    public const MAX_CLIENTS = 500;

    /** Области по умолчанию, если клиент не запросил свои. */
    public const DEFAULT_SCOPES = ['shop:read', 'content:read'];

    /** Все области и кому они доступны: true — только Суперпользователю. */
    public const SCOPES = [
        'shop:read' => false, 'shop:write' => false,
        'content:read' => false, 'content:write' => false,
        'template:read' => true, 'template:write' => true,
        'system:read' => true, 'system:write' => true,
        'customers:pii' => false,
    ];

    public function __construct(private readonly DatabaseInterface $db) {}

    // ---- Адреса и метаданные ---------------------------------------------------------------------------------------

    public static function issuer(): string
    {
        return rtrim(Uri::root(), '/');
    }

    /** Адрес MCP-сервера по умолчанию: короткий (с системным плагином) или обычный. */
    public static function mcpUrl(): string
    {
        if (!\Joomla\CMS\Plugin\PluginHelper::isEnabled('system', 'dharmamcp')) {
            return self::issuer() . '/index.php?option=com_dharma_mcp&format=json';
        }

        // Без перезаписи адресов (например, nginx без try_files) короткий адрес работает через /index.php/mcp.
        return self::issuer() . ((bool) Factory::getApplication()->get('sef_rewrite') ? '/mcp' : '/index.php/mcp');
    }

    /**
     * Какой короткий адрес MCP использовал клиент в этом запросе: «/mcp», «/index.php/mcp» или пусто (обычный адрес).
     * Нужен, чтобы метаданные называли ресурсом ровно тот адрес, который ввёл пользователь.
     */
    public static function requestedMcpPath(): string
    {
        $path = rawurldecode((string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: ''));
        $base = Uri::base(true);
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, \strlen($base));
        }

        return preg_match('#^(/index\.php)?/mcp$#', rtrim($path, '/')) ? rtrim($path, '/') : '';
    }

    /** Адрес ресурса для метаданных: запрошенный клиентом короткий адрес, если он допустим, иначе адрес по умолчанию. */
    public static function resourceUrl(string $path = ''): string
    {
        return preg_match('#^(/index\.php)?/mcp$#', $path) === 1 ? self::issuer() . $path : self::mcpUrl();
    }

    public static function endpoint(string $task): string
    {
        return self::issuer() . '/index.php?option=com_dharma_mcp&task=oauth.' . $task;
    }

    public static function authorizationEndpoint(): string
    {
        return self::issuer() . '/index.php?option=com_dharma_mcp&view=authorize';
    }

    /** Метаданные защищённого ресурса (RFC 9728). */
    public static function resourceMetadata(string $path = ''): array
    {
        return [
            'resource' => self::resourceUrl($path),
            'authorization_servers' => [self::issuer()],
            'scopes_supported' => array_keys(self::SCOPES),
            'bearer_methods_supported' => ['header'],
            'resource_name' => 'Dharma MCP',
        ];
    }

    /** Метаданные сервера авторизации (RFC 8414). */
    public static function serverMetadata(): array
    {
        return [
            'issuer' => self::issuer(),
            'authorization_endpoint' => self::authorizationEndpoint(),
            'token_endpoint' => self::endpoint('token'),
            'registration_endpoint' => self::endpoint('register'),
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'scopes_supported' => array_keys(self::SCOPES),
        ];
    }

    /** Адрес метаданных ресурса для заголовка WWW-Authenticate; $path — короткий адрес, которым пользуется клиент. */
    public static function resourceMetadataUrl(string $path = ''): string
    {
        // Системный плагин отдаёт метаданные прямо по /index.php/.well-known/...: без редиректа Joomla и без перезаписи адресов.
        if (\Joomla\CMS\Plugin\PluginHelper::isEnabled('system', 'dharmamcp')) {
            return self::issuer() . '/index.php/.well-known/oauth-protected-resource' . (preg_match('#^(/index\.php)?/mcp$#', $path) === 1 ? $path : '');
        }

        return self::endpoint('resource') . ($path !== '' ? '&r=' . rawurlencode($path) : '');
    }

    // ---- Клиенты ---------------------------------------------------------------------------------------------------

    /**
     * Динамическая регистрация клиента (RFC 7591). Возвращает ответ регистрации.
     *
     * @param   array<string, mixed>  $meta
     */
    public function register(array $meta): array
    {
        $uris = $meta['redirect_uris'] ?? null;
        if (!\is_array($uris) || $uris === [] || \count($uris) > 10) {
            throw new OauthException('invalid_redirect_uri', 'redirect_uris must be a list of 1 to 10 addresses.');
        }
        foreach ($uris as $uri) {
            if (!\is_string($uri) || !self::redirectUriAllowed($uri)) {
                throw new OauthException('invalid_redirect_uri', 'Unsupported redirect URI. Use https://, http://localhost or an app scheme.');
            }
        }

        $count = (int) $this->db->setQuery($this->db->createQuery()->select('COUNT(*)')->from($this->db->quoteName('#__dharma_mcp_oauth_clients')))->loadResult();
        if ($count >= self::MAX_CLIENTS) {
            throw new OauthException('temporarily_unavailable', 'Too many registered clients; try again later.', 503);
        }

        $name = trim((string) ($meta['client_name'] ?? 'MCP client'));
        $name = mb_substr($name !== '' ? strip_tags($name) : 'MCP client', 0, 120);
        $clientId = 'dhmcp_c_' . bin2hex(random_bytes(16));
        $created = Factory::getDate()->toSql();
        $json = json_encode(array_values($uris), JSON_UNESCAPED_SLASHES) ?: '[]';

        $query = $this->db->createQuery()
            ->insert($this->db->quoteName('#__dharma_mcp_oauth_clients'))
            ->columns($this->db->quoteName(['client_id', 'client_name', 'redirect_uris', 'created']))
            ->values(':id, :name, :uris, :created')
            ->bind(':id', $clientId)
            ->bind(':name', $name)
            ->bind(':uris', $json)
            ->bind(':created', $created);
        $this->db->setQuery($query)->execute();

        return [
            'client_id' => $clientId,
            'client_name' => $name,
            'redirect_uris' => array_values($uris),
            'token_endpoint_auth_method' => 'none',
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'client_id_issued_at' => time(),
        ];
    }

    public static function redirectUriAllowed(string $uri): bool
    {
        if (\strlen($uri) > 1000 || preg_match('/[\s\x00-\x1f]/', $uri) || str_contains($uri, '#')) {
            return false;
        }
        $parts = parse_url($uri);
        if (!\is_array($parts) || empty($parts['scheme'])) {
            return false;
        }
        $scheme = strtolower((string) $parts['scheme']);
        if (\in_array($scheme, ['javascript', 'data', 'file', 'vbscript', 'about', 'blob', 'ftp'], true)) {
            return false;
        }
        if ($scheme === 'https') {
            return !empty($parts['host']);
        }
        if ($scheme === 'http') {
            return \in_array(strtolower((string) ($parts['host'] ?? '')), ['localhost', '127.0.0.1', '[::1]', '::1'], true);
        }

        // Адреса приложений (claude://, cursor://, com.example.app:/...).
        return (bool) preg_match('/^[a-z][a-z0-9+.\-]*$/', $scheme);
    }

    public function client(string $clientId): ?object
    {
        $query = $this->db->createQuery()
            ->select('*')
            ->from($this->db->quoteName('#__dharma_mcp_oauth_clients'))
            ->where($this->db->quoteName('client_id') . ' = :id')
            ->bind(':id', $clientId);

        return $this->db->setQuery($query)->loadObject() ?: null;
    }

    // ---- Запрос авторизации ----------------------------------------------------------------------------------------

    /**
     * Проверяет запрос на согласие и возвращает нормализованные данные.
     *
     * @param   array<string, mixed>  $p  Параметры запроса (client_id, redirect_uri, scope, state, code_challenge...)
     *
     * @return array{client: object, redirect_uri: string, scopes: string[], state: ?string, code_challenge: string}
     */
    public function validateAuthorizeRequest(array $p): array
    {
        $client = $this->client((string) ($p['client_id'] ?? ''));
        if (!$client) {
            throw new OauthException('invalid_client', 'Unknown client.', 400, false);
        }

        $registered = json_decode((string) $client->redirect_uris, true) ?: [];
        $redirect = (string) ($p['redirect_uri'] ?? '');
        if ($redirect === '' && \count($registered) === 1) {
            $redirect = (string) $registered[0];
        }
        if (!\in_array($redirect, $registered, true)) {
            throw new OauthException('invalid_request', 'redirect_uri does not match the registered one.', 400, false);
        }

        if (($p['response_type'] ?? '') !== 'code') {
            throw new OauthException('unsupported_response_type', 'Only response_type=code is supported.');
        }
        $challenge = (string) ($p['code_challenge'] ?? '');
        if (($p['code_challenge_method'] ?? '') !== 'S256' || !preg_match('/^[A-Za-z0-9\-._~]{43,128}$/', $challenge)) {
            throw new OauthException('invalid_request', 'PKCE with code_challenge_method=S256 is required.');
        }

        $requested = preg_split('/\s+/', trim((string) ($p['scope'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $scopes = array_values(array_intersect($requested, array_keys(self::SCOPES)));
        if ($scopes === []) {
            $scopes = self::DEFAULT_SCOPES;
        }

        $state = isset($p['state']) ? mb_substr((string) $p['state'], 0, 500) : null;

        return ['client' => $client, 'redirect_uri' => $redirect, 'scopes' => $scopes, 'state' => $state, 'code_challenge' => $challenge];
    }

    /** Области, которые пользователь вправе выдать клиенту (по его правам Joomla и настройкам сервера). */
    public static function grantableScopes(User $user, bool $piiEnabled): array
    {
        $isSuper = (bool) $user->authorise('core.admin');
        $list = [];
        foreach (self::SCOPES as $scope => $superOnly) {
            if ($superOnly && !$isSuper) {
                continue;
            }
            if ($scope === 'customers:pii' && !$piiEnabled) {
                continue;
            }
            $list[] = $scope;
        }

        return $list;
    }

    /** Выдаёт одноразовый код авторизации; возвращает сам код (в БД — только хэш). */
    public function issueCode(string $clientId, int $userId, array $scopes, string $redirectUri, string $challenge): string
    {
        $code = bin2hex(random_bytes(32));
        $hash = hash('sha256', $code);
        $now = Factory::getDate();
        $created = $now->toSql();
        $expires = (clone $now)->modify('+' . self::CODE_TTL . ' seconds')->toSql();
        $scopeJson = json_encode(array_values($scopes)) ?: '[]';

        $query = $this->db->createQuery()
            ->insert($this->db->quoteName('#__dharma_mcp_oauth_codes'))
            ->columns($this->db->quoteName(['code_hash', 'client_id', 'user_id', 'scopes', 'redirect_uri', 'code_challenge', 'expires_at', 'created']))
            ->values(':hash, :client, :user, :scopes, :redirect, :challenge, :expires, :created')
            ->bind(':hash', $hash)
            ->bind(':client', $clientId)
            ->bind(':user', $userId, ParameterType::INTEGER)
            ->bind(':scopes', $scopeJson)
            ->bind(':redirect', $redirectUri)
            ->bind(':challenge', $challenge)
            ->bind(':expires', $expires)
            ->bind(':created', $created);
        $this->db->setQuery($query)->execute();

        return $code;
    }

    // ---- Токен-эндпоинт --------------------------------------------------------------------------------------------

    /**
     * Обмен кода или токена обновления на токены.
     *
     * @param   array<string, mixed>  $p  Тело запроса к /token
     *
     * @return array<string, mixed>
     */
    public function token(array $p): array
    {
        $grant = (string) ($p['grant_type'] ?? '');
        $clientId = (string) ($p['client_id'] ?? '');
        if ($clientId === '' || !$this->client($clientId)) {
            throw new OauthException('invalid_client', 'Unknown client.', 401);
        }

        return match ($grant) {
            'authorization_code' => $this->exchangeCode($clientId, $p),
            'refresh_token' => $this->refresh($clientId, $p),
            default => throw new OauthException('unsupported_grant_type', 'Use authorization_code or refresh_token.'),
        };
    }

    private function exchangeCode(string $clientId, array $p): array
    {
        $code = (string) ($p['code'] ?? '');
        $verifier = (string) ($p['code_verifier'] ?? '');
        if ($code === '' || !preg_match('/^[A-Za-z0-9\-._~]{43,128}$/', $verifier)) {
            throw new OauthException('invalid_request', 'code and a valid code_verifier are required.');
        }

        $hash = hash('sha256', $code);
        $now = Factory::getDate()->toSql();
        $query = $this->db->createQuery()
            ->select('*')
            ->from($this->db->quoteName('#__dharma_mcp_oauth_codes'))
            ->where($this->db->quoteName('code_hash') . ' = :hash')
            ->bind(':hash', $hash);
        $row = $this->db->setQuery($query)->loadObject();

        // Код одноразовый: удаляем при любой попытке обмена, даже неудачной.
        if ($row) {
            $delete = $this->db->createQuery()
                ->delete($this->db->quoteName('#__dharma_mcp_oauth_codes'))
                ->where($this->db->quoteName('id') . ' = :id')
                ->bind(':id', $row->id, ParameterType::INTEGER);
            $this->db->setQuery($delete)->execute();
        }

        if (!$row || $row->expires_at < $now || !hash_equals((string) $row->client_id, $clientId)) {
            throw new OauthException('invalid_grant', 'The authorization code is invalid or expired.');
        }
        if (isset($p['redirect_uri']) && (string) $p['redirect_uri'] !== (string) $row->redirect_uri) {
            throw new OauthException('invalid_grant', 'redirect_uri does not match the authorization request.');
        }

        $computed = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        if (!hash_equals((string) $row->code_challenge, $computed)) {
            throw new OauthException('invalid_grant', 'PKCE verification failed.');
        }

        $scopes = json_decode((string) $row->scopes, true) ?: [];

        // Грант — одна выданная пользователем авторизация: связывает токены доступа и обновления для отзыва.
        return $this->issueTokens($clientId, (int) $row->user_id, $scopes, bin2hex(random_bytes(8)));
    }

    private function refresh(string $clientId, array $p): array
    {
        $refresh = (string) ($p['refresh_token'] ?? '');
        if ($refresh === '') {
            throw new OauthException('invalid_request', 'refresh_token is required.');
        }

        $hash = hash('sha256', $refresh);
        $now = Factory::getDate()->toSql();
        $query = $this->db->createQuery()
            ->select('*')
            ->from($this->db->quoteName('#__dharma_mcp_oauth_refresh'))
            ->where($this->db->quoteName('refresh_hash') . ' = :hash')
            ->bind(':hash', $hash);
        $row = $this->db->setQuery($query)->loadObject();

        // Вращение: использованный токен обновления погашается всегда.
        if ($row) {
            $delete = $this->db->createQuery()
                ->delete($this->db->quoteName('#__dharma_mcp_oauth_refresh'))
                ->where($this->db->quoteName('id') . ' = :id')
                ->bind(':id', $row->id, ParameterType::INTEGER);
            $this->db->setQuery($delete)->execute();
        }

        if (!$row || $row->expires_at < $now || !hash_equals((string) $row->client_id, $clientId)) {
            throw new OauthException('invalid_grant', 'The refresh token is invalid or expired.');
        }

        // Отзыв: если администратор отключил или удалил токен этого гранта, обновлять нечего.
        $grant = (string) $row->grant_id;
        $lookup = $this->db->createQuery()
            ->select($this->db->quoteName('state'))
            ->from($this->db->quoteName('#__dharma_mcp_tokens'))
            ->where($this->db->quoteName('grant_id') . ' = :grant')
            ->order($this->db->quoteName('id') . ' DESC')
            ->bind(':grant', $grant);
        $state = $this->db->setQuery($lookup, 0, 1)->loadResult();
        if ($state === null || (int) $state !== 1) {
            $this->revokeGrant($grant);
            throw new OauthException('invalid_grant', 'This authorization was revoked.');
        }

        $user = Factory::getContainer()->get(UserFactoryInterface::class)->loadUserById((int) $row->user_id);
        if (!$user || (int) $user->id === 0 || (int) $user->block === 1) {
            $this->revokeGrant($grant);
            throw new OauthException('invalid_grant', 'The user of this authorization is no longer available.');
        }

        return $this->issueTokens($clientId, (int) $row->user_id, json_decode((string) $row->scopes, true) ?: [], $grant);
    }

    /** Создаёт токен доступа (строка в таблице токенов) и новый токен обновления. */
    private function issueTokens(string $clientId, int $userId, array $scopes, string $grantId): array
    {
        $access = 'dhmcp_at_' . bin2hex(random_bytes(24));
        $refresh = 'dhmcp_rt_' . bin2hex(random_bytes(32));
        $now = Factory::getDate();
        $created = $now->toSql();

        // У гранта остаётся один токен доступа (последний): в нём же хранится признак отзыва.
        $cleanup = $this->db->createQuery()
            ->delete($this->db->quoteName('#__dharma_mcp_tokens'))
            ->where($this->db->quoteName('grant_id') . ' = :grant')
            ->bind(':grant', $grantId);
        $this->db->setQuery($cleanup)->execute();

        $client = $this->client($clientId);
        $row = (object) [
            'title' => 'OAuth: ' . ($client->client_name ?? 'client'),
            'kind' => 'oauth',
            'client_id' => $clientId,
            'grant_id' => $grantId,
            'user_id' => $userId,
            'token_hash' => hash('sha256', $access),
            'token_prefix' => substr($access, 0, 14) . '...',
            'scopes' => json_encode(array_values($scopes)),
            'state' => 1,
            'expires_at' => (clone $now)->modify('+' . self::ACCESS_TTL . ' seconds')->toSql(),
            'requests_count' => 0,
            'created' => $created,
            'created_by' => $userId,
            'params' => '{}',
        ];
        $this->db->insertObject('#__dharma_mcp_tokens', $row);

        // insertObject() принимает объект по ссылке: нужна переменная, а не литерал.
        $refreshRow = (object) [
            'refresh_hash' => hash('sha256', $refresh),
            'grant_id' => $grantId,
            'client_id' => $clientId,
            'user_id' => $userId,
            'scopes' => json_encode(array_values($scopes)),
            'expires_at' => (clone $now)->modify('+' . self::REFRESH_TTL . ' seconds')->toSql(),
            'created' => $created,
        ];
        $this->db->insertObject('#__dharma_mcp_oauth_refresh', $refreshRow);

        $touch = $this->db->createQuery()
            ->update($this->db->quoteName('#__dharma_mcp_oauth_clients'))
            ->set($this->db->quoteName('last_used') . ' = :now')
            ->where($this->db->quoteName('client_id') . ' = :id')
            ->bind(':now', $created)
            ->bind(':id', $clientId);
        $this->db->setQuery($touch)->execute();

        return [
            'access_token' => $access,
            'token_type' => 'Bearer',
            'expires_in' => self::ACCESS_TTL,
            'refresh_token' => $refresh,
            'scope' => implode(' ', $scopes),
        ];
    }

    /** Отзыв гранта целиком: токены обновления и доступа. */
    public function revokeGrant(string $grantId): void
    {
        foreach (['#__dharma_mcp_oauth_refresh', '#__dharma_mcp_tokens'] as $table) {
            $q = $this->db->createQuery()->delete($this->db->quoteName($table))->where($this->db->quoteName('grant_id') . ' = :grant')->bind(':grant', $grantId);
            $this->db->setQuery($q)->execute();
        }
    }

    /** Уборка: просроченные коды, токены обновления, токены доступа OAuth и давно не использованные клиенты. */
    public function purge(): void
    {
        $now = Factory::getDate()->toSql();
        $dayAgo = Factory::getDate('-1 day')->toSql();
        $monthAgo = Factory::getDate('-30 days')->toSql();

        foreach ([
            ['#__dharma_mcp_oauth_codes', 'expires_at', $now],
            ['#__dharma_mcp_oauth_refresh', 'expires_at', $now],
        ] as [$table, $column, $limit]) {
            $q = $this->db->createQuery()->delete($this->db->quoteName($table))->where($this->db->quoteName($column) . ' < :limit')->bind(':limit', $limit);
            $this->db->setQuery($q)->execute();
        }

        $q = $this->db->createQuery()
            ->delete($this->db->quoteName('#__dharma_mcp_tokens'))
            ->where($this->db->quoteName('kind') . ' = ' . $this->db->quote('oauth'))
            ->where($this->db->quoteName('expires_at') . ' < :limit')
            // Токен гранта с действующим токеном обновления хранит признак отзыва, его не удаляем.
            ->where('(' . $this->db->quoteName('grant_id') . ' IS NULL OR ' . $this->db->quoteName('grant_id')
                . ' NOT IN (SELECT ' . $this->db->quoteName('grant_id') . ' FROM ' . $this->db->quoteName('#__dharma_mcp_oauth_refresh') . '))')
            ->bind(':limit', $dayAgo);
        $this->db->setQuery($q)->execute();

        $q = $this->db->createQuery()
            ->delete($this->db->quoteName('#__dharma_mcp_oauth_clients'))
            ->where($this->db->quoteName('created') . ' < :limit')
            ->where('(' . $this->db->quoteName('last_used') . ' IS NULL OR ' . $this->db->quoteName('last_used') . ' < :limit2)')
            ->bind(':limit', $monthAgo)
            ->bind(':limit2', $monthAgo);
        $this->db->setQuery($q)->execute();
    }
}
