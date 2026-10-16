<?php

/**
 * Dharma MCP.
 *
 * @package    com_dharma_mcp
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

declare(strict_types=1);

namespace Dharma\Component\DharmaMcp\Administrator\View\Token;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\GenericDataException;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\Toolbar;
use Joomla\CMS\Toolbar\ToolbarHelper;

class HtmlView extends BaseHtmlView
{
    /** Joomla угадывает имя компонента по пространству имён (com_dharmamcp), поэтому оно задано явно. */
    protected $option = 'com_dharma_mcp';

    protected $form;
    protected $item;
    protected $state;

    public function display($tpl = null): void
    {
        $this->form  = $this->get('Form');
        $this->item  = $this->get('Item');
        $this->state = $this->get('State');

        if (count($errors = $this->get('Errors'))) {
            throw new GenericDataException(implode("\n", $errors), 500);
        }

        $this->addToolbar();

        parent::display($tpl);
    }

    protected function addToolbar(): void
    {
        $isNew = empty($this->item->id);
        $title = $isNew ? Text::_('COM_DHARMA_MCP_TOKEN_NEW') : Text::_('COM_DHARMA_MCP_TOKEN_EDIT');

        ToolbarHelper::title($title, 'key');

        ToolbarHelper::apply('token.apply');
        ToolbarHelper::save('token.save');

        // Пересоздать можно сохранённый токен, созданный вручную; OAuth-токены программ не пересоздаются.
        if (!$isNew && (string) ($this->item->kind ?? 'manual') === 'manual' && Factory::getApplication()->getIdentity()->authorise('core.edit', 'com_dharma_mcp')) {
            Toolbar::getInstance()->confirmButton('regenerate', 'COM_DHARMA_MCP_TOKEN_REGENERATE', 'token.regenerate')
                ->message('COM_DHARMA_MCP_TOKEN_REGENERATE_CONFIRM')->icon('icon-refresh')->listCheck(false);
        }

        ToolbarHelper::cancel('token.cancel');
    }
}
