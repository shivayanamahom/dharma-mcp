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
use Dharma\Component\DharmaMcp\Site\Tool\ToolContext;
use Dharma\Component\DharmaMcp\Site\Tool\ToolException;

/**
 * Общее для инструментов набора. Все изменения идут через штатные административные модели Joomla
 * (com_content, com_categories, com_menus, com_modules): те же проверки, события, вложенные наборы и корзина.
 */
abstract class AbstractContentTool extends AbstractTool
{
    /** Состояния публикации Joomla. */
    protected const STATES = ['published' => 1, 'unpublished' => 0, 'archived' => 2, 'trashed' => -2];

    public function getPack(): string
    {
        return 'content';
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
