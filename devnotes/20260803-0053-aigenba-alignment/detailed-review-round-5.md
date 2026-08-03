## 全体判定

**CHANGES_REQUESTED**

2段検出の方向性は正しいですが、IV-9 が Laravel の binding key を検証しておらず、`User $user` を持つ `{user:slug}` のような一般的な衝突が残ります。また `EXTERNAL` の採取方法に、廃止したはずの出自判定が再登場しています。

## 施策別判定

### 施策1・3〜14

**すべて APPROVE**

施策6の media type 判定、施策8の Chromium/WebKit 両レーン契約も妥当です。

### 施策2: total inventory gate

**REQUEST_CHANGES**

- [Critical] IV-9 はモデル型だけを確認するため、`User $user` を受ける `{user:slug}` や、`getRouteKeyName()` が非数値キーのモデルを通過させます。これはLaravelで一般的な記法であり、「実質存在しない残存リスク」とは扱えません。  
  修正案: paramごとの対応モデルと許可binding fieldを明示してください。例えば `BIGINT` を `array<string, class-string<Model>>` または別の `MODEL_TYPES` mapにし、IV-9で次を検証します。
  - action引数が対応モデル型である
  - `$route->bindingFieldFor($param)` が `null` または許可された数値PK field
  - field未指定時はモデルの `getRouteKeyName()` が許可field
  - UUIDも同様にUUID columnとの一致を確認する
- [Warning] `BIGINT`/`UUID` がparam名のlistだけなので、「対応モデル型」のsource of truthが設計上存在しません。StudlyCaseからの推測は名前空間や例外モデルで破綻します。  
  修正案: `MODEL_TYPES` / `BINDING_FIELDS` を明示的なmapとしてinventoryに含め、IV-3・4・9で共用してください。
- [Warning] `EXTERNAL` の採取方法に「`routes/web.php`由来か判定する」が残っており、廃止した出自判定問題が再発しています。`route:list --json` からroute file由来は通常判定できません。  
  修正案: 全routeの未登録paramをgateで列挙し、route identity・action・用途を人間が確認して5分類へ登録する方式にしてください。外部routeの自動抽出は要件にしない方が一貫します。
- [Warning] IV-2はparamの逆方向検査しか定義されておらず、`EXTERNAL` のroute identity実在確認とは別の検査です。  
  修正案: IV-7内で「route identityの実在」「登録paramsと実route paramsの完全一致」「BIGINT/UUIDとの衝突」を検証すると明記してください。
- [Warning] 文書内にまだ「4分類」「アプリroute限定」「vendorはIV-1対象外」が残り、5分類・全route走査と矛盾しています。  
  修正案: docblock、実装スケッチ、リスク表を5分類・全route走査へ統一してください。
- [Suggestion] unnamed routeのidentityはHTTP methodをソートし、暗黙の`HEAD`をどう扱うかまで固定すると安定します。

IV-9を「モデル型＋binding field」の検証へ拡張し、残存する文書矛盾を除けば承認可能です。