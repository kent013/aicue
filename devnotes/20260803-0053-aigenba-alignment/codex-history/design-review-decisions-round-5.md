# 対応マトリクス: design-review Round 5

## 施策 2 [Critical] IV-9 が binding key を検証しておらず `{user:slug}` を通過させる

- **判断: 対応する（指摘が正しい。「実質存在しない残存リスク」という私の評価が誤り）**
- **根拠**: 全面的に正しい。R4 の IV-9 は **action 引数のモデル型しか見ていなかった**ため、
  `User $user` を受ける **`{user:slug}`** を通過させる。
  これは **Laravel で一般的な記法**であり、私が「実質存在しない残存リスク」と書いたのは誤り。
  しかも本設計は `{organization:slug}` で**まったく同じ形の問題を既に踏んでいる**
  （数値 pattern を掛けると slug route が全滅する）。同じ罠を別 param で見逃していた。
- **対応内容**: IV-9 を **3 点検査**へ拡張した。
  - **(a) モデル型**: action 引数が `RouteBindingTypes` の map で宣言された対応モデル型であること
  - **(b) binding field**: `$route->bindingFieldFor($param)` が `null` か
    `ALLOWED_BINDING_FIELDS` に列挙された field であること
  - **(c) routeKeyName**: field 未指定なら当該モデルの `getRouteKeyName()` が
    PK（bigint / uuid 列）であること
  - `ALLOWED_BINDING_FIELDS` の**既定は空 = field 指定を一切許さない**。
    将来 `{manual:slug}` 等が必要になったら、その param を `BIGINT`/`UUID` から外すか
    ここへ明示登録する（= **型制約と両立するかを人間が判断する契機**になる）。
  - 負のコントロールも 3 分割した（typehint 無し / `{user:slug}` / 非 PK routeKeyName）。

## 施策 2 [Warning] `BIGINT`/`UUID` が param 名の list だけで「対応モデル型」の SoT が無い

- **判断: 対応する**
- **根拠**: 正当。IV-9 で「対応モデル型」を検証するには型の SoT が要る。
  StudlyCase からのクラス名推測は **namespace や例外モデル**（`manual` → `VideoManual`、
  `invitation` → `OrganizationInvitation`、`notification` → `DatabaseNotification`）で**必ず破綻する**。
  実際 AI-CUE の param 名は 11 個中 3 個が推測不能だった。
- **対応内容**: `BIGINT` / `UUID` を **`array<string, class-string<Model>>` の map** に変更し、
  **対応モデル型の SoT を inventory 自身に置いた**。IV-3 / IV-4 / IV-9 が共用する。
  `Route::pattern` 適用側も `array_keys()` 経由に修正した。

## 施策 2 [Warning] `EXTERNAL` の採取方法に廃止したはずの出自判定が再登場している

- **判断: 対応する（指摘が正しい。私の記述が後退していた）**
- **根拠**: 正当。R4 で「`routes/{web,api}.php` 由来でない route を洗い出す」と書いており、
  **R3 で廃止した出自判定問題をそのまま復活させていた**。
  `route:list --json` から route file 由来は通常判定できない。
- **対応内容**: **自動抽出を要件から外した**。
  gate（IV-1）が**未登録 param を route identity・action 付きで列挙**し、
  **人間が用途を確認して 5 分類のいずれかへ登録する**方式に確定した。

## 施策 2 [Warning] IV-2 は param の逆方向検査であり `EXTERNAL` の route identity 実在確認とは別

- **判断: 対応する**
- **対応内容**: 責務を分離した。
  - **IV-2**: param の逆方向検査（登録済みだが routes に現れない param の検出）
  - **IV-7**: `EXTERNAL` の **(a) route identity 実在 / (b) 登録 params と実 params の完全一致 /
    (c) `BIGINT`・`UUID` との同名衝突** の 3 点
  IV-2 の定義文に「route identity の実在確認は IV-7 の責務」と明記した。

## 施策 2 [Warning] 「4 分類」「アプリ route 限定」「vendor は IV-1 対象外」が残っている

- **判断: 対応する**
- **根拠**: 正当。R4 で修正したつもりが、**docblock・実装スケッチ・リスク表の 3 箇所が
  取り残されていた**（同一文言が複数箇所にあり、置換が 1 箇所にしか当たっていなかった）。
- **対応内容**: grep で残存を洗い出し、**5 分類 / 全 route 走査**へ統一した。
  リスク表の「アプリ route に限定」「vendor route は IV-1 の対象外」も書き換えた。

## 施策 2 [Suggestion] unnamed route の identity は HTTP method のソートと暗黙 `HEAD` の扱いまで固定する

- **判断: 対応する**
- **対応内容**: route identity の規約を明記した。
  - **route name を第一**とする（URI は prefix 設定で動くため不安定）
  - name 無し route は **`method:uri`** signature。
    HTTP method は**昇順ソート**し、**暗黙の `HEAD` は除外**する
    （`GET` 登録時に自動付与されるため identity が揺れる）

## 施策 1 / 3〜14

- **判断: 対応不要**（すべて APPROVE）
