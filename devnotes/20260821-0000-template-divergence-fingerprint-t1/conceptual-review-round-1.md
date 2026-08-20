全体判定: **CHANGES_REQUESTED**

1. 使命との整合性

- [Suggestion] North Star への寄与は間接的ですが妥当です。PWA・LLM 防御・テナント境界を支える検査資産の静かな乖離を防ぐ、という位置付けを維持してください。撮影機能そのものの改善として過大に主張しない点も適切です。

2. 禁止事項違反

- [Warning] `scripts/update-template-fingerprints.php` は PHP ファイルです。`declare(strict_types=1)`、開始タグ付き出力記法・`echo` 不使用、既存の禁止文 gate 対応を実装要件として明記してください。標準出力は `fwrite(STDOUT, ...)` 等で扱う設計が必要です。
  - 修正提案: 実装方針に「生成器も strict types・Pint・禁止文 token gate の対象」と追記してください。

- [Suggestion] 新規・変更する Architecture/Unit テストを伴うため、「テストなしの実装完了」にはなりません。テスト先行で赤を確認する手順を実装計画に明文化するとよいです。

3. 実現可能性

- [Critical] 指紋台帳の生成入力が任意のローカル `--template-ledger` である一方、設計には「入力台帳が正典の template 台帳である」ことを検証する仕様がありません。`role: app` の出力を作れてしまうことだけでは、偽の template baseline を混入させる経路を防げません。これでは「テンプレートとの差分」を保証できません。
  - 修正提案: 生成器で入力の `role: template`、schema、hash 書式、entries の整合性を fail-closed で検証してください。さらに採用する template ledger の取得元・コミット・SHA-256 等を生成物または検査側で pin し、更新時に意図的な pin 更新を要求してください。

- [Warning] `generated_at_commit` を子リポジトリで実在検証できないという整理は妥当です。ただし、任意のコミット文字列をコピーするだけでは provenance が弱いです。
  - 修正提案: `template_source`（リポジトリ識別子、参照コミット、台帳ファイル hash）を台帳 schema に追加できない場合でも、少なくとも Architecture test 側の定数として正典台帳の fingerprint を固定してください。

4. 期待効果の妥当性

- [Warning] 「76 件の byte 一致資産が静かに分岐する経路が閉じる」は正確ではありません。導入後に監視されるのは母集合の全 275 件であり、76 件は導入時点で一致していた内訳です。また、指紋台帳・検査・債務一覧そのものを変更する PR は原理的に検査を弱め得ます。
  - 修正提案: 「通常の共有ファイル変更で未登録の乖離が CI で検出される。ただし検査・台帳自身の改変は PR レビューの信頼境界である」と表現を修正してください。

5. リスク

- [Critical] 採用時債務 178 件は安全な既知差分ではなく、「意図的逸脱と追従遅れが未分類」の集合です。exact-fit で pin しても、将来テンプレート側の安全修正を取り込まずに済ませる固定化リスクがあります。設計は「縮む方向」を述べていますが、誰が・いつ・どう分類するかの運用契機がありません。
  - 修正提案: 各債務に少なくとも再分類の責任主体・優先度または見直し契機を持たせるか、一覧全体に期限付きの棚卸しルールを置いてください。「件数を減らす」だけでなく、template 追従か業務上の逸脱登録かを選ぶ出口を定義すべきです。

- [Warning] `LedgerPins` へ既存の登録件数 pin を集約すると、形式検査が新しい Support 型へ依存します。純関数を保つこと自体は可能ですが、既存検査の責務境界が不透明になり得ます。
  - 修正提案: `LedgerPins` は不変の scalar 定数だけを提供し、パース・ファイル I/O・git 実行を持たない DTO／value object としてください。

6. スコープの適切さ

- [Warning] t1 到達が主目的なのに、t3 のスキル確認段と composer package name 修正を同一変更へ含めています。いずれも合理性はありますが、検査導入失敗時の原因切り分けを悪化させます。
  - 修正提案: 最低でも実装計画・コミットを「t1 の機械的突合」「識別子修正」「t3 確認段」に分離してください。特にスキル変更は t1 の受入条件ではないことを明確にしてください。

- [Suggestion] `SharedPathRules` を移植せず、公開済み指紋台帳のキーを母集合とする判断は、子アプリで template の現物を保持しない前提に合っており適切です。

7. 型安全性

- [Warning] 「DTO・純関数・薄い検査層」とあるだけでは PHPStan level 10 を通す設計として不足しています。JSON decode、`git ls-files` 出力、ハッシュ値、登録簿の複数対象パスはすべて `mixed`／外部入力境界です。
  - 修正提案: `TemplateFingerprintLedger::fromJson(string)`、`FingerprintEntry`、`DebtInventory`、`ComparisonResult` を不変 DTO とし、`mixed` を DTO 境界で完全検証してください。`json_decode(..., true, flags: JSON_THROW_ON_ERROR)` を使い、配列 shape、文字列キー、SHA-256 の 64 桁 hex、role、schema version、重複パスを明示的に検査してください。`array<string, string>` 等の具体的な PHPDoc/ネイティブ型を維持し、無効値を空配列へフォールバックしないでください。

- [Suggestion] 比較結果は `missingRegistrations`、`staleRegistrations`、`staleDebtPaths`、`overlapPaths` 等を型付きに分けると、集合差分を集めて判定に使わないという gate 規約違反を避けやすくなります。