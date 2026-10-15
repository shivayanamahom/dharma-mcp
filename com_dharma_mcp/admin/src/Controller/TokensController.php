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

use Joomla\CMS\MVC\Controller\AdminController;

class TokensController extends AdminController
{
    /** Joomla угадывает имя компонента по пространству имён (com_dharmamcp), поэтому оно задано явно. */
    protected $option = 'com_dharma_mcp';

    public function getModel($name = 'Token', $prefix = 'Administrator', $config = ['ignore_request' => true])
    {
        return parent::getModel($name, $prefix, $config);
    }
}
