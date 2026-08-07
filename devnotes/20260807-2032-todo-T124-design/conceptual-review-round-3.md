全体判定: **CHANGES_REQUESTED**

enum の case 粒度に関する反論は受け入れます。既存の目録設計が「case = 免除理由の型」で統一されており、全体 cap と case 別 cap の両方を exact-fit で固定するなら、2 case / 3 routes は整合しています。Round 2 の Critical は撤回します。

### 1. 使命との整合性

[Suggestion] 整合しています。秘密の複製だけでなく、`force=true` による第二要素の破壊も同時に閉じることで、組織資産を保護する認証基盤として一貫した改善になっています。

### 2. 禁止事項違反

[Suggestion] 違反は見当たりません。既存DTO / JsonResource契約を維持し、Architecture・Feature・JSテストまで計画されています。

### 3. 実現可能性

[Warning] native `fetch` が確実に409契約を受け取る条件が設計に不足しています。

Laravelの `$request->expectsJson()` や `$request->ajax()` は、native `fetch` を使っただけでは成立しません。適切な `Accept` または `X-Requested-With` ヘッダーがなければ、`RequireRecentAuth` が302を返し、fetchがリダイレクトを追従してHTMLを取得する可能性があります。その場合、`status === 409 && code === recent_auth_required` は一度も成立しません。

修正提案: enrollment素材fetchが既に送信しているヘッダーを確認し、少なくとも `Accept: application/json` によってXHR相当の409契約へ入ることを設計に明記してください。JSテストだけでなくFeatureテストでも、その実際のヘッダー条件による409を固定してください。

[Suggestion] 並列fetchの結果を集約して一度だけ再認証へ接続する設計は妥当です。両方409、一方だけ409、通常の非409エラーの3系統を分けてテストすると、通常エラーをstep-upへ誤分類しません。

### 4. 期待効果の妥当性

[Warning] 「回帰の恒久化」の記述が、追加した「保証範囲」節より広いままです。

現在の記述は「アプリが2FA面を足しても、分類しない限りCIが赤」と断定していますが、`mfa.*` など別名なら沈黙すると後段で正しく説明されています。

修正提案: 次のように限定してください。

> Fortifyの既存命名規則またはroute名に`two-factor`を含むアプリ側routeが増えた場合、分類しない限りCIが赤になる。

### 5. リスク

[Warning] 「改善アイデア」冒頭の不変条件も、依然として機械保証より広い表現です。

> 2FAの秘密と第二要素の状態に触るrouteは……

という宣言は意味ベースの全routeを保証するように読めますが、実際のgateは名前ベースです。「保証範囲」節だけで訂正するより、最初の宣言自体を正確にすべきです。

修正提案:

> route名に`two-factor`を含むrouteは、recent-auth必須または理由付きexemptionのいずれかへ分類する。

秘密GET3本の名指し固定により、このTODOの中心的な安全性は別途守られます。

### 6. スコープの適切さ

[Suggestion] 適切です。`two-factor.enable` は今回に含め、vendor controller差し替えやnamed limiter化は分離する境界が妥当です。

passkey satisfierのallowlist追加も、今回新しく発生する到達不能を防ぐ必須の波及変更として収まっています。

### 7. 型安全性

[Suggestion] 2 case / 3 routes の設計で問題ありません。enumを免除理由の型として使い、routeとの対応、全体 cap、case別 capを別々に検査する方が既存パターンと整合します。

409判定についても、既存定数を共有して `status` と `code` の両方を見る方針は妥当です。レスポンスbodyが未知形状である可能性を考慮し、判定関数が `unknown` を受けて構造を絞り込む型ガードになれば、Svelte/TypeScript側でも安全に実装できます。

残る修正は実質2点です。

- native `fetch` が409 JSON契約へ入るためのリクエストヘッダーを固定する。
- 「改善アイデア」と「期待効果」の過大な保証表現を、`two-factor`名前セレクタの範囲へ合わせる。

これらを反映すれば、概念設計として **APPROVED** にできます。