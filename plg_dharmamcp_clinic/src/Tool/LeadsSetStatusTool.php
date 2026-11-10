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

use Dharma\Component\DharmaClinic\Administrator\Service\LeadStatusService;
use Dharma\Component\DharmaMcp\Site\Tool\Access;
use Dharma\Component\DharmaMcp\Site\Tool\ToolContext;
use Dharma\Component\DharmaMcp\Site\Tool\ToolException;

/** Смена статуса заявок: теми же службой и журналом, что в админке. */
final class LeadsSetStatusTool extends AbstractClinicTool
{
    public function getName(): string
    {
        return 'leads.set_status';
    }

    public function getTitle(): string
    {
        return 'Change the status of leads';
    }

    public function getDescription(): string
    {
        return 'Sets a status (for example "In progress", "Booked", "Declined", "Spam") on up to 50 leads and writes the change to the lead history, exactly like the admin list. '
            . 'Get status ids from clinic.entity_list with entity lead_status. Marking real patients\' requests as spam hides them from the work queue: do it only on the user\'s clear instruction.';
    }

    public function getRequiredScope(): string
    {
        return 'clinic:write';
    }

    public function getAccess(): Access
    {
        return Access::Write;
    }

    public function isIdempotent(): bool
    {
        return true;
    }

    public function getInputSchema(): array
    {
        return self::schema([
            'lead_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'maxItems' => 50, 'description' => 'Lead ids (up to 50).'],
            'status_id' => self::int('New status id.'),
            'comment' => self::str('Optional note for the history (what was agreed with the patient). Do not put personal data here.'),
        ], ['lead_ids', 'status_id']);
    }

    /** @return array{0: int[], 1: int} */
    private function parse(array $arguments, ToolContext $context): array
    {
        $this->boot($context);
        $context->requireCan('lead.edit', self::COMPONENT, 'change lead statuses');
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($arguments['lead_ids'] ?? [])))));
        $statusId = self::requireInt($arguments, 'status_id');

        if ($ids === [] || \count($ids) > 50) {
            throw ToolException::validation("'lead_ids' must contain from 1 to 50 lead ids.");
        }

        $known = array_map(static fn ($s): int => (int) $s->id, LeadStatusService::fromContainer()->statuses());

        if (!\in_array($statusId, $known, true)) {
            throw ToolException::validation('Unknown status_id. Known: ' . implode(', ', $known) . '.');
        }

        return [$ids, $statusId];
    }

    public function preview(array $arguments, ToolContext $context): ?array
    {
        [$ids, $statusId] = $this->parse($arguments, $context);

        return ['action' => 'Change the status of leads', 'lead_ids' => $ids, 'count' => \count($ids), 'new_status_id' => $statusId];
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        [$ids, $statusId] = $this->parse($arguments, $context);
        $changed = LeadStatusService::fromContainer()->change($ids, $statusId, (int) $context->user()->id, trim((string) ($arguments['comment'] ?? '')));

        return ['changed' => $changed, 'requested' => \count($ids), 'status_id' => $statusId];
    }
}
