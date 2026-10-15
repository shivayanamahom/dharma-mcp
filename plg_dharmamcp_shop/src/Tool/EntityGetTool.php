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
use Joomla\Database\ParameterType;

/** Полная запись сущности магазина. */
final class EntityGetTool extends AbstractEntityTool
{
    public function getName(): string
    {
        return 'shop.entity_get';
    }

    public function getTitle(): string
    {
        return 'Get one shop record in full';
    }

    public function getDescription(): string
    {
        return 'Returns the full record (all form fields with current values) of a category, brand, badge, field, field group, product group, promotion, shipping or payment method, '
            . 'order status, warehouse, customer or landing page. Promotions include their promo codes inside "params".';
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
        return self::schema(['entity' => self::entityProperty(), 'id' => self::int('Record id.')], ['entity', 'id']);
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        $def = self::definition($arguments);
        $this->prepare($context, $def);
        $id = self::requireInt($arguments, 'id');
        $record = $this->load($context, $def, $id);

        if (isset($def['extension']) && ($record['extension'] ?? $def['extension']) !== $def['extension']) {
            throw \Dharma\Component\DharmaMcp\Site\Tool\ToolException::notFound(ucfirst((string) $def['label']) . " $id not found.");
        }

        $result = ['entity' => $def['key'], 'record' => $record];

        // Для категорий, брендов и бейджей полезно знать, сколько в них товаров.
        if (isset($def['extension'])) {
            try {
                $result['product_counts'] = $this->model($context, $def)->getProductCounts($id);
            } catch (\Throwable) {
                // счётчик необязателен
            }
        }

        return $result;
    }
}
