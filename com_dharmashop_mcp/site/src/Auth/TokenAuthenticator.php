<?php
declare(strict_types=1);

namespace Dharma\Component\DharmaShopMcp\Site\Auth;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

final class TokenAuthenticator
{
    public function __construct(
        private readonly DatabaseInterface $db
    ) {}

    public function authenticate(?string $authHeader, ?string $clientIp = null): AuthResult
    {
        if (empty($authHeader) || !str_starts_with($authHeader, 'Bearer ')) {
            return AuthResult::failure('Missing or malformed Authorization header', 401);
        }

        $rawToken = trim(substr($authHeader, 7));
        if ($rawToken === '') {
            return AuthResult::failure('Empty Bearer token', 401);
        }

        $hash = hash('sha256', $rawToken);

        $query = $this->db->createQuery()
            ->select('*')
            ->from($this->db->quoteName('#__dharmashop_mcp_tokens'))
            ->where($this->db->quoteName('token_hash') . ' = :hash')
            ->bind(':hash', $hash);

        $token = $this->db->setQuery($query)->loadObject();

        if (!$token) {
            return AuthResult::failure('Invalid or unknown token', 401);
        }

        if ((int) $token->state !== 1) {
            return AuthResult::failure('Token has been revoked or disabled', 403);
        }

        if (!empty($token->expires_at)) {
            $now = Factory::getDate()->toSql();
            if ($token->expires_at < $now) {
                return AuthResult::failure('Token has expired', 403);
            }
        }

        // Обновляем статистику использования
        try {
            $update = $this->db->createQuery()
                ->update($this->db->quoteName('#__dharmashop_mcp_tokens'))
                ->set($this->db->quoteName('last_used_at') . ' = :now')
                ->set($this->db->quoteName('last_used_ip') . ' = :ip')
                ->set($this->db->quoteName('requests_count') . ' = ' . $this->db->quoteName('requests_count') . ' + 1')
                ->where($this->db->quoteName('id') . ' = :id')
                $now = Factory::getDate()->toSql();
            $update->bind(':now', $now)
                ->bind(':ip', $clientIp)
                ->bind(':id', $token->id, ParameterType::INTEGER);
            $this->db->setQuery($update)->execute();
        } catch (\Throwable) {}

        $user = Factory::getUser((int) $token->user_id);
        if (!$user || (int) $user->id === 0) {
            return AuthResult::failure('Associated user not found', 403);
        }

        $scopes = json_decode((string) $token->scopes, true);
        $scopes = is_array($scopes) ? $scopes : [];

        return AuthResult::success($token, $user, $scopes);
    }
}
