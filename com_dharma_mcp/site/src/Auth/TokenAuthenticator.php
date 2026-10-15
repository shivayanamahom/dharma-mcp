<?php

/**
 * Dharma MCP.
 *
 * @package    com_dharma_mcp
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

declare(strict_types=1);

namespace Dharma\Component\DharmaMcp\Site\Auth;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Registry\Registry;

/**
 * Проверка Bearer-токена: хэш в БД, срок, состояние, список IP и пользователь Joomla.
 * Сырой токен нигде не хранится. После успеха пользователь токена становится текущим
 * пользователем приложения, поэтому модели и ACL Joomla работают от его имени.
 */
final class TokenAuthenticator
{
    public function __construct(private readonly DatabaseInterface $db) {}

    public function authenticate(?string $authHeader, ?string $clientIp = null): AuthResult
    {
        if ($authHeader === null || !preg_match('/^Bearer\s+(\S+)$/i', trim($authHeader), $m)) {
            return AuthResult::failure('Missing or malformed Authorization header', 401);
        }

        $hash = hash('sha256', $m[1]);

        $query = $this->db->createQuery()
            ->select('*')
            ->from($this->db->quoteName('#__dharma_mcp_tokens'))
            ->where($this->db->quoteName('token_hash') . ' = :hash')
            ->bind(':hash', $hash);
        $token = $this->db->setQuery($query)->loadObject();

        if (!$token) {
            return AuthResult::failure('Invalid or unknown token', 401);
        }

        if ((int) $token->state !== 1) {
            return AuthResult::failure('Token has been revoked or disabled', 403);
        }

        $now = Factory::getDate()->toSql();
        if (!empty($token->expires_at) && $token->expires_at < $now) {
            return AuthResult::failure('Token has expired', 403);
        }

        $params = new Registry($token->params ?? '{}');
        $allowed = trim((string) $params->get('allowed_ips', ''));
        if ($allowed !== '' && !self::ipAllowed((string) $clientIp, $allowed)) {
            return AuthResult::failure('This token cannot be used from your IP address', 403);
        }

        $user = Factory::getContainer()->get(UserFactoryInterface::class)->loadUserById((int) $token->user_id);
        if (!$user || (int) $user->id === 0) {
            return AuthResult::failure('The Joomla user of this token no longer exists', 403);
        }
        if ((int) $user->block === 1) {
            return AuthResult::failure('The Joomla user of this token is blocked', 403);
        }

        $scopes = json_decode((string) $token->scopes, true);
        $scopes = \is_array($scopes) ? array_values(array_filter($scopes, 'is_string')) : [];

        $this->touch((int) $token->id, $clientIp, $now);
        Factory::getApplication()->loadIdentity($user);

        return AuthResult::success($token, $user, $scopes);
    }

    private function touch(int $id, ?string $ip, string $now): void
    {
        try {
            $update = $this->db->createQuery()
                ->update($this->db->quoteName('#__dharma_mcp_tokens'))
                ->set($this->db->quoteName('last_used_at') . ' = :now')
                ->set($this->db->quoteName('last_used_ip') . ' = :ip')
                ->set($this->db->quoteName('requests_count') . ' = ' . $this->db->quoteName('requests_count') . ' + 1')
                ->where($this->db->quoteName('id') . ' = :id')
                ->bind(':now', $now)
                ->bind(':ip', $ip)
                ->bind(':id', $id, ParameterType::INTEGER);
            $this->db->setQuery($update)->execute();
        } catch (\Throwable) {
            // Статистика использования не должна ломать запрос.
        }
    }

    /** Точное совпадение или IPv4-сеть вида 10.0.0.0/8; список через запятую, пробел или перевод строки. */
    public static function ipAllowed(string $ip, string $list): bool
    {
        foreach (preg_split('/[\s,;]+/', $list, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $entry) {
            if ($entry === $ip) {
                return true;
            }
            if (str_contains($entry, '/') && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                [$net, $bits] = explode('/', $entry, 2);
                $bits = (int) $bits;
                $netLong = ip2long($net);
                $ipLong = ip2long($ip);
                if ($netLong !== false && $ipLong !== false && $bits >= 0 && $bits <= 32) {
                    $mask = $bits === 0 ? 0 : (~0 << (32 - $bits)) & 0xFFFFFFFF;
                    if (($ipLong & $mask) === ($netLong & $mask)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
