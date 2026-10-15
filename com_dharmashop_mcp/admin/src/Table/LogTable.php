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

use Joomla\CMS\Table\Table;
use Joomla\Database\DatabaseDriver;

class LogTable extends Table
{
    public function __construct(DatabaseDriver $db)
    {
        parent::__construct('#__dharmashop_mcp_logs', 'id', $db);
    }
}
