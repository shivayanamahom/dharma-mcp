<?php
/** Dharma MCP — инструменты RadicalMart. */
declare(strict_types=1);
namespace Dharma\Plugin\DharmaMcp\Radicalmart\Tool;
\defined('_JEXEC') or die;
use Dharma\Component\DharmaMcp\Site\Tool\Access;
use Dharma\Component\DharmaMcp\Site\Tool\ToolContext;
use Dharma\Component\DharmaMcp\Site\Tool\ToolException;
final class EntitiesTool extends AbstractRadicalmartTool
{
    public function getName(): string { return 'radicalmart.entities'; }
    public function getDescription(): string { return 'Lists RadicalMart entity kinds and read/write support. With entity, returns allowed native form fields; supply id for an existing record or data (category, plugin, area) to discover dynamic product characteristics and field-plugin settings. Category type can be category, manufacturer or badge. Prices use prices.<currency group>.base; stock uses stock.all.'; }
    public function getRequiredScope(): string { return 'radicalmart:read'; }
    public function getAccess(): Access { return Access::Read; }
    public function getInputSchema(): array { return self::schema(['entity' => self::entityProperty(), 'id' => self::int('Existing record id.'), 'data' => ['type' => 'object', 'description' => 'Form context, such as category for product or plugin and area for field.']]); }
    public function execute(array $arguments, ToolContext $context): array
    {
        $this->prepare($context);
        if (empty($arguments['entity'])) {
            return ['entities' => \Dharma\Plugin\DharmaMcp\Radicalmart\Entity\EntityCatalog::DEFINITIONS];
        }
        $def = $this->definition($arguments);
        if (!$def['write']) {
            $this->model($context, $def);
            return ['entity' => $def['key'], 'writable' => false, 'fields' => [], 'readable_fields' => $def['fields']];
        }
        $data = !empty($arguments['id']) ? $this->load($context, $def, self::requireInt($arguments, 'id')) : [];
        $data = self::merge($data, self::dataArgument($arguments, false));
        return ['entity' => $def['key'], 'writable' => $def['write'], 'fields' => $this->formSchema($context, $def, $data)];
    }
}
