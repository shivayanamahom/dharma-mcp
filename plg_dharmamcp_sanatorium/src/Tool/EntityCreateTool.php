<?php
declare(strict_types=1);

namespace Dharma\Plugin\DharmaMcp\Sanatorium\Tool;

use Dharma\Component\DharmaMcp\Site\Tool\Access;
use Dharma\Component\DharmaMcp\Site\Tool\ToolContext;

\defined('_JEXEC') or die;

final class EntityCreateTool extends AbstractRecordTool
{
    public function getName(): string
    {
        return 'sanatorium.entity_create';
    }

    public function getDescription(): string
    {
        return 'Create an unpublished sanatorium record or an internal lead through the Joomla form. Discover fields first and assign publication state separately.';
    }

    public function getRequiredScope(): string
    {
        return 'sanatorium:write';
    }

    public function getAccess(): Access
    {
        return Access::Draft;
    }

    public function getInputSchema(): array
    {
        return self::schema(['entity' => self::entity(), 'data' => ['type' => 'object', 'description' => 'Editable fields for the new record.']], ['entity', 'data']);
    }

    public function preview(array $arguments, ToolContext $context): ?array
    {
        return $this->change($arguments, $context, true, true);
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        return $this->change($arguments, $context, true, false);
    }
}
