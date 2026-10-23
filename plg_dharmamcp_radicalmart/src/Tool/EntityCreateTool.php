<?php
/** Dharma MCP — инструменты RadicalMart. */
declare(strict_types=1);
namespace Dharma\Plugin\DharmaMcp\Radicalmart\Tool;
\defined('_JEXEC') or die;
use Dharma\Component\DharmaMcp\Site\Tool\Access;
use Dharma\Component\DharmaMcp\Site\Tool\ToolContext;
use Dharma\Component\DharmaMcp\Site\Tool\ToolException;
final class EntityCreateTool extends AbstractRadicalmartTool
{
    public function getName(): string { return 'radicalmart.entity_create'; }
    public function getDescription(): string { return 'Creates an unpublished RadicalMart product, category (also manufacturer or badge), or custom field through its native form validation and model. Call radicalmart.entities first to learn field names. data uses native fields: title, category for products, parent_id/type for categories, area/plugin/params for characteristics. Always forces state=0, even if input says otherwise.'; }
    public function getRequiredScope(): string { return 'radicalmart:write'; }
    public function getAccess(): Access { return Access::Draft; }
    public function getInputSchema(): array { return self::schema(['entity' => self::entityProperty(), 'data' => ['type' => 'object', 'description' => 'Native form data, fields discovered with radicalmart.entities. No id or author fields.']], ['entity','data']); }
    public function execute(array $arguments, ToolContext $context): array
    {
        $def = $this->definition($arguments);
        $this->model($context, $def);
        $this->authorize($context, $def, 'core.create');
        $data = self::dataArgument($arguments);
        $this->validatePatch($def, $data);
        $defaults = $def['key'] === 'category' ? ['parent_id' => 1, 'type' => 'category'] : [];
        $data = self::merge(['language' => '*'] + $defaults, $data);
        $data['id'] = 0; $data['state'] = 0;
        if (trim((string) ($data['title'] ?? '')) === '') { throw ToolException::validation('title is required.'); }
        if ($def['key'] === 'field' && ($data['area'] ?? 'products') !== 'products') { throw ToolException::forbidden('This pack manages product characteristics only.'); }
        if ($def['key'] === 'field' && !empty($data['categories'])) { $context->requireCan('core.edit', 'com_radicalmart.categories'); }
        [, $form] = $this->form($context, $def, $data);
        $this->assertFormPatch($form, array_intersect_key($data, array_flip($def['fields'])));
        $id = $this->save($context, $def, $data);
        return ['id' => $id, 'entity' => $def['key'], 'state' => 0];
    }
}
