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

/** Создание записи сущности магазина. Всегда снятой с публикации. */
final class EntityCreateTool extends AbstractEntityTool
{
    public function getName(): string
    {
        return 'shop.entity_create';
    }

    public function getTitle(): string
    {
        return 'Create a category, brand, badge, field, promotion, shipping method or other shop record';
    }

    public function getDescription(): string
    {
        return 'Creates one record: category, brand, badge, field, field_group, product_group, promotion (with promo codes), shipping_method, payment_method, order_status, warehouse, customer or landing_page. '
            . 'Call shop.entities with the same "entity" first to learn the fields. Pass them in "data". The record is always created unpublished (hidden from shoppers); '
            . 'publish it later with shop.entity_update and {"state": true} after the user agrees. Returns the new id.';
    }

    public function getRequiredScope(): string
    {
        return 'shop:write';
    }

    public function getAccess(): Access
    {
        return Access::Draft;
    }

    public function getInputSchema(): array
    {
        return self::schema([
            'entity' => self::entityProperty(),
            'data' => ['type' => 'object', 'description' => 'Field values as listed by shop.entities. Example for a badge: {"title": "Sale", "params": {"badge_color": "danger"}}.'],
        ], ['entity', 'data']);
    }

    /** @return array<string, mixed> */
    private function newData(array $arguments, array $def): array
    {
        $data = self::data($arguments, $def);
        unset($data['id']);

        if ($def['state'] !== null) {
            $data[$def['state']] = 0;
        }
        if (isset($def['extension'])) {
            $data['extension'] = $def['extension'];
        }

        // Поле без выбранных категорий иначе сохраняется «ни к чему не применяется» и потом не даёт себя править.
        if ($def['key'] === 'field' && !isset($data['all_categories']) && empty($data['catids'])) {
            $data['all_categories'] = 1;
        }

        return $data;
    }

    public function preview(array $arguments, ToolContext $context): ?array
    {
        $def = self::definition($arguments);
        $this->prepare($context, $def);
        $this->authorize($context, $def, 'core.create');

        return ['action' => 'Create ' . $def['label'], 'created_unpublished' => $def['state'] !== null, 'data' => $this->newData($arguments, $def)];
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        $def = self::definition($arguments);
        $this->prepare($context, $def);
        $this->authorize($context, $def, 'core.create');

        $id = $this->store($context, $def, $this->newData($arguments, $def));
        $record = $id > 0 ? $this->load($context, $def, $id) : [];

        return [
            'created' => true,
            'entity' => $def['key'],
            'id' => $id,
            'title' => self::titleOf($def, $record),
            'published' => $def['state'] === null ? null : ((int) ($record[$def['state']] ?? 0) === 1),
            'next' => $def['state'] === null ? null : 'The record is unpublished. Ask the user before publishing it with shop.entity_update.',
        ];
    }
}
