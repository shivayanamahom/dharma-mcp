<?php

/**
 * Dharma MCP.
 *
 * @package    com_dharma_mcp
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

declare(strict_types=1);

namespace Dharma\Component\DharmaMcp\Administrator\Extension;

\defined('_JEXEC') or die;

use Joomla\CMS\Extension\MVCComponent;

/**
 * Компонент MCP. Собственного маршрутизатора нет: адреса — index.php?option=com_dharma_mcp... и короткие
 * адреса системного плагина; штатная маршрутизация Joomla для них достаточна.
 */
final class McpComponent extends MVCComponent
{
}
