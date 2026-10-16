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
use Joomla\CMS\MVC\Controller\FormController;
use Joomla\CMS\Router\Route;

class TokenController extends FormController
{
    /** Joomla угадывает имя компонента по пространству имён (com_dharmamcp), поэтому оно задано явно. */
    protected $option = 'com_dharma_mcp';

    /** Кнопка «Пересоздать» на странице токена: меняет секрет, остальное не трогает; новое значение показывается на странице списка. */
    public function regenerate(): bool
    {
        $this->checkToken();

        $id = $this->input->getInt('id') ?: (int) ($this->input->get('jform', [], 'array')['id'] ?? 0);

        if (!$this->allowEdit(['id' => $id])) {
            $this->setMessage(Text::_('JLIB_APPLICATION_ERROR_EDIT_NOT_PERMITTED'), 'error');
        } else {
            try {
                $this->getModel()->regenerate($id);
                $this->setMessage(Text::_('COM_DHARMA_MCP_TOKEN_REGENERATED'));
            } catch (\RuntimeException $error) {
                $this->setMessage($error->getMessage(), 'error');
            }
        }

        $this->releaseEditId($this->context, $id);
        $this->setRedirect(Route::_('index.php?option=com_dharma_mcp&view=tokens', false));

        return true;
    }
}
