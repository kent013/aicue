全体判定: **CHANGES_REQUESTED**

仮説: この設計の成功条件は「passkey-only / password未設定ユーザーの踏破不能 UI を消す」だけでなく、「次の field 追加・call-site 追加・delegated 409 でも同じ穴を再発させない」ことです。方向性は概ね妥当ですが、gate と delegated 経路、パスワード確定処理、Passkey 登録状態管理に修正必須点があります。

## 施策別レビュー

### 施策 1: RecentAuthModal props 契約統合
判定: **APPROVE**

[Suggestion] `status === null` の表示は良いです。テストでは「空表示にならない」だけでなく、password / SSO / passkey の誤った導線が出ないことも固定してください。

### 施策 2: call-site inventory gate
判定: **REQUEST_CHANGES**

[Critical] 現行 gate は `@/components/organisms/RecentAuthModal.svelte` の alias import しか検出しないため、相対 import や動的描画で bypass できます。  
修正案: `resources/js` 全体から `<RecentAuthModal\b` のタグ出現も走査し、import 形に依存せず未登録 call-site を検出してください。可能なら Svelte/TS AST、最低でもタグ走査を主検出にしてください。

[Warning] `ON_STALE_PATTERN` は `onStale` の存在しか見ておらず、`recentAuthStatus = status` への格納を保証していません。  
修正案: `onStale` 内で `recentAuthStatus` に代入していることまで検査するか、`openRecentAuthModal(status)` のようなローカル helper 名を固定して gate してください。

### 施策 3: `/recent-auth/status` strict parse
判定: **APPROVE**

[Suggestion] contract test では JSON wrapping の有無も固定してください。`RecentAuthStatusResource` が将来 `data` ラップされると TS strict parse は即 `null` になります。

### 施策 4: 409 `recent_auth_required` handler
判定: **REQUEST_CHANGES**

[Critical] `RequireRecentAuth` の 409 分岐は `url.intended` / `recent_auth.dropped_mutation` を保存していません。handler が `/recent-auth/confirm` へ飛ばしても、confirm 後に元画面へ戻れず dashboard fallback になり得ます。  
修正案: Inertia mutation で 409 を返す前に、same-origin referer を `url.intended` に保存し、mutation body が失われるため `recent_auth.dropped_mutation=true` も立ててください。既存 302 fallback と同じ「戻る + 再操作を促す」契約に揃えるべきです。

[Warning] `event.detail.response` を `{ status, data }` として扱う前提は Inertia core の実体に強く依存します。  
修正案: 実際の `@inertiajs/*` 型に合わせて narrowing し、unit mock だけでなく実イベント形状に近いテストを追加してください。native `Response` なら `data` は存在しません。

### 施策 5: RecentAuthRecoveryNotice
判定: **APPROVE**

[Suggestion] `logout()` は `if (loggingOut) return;` を入れて二重送信を避けると堅いです。molecule に domain-specific logout を持たせる判断は、organism から features を import できない制約上は妥当です。

### 施策 6: パスワード初回設定経路
判定: **REQUEST_CHANGES**

[Critical] `deleteOtherSessionRecords()` を transaction 内で “best-effort catch” する設計は危険です。PostgreSQL では DB 例外を catch しても transaction が aborted 状態になり、commit 時に失敗し得ます。既存実装では transaction 外だった副作用を transaction 内へ移しており後退リスクがあります。  
修正案: `users.password` 保存と監査記録だけを transaction に入れ、commit 後に `Auth::logoutOtherDevices($plain)` と DB session 行削除を実行してください。必要なら認証中 User を refresh してから `logoutOtherDevices` を呼んでください。

[Warning] `UpdateUserPassword` の constructor 差し替えが設計本文に明示されていません。  
修正案: `SecurityEventRecorder` 依存を `PasswordCredentialService` 依存へ変更する差分を設計に含め、既存 password change Feature test で DI 解決まで確認してください。

### 施策 7: `/settings` password card 出し分け
判定: **REQUEST_CHANGES**

[Warning] `props.hasPassword ?? false` は、prop 欠落時に password 設定済みユーザーへ初回設定フォームを出します。今回潰そうとしている「状態不明を誤った UI に倒す」問題の再発です。  
修正案: `hasPassword` を必須 contract とし、runtime でも `typeof props.hasPassword !== "boolean"` の場合は unknown 表示または reload 導線に倒してください。少なくとも false 補完は避けるべきです。

### 施策 8: `settingsUrl` 削除と CTA 修正
判定: **APPROVE**

[Suggestion] `settingsUrl` の消費者ゼロは grep 前提だけでなく、JSON contract test で「返らない」ことを固定してください。phantom contract の再追加を防げます。

### 施策 9: WebAuthn 失敗を Alert に統一
判定: **APPROVE**

設計は妥当です。特に `RecentAuthModal` の password field error と passkey ceremony error を分離する点は必要です。

### 施策 10: `nameError` の `$derived` 化
判定: **APPROVE**

設計は妥当です。server error を client error より優先する方針も明確です。

### 施策 11: PasskeySection 登録フロー整理
判定: **REQUEST_CHANGES**

[Critical] `registering` を guard callback 内で立てる設計だと、`guard()` の recent-auth precheck 中に二重クリックでき、ceremony 多重起動や pending action 上書きが残ります。  
修正案: ceremony 前だけでなく guard 開始前から登録フロー全体を表す state を立ててください。さらに stale modal cancel 時に解除できる contract が必要です。現在の `guard: (action) => void` では完結しにくいので、`guard` を Promise/結果返却型にするか、cancel/onDelegated/onStale の解除点を明示してください。

[Warning] テスト計画の「連打しても ceremony 1 回」は ceremony 中だけでなく、`fetchRecentAuthStatus` pending 中の連打も対象にしてください。  
修正案: `guard` が action 実行を遅延する mock を使い、その間に複数クリックしても pending action が 1 つだけになることを固定してください。

### 施策 12: ドキュメント更新
判定: **APPROVE**

内容は施策 5 / 9 と整合しています。実装後に logout inventory と `docs/supported-browsers.md` の呼び出し元が一致していることを gate で確認してください。

## 追加の横断指摘

[Warning] テスト計画に `npx vitest run ...` が混在していますが、規約では T099 のグローバルテストロック経由です。  
修正案: 個別確認も `pnpm test` 系の既存 script 経由に統一してください。`npx vitest` 直叩きが許容されるなら、その例外を設計に明記してください。