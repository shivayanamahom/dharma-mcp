<?php

/**
 * Dharma Shop MCP.
 *
 * @package    com_dharmashop_mcp
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

declare(strict_types=1);

namespace Dharma\Component\DharmaShopMcp\Administrator\View\Tokens;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\GenericDataException;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\Toolbar;
use Joomla\CMS\Toolbar\ToolbarHelper;

class HtmlView extends BaseHtmlView
{
    protected $items;
    protected $pagination;
    protected $state;
    public $filterForm;
    public $activeFilters;

    public function display($tpl = null): void
    {
        $this->items         = $this->get('Items');
        $this->pagination    = $this->get('Pagination');
        $this->state         = $this->get('State');
        $this->filterForm    = $this->get('FilterForm');
        $this->activeFilters = $this->get('ActiveFilters');

        if (count($errors = $this->get('Errors'))) {
            throw new GenericDataException(implode("\n", $errors), 500);
        }

        // Если только что был создан токен, показываем его в открытом виде один раз
        $session = Factory::getApplication()->getSession();
        if ($rawToken = $session->get('com_dharmashop_mcp.raw_token')) {
            $session->remove('com_dharmashop_mcp.raw_token');
            $msg = Text::_('COM_DHARMASHOP_MCP_RAW_TOKEN_NOTICE') . '<br><br><code>' . htmlspecialchars($rawToken) . '</code>';
            Factory::getApplication()->enqueueMessage($msg, 'warning');
        }

        $this->addToolbar();

        parent::display($tpl);
    }

    protected function addToolbar(): void
    {
        ToolbarHelper::title(Text::_('COM_DHARMASHOP_MCP_TOKENS_TITLE'), 'key');

        $user = Factory::getApplication()->getIdentity();

        if ($user->authorise('core.create', 'com_dharmashop_mcp')) {
            ToolbarHelper::addNew('token.add');
        }

        if ($user->authorise('core.edit', 'com_dharmashop_mcp')) {
            ToolbarHelper::editList('token.edit');
        }

        if ($user->authorise('core.delete', 'com_dharmashop_mcp')) {
            ToolbarHelper::deleteList('', 'tokens.delete');
        }

        if ($user->authorise('core.admin', 'com_dharmashop_mcp') || $user->authorise('core.options', 'com_dharmashop_mcp')) {
            ToolbarHelper::preferences('com_dharmashop_mcp');
        }
    }
}
