<?php

/**
 * Dharma MCP.
 *
 * @package    com_dharma_mcp
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

declare(strict_types=1);

namespace Dharma\Component\DharmaMcp\Administrator\Model;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Model\AdminModel;
use Joomla\CMS\Table\Table;
use Joomla\Registry\Registry;

class TokenModel extends AdminModel
{
    /** Joomla угадывает имя компонента по пространству имён (com_dharmamcp), поэтому оно задано явно. */
    protected $option = 'com_dharma_mcp';

    public function getTable($name = 'Token', $prefix = 'Administrator', $options = []): Table
    {
        return parent::getTable($name, $prefix, $options);
    }

    public function getForm($data = [], $loadData = true)
    {
        $form = $this->loadForm('com_dharma_mcp.token', 'token', ['control' => 'jform', 'load_data' => $loadData]);

        return empty($form) ? false : $form;
    }

    protected function loadFormData()
    {
        $data = Factory::getApplication()->getUserState('com_dharma_mcp.edit.token.data', []);

        if (empty($data)) {
            $data = $this->getItem();
            if (!empty($data->scopes) && \is_string($data->scopes)) {
                $data->scopes = json_decode($data->scopes, true) ?: [];
            }
            if (\is_string($data->params ?? null)) {
                $data->params = (new Registry($data->params))->toArray();
            }
        }

        return $data;
    }

    /**
     * Для нового токена здесь создаётся значение, хэш и префикс. Сырой токен показывается
     * администратору один раз (сообщение после сохранения) и нигде не хранится.
     */
    public function save($data): bool
    {
        $raw = null;

        if (empty($data['id'])) {
            $raw = self::newSecret();
            $data['token_hash'] = hash('sha256', $raw);
            $data['token_prefix'] = substr($raw, 0, 14) . '...';
        }

        if (!parent::save($data)) {
            return false;
        }

        if ($raw !== null) {
            Factory::getApplication()->getSession()->set('com_dharma_mcp.raw_token', $raw);
        }

        return true;
    }

    /** Новое значение токена: 192 бита случайных данных. */
    private static function newSecret(): string
    {
        return 'dhmcp_' . bin2hex(random_bytes(24));
    }

    /**
     * Пересоздание: у той же записи (название, пользователь, области, срок, IP, состояние, журнал) меняется только секрет.
     * Старое значение перестаёт действовать сразу, непогашенные коды подтверждения этого токена удаляются. Новое значение
     * показывается один раз (сессия), как при создании. OAuth-токены Claude и ChatGPT не пересоздаются: их отключают, и программа
     * получает новый доступ при следующем входе.
     *
     * @throws \RuntimeException
     */
    public function regenerate(int $id): void
    {
        $table = $this->getTable();

        if ($id < 1 || !$table->load($id)) {
            throw new \RuntimeException(Text::_('COM_DHARMA_MCP_TOKEN_NOT_FOUND'));
        }

        if ((string) $table->kind !== 'manual') {
            throw new \RuntimeException(Text::_('COM_DHARMA_MCP_TOKEN_REGENERATE_OAUTH'));
        }

        $raw = self::newSecret();
        $table->token_hash = hash('sha256', $raw);
        $table->token_prefix = substr($raw, 0, 14) . '...';
        // Новое значение ещё нигде не использовалось: «последнее использование» сбрасывается, чтобы было видно, когда им воспользуются.
        $table->last_used_at = null;
        $table->last_used_ip = null;

        // updateNulls: поля «последнее использование» должны очиститься, а не остаться прежними.
        $table->store(true);

        $db = $this->getDatabase();
        $db->setQuery(
            $db->createQuery()->delete($db->quoteName('#__dharma_mcp_confirmations'))
                ->where($db->quoteName('token_id') . ' = :token')->bind(':token', $id, \Joomla\Database\ParameterType::INTEGER)
        )->execute();

        Factory::getApplication()->getSession()->set('com_dharma_mcp.raw_token', $raw);
    }
}
