<?php
declare(strict_types=1);

namespace Dharma\Plugin\DharmaMcp\Sanatorium\Tool;

use Dharma\Component\DharmaMcp\Site\Tool\Access;
use Dharma\Component\DharmaMcp\Site\Tool\ToolContext;

\defined('_JEXEC') or die;

final class HealthmapSetTool extends AbstractSettingsTool
{
    public function getName(): string
    {
        return 'sanatorium.healthmap_set';
    }

    public function getDescription(): string
    {
        return 'Patch the health map settings. Directions and points replace the supplied list. Keep the existing direction ids when editing rows. Always requires confirmation.';
    }

    public function getRequiredScope(): string
    {
        return 'sanatorium:write';
    }

    public function getAccess(): Access
    {
        return Access::Danger;
    }

    public function getInputSchema(): array
    {
        return self::schema(['data' => ['type' => 'object', 'description' => 'Only the settings to change.']], ['data']);
    }

    public function preview(array $arguments, ToolContext $context): ?array
    {
        return $this->settings($arguments, $context, true, true, true);
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        return $this->settings($arguments, $context, true, true);
    }
}
