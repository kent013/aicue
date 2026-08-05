# 対応マトリクス: design-review Round 1

## [Critical] 施策 2: gate が alias import しか見ておらず相対 import で bypass できる
- 判断: 対応する
- 根拠: 妥当。gate は「書き方を変えれば抜けられる」時点で deny-by-default ではない。
- 対応内容: 主検出を **`<RecentAuthModal` タグ出現**に変更し、import 検出は
  パス末尾一致 (`[^"']*RecentAuthModal\.svelte`) の補助検出として残した (両者の和で列挙)。
  `/g` 正規表現の `lastIndex` 持ち越しを避ける注記も追加。

## [Warning] 施策 2: `onStale` の存在確認だけでは `recentAuthStatus` への格納を保証しない
- 判断: 対応する
- 根拠: 妥当。
- 対応内容: `ON_STALE_ASSIGNMENT_PATTERN` (`onStale: (status) => { ... recentAuthStatus = status`)
  に変更し、代入まで検査する。

## [Suggestion] 施策 3: `data` ラップの有無も contract テストで固定
- 判断: 対応する
- 根拠: `$wrap = null` が外れると TS の strict parse が全件 `null` を返し、全画面が delegated に落ちる。
- 対応内容: Feature contract テストに「top-level に `data` ラップが無いこと」を追加。

## [Critical] 施策 4: 409 分岐が `url.intended` / `dropped_mutation` を保存していない
- 判断: 対応する
- 根拠: 妥当かつ重要。施策 4 で 409 → confirm 画面へ飛ばすようになると、
  confirm 成功後に元画面へ戻れず dashboard へ落ち、「操作は実行されていません」の案内も出ない
  (操作のサイレント喪失)。302 分岐だけが着地契約を持っていたのは、409 を拾う実装が無かったため。
- 対応内容: `RequireRecentAuth` を変更箇所に追加。**Inertia mutation の 409 のみ**
  `url.intended` (same-origin referer) と `recent_auth.dropped_mutation` を保存する。
  純 XHR (fetch) はクライアントが自前で再開するため対象外にし、他フローの intended を汚さない。
  Feature テスト 2 本 (Inertia mutation で intended 保持 + 再操作案内 / 純 XHR では書き換えない) を追加。

## [Warning] 施策 4: `event.detail.response` の形状依存
- 判断: 対応する
- 根拠: 妥当。
- 対応内容: 引数を `unknown` として narrowing する形は維持しつつ、
  「実装時に `@inertiajs` の型定義で実体を確認する」「`data` を持たない形なら
  preventDefault せず既定処理へ渡る (fail-closed)」を明記。テストの mock も実 event 形状に合わせる。

## [Suggestion] 施策 5: `logout()` の二重送信ガード
- 判断: 対応する
- 対応内容: `if (loggingOut) return;` を追加。

## [Critical] 施策 6: best-effort な副作用を transaction 内に入れると PostgreSQL で巻き添えになる
- 判断: 対応する
- 根拠: 妥当かつ本質的。PostgreSQL は transaction 内で失敗した文があると以降 aborted になり、
  アプリ側で catch しても commit できない。監査記録 (recorder が Throwable を握る) や
  session 行削除 (best-effort catch) を transaction に入れると、
  **best-effort のつもりの副作用がパスワード保存を巻き添えにする**。
  既存 `UpdateUserPassword` はこれらを transaction 外で実行しており、その性質を保つべき。
- 対応内容: `setInitial()` の transaction を「ロック → 前提の再確認 → password 保存」だけにし、
  監査記録 / `logoutOtherDevices` / session 行削除は **commit 後**の `afterPersist()` へ移した。
  `change()` は単一 UPDATE なので transaction を開かない (既存挙動を変えない)。
  リスク節も書き換え。

## [Warning] 施策 6: `UpdateUserPassword` の constructor 差し替えが未記載
- 判断: 対応する
- 対応内容: `SecurityEventRecorder` → `PasswordCredentialService` への依存差し替えを明記し、
  テスト計画に「DI 解決まで通ることを既存 Feature テストで確認」を追加。

## [Warning] 施策 7: `props.hasPassword ?? false` は状態不明を誤った UI に倒す
- 判断: 対応する
- 根拠: 妥当。本批で潰している species そのもの (施策 1 の null 分岐と同じ扱いにすべき)。
- 対応内容: `"set" | "unset" | "unknown"` の 3 値に変更。unknown では**どちらのフォームも出さず**
  警告 Alert + 再読み込みボタンを出す。JS テストにも unknown ケースを追加。

## [Suggestion] 施策 8: `settingsUrl` が返らないことを contract テストで固定
- 判断: 対応する
- 対応内容: 422 ボディのキー集合が `code` / `message` に一致することを固定 (再追加を機械的に防ぐ)。

## [Critical] 施策 11: precheck 中の連打で ceremony が多重起動する
- 判断: 対応する
- 根拠: 妥当。`guard()` (= `/recent-auth/status` の fetch) 待ちの区間が無防備。
- 対応内容: `guard` prop の型を
  `(action: () => void) => Promise<"fresh" | "stale" | "delegated">` に変更し
  (`withRecentAuth` の戻り値をそのまま流す)、precheck 区間を `prechecking` state で覆う。
  ボタンの loading は `prechecking || registering`。stale 委譲時は precheck を閉じ、
  再開は modal `onConfirmed` → `resumePendingAction` 経路で `registering` が握る
  (モーダルをキャンセルしてもボタンが loading のまま固まらない)。
  波及として `Settings/Security.svelte` の `guardWithRecentAuth` を戻り値返却に変更し、
  他の呼び出し側は `void` で明示破棄する。

## [Warning] 施策 11: 連打テストは precheck pending 中も対象にする
- 判断: 対応する
- 対応内容: 「guard の解決を遅延させる mock で複数クリックしても ceremony/pending action が 1 つ」
  「stale 委譲後にモーダルをキャンセルしてもボタンが固まらない」の 2 ケースを追加。

## [Suggestion] 施策 1: null 表示のテストで誤った導線が出ないことも固定
- 判断: 対応する
- 対応内容: `status=null` で password フォーム / SSO / パスキー / 回復 notice のいずれも
  描画されないことをテストに追加。

## [Warning] 横断: `npx vitest` 直叩きは規約 (T099 グローバルロック) 違反
- 判断: 対応する
- 対応内容: テスト計画の実行コマンドを `pnpm test <path>` (= `scripts/run-vitest.sh` 経由) に統一した。
