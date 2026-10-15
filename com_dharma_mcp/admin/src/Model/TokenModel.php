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
use Joomla\CMS\MVC\Model\AdminModel;
use Joomla\CMS\Table\Table;
use Joomla\Registry\Registry;

class TokenModel extends AdminModel
{
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
            $raw = 'dhmcp_' . bin2hex(random_bytes(24));
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
}
