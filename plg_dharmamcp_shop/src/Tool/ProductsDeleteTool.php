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
use Dharma\Plugin\DharmaMcp\Shop\Entity\EntityCatalog;
use Joomla\CMS\Language\Text;

/** Удаление товара: в корзину или насовсем, через ProductModel магазина (индексы, галерея, фильтр, группы). */
final class ProductsDeleteTool extends AbstractEntityTool
{
    public function getName(): string
    {
        return 'products.delete';
    }

    public function getTitle(): string
    {
        return 'Delete a product';
    }

    public function getDescription(): string
    {
        return 'Moves a product to the trash (it disappears from the shop; restore it with products.update {"published": true}) or, with permanent=true, deletes it for good with its gallery and field values. '
            . 'Past orders keep their own copy of the item, so they are not affected. The user must always confirm. Never delete on your own initiative.';
    }

    public function getRequiredScope(): string
    {
        return 'shop:write';
    }

    public function getAccess(): Access
    {
        return Access::Danger;
    }

    public function getInputSchema(): array
    {
        return self::schema([
            'id' => self::int('Product id.'),
            'permanent' => self::bool('true deletes for good (no way back). Default false: only moves to the trash.'),
        ], ['id']);
    }

    private function check(ToolContext $context, int $id): array
    {
        $def = EntityCatalog::get('product');
        $this->prepare($context, $def);
        $context->requireCan('core.delete', 'com_dharmashop.product.' . $id, 'delete this product');
        $product = ProductsGetTool::load($context, $id);

        return [$def, $product];
    }

    public function preview(array $arguments, ToolContext $context): ?array
    {
        $id = self::requireInt($arguments, 'id');
        [, $product] = $this->check($context, $id);
        $db = $context->db;
        $orders = (int) $db->setQuery(
            $db->createQuery()->select('COUNT(DISTINCT order_id)')->from($db->quoteName('#__dharmashop_order_items'))->where('product_id = ' . $id)
        )->loadResult();

        return [
            'action' => !empty($arguments['permanent']) ? 'Delete product permanently' : 'Move product to the trash',
            'product' => ['id' => $id, 'title' => (string) $product->title],
            'orders_with_this_product' => $orders,
        ];
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        $id = self::requireInt($arguments, 'id');
        [$def, $product] = $this->check($context, $id);
        $model = $this->model($context, $def);

        if ((int) $product->state !== -2) {
            $pks = [$id];
            if (!$model->publish($pks, -2)) {
                throw ToolException::validation('The shop could not move the product to the trash: ' . trim(strip_tags(Text::_((string) $model->getError()))) . '.');
            }
        }

        if (empty($arguments['permanent'])) {
            return ['deleted' => false, 'trashed' => true, 'id' => $id, 'title' => (string) $product->title];
        }

        $pks = [$id];
        if (!$model->delete($pks)) {
            $why = rtrim(trim(strip_tags(Text::_((string) $model->getError()))), '. ');

            throw ToolException::validation('The shop refused to delete the product' . ($why !== '' ? ': ' . $why : '') . '. It stays in the trash.');
        }

        return ['deleted' => true, 'id' => $id, 'title' => (string) $product->title];
    }
}
