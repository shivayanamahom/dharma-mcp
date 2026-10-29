<?php

/**
 * Dharma MCP.
 *
 * @package    com_dharma_mcp
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

declare(strict_types=1);

namespace Dharma\Component\DharmaMcp\Site\Support;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

/**
 * Двухэтапное подтверждение: первый вызов возвращает предпросмотр и одноразовый код,
 * второй (те же аргументы + confirm_code) выполняет. Код привязан к токену, инструменту и аргументам.
 */
final class Confirmations
{
    public const TTL_SECONDS = 600;

    public function __construct(private readonly DatabaseInterface $db) {}

    /**
     * @return array{code: string, expires_in: int}
     */
    public function issue(int $tokenId, string $tool, array $arguments, array $preview): array
    {
        $this->purge();

        $hash = self::hashArguments($arguments);
        $code = strtoupper(bin2hex(random_bytes(4)));
        $now = Factory::getDate();
        $created = $now->toSql();
        $expires = (clone $now)->modify('+' . self::TTL_SECONDS . ' seconds')->toSql();
        $payload = json_encode($preview, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '{}';

        $query = $this->db->createQuery()
            ->insert($this->db->quoteName('#__dharma_mcp_confirmations'))
            ->columns($this->db->quoteName(['token_id', 'tool_name', 'arguments_hash', 'payload_json', 'confirm_code', 'expires_at', 'created']))
            ->values(':token, :tool, :hash, :payload, :code, :expires, :created')
            ->bind(':token', $tokenId, ParameterType::INTEGER)
            ->bind(':tool', $tool)
            ->bind(':hash', $hash)
            ->bind(':payload', $payload)
            ->bind(':code', $code)
            ->bind(':expires', $expires)
            ->bind(':created', $created);
        $this->db->setQuery($query)->execute();

        return ['code' => $code, 'expires_in' => self::TTL_SECONDS];
    }

    /** Проверяет и погашает код (одноразовый). */
    public function consume(int $tokenId, string $tool, array $arguments, string $code): bool
    {
        $hash = self::hashArguments($arguments);
        $code = strtoupper(trim($code));
        $now = Factory::getDate()->toSql();

        $query = $this->db->createQuery()
            ->select($this->db->quoteName('id'))
            ->from($this->db->quoteName('#__dharma_mcp_confirmations'))
            ->where($this->db->quoteName('confirm_code') . ' = :code')
            ->where($this->db->quoteName('token_id') . ' = :token')
            ->where($this->db->quoteName('tool_name') . ' = :tool')
            ->where($this->db->quoteName('arguments_hash') . ' = :hash')
            ->where($this->db->quoteName('expires_at') . ' > :now')
            ->bind(':code', $code)
            ->bind(':token', $tokenId, ParameterType::INTEGER)
            ->bind(':tool', $tool)
            ->bind(':hash', $hash)
            ->bind(':now', $now);
        $id = (int) $this->db->setQuery($query)->loadResult();

        if ($id === 0) {
            return false;
        }

        $delete = $this->db->createQuery()
            ->delete($this->db->quoteName('#__dharma_mcp_confirmations'))
            ->where($this->db->quoteName('id') . ' = :id')
            ->bind(':id', $id, ParameterType::INTEGER);
        $this->db->setQuery($delete)->execute();

        // Код одноразовый: если параллельный запрос успел погасить его раньше, действие не выполняется второй раз.
        return $this->db->getAffectedRows() === 1;
    }

    /** Хэш аргументов без самого кода подтверждения; порядок ключей не важен. */
    public static function hashArguments(array $arguments): string
    {
        unset($arguments['confirm_code']);

        return hash('sha256', json_encode(self::sortRecursive($arguments), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '');
    }

    private static function sortRecursive(array $data): array
    {
        foreach ($data as $key => $value) {
            if (\is_array($value)) {
                $data[$key] = self::sortRecursive($value);
            }
        }
        if (!array_is_list($data)) {
            ksort($data);
        }

        return $data;
    }

    private function purge(): void
    {
        $now = Factory::getDate()->toSql();
        $query = $this->db->createQuery()
            ->delete($this->db->quoteName('#__dharma_mcp_confirmations'))
            ->where($this->db->quoteName('expires_at') . ' < :now')
            ->bind(':now', $now);
        $this->db->setQuery($query)->execute();
    }
}
