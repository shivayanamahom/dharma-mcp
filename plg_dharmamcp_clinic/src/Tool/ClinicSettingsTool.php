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

/** Настройки компонента «Клиника» (вкладки настроек в админке). Секреты не отдаются. */
final class ClinicSettingsTool extends AbstractEntityTool
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
        return 'clinic.settings';
    }

    public function getTitle(): string
    {
        return 'Read clinic settings';
    }

    public function getDescription(): string
    {
        return 'Returns the settings of the clinic component (the Settings pages of the admin: reviews, forms, notifications, SEO, sitemap, images, price list...) as key → value. '
            . 'Tokens, passwords and secrets are hidden. Use "search" to narrow by key text (for example "seo_", "reviews_", "notify_") and "describe": true to get the label, type and allowed values of the matching fields '
            . '(needed before clinic.settings_set).';
    }

    public function getRequiredScope(): string
    {
        return 'clinic:read';
    }

    public function getAccess(): Access
    {
        return Access::Read;
    }

    public function getInputSchema(): array
    {
        return self::schema([
            'search' => self::str('Part of the setting key, e.g. "seo_" or "notify_".'),
            'describe' => self::bool('true: also describe the matching form fields (label, type, allowed values).'),
        ]);
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        $this->boot($context);
        $context->requireCan('core.options', self::COMPONENT, 'read the clinic settings');
        $search = strtolower(trim((string) ($arguments['search'] ?? '')));
        $values = [];

        foreach ($this->current() as $key => $value) {
            if ($search !== '' && !str_contains(strtolower((string) $key), $search)) {
                continue;
            }

            $values[$key] = preg_match(self::SECRET, (string) $key) === 1 && $value !== '' && $value !== null ? '[hidden]' : $value;
        }

        $out = ['count' => \count($values), 'settings' => $values];

        if (!empty($arguments['describe'])) {
            $form = $context->model(self::COMPONENT === 'com_dharmaclinic' ? 'com_dharmaclinic' : self::COMPONENT, 'Settings')->getForm([], false);
            $fields = [];

            if ($form) {
                foreach ($form->getFieldsets() as $fieldset) {
                    foreach ($form->getFieldset($fieldset->name) as $field) {
                        $name = (string) $field->fieldname;

                        if (($search === '' || str_contains(strtolower($name), $search)) && preg_match(self::SECRET, $name) !== 1) {
                            $row = self::describeField($field);

                            if ($row !== null) {
                                $fields[] = $row;
                            }
                        }
                    }
                }
            }

            $out['fields'] = $fields;
        }

        return $out;
    }
}
