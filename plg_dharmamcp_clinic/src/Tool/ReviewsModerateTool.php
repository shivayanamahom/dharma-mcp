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

use Dharma\Component\DharmaMcp\Site\Tool\Access;
use Dharma\Component\DharmaMcp\Site\Tool\ToolContext;
use Dharma\Component\DharmaMcp\Site\Tool\ToolException;
use Joomla\CMS\Language\Text;

/** Модерация отзывов: одобрить, отклонить, спам, в корзину. Рейтинги пересчитывает модель. */
final class ReviewsModerateTool extends AbstractClinicTool
{
    private const ACTIONS = ['approve' => 1, 'reject' => 2, 'spam' => 3, 'trash' => -2, 'to_pending' => 0];

    public function getName(): string
    {
        return 'reviews.moderate';
    }

    public function getTitle(): string
    {
        return 'Moderate reviews: approve, reject, mark as spam';
    }

    public function getDescription(): string
    {
        return 'Changes the status of up to 30 reviews: approve (publishes the review and counts it in ratings), reject, spam, trash or to_pending (back to the moderation queue). '
            . 'Publishing is a decision of the clinic: approve only on the user\'s explicit instruction, after showing the review text. Never approve reviews that contain advice, advertising or personal data.';
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
            'review_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'maxItems' => 30, 'description' => 'Review ids (up to 30).'],
            'action' => self::str('What to do.', ['enum' => array_keys(self::ACTIONS)]),
        ], ['review_ids', 'action']);
    }

    /** @return array{0: int[], 1: string} */
    private function parse(array $arguments, ToolContext $context): array
    {
        $this->boot($context);
        $context->requireCan('review.moderate', self::COMPONENT, 'moderate reviews');
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($arguments['review_ids'] ?? [])))));

        if ($ids === [] || \count($ids) > 30) {
            throw ToolException::validation("'review_ids' must contain from 1 to 30 review ids.");
        }

        return [$ids, (string) (self::oneOf($arguments, 'action', array_keys(self::ACTIONS)) ?? '')];
    }

    public function preview(array $arguments, ToolContext $context): ?array
    {
        [$ids, $action] = $this->parse($arguments, $context);

        return ['action' => 'Moderate reviews: ' . $action, 'review_ids' => $ids, 'count' => \count($ids)];
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        [$ids, $action] = $this->parse($arguments, $context);
        $model = $context->model(self::COMPONENT, 'Review');
        $pks = $ids;

        if (!$model->publish($pks, self::ACTIONS[$action])) {
            throw ToolException::validation('The clinic refused: ' . trim(strip_tags(Text::_((string) $model->getError()))) . '.');
        }

        return ['done' => true, 'action' => $action, 'review_ids' => $ids];
    }
}
