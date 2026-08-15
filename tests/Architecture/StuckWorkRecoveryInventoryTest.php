<?php

declare(strict_types=1);

use App\Enums\Recovery\RecoveryOutcome;
use App\Enums\Recovery\RecoveryStream;
use App\Services\Recovery\StuckWorkStreamRegistry;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Schedule;
use Tests\Support\Recovery\NonRecoveryScheduleEntry;
use Tests\Support\Recovery\RecoveryStreamEntry;
use Tests\Support\Recovery\StuckWorkRecoveryInventory;

/*
 * 滞留回収の目録 (deny-by-default / exact-fit)。
 *
 * 本 gate が固定すること:
 * 1. registry の系列集合 == RecoveryStream の全 case == 目録の申告集合
 * 2. Schedule に載る work:recover-stuck --stream=<key> の集合が系列のキーと一致する
 *    (突き合わせは**コマンド名ではなく系列のキー**で行う。全部が同じコマンド名のため)
 * 3. 各系列の Schedule が --apply / onOneServer / withoutOverlapping / onFailure の 4 点と
 *    目録の実行間隔を持ち、多重起動抑止の有効期限が既定 (24 時間) ではないこと
 *    (**--apply の付け忘れは無音で回収を全面停止させるため、この検査が本 gate の主目的**)
 * 4. 各系列の sweepItemLimit() が目録の申告値と一致する
 * 5. 各系列が取りうる結果の種類を目録で申告している
 * 6. Schedule に載っている全コマンドが、上の回収の入口か NonRecoveryScheduleEntry
 *    (区分 + 30 文字以上の理由) のどちらかに属する (未分類は fail)
 *
 * **保証しないもの (誇張しない)**:
 * - 目録は申告の集合一致を見るだけで、recover() が実際に行ロック下で述語を再評価しているかは
 *   検査できない (それは各系列の Feature テストが担う)
 * - Schedule の検査は**登録内容**を見るだけで、定期実行の仕組みが実際に動いているかは
 *   検査できない (運用側の監視対象)
 */

/** Schedule に登録された全イベント */
function recoveryScheduledEvents(): array
{
    return array_values(Schedule::events());
}

/** イベントのコマンド文字列から artisan のコマンド名と引数部分を取り出す */
function recoveryCommandLine(Event $event): string
{
    $command = (string) $event->command;
    // "'/usr/bin/php' 'artisan' foo:bar --baz" の形から artisan 以降だけを残す
    $position = strpos($command, "'artisan'");

    return $position === false ? $command : trim(substr($command, $position + strlen("'artisan'")));
}

/** コマンド行の先頭 (引数を除いたコマンド名。artisan の引用符も外す) */
function recoveryCommandName(Event $event): string
{
    $first = explode(' ', trim(recoveryCommandLine($event)))[0];

    return trim($first, "'\"");
}

/** work:recover-stuck の登録だけを系列キー => Event で返す */
function recoveryStreamEvents(): array
{
    $events = [];
    foreach (recoveryScheduledEvents() as $event) {
        if (recoveryCommandName($event) !== StuckWorkRecoveryInventory::RECOVERY_COMMAND) {
            continue;
        }
        $line = recoveryCommandLine($event);
        if (preg_match('/--stream=([a-z_]+)/', $line, $matches) !== 1) {
            continue;
        }
        $events[$matches[1]][] = $event;
    }

    return $events;
}

test('registry の系列集合と RecoveryStream の全 case と目録の申告集合が一致する', function (): void {
    $cases = array_map(static fn (RecoveryStream $stream): string => $stream->value, RecoveryStream::cases());
    sort($cases);

    $registered = array_map(
        static fn (object $stream): string => $stream->stream()->value,
        app(StuckWorkStreamRegistry::class)->all(),
    );
    sort($registered);

    $declared = array_keys(StuckWorkRecoveryInventory::streams());
    sort($declared);

    expect($registered)->toBe($cases, 'registry の登録が RecoveryStream の case と一致していません');
    expect($declared)->toBe($cases,
        '滞留回収の目録 (StuckWorkRecoveryInventory::streams) に未登録の系列があります。'
        .'系列を増やしたら目録・registry・Schedule の 3 つを同時に更新してください。');
});

test('目録の申告する実装クラスが registry の解決結果と一致する', function (): void {
    $registry = app(StuckWorkStreamRegistry::class);
    $violations = [];

    foreach (StuckWorkRecoveryInventory::streams() as $key => $entry) {
        $resolved = $registry->get($entry->stream);
        if ($resolved::class !== $entry->implementation) {
            $violations[] = $key.' — 目録: '.$entry->implementation.' / 実際: '.$resolved::class;
        }
    }

    expect($violations)->toBe([], '目録の implementation が registry の解決結果と違います。'
        .PHP_EOL.implode(PHP_EOL, $violations));
});

test('各系列の 1 掃引の上限が目録の申告値と一致する', function (): void {
    $registry = app(StuckWorkStreamRegistry::class);
    $violations = [];

    foreach (StuckWorkRecoveryInventory::streams() as $key => $entry) {
        $actual = $registry->get($entry->stream)->sweepItemLimit();
        if ($actual !== $entry->sweepItemLimit) {
            $violations[] = $key.' — 目録: '.var_export($entry->sweepItemLimit, true).' / 実際: '.var_export($actual, true);
        }
    }

    expect($violations)->toBe([], '1 掃引の上限が目録と食い違っています (上限を変えたら目録も変える)。'
        .PHP_EOL.implode(PHP_EOL, $violations));
});

test('各系列が取りうる結果の種類を目録で申告し、説明を持つ', function (): void {
    foreach (StuckWorkRecoveryInventory::streams() as $key => $entry) {
        expect($entry->possibleOutcomes)->not->toBe([], $key);
        expect(mb_strlen($entry->description))
            ->toBeGreaterThanOrEqual(RecoveryStreamEntry::DESCRIPTION_MIN_LENGTH, $key);
    }
});

test('全系列が「前へ進めた」と「競合で何もしなかった」を必ず申告する', function (): void {
    // 回収の系列である以上、この 2 つは必ず起こりうる (起こりえないなら回収ではない)。
    // これを落とすと申告が「実際に取りうる種類」ではなく飾りになる
    $violations = [];
    foreach (StuckWorkRecoveryInventory::streams() as $key => $entry) {
        foreach ([RecoveryOutcome::Recovered, RecoveryOutcome::Skipped] as $required) {
            if (! in_array($required, $entry->possibleOutcomes, true)) {
                $violations[] = $key.' — '.$required->value.' を申告していない';
            }
        }
    }

    expect($violations)->toBe([],
        '回収の系列である以上「前へ進めた」と「競合で何もしなかった」は必ず起こりうる。'
        .PHP_EOL.implode(PHP_EOL, $violations));
});

test('申告された結果の種類の合併が RecoveryOutcome の全 case を覆う (死んだ値を残さない)', function (): void {
    $declared = [];
    foreach (StuckWorkRecoveryInventory::streams() as $entry) {
        foreach ($entry->possibleOutcomes as $outcome) {
            $declared[$outcome->value] = true;
        }
    }
    $declared = array_keys($declared);
    sort($declared);

    $cases = array_map(static fn (RecoveryOutcome $outcome): string => $outcome->value, RecoveryOutcome::cases());
    sort($cases);

    // ★保証しないもの: 目録は**申告**の集合を見るだけで、各系列の recover() が実際にその種類を
    //   返しうるかは検査できない (それは各系列の Feature テストが担う)。
    //   ここで固定するのは「どの系列も返さない結果の種類が enum に残っていない」ことである
    expect($declared)->toBe($cases,
        'どの系列も申告していない結果の種類があります (使われない値を enum に残さない)。'
        .'値を増やすなら、それを返す系列の申告も同時に足してください。');
});

test('Schedule の work:recover-stuck は系列ごとにちょうど 1 本ずつ登録されている', function (): void {
    $events = recoveryStreamEvents();

    $keys = array_keys($events);
    sort($keys);
    $declared = array_keys(StuckWorkRecoveryInventory::streams());
    sort($declared);

    expect($keys)->toBe($declared,
        'Schedule に載っている系列と目録の系列が一致しません '
        .'(突き合わせはコマンド名ではなく系列のキーで行う。全系列が同じコマンド名のため)。');

    foreach ($events as $key => $registered) {
        expect($registered)->toHaveCount(1, $key.' の Schedule 登録が 1 本ではありません');
    }
});

test('各系列の Schedule が --apply / onOneServer / withoutOverlapping / 実行間隔を持つ', function (): void {
    $violations = [];

    foreach (recoveryStreamEvents() as $key => $registered) {
        $event = $registered[0];
        $line = recoveryCommandLine($event);
        $stream = RecoveryStream::from($key);

        if (! str_contains($line, '--apply')) {
            // ここが本 gate の主目的。--apply が落ちると回収は 1 件も実行されないのに
            // 終了コードも出力も正常に見えるため、無音で全面停止する
            $violations[] = $key.' — Schedule に --apply が無い (回収が 1 件も実行されない)';
        }
        if (! $event->onOneServer) {
            $violations[] = $key.' — onOneServer() が無い';
        }
        if (! $event->withoutOverlapping) {
            $violations[] = $key.' — withoutOverlapping() が無い';
        }
        if ($event->expiresAt !== $stream->overlapExpiryMinutes()) {
            $violations[] = $key.' — 多重起動抑止の有効期限が '.$stream->overlapExpiryMinutes()
                .' 分でない (実際: '.$event->expiresAt.' 分)。既定の 1440 分だと'
                .'異常終了で残ったロックが丸 1 日回収を止める';
        }
        $expected = '*/'.$stream->cadenceMinutes().' * * * *';
        if ($event->expression !== $expected) {
            $violations[] = $key.' — 実行間隔が目録 (RecoveryStream::cadenceMinutes) と違う: '
                .$event->expression.' (期待: '.$expected.')';
        }
    }

    expect($violations)->toBe([], '滞留回収の Schedule 配線が契約を満たしていません。'
        .PHP_EOL.implode(PHP_EOL, $violations));
});

test('各系列の Schedule 失敗が報告される (onFailure が繋がっている)', function (): void {
    $violations = [];

    foreach (recoveryStreamEvents() as $key => $registered) {
        $event = $registered[0];
        $property = new ReflectionProperty(Event::class, 'afterCallbacks');
        /** @var list<Closure> $callbacks */
        $callbacks = $property->getValue($event);

        Exceptions::fake();
        $event->exitCode = 1;
        foreach ($callbacks as $callback) {
            $callback(app());
        }

        $messages = array_map(
            static fn (Throwable $exception): string => $exception->getMessage(),
            Exceptions::reported(),
        );
        $matched = array_filter(
            $messages,
            static fn (string $message): bool => str_contains($message, 'work:recover-stuck --stream='.$key),
        );
        if ($matched === []) {
            $violations[] = $key.' — 失敗時に報告が出ない (onFailure が繋がっていない)';
        }
    }

    expect($violations)->toBe([],
        '回収が止まったことが無音にならないよう、全系列の Schedule に onFailure → report() を付けてください。'
        .PHP_EOL.implode(PHP_EOL, $violations));
});

test('Schedule の全コマンドが回収の入口か非回収の申告のどちらかに属する (未分類は fail)', function (): void {
    $declared = StuckWorkRecoveryInventory::nonRecoverySchedules();
    $unclassified = [];
    $seen = [];

    foreach (recoveryScheduledEvents() as $event) {
        $name = recoveryCommandName($event);
        if ($name === StuckWorkRecoveryInventory::RECOVERY_COMMAND) {
            continue; // 回収の入口 (上のテスト群が担当)
        }
        $seen[$name] = true;
        if (! array_key_exists($name, $declared)) {
            $unclassified[] = $name;
        }
    }

    expect(array_values(array_unique($unclassified)))->toBe([],
        '定期実行に未分類のコマンドがあります。滞留回収なら work:recover-stuck の系列として '
        .'RecoveryStream へ足し、そうでなければ StuckWorkRecoveryInventory::nonRecoverySchedules() へ '
        .'区分と 30 文字以上の理由付きで登録してください (6 本目の独自回収を素通しで足せない)。'
        .PHP_EOL.implode(PHP_EOL, $unclassified));

    $stale = array_values(array_diff(array_keys($declared), array_keys($seen)));
    expect($stale)->toBe([],
        '非回収の申告に、Schedule へ登録されていないコマンドが残っています (申告を消してください)。'
        .PHP_EOL.implode(PHP_EOL, $stale));
});

test('非回収の申告はすべて区分と 30 文字以上の理由を持つ', function (): void {
    foreach (StuckWorkRecoveryInventory::nonRecoverySchedules() as $name => $entry) {
        expect(mb_strlen($entry->reason))
            ->toBeGreaterThanOrEqual(NonRecoveryScheduleEntry::REASON_MIN_LENGTH, $name);
    }
});
