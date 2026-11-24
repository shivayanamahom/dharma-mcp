<?php
declare(strict_types=1);

namespace Dharma\Plugin\DharmaMcp\Sanatorium\Tool;

use Dharma\Component\DharmaMcp\Site\Tool\Access;
use Dharma\Component\DharmaMcp\Site\Tool\ToolContext;
use Dharma\Component\DharmaMcp\Site\Tool\ToolException;
use Dharma\Component\DharmaSanatorium\Administrator\Service\LeadQuestionService;

\defined('_JEXEC') or die;

final class LeadQuestionTool extends AbstractSanatoriumTool
{
    public function getName(): string
    {
        return 'sanatorium.lead_question';
    }

    public function getDescription(): string
    {
        return 'Create an unpublished FAQ entry from a lead. Reuses the existing draft when available. Review and remove guest information before publishing.';
    }

    public function getRequiredScope(): string
    {
        return 'sanatorium:write';
    }

    public function getAccess(): Access
    {
        return Access::Draft;
    }

    public function needsConfirmation(array $arguments, ToolContext $context): bool
    {
        return true;
    }

    public function getInputSchema(): array
    {
        return self::schema(['id' => self::int('Lead id.')], ['id']);
    }

    private function check(array $arguments, ToolContext $context): int
    {
        $this->boot($context);
        $context->requireCan('lead.view', self::COMPONENT);
        $context->requireCan('lead.contacts', self::COMPONENT);
        $context->requireCan('core.create', self::COMPONENT);
        if (!$context->piiAllowed()) {
            throw ToolException::forbidden('Creating a draft from a guest message requires the personal data scope.');
        }

        return self::requireInt($arguments, 'id');
    }

    public function preview(array $arguments, ToolContext $context): ?array
    {
        return ['action' => 'Copy the guest message into a private FAQ draft', 'lead_id' => $this->check($arguments, $context)];
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        $lead = $this->check($arguments, $context);
        [$id, $created] = (new LeadQuestionService($context->db))->create($lead, $context->model(self::COMPONENT, 'Question'));

        return ['id' => $id, 'created' => $created];
    }
}
