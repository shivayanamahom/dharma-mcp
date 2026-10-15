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
use Dharma\Component\DharmaMcp\Site\Tool\ToolException;
use Joomla\CMS\Language\Text;
use Joomla\Database\ParameterType;

final class PluginsSetStateTool extends AbstractSystemTool
{
    /** Группы, которые нельзя трогать: сбой входа заблокирует доступ к админке. */
    private const FORBIDDEN_GROUPS = ['authentication'];

    public function getName(): string
    {
        return 'plugins.set_state';
    }

    public function getTitle(): string
    {
        return 'Enable or disable plugin';
    }

    public function getDescription(): string
    {
        return 'Enables or disables one Joomla plugin. DANGEROUS: plugins can change how the whole site works. '
            . 'Core protected plugins and the authentication group cannot be changed. The user must confirm. Needs a Joomla Super User token.';
    }

    public function getRequiredScope(): string
    {
        return 'system:write';
    }

    public function getAccess(): Access
    {
        return Access::Danger;
    }

    public function isIdempotent(): bool
    {
        return true;
    }

    public function getInputSchema(): array
    {
        return self::schema([
            'id' => self::int('Plugin extension id (see plugins.list).'),
            'enabled' => self::bool('true to enable, false to disable.'),
        ], ['id', 'enabled']);
    }

    private function plugin(ToolContext $context, int $id): object
    {
        $this->admin($context);
        $db = $context->db;
        $query = $db->createQuery()
            ->select($db->quoteName(['extension_id', 'name', 'element', 'folder', 'enabled', 'protected']))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('extension_id') . ' = :id')
            ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
            ->bind(':id', $id, ParameterType::INTEGER);
        $plugin = $db->setQuery($query)->loadObject();

        if (!$plugin) {
            throw ToolException::notFound("Plugin $id not found.");
        }
        if ((int) $plugin->protected === 1) {
            throw ToolException::forbidden('This is a protected core plugin and cannot be changed.');
        }
        if (\in_array((string) $plugin->folder, self::FORBIDDEN_GROUPS, true)) {
            throw ToolException::forbidden("Plugins of the '" . $plugin->folder . "' group cannot be changed through MCP.");
        }

        return $plugin;
    }

    public function preview(array $arguments, ToolContext $context): ?array
    {
        $plugin = $this->plugin($context, self::requireInt($arguments, 'id'));

        return [
            'action' => !empty($arguments['enabled']) ? 'Enable plugin' : 'Disable plugin',
            'plugin' => ['id' => (int) $plugin->extension_id, 'name' => Text::_((string) $plugin->name), 'group' => (string) $plugin->folder, 'element' => (string) $plugin->element],
            'currently_enabled' => (bool) $plugin->enabled,
        ];
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        $id = self::requireInt($arguments, 'id');
        $this->plugin($context, $id);
        $enable = !empty($arguments['enabled']);

        $context->app->getLanguage()->load('com_plugins', JPATH_ADMINISTRATOR);
        $model = $context->model('com_plugins', 'Plugin');
        $pks = [$id];
        if (!$model->publish($pks, $enable ? 1 : 0)) {
            $error = method_exists($model, 'getError') ? $model->getError() : '';
            throw ToolException::validation('Joomla could not change the plugin' . ($error ? ': ' . strip_tags((string) $error) : '.'));
        }

        return ['id' => $id, 'enabled' => $enable, 'changed' => true];
    }
}
