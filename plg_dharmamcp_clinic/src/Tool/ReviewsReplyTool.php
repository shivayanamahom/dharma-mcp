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
use Dharma\Plugin\DharmaMcp\Clinic\Entity\EntityCatalog;

/** Ответ клиники на отзыв (через форму и модель отзыва: автор, время и запись в журнале ставятся сами). */
final class ReviewsReplyTool extends AbstractEntityTool
{
    public function getName(): string
    {
        return 'reviews.reply';
    }

    public function getTitle(): string
    {
        return 'Write or change the clinic reply to a review';
    }

    public function getDescription(): string
    {
        return 'Sets the public reply of the clinic under a review (an empty reply removes it). Replies are visible to everyone on the site: keep them short and polite, thank the patient, '
            . 'do not disclose any treatment or diagnosis, do not argue, offer to contact the administrator by phone, never promise compensation, do not name doctors. Show the text to the user before sending.';
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
            'review_id' => self::int('Review id.'),
            'reply' => self::str('Reply text, plain text up to 2000 characters. Empty string removes the reply.'),
        ], ['review_id', 'reply']);
    }

    private function check(array $arguments, ToolContext $context): array
    {
        $def = EntityCatalog::get('review');
        $this->prepare($context, $def);
        $context->requireCan('review.reply', self::COMPONENT, 'reply to reviews');
        $reply = trim(strip_tags((string) ($arguments['reply'] ?? '')));

        if (mb_strlen($reply) > 2000) {
            throw ToolException::validation('The reply is longer than 2000 characters.');
        }

        return [$def, $reply];
    }

    public function preview(array $arguments, ToolContext $context): ?array
    {
        [$def, $reply] = $this->check($arguments, $context);
        $id = self::requireInt($arguments, 'review_id');
        $current = $this->load($context, $def, $id);

        return ['action' => $reply === '' ? 'Remove the clinic reply' : 'Set the clinic reply', 'review_id' => $id, 'review_status' => $current['status'] ?? null, 'old_reply' => $current['reply'] ?? null, 'new_reply' => $reply];
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        [$def, $reply] = $this->check($arguments, $context);
        $id = self::requireInt($arguments, 'review_id');
        $full = $this->model($context, $def)->getItem($id);

        if (!$full || empty($full->id)) {
            throw ToolException::notFound("Review $id not found.");
        }

        $data = json_decode((string) json_encode($full), true) ?: [];
        // Даты записи форма принимает в своём формате, а читать их не нужно: модель оставляет прежние значения.
        unset($data['created'], $data['moderated_at'], $data['reply_at']);
        $data['reply'] = $reply;
        $data['id'] = $id;
        $this->store($context, $def, $data);

        return ['updated' => true, 'review_id' => $id, 'has_reply' => $reply !== ''];
    }
}
