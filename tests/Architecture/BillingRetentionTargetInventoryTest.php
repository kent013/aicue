<?php

declare(strict_types=1);

use App\Enums\Billing\BillingRetentionExclusion;
use App\Enums\Billing\BillingRetentionTarget;
use App\Services\Billing\Contracts\BillingRetentionPurger;
use App\Services\Billing\Retention\BillingRetentionPurgerRegistry;
use Illuminate\Database\Eloquent\Model;

/*
 * Architecture invariant: **課金記録の保持期間 (7 年) の purge 目録は deny-by-default**。
 *
 * SoT = devnotes/20260809-0908-account-deletion-grace/detailed-design.md の PR-C1
 * (lctl 台帳 feature `account-deletion-billing-guard` 標準形 v1 / 裁定 AG-128 の必須 (3)
 * 「保持期間 = 規約が宣言する年数と実処理の対応づけ」)。
 *
 * ★この gate が保証するもの:
 *   - 検査 1: 課金モデルの母集団 (app/Models/Billing/** + Cashier の SubscriptionItem) が
 *     `BillingRetentionTarget` ∪ `BillingRetentionExclusion` に **ちょうど 1 回**現れる
 *     (新しい課金モデルを足したら「消す / 消さない」を必ず宣言させる)
 *   - 検査 2: 全 case の rationale() が 30 文字以上
 *   - 検査 3: 起算点 / 補助時計の**修飾名が構造的に解決できる** (`{table}.{column}` の
 *     table 部が目録内の実在 target のテーブルである)
 *   - 検査 4: target と purger 実装クラスが exact-fit (registry / ディレクトリ / enum の 3 者)
 *   - 検査 4b: 実行順が**子 → 親** (`SubscriptionItem` → `Subscription`)
 *   - 検査 5: 空振り検知 (母集団件数 / 目録件数 / purger 件数を**現在値ちょうど**で pin。
 *     余裕枠は「根拠なしに増やせる枠」になるため持たせない)
 *   - 検査 6: 負のコントロール (未分類のダミーモデルを混ぜると検査 1 が点灯する)
 *
 * ★この gate が保証しないもの (誇張しない):
 *   - **purge 対象テーブルの網羅性は保証しない**。母集団は `app/Models/Billing/` という
 *     **ディレクトリの人間の申告**であり、課金取引の記録が別ディレクトリ (例: app/Models/ 直下 /
 *     別ドメインのモデル) や Eloquent を経由しない表に置かれた場合、この gate は**沈黙する**。
 *     目録は「機械が見つけた全部」ではなく「人間が申告した全部」である
 *   - **列が実在するか**は静的には見ない。詳細設計 C1d は実在列の照合 (schema 照合) も
 *     本 gate の責務としていたが、**Architecture lane は DB を持たない** (tests/Pest.php が
 *     RefreshDatabase を Feature/Unit にしか適用しないため、ここで Schema を引いても
 *     migration 前の空スキーマしか見えず**常に空振り**する)。よって schema 照合だけは
 *     tests/Feature/Billing/BillingRetentionHorizonTest.php へ移した (検査そのものは消していない)。
 *     ここで見るのは修飾名の**構造**(検査 3) までである
 *   - **起算点の意味の正しさ** (その列が本当に「取引の終了時」か) は人間のレビュー対象である
 *   - 保持年数の値そのもの / 規約文面との一致は本 gate の担当ではない
 *     (前者は tests/Architecture/BillingRetentionConfigSingleSourceTest.php、
 *      後者は PR-C3 の三者一致 gate)
 *
 * DB 不使用の静的検査 (Architecture lane の作法)。
 */

/**
 * app/Models/Billing/ の外にある母集団 (理由付きの明示列挙)。
 *
 * `Laravel\Cashier\SubscriptionItem` は vendor のモデルだが `subscription_items` は
 * 自リポジトリの migration が作る課金取引の記録であり、親 (`subscriptions`) を消すなら
 * 一緒に決着させないと孤児になる。vendor 由来を理由に母集団から外さない。
 *
 * @var array<class-string<Model>, string>
 */
const BILLING_RETENTION_EXTERNAL_POPULATION = [
    'Laravel\Cashier\SubscriptionItem' => 'subscription_items は自リポジトリの migration が作る課金記録 (親 subscriptions の子)',
];

/** 母集団の**現在件数** (cap ではなく exact-fit。増減したら必ずこの数字を書き換える)。 */
const BILLING_RETENTION_POPULATION_COUNT = 14;

/** C1 時点の purger 実装数 (TicketLedgerEntry は C2 の畳み込み待ちで含まない)。 */
const BILLING_RETENTION_PURGER_COUNT = 6;

/**
 * 母集団: app/Models/Billing 配下の全 Model + 外部列挙。
 *
 * @return list<class-string<Model>>
 */
function billingRetentionPopulation(): array
{
    $classes = [];
    $base = app_path('Models/Billing');
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
    );
    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $relative = str_replace([$base.'/', '.php', '/'], ['', '', '\\'], $file->getPathname());
        $class = 'App\\Models\\Billing\\'.$relative;
        if (class_exists($class) && is_subclass_of($class, Model::class)) {
            $classes[] = $class;
        }
    }

    foreach (array_keys(BILLING_RETENTION_EXTERNAL_POPULATION) as $class) {
        if (class_exists($class) && is_subclass_of($class, Model::class)) {
            $classes[] = $class;
        }
    }

    sort($classes);

    return $classes;
}

/**
 * 目録が宣言しているモデルクラス => 宣言箇所ラベル (重複検出のため list で持つ)。
 *
 * @return array<class-string<Model>, list<string>>
 */
function billingRetentionDeclarations(): array
{
    $declared = [];
    foreach (BillingRetentionTarget::cases() as $case) {
        $declared[$case->modelClass()][] = 'target:'.$case->value;
    }
    foreach (BillingRetentionExclusion::cases() as $case) {
        $declared[$case->modelClass()][] = 'exclusion:'.$case->value;
    }

    return $declared;
}

/**
 * 母集団と目録の突合 (純関数 = 負のコントロールから直接呼べる)。
 *
 * @param  list<class-string<Model>>  $population
 * @param  array<class-string<Model>, list<string>>  $declared
 * @return array{unclassified: list<string>, duplicated: list<string>, phantom: list<string>}
 */
function billingRetentionClassify(array $population, array $declared): array
{
    $unclassified = [];
    $duplicated = [];
    foreach ($population as $class) {
        $labels = $declared[$class] ?? [];
        if ($labels === []) {
            $unclassified[] = $class;

            continue;
        }
        if (count($labels) > 1) {
            $duplicated[] = $class.' ('.implode(' / ', $labels).')';
        }
    }

    $phantom = [];
    foreach (array_keys($declared) as $class) {
        if (! in_array($class, $population, true)) {
            $phantom[] = $class;
        }
    }

    return ['unclassified' => $unclassified, 'duplicated' => $duplicated, 'phantom' => $phantom];
}

/** 目録内の全 target のテーブル名。 */
function billingRetentionKnownTables(): array
{
    return array_map(
        static fn (BillingRetentionTarget $case): string => $case->table(),
        BillingRetentionTarget::cases(),
    );
}

/**
 * `app/Services/Billing/Retention/` 配下で BillingRetentionPurger を実装するクラス。
 *
 * @return list<class-string<BillingRetentionPurger>>
 */
function billingRetentionPurgerClassesOnDisk(): array
{
    $classes = [];
    $base = app_path('Services/Billing/Retention');
    /** @var SplFileInfo $file */
    foreach (new DirectoryIterator($base) as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $class = 'App\\Services\\Billing\\Retention\\'.$file->getBasename('.php');
        if (! class_exists($class)) {
            continue;
        }
        $reflection = new ReflectionClass($class);
        if ($reflection->isAbstract() || ! $reflection->implementsInterface(BillingRetentionPurger::class)) {
            continue;
        }
        $classes[] = $class;
    }

    sort($classes);

    return $classes;
}

test('検査 1: 課金モデルの母集団が target / exclusion にちょうど 1 回分類されている', function (): void {
    $result = billingRetentionClassify(billingRetentionPopulation(), billingRetentionDeclarations());

    expect($result['unclassified'])->toBe([],
        '保持期間の分類が無い課金モデルを検出しました。BillingRetentionTarget (消す) か '
        .'BillingRetentionExclusion (消さない) のどちらかへ根拠付きで登録してください。'
        .PHP_EOL.implode(PHP_EOL, $result['unclassified']));

    expect($result['duplicated'])->toBe([], '同一モデルが 2 か所で分類されています: '
        .implode(', ', $result['duplicated']));

    expect($result['phantom'])->toBe([],
        '母集団に実在しないモデルが目録に登録されています (削除されたモデルの残置): '
        .implode(', ', $result['phantom']));
});

test('検査 2: 全 case の rationale が 30 文字以上である', function (): void {
    $short = [];
    foreach (BillingRetentionTarget::cases() as $case) {
        if (mb_strlen($case->rationale()) < 30) {
            $short[] = 'target:'.$case->value;
        }
    }
    foreach (BillingRetentionExclusion::cases() as $case) {
        if (mb_strlen($case->rationale()) < 30) {
            $short[] = 'exclusion:'.$case->value;
        }
    }

    expect($short)->toBe([], '根拠が 30 文字未満の case: '.implode(', ', $short));
});

test('検査 3: 起算点 / 補助時計の修飾名が目録内のテーブルへ解決できる', function (): void {
    $known = billingRetentionKnownTables();
    $violations = [];

    foreach (BillingRetentionTarget::cases() as $case) {
        foreach ([$case->clockStartColumn(), $case->anomalyClockColumn()] as $column) {
            if ($column === null) {
                continue;
            }
            if (! str_contains($column, '.')) {
                continue; // 自テーブルの列 (実在照合は Feature lane)
            }
            [$table] = explode('.', $column, 2);
            if (! in_array($table, $known, true)) {
                $violations[] = $case->value.' => '.$column;
            }
        }
    }

    expect($violations)->toBe([],
        '修飾名の table 部が目録内の target テーブルに存在しません (親テーブルの綴り間違い?): '
        .implode(', ', $violations));

    // 正の自己検証: 修飾名の形が 1 件以上実在する (検査が形だけになっていない)
    $qualified = array_filter(
        BillingRetentionTarget::cases(),
        static fn (BillingRetentionTarget $case): bool => str_contains($case->clockStartColumn(), '.'),
    );
    expect($qualified)->not->toBeEmpty();
});

test('検査 4: target と purger 実装クラスが exact-fit である', function (): void {
    $registry = BillingRetentionPurgerRegistry::purgerClasses();
    $onDisk = billingRetentionPurgerClassesOnDisk();

    $sortedRegistry = $registry;
    sort($sortedRegistry);
    expect($sortedRegistry)->toBe($onDisk,
        'app/Services/Billing/Retention/ の purger 実装と registry の登録が一致しません '
        .'(登録漏れの purger は実行されず、期限超過が黙って残ります)。');

    $registeredTargets = array_map(
        static fn (string $class): string => (new $class)->target()->value,
        $registry,
    );
    sort($registeredTargets);

    $expected = array_map(
        static fn (BillingRetentionTarget $case): string => $case->value,
        array_filter(
            BillingRetentionTarget::cases(),
            static fn (BillingRetentionTarget $case): bool => ! $case->isPendingCarryForward(),
        ),
    );
    sort($expected);

    expect($registeredTargets)->toBe($expected,
        'purger を持つべき target と実装が一致しません (isPendingCarryForward() の target を除く)。');

    // 1 target につき purger は 1 本 (重複登録で二重実行しない)
    expect(count(array_unique($registeredTargets)))->toBe(count($registeredTargets));
});

test('検査 4b: 実行順が子 → 親である (SubscriptionItem → Subscription)', function (): void {
    $order = array_map(
        static fn (string $class): string => (new $class)->target()->value,
        BillingRetentionPurgerRegistry::purgerClasses(),
    );

    $child = array_search(BillingRetentionTarget::SubscriptionItem->value, $order, true);
    $parent = array_search(BillingRetentionTarget::Subscription->value, $order, true);

    expect($child)->toBeInt();
    expect($parent)->toBeInt();
    expect($child)->toBeLessThan($parent,
        '親 (subscriptions) を先に消すと FK cascade で子 (subscription_items) が'
        .'件数報告を経由せず消える。子 → 親の順を維持すること。');
});

test('検査 5: 空振り検知 (母集団 / 目録 / purger の件数を現在値ちょうどで pin)', function (): void {
    expect(billingRetentionPopulation())->toHaveCount(BILLING_RETENTION_POPULATION_COUNT);
    expect(BillingRetentionTarget::cases())->toHaveCount(7);
    expect(BillingRetentionExclusion::cases())->toHaveCount(7);
    expect(BillingRetentionPurgerRegistry::purgerClasses())->toHaveCount(BILLING_RETENTION_PURGER_COUNT);
    expect(billingRetentionPurgerClassesOnDisk())->toHaveCount(BILLING_RETENTION_PURGER_COUNT);
    expect(BILLING_RETENTION_EXTERNAL_POPULATION)->not->toBeEmpty();
});

test('負のコントロール: 未分類のモデルを母集団へ混ぜると検査 1 が点灯する', function (): void {
    $population = billingRetentionPopulation();
    $population[] = 'App\Models\Billing\DummyUnclassifiedModel';

    $result = billingRetentionClassify($population, billingRetentionDeclarations());

    expect($result['unclassified'])->toBe(['App\Models\Billing\DummyUnclassifiedModel']);
    expect($result['duplicated'])->toBe([]);
    expect($result['phantom'])->toBe([]);
});

test('負のコントロール: 二重分類と幽霊登録を検出する', function (): void {
    $population = ['App\Models\Billing\Alpha', 'App\Models\Billing\Beta'];
    $declared = [
        'App\Models\Billing\Alpha' => ['target:alpha', 'exclusion:alpha'],
        'App\Models\Billing\Gamma' => ['target:gamma'],
    ];

    $result = billingRetentionClassify($population, $declared);

    expect($result['unclassified'])->toBe(['App\Models\Billing\Beta']);
    expect($result['duplicated'])->toBe(['App\Models\Billing\Alpha (target:alpha / exclusion:alpha)']);
    expect($result['phantom'])->toBe(['App\Models\Billing\Gamma']);
});
