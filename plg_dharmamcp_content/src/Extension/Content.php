<?php

/**
 * Dharma MCP — набор «content».
 *
 * @package    plg_dharmamcp_content
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

declare(strict_types=1);

namespace Dharma\Plugin\DharmaMcp\Content\Extension;

\defined('_JEXEC') or die;

use Dharma\Component\DharmaMcp\Site\Event\RegisterToolsEvent;
use Dharma\Component\DharmaMcp\Site\Tool\ToolInterface;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\SubscriberInterface;

/**
 * Регистрирует в Dharma MCP все инструменты из src/Tool/*Tool.php.
 * Отключение плагина в менеджере плагинов полностью убирает набор.
 */
final class Content extends CMSPlugin implements SubscriberInterface
{
    protected $autoloadLanguage = true;

    public static function getSubscribedEvents(): array
    {
        return ['onDharmaMcpRegisterTools' => 'onRegisterTools'];
    }

    public function onRegisterTools(RegisterToolsEvent $event): void
    {
        foreach (glob(\dirname(__DIR__) . '/Tool/*Tool.php') ?: [] as $file) {
            $class = 'Dharma\\Plugin\\DharmaMcp\\Content\\Tool\\' . basename($file, '.php');
            if (!class_exists($class) || !is_subclass_of($class, ToolInterface::class) || (new \ReflectionClass($class))->isAbstract()) {
                continue;
            }
            $event->register(new $class());
        }
    }
}
