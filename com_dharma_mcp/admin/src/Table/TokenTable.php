<?php

/**
 * Dharma MCP.
 *
 * @package    com_dharma_mcp
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

declare(strict_types=1);

namespace Dharma\Component\DharmaMcp\Administrator\Table;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Table\Table;
use Joomla\Database\DatabaseInterface;

/**
 * Токен доступа. Сырой токен здесь не хранится: только хэш и префикс (создаются в модели).
 */
class TokenTable extends Table
{
    /** Массивы, которые Table::bind() сам превращает в JSON. */
    protected $_jsonEncode = ['scopes', 'params'];

    public function __construct(DatabaseInterface $db)
    {
        parent::__construct('#__dharma_mcp_tokens', 'id', $db);
    }

    public function check(): bool
    {
        if (trim((string) $this->title) === '') {
            throw new \UnexpectedValueException('Укажите название токена.');
        }

        if (empty($this->user_id)) {
            throw new \UnexpectedValueException('Выберите пользователя Joomla.');
        }

        if (empty($this->token_hash)) {
            throw new \UnexpectedValueException('У токена нет хэша.');
        }

        if (empty($this->expires_at)) {
            $this->expires_at = null;
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
