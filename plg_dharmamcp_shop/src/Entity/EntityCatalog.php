<?php

/**
 * Dharma MCP — набор «Магазин».
 *
 * @package    plg_dharmamcp_shop
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

declare(strict_types=1);

namespace Dharma\Plugin\DharmaMcp\Shop\Entity;

\defined('_JEXEC') or die;

/**
 * Справочник сущностей магазина, которыми можно управлять общими инструментами shop.entity_*.
 *
 * Запись всегда идёт через штатные модели магазина (форма, проверка, события, пересчёты), как в админке.
 * Здесь описано только то, чего модель о себе не сообщает: из какой таблицы показывать список, какой столбец
 * хранит состояние и можно ли сущность удалять.
 *
 * Ключи записи:
 *  label, summary — для ассистента;
 *  component, model — модель Administrator;
 *  table, columns, title, state, order, where — как строить список (только чтение);
 *  extension — тип категории (для категорий, брендов, бейджей);
 *  delete — 'trash' (корзина, затем удаление) или 'none' (удалять нельзя);
 *  pii — содержит персональные данные покупателей.
 */
final class EntityCatalog
{
    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        $shop = ['component' => 'com_dharmashop', 'state' => 'state', 'delete' => 'trash', 'order' => 'id ASC', 'pii' => false, 'hidden' => false];

        return [
            'category' => [
                'label' => 'Product category',
                'summary' => 'Catalogue category tree. A product lives in one main category (and may sit in extra ones). Fields: title, parent_id, description, image, SEO and display options.',
                'model' => 'Category', 'extension' => 'com_dharmashop', 'table' => '#__categories', 'state' => 'published', 'order' => 'lft ASC',
                'columns' => ['id', 'title', 'alias', 'parent_id', 'level', 'published', 'access'],
                'where' => "extension = 'com_dharmashop'",
            ] + $shop,
            'brand' => [
                'label' => 'Brand',
                'summary' => 'Product brand (manufacturer). A product has at most one brand. Fields: title, description, image, website.',
                'model' => 'Category', 'extension' => 'com_dharmashop.brand', 'table' => '#__categories', 'state' => 'published', 'order' => 'lft ASC',
                'columns' => ['id', 'title', 'alias', 'parent_id', 'level', 'published', 'access'],
                'where' => "extension = 'com_dharmashop.brand'",
            ] + $shop,
            'badge' => [
                'label' => 'Badge',
                'summary' => 'Label shown on product cards ("New", "Sale", "Hit"). Fields: title, colour, whether to show it in lists.',
                'model' => 'Category', 'extension' => 'com_dharmashop.badge', 'table' => '#__categories', 'state' => 'published', 'order' => 'lft ASC',
                'columns' => ['id', 'title', 'alias', 'published', 'access'],
                'where' => "extension = 'com_dharmashop.badge'",
            ] + $shop,
            'field' => [
                'label' => 'Product field',
                'summary' => 'A characteristic of products or categories (colour, size, material...). Defines its type, unit, where it is shown and how it works in the product filter (the filter is configured here, in the filter-related fields).',
                'model' => 'Field', 'table' => '#__dharmashop_fields', 'order' => 'ordering ASC, id ASC',
                'columns' => ['id', 'title', 'alias', 'target', 'type', 'group_id', 'required', 'filterable', 'in_card', 'in_page', 'unit', 'state'],
            ] + $shop,
            'field_group' => [
                'label' => 'Field group',
                'summary' => 'A named set of product fields shown together (a tab or a block). Fields: title, target (product or category), tab mode.',
                'model' => 'Fieldgroup', 'table' => '#__dharmashop_fieldgroups', 'order' => 'ordering ASC, id ASC',
                'columns' => ['id', 'title', 'alias', 'target', 'tab_mode', 'state'],
            ] + $shop,
            'product_group' => [
                'label' => 'Product group',
                'summary' => 'Variants of one product (for example sizes or colours) combined into one card.',
                'model' => 'Productgroup', 'table' => '#__dharmashop_product_groups', 'order' => 'id ASC',
                'columns' => ['id', 'title', 'alias', 'state', 'catid', 'brand_id', 'members_count', 'price_min', 'price_max'],
            ] + $shop,
            'promotion' => [
                'label' => 'Promotion',
                'summary' => 'A discount rule. Types: "coupon" (promo codes) and "threshold" (discount by order amount). Dates "starts" and "ends" limit it in time. Read the exact fields with shop.entities.',
                'model' => 'Promotion', 'table' => '#__dharmashop_promotions', 'order' => 'ordering ASC, id ASC',
                'columns' => ['id', 'title', 'plugin', 'state', 'starts', 'ends'],
            ] + $shop,
            'shipping_method' => [
                'label' => 'Shipping method',
                'summary' => 'How an order is delivered (courier, pickup, standard). The type is chosen at creation and cannot be changed later.',
                'model' => 'Shipping', 'table' => '#__dharmashop_shipping_methods', 'order' => 'ordering ASC, id ASC',
                'columns' => ['id', 'title', 'alias', 'plugin', 'is_default', 'state'],
            ] + $shop,
            'payment_method' => [
                'label' => 'Payment method',
                'summary' => 'How an order is paid. The type is chosen at creation and cannot be changed later.',
                'model' => 'Payment', 'table' => '#__dharmashop_payment_methods', 'order' => 'ordering ASC, id ASC',
                'columns' => ['id', 'title', 'alias', 'plugin', 'is_default', 'paid_status_id', 'state'],
            ] + $shop,
            'order_status' => [
                'label' => 'Order status',
                'summary' => 'A step of the order life cycle ("New", "Paid", "Shipped"...). Statuses cannot be deleted, only unpublished.',
                'model' => 'Status', 'table' => '#__dharmashop_statuses', 'order' => 'ordering ASC, id ASC', 'delete' => 'none',
                'columns' => ['id', 'title', 'alias', 'color', 'is_default', 'is_final', 'stock_effect', 'state'],
            ] + $shop,
            'warehouse' => [
                'label' => 'Warehouse / pickup point',
                'summary' => 'A place where goods are stored or picked up: address, phone, opening hours, map coordinates ("lat, lon").',
                'model' => 'Warehouse', 'table' => '#__dharmashop_warehouses', 'order' => 'ordering ASC, id ASC',
                'columns' => ['id', 'title', 'alias', 'address', 'ready_term', 'state'],
            ] + $shop,
            'customer' => [
                'label' => 'Customer',
                'summary' => 'A buyer record (name, e-mail, phone, note). Personal data: needs the "customers:pii" scope and the server setting that allows personal data. Customers cannot be deleted.',
                'model' => 'Customer', 'table' => '#__dharmashop_customers', 'state' => null, 'order' => 'id DESC', 'delete' => 'none', 'pii' => true,
                'columns' => ['id', 'name', 'email', 'phone', 'user_id', 'created'],
            ] + $shop,
            'landing_page' => [
                'label' => 'Landing page',
                'summary' => 'A saved product selection with its own address, picture, text and SEO ("Apple smartphones", "Gifts under 3000"). Needs the Dharma Shop landing component.',
                'component' => 'com_dharmashop_landing', 'model' => 'Page', 'table' => '#__dharmashop_landing_pages', 'order' => 'ordering ASC, id ASC',
                'columns' => ['id', 'title', 'alias', 'state', 'catid', 'source', 'created'],
            ] + $shop,
            // Не входит в общие инструменты: товары создаются и удаляются отдельными инструментами products.*.
            'product' => [
                'label' => 'Product', 'summary' => '', 'model' => 'Product', 'table' => '#__dharmashop_products', 'hidden' => true,
                'columns' => ['id', 'title', 'state'],
            ] + $shop,
        ];
    }

    /** @return string[] */
    public static function keys(): array
    {
        return array_keys(array_filter(self::all(), static fn (array $e): bool => !$e['hidden']));
    }

    /** @return array<string, mixed> */
    public static function get(string $key): array
    {
        $all = self::all();

        if (!isset($all[$key])) {
            throw new \InvalidArgumentException("Unknown entity '$key'. Known: " . implode(', ', self::keys()) . '.');
        }

        return ['key' => $key] + $all[$key];
    }
}
