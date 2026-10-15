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
use Joomla\CMS\Extension\ExtensionHelper;
use Joomla\CMS\Version;
use Joomla\Registry\Registry;

final class ShopInfoTool extends AbstractShopTool
{
    public function getName(): string
    {
        return 'shop.info';
    }

    public function getTitle(): string
    {
        return 'Shop overview';
    }

    public function getDescription(): string
    {
        return 'Overview of the online shop: site name, Joomla and Dharma Shop versions, number of published products, '
            . 'number of orders and the list of order statuses (ids are needed for orders.set_status). Call it first to orient yourself.';
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
        return self::schema();
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        $this->services($context);

        $products = $context->model('com_dharmashop', 'Products');
        $products->setState('filter.published', 1);
        $products->setState('list.limit', 1);

        $orders = $context->model('com_dharmashop', 'Orders');
        $orders->setState('list.limit', 1);

        $statusesModel = $context->model('com_dharmashop', 'Statuses');
        $statusesModel->setState('filter.state', 1);
        $statusesModel->setState('list.limit', 100);
        $statuses = [];
        foreach ($statusesModel->getItems() ?: [] as $status) {
            $statuses[] = [
                'id' => (int) $status->id,
                'alias' => (string) $status->alias,
                'title' => (string) $status->title,
                'is_default' => (bool) $status->is_default,
            ];
        }

        $record = ExtensionHelper::getExtensionRecord('com_dharmashop', 'component');
        $version = $record ? (new Registry((string) $record->manifest_cache))->get('version') : null;

        return [
            'site_name' => $context->app->get('sitename'),
            'joomla_version' => Version::MAJOR_VERSION . '.' . Version::MINOR_VERSION . '.' . Version::PATCH_VERSION,
            'dharma_shop_version' => $version,
            'published_products' => (int) $products->getTotal(),
            'orders_total' => (int) $orders->getTotal(),
            'order_statuses' => $statuses,
            'mcp' => [
                'server_mode' => $context->serverMode,
                'personal_data_allowed' => $context->piiAllowed(),
            ],
        ];
    }
}
