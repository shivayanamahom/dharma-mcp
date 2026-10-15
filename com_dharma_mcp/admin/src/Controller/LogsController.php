<?php

/**
 * Dharma MCP.
 *
 * @package    com_dharma_mcp
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

declare(strict_types=1);

namespace Dharma\Component\DharmaMcp\Administrator\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\AdminController;

class LogsController extends AdminController
{
    /** Joomla угадывает имя компонента по пространству имён (com_dharmamcp), поэтому оно задано явно. */
    protected $option = 'com_dharma_mcp';

    public function getModel($name = 'Logs', $prefix = 'Administrator', $config = ['ignore_request' => true])
    {
        return parent::getModel($name, $prefix, $config);
    }

    /** Удаляет отмеченные записи журнала. */
    public function delete()
    {
        $this->checkToken();
        $this->checkRight();

        $ids = (array) $this->input->get('cid', [], 'array');

        if ($ids === []) {
            $this->setRedirect('index.php?option=com_dharma_mcp&view=logs', Text::_('JERROR_NO_ITEMS_SELECTED'), 'warning');

            return false;
        }

        $count = $this->getModel()->deleteLogs($ids);
        $this->setRedirect('index.php?option=com_dharma_mcp&view=logs', Text::sprintf('COM_DHARMA_MCP_LOGS_DELETED', $count));

        return true;
    }

    /** Очищает весь журнал вызовов. */
    public function clear()
    {
        $this->checkToken();
        $this->checkRight();

        $count = $this->getModel()->clearAll();
        $this->setRedirect('index.php?option=com_dharma_mcp&view=logs', Text::sprintf('COM_DHARMA_MCP_LOGS_DELETED', $count));

        return true;
    }

    /** Удалять записи журнала может тот, у кого есть право «Удалять» в компоненте. */
    private function checkRight(): void
    {
        if (!$this->app->getIdentity()->authorise('core.delete', 'com_dharma_mcp')) {
            throw new \Joomla\CMS\Access\Exception\NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }
    }
}
