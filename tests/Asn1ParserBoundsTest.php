<?php

declare(strict_types=1);

namespace Glueful\Extensions\Entrada\Tests;

use Glueful\Extensions\Entrada\Providers\ASN1Parser;
use PHPUnit\Framework\TestCase;

/**
 * ASN1Parser must parse the well-formed SEQUENCE-of-two-INTEGERs DER that OpenSSL emits for
 * ECDSA signatures, and must fail closed (throw \RuntimeException) on any truncated or malformed
 * input rather than reading past the end of the buffer.
 */
final class Asn1ParserBoundsTest extends TestCase
{
    public function test_parses_well_formed_ecdsa_der_signature(): void
    {
        $der = $this->makeEcdsaDer();

        $parser = new ASN1Parser($der);

        $seq = $parser->readObject();
        self::assertSame(0x30, $seq['type']);

        $r = $parser->readObject();
        $s = $parser->readObject();
        self::assertSame(0x02, $r['type']);
        self::assertSame(0x02, $s['type']);
        self::assertNotSame('', $r['value']);
        self::assertNotSame('', $s['value']);
    }

    public function test_hand_constructed_sequence_of_two_integers_parses(): void
    {
        // SEQUENCE { INTEGER 0x01, INTEGER 0x02 }
        $der = "\x30\x06\x02\x01\x01\x02\x01\x02";

        $parser = new ASN1Parser($der);

        $seq = $parser->readObject();
        self::assertSame(0x30, $seq['type']);
        self::assertSame(6, $seq['length']);
    }

    public function test_empty_input_throws(): void
    {
        $parser = new ASN1Parser('');

        $this->expectException(\RuntimeException::class);
        $parser->readObject();
    }

    public function test_truncated_der_throws(): void
    {
        // SEQUENCE header declares 6 content bytes, but only the type tag is present.
        $der = "\x30\x06\x02";

        $parser = new ASN1Parser($der);

        $this->expectException(\RuntimeException::class);
        // First readObject consumes the SEQUENCE (length 6 > remaining) and throws.
        $parser->readObject();
    }

    public function test_declared_length_beyond_data_throws(): void
    {
        // INTEGER claiming 200 content bytes with none following.
        $der = "\x02\x81\xC8";

        $parser = new ASN1Parser($der);

        $this->expectException(\RuntimeException::class);
        $parser->readObject();
    }

    public function test_missing_length_byte_throws(): void
    {
        // Only a type tag, no length byte.
        $der = "\x30";

        $parser = new ASN1Parser($der);

        $this->expectException(\RuntimeException::class);
        $parser->readObject();
    }

    public function test_overlong_length_encoding_throws(): void
    {
        // Long-form length claiming 5 length bytes (> 4 supported).
        $der = "\x02\x85\x00\x00\x00\x00\x01";

        $parser = new ASN1Parser($der);

        $this->expectException(\RuntimeException::class);
        $parser->readObject();
    }

    /**
     * Produce a real DER-encoded ECDSA signature (SEQUENCE of two INTEGERs) using a P-256 key.
     */
    private function makeEcdsaDer(): string
    {
        $pkey = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);

        self::assertNotFalse($pkey, 'Unable to generate a P-256 key (OpenSSL EC support required)');

        $signature = '';
        $signed = openssl_sign('entrada-asn1-test', $signature, $pkey, OPENSSL_ALGO_SHA256);
        self::assertTrue($signed, 'openssl_sign failed');

        return $signature;
    }
}
