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

final class ArticlesCreateTool extends AbstractContentTool
{
    public function getName(): string
    {
        return 'articles.create';
    }

    public function getTitle(): string
    {
        return 'Create article draft';
    }

    public function getDescription(): string
    {
        return 'Creates a new article as an UNPUBLISHED draft (visitors cannot see it) in the given category. '
            . 'The text is HTML. Tell the owner the article id so they can review it; publish it only after they agree, with articles.set_state. '
            . 'Find category ids with categories.list. '
            . 'Images: upload a file with media.image_upload, then pass its path as image_intro (blog/list views) and/or image_fulltext (article page), with alt text in image_intro_alt / image_fulltext_alt.';
    }

    public function getRequiredScope(): string
    {
        return 'content:write';
    }

    public function getAccess(): Access
    {
        return Access::Draft;
    }

    public function getInputSchema(): array
    {
        return self::schema([
            'title' => self::str('Article title.'),
            'category_id' => self::int('Category id (see categories.list).'),
            'text' => self::str('Article body as HTML. To split intro and the rest use <hr id="system-readmore">.'),
            'alias' => self::str('URL alias (optional; made from the title when empty).'),
            'meta_description' => self::str('SEO description, up to about 160 characters.'),
            'meta_keywords' => self::str('SEO keywords, comma separated.'),
        ] + self::imageProperties(), ['title', 'category_id']);
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        $title = self::requireString($arguments, 'title');
        $categoryId = self::requireInt($arguments, 'category_id');
        $context->requireCan('core.create', 'com_content.category.' . $categoryId, 'create articles in this category');
        $images = self::imageArguments($arguments);
        $this->lang($context, 'com_content');

        $model = $context->model('com_content', 'Article');
        $data = [
            'id' => 0,
            'title' => $title,
            'alias' => self::optionalString($arguments, 'alias') ?? '',
            'catid' => $categoryId,
            'state' => 0,
            'featured' => 0,
            'access' => 1,
            'language' => '*',
            'articletext' => self::optionalString($arguments, 'text') ?? '',
            'metadesc' => self::optionalString($arguments, 'meta_description') ?? '',
            'metakey' => self::optionalString($arguments, 'meta_keywords') ?? '',
            'created_by' => (int) $context->user()->id,
            'images' => $images,
            'urls' => [],
            'attribs' => [],
            'metadata' => [],
            'version_note' => 'Created through Dharma MCP',
        ];

        if (!$model->save($data)) {
            throw self::modelFailure($model, 'Joomla could not save the article');
        }

        $id = (int) $model->getState('article.id');

        return [
            'created' => true,
            'id' => $id,
            'state' => 'unpublished',
            'edit_in_admin' => 'administrator/index.php?option=com_content&task=article.edit&id=' . $id,
            'note' => 'Saved as an unpublished draft. Publish it with articles.set_state after the owner has reviewed it.',
        ];
    }
}
