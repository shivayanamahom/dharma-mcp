<?php

/**
 * Dharma MCP — системный плагин адресов.
 *
 * @package    plg_system_dharmamcp
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

declare(strict_types=1);

namespace Dharma\Plugin\System\DharmaMcp\Extension;

\defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Uri\Uri;
use Joomla\Event\SubscriberInterface;

/**
 * Открывает MCP и OAuth по коротким адресам (до маршрутизации Joomla):
 *   /mcp                                      — MCP-эндпоинт;
 *   /oauth/token, /oauth/register             — OAuth;
 *   /oauth/authorize                          — страница согласия;
 *   /.well-known/oauth-authorization-server   — метаданные сервера авторизации (RFC 8414);
 *   /.well-known/oauth-protected-resource     — метаданные ресурса (RFC 9728).
 * Остальные запросы плагин не трогает. Без плагина всё работает по адресам index.php?option=com_dharma_mcp...
 */
final class DharmaMcp extends CMSPlugin implements SubscriberInterface
{
    protected $autoloadLanguage = true;

    /** Путь => [контроллер, задача]; null вместо задачи — стандартное действие контроллера. */
    private const ROUTES = [
        '/mcp' => ['Display', null],
        '/oauth/token' => ['Oauth', 'token'],
        '/oauth/register' => ['Oauth', 'register'],
        '/.well-known/oauth-authorization-server' => ['Oauth', 'server'],
        '/.well-known/openid-configuration' => ['Oauth', 'server'],
        '/.well-known/oauth-protected-resource' => ['Oauth', 'resource'],
    ];

    public static function getSubscribedEvents(): array
    {
        return ['onAfterInitialise' => 'onAfterInitialise'];
    }

    public function onAfterInitialise(): void
    {
        $app = $this->getApplication();
        if (!$app->isClient('site') || !ComponentHelper::isEnabled('com_dharma_mcp')) {
            return;
        }

        $path = rawurldecode((string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: ''));
        $base = Uri::base(true);
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, \strlen($base));
        }
        $path = '/' . trim((string) preg_replace('#^/index\.php#', '', $path), '/');

        // Метаданные допускают суффикс с путём ресурса: /.well-known/oauth-protected-resource/mcp
        $path = (string) preg_replace('#^(/\.well-known/[a-z\-]+)/.+$#', '$1', $path);

        if ($path === '/oauth/authorize') {
            $app->redirect(Uri::root() . 'index.php?option=com_dharma_mcp&view=authorize&' . (string) ($_SERVER['QUERY_STRING'] ?? ''), 302);

            return;
        }

        if (!isset(self::ROUTES[$path])) {
            return;
        }

        [$controller, $task] = self::ROUTES[$path];
        $input = $app->getInput();
        $input->set('option', 'com_dharma_mcp');

        // Штатный диспетчер определяет эти константы перед созданием контроллера; мы его обходим.
        foreach ([
            'JPATH_COMPONENT' => JPATH_BASE . '/components/com_dharma_mcp',
            'JPATH_COMPONENT_SITE' => JPATH_SITE . '/components/com_dharma_mcp',
            'JPATH_COMPONENT_ADMINISTRATOR' => JPATH_ADMINISTRATOR . '/components/com_dharma_mcp',
        ] as $name => $value) {
            if (!\defined($name)) {
                \define($name, $value);
            }
        }

        $instance =$app->bootComponent('com_dharma_mcp')->getMVCFactory()->createController($controller, 'Site', [], $app, $input);
        if ($task === null) {
            $instance->display();
        } else {
            $instance->{$task}();
        }

        // Контроллеры завершают приложение сами; сюда попадаем только если нет.
        $app->close();
    }
}
