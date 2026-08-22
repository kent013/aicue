<?php

declare(strict_types=1);

namespace App\Support\Organization;

use App\Enums\Organization\SlugReservationReason;
use RuntimeException;

/**
 * 予約語の判定器 (家系裁定 AG-039 / 不変条件 I9・I11)。
 *
 * ★設定 (`config/organization-slug-reserved.php`) を読み込み、**読み込み直後に
 *   型付きの値へ変換**する。分類の無い語・未知の分類は例外で落とす (fail-closed)。
 * ★語そのものは構文型を通してから比較する — 大文字混じりの語が設定に紛れても
 *   照合が黙って外れないようにするため、読み込み時に構文検査を通す。
 */
final class OrganizationSlugReservedWords
{
    /** @param array<string, SlugReservationReason> $words */
    private function __construct(private readonly array $words) {}

    /**
     * 設定を読み込み、**読み込み直後に型付きの値へ変換**する。
     *
     * @param  array<mixed>|null  $config  null のとき config() から読む (テストが合成入力を渡せる seam)
     */
    public static function load(?array $config = null): self
    {
        /** @var array<mixed> $raw */
        $raw = $config ?? config('organization-slug-reserved.words', []);

        $words = [];
        foreach ($raw as $word => $reason) {
            if (! is_string($word)) {
                throw new RuntimeException('予約語のキーは文字列でなければならない: '.var_export($word, true));
            }
            if (! is_string($reason) || $reason === '') {
                throw new RuntimeException("予約語 '{$word}' に理由の分類が無い (3 分類の記載は必須)");
            }
            $parsed = SlugReservationReason::tryFrom($reason);
            if ($parsed === null) {
                throw new RuntimeException("予約語 '{$word}' の分類 '{$reason}' は未知である");
            }

            // 設定に書かれた語も構文型を通す (大文字混じり等で照合が黙って外れるのを防ぐ)
            $words[OrganizationSlug::fromString($word)->value] = $parsed;
        }

        if ($words === []) {
            throw new RuntimeException('予約語が 1 件も無い (設定の読み込みに失敗している可能性がある)');
        }

        return new self($words);
    }

    public function reservationFor(OrganizationSlug $slug): ?SlugReservationReason
    {
        return $this->words[$slug->value] ?? null;
    }

    /** @return list<string> 登録済みの予約語 (昇順) */
    public function all(): array
    {
        $words = array_keys($this->words);
        sort($words);

        return $words;
    }
}
