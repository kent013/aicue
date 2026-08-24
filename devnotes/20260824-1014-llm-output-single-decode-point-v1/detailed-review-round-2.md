## 全体判定: CHANGES_REQUESTED

Round 1 の指摘はすべて実質的に解消されています。新たな Critical はありません。ただし、施策7に検出条件の不整合、施策8に実装時点では満たせない文書要件が残っています。

| 施策 | 判定 |
|---|---|
| 1. 失敗区分 | APPROVE |
| 2. 構造走査による復号 | APPROVE |
| 3. 復号契約テスト | APPROVE |
| 4. prompt 出力指示 | APPROVE |
| 5. canned 応答 | APPROVE |
| 6. 既存テスト更新 | APPROVE |
| 7. 単一性 gate | REQUEST_CHANGES |
| 8. 文書 | REQUEST_CHANGES |

## 施策 1〜6: APPROVE

Round 1 で指摘した以下は適切に解消されています。

- テストファーストの順序
- 6区分の統合層非漏洩テスト
- JSON whitespace の明文化
- 深さ、不正UTF-8、フェンス個数不一致の境界テスト
- 旧契約テスト更新の説明
- canned・prompt・DTOの契約同期

復号ロジックにも新たなロジックエラーは見当たりません。

## 施策 7: REQUEST_CHANGES

[Warning] group use の例が「完全修飾名で判定する」という方針と矛盾しています。

設計にある次の例は、global の `json_decode` ではありません。

```php
use function Foo\{json_decode as decodeJson};
```

これは `Foo\json_decode` の alias です。解決後の完全修飾名が `Foo\json_decode` なら、global の `json_decode` として違反にしてはいけません。末尾名だけで判定すると、共通規約 (a) と同じ種類の誤検出が関数側に再発します。

修正案:

- 違反対象は解決後の関数名が正確に `json_decode` であるものに限定する
- `use function json_decode as decodeJson` と `use function \json_decode as decodeJson` は違反
- `use function Foo\{json_decode as decodeJson}` は非違反の正例にする
- group use は「alias 解決を実装する対象」には含めるが、「global json_decode の回避例」からは外す

[Warning] 変更ファイル一覧と説明が8検査への更新に追従していません。

施策7冒頭はまだ次の状態です。

- `tests/Support/Llm/LlmResponseHandling.php` が変更ファイル一覧にない
- `LlmResponseDecodePointGateTest.php` の説明が「目録 + 6 検査」のまま

修正案: 新しい enum ファイルを変更一覧に加え、「目録 + 8検査」へ更新してください。実装時のファイル漏れを防ぐため、単なる表記修正以上に重要です。

[Warning] 公開面の fixture が本番と同じ判定経路を通ることを明記してください。

本番検査だけが `LlmJson::class` を直接 Reflection し、fixture テストが別ロジックでメソッド数を検査すると、負例が本番 gate の検出力を証明しません。

修正案: `class-string` を受け取って公開面を判定する共通の純関数または検査クラスを用意し、本番の `LlmJson` と負例 fixture の両方を同じ関数へ渡す設計にしてください。

## 施策 8: REQUEST_CHANGES

[Warning] 本変更のコミット内へ、そのコミット自身の SHA を記録することはできません。

ファイル内容に SHA を書くとコミットSHAが変わるため、同一コミットへの自己参照は成立しません。また、本番デプロイ日は実装・コミット時点では未確定です。このままでは施策8の完了条件を実装PR内で満たせません。

修正案は次のいずれかです。

- `docs/architecture.md` には「本変更の本番デプロイを境界とする」とだけ記載し、具体的な日時・リリースSHAはデプロイ記録やリリースノートを正本にする
- デプロイ後の別コミットで運用記録を追記することを明示する
- 事前に確定できるリリースタグや変更管理IDを境界識別子として使う

実装PR内に未確定値や placeholder を残す設計にはしないでください。

## 補足

公開面 pin、receiver の `$text` 一回使用、`LlmJson::decode()` への直結という3層は、Round 1 時点よりかなり強固になっています。上記は設計方向の変更ではなく、関数名解決の正確性、負例の同一経路性、実行可能な文書要件への修正です。