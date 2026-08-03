# 対応マトリクス: conceptual-review Round 2

## [Critical] 観点3 — `{oauthSession}` を「UUID PK」という理由だけで B 群へ除外したのは不整合

- **判断: 対応する（指摘は正しく、実バグを 1 件追加で掘り当てた）**
- **根拠**: 実コードで確認したところ、Codex の疑いはそのまま成立していた。

  | 確認事項 | 実測 |
  |---|---|
  | `OauthSession` の PK | `use HasUuids;` (`app/Models/OauthSession.php:47`)、`oauth_sessions` は `$table->uuid()` |
  | route 定義 | `DELETE /organizations/{organization:slug}/api-keys/sessions/{oauthSession}` (`routes/web.php:278`) |
  | `whereUuid` の適用 | **無し**。`routes/web.php` の `whereUuid` は L358 / L361 の `{notification}` **2 箇所のみ** |
  | custom binder | **無し**。`Route::bind` は `organization` の 1 件のみ (`AppServiceProvider.php:154`) |

  したがって `DELETE .../api-keys/sessions/abc` は `where id = 'abc'` を uuid 列へ投げ、
  pgsql 22P02 → **404 ではなく生 500**。**課題 1 と同一のバグクラスで、2 件目の未防御経路**。
  「UUID だから除外」は私の分類誤りで、正しくは「UUID は**別種の制約が要る**」だった。

- **対応内容**:
  1. `{oauthSession}` を **P1 の修正対象へ追加**した (whereUuid 相当の制約 + 404 Feature テスト)。
  2. inventory を「数値 allowlist + 除外リスト」から、Codex 提案の **4 分類**へ作り直した:
     `bigint` / `uuid` / `custom binder` / `非モデル文字列`。
  3. 概念設計の課題 1 の記述を「数値 PK が無防備」から
     **「型付き PK の route binding が系統的に無防備 (bigint と uuid の両方)」**へ改めた。
     施策名も `NumericRouteBindingConstraintTest` から
     **`RouteBindingTypeConstraintInventoryTest`** へ改名 (数値限定でなくなったため)。

## [Warning] 観点3 — inventory gate の成立方法が曖昧 (route 定義だけでは param の PK 型を判定できない)

- **判断: 対応する**
- **根拠**: 正当。「未知 param を数値と推測する」設計は原理的に成立しない。
  Codex の「**total inventory** にして分類漏れ自体を禁止する」提案が正しい。
  AI-CUE には既に同型の precedent がある (`NestedRouteIdorDefenseTest` の
  「inventory に登録必須」、`ScenarioWritePathInventoryTest` の「新経路は登録必須」)。
- **対応内容**: gate を **total inventory 方式**に再定義した。
  - 全 binding param を 4 分類のいずれかに登録することを必須とする。
  - **未登録の param が route に現れたら fail**。「数値と推測して制約を掛ける」ことはしない。
  - fail 時のメッセージで「型・解決方式・除外理由を登録せよ」と要求する。
  - 登録済み param については、分類に応じた制約 (bigint→数値 / uuid→UUID /
    custom binder→binder の入力正規化の存在) を検証する。
  - これにより param rename・新 route 追加・新モデル追加のいずれでも gate が落ちる。

## [Warning] 観点2 — P3 の `logout → browser back` は Feature テストでは検証できない

- **判断: 対応する**
- **根拠**: 全面的に正しい。Feature テストが見られるのは応答ヘッダまでで、
  ブラウザの bfcache 復元動作ではない。ここを一括で「テスト済み」と書くのは
  **禁止事項 #1 (テストなしの実装完了報告) の実質的な違反**にあたる。
- **対応内容**: P3 の検証を **2 層に明確分離**した。

  | 層 | 検証内容 | 手段 |
  |---|---|---|
  | Feature | 認証済み HTML/Inertia 応答の `Cache-Control` に `no-store` が付くこと / 既存 4 経路が untouched であること | Pest Feature テスト |
  | Browser (E2E) | `logout → back` で PII 画面が再表示されないこと | `scripts/run-browser-test.sh` (`docs/testing-browser.md`) または bug-hunt |

  成果指標も同じ 2 層に分けて書き直した (下記 Warning 観点4 と併せて対応)。

## [Warning] 観点4 — 「bfcache 再表示を常に保証する」は表現が強すぎる

- **判断: 対応する**
- **根拠**: 正当。`no-store` に対する bfcache の扱いはブラウザ実装依存
  (Firefox は格納自体を拒否、Chrome は cookie 変更時に CCNS ページを evict、
  **Safari は `no-store` でも格納しうる**)。HTTP ヘッダだけで全ブラウザの挙動は断定できない。
  aigenba の実装コメントも「Safari は本施策のスコープ外」と明記しており、
  私の概念設計はその但し書きを落としていた。
- **対応内容**: 成果指標を分離した。
  - (a) **保証できること**: 認証済み応答に再利用禁止ヘッダを付与する (機械的に検証可能)
  - (b) **確認すること**: サポート対象ブラウザの代表 E2E で再表示されない
  - (c) **限界として明記**: Safari の bfcache は `no-store` で抑止しきれない (スコープ外)

## [Warning] 観点5 — 「既に no-store を持つ応答は untouched」だけでは `public, no-store` のような矛盾値も温存する

- **判断: 対応する（ただしスコープは広げない）**
- **根拠**: 正当な指摘。ただし Codex 自身が「矛盾ヘッダの一般的な正規化まで今回のスコープへ
  広げる必要はない」と添えており、これに同意する (思考原則 #2「今必要なものだけ作る」)。
- **対応内容**:
  - baseline middleware の契約を **「既存ヘッダを上書きしない」と明示**した。
  - **既存 4 経路の期待値を個別 Feature テストで固定**することを施策に追加した
    (`FortifyServiceProvider:199` / `RequireRecentAuth:57` /
     `RequireTwoFactorForEnforcedOrganizations:93` / `CaptureTakeController:177`)。
    これにより矛盾値が現存しないことを実測で確認でき、将来混入したら落ちる。
  - 矛盾ヘッダの一般正規化は**スコープ外**として明記した。

## [Suggestion] 観点1 / 観点6 / 観点7 — 主便益の整理・トラック分割・型安全性方針は妥当

- **判断: 対応不要**（肯定的評価）
