<?php

/**
 * Dharma MCP — набор «Система».
 *
 * @package    plg_dharmamcp_system
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

declare(strict_types=1);

namespace Dharma\Plugin\DharmaMcp\System\Tool;

\defined('_JEXEC') or die;

use Dharma\Component\DharmaMcp\Site\Tool\Access;
use Dharma\Component\DharmaMcp\Site\Tool\ToolContext;
use Joomla\CMS\Version;

final class SystemInfoTool extends AbstractSystemTool
{
    public function getName(): string
    {
        return 'system.info';
    }

    public function getTitle(): string
    {
        return 'System information';
    }

    public function getDescription(): string
    {
        return 'Diagnostic overview of the site and server: Joomla, PHP and database versions, key PHP limits and extensions, '
            . 'whether important folders and files are writable, and main site settings (offline, debug, cache, SEF). No secrets are returned. '
            . 'Needs a Joomla Super User token.';
    }

    public function getRequiredScope(): string
    {
        return 'system:read';
    }

    public function getAccess(): Access
    {
        return Access::Read;
    }

    public function getInputSchema(): array
    {
        return self::schema();
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        $this->admin($context);
        $app = $context->app;

        $paths = [];
        foreach (['configuration.php' => JPATH_CONFIGURATION . '/configuration.php', 'tmp' => JPATH_ROOT . '/tmp', 'cache' => JPATH_ROOT . '/cache',
            'administrator/logs' => JPATH_ADMINISTRATOR . '/logs', 'images' => JPATH_ROOT . '/images', 'templates' => JPATH_ROOT . '/templates'] as $label => $path) {
            $paths[$label] = ['exists' => file_exists($path), 'writable' => is_writable($path)];
        }

        // Списками, а не ключами: имена вроде «zip» совпали бы с полем почтового индекса и были бы замаскированы.
        $wanted = ['mbstring', 'gd', 'intl', 'curl', 'zip', 'json', 'xml', 'pdo_mysql', 'opcache', 'imagick', 'fileinfo'];
        $extensions = [
            'loaded' => array_values(array_filter($wanted, 'extension_loaded')),
            'missing' => array_values(array_filter($wanted, static fn (string $e): bool => !\extension_loaded($e))),
        ];

        return [
            'site_name' => $app->get('sitename'),
            'joomla' => Version::MAJOR_VERSION . '.' . Version::MINOR_VERSION . '.' . Version::PATCH_VERSION,
            'php' => ['version' => PHP_VERSION, 'sapi' => PHP_SAPI, 'memory_limit' => ini_get('memory_limit'), 'max_execution_time' => ini_get('max_execution_time'),
                'upload_max_filesize' => ini_get('upload_max_filesize'), 'post_max_size' => ini_get('post_max_size'), 'extensions' => $extensions],
            'database' => ['type' => $context->db->getServerType(), 'version' => $context->db->getVersion(), 'prefix' => $context->db->getPrefix()],
            'server' => ['software' => $_SERVER['SERVER_SOFTWARE'] ?? null, 'https' => $app->isSslConnection()],
            'paths' => $paths,
            'settings' => [
                'offline' => (bool) $app->get('offline'),
                'debug' => (bool) $app->get('debug'),
                'error_reporting' => $app->get('error_reporting'),
                'caching' => (int) $app->get('caching'),
                'sef' => (bool) $app->get('sef'),
                'sef_rewrite' => (bool) $app->get('sef_rewrite'),
                'timezone' => $app->get('offset'),
                'mailer' => $app->get('mailer'),
                'session_lifetime_min' => (int) $app->get('lifetime'),
            ],
        ];
    }
}
