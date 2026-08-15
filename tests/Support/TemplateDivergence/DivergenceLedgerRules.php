<?php

declare(strict_types=1);

namespace Tests\Support\TemplateDivergence;

use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;

/**
 * 逸脱の登録簿の形式違反を列挙する (純関数)。
 *
 * **保証しない範囲** (誇張しない):
 *  - 実ファイルがテンプレートから逸脱したのに登録が無いことは検出しない
 *    (実体との突合は台帳リポジトリの巡回が持つ。家系の裁定 AG-159)
 *  - 内容がテンプレート準拠へ戻った登録の残置も検出しない (対象パスは実在し続けるため)
 *  - 登録の中身が正しいことは見ない (空でないこと・値域に収まっていることだけを見る)
 *  - 登録エントリ領域より前の節と、エントリの中の `###` 見出し・地の文は見ない
 *  - 削除した番号の再利用は検出しない (使用済み番号の履歴を持たないため)
 *
 * 固定件数 (`LedgerContext::$pinnedEntryCount`) は**明示件数との同期検査**であって、
 * 例外を許す一覧ではない。個別の D 番号を名指しする許可一覧は持たない。
 */
final class DivergenceLedgerRules
{
    /** 状態の値域。どちらも「今ある逸脱」を表し、解消を意味する語は持たない。 */
    public const STATES = ['恒久', '監視中'];

    /** 決めた人の値域。 */
    public const DECIDERS = ['オーナー', '開発者'];

    /** 見直し期限の上限 (基準日からの日数)。青天井の期限で検査を無力化させない。 */
    public const MAX_REVIEW_WINDOW_DAYS = 400;

    /** `恒久` の見直し期限に置く不在の記号。 */
    public const PERMANENT_DEADLINE = '—';

    /**
     * プレースホルダの語彙 (過剰検出寄りに倒す)。
     *
     * 適用先は根拠と自由記述 3 欄だけである。見直し期限の `—` は `恒久` の正値なので、
     * 期限欄にはプレースホルダ検査を掛けない。
     *
     * @var list<string>
     */
    private const PLACEHOLDERS = ['', '...', '…', 'TBD', '未定', '-', '—', '?', '？'];

    /**
     * @return list<string> 違反一覧 (空 = 合格)
     */
    public static function violations(ParsedLedger $ledger, LedgerContext $context): array
    {
        $violations = $ledger->parseViolations;

        // 解析不能なら以降の規則は評価できない。評価しないことを違反として返す (fail-closed)。
        if ($ledger->unparsable) {
            return $violations;
        }

        /** @var array<string, list<string>> $pathOwners */
        $pathOwners = [];

        foreach ($ledger->entries as $entry) {
            $metadata = $entry->metadata;
            if ($metadata === null) {
                continue;
            }

            foreach (self::targetPathViolations($entry, $metadata, $context) as $violation) {
                $violations[] = $violation;
            }
            foreach ($metadata->targetPaths as $path) {
                $pathOwners[$path][] = $entry->label();
            }

            foreach (self::freeTextViolations($entry, $metadata) as $violation) {
                $violations[] = $violation;
            }
            foreach (self::decisionViolations($entry, $metadata, $context) as $violation) {
                $violations[] = $violation;
            }
            foreach (self::stateViolations($entry, $metadata, $context) as $violation) {
                $violations[] = $violation;
            }
        }

        foreach ($pathOwners as $path => $owners) {
            if (count($owners) > 1) {
                $violations[] = sprintf(
                    'TD4: 対象パス `%s` を %s が重複して挙げている。和集合で重複させない (片方を消しても赤にならなくなる)',
                    $path,
                    implode(' / ', $owners),
                );
            }
        }

        $parsedCount = count($ledger->entries);
        if ($ledger->declaredCount !== null && $ledger->declaredCount !== $parsedCount) {
            $violations[] = sprintf(
                'TD12: 明示件数 %d 件と解析した見出しの件数 %d 件が食い違う',
                $ledger->declaredCount,
                $parsedCount,
            );
        }
        if ($parsedCount !== $context->pinnedEntryCount) {
            $violations[] = sprintf(
                'TD12: 解析した見出しの件数 %d 件と検査側の固定件数 %d 件が食い違う (登録を足した / 消したら同じ変更で固定件数も直す)',
                $parsedCount,
                $context->pinnedEntryCount,
            );
        }

        return $violations;
    }

    /**
     * TD3: 対象パスの書式・値域・実在。
     *
     * @return list<string>
     */
    private static function targetPathViolations(ParsedEntry $entry, EntryMetadata $metadata, LedgerContext $context): array
    {
        /** @var list<string> $violations */
        $violations = [];

        if ($metadata->targetPaths === []) {
            $violations[] = sprintf(
                'TD3: %s の対象パスが読めない。バッククォート囲みのパスを ` / ` でつないだ形だけを許す (実測: %s)',
                $entry->label(),
                $metadata->rawTargetPathCell === '' ? '(空)' : $metadata->rawTargetPathCell,
            );

            return $violations;
        }

        foreach ($metadata->targetPaths as $path) {
            if (str_starts_with($path, '/')) {
                $violations[] = sprintf('TD3: %s の対象パス `%s` が絶対パスである', $entry->label(), $path);

                continue;
            }
            if (preg_match('/[*?\[\]{}]/', $path) === 1) {
                $violations[] = sprintf('TD3: %s の対象パス `%s` が glob である。実ファイル名へ展開する', $entry->label(), $path);

                continue;
            }
            if (in_array('..', explode('/', $path), true)) {
                $violations[] = sprintf('TD3: %s の対象パス `%s` が上位への相対指定を含む', $entry->label(), $path);

                continue;
            }
            if (! ($context->pathExists)($path)) {
                $violations[] = sprintf(
                    'TD3: %s の対象パス `%s` がファイルとして実在しない (ディレクトリは対象パスに書けない)',
                    $entry->label(),
                    $path,
                );
            }
        }

        return $violations;
    }

    /**
     * TD11: 自由記述 3 欄が空でもプレースホルダでもないこと。
     *
     * @return list<string>
     */
    private static function freeTextViolations(ParsedEntry $entry, EntryMetadata $metadata): array
    {
        /** @var list<string> $violations */
        $violations = [];

        $columns = [
            '業務要件起因の説明' => $metadata->domainReason,
            '揃え続ける不変条件と保証機構' => $metadata->invariantAndGuard,
            '再判定の条件' => $metadata->reevaluationCondition,
        ];

        foreach ($columns as $label => $value) {
            if (self::isPlaceholder($value)) {
                $violations[] = sprintf(
                    'TD11: %s の「%s」が空かプレースホルダである (恒久の登録にも再判定の条件は必須)',
                    $entry->label(),
                    $label,
                );
            }
        }

        return $violations;
    }

    /**
     * TD8 / TD9 / TD10: 決めた日・決めた人・根拠。
     *
     * @return list<string>
     */
    private static function decisionViolations(ParsedEntry $entry, EntryMetadata $metadata, LedgerContext $context): array
    {
        /** @var list<string> $violations */
        $violations = [];

        $decidedOn = self::parseDate($metadata->decidedOn);
        if ($decidedOn === null) {
            $violations[] = sprintf(
                'TD8: %s の決めた日「%s」が `YYYY-MM-DD` の実在する日付ではない',
                $entry->label(),
                $metadata->decidedOn,
            );
        } elseif ($decidedOn->greaterThan($context->baseDate)) {
            $violations[] = sprintf(
                'TD8: %s の決めた日 %s が未来日である (基準日 %s)',
                $entry->label(),
                $metadata->decidedOn,
                $context->baseDate->format('Y-m-d'),
            );
        }

        if (! in_array($metadata->decidedBy, self::DECIDERS, true)) {
            $violations[] = sprintf(
                'TD9: %s の決めた人「%s」は値域 (%s) にない',
                $entry->label(),
                $metadata->decidedBy,
                implode(' / ', self::DECIDERS),
            );
        }

        $rationale = $metadata->rationale;
        if (self::isPlaceholder($rationale)) {
            $violations[] = sprintf('TD10: %s の根拠が空かプレースホルダである', $entry->label());
        } elseif (preg_match('/^T\d{3,}$/', $rationale) === 1) {
            if (! ($context->rationaleExists)($rationale)) {
                $violations[] = sprintf(
                    'TD10: %s の根拠 %s が TODO 台帳 (docs/TODO.md / docs/TODO-closed.md) の表に実在しない',
                    $entry->label(),
                    $rationale,
                );
            }
        } elseif (preg_match('#^devnotes/[^/]+/$#', $rationale) === 1) {
            if (! ($context->directoryExists)(rtrim($rationale, '/'))) {
                $violations[] = sprintf('TD10: %s の根拠 %s がディレクトリとして実在しない', $entry->label(), $rationale);
            }
        } else {
            $violations[] = sprintf(
                'TD10: %s の根拠「%s」は `T<n>` (3 桁以上) か `devnotes/<dir>/` で書く',
                $entry->label(),
                $rationale,
            );
        }

        return $violations;
    }

    /**
     * TD5 / TD6 / TD7: 状態と見直し期限。
     *
     * @return list<string>
     */
    private static function stateViolations(ParsedEntry $entry, EntryMetadata $metadata, LedgerContext $context): array
    {
        /** @var list<string> $violations */
        $violations = [];

        if (! in_array($metadata->state, self::STATES, true)) {
            $violations[] = sprintf(
                'TD5: %s の状態「%s」は値域 (%s) にない。解消は状態ではなく登録の削除で表す',
                $entry->label(),
                $metadata->state,
                implode(' / ', self::STATES),
            );

            return $violations;
        }

        if ($metadata->state === '恒久') {
            if ($metadata->reviewDeadline !== self::PERMANENT_DEADLINE) {
                $violations[] = sprintf(
                    'TD7: %s は恒久なので見直し期限は「%s」ちょうどにする (実測「%s」)',
                    $entry->label(),
                    self::PERMANENT_DEADLINE,
                    $metadata->reviewDeadline,
                );
            }

            return $violations;
        }

        $deadline = self::parseDate($metadata->reviewDeadline);
        if ($deadline === null) {
            $violations[] = sprintf(
                'TD6: %s は監視中なので見直し期限を `YYYY-MM-DD` の実在する日付で書く (実測「%s」)',
                $entry->label(),
                $metadata->reviewDeadline,
            );

            return $violations;
        }

        if ($deadline->lessThan($context->baseDate)) {
            $violations[] = sprintf(
                'TD6: %s の見直し期限 %s が切れている (基準日 %s)。直し方は登録簿の規約節の 4 通りから選ぶ (検査は緩めない)',
                $entry->label(),
                $metadata->reviewDeadline,
                $context->baseDate->format('Y-m-d'),
            );
        }

        if ($deadline->greaterThan($context->baseDate->addDays(self::MAX_REVIEW_WINDOW_DAYS))) {
            $violations[] = sprintf(
                'TD6: %s の見直し期限 %s が基準日 %s から %d 日を超えている',
                $entry->label(),
                $metadata->reviewDeadline,
                $context->baseDate->format('Y-m-d'),
                self::MAX_REVIEW_WINDOW_DAYS,
            );
        }

        return $violations;
    }

    /** プレースホルダか (前後の空白は解析器が落としている)。 */
    private static function isPlaceholder(string $value): bool
    {
        return in_array($value, self::PLACEHOLDERS, true);
    }

    /**
     * `YYYY-MM-DD` として実在する日付だけを受け取る (失敗は null を返し、呼び出し側が違反にする)。
     *
     * `Carbon::parse()` は `2026-02-30` を 3/2 へ正規化して通してしまうため、
     * 書式を固定して往復比較する。例外に倒さないのは、日付の書き間違いを
     * 「検査の実行時エラー」ではなく「違反一覧の 1 行」として報せるためである。
     */
    private static function parseDate(string $value): ?CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (InvalidFormatException) {
            return null;
        }

        if (! $date instanceof CarbonImmutable || $date->format('Y-m-d') !== $value) {
            return null;
        }

        return $date;
    }
}
