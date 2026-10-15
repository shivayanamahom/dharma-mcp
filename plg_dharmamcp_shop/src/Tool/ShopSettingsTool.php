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
use Joomla\CMS\Form\Form;

/** Настройки магазина (параметры com_dharmashop): валюта, поиск, фильтр, оформление, заказ, картинки, SEO. */
final class ShopSettingsTool extends AbstractEntityTool
{
    public function getName(): string
    {
        return 'shop.settings';
    }

    public function getTitle(): string
    {
        return 'Shop settings (currency, search, filter, display, checkout, images, SEO)';
    }

    public function getDescription(): string
    {
        return 'Shows the options of the shop component with their current values. Without "section" lists the sections (shop, catalog, display, favorites, cart, images, filter, seo, order...). '
            . 'With "section" returns every option of it: name, type, label, allowed values and the current value. The product filter on the shop level is the "filter" section. '
            . 'Change options with shop.settings_set.';
    }

    public function getRequiredScope(): string
    {
        return 'shop:read';
    }

    public function getAccess(): Access
    {
        return Access::Read;
    }

    public function getInputSchema(): array
    {
        return self::schema(['section' => self::str('Section name, e.g. "filter" or "catalog". Omit to list the sections.')]);
    }

    /** Форма настроек компонента из его config.xml. */
    public static function form(ToolContext $context): Form
    {
        $context->app->getLanguage()->load('com_dharmashop', JPATH_ADMINISTRATOR);
        $context->app->getLanguage()->load('com_dharmashop.sys', JPATH_ADMINISTRATOR);
        Form::addFieldPath(JPATH_ADMINISTRATOR . '/components/com_dharmashop/src/Field');
        $form = Form::getInstance('com_dharmashop.settings.mcp', JPATH_ADMINISTRATOR . '/components/com_dharmashop/config.xml', ['control' => 'jform'], false, '/config');
        $form->bind(ComponentHelper::getParams('com_dharmashop')->toArray());

        return $form;
    }

    /**
     * Поля по разделам (вкладкам настроек; вложенные группы входят в свой раздел).
     *
     * @return array<string, array<int, array<string, mixed>>>  раздел => поля
     */
    public static function sections(Form $form): array
    {
        $byGroup = [];

        foreach ($form->getFieldsets() as $fieldset) {
            foreach ($form->getFieldset($fieldset->name) as $field) {
                $row = self::describeField($field);

                if ($row === null || $row['name'] === 'rules') {
                    continue;
                }

                $value = $form->getValue($field->fieldname, $field->group ?: null);
                // Ключи и токены ассистенту не показываются (менять их можно, читать — нет).
                $secret = preg_match('/(token|secret|password|api_?key)/i', $row['name']) === 1;
                $row['value'] = $secret && $value !== null && $value !== '' ? '[hidden]' : $value;
                $byGroup[$fieldset->name][$row['name']] = $row;
            }
        }

        // Разделы верхнего уровня — вкладки config.xml; вложенные группы собираются в свою вкладку.
        $out = [];
        $xml = @simplexml_load_file(JPATH_ADMINISTRATOR . '/components/com_dharmashop/config.xml');

        foreach ($xml ? $xml->fieldset : [] as $tab) {
            $names = [(string) $tab['name']];
            foreach ($tab->xpath('.//fieldset') ?: [] as $inner) {
                $names[] = (string) $inner['name'];
            }
            $rows = [];
            foreach ($names as $name) {
                $rows += $byGroup[$name] ?? [];
            }
            if ($rows !== []) {
                $out[(string) $tab['name']] = array_values($rows);
            }
        }

        return $out;
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        $this->services($context);
        $sections = self::sections(self::form($context));

        if (empty($arguments['section'])) {
            return ['sections' => array_map('count', $sections), 'next' => 'Call again with "section" to see its options.'];
        }

        $name = (string) $arguments['section'];
        if (!isset($sections[$name])) {
            throw ToolException::validation("Unknown section '$name'.", ['allowed' => array_keys($sections)]);
        }

        return ['section' => $name, 'options' => $sections[$name]];
    }
}
