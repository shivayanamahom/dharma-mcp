<?php

/**
 * Dharma Shop MCP.
 *
 * @package    com_dharmashop_mcp
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

declare(strict_types=1);

namespace Dharma\Component\DharmaShopMcp\Administrator\Model;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Model\AdminModel;
use Joomla\CMS\Table\Table;

class TokenModel extends AdminModel
{
    public function getTable($name = 'Token', $prefix = 'Administrator', $options = []): Table
    {
        return parent::getTable($name, $prefix, $options);
    }

    public function getForm($data = [], $loadData = true)
    {
        $form = $this->loadForm('com_dharmashop_mcp.token', 'token', ['control' => 'jform', 'load_data' => $loadData]);

        if (empty($form)) {
            return false;
        }

        return $form;
    }

    protected function loadFormData()
    {
        $data = Factory::getApplication()->getUserState('com_dharmashop_mcp.edit.token.data', []);

        if (empty($data)) {
            $data = $this->getItem();
            if (!empty($data->scopes) && is_string($data->scopes)) {
                $data->scopes = json_decode($data->scopes, true) ?: [];
            }
        }

        return $data;
    }

    public function save($data): bool
    {
        $isNew = empty($data['id']);
        $table = $this->getTable();

        if (parent::save($data)) {
            if ($isNew && !empty($table->rawGeneratedToken)) {
                Factory::getApplication()->getSession()->set('com_dharmashop_mcp.raw_token', $table->rawGeneratedToken);
            }
            return true;
        }

        return false;
    }
}
