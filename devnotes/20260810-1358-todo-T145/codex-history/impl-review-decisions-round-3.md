# 対応マトリクス: impl-review Round 3

Codex 判定 **CHANGES_REQUESTED** (Critical 1 / Warning 1)。両方とも指摘が正しい。

## [Critical] 大文字小文字の違いで caller inventory を迂回できる

- 判断: **対応する**
- 根拠: PHP のクラス名・import alias・メソッド名は **case-insensitive** である。
  `retention::years()` / `Retention::YEARS()` / `\App\Support\Legal\billingretention::threshold()`
  はいずれも有効な呼び出しなのに、case-sensitive 比較では 1 件も検出できない。
  exact-fit の目録が**有効な PHP 構文で素通りできる**のは、gate の主張と実態の食い違いである。
- 対応内容: 比較を 4 箇所すべて正規化した。
  1. alias 収集時の FQCN 比較 … `strtolower($fqcn) === strtolower(BILLING_RETENTION_FQCN)`
  2. alias の保持 … 小文字化して保持 (返り値の契約を docblock に明記)
  3. 呼び出し側の最終セグメント一致 … `strtolower()` 経由
  4. メソッド名 (`years` / `threshold`) … `strtolower()` 経由
  - **検査 10** を新設し、alias の大小文字違い / クラス名の大小文字違い / import 側の
    大小文字違い / 無関係な別名 (`OtherRetention`) を負のコントロールで固定した。
- **mutation 実測**: `app/Support/Legal/CaseProbe.php` を作り
  `use ... BillingRetention as Retention;` + `retention::YEARS();` と書くと **検査 6 が赤**
  (未登録の呼び出し元として検出)。probe は削除済み。

## [Warning] mixed group use で function/const entry が先にあると後続 entry を取りこぼす

- 判断: **対応する**
- 根拠: PHP の group use は entry ごとに種別を書ける。`{function helper, BillingRetention as R}`
  の形で **文全体の解析を打ち切っていた**ため、後続の正当な class alias が登録されなかった
  = alias 迂回が残っていた。
- 対応内容: entry ループで `T_FUNCTION` / `T_CONST` を検出したら**その entry だけ**読み飛ばし、
  後続 entry の解析を継続するようにした (group 内でのみ有効。`use function X as Y;` の
  文頭形は従来どおり文全体を除外)。
  検査 9 に前後両順の mixed group と `{function BillingRetention as R}` (= alias にしない) を追加。

## [Suggestion] 最終セグメント一致の過検出は妥当

- 判断: **対応不要** (肯定的評価)。ただし指摘どおり case-insensitive 化に伴って
  「最終セグメント一致も大文字小文字を区別しない」ことを docblock へ反映した。
