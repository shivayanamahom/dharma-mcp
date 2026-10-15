<?php

/**
 * Dharma MCP — системный плагин адресов.
 *
 * @package    plg_system_dharmamcp
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

\defined('_JEXEC') or die;

use Dharma\Plugin\System\DharmaMcp\Extension\DharmaMcp;
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
            static function (Container $container): DharmaMcp {
                $plugin = new DharmaMcp((array) PluginHelper::getPlugin('system', 'dharmamcp'));
                $plugin->setApplication(Factory::getApplication());

                return $plugin;
            }
        );
    }
};
