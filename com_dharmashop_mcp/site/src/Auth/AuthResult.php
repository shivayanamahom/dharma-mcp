<?php
declare(strict_types=1);

namespace Dharma\Component\DharmaShopMcp\Site\Auth;

\defined('_JEXEC') or die;

use Joomla\CMS\User\User;

final class AuthResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?object $token = null,
        public readonly ?User $user = null,
        public readonly array $scopes = [],
        public readonly ?string $errorMessage = null,
        public readonly int $errorCode = 0
    ) {}

    public static function success(object $token, User $user, array $scopes): self
    {
        return new self(true, $token, $user, $scopes);
    }

    public static function failure(string $message, int $code = 401): self
    {
        return new self(false, null, null, [], $message, $code);
    }

    public function hasScope(string $scope): bool
    {
        if (in_array('*', $this->scopes, true)) {
            return true;
        }
        return in_array($scope, $this->scopes, true);
    }
}
