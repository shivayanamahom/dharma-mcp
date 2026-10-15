<?php
declare(strict_types=1);

namespace Dharma\Component\DharmaMcp\Site\Protocol;

\defined('_JEXEC') or die;

final class JsonRpcResponse
{
    public function __construct(
        public readonly string|int|null $id,
        public readonly mixed $result = null,
        public readonly ?JsonRpcError $error = null
    ) {}

    public static function success(string|int|null $id, mixed $result): self
    {
        return new self($id, $result, null);
    }

    public static function error(string|int|null $id, JsonRpcError $error): self
    {
        return new self($id, null, $error);
    }

    public function toJson(): string
    {
        $payload = [
            'jsonrpc' => '2.0',
            'id' => $this->id,
        ];
        if ($this->error !== null) {
            $payload['error'] = $this->error->toArray();
        } else {
            $payload['result'] = $this->result;
        }
        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
