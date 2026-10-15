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

/** Список записей сущности магазина. */
final class EntityListTool extends AbstractEntityTool
{
    public function getName(): string
    {
        return 'shop.entity_list';
    }

    public function getTitle(): string
    {
        return 'List categories, brands, fields, promotions, shipping and other shop records';
    }

    public function getDescription(): string
    {
        return 'Lists records of one kind (category, brand, badge, field, field_group, product_group, promotion, shipping_method, payment_method, order_status, warehouse, customer, landing_page) '
            . 'with their main columns. Trashed records are hidden unless state is "trashed" or "all". Use shop.entity_get for the full record.';
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
            'entity' => self::entityProperty(),
            'search' => self::str('Part of the title.'),
            'state' => self::str('Which records: "active" (published and unpublished, default), "published", "unpublished", "trashed" or "all".', ['enum' => ['active', 'published', 'unpublished', 'trashed', 'all']]),
        ] + self::paging(200), ['entity']);
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        $def = self::definition($arguments);
        $this->prepare($context, $def);
        [$limit, $offset] = $context->page($arguments, 50, 200);
        $page = $this->rows($context, $def, $arguments, $limit, $offset);

        return ['entity' => $def['key']] + self::paged($page['items'], $page['total'], $limit, $offset);
    }
}
