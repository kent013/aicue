# 対応マトリクス: impl-review Round 4

Codex 判定 **CHANGES_REQUESTED** (Critical 1)。Round 3 の 2 件 (case 正規化 / mixed group use) は
「指摘なし」で解消確認済み。

## [Critical] `T_NAME_RELATIVE` (`namespace\BillingRetention::years()`) を検出できない

- 判断: **対応する**
- 根拠: `namespace\X::m()` は同一 namespace の X を指す**静的に解決できる直接呼び出し**であり、
  「動的呼び出しは保証外」には該当しない。SSOT クラスと同じ `App\Support\Legal` に置かれた
  新規クラスがこの形で書けば、exact-fit の目録を無言で素通りできてしまう。
- 対応内容:
  1. **呼び出し側**のクラス名 token 集合を定数
     `BILLING_RETENTION_CALL_NAME_TOKENS` として切り出し、`T_NAME_RELATIVE` を追加した。
  2. **import parser (alias 収集) 側の token 集合とは分けた** (Codex の助言どおり)。
     `namespace\...` は use 文には書けないため、alias 収集側に入れると保証範囲がぼやける。
  3. **検査 11** を新設: `namespace\BillingRetention::years()` /
     `namespace\billingretention::THRESHOLD()` を 2 件として検出すること、
     `namespace\LegalConsent::version()` を巻き込まないこと、
     定数に `T_NAME_RELATIVE` が実在すること (空振り検知) を固定した。
- **mutation 実測**: `app/Support/Legal/RelativeProbe.php` に
  `namespace\billingretention::YEARS();` を書くと **検査 6 が赤**。probe は削除済み。
