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
use Dharma\Plugin\DharmaMcp\Shop\Entity\EntityCatalog;

/** Каталог управляемых сущностей магазина и описание полей формы каждой. */
final class ShopEntitiesTool extends AbstractEntityTool
{
    public function getName(): string
    {
        return 'shop.entities';
    }

    public function getTitle(): string
    {
        return 'Shop settings and reference data: what can be managed';
    }

    public function getDescription(): string
    {
        return 'Start here before creating or changing shop reference data. Without arguments lists the kinds of records that shop.entity_list / '
            . 'shop.entity_get / shop.entity_create / shop.entity_update / shop.entity_delete work with: categories, brands, badges, product fields and field groups '
            . '(the product filter is configured in the fields), product groups, promotions and promo codes, shipping and payment methods, order statuses, warehouses, '
            . 'customers and landing pages. With "entity" returns every field of that record as the shop admin form defines it: name (nested as "params.badge_color"), '
            . 'type, label, required, allowed values, and "only_if" conditions. Products have their own products.* tools; pass entity "product" here only to see their form fields.';
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
        return self::schema([
            'entity' => ['type' => 'string', 'description' => 'Kind of record to describe (see the list this tool returns without arguments). Use "product" to see all product form fields.', 'enum' => array_merge(EntityCatalog::keys(), ['product'])],
            'type' => self::str('Narrows the list to the settings of one type: for promotions, shipping and payment methods the "plugin" value (e.g. "coupon", "pickup"); for fields the field type (e.g. "list", "text").'),
        ]);
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        if (empty($arguments['entity'])) {
            $kinds = [];
            foreach (EntityCatalog::keys() as $key) {
                $def = EntityCatalog::get($key);
                $kinds[] = [
                    'entity' => $key,
                    'label' => $def['label'],
                    'about' => $def['summary'],
                    'can_delete' => $def['delete'] !== 'none',
                    'personal_data' => $def['pii'],
                ];
            }

            return ['entities' => $kinds, 'products' => 'Use products.search / products.get / products.create / products.update / products.delete.'];
        }

        $def = self::definition($arguments, true);
        $this->prepare($context, $def);
        $this->authorize($context, $def, 'core.create');

        $data = isset($arguments['type']) ? ['plugin' => (string) $arguments['type']] : [];
        $fields = $this->describeForm($context, $def, $data);

        // Для типа записи оставляем только его настройки: у доставки и полей их десятки, и чужие типы только мешают.
        if (!empty($arguments['type'])) {
            $type = (string) $arguments['type'];
            $fields = array_values(array_filter($fields, static function (array $f) use ($type): bool {
                if (!empty($f['only_if']) && preg_match('/^\.?(?:plugin|type):([A-Za-z0-9_,]+)/', (string) $f['only_if'], $m)) {
                    return \in_array($type, explode(',', $m[1]), true);
                }

                return true;
            }));
        }

        return [
            'entity' => $def['key'],
            'label' => $def['label'],
            'about' => $def['summary'],
            'fields' => $fields,
            'notes' => [
                'Send values in "data" as an object; nested names such as "params.badge_color" become {"params": {"badge_color": "..."}}.',
                'New records are always created unpublished. Publish with shop.entity_update and {"state": true}.',
                'Fields marked read_only cannot be set. Fields with "only_if" matter only when the condition holds.',
            ],
        ];
    }
}
