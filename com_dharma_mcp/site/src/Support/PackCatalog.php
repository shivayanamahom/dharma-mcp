<?php
/** Dharma MCP: наборы инструментов — какие включены, чьи продукты установлены; включение из настроек компонента. */
declare(strict_types=1);

namespace Dharma\Component\DharmaMcp\Site\Support;

\defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

/**
 * Наборы инструментов — плагины группы `dharmamcp`. Единственный источник правды о том, включён ли набор, — само состояние
 * плагина; переключатели в настройках компонента (`pack_<набор>`) только показывают его и по «Сохранить» включают или
 * выключают плагин (поэтому менеджер плагинов и настройки никогда не расходятся). Набор продукта, которого на сайте нет
 * (Dharma Shop, RadicalMart, Dharma Clinic), включить нельзя; «Шаблон» и «Система» (файлы и настройки сайта) — только Суперпользователю.
 */
final class PackCatalog
{
    /** Набор => [компонент, из-за которого он нужен (null — всегда доступен), опасный]. */
    public const PACKS = [
        'shop' => ['com_dharmashop', false],
        'radicalmart' => ['com_radicalmart', false],
        'clinic' => ['com_dharmaclinic', false],
        'content' => [null, false],
        'template' => [null, true],
        'system' => [null, true],
    ];

    public static function field(string $key): string
    {
        return 'pack_' . $key;
    }

    /** Есть ли продукт, для которого предназначен набор. */
    public static function available(string $key): bool
    {
        $component = self::PACKS[$key][0] ?? null;

        return $component === null || ComponentHelper::isEnabled($component);
    }

    public static function dangerous(string $key): bool
    {
        return (bool) (self::PACKS[$key][1] ?? false);
    }

    /** Включён ли плагин набора. */
    public static function enabled(string $key): bool
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $folder = 'dharmamcp';
        $query = $db->createQuery()
            ->select($db->quoteName('enabled'))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
            ->where($db->quoteName('folder') . ' = :folder')
            ->where($db->quoteName('element') . ' = :element')
            ->bind(':folder', $folder, ParameterType::STRING)
            ->bind(':element', $key, ParameterType::STRING);

        return (int) $db->setQuery($query)->loadResult() === 1;
    }

    /** Включает или выключает плагин набора. Возвращает true, если состояние изменилось. */
    public static function set(string $key, bool $on): bool
    {
        if (!isset(self::PACKS[$key]) || self::enabled($key) === $on) {
            return false;
        }

        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $folder = 'dharmamcp';
        $value = $on ? 1 : 0;
        $query = $db->createQuery()
            ->update($db->quoteName('#__extensions'))
            ->set($db->quoteName('enabled') . ' = :value')
            ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
            ->where($db->quoteName('folder') . ' = :folder')
            ->where($db->quoteName('element') . ' = :element')
            ->bind(':value', $value, ParameterType::INTEGER)
            ->bind(':folder', $folder, ParameterType::STRING)
            ->bind(':element', $key, ParameterType::STRING);
        $db->setQuery($query)->execute();

        // Список включённых плагинов Joomla кэширует.
        Factory::getContainer()->get(\Joomla\CMS\Cache\CacheControllerFactoryInterface::class)->createCacheController('callback', ['defaultgroup' => 'com_plugins', 'application' => 'administrator'])->clean();

        return true;
    }
}
