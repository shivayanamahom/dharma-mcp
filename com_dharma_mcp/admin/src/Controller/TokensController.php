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
use Joomla\CMS\Router\Route;

class TokensController extends AdminController
{
    /** Joomla угадывает имя компонента по пространству имён (com_dharmamcp), поэтому оно задано явно. */
    protected $option = 'com_dharma_mcp';

    public function getModel($name = 'Token', $prefix = 'Administrator', $config = ['ignore_request' => true])
    {
        return parent::getModel($name, $prefix, $config);
    }

    /** Кнопка «Пересоздать» в списке: выбранный токен получает новое значение, остальные свойства сохраняются. */
    public function regenerate(): bool
    {
        $this->checkToken();

        $ids = array_values(array_filter(array_map('intval', (array) $this->input->get('cid', [], 'array'))));

        if (!$this->app->getIdentity()->authorise('core.edit', 'com_dharma_mcp')) {
            $this->setMessage(Text::_('JLIB_APPLICATION_ERROR_EDIT_NOT_PERMITTED'), 'error');
        } elseif (\count($ids) !== 1) {
            $this->setMessage(Text::_('COM_DHARMA_MCP_TOKEN_REGENERATE_ONE'), 'warning');
        } else {
            try {
                $this->getModel()->regenerate($ids[0]);
                $this->setMessage(Text::_('COM_DHARMA_MCP_TOKEN_REGENERATED'));
            } catch (\RuntimeException $error) {
                $this->setMessage($error->getMessage(), 'error');
            }
        }

        $this->setRedirect(Route::_('index.php?option=com_dharma_mcp&view=tokens', false));

        return true;
    }
}
