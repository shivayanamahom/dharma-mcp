<?php
declare(strict_types=1);

namespace Dharma\Component\DharmaShopMcp\Site\Tool;

\defined('_JEXEC') or die;

use Dharma\Component\DharmaShopMcp\Site\Auth\AuthResult;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Version;

final class ShopInfoTool implements ToolInterface
{
    public function getName(): string
    {
        return 'shop.info';
    }

    public function getDescription(): string
    {
        return 'Возвращает общую информацию о магазине Dharma Shop (версии, валюта, режим работы, число товаров и заказов).';
    }

    public function getRequiredScope(): string
    {
        return 'shop:read';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [],
        ];
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function isDestructive(): bool
    {
        return false;
    }

    public function execute(array $arguments, AuthResult $auth): array
    {
        $app = Factory::getApplication();
        $db = Factory::getDbo();

        $shopParams = ComponentHelper::getParams('com_dharmashop');
        $mcpParams = ComponentHelper::getParams('com_dharmashop_mcp');

        // Считаем опубликованные товары и заказы
        $productsCount = 0;
        $ordersCount = 0;
        try {
            $productsCount = (int) $db->setQuery('SELECT COUNT(*) FROM #__dharmashop_products WHERE state = 1')->loadResult();
            $ordersCount = (int) $db->setQuery('SELECT COUNT(*) FROM #__dharmashop_orders')->loadResult();
        } catch (\Throwable) {}

        return [
            'site_name' => $app->get('sitename'),
            'joomla_version' => Version::MAJOR_VERSION . '.' . Version::MINOR_VERSION . '.' . Version::PATCH_VERSION,
            'dharma_shop' => [
                'installed' => ComponentHelper::isEnabled('com_dharmashop'),
                'currency' => $shopParams->get('currency', 'RUB'),
                'products_count' => $productsCount,
                'orders_count' => $ordersCount,
            ],
            'mcp_server' => [
                'version' => '0.1.0',
                'mode' => $mcpParams->get('server_mode', 'drafts'),
                'pii_enabled' => (bool) $mcpParams->get('pii_enabled', 0),
            ],
            'authenticated_as' => [
                'name' => $auth->user->name,
                'username' => $auth->user->username,
                'scopes' => $auth->scopes,
            ],
        ];
    }
}
