<?php
/** Модели и разрешённые поля RadicalMart. Ключи БД и служебные поля через MCP не редактируются. */
declare(strict_types=1);
namespace Dharma\Plugin\DharmaMcp\Radicalmart\Entity;
\defined('_JEXEC') or die;
final class EntityCatalog
{
    public const DEFINITIONS = [
        'product' => ['model' => 'Product', 'list' => 'Products', 'section' => 'products', 'write' => true,
            'fields' => ['title','alias','code','category','categories_additional_categories','categories_additional_manufacturers','categories_additional_badges','introtext','fulltext','search_text','prices','stock','fields','media','params','state','language','metadesc','metakey','meta_variability']],
        'category' => ['model' => 'Category', 'list' => 'Categories', 'section' => 'categories', 'write' => true,
            'fields' => ['title','alias','parent_id','type','introtext','fulltext','description','fields','media','params','state','language','metadesc','metakey']],
        'field' => ['model' => 'Field', 'list' => 'Fields', 'section' => 'fields', 'write' => true,
            'fields' => ['title','alias','description','note','area','plugin','params','options','all_categories','categories','state','language','fieldset_site','fieldset_administrator']],
        'fieldset' => ['model' => 'Fieldset', 'list' => 'Fieldsets', 'section' => 'fieldsets', 'write' => false,
            'fields' => ['title','alias','description','area','params']],
        'meta' => ['model' => 'Meta', 'list' => 'Metas', 'section' => 'metas', 'write' => false,
            'fields' => ['title','alias','type','products','fields','prices','stock','media','state','language']],
        'status' => ['model' => 'Status', 'list' => 'Statuses', 'section' => 'statuses', 'write' => false,
            'fields' => ['title','alias','description','state']],
    ];
}
