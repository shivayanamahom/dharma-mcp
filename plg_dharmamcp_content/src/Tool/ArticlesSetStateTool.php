<?php

/**
 * Dharma MCP — набор «Материалы и меню».
 *
 * @package    plg_dharmamcp_content
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

declare(strict_types=1);

namespace Dharma\Plugin\DharmaMcp\Content\Tool;

\defined('_JEXEC') or die;

use Dharma\Component\DharmaMcp\Site\Tool\Access;
use Dharma\Component\DharmaMcp\Site\Tool\ToolContext;
use Dharma\Component\DharmaMcp\Site\Tool\ToolException;

final class ArticlesSetStateTool extends AbstractContentTool
{
    public function getName(): string
    {
        return 'articles.set_state';
    }

    public function getTitle(): string
    {
        return 'Publish or hide article';
    }

    public function getDescription(): string
    {
        return 'Publishes, unpublishes, archives or moves to trash one article. Publishing makes the page visible to all visitors, '
            . 'so the user must confirm. Trashed articles can be restored in the Joomla admin; nothing is deleted permanently.';
    }

    public function getRequiredScope(): string
    {
        return 'content:write';
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
            'id' => self::int('Article id.'),
            'state' => ['type' => 'string', 'enum' => array_keys(self::STATES), 'description' => 'New state.'],
        ], ['id', 'state']);
    }

    public function preview(array $arguments, ToolContext $context): ?array
    {
        $id = self::requireInt($arguments, 'id');
        $article = ArticlesGetTool::load($context, $id);
        $this->authorize($context, $id);

        return [
            'action' => 'Change publication state',
            'article' => ['id' => $id, 'title' => (string) $article->title],
            'from' => self::stateName((int) $article->state),
            'to' => self::oneOf($arguments, 'state', array_keys(self::STATES)),
        ];
    }

    private function authorize(ToolContext $context, int $id): void
    {
        $context->requireCan('core.edit.state', 'com_content.article.' . $id, 'change the state of this article');
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        $id = self::requireInt($arguments, 'id');
        $state = self::stateArgument($arguments);
        if ($state === null) {
            throw ToolException::validation("Parameter 'state' is required.");
        }
        ArticlesGetTool::load($context, $id);
        $this->authorize($context, $id);

        $model = $context->model('com_content', 'Article');
        $pks = [$id];
        if (!$model->publish($pks, $state)) {
            throw self::modelFailure($model, 'Joomla could not change the state');
        }

        return ['id' => $id, 'state' => self::stateName($state), 'changed' => true];
    }
}
