# 赤→緑の証跡 (T256 / gate-env-example-sync の正典 t2 追従)

詳細設計「テストファースト手順 (赤の順序)」の段 1〜6 を実装セッションでそのまま実行した記録。
段ごとに `composer test -- --filter='<テスト名>'` を単独で走らせている
(全体実行のログでは、他の失敗と混同したのか本当に狙った表明が赤いのかが区別できない)。

対象: `tests/Architecture/EnvExampleInvariantTest.php`
実装ブランチ: `todo/T256` / worktree `.claude/worktrees/tasks/T256`

## 段 1: 反証 R17〜R29 を先に足して赤を確認する

```
$ composer test -- --filter='反証: 解析器は合成した本文を仕様どおりに分解する'
tests=29 passed=22 failed=7
```

赤になったのは **R17 / R19 / R20 / R21 / R22 / R23 / R24 の 7 件**。

**設計との差 (1 件)**: 詳細設計は「R17〜R24 の 8 ケースが赤」と予測していたが、
**R18 (`A\x01=1` = キーの側の SOH) は t1 の解析器でも既に形式違反**だった
(受理正規表現 `^([A-Z][A-Z0-9_]*)=(.*)$` がキーの綴りで先に落とすため)。
R18 は「制御文字の検査を入れても引き続き形式違反であること」を固定する回帰ケースとして残す
(削らない。番号も詰めない)。R25〜R29 の 5 件は設計どおり正例で、t1 の解析器でも緑だった
(= 厳しくした側が正当な書き方を巻き込んでいないことの裏取りになる)。

## 段 2〜4: 床の検査 / 台帳の誠実性の新形式 / 負のコントロール V1〜V21 / 前提の固定を足して赤を確認する

```
$ composer test -- --filter='EnvExampleInvariantTest'
tests=0 passed=0  → "No tests found." / Pest\Exceptions\DatasetMissing
```

`envExampleLedgerCounterexamples()` が未実装の `ENV_EXAMPLE_KIND_VALUE_PIN` /
`envExampleLedgerViolations()` を参照するため、**ファイルの読み込み段で落ちる** = 赤。

## 段 5: M1 の解析器・M2 の台帳・`APP_ENV` の移送・M4 の docblock を実装して緑へ

```
$ composer test -- --filter='EnvExampleInvariantTest'
tests=58 passed=58 assertions=88
```

## 段 6: 検出力の裏取り (一時的に壊して赤を確認 → 戻す)

いずれも確認後に**元へ戻して**ある (最終の実装は段 5 の緑と同一)。

### 裏取り 1 — 禁止文字から TAB (`\x09`) を外す → R20 が赤

`ENV_EXAMPLE_FORBIDDEN_CHARS` を `/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]|\xC2[\x80-\x9F]/` に落とす。

```
$ composer test -- --filter='R20'
tests=1 failed=1
R20 値の中の TAB は形式違反 (TAB も C0 制御文字である)
  Failed asserting that two arrays are identical.
  - 'values' => []                        + 'values' => ['A' => "1\t2"]
  - 'malformedLineNumbers' => [1]         + 'malformedLineNumbers' => []
```

### 裏取り 2 — UTF-8 妥当性の判定を常に真へ倒す → R24 が赤

`envExampleIsValidUtf8()` の `if ($matched === false)` を `if (false)` に置換。

```
$ composer test -- --filter='R24'
tests=1 failed=1
R24 不正 UTF-8 を含む行は形式違反 (fail-closed)
  - 'values' => []                        + 'values' => ['A' => "\xC3"]
  - 'malformedLineNumbers' => [1]         + 'malformedLineNumbers' => []
```

### 裏取り 3 — 分類の申告件数を 1 増やす (`integration` 14 → 15) → 台帳の誠実性が赤

```
$ composer test -- --filter='台帳の誠実性'
tests=1 failed=1
  0 => '種別 required_key の分類ごとの件数が申告と一致しない
        (申告 {"integration":15,...} / 実測 {"integration":14,...})'
  1 => '種別 required_key の分類ごとの件数の合計 36 が種別の申告 35 と一致しない'
```

規則 9 の「分類ごとの map」と「分類 map の合計」の 2 分岐が同時に発火することも確認できた。

### 裏取り 4 — `ENV_EXAMPLE_PATH` を `.env.testing` へ差し替える → 前提の固定が赤

```
$ composer test -- --filter='前提: テスト実行時に読み込まれている env ファイルが見本ではない'
tests=1 failed=1
  Expecting '/workspace/.claude/worktrees/…esting' not to be '/workspace/.claude/worktrees/…esting'.
```

= 「実行時に読まれている env が本 gate の対象ファイルだったら赤くなる」ことの裏取り。

### 裏取り 5 — `APP_ENV` を値の固定と必須キーへ**併存**させる → 誠実性の規則 4 が赤

移送前の姿 (`ENV_EXAMPLE_REQUIRED_KEYS_SETUP` に `APP_ENV` を残したまま値の固定へも足す) を
一時的に再現し、申告件数も併せて合わせた (`setup` 8→9 / `required_key` 35→36) 状態で実行する。

```
$ composer test -- --filter='台帳の誠実性'
tests=1 failed=1
  0 => 'APP_ENV が台帳に 2 回現れる (種別をまたいだ重複も禁止)'
```

= 「後方互換の並走 (両方に載せる形) は機械で禁じられている」ことの裏取り
(詳細設計「migration / 後方互換の扱い」の 3 点目)。

## 段 7: 乖離台帳を同じコミットで更新 → 突合 gate が緑

main の最新を起点に D 番号と件数 pin を**再確定**した (設計書の仮採番 D50 は T255 が使用済み)。

| 項目 | 設計書の仮値 | 実装での確定値 |
|---|---|---|
| 新規登録の番号 | D50 | **D51** |
| `docs/template-divergence.md` の宣言行 | 46 → 47 | **47 → 48** |
| `LedgerPins::DIVERGENCE_ENTRY_COUNT` | 46 → 47 | **47 → 48** |
| `LedgerPins::ADOPTION_DEBT_COUNT` | 148 → 147 | 148 → **147** (変わらず) |
| `adoption-debt.tsv` | 当該 1 行を削除 | 同左 (149 → 148 行 = header 込み) |

```
$ composer test -- --filter='TemplateDivergence'
tests=118 passed=118 assertions=672
```
