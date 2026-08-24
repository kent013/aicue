# 詳細設計レビュー Round 4

Round 3 の Warning 2 件 (どちらも M2) を対応した (`codex-history/design-review-decisions-round-3.md`)。

1. **V21 (固定値に CR) を追加**し、V20 のラベルを「固定値に LF が含まれる」へ明確化した。
   `ENV_EXAMPLE_LEDGER_COUNTEREXAMPLE_IDS` を V1〜V21 へ更新し、対応表・テスト計画・
   テストファースト手順の段 2 / 段 3 も V1〜V21 に揃えた。
2. PHPStan 適合チェックの「規則 5 が種別との整合を見る」を **「規則 6」** へ訂正した。

該当箇所の修正後の本文を再掲する。他の節は Round 3 から変更していない。

---

## M2 の負のコントロールの対応表 (docblock)

 * **9 規則**である (各規則の判定分岐を負のコントロール V1〜V20 が対応表で押さえる):
 *
 * | # | 規則 | 塞ぐ穴 | 正典 |
 * |---|---|---|---|
 * | 1 | 申告 map 自身が健全 (キー集合が既知の 2 種別と完全一致 / 申告値が 1 以上 / 分類名が空白のみでない) | 申告を空にして件数の照合を無効化する迂回 | i9 |
 * | 2 | entry が 1 件以上ある | 台帳を空にすると全検査が緑になる無言の失効 | i8 (1) |
 * | 3 | キーが `/^[A-Z][A-Z0-9_]*$/` に一致する | 検査対象にならない綴りの登録 | i8 (2) |
 * | 4 | キーが台帳全体で一意 (種別をまたいでも) | 台帳内の重複 / 値の固定と必須キーの二重登録 | i8 (3) |
 * | 5 | 種別が既知の 2 つのいずれかである | 綴り違いの種別が数え上げから漏れる | i8 (4) |
 * | 6 | `value_pin` は非空の固定値を持ち改行も禁止文字も含まない。`required_key` は値を持たない | 種別と値の取り違え | i8 (4) |
 * | 7 | 分類が申告 map に在る名前である | 未申告の分類の混入 (件数の照合をすり抜ける) | i7 / i9 |
 * | 8 | 由来が trim 後に非空である | 由来不明の entry の堆積 | i7 |
 * | 9 | 種別ごとの実件数が申告と一致し、分類ごとの実 map が申告 map と完全一致し、分類 map の合計が種別の申告と一致する | 静かな削除 / 分類の増減・改名 / 申告の片側だけの修正 | i9 |
 *
 * ★申告 map の**宣言順は仕様にしない** (規則 1 は `ksort()` 済みのキー集合で比べる)。
 * ★**保証しないもの**: 由来の**長さと内容**は見ない (trim 後に非空であることだけを見る)。
 *   台帳の内容が見本と一致するかは本関数の担当ではない (検査 a / b が見る)。
 *
 * @param  list<array{key: string, kind: string, classification: string, origin: string, value: string|null}>  $entries
 * @param  array{kinds: array<string, int>, classifications: array<string, array<string, int>>}  $declared
 * @return list<string>

## M2 の V データセット (抜粋)

 * 誠実性の検査の負のコントロール (V1〜V10 / V12〜V20) と正のコントロール (V11)。
 *
 * 合成した台帳を `envExampleLedgerViolations()` へ直接食わせる (現物の台帳を壊さずに
 * 「壊れたら赤くなる」ことを示す)。期待値は**違反メッセージの部分一致**で持つ
 * (件数だけを見ると、別の規則が偶然発火しても緑になるため)。
 *
 * ★**各規則の判定分岐を対応表で固定する** (規則 1 本につき 1 ケースではなく、
 *   分岐ごとにケースを持つ) —
 *   規則 1 は V12 / V13 / V14 / V15 / V16、規則 2 は V1、規則 3 は V2、規則 4 は V3、
 *   規則 5 は V17、規則 6 は V4 (null) / V19 (空文字) / V18 (禁止文字) / V20 (改行) /
 *   V5 (値を持てない種別)、規則 7 は V7、規則 8 は V6、規則 9 は V8 / V9 / V10 が押さえる。
 * ★ラベルの先頭の `V<n>` は**恒久の識別子**である (床の検査が集合として突き合わせる)。
 *
 * @return array<string, array{0: list<array{key: string, kind: string, classification: string, origin: string, value: string|null}>, 1: array{kinds: array<string, int>, classifications: array<string, array<string, int>>}, 2: string|null}>
 */
function envExampleLedgerCounterexamples(): array
{
    // 最小の健全な台帳 (V11 の正のコントロールと、各負例の素材)
    $soundEntries = [
        ['key' => 'A_PIN', 'kind' => ENV_EXAMPLE_KIND_VALUE_PIN, 'classification' => 'pins', 'origin' => '由来', 'value' => 'true'],
        ['key' => 'B_REQUIRED', 'kind' => ENV_EXAMPLE_KIND_REQUIRED_KEY, 'classification' => 'keys', 'origin' => '由来', 'value' => null],
    ];
    $soundDeclared = [
        'kinds' => [ENV_EXAMPLE_KIND_VALUE_PIN => 1, ENV_EXAMPLE_KIND_REQUIRED_KEY => 1],
        'classifications' => [
            ENV_EXAMPLE_KIND_VALUE_PIN => ['pins' => 1],
            ENV_EXAMPLE_KIND_REQUIRED_KEY => ['keys' => 1],
        ],
    ];

    return [
        'V1 空の台帳' => [[], ['kinds' => [ENV_EXAMPLE_KIND_VALUE_PIN => 0, ENV_EXAMPLE_KIND_REQUIRED_KEY => 0], 'classifications' => [ENV_EXAMPLE_KIND_VALUE_PIN => [], ENV_EXAMPLE_KIND_REQUIRED_KEY => []]], '台帳に entry が 1 件も無い'],
        'V2 代入行として成立しない綴りのキー' => [/* A_PIN を 'a_pin' に差し替えた entries */, $soundDeclared, 'キーの綴りが env の代入行として成立しない'],
        'V3 種別をまたいだ二重登録' => [/* 同じキーを両種別に持つ entries + 申告を合わせた declared */, /* … */, '台帳に 2 回現れる'],
        'V4 値の固定に固定値が無い' => [/* value を null にした entries */, $soundDeclared, '値の固定なのに固定値が無い'],
        'V5 必須キーに固定値がある' => [/* B_REQUIRED に value を入れた entries */, $soundDeclared, '値を持てない種別'],
        'V6 由来が空白のみ' => [/* origin を "  " にした entries */, $soundDeclared, '由来 (origin) が空である'],
        'V7 未申告の分類' => [/* classification を 'unknown' にした entries */, $soundDeclared, 'の申告に無い'],
        'V8 種別の申告件数が実件数と違う' => [$soundEntries, /* value_pin を 2 に増やした declared */, 'の申告件数'],
        'V9 分類の申告 map が実測と違う' => [$soundEntries, /* classifications を別名にした declared */, '分類ごとの件数が申告と一致しない'],
        'V10 分類 map の合計が種別の申告と違う' => [$soundEntries, /* value_pin => 2 かつ pins => 1 の declared */, '合計'],
        'V11 健全な台帳 (正のコントロール)' => [$soundEntries, $soundDeclared, null],
        // ---- 規則 1 (申告 map 自身の健全性) と規則 5 / 6 の残りの分岐 ----
        'V12 種別の申告のキー集合が既知の種別と違う' => [$soundEntries, /* kinds に 3 つ目の種別を足した declared */, '種別の申告のキー集合'],
        'V13 分類の申告のキー集合が既知の種別と違う' => [$soundEntries, /* classifications から 1 種別を落とした declared */, '分類の申告のキー集合'],
        'V14 種別の申告件数が 0' => [/* required_key 1 件だけの entries */, /* value_pin => 0 の declared */, '種別 value_pin の申告件数が 1 未満'],
        'V15 分類名が空白のみ' => [$soundEntries, /* classifications に \'  \' => 1 を足した declared */, '空白のみの分類名'],
        'V16 分類の申告件数が 0' => [$soundEntries, /* pins => 0 の declared */, '申告件数が 1 未満'],
        'V17 未知の種別' => [/* kind を \'unknown_kind\' にした entries */, $soundDeclared, '未知の種別'],
        'V18 固定値に禁止文字が含まれる' => [/* value を "true\\x01" にした entries */, $soundDeclared, '固定値に改行または禁止文字'],
        'V19 固定値が空文字' => [/* value を '' にした entries */, $soundDeclared, '値の固定なのに固定値が無い'],
        'V20 固定値に改行が含まれる' => [/* value を "true\\ntrue" にした entries */, $soundDeclared, '固定値に改行または禁止文字'],
    ];
}

/** 誠実性の検査の負/正のコントロールの識別子 (床の検査の期待値)。 */

const ENV_EXAMPLE_LEDGER_COUNTEREXAMPLE_IDS = [
    'V1', 'V2', 'V3', 'V4', 'V5', 'V6', 'V7', 'V8', 'V9', 'V10',
    'V11', 'V12', 'V13', 'V14', 'V15', 'V16', 'V17', 'V18', 'V19', 'V20',
];

## M2 の PHPStan 適合チェックとテスト計画


## テストファースト手順

| 段 | 変更 | 期待される赤 |
|---|---|---|
| 1 | 反証 R17〜R29 の 13 件を追加 (駆動元を関数へ切り出す) | **R17〜R24 の 8 ケースが赤** / R25〜R29 の 5 件は正例なので緑 |
| 2 | 床の検査を追加 (`ENV_EXAMPLE_COUNTEREXAMPLE_IDS` を R1〜R29 / `ENV_EXAMPLE_LEDGER_COUNTEREXAMPLE_IDS` を V1〜V20 で宣言) | 段 1 / 段 3 を入れる前なら床も赤 |
| 3 | 台帳の誠実性を新形式へ / 負のコントロール V1〜V20 を追加 | `envExampleLedgerViolations()` 不在で赤 |
| 4 | 前提の固定 (M3) を追加 | `ENV_EXAMPLE_PATH` 不在で赤 (実装後に緑) |
| 5 | M1 の解析器・M2 の台帳・`APP_ENV` の移送を実装 | 全緑へ |
| 6 | 検出力の裏取り (一時的に壊して赤を確認 → 戻す) | `\x09` を外す → R20 赤 / UTF-8 判定を真固定 → R24 赤 / 件数の申告を 1 増やす → 誠実性が赤 / `ENV_EXAMPLE_PATH` を `.env.testing` へ → M3 が赤 |
| 7 | M5 (乖離台帳) を同じコミットで更新 | 突合 gate の F10 / F11 が緑へ |
| 8 | `composer test` / `composer phpstan` / `vendor/bin/pint --test` / `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` / `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages` | 全 green |

段 6 の各裏取りは **`composer test -- --filter='<テスト名>'` で 1 本ずつ実行**し、

残る Critical / Warning が無ければ全体判定 APPROVED を返してほしい。
