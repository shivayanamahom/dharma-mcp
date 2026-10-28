<?php
/** Dharma MCP — инструменты RadicalMart. */
declare(strict_types=1);
namespace Dharma\Plugin\DharmaMcp\Radicalmart\Tool;
\defined('_JEXEC') or die;
use Dharma\Component\DharmaMcp\Site\Tool\Access;
use Dharma\Component\DharmaMcp\Site\Tool\ToolContext;
use Dharma\Component\DharmaMcp\Site\Tool\ToolException;
final class EntityUpdateTool extends AbstractRadicalmartTool
{
    public function getName(): string { return 'radicalmart.entity_update'; }
    public function getDescription(): string { return 'Patches an existing RadicalMart product, category or characteristic through the native form and model; keeps omitted data. Supports prices.<currency group>.base, stock.all, fields, gallery in media and SEO. Publishing requires core.edit.state; root category cannot be changed. Call radicalmart.entities for native field names. Numeric lists replace, objects merge. Gallery: data.media.gallery replaces the whole list, so read the record first and send existing items too; upload new images with radicalmart.image_upload and use items {"type": "image", "src": "<path>", "alt": "..."}.'; }
    public function getRequiredScope(): string { return 'radicalmart:write'; }
    public function getAccess(): Access { return Access::Write; }
    public function getInputSchema(): array { return self::schema(['entity' => self::entityProperty(), 'id' => self::int('Record id.'), 'data' => ['type' => 'object', 'description' => 'Only changed native form fields; no id, author, rules or plugin-code fields.']], ['entity','id','data']); }
    private function changes(array $arguments, ToolContext $context): array
    {
        $def = $this->definition($arguments);
        $id = self::requireInt($arguments, 'id');
        $row = $this->load($context, $def, $id);
        if ($def['key'] === 'category' && $id === 1) { throw ToolException::forbidden('The root category is protected.'); }
        $patch = self::dataArgument($arguments);
        $this->validatePatch($def, $patch);
        self::normalizeGallery($patch);
        $this->authorize($context, $def, 'core.edit', $row);
        if (isset($patch['state']) && (int) $patch['state'] !== (int) ($row['state'] ?? 0)) {
            $this->authorize($context, $def, 'core.edit.state', $row);
        }
        if ($def['key'] === 'field' && ($patch['area'] ?? $row['area'] ?? '') !== 'products') { throw ToolException::forbidden('This pack manages product characteristics only.'); }
        if ($def['key'] === 'field' && array_key_exists('categories', $patch)) { $context->requireCan('core.edit', 'com_radicalmart.categories'); }
        [, $form] = $this->form($context, $def, self::merge($row, $patch));
        $this->assertFormPatch($form, $patch);
        return [$def, $row, $patch];
    }
    public function preview(array $arguments, ToolContext $context): ?array
    {
        [$def, $row, $patch] = $this->changes($arguments, $context);
        return ['action' => 'Update RadicalMart ' . $def['key'], 'id' => $row['id'],
            'before' => $this->clean($context, $def, array_intersect_key($row, $patch)), 'after' => $this->clean($context, $def, $patch)];
    }
    public function execute(array $arguments, ToolContext $context): array
    {
        [$def, $row, $patch] = $this->changes($arguments, $context);
        $data = self::merge($row, $patch);
        $id = $this->save($context, $def, $data);
        return ['id' => $id, 'entity' => $def['key'], 'updated_fields' => array_keys($patch)];
    }
}
