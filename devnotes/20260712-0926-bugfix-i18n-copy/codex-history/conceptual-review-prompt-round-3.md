# conceptual-review Round 3

Round 2 の残件 (inline validation 検査の fail-closed 化) に対応しました。再レビューし、全体判定を出してください。

## 対応マトリクス

- [Warning] inline validation 検出の見逃し: **対応**。第 2 検査を fail-closed の二段階に再設計した。
- [Warning] 期待効果の保証不能: **対応**。上記により保証可能になった旨を設計に明記。

## 修正後の施策 2-3 (全文)

3. 再発防止 (inline validate 面): 同テストに第 2 検査を追加する。**fail-closed (未検出・未解析を成功扱いしない)** を原則とし、二段階で検査する:
   1. **呼び出し検出**: 対象を Controllers/Actions に限定せず `app/` 配下の全 PHP ファイル (FormRequest 本体の `app/Http/Requests/**` は第 1 検査でカバー済みのため除外) を走査し、validation API 呼び出し (`->validate(` / `->validateWithBag(` / `Validator::make(`) を全件列挙する。
   2. **キー抽出 or inventory 強制**: 各呼び出しについてインラインのルール配列リテラルからキーを静的抽出する。抽出できた dotted/wildcard キー (数値 index は `*` 正規化) は attributes 登録を要求。**ルール配列が変数・メソッド分離等で静的抽出できない呼び出しは、理由付き inventory (`array<string, string>`: `ファイルパス@識別子 => 理由`) に登録されていない限り fail** させる。

   これにより「解析できなかった呼び出しがテスト成功になる」穴を塞ぎ、期待効果 (フィールド追加時に attributes 未登録なら CI fail) を FormRequest / inline validate の両面で保証する。inline validate の FormRequest への寄せ替え (ロジック refactor) は本 fix のスコープ外とする。
