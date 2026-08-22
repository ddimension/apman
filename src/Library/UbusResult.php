<?php

namespace ApManBundle\Library;

/**
 * The answer to one ubus call over HTTP, with the reason it failed.
 *
 * wrtJsonRpc::call() flattened everything to `false`: a missing object, a
 * denied permission, a timeout and a broken connection were the same value.
 * That is workable for "fetch this and carry on if it is not there", which is
 * what the thirty existing callers do, and useless for anything that has to
 * react — the provisioning transaction has to tolerate "not found" on a delete
 * and abort on anything else, and it cannot tell them apart.
 *
 * So the status code travels. Transport failures, which have no ubus code, get
 * one of their own above the ubus range so a caller can switch on a single
 * value.
 */
final class UbusResult
{
    /** ubus status codes, as rpcd returns them in result[0] */
    public const OK = 0;
    public const INVALID_COMMAND = 1;
    public const INVALID_ARGUMENT = 2;
    public const METHOD_NOT_FOUND = 3;
    public const NOT_FOUND = 4;
    public const NO_DATA = 5;
    public const PERMISSION_DENIED = 6;
    public const TIMEOUT = 7;
    public const NOT_SUPPORTED = 8;
    public const UNKNOWN_ERROR = 9;
    public const CONNECTION_FAILED = 10;

    /** ours: the call never reached a ubus object */
    public const TRANSPORT_FAILED = 100;
    public const MALFORMED_ANSWER = 101;

    private const NAMES = [
        self::OK => 'ok',
        self::INVALID_COMMAND => 'invalid command',
        self::INVALID_ARGUMENT => 'invalid argument',
        self::METHOD_NOT_FOUND => 'method not found',
        self::NOT_FOUND => 'not found',
        self::NO_DATA => 'no data',
        self::PERMISSION_DENIED => 'permission denied',
        self::TIMEOUT => 'timeout',
        self::NOT_SUPPORTED => 'not supported',
        self::UNKNOWN_ERROR => 'unknown error',
        self::CONNECTION_FAILED => 'connection failed',
        self::TRANSPORT_FAILED => 'the call did not reach the access point',
        self::MALFORMED_ANSWER => 'the answer was not a ubus answer',
    ];

    private function __construct(
        public readonly int $status,
        public readonly mixed $data = null,
        public readonly ?string $detail = null,
    ) {
    }

    public static function ok(mixed $data): self
    {
        return new self(self::OK, $data);
    }

    public static function failed(int $status, ?string $detail = null): self
    {
        return new self($status, null, $detail);
    }

    public function isOk(): bool
    {
        return self::OK === $this->status;
    }

    /** The call reached ubus and ubus answered — even if the answer was "no". */
    public function reachedUbus(): bool
    {
        return $this->status < self::TRANSPORT_FAILED;
    }

    public function why(): string
    {
        $name = self::NAMES[$this->status] ?? ('status '.$this->status);

        return $this->detail ? $name.': '.$this->detail : $name;
    }

    /**
     * What call() has always returned: the payload, or false.
     *
     * Kept so the callers that only ever asked "did I get something" do not
     * have to change to gain the ones that need more.
     */
    public function orFalse(): mixed
    {
        return $this->isOk() ? $this->data : false;
    }
}
