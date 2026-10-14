<?php

declare(strict_types=1);

namespace App\Domain\ValueObject;

use InvalidArgumentException;
use Random\RandomException;

final readonly class UuidV7
{
    private const string REGEX = '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    public string $value;

    public function __construct(string $value)
    {
        $trimmed = strtolower(trim($value));
        if (preg_match(self::REGEX, $trimmed) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid UUID v7: "%s".', $value));
        }

        $this->value = $trimmed;
    }

    /**
     * Generate a new RFC 9562 UUID v7 (Unix Epoch timestamp in ms + random bits).
     *
     * @throws RandomException
     */
    public static function generate(?int $timestampMs = null): self
    {
        $ms = $timestampMs ?? (int) floor(microtime(true) * 1000);

        // 48 bits of unix timestamp in milliseconds
        $timeHex = str_pad(dechex($ms), 12, '0', STR_PAD_LEFT);

        // 12 random bytes
        $bytes = random_bytes(10);
        $randomHex = bin2hex($bytes);

        // Format into UUID v7: 8-4-4-4-12
        // time_high_and_mid (8 chars)
        $p1 = substr($timeHex, 0, 8);
        // time_low (4 chars)
        $p2 = substr($timeHex, 8, 4);
        // ver (7) + 3 random hex chars
        $p3 = '7' . substr($randomHex, 0, 3);
        // var (10xx in binary -> 8, 9, a, or b in hex) + 3 random hex chars
        $varByte = dechex((hexdec(substr($randomHex, 3, 2)) & 0x3f) | 0x80);
        $p4 = str_pad($varByte, 2, '0', STR_PAD_LEFT) . substr($randomHex, 5, 2);
        // remaining 12 random hex chars
        $p5 = substr($randomHex, 7, 12);

        return new self(sprintf('%s-%s-%s-%s-%s', $p1, $p2, $p3, $p4, $p5));
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function toString(): string
    {
        return $this->value;
    }
}
