# 対応マトリクス: design-review Round 3

## 施策 6 [Critical] `/session/status` が既存の認証後 middleware に遮断され reload ループになり得る

- **判断: 対応する（実装可能性に直結する重大な見落とし）**
- **根拠**: 全面的に正しい。`RequireTwoFactorForEnforcedOrganizations` は
  `bootstrap/app.php` の **`web` グループ append** に登録されているため、
  **web グループの全 route に効く**。プローブもその対象になり、
  2FA 強制中のユーザーには **409 / redirect** が返る。
  guard は 200 boolean 以外をプローブ失敗として扱うため、
  **有効なセッションなのに秘匿が解除されず、再試行 → 同じ結果 → ループ**になる。
- **対応内容**: 既存の allowlist 機構に載せる。
  - `RequireTwoFactorForEnforcedOrganizations::ALLOWED_ROUTE_NAMES`（`app/Http/Middleware/RequireTwoFactorForEnforcedOrganizations.php:41`）は
    **route name → 必要理由**の連想配列で、`TwoFactorEnforcementAllowlistTest` が
    「全エントリが実在する named route」「各エントリが非空の理由を持つ」を CI で固定している。
    **本リポジトリに確立済みの exemption 作法**なので、`session.status` をここへ登録する。
  - 安全性: プローブの応答は **`{ authenticated: bool }` のみで PII も操作も含まない**ため、
    2FA 強制中に 200 を返しても情報露出にならない。
  - **web グループ append の他 middleware も確認済み**:
    `BlockTwoFactorDisableForEnforcedOrganizations` は 2FA disable route 限定、
    `HandleInertiaRequests` / `SecurityHeaders` は遮断しない。
    `RequireRecentAuth` / `RequireActiveSubscription` / `verified` は **route レベル**適用で
    プローブ route には付かない。→ **遮断要因は 2FA gate のみ**。
  - **Feature テストを追加**する: **2FA 強制中 / recent-auth 期限切れ / 組織未選択**の各状態で
    **必ず 200 + boolean** を返すこと。

## 施策 6 [Warning] `SessionStatusResource` にヘッダを付ける方法が未確定

- **判断: 対応する**
- **根拠**: 正当。Controller の戻り値を Resource に固定したままだと、
  ヘッダ付与のフックが設計から抜けている。
- **対応内容**: **`JsonResource::withResponse()`** で `no-store, private` を設定すると明記した。
  Controller の戻り値型は `SessionStatusResource` のまま保てる
  （既存 `RecentAuthStatusResource` は controller 側で付けているが、
   プローブは guest 応答も対象なので **Resource 側に閉じる方が漏れない**）。

## 施策 6 [Warning] `fetch()` の HTTP 成功だけで JSON を信用すると HTML redirect / 409 body を誤処理する

- **判断: 対応する**
- **根拠**: 正当。`fetch` は redirect を自動追従するため、
  login ページの **HTML が 200 で返る**ケースがある。
  `res.ok` だけで JSON を期待すると誤判定する。
- **対応内容**: 判定条件を厳密化した。以下を**全て満たした場合のみ**判定に採用する:
  1. `response.ok`（2xx）
  2. `Content-Type` が JSON
  3. JSON shape が厳密に成立（`authenticated` が `boolean`）

  いずれか 1 つでも崩れたら **プローブ失敗**へ倒す（= 秘匿維持 + 再試行導線）。

## 施策 6 [Suggestion] vitest では実際の描画露出は検証できない

- **判断: 対応する**
- **対応内容**: 負のコントロールの表現を
  「旧 DOM が可視」→ **「秘匿属性が付いていない」** に言い換えた。
  テスト責務（vitest は属性・分岐の検証、実描画は E2E）を正確にした。

## 施策 2 [Warning] route 出自判定が「実装時に候補から選ぶ」のままで未確定

- **判断: 対応する（方式そのものを変更した）**
- **根拠**: 正当。指摘のとおり controller namespace 方式は
  **closure route** と **vendor controller をアプリ側で登録する route（Fortify 等）** を
  正しく分類できない。**実装時判断として残すべきではない**。
- **対応内容**: Codex の第 2 案を採り、**出自判定そのものを不要にした**。
  - inventory に **第 5 分類 `EXTERNAL`**（vendor route が持ち込む param 名）を追加する。
  - **IV-1 は全 route（vendor 含む）を走査**し、
    **現れる全 param 名が 5 分類のいずれかに登録されていること**を要求する。
    → **出自を判定する必要が無くなる**。
  - **限界を正直に書く**: IV-7（衝突検出）が保証するのは
    「**新しい param 名が現れた時点で人間の分類を強制する**」ことであって、
    「vendor が `{user}` を非数値用途で使っていることを機械的に意味判定する」ことではない。
    新規 param は必ず未登録 → IV-1 が fail → 分類時に人間が既存 `BIGINT` との衝突に気づく、
    という**強制レビュー**が実質的な防御になる。この限界を設計に明記する。

## 施策 2 [Warning] 負のコントロール計画が IV-1・IV-3 までしか無い

- **判断: 対応する**
- **対応内容**: **IV-7 / IV-8 の負のコントロール**を追加した。
  - IV-7: fixture の vendor route に未登録 param を持たせて fail することを確認
  - IV-8: `BIGINT_PATTERN` を `[0-9]+` に変えると fail することを確認

## 施策 2 [Suggestion] リスク表が見出しで分断され IV-2 の行が表外に出ている

- **判断: 対応する**
- **対応内容**: 文書構造を修正し、リスク表を分断しないよう節を並べ替えた。

## 施策 3 [Suggestion] `{organization:未許可 field}` のテスト専用 route が production inventory に混入する

- **判断: 対応する（施策 2 の IV-1 と直接ぶつかるため重要）**
- **根拠**: 正当。IV-1 が全 route を走査する設計にしたため、
  テスト用 route を `routes/` に置くと **inventory 登録が必要になり本番 route を汚す**。
- **対応内容**: **テスト内で route を定義する**（`Route::get(...)` をテストケース内で登録し、
  そのテストの中でだけ有効にする）。`routes/` 配下には置かない。
  IV-1 は別テストで実行されるためテスト用 route を観測しない。この方針を設計に明記した。

## 施策 4 [Suggestion] 「最終応答」より「下流から返った応答」が正確

- **判断: 対応する**
- **根拠**: 正当。`$next` 後の応答は**さらに外側の middleware がまだ変更できる**ため
  「最終」ではない。
- **対応内容**: 表現を **「`$next` から返った（= 下流の）応答」**に修正した。

## 施策 7 [Warning] 同じ PR で WebKit を必須導入するのに、文書が WebKit を「Target・未対応」としている

- **判断: 対応する**
- **根拠**: 正当。施策 8 で WebKit レーンを**必須の実装完了条件**にしたのに、
  施策 7 の運用文書だけが R1 時点の「未対応」記述のまま取り残されていた。
  **運用文書はマージ後の実態を書くべき**で、実装途中の状態は設計書に残せばよい。
- **対応内容**: 施策 7 の `Current` を **Chromium + WebKit** に更新し、
  「未対応事項」から WebKit レーンを削除した。
  実装途中で WebKit が未導入であることは**本詳細設計書にのみ**残す。
  併せて施策 8 の完了条件と施策 7 の保証表を**同期**させた。

## 施策 1 [Suggestion] IV-8 の pin と regex 境界テストは重複気味

- **判断: 対応不要（安全性重視として許容と明示されているため現状維持）**
- **根拠**: 役割が違う。IV-8 は「値が変えられた」ことの検出（Architecture）、
  regex 境界テストは「その値が意図どおり 18/19 桁を分ける」ことの検証（Unit）。
  重複コストは小さく、`[0-9]+` への退行という**実害の大きい変更**を二重に防げる。

## 施策 5 / 施策 8 / 施策 9〜14

- **判断: 対応不要**（APPROVE。施策 8 の Warning は施策 7 の同期で解消済み）
