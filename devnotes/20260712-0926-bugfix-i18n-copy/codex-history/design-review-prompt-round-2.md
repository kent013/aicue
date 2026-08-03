# design-review Round 2

Round 1 の全指摘に対応しました。対応マトリクスと修正差分を提示します。再レビューし、全体判定を出してください。

## 対応マトリクス

- [Critical] 施策 3 の g-recaptcha-response 責務境界: **対応**。ラベル対応表直下に責務境界の段落を追加した (下記差分 1)。`required` は `StoreInquiryRequest::messages()` の個別文言が正で本施策では変更しない。attributes の `'g-recaptcha-response' => 'reCAPTCHA'` は個別 messages がカバーしない残り rule (`string` / `Recaptcha` カスタムルール) の fallback。
- [Warning] 施策 2 の export 形式: **対応**。正規表現を `^(?:export\s+)?([A-Z0-9_]+)=(.*)$` に拡張 (非キャプチャのためグループ位置不変、`[$_, $key, $value] = $m` は従来通り成立)。
- [Warning] 施策 3 の多義語: **対応**。局所上書きの具体例 1 件を実装対象に追加 (下記差分 2)。`UpdateOrganizationRequest::attributes(): array` が `['name' => '組織名']` を返し、UI ラベル (Organizations/Settings.svelte「組織名」) と揃える。inline validate 側は `validate($rules, $messages, $attributes)` 第 3 引数で対応する規約も明記。施策一覧・変更箇所・波及変更 (既存の組織更新テストに文言一致 assert が無いことを実装時に確認) を更新済み。
- [Warning] 施策 4 の Validator alias/FQCN: **対応**。検出仕様を「同ファイル use 文 (alias 含む) の最小パース map で `X::make` の X を解決し、`Illuminate\Support\Facades\Validator` (alias 経由含む) と FQCN 直書き (末尾セグメント Validator) の両方を検出」に拡張 (下記差分 3)。
- [Suggestion] inventory キー形式: **採用**。`"{相対パス}@{行番号}#{validate|validateWithBag|make}"` に固定。行番号ずれで stale になった entry は fail し再確認を強制 (fail-closed 側)。
- [Suggestion] ラベル表の出典列: **見送り (代替あり)**。出典は表直前の棚卸し節に全列挙済みで、施策 4 のテストが違反メッセージでクラス+キーを示すため、静的な出典列は冗長と判断。
- [Suggestion] 施策 5 の厳密一致方針: **採用**。テストコード例に「意図的に厳密一致 (文言変更を明示的にレビューさせる)」コメントを追加。

## 差分 1: 施策 3 ラベル対応表直下 (追加)

> **`g-recaptcha-response` の責務境界（明確化）**: `required` 違反の文言は `StoreInquiryRequest::messages()` の個別定義（「reCAPTCHAの確認に失敗しました。ページを再読み込みのうえ、もう一度お試しください。」）が**正**であり、本施策では変更しない。attributes の `'g-recaptcha-response' => 'reCAPTCHA'` は、個別 messages がカバーしない残り rule（`string` / `Recaptcha` カスタムルール）で `:attribute` が生キーのまま露出しないための **fallback** である。

## 差分 2: 施策 3 局所上書き例 (追加実装)

```php
// app/Http/Requests/Organizations/UpdateOrganizationRequest.php に追加
/**
 * @return array<string, string>
 */
public function attributes(): array
{
    // UI ラベル (Organizations/Settings.svelte「組織名」) と揃える。
    // グローバル attributes の 'name' => '名前' より優先される局所上書き。
    return ['name' => '組織名'];
}
```

## 差分 3: 施策 4 検査 2 の検出仕様 (置換後)

```
//   (a) T_OBJECT_OPERATOR + 'validate' / 'validateWithBag'
//   (b) X + T_DOUBLE_COLON + 'make' で、X の解決結果が Validator 系のもの。
//       X の解決は「同ファイルの use 文 (alias 含む) を最小パースした map」で行い、
//       - use Illuminate\Support\Facades\Validator (as Alias) → Alias::make / Validator::make
//       - FQCN 直書き (\Illuminate\Support\Facades\Validator::make 等、末尾セグメント Validator)
//       のいずれも検出する (alias 越しの取りこぼしを防ぐ)。
// 呼び出しごとにルール配列引数 (validate 系は第 1、make は第 2) を追跡し、
// '[' ... ']' の深さ 1 にある T_CONSTANT_ENCAPSED_STRING キー ('key' =>) を抽出する。
// 引数が配列リテラルでない場合は "{相対パス}@{行番号}#{validate|validateWithBag|make}" を
// violation とし、UNPARSEABLE_CALL_INVENTORY に同一キーの登録が無ければ fail
// (inventory キー形式はこの 3 要素で固定する)。
```
