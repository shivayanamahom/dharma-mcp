<?php

/**
 * Dharma Shop MCP.
 *
 * @package    com_dharmashop_mcp
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

declare(strict_types=1);

namespace Dharma\Component\DharmaShopMcp\Administrator\Table;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Table\Table;
use Joomla\Database\DatabaseDriver;

class TokenTable extends Table
{
    /** @var string|null Сырой токен для однократного показа пользователю при создании */
    public ?string $rawGeneratedToken = null;

    public function __construct(DatabaseDriver $db)
    {
        parent::__construct('#__dharmashop_mcp_tokens', 'id', $db);
    }

    public function check(): bool
    {
        if (trim((string) $this->title) === '') {
            $this->setError('Название токена обязательно.');
            return false;
        }

        if (empty($this->user_id)) {
            $this->setError('Выберите пользователя Joomla.');
            return false;
        }

        if (is_array($this->scopes)) {
            $this->scopes = json_encode(array_values($this->scopes), JSON_UNESCAPED_UNICODE);
        }

        if (empty($this->id) && empty($this->token_hash)) {
            $rawToken = 'dhmcp_' . bin2hex(random_bytes(24));
            $this->token_hash = hash('sha256', $rawToken);
            $this->token_prefix = substr($rawToken, 0, 14) . '...';
            $this->rawGeneratedToken = $rawToken;
        }

        if (empty($this->created)) {
            $this->created = Factory::getDate()->toSql();
        }

        if (empty($this->created_by)) {
            $this->created_by = (int) Factory::getApplication()->getIdentity()->id;
        }

        return true;
    }
}
