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
use Joomla\CMS\Cache\CacheControllerFactoryInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Table\Category as CategoryTable;

/**
 * Мета-данные рубрики материалов: описание и ключевые слова. Название, алиас, родитель и описание рубрики не меняются,
 * поэтому правится запись таблицы Joomla напрямую, без полной формы.
 */
final class CategoriesUpdateTool extends AbstractContentTool
{
    private const FIELDS = ['meta_description' => 'metadesc', 'meta_keywords' => 'metakey'];

    private const MAX = 500;

    public function getName(): string
    {
        return 'categories.update';
    }

    public function getTitle(): string
    {
        return 'Set article category meta data';
    }

    public function getDescription(): string
    {
        return 'Sets the SEO meta description (about 150 characters) and meta keywords of one article category. '
            . 'An empty string clears a value, an omitted field stays unchanged. Title, alias, parent and description of the category are not touched. '
            . 'Blog and list pages of the category change for visitors at once, so the user must confirm. Find ids with categories.list.';
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
            'id' => self::int('Article category id (see categories.list).'),
            'meta_description' => self::str('Meta description (up to ' . self::MAX . ' characters; about 150 reads best).'),
            'meta_keywords' => self::str('Meta keywords, comma separated.'),
        ], ['id']);
    }

    /** @return array<string, string>  Поле таблицы => новое значение. */
    private function changes(array $arguments): array
    {
        $changes = [];
        foreach (self::FIELDS as $field => $column) {
            if (!\array_key_exists($field, $arguments)) {
                continue;
            }
            $value = $arguments[$field];
            if (!\is_string($value)) {
                throw ToolException::validation("Parameter '$field' must be a string.");
            }
            $value = trim(preg_replace('/\s+/u', ' ', strip_tags($value)) ?? '');
            if (mb_strlen($value) > self::MAX) {
                throw ToolException::validation("Parameter '$field' must be up to " . self::MAX . ' characters.');
            }
            $changes[$column] = $value;
        }

        if ($changes === []) {
            throw ToolException::validation('Pass at least one field to change.', ['fields' => array_keys(self::FIELDS)]);
        }

        return $changes;
    }

    private function load(ToolContext $context, int $id): CategoryTable
    {
        $this->lang($context, 'com_categories');
        $table = new CategoryTable($context->db);
        if ($id < 2 || !$table->load($id) || (string) $table->extension !== 'com_content') {
            throw ToolException::notFound("Article category $id not found (see categories.list).");
        }
        if ((int) $table->published === -2) {
            throw ToolException::validation('The category is in the trash.');
        }
        $context->requireCan('core.edit', 'com_content.category.' . $id, 'edit this category');

        return $table;
    }

    public function preview(array $arguments, ToolContext $context): ?array
    {
        $table = $this->load($context, self::requireInt($arguments, 'id'));
        $changes = $this->changes($arguments);

        $diff = [];
        foreach (self::FIELDS as $field => $column) {
            if (isset($changes[$column])) {
                $diff[$field] = ['from' => (string) $table->$column, 'to' => $changes[$column]];
            }
        }

        return [
            'action' => 'Set category meta data',
            'category' => ['id' => (int) $table->id, 'title' => (string) $table->title],
            'live_now' => (int) $table->published === 1,
            'changes' => $diff,
        ];
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        $table = $this->load($context, self::requireInt($arguments, 'id'));
        $changes = $this->changes($arguments);

        foreach ($changes as $column => $value) {
            $table->$column = $value;
        }

        if (!$table->check() || !$table->store()) {
            throw ToolException::validation('Joomla could not save the category: ' . strip_tags((string) $table->getError()));
        }

        $cache = Factory::getContainer()->get(CacheControllerFactoryInterface::class);
        foreach (['_system', 'com_content', 'com_categories'] as $group) {
            $cache->createCacheController('callback', ['defaultgroup' => $group])->clean();
        }

        $fields = [];
        foreach (self::FIELDS as $field => $column) {
            if (isset($changes[$column])) {
                $fields[] = $field;
            }
        }

        return ['updated' => true, 'id' => (int) $table->id, 'title' => (string) $table->title, 'changed_fields' => $fields];
    }
}
