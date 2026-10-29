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

use Dharma\Component\DharmaMcp\Site\Tool\AbstractTool;
use Dharma\Component\DharmaMcp\Site\Tool\ImageStore;
use Dharma\Component\DharmaMcp\Site\Tool\ToolContext;
use Dharma\Component\DharmaMcp\Site\Tool\ToolException;
use Joomla\CMS\Component\ComponentHelper;

/**
 * Общее для инструментов набора. Все изменения идут через штатные административные модели Joomla
 * (com_content, com_categories, com_menus, com_modules): те же проверки, события, вложенные наборы и корзина.
 */
abstract class AbstractContentTool extends AbstractTool
{
    /** Состояния публикации Joomla. */
    protected const STATES = ['published' => 1, 'unpublished' => 0, 'archived' => 2, 'trashed' => -2];

    /** Картинки статьи: поле инструмента => ключ в параметре images статьи Joomla. */
    protected const IMAGE_FIELDS = [
        'image_intro' => 'image_intro',
        'image_intro_alt' => 'image_intro_alt',
        'image_fulltext' => 'image_fulltext',
        'image_fulltext_alt' => 'image_fulltext_alt',
    ];

    /** Свойства входной схемы для картинок статьи. */
    protected static function imageProperties(): array
    {
        return [
            'image_intro' => self::str('Intro image (shown in blog and list views): path under images/, e.g. from media.image_upload. Empty string removes it.'),
            'image_intro_alt' => self::str('Alt text of the intro image.'),
            'image_fulltext' => self::str('Full-article image (shown on the article page): path under images/, e.g. from media.image_upload. Empty string removes it.'),
            'image_fulltext_alt' => self::str('Alt text of the full-article image.'),
        ];
    }

    /**
     * Переданные картинки статьи в виде ключей параметра images; пути проверяются (файл должен лежать на сайте).
     *
     * @return array<string, string>
     */
    protected static function imageArguments(array $arguments): array
    {
        $images = [];
        foreach (self::IMAGE_FIELDS as $field => $key) {
            if (!\array_key_exists($field, $arguments)) {
                continue;
            }
            $value = $arguments[$field];
            if (!\is_string($value)) {
                throw ToolException::validation("Parameter '$field' must be a string.");
            }
            if (str_ends_with($field, '_alt')) {
                if (mb_strlen($value) > 255) {
                    throw ToolException::validation("Parameter '$field' must be up to 255 characters.");
                }
                $images[$key] = trim($value);
            } else {
                $images[$key] = ImageStore::existingPath($value);
            }
        }

        return $images;
    }

    /** Текущие картинки статьи (параметр images приходит из модели строкой JSON или массивом). */
    protected static function currentImages(object $article): array
    {
        $images = $article->images ?? [];
        if (\is_string($images)) {
            $images = json_decode($images, true);
        }

        return \is_array($images) ? $images : (\is_object($images) ? get_object_vars($images) : []);
    }

    public function getPack(): string
    {
        return 'content';
    }

    /**
     * HTML-текст так же, как при сохранении в админке: фильтр «Параметры → Текстовые фильтры» для групп пользователя токена.
     * Инструменты сохраняют через model->save() без проверки формы, где этот фильтр обычно применяется, поэтому без него
     * пользователь с ограниченным фильтром (например, Менеджер) записал бы в материал или модуль скрипты.
     */
    protected static function filterHtml(string $text): string
    {
        return \is_callable([ComponentHelper::class, 'filterText']) ? (string) ComponentHelper::filterText($text) : $text;
    }

    /** Подгружает административные языковые файлы, чтобы тексты ошибок моделей были читаемыми. */
    protected function lang(ToolContext $context, string ...$extensions): void
    {
        foreach ($extensions as $extension) {
            $context->app->getLanguage()->load($extension, JPATH_ADMINISTRATOR);
        }
    }

    /**
     * Первая ошибка модели в виде исключения. Модели Joomla сообщают об ошибках через getError().
     */
    protected static function modelFailure(object $model, string $fallback): ToolException
    {
        $error = method_exists($model, 'getError') ? $model->getError() : null;
        if ($error instanceof \Throwable) {
            $error = $error->getMessage();
        }

        return ToolException::validation($fallback . ($error ? ': ' . strip_tags((string) $error) : '.'));
    }

    /** Состояние публикации из аргумента (published/unpublished/archived/trashed). */
    protected static function stateArgument(array $arguments, string $key = 'state', ?string $default = null): ?int
    {
        $name = self::oneOf($arguments, $key, array_keys(self::STATES), $default);

        return $name === null ? null : self::STATES[$name];
    }

    protected static function stateName(int $state): string
    {
        return array_search($state, self::STATES, true) ?: (string) $state;
    }
}
