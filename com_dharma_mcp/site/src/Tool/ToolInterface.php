<?php

/**
 * Dharma MCP.
 *
 * @package    com_dharma_mcp
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

declare(strict_types=1);

namespace Dharma\Component\DharmaMcp\Site\Tool;

\defined('_JEXEC') or die;

/**
 * Контракт инструмента MCP. Наборы инструментов (плагины группы dharmamcp) реализуют его
 * и регистрируют свои инструменты в событии onDharmaMcpRegisterTools.
 */
interface ToolInterface
{
    /** Имя вида «область.действие», например orders.list. */
    public function getName(): string;

    /** Набор, к которому относится инструмент: core, shop, content, template, system. */
    public function getPack(): string;

    /** Короткое название для человека (список инструментов в клиенте). */
    public function getTitle(): string;

    /** Описание для ассистента: что делает, что вернёт, когда не использовать. */
    public function getDescription(): string;

    /** JSON Schema входных параметров. */
    public function getInputSchema(): array;

    /** Область токена, нужная для вызова (пустая строка — любой действующий токен). */
    public function getRequiredScope(): string;

    public function getAccess(): Access;

    /** Идемпотентна ли операция (повтор не меняет результат). */
    public function isIdempotent(): bool;

    /**
     * Нужно ли подтверждение именно для этих аргументов (например, массовое изменение).
     * Подтверждение по уровню доступа и режиму сервера решает сервер, а не инструмент.
     */
    public function needsConfirmation(array $arguments, ToolContext $context): bool;

    /**
     * Описание того, что произойдёт, без выполнения. Для Write/Danger возвращается ассистенту
     * вместе с кодом подтверждения. Null — показать только аргументы.
     */
    public function preview(array $arguments, ToolContext $context): ?array;

    /**
     * Выполняет инструмент от имени пользователя токена.
     * Ожидаемые ошибки — через ToolException.
     */
    public function execute(array $arguments, ToolContext $context): array;
}
