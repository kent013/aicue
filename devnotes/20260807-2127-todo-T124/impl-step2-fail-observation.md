# Step A: 施策 2 の gate を配線前に走らせた実測 (テストファースト)

実行日時: 2026-08-07 21:3x JST / branch `todo/T124` (main 3f38e06 起点)

## コマンド

```bash
cd /workspace/.claude/worktrees/tasks/T124 && composer test -- tests/Architecture/TwoFactorStepUpInventoryTest.php
```

施策 1 (`FortifyServiceProvider::RECENT_AUTH_ROUTE_NAMES` への 3 本追加) は**まだ入れていない**状態。

## 結果

```
tests 8 / passed 6 / failed 2
```

### fail 1: 「母集団の各 route は recent-auth 系 middleware をちょうど 1 種類持つか exemption inventory に明示分類されている (未知は fail)」

```
two-factor.enable: recent-auth が無く exemption inventory にも未登録
two-factor.qr-code: recent-auth が無く exemption inventory にも未登録
two-factor.secret-key: recent-auth が無く exemption inventory にも未登録
```

**設計の期待どおり 3 本ちょうど**を列挙している。
`two-factor.confirm` / `two-factor.login` / `two-factor.login.store` は exemption 済みのため出ていない
(= inventory の書き間違いが無い)。

### fail 2: 「免除にできない route は必ず recent-auth 系 middleware をちょうど 1 種類持つ」

```
two-factor.qr-code: 秘密の開示 / 第二要素の差し替え経路なのに recent-auth 系 middleware が 1 種類ではありません
two-factor.secret-key: 同上
two-factor.enable: 同上
```

non-exemptible 名指し 6 本のうち、既に recent-auth 済みの 3 本
(`two-factor.recovery-codes` / `two-factor.disable` / `two-factor.regenerate-recovery-codes`) は
出ていない。

### 母集団 exact fit は green

`twoFactorStepUpPopulationSize() = 11` が実測と一致 (Fortify 9 本 + アプリ 2 本)。
セレクタの空振りは起きていない。

## 設計コード片の不具合を 1 件修正 (deviation)

設計書 L638-640 の

```php
foreach (TwoFactorStepUpExemption::cases() as $case) {
    expect($caps)->toHaveKey($case->value, "case {$case->value} が cap 表に未登録です");
}
```

は **Pest の `toHaveKey($key, $value)` の第 2 引数が「期待値」であってメッセージではない**ため、
`cap 表の値 (int 2)` と `文言 (string)` を比較して**常に fail** する
(初回実行で `Failed asserting that 2 matches expected 'case pre_auth_challenge_surface が cap 表に未登録です'` を実測)。

意図 (「case 追加時の cap 表への登録漏れ検出」) は変えずに、
他の検査と同じ violations 集約方式 (`array_key_exists`) へ書き換えた。
