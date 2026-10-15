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
use Dharma\Component\DharmaShop\Administrator\Pricing\BatchPriceUpdater;
use Joomla\CMS\Language\Text;

/**
 * Пакетное изменение цен через BatchPriceUpdater магазина (тот же код, что и кнопка «Пакетно» в админке).
 */
final class PricesBatchTool extends AbstractShopTool
{
    private const MAX_PRODUCTS = 100;

    /** Больше этого числа товаров подтверждение нужно и в режиме полной записи. */
    private const CONFIRM_ABOVE = 10;

    private const PRICE_ACTIONS = ['skip', 'set', 'increase', 'decrease', 'increase_percent', 'decrease_percent'];

    public function getName(): string
    {
        return 'prices.batch';
    }

    public function getTitle(): string
    {
        return 'Change prices in bulk';
    }

    public function getDescription(): string
    {
        return 'Changes the price and/or discount of several products at once (up to ' . self::MAX_PRODUCTS . '). '
            . 'price_action: set, increase/decrease (amount) or increase_percent/decrease_percent. '
            . 'discount_action: set (amount or percent) or clear. Find product ids with products.search first. '
            . 'This changes live prices: the user must confirm, and for more than ' . self::CONFIRM_ABOVE . ' products confirmation is always required.';
    }

    public function getRequiredScope(): string
    {
        return 'shop:write';
    }

    public function getAccess(): Access
    {
        return Access::Write;
    }

    public function getInputSchema(): array
    {
        return self::schema([
            'product_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'minItems' => 1, 'maxItems' => self::MAX_PRODUCTS, 'description' => 'Ids of the products.'],
            'price_action' => ['type' => 'string', 'enum' => self::PRICE_ACTIONS, 'description' => 'How to change the price (default skip).'],
            'price_value' => ['type' => 'number', 'minimum' => 0, 'description' => 'Amount, or percent for the *_percent actions.'],
            'discount_action' => ['type' => 'string', 'enum' => ['skip', 'set', 'clear'], 'description' => 'What to do with the discount (default skip).'],
            'discount_value' => ['type' => 'number', 'exclusiveMinimum' => 0, 'description' => 'Discount amount or percent (see discount_percent).'],
            'discount_percent' => self::bool('true if discount_value is a percent.'),
        ], ['product_ids']);
    }

    public function needsConfirmation(array $arguments, ToolContext $context): bool
    {
        return \count((array) ($arguments['product_ids'] ?? [])) > self::CONFIRM_ABOVE;
    }

    /** @return int[] */
    private function ids(array $arguments): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($arguments['product_ids'] ?? [])))));
        if ($ids === []) {
            throw ToolException::validation("Parameter 'product_ids' must be a non-empty list of product ids.");
        }
        if (\count($ids) > self::MAX_PRODUCTS) {
            throw ToolException::validation('At most ' . self::MAX_PRODUCTS . ' products per call.');
        }

        return $ids;
    }

    /** Данные для BatchPriceUpdater в его формате. */
    private function data(array $arguments): array
    {
        $priceAction = self::oneOf($arguments, 'price_action', self::PRICE_ACTIONS, 'skip');
        $discountAction = self::oneOf($arguments, 'discount_action', ['skip', 'set', 'clear'], 'skip');
        if ($priceAction === 'skip' && $discountAction === 'skip') {
            throw ToolException::validation('Nothing to change: set price_action and/or discount_action.');
        }
        if ($priceAction !== 'skip' && !isset($arguments['price_value'])) {
            throw ToolException::validation("price_value is required for price_action '$priceAction'.");
        }
        if ($discountAction === 'set' && !isset($arguments['discount_value'])) {
            throw ToolException::validation("discount_value is required for discount_action 'set'.");
        }

        return [
            'price_action' => $priceAction,
            'price_value' => $arguments['price_value'] ?? null,
            'discount_action' => $discountAction,
            'discount_value' => $arguments['discount_value'] ?? null,
            'discount_percent' => !empty($arguments['discount_percent']) ? 1 : 0,
            'period_action' => 'skip',
        ];
    }

    private function authorize(ToolContext $context, array $ids): void
    {
        foreach ($ids as $id) {
            $context->requireCan('core.edit', 'com_dharmashop.product.' . $id, "edit product $id");
        }
    }

    public function preview(array $arguments, ToolContext $context): ?array
    {
        $shop = $this->services($context);
        $ids = $this->ids($arguments);
        $data = $this->data($arguments);
        $this->authorize($context, $ids);

        $sample = [];
        foreach (\array_slice($ids, 0, 10) as $id) {
            $variant = $shop->variants()->getDefault($id);
            $old = $variant && $variant->price !== null ? (float) $variant->price : null;
            $sample[] = ['id' => $id, 'price_now' => $old, 'price_after' => $old === null ? null : self::newPrice($old, $data)];
        }

        return [
            'action' => 'Bulk price change',
            'products' => \count($ids),
            'price_action' => $data['price_action'],
            'price_value' => $data['price_value'],
            'discount_action' => $data['discount_action'],
            'discount_value' => $data['discount_value'],
            'discount_is_percent' => (bool) $data['discount_percent'],
            'sample_first_10' => $sample,
        ];
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        $shop = $this->services($context);
        $ids = $this->ids($arguments);
        $data = $this->data($arguments);
        $this->authorize($context, $ids);

        try {
            $result = (new BatchPriceUpdater($context->db, $shop->rebuilder(), $shop->indexer()))->apply($ids, $data, $context->user());
        } catch (\InvalidArgumentException $e) {
            throw ToolException::validation(Text::_($e->getMessage()));
        }

        return $result + ['requested' => \count($ids)];
    }

    private static function newPrice(float $old, array $data): float
    {
        $value = (float) ($data['price_value'] ?? 0);

        return round(match ($data['price_action']) {
            'set' => $value,
            'increase' => $old + $value,
            'decrease' => max(0.0, $old - $value),
            'increase_percent' => $old * (1 + $value / 100),
            'decrease_percent' => max(0.0, $old * (1 - $value / 100)),
            default => $old,
        }, 2);
    }
}
