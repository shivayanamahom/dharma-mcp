<?php
/** Dharma MCP — инструменты RadicalMart. */
declare(strict_types=1);
namespace Dharma\Plugin\DharmaMcp\Radicalmart\Tool;
\defined('_JEXEC') or die;
use Dharma\Component\DharmaMcp\Site\Tool\Access;
use Dharma\Component\DharmaMcp\Site\Tool\ToolContext;
use Dharma\Component\DharmaMcp\Site\Tool\ToolException;
final class InfoTool extends AbstractRadicalmartTool
{
    public function getName(): string { return 'radicalmart.info'; }
    public function getDescription(): string { return 'Reports RadicalMart version, catalog/shop mode and available entity kinds. Start here, then call radicalmart.entities to discover native form fields.'; }
    public function getRequiredScope(): string { return 'radicalmart:read'; }
    public function getAccess(): Access { return Access::Read; }
    public function getInputSchema(): array { return self::schema(); }
    public function execute(array $arguments, ToolContext $context): array
    {
        $this->prepare($context);
        $params = \Joomla\CMS\Component\ComponentHelper::getParams('com_radicalmart');
        return ['component' => 'com_radicalmart', 'version' => (string) simplexml_load_file(JPATH_ADMINISTRATOR . '/components/com_radicalmart/radicalmart.xml')->version,
            'mode' => $params->get('mode', 'shop'), 'entities' => array_keys(\Dharma\Plugin\DharmaMcp\Radicalmart\Entity\EntityCatalog::DEFINITIONS),
            'currencies' => array_values(array_map(static fn ($c) => ['code' => $c['code'], 'group' => $c['group']], \Joomla\Component\RadicalMart\Administrator\Helper\PriceHelper::getCurrencies())),
            'write_scope' => 'radicalmart:write', 'shop_scopes_grant_access' => false];
    }
}
