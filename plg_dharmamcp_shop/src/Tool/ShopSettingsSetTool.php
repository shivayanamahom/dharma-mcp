<?php

/**
 * Dharma MCP — набор «Магазин».
 *
 * @package    plg_dharmamcp_shop
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

declare(strict_types=1);

namespace Dharma\Plugin\DharmaMcp\Shop\Tool;

\defined('_JEXEC') or die;

use Dharma\Component\DharmaMcp\Site\Tool\Access;
use Dharma\Component\DharmaMcp\Site\Tool\ToolContext;
use Dharma\Component\DharmaMcp\Site\Tool\ToolException;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Table\Table;
use Joomla\Registry\Registry;

/** Изменение настроек магазина по его форме настроек (с теми же фильтрами и проверками). */
final class ShopSettingsSetTool extends AbstractEntityTool
{
    public function getName(): string
    {
        return 'shop.settings_set';
    }

    public function getTitle(): string
    {
        return 'Change shop settings';
    }

    public function getDescription(): string
    {
        return 'Changes options of the shop component (see shop.settings for names and allowed values). Pass only the options you want to change in "options", e.g. {"currency_code": "EUR", "currency_symbol": "€"}. '
            . 'This changes how the live shop behaves: the user must confirm. Access rights of the component are not changed here.';
    }

    public function getRequiredScope(): string
    {
        return 'shop:write';
    }

    public function getAccess(): Access
    {
        return Access::Write;
    }

    public function isIdempotent(): bool
    {
        return true;
    }

    public function getInputSchema(): array
    {
        return self::schema(['options' => ['type' => 'object', 'description' => 'Option names and new values, as listed by shop.settings.']], ['options']);
    }

    /** @return array<string, mixed> */
    private function patch(array $arguments, array $known): array
    {
        $options = $arguments['options'] ?? null;

        if (!\is_array($options) || $options === [] || array_is_list($options)) {
            throw ToolException::validation("Parameter 'options' must be an object with at least one option.");
        }

        $unknown = array_diff(array_keys($options), $known);
        if ($unknown !== []) {
            throw ToolException::validation('Unknown options: ' . implode(', ', $unknown) . '. Look them up with shop.settings.');
        }

        return $options;
    }

    /** @return array{0: \Joomla\CMS\Form\Form, 1: string[]} */
    private function formAndNames(ToolContext $context): array
    {
        $this->services($context);
        $context->requireCan('core.admin', 'com_dharmashop', 'change the shop settings');
        $form = ShopSettingsTool::form($context);
        $names = [];
        foreach (ShopSettingsTool::sections($form) as $rows) {
            foreach ($rows as $row) {
                if (empty($row['read_only'])) {
                    $names[] = $row['name'];
                }
            }
        }

        return [$form, $names];
    }

    /** Прежнее значение ключа или токена в предпросмотре не показывается. */
    private static function hideSecrets(array $changes): array
    {
        foreach ($changes as $name => $change) {
            if (preg_match('/(token|secret|password|api_?key)/i', (string) $name) === 1) {
                $changes[$name]['from'] = '[hidden]';
            }
        }

        return $changes;
    }

    public function preview(array $arguments, ToolContext $context): ?array
    {
        [$form, $names] = $this->formAndNames($context);
        $patch = $this->patch($arguments, $names);
        $current = ComponentHelper::getParams('com_dharmashop')->toArray();

        return ['action' => 'Change shop settings', 'changes' => self::hideSecrets(self::changes($current, $patch))];
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        [$form, $names] = $this->formAndNames($context);
        $patch = $this->patch($arguments, $names);
        $current = ComponentHelper::getParams('com_dharmashop')->toArray();
        $changes = self::changes($current, $patch);
        $merged = self::merge($current, $patch);

        $valid = $form->validate($merged);
        if ($valid === false) {
            $errors = array_map(static fn ($e): string => trim(strip_tags($e instanceof \Throwable ? $e->getMessage() : (string) $e)), $form->getErrors());

            throw ToolException::validation('The shop rejected the options: ' . rtrim(implode(' ', $errors), '. ') . '.');
        }

        // Фильтры полей формы (целые числа, очистка строк) применяются так же, как при сохранении в админке; остальные параметры не трогаются.
        $filtered = $form->filter($merged);
        $params = array_replace($current, array_intersect_key($filtered, $patch));

        $table = Table::getInstance('extension');
        if (!$table->load(['element' => 'com_dharmashop', 'type' => 'component'])) {
            throw ToolException::notFound('The shop component record was not found.');
        }

        $table->params = (new Registry($params))->toString();
        if (!$table->check() || !$table->store()) {
            throw ToolException::validation('The settings could not be saved: ' . trim(strip_tags(Text::_((string) $table->getError()))) . '.');
        }

        \Joomla\CMS\Factory::getContainer()->get(\Joomla\CMS\Cache\CacheControllerFactoryInterface::class)
            ->createCacheController('callback', ['defaultgroup' => '_system'])->clean();
        ComponentHelper::getComponent('com_dharmashop', true);

        return ['updated' => true, 'changed_options' => array_keys($changes)];
    }
}
