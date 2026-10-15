<?php
declare(strict_types=1);

namespace Dharma\Component\DharmaMcp\Site\Protocol;

\defined('_JEXEC') or die;

final class JsonRpcError
{
    public const PARSE_ERROR = -32700;
    public const INVALID_REQUEST = -32600;
    public const METHOD_NOT_FOUND = -32601;
    public const INVALID_PARAMS = -32602;
    public const INTERNAL_ERROR = -32603;

    public const UNAUTHORIZED = -32001;
    public const FORBIDDEN = -32003;
    public const CONFIRMATION_REQUIRED = -32004;
    public const RATE_LIMITED = -32029;

    public function __construct(
        public readonly int $code,
        public readonly string $message,
        public readonly mixed $data = null
    ) {}

    public function toArray(): array
    {
        $err = [
            'code' => $this->code,
            'message' => $this->message,
        ];
        if ($this->data !== null) {
            $err['data'] = $this->data;
        }
        return $err;
    }
}
