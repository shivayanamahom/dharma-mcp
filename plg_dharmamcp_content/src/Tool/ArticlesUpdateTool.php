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

final class ArticlesUpdateTool extends AbstractContentTool
{
    /** Поле инструмента => поле Joomla. */
    private const FIELDS = [
        'title' => 'title',
        'alias' => 'alias',
        'text' => 'articletext',
        'meta_description' => 'metadesc',
        'meta_keywords' => 'metakey',
        'category_id' => 'catid',
    ];

    public function getName(): string
    {
        return 'articles.update';
    }

    public function getTitle(): string
    {
        return 'Update article';
    }

    public function getDescription(): string
    {
        return 'Changes selected fields of an existing article: title, alias, body HTML (replaces the whole body), meta data, category, intro and full-article images (image_intro, image_fulltext with alt text; upload files with media.image_upload first). '
            . 'If the article is published the change is live at once, so the user must confirm. '
            . 'To keep the live page untouched, create a new draft with articles.create instead. Read the current text with articles.get first.';
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
            'title' => self::str('New title.'),
            'alias' => self::str('New URL alias.'),
            'text' => self::str('New body as HTML; replaces the entire current body.'),
            'meta_description' => self::str('New SEO description.'),
            'meta_keywords' => self::str('New SEO keywords.'),
            'category_id' => self::int('Move to this category.'),
        ] + self::imageProperties(), ['id']);
    }

    /** @return array<string, mixed>  Изменения в терминах Joomla. */
    private function changes(array $arguments): array
    {
        $changes = [];
        foreach (self::FIELDS as $field => $joomla) {
            if (!\array_key_exists($field, $arguments)) {
                continue;
            }
            $value = $arguments[$field];
            if ($field === 'category_id') {
                $changes[$joomla] = self::requireInt($arguments, $field);
            } elseif (!\is_string($value)) {
                throw ToolException::validation("Parameter '$field' must be a string.");
            } elseif (\in_array($field, ['title'], true) && trim($value) === '') {
                throw ToolException::validation('Title must not be empty.');
            } else {
                $changes[$joomla] = $joomla === 'articletext' ? self::filterHtml($value) : $value;
            }
        }

        $images = self::imageArguments($arguments);
        if ($images !== []) {
            $changes['images'] = $images;
        }

        if ($changes === []) {
            throw ToolException::validation('Pass at least one field to change.', ['fields' => array_merge(array_keys(self::FIELDS), array_keys(self::IMAGE_FIELDS))]);
        }

        return $changes;
    }

    private function authorize(ToolContext $context, object $article, array $changes): void
    {
        $id = (int) $article->id;
        $isOwn = (int) $article->created_by === (int) $context->user()->id;
        if (!$context->can('core.edit', 'com_content.article.' . $id) && !($isOwn && $context->can('core.edit.own', 'com_content.article.' . $id))) {
            throw ToolException::forbidden('The Joomla user of this token may not edit this article.');
        }
        if (isset($changes['catid']) && (int) $changes['catid'] !== (int) $article->catid) {
            $context->requireCan('core.create', 'com_content.category.' . (int) $changes['catid'], 'move articles into that category');
        }
    }

    public function preview(array $arguments, ToolContext $context): ?array
    {
        $id = self::requireInt($arguments, 'id');
        $article = ArticlesGetTool::load($context, $id);
        $changes = $this->changes($arguments);
        $this->authorize($context, $article, $changes);

        $current = ['articletext' => trim((string) $article->introtext . ((string) $article->fulltext !== '' ? '<hr id="system-readmore">' . $article->fulltext : ''))];
        $diff = [];
        foreach ($changes as $key => $new) {
            $old = $key === 'images' ? array_intersect_key(self::currentImages($article), $new) : ($current[$key] ?? $article->$key ?? null);
            $diff[$key] = ['from' => self::shorten($old), 'to' => self::shorten($new)];
        }

        return [
            'action' => 'Update article',
            'article' => ['id' => $id, 'title' => (string) $article->title, 'state' => self::stateName((int) $article->state)],
            'live_now' => (int) $article->state === 1,
            'changes' => $diff,
        ];
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        $id = self::requireInt($arguments, 'id');
        $article = ArticlesGetTool::load($context, $id);
        $changes = $this->changes($arguments);
        $this->authorize($context, $article, $changes);

        if (isset($changes['images'])) {
            // Параметр images сохраняется целиком: подмешиваем к текущим, чтобы не потерять остальные (выравнивание, подписи).
            $changes['images'] = $changes['images'] + self::currentImages($article);
        }

        $model = $context->model('com_content', 'Article');
        // ArticleModel::save всегда читает catid; без него Joomla печатает предупреждения PHP.
        if (!$model->save(['id' => $id, 'catid' => (int) $article->catid] + $changes)) {
            throw self::modelFailure($model, 'Joomla could not save the article');
        }

        $after = ArticlesGetTool::load($context, $id);

        return [
            'updated' => true,
            'id' => $id,
            'title' => (string) $after->title,
            'state' => self::stateName((int) $after->state),
            'modified' => $after->modified ?: null,
            'changed_fields' => array_keys(array_intersect_key($arguments, self::FIELDS + self::IMAGE_FIELDS)),
        ];
    }

    private static function shorten(mixed $value): mixed
    {
        return \is_string($value) && mb_strlen($value) > 300 ? mb_substr($value, 0, 300) . '…' : $value;
    }
}
