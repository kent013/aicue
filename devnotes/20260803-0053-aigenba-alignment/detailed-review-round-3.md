## 全体判定

**CHANGES_REQUESTED**

Round 2 の主要な Critical は適切に解消されています。残る変更要求は、施策2の route 出自判定未確定、施策6のプローブに対する既存 middleware 干渉、施策7と8の保証状態の不整合です。

## 施策別判定

### 施策1: route binding 型制約

**APPROVE**

18桁制約をドメイン制約として明示し、Unit・Architecture・Feature の役割も整理されています。

- [Suggestion] IV-8 の完全一致 pin と regex 境界テストは重複気味ですが、安全性重視として許容できます。

### 施策2: total inventory gate

**REQUEST_CHANGES**

- [Warning] アプリ route の出自判定が「実装時に候補から選ぶ」のままで、詳細設計として未確定です。controller namespace 方式では closure route、Fortify等の vendor controller をアプリ側で登録する route を正しく分類できません。  
  修正案: route 登録時に action metadata などの明示的な origin marker を付ける方式へ確定するか、全 route を対象に `APP` / `EXTERNAL` を明示分類する inventory に変更してください。実装時判断として残さない方が安全です。
- [Warning] IV-8を追加した一方、負のコントロール計画が IV-1・IV-3までしか記載されていません。  
  修正案: vendor衝突で IV-7、`[0-9]+` への変更で IV-8が落ちる fixture テストも追加してください。
- [Suggestion] リスク表が途中の見出しで分断され、IV-2の行が表外に出ています。文書構造を修正してください。

### 施策3: 非適合セグメントテスト

**APPROVE**

custom binder の実効性を Feature テストへ移した判断は妥当です。

- [Suggestion] `{organization:未許可 field}` はテスト専用 route が必要になるため、production route inventoryへ混入しない登録方法を明記すると安全です。

### 施策4: no-store middleware

**APPROVE**

- [Suggestion] `$next` 後の応答は外側 middleware がまだ変更できるため、「最終応答」より「下流から返った応答」という表現が正確です。

### 施策5: no-store 契約テスト

**APPROVE**

指摘事項はありません。

### 施策6: bfcache 秘匿・再検証

**REQUEST_CHANGES**

`documentElement` を履歴エントリ単位のマーカーにする修正は妥当です。

- [Critical] `/session/status` が web group に入るため、既存の `RequireTwoFactorForEnforcedOrganizations` や他の認証後 middleware に遮断され、409/redirectを返す可能性が未検討です。これが起きると有効なセッションでも秘匿解除されず、reloadループになり得ます。  
  修正案: プローブを遮断対象外とする route-name exemptionを明示し、2FA強制中・recent-auth期限切れ・組織未選択状態でも必ず200 booleanを返すFeatureテストを追加してください。
- [Warning] `SessionStatusResource` にヘッダを付ける具体的な方法が未確定です。Controllerの戻り値をResourceに固定したままでは、通常のレスポンス操作と噛み合わない可能性があります。  
  修正案: 既存Resourceの作法に合わせて `withResponse()` で `no-store, private` を設定する、と設計に明記してください。
- [Warning] `fetch()` のHTTP成功だけでJSONを信用すると、HTML redirectや409 bodyを誤処理する可能性があります。  
  修正案: `response.ok`、`Content-Type`、JSON shapeが厳密に成立した場合のみ判定し、それ以外はプローブ失敗へ倒してください。
- [Suggestion] Vitestでは実際の描画露出は検証できないため、「旧DOMが可視」を「秘匿属性がない」に言い換えるとテスト責務が正確です。

### 施策7: ブラウザ方針

**REQUEST_CHANGES**

- [Warning] 同じPRで施策8がWebKitレーンを必須導入するのに、マージ後に作られる文書が WebKitを「Target・未対応」としています。  
  修正案: 本PR完了後の `Current` を Chromium + WebKit に更新してください。実装途中の状態は設計書にのみ残し、運用文書はマージ後の実態を記載します。

### 施策8: Browser E2E + WebKit

**APPROVE**

WebKit必須化、`pageshow.persisted` の正のコントロール、iOS実機を補完へ降格した点はいずれも妥当です。

- [Warning] 施策7の保証表だけが追随していません。  
  修正案: 施策7の `Current` と完了条件を同期してください。

### 施策9〜14

**すべて APPROVE**

Round 2 以降に新たなブロッカーはありません。

修正対象は限定的です。施策2の出自判定を確定し、施策6のプローブを既存middlewareから独立させ、施策7をWebKit導入後の状態へ合わせれば承認可能です。