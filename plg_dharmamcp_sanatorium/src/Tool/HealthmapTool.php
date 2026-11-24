<?php
declare(strict_types=1);

namespace Dharma\Plugin\DharmaMcp\Sanatorium\Tool;

use Dharma\Component\DharmaMcp\Site\Tool\Access;
use Dharma\Component\DharmaMcp\Site\Tool\ToolContext;

\defined('_JEXEC') or die;

final class HealthmapTool extends AbstractSettingsTool
{
    public function getName(): string
    {
        return 'sanatorium.healthmap';
    }

    public function getDescription(): string
    {
        return 'Read the health map settings, directions, points, service references and presentation options.';
    }

    public function getRequiredScope(): string
    {
        return 'sanatorium:read';
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
        return $this->settings($arguments, $context, true, false);
    }
}
