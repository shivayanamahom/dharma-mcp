<?php
declare(strict_types=1);

namespace Dharma\Component\DharmaShopMcp\Site\Protocol;

\defined('_JEXEC') or die;

final class JsonRpcRequest
{
    public function __construct(
        public readonly string|int|null $id,
        public readonly string $method,
        public readonly array $params = []
    ) {}

    public function isNotification(): bool
    {
        return $this->id === null;
    }

    public static function fromRaw(string $raw): self
    {
        $data = json_decode($raw, true);
        if (!is_array($data) || ($data['jsonrpc'] ?? '') !== '2.0' || empty($data['method'])) {
            throw new \InvalidArgumentException('Invalid JSON-RPC 2.0 payload', JsonRpcError::INVALID_REQUEST);
        }

        return new self(
            $data['id'] ?? null,
            (string) $data['method'],
            is_array($data['params'] ?? null) ? $data['params'] : []
        );
    }
}
