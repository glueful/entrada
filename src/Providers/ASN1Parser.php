<?php

declare(strict_types=1);

namespace Glueful\Extensions\Entrada\Providers;

/**
 * Minimal ASN.1 DER parser for ECDSA signatures.
 *
 * Only handles the SEQUENCE / INTEGER shapes OpenSSL emits for ECDSA signatures
 * (a SEQUENCE of two INTEGERs). Every read is bounds-checked: truncated input,
 * over-long length encodings, and lengths that run past the end of the buffer all
 * throw \RuntimeException rather than reading out of bounds.
 */
class ASN1Parser
{
    /** @var string Binary data to parse */
    private string $data;

    /** @var int Byte length of the data */
    private int $len;

    /** @var int Current position in the data */
    private int $pos = 0;

    /**
     * Constructor
     *
     * @param string $data Binary data to parse
     */
    public function __construct(string $data)
    {
        $this->data = $data;
        $this->len = strlen($data);
    }

    /** ASN.1 SEQUENCE tag (constructed). */
    private const TAG_SEQUENCE = 0x30;

    /**
     * Read an ASN.1 object from the data.
     *
     * For the constructed SEQUENCE tag the position is left at the start of the content so the
     * caller can read the contained elements (the two INTEGERs of an ECDSA signature) with
     * subsequent readObject() calls. For primitive elements the content is consumed and returned
     * as `value`. Either way the declared length is bounds-checked against the remaining buffer.
     *
     * @return array{type: int, length: int, value: string} Object information
     * @throws \RuntimeException on truncated or malformed DER
     */
    public function readObject(): array
    {
        $type = $this->readByte();
        $length = $this->readLength();

        if ($length > $this->remaining()) {
            throw new \RuntimeException('ASN.1 content length exceeds available data');
        }

        if ($type === self::TAG_SEQUENCE) {
            // Descend into the SEQUENCE; leave the position at its first inner element.
            $value = substr($this->data, $this->pos, $length);
        } else {
            $value = substr($this->data, $this->pos, $length);
            $this->pos += $length;
        }

        return [
            "type" => $type,
            "length" => $length,
            "value" => $value,
        ];
    }

    /**
     * Read a single byte and advance the position.
     *
     * @throws \RuntimeException when no bytes remain
     */
    private function readByte(): int
    {
        if ($this->pos >= $this->len) {
            throw new \RuntimeException('Unexpected end of ASN.1 data');
        }

        return ord($this->data[$this->pos++]);
    }

    /**
     * Read an ASN.1 length field (short or long form).
     *
     * @return int Length of the following content
     * @throws \RuntimeException on truncated or unsupported length encoding
     */
    private function readLength(): int
    {
        $length = $this->readByte();

        if (($length & 0x80) === 0) {
            return $length;
        }

        $lengthBytes = $length & 0x7F;

        // 0x80 is the indefinite-length form, which DER forbids; reject it.
        if ($lengthBytes === 0) {
            throw new \RuntimeException('Indefinite-length ASN.1 encoding is not supported');
        }

        // A length spanning more than 4 bytes would overflow a sane signature size
        // (and risks integer overflow); reject it outright.
        if ($lengthBytes > 4) {
            throw new \RuntimeException('ASN.1 length encoding is too long');
        }

        $length = 0;
        for ($i = 0; $i < $lengthBytes; $i++) {
            $length = ($length << 8) | $this->readByte();
        }

        return $length;
    }

    /**
     * Number of unconsumed bytes remaining in the buffer.
     */
    private function remaining(): int
    {
        return $this->len - $this->pos;
    }
}
