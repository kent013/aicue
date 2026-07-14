<?php

declare(strict_types=1);

namespace App\Services\Storage\Fakes;

use RuntimeException;

/**
 * fake storage の PUT で実 body の checksum が期待値 (署名 checksum) と一致しない。
 * controller が catch して 400 に写像する (実 S3 の checksum 不一致 PUT 拒否の emulation)。
 */
final class FakeStorageChecksumMismatch extends RuntimeException {}
