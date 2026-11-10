<?php

/**
 * Dharma MCP — набор «Клиника».
 *
 * @package    plg_dharmamcp_clinic
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

declare(strict_types=1);

namespace Dharma\Plugin\DharmaMcp\Clinic\Tool;

\defined('_JEXEC') or die;

use Dharma\Component\DharmaClinic\Administrator\Service\LeadQuestionService;
use Dharma\Component\DharmaMcp\Site\Tool\Access;
use Dharma\Component\DharmaMcp\Site\Tool\ToolContext;
use Dharma\Component\DharmaMcp\Site\Tool\ToolException;

/** Черновик вопроса для страницы «Вопросы и ответы» из заявки-вопроса (как кнопка в админке). */
final class LeadsCreateQuestionTool extends AbstractClinicTool
{
    public function masksPersonalData(array $arguments): bool
    {
        return true;
    }

    public function getName(): string
    {
        return 'leads.create_question';
    }

    public function getTitle(): string
    {
        return 'Create a draft question from a lead';
    }

    public function getDescription(): string
    {
        return 'Creates an unpublished draft on the "Questions and answers" page from a lead of kind "question": the text and the asker name come from the lead. One lead gives one question (a repeat call returns the existing one). '
            . 'The draft has no answer: a clinic doctor writes it, and only the owner publishes it. The patient text is copied as is: review it for personal data before publishing.';
    }

    public function getRequiredScope(): string
    {
        return 'clinic:write';
    }

    public function getAccess(): Access
    {
        return Access::Draft;
    }

    public function getInputSchema(): array
    {
        return self::schema(['lead_id' => self::int('Lead id.')], ['lead_id']);
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        $this->boot($context);
        $context->requireCan('lead.view', self::COMPONENT, 'view leads');
        $context->requireCan('core.create', self::COMPONENT, 'create questions');
        $id = self::requireInt($arguments, 'lead_id');

        $service = LeadQuestionService::fromContainer();

        try {
            [$questionId, $created] = $service->create($id, $context->model(self::COMPONENT, 'Question'));
        } catch (\RuntimeException $e) {
            throw ToolException::validation('The clinic refused to create the question: ' . $e->getMessage());
        }

        if (!$created) {
            return ['created' => false, 'question_id' => $questionId, 'note' => 'This lead already has a question.'];
        }

        return ['created' => true, 'question_id' => $questionId, 'published' => false, 'next' => 'Ask a clinic doctor to write the answer; publish only with the owner\'s consent.'];
    }
}
