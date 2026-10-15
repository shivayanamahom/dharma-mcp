<?php

/**
 * Dharma MCP.
 *
 * @package    com_dharma_mcp
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

declare(strict_types=1);

namespace Dharma\Component\DharmaMcp\Administrator\View\Connect;

\defined('_JEXEC') or die;

use Dharma\Component\DharmaMcp\Administrator\Helper\McpHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;

/**
 * Страница «Подключение»: адрес сервера, готовые команды для клиентов и самопроверка.
 */
class HtmlView extends BaseHtmlView
{
    protected $option = 'com_dharma_mcp';

    public string $serverUrl = '';

    /** @var array<int, array{title: string, ok: bool, detail: string, hint: string}> */
    public array $checks = [];

    public function display($tpl = null): void
    {
        $this->serverUrl = McpHelper::serverUrl();
        $this->checks = McpHelper::checkEndpoints();

        ToolbarHelper::title(Text::_('COM_DHARMA_MCP_CONNECT_TITLE'), 'link');

        parent::display($tpl);
    }
}
