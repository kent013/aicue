# 対応マトリクス: design-review Round 1

## [Critical] 施策 5: 初期目録の分類が実コードと一致しない (`TakeFileUpload` の三項演算子 / `CaptureFileFallback` の `{accept}` 短縮記法)

- 判断: **対応する (指摘は事実。実測で確認した)**
- 根拠: `svelte/compiler` の `parse(src, { modern: true })` で実測したところ、
  `accept={isStill ? "image/*" : "video/*"}` は `Attribute` + `ExpressionTag`、
  `{accept}` 短縮記法も `Attribute` + `ExpressionTag` である。
  当初案の `client-literal` は成立せず、目録は緑にできなかった。
  原因は「機械が実測できる構文」と「人が宣言する供給元」を 1 つの区分に混ぜたこと。
- 対応内容: 施策 5 を**2 軸**へ書き直した。
  - 実測構文 `syntax`: `static-text` / `expression` (**走査器が AST から実測**)
  - 供給元の宣言 `supply`: `server-prop` / `client-owned` (**人が理由付きで宣言。
    gate は由来を証明しない**)
  初期目録は 4 件すべて `expression` で、SOP 2 件が `server-prop`、
  撮影 2 件が `client-owned`。`{accept}` 短縮記法と三項演算子を
  `expression` として受理する正例を自己検査 (11 / 12) に置いた。
  `static-text` が現在 0 件であることと、それでも区分値が必要な理由も明記した。

## [Critical] 施策 5: `parse-failed` を `FileInputClassification` に入れる型設計は成立しない (序数を決められない)

- 判断: **対応する (提案どおり分離)**
- 根拠: parse に失敗したファイルでは input を列挙できず、動的 `type` では
  「file input の序数」が定義できない。同じ配列に入れると型と意味の両方が崩れる。
- 対応内容: 走査結果を
  `fileInputs: FileInputRecord[]` (実測できたものだけ。`occurrence` を持つ) と
  `diagnostics: ScanDiagnostic[]` (`file` / `reason` / AST 位置 or null / detail) に
  **分離**した。`occurrence` の定義を
  「静的に `file` と確定し `accept` が実測できた file input の、ファイル内の 1 始まりの序数」へ
  限定し、`parse-failed` はファイル単位の診断 (`at` は null) とした。
  自己検査 8 で「`parse-failed` が `occurrence` を持たないこと自体」も assert する。

## [Warning] 施策 5: gate 自身の判定分岐に対する負例が無い

- 判断: **対応する (提案どおり)**
- 根拠: 走査器の検出力テストだけでは、実リポジトリが偶然適合しているせいで
  比較分岐が壊れていても緑になる (共通規約 (c)(d))。
- 対応内容: 判定を純関数 `evaluateFileInputInventory(scan, inventory, countPin)` へ分離し、
  合成 `FileInputScanResult` + 合成目録による自己検査 (B) 9 ケースを追加した
  (未登録 / 残置 / `syntax` 不一致 / 重複キー / `rationale` 不足 /
  `occurrence` 不正 / 件数 pin ずれ / native input 空 / file input 空 / 適合の正例)。

## [Warning] 施策 5: `rationale` の長さ検査が `client-literal` だけでは `dynamic` を空理由で通せる

- 判断: **対応する**
- 対応内容: `rationale` は**全エントリ 30 文字以上**を必須にした。併せて
  `file` + `occurrence` の一意性、`occurrence` が正の整数であること、
  `FILE_INPUT_COUNT` が**実測件数・目録配列長・一意キー数の 3 つと一致**することを
  判定関数の検査項目に加えた。

## [Warning] 施策 2: `StoreVideoManualRequest` 側のラベル結線を独立に固定できていない

- 判断: **対応する (提案どおり)**
- 根拠: 既存の 422 文言テストは後付けアップロード経路
  (`StoreSourceDocumentRequest`) しか通っておらず、施策 1 で片方の置換を忘れても緑になりうる。
- 対応内容: 施策 2 のテスト計画へ追加。`POST projects.manuals.store` に**有効な `title`** と
  非対応形式のファイルを送って `document.mimes` だけを発火させ、
  フラグ false は jpeg / フラグ true は heic で 422 文言を**完全一致**で検証する。
  期待文はリテラルではなく `AcceptedSourceDocumentTypes::formatsLabel()` から組み立てる
  (結線の確認が目的。文面そのものの pin は施策 1 の Unit テストが持つ)。

## [Warning] 施策 2: props 同値の比較対象が曖昧 (`sourceDocumentFormatsLabel` は詳細画面に無い)

- 判断: **対応する**
- 対応内容: 同値比較の対象は**両面に存在する 2 件だけ**であることをテスト計画に明記し、
  テスト名・コメントにも書くこととした。

## [Warning] 施策 3: 親子構造の検証が無いので wrapper 追加を検出できない

- 判断: **対応する (提案 1 つ目を採用)**
- 根拠: 順序だけでは `gap` の適用単位が変わる後退を見逃す。
  wrapper を許容して spacing を明示する案 (提案 3 つ目) は、現状の見た目を
  変えない目的に対して余計な自由度を増やすので採らない。
- 対応内容: `SourceDocumentUpload.test.ts` で両 notice の `parentElement` が
  `source-document-upload` の `form` であることを検証する。作成画面側 (施策 4) でも
  作成 `form` 直下かつ file input より前であることを検証する。
  文言の全文比較は**空白を正規化**してから行う (Svelte ソースの改行・インデント差を吸収。
  正規化 helper は 1 か所に置いて両テストで共有)。

## [Suggestion] 施策 1: 前提 pin は集合の差分ではなく順序込みの完全一致で

- 判断: **対応する**
- 根拠: `acceptAttribute()` は `extensions()` の順序に依存して文字列を組むため、
  集合比較では並び順の変更を見逃す。
- 対応内容: 前提 pin を両フラグの `extensions()` の**順序込み完全一致**に変更し、
  既存の完全一致テストがこの pin の土台であることを明記した。

## [Suggestion] 施策 4: 保証の分担の記述が不正確 (`pnpm typecheck` は props 名の一致を保証しない) / help は全文一致で固定

- 判断: **対応する**
- 対応内容: 保証の分担表 (Feature テスト = 名前と値 / component・page テスト = 使い方 /
  typecheck = Svelte 内とテスト呼び出し側の型整合性) を施策 4 に追加し、
  「PHP 側の props 名と Svelte の Props を機械で突き合わせる仕組みは無い」ことも明記した。
  help は空白正規化後の**全文一致**を 1 ケース置く。

## [Suggestion] 実装モードの「新規 3 ファイル」が一覧と一致しない

- 判断: **対応する**
- 対応内容: 新規は**5 ファイル** (共有 Svelte 1 / テスト支援 2 / architecture テスト 2) へ訂正
  (施策 3 の component テスト 1 件を加えると新規テストは 3 件) と書き直した。

## [Suggestion] 施策 5 を落とす判断について

- 判断: 受領 (設計に既に明記済みの方針と一致)
- 対応内容: 施策 5 のリスク節に「規約を満たす形に収まらなければこの施策だけを落とす。
  安直な文字列 grep 版に劣化させない」を残した (指摘と同じ結論)。
