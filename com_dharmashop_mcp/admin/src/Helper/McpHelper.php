<?php

/**
 * Dharma Shop MCP.
 *
 * @package    com_dharmashop_mcp
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

declare(strict_types=1);

namespace Dharma\Component\DharmaShopMcp\Administrator\Helper;

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Toolbar\ToolbarHelper;

abstract class McpHelper
{
    public static function addSubmenu(string $vName): void
    {
        // В Joomla 6 боковое меню формируется через манифест и менеджер меню
    }
}
