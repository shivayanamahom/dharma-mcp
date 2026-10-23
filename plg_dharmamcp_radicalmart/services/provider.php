<?php

/**
 * Dharma MCP — набор «shop».
 *
 * @package    plg_dharmamcp_radicalmart
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

\defined('_JEXEC') or die;

use Dharma\Plugin\DharmaMcp\Radicalmart\Extension\Radicalmart;
use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            static function (Container $container): Radicalmart {
                $plugin = new Radicalmart((array) PluginHelper::getPlugin('dharmamcp', 'radicalmart'));
                $plugin->setApplication(Factory::getApplication());

                return $plugin;
            }
        );
    }
};
