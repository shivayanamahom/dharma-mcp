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

/**
 * Изменение глобальной конфигурации через ApplicationModel компонента com_config
 * (он сам объединяет значения с текущей конфигурацией и записывает configuration.php).
 */
final class SystemConfigSetTool extends AbstractSystemTool
{
    public function getName(): string
    {
        return 'system.config_set';
    }

    public function getTitle(): string
    {
        return 'Change global configuration';
    }

    public function getDescription(): string
    {
        return 'Changes selected Joomla global configuration values: site name, offline mode and message (maintenance), SEO meta, list length, cache, session lifetime. '
            . 'Only the keys listed by system.config_get as writable are accepted. DANGEROUS: it rewrites configuration.php and affects the whole site '
            . '(offline mode hides the site from visitors), so the user must confirm. Needs a Joomla Super User token.';
    }

    public function getRequiredScope(): string
    {
        return 'system:write';
    }

    public function getAccess(): Access
    {
        return Access::Danger;
    }

    public function getInputSchema(): array
    {
        return self::schema([
            'values' => ['type' => 'object', 'description' => 'Configuration keys and new values, e.g. {"offline": "1", "offline_message": "Back soon"}.'],
        ], ['values']);
    }

    /** @return array<string, string> */
    private function values(array $arguments): array
    {
        $values = $arguments['values'] ?? null;
        if (!\is_array($values) || $values === []) {
            throw ToolException::validation("Parameter 'values' must be a non-empty object.");
        }

        $clean = [];
        foreach ($values as $key => $value) {
            $rule = self::WRITABLE_CONFIG[$key] ?? null;
            if ($rule === null) {
                throw ToolException::validation("Key '$key' cannot be changed through MCP.", ['writable' => array_keys(self::WRITABLE_CONFIG)]);
            }
            if (!\is_scalar($value)) {
                throw ToolException::validation("Value of '$key' must be a string or number.");
            }
            $value = (string) $value;
            if (\is_array($rule) && !\in_array($value, $rule, true)) {
                throw ToolException::validation("Value of '$key' must be one of: " . implode(', ', $rule) . '.');
            }
            if ($rule === 'int' && !preg_match('/^\d{1,6}$/', $value)) {
                throw ToolException::validation("Value of '$key' must be a whole number.");
            }
            $clean[(string) $key] = $value;
        }

        return $clean;
    }

    public function preview(array $arguments, ToolContext $context): ?array
    {
        $this->admin($context);
        $values = $this->values($arguments);

        $diff = [];
        foreach ($values as $key => $new) {
            $diff[$key] = ['from' => $context->app->get($key), 'to' => $new];
        }

        return [
            'action' => 'Change global configuration (rewrites configuration.php)',
            'changes' => $diff,
            'warning' => isset($values['offline']) && $values['offline'] === '1' ? 'This puts the whole site into offline mode: visitors will not see it.' : null,
            'configuration_file_writable' => is_writable(JPATH_CONFIGURATION . '/configuration.php'),
        ];
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        $this->admin($context);
        $values = $this->values($arguments);

        if (!is_writable(JPATH_CONFIGURATION . '/configuration.php')) {
            throw ToolException::conflict('configuration.php is not writable on this server, so it cannot be changed here. Make it writable temporarily or change the value in the Joomla admin.');
        }

        $context->app->getLanguage()->load('com_config', JPATH_ADMINISTRATOR);
        $model = $context->model('com_config', 'Application');
        if (!$model->save($values)) {
            $error = method_exists($model, 'getError') ? $model->getError() : '';
            throw ToolException::validation('Joomla could not save the configuration' . ($error ? ': ' . strip_tags((string) $error) : '.'));
        }

        return ['saved' => true, 'changed_keys' => array_keys($values)];
    }
}
