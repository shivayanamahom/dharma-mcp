<?php

/**
 * Dharma MCP — набор «content».
 *
 * @package    plg_dharmamcp_content
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

\defined('_JEXEC') or die;

use Dharma\Plugin\DharmaMcp\Content\Extension\Content;
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
            static function (Container $container): Content {
                $plugin = new Content((array) PluginHelper::getPlugin('dharmamcp', 'content'));
                $plugin->setApplication(Factory::getApplication());

                return $plugin;
            }
        );
    }
};
