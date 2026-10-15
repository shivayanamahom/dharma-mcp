<?php

/**
 * Dharma MCP — скрипт установки пакета.
 *
 * @package    pkg_dharma_mcp
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

declare(strict_types=1);

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

return new class () implements InstallerScriptInterface {
    /**
     * Наборы, включаемые при первой установке. «Шаблон» и «Система» дают доступ к файлам и настройкам сайта,
     * поэтому остаются выключенными: их включает администратор осознанно (Плагины → группа dharmamcp).
     */
    private const ENABLE_ON_INSTALL = ['shop', 'content'];

    public function install(InstallerAdapter $adapter): bool
    {
        return true;
    }

    public function update(InstallerAdapter $adapter): bool
    {
        return true;
    }

    public function uninstall(InstallerAdapter $adapter): bool
    {
        return true;
    }

    public function preflight(string $type, InstallerAdapter $adapter): bool
    {
        if (version_compare(PHP_VERSION, '8.3.0', '<')) {
            Factory::getApplication()->enqueueMessage('Dharma MCP требует PHP 8.3 или новее.', 'error');

            return false;
        }

        return true;
    }

    public function postflight(string $type, InstallerAdapter $adapter): bool
    {
        // Только при первой установке: при обновлении выбор администратора не трогаем.
        if ($type !== 'install') {
            return true;
        }

        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $shopInstalled = (int) $db->setQuery(
            $db->createQuery()
                ->select('COUNT(*)')
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('com_dharmashop'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
        )->loadResult() > 0;

        foreach (self::ENABLE_ON_INSTALL as $element) {
            if ($element === 'shop' && !$shopInstalled) {
                continue;
            }

            $query = $db->createQuery()
                ->update($db->quoteName('#__extensions'))
                ->set($db->quoteName('enabled') . ' = 1')
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                ->where($db->quoteName('folder') . ' = ' . $db->quote('dharmamcp'))
                ->where($db->quoteName('element') . ' = :element')
                ->bind(':element', $element, ParameterType::STRING);
            $db->setQuery($query)->execute();
        }

        return true;
    }
};
