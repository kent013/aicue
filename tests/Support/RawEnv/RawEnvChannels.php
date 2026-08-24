<?php

declare(strict_types=1);

namespace Tests\Support\RawEnv;

/**
 * 3 面 (`$_SERVER` / `$_ENV` / `putenv`) へ何を入れるかの指定 (不変の値オブジェクト)。
 *
 * ★**「指定しなかった」と「値が null」は別物である**。前者は「その面を明示的に未設定にする」
 *   という意味であり (家系の正典 raw-env-snapshot-restore v1 の i7)、後者は
 *   「その面に null を入れる」である。したがって面ごとに「指定したか」を値と別に持つ。
 *   `?null` では表現できない。
 * ★**値の型を絞らない** (i3)。`$_SERVER` / `$_ENV` は mixed を持ちうるし、
 *   本リポジトリには非文字列 (配列) を入れて fail-closed を確かめる既存ケースがある。
 * ★**`putenv` 面だけは `string` に限る**。`putenv()` は文字列しか受け取れないので、
 *   非文字列がこの面へ到達する経路を型で消す (`sameOnAllSurfaces()` が `string` しか
 *   受け取らないのはこのためである。非文字列は `withServer()` / `withEnv()` からしか指定できない)。
 * ★生成は `none()` / `sameOnAllSurfaces()` を起点にした派生だけである
 *   (配列リテラルを受ける口は公開しない)。
 */
final class RawEnvChannels
{
    private function __construct(
        public readonly bool $serverSpecified,
        public readonly mixed $serverValue,
        public readonly bool $envSpecified,
        public readonly mixed $envValue,
        public readonly bool $processSpecified,
        public readonly string $processValue,
    ) {}

    /** 3 面とも未指定 (= 適用すると 3 面とも明示的に未設定になる)。 */
    public static function none(): self
    {
        return new self(false, null, false, null, false, '');
    }

    /** 3 面そろえて同じ文字列を入れる (最も普通の使い方)。 */
    public static function sameOnAllSurfaces(string $value): self
    {
        return new self(true, $value, true, $value, true, $value);
    }

    /** `$_SERVER` 面にだけ値を足す (他の面の指定は引き継ぐ)。 */
    public function withServer(mixed $value): self
    {
        return new self(
            true,
            $value,
            $this->envSpecified,
            $this->envValue,
            $this->processSpecified,
            $this->processValue,
        );
    }

    /** `$_ENV` 面にだけ値を足す (他の面の指定は引き継ぐ)。 */
    public function withEnv(mixed $value): self
    {
        return new self(
            $this->serverSpecified,
            $this->serverValue,
            true,
            $value,
            $this->processSpecified,
            $this->processValue,
        );
    }

    /** `putenv` 面にだけ値を足す (他の面の指定は引き継ぐ)。 */
    public function withProcess(string $value): self
    {
        return new self(
            $this->serverSpecified,
            $this->serverValue,
            $this->envSpecified,
            $this->envValue,
            true,
            $value,
        );
    }
}
