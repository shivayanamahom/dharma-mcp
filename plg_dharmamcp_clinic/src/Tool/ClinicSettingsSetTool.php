<?php

/**
 * Dharma MCP — набор «Клиника».
 *
 * @package    plg_dharmamcp_clinic
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

declare(strict_types=1);

namespace Dharma\Plugin\DharmaMcp\Clinic\Tool;

\defined('_JEXEC') or die;

use Dharma\Component\DharmaMcp\Site\Tool\Access;
use Dharma\Component\DharmaMcp\Site\Tool\ToolContext;
use Dharma\Component\DharmaMcp\Site\Tool\ToolException;
use Joomla\CMS\Language\Text;

/** Изменение настроек компонента «Клиника»: через форму и модель настроек, остальные значения не трогаются. */
final class ClinicSettingsSetTool extends AbstractEntityTool
{

    /** Ключи, которые через MCP не читаются и не пишутся: токены, пароли, секреты. */
    protected const SECRET = '/(token|secret|password|api_?key)/i';

    /** @return array<string, mixed> */
    protected function current(): array
    {
        return \Joomla\CMS\Component\ComponentHelper::getParams(self::COMPONENT)->toArray();
    }

    public function getName(): string
    {
        return 'clinic.settings_set';
    }

    public function getTitle(): string
    {
        return 'Change clinic settings';
    }

    public function getDescription(): string
    {
        return 'Changes one or more settings of the clinic component, exactly like the Settings pages of the admin (the values are checked by the same form; all other settings stay as they are). '
            . 'Read the keys and allowed values first with clinic.settings and "describe": true. Tokens, passwords and secrets cannot be set here. '
            . 'Settings change how the live site works (forms, notifications, SEO, sitemap): the user must confirm every change.';
    }

    public function getRequiredScope(): string
    {
        return 'clinic:write';
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
            'settings' => ['type' => 'object', 'description' => 'Only the keys to change, e.g. {"reviews_limit": 12, "seo_robots": ""}.'],
        ], ['settings']);
    }

    /** @return array<string, mixed> */
    private function patch(array $arguments, ToolContext $context): array
    {
        $this->boot($context);
        $context->requireCan('core.options', self::COMPONENT, 'change the clinic settings');
        $patch = $arguments['settings'] ?? null;

        if (!\is_array($patch) || $patch === [] || array_is_list($patch)) {
            throw ToolException::validation("'settings' must be an object with at least one key.");
        }

        $current = $this->current();

        foreach ($patch as $key => $value) {
            if (preg_match(self::SECRET, (string) $key) === 1) {
                throw ToolException::forbidden("The setting '$key' holds a secret and cannot be changed through MCP. Ask the site owner to change it in the admin.");
            }
            if (!\array_key_exists($key, $current) && !$this->inForm($context, (string) $key)) {
                throw ToolException::validation("Unknown setting '$key'. Use clinic.settings to see the keys.");
            }
            if (!\is_scalar($value) && $value !== null && !\is_array($value)) {
                throw ToolException::validation("The value of '$key' must be text, a number or a list.");
            }

            // Форма приводит нечисловой текст в числовом поле к нулю и молча сохраняет: проверяем до этого.
            $field = $this->field($context, (string) $key);

            if ($field !== null && \in_array(strtolower((string) $field->type), ['number', 'integer'], true) && $value !== '' && $value !== null && !is_numeric($value)) {
                throw ToolException::validation("The value of '$key' must be a number.");
            }
        }

        return $patch;
    }

    private function field(ToolContext $context, string $key): ?object
    {
        $form = $context->model(self::COMPONENT, 'Settings')->getForm([], false);
        $field = $form ? $form->getField($key) : false;

        return $field === false ? null : $field;
    }

    private function inForm(ToolContext $context, string $key): bool
    {
        $form = $context->model(self::COMPONENT, 'Settings')->getForm([], false);

        return $form && $form->getField($key) !== false;
    }

    public function preview(array $arguments, ToolContext $context): ?array
    {
        $patch = $this->patch($arguments, $context);

        return ['action' => 'Change clinic settings', 'changes' => self::changes($this->current(), $patch)];
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        $patch = $this->patch($arguments, $context);
        $current = $this->current();
        $model = $context->model(self::COMPONENT, 'Settings');
        $form = $model->getForm([], false);

        if (!$form) {
            throw ToolException::validation('The clinic could not build the settings form.');
        }

        $merged = array_merge($current, $patch);
        $valid = $model->validate($form, $merged);

        if ($valid === false) {
            $errors = array_map(static fn ($e): string => trim(strip_tags((string) ($e instanceof \Throwable ? $e->getMessage() : $e))), $model->getErrors());

            throw ToolException::validation('The clinic rejected the settings: ' . rtrim(implode(' ', array_filter($errors)), '. ') . '.', ['errors' => array_values(array_filter($errors))]);
        }

        // Значения, которых нет в форме (служебные: адрес сайта для публичных файлов, ключ фида), форма отбрасывает — возвращаем прежние.
        $params = $valid + array_diff_key($current, $valid);
        $component = $model->getComponent();
        $saved = $model->save(['params' => $params, 'id' => (int) $component->id, 'option' => self::COMPONENT]);

        if (!$saved) {
            throw ToolException::validation('The clinic refused to save the settings: ' . trim(strip_tags(Text::_((string) $model->getError()))) . '.');
        }

        return ['updated' => true, 'changed_keys' => array_keys(self::changes($current, $patch))];
    }
}
