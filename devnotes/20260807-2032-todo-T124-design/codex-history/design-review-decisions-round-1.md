# 対応マトリクス: design-review Round 1

## 施策 4 [Critical] 409 → `guardWithRecentAuth` は status 取得失敗時に自動再実行ループになる
- 判断: **対応する（指摘は正しい。実装したら実害が出るバグ）**
- 根拠: `withRecentAuth()` は status 取得失敗 (delegated) のとき
  `(handlers.onDelegated ?? handlers.onFresh)()` を呼ぶ。
  現行の `guardWithRecentAuth(action)` は `onDelegated` を渡していないため、
  409 → status 500 → `onFresh` = `loadEnrollmentAssets()` → また 409 →
  …が**ユーザー操作なしで回り続ける**。リスク欄の「成立時 1 回だけ」という記述は
  delegated 分岐を見落としていた。
- 対応内容（3 重で止める）:
  1. `guardWithRecentAuth(action, onDelegated?)` に **optional 第 2 引数**を追加し、
     409 分岐からは `onDelegated` を**必ず**渡す（既存 4 呼び出し側は無指定のまま挙動不変）。
  2. `onDelegated` は再取得しない。専用状態 `enrollmentStepUpBlocked` を立て、
     「再認証が必要」Alert + `/recent-auth/confirm` への導線 + 手動再試行を出す
     （行き先のない詰みを作らない）。
  3. **自動再開は 1 enrollment あたり 1 回**に上限を設ける
     (`enrollmentStepUpRetried` を `resetEnrollmentAssets()` で戻す)。
     サーバ側の鮮度判定が status と 409 で食い違う異常時にも必ず停止する。
     手動再試行ボタンはこのフラグを戻す（ループを切るのは常に人間の操作）。
  4. JS テストに「素材 409 + `/recent-auth/status` 500 のとき再取得ループしない
     （fetch 呼び出し回数が有界）」を追加。

## 施策 4 [Warning] 409 の `redirect` を捨てている
- 判断: **一部対応（redirect は保持しない）**
- 根拠: 対応 2 の着地はアプリ自身の既知 path であり、サーバ由来 URL を使う必要がない。
  redirect を保持すると `recentAuthRedirectTarget()` と同等の
  same-origin / known-path 検証を**もう 1 箇所**書くことになり、判定点が増える
  （既存設計方針「判定点を 2 つ作らない」に反する）。
- 対応内容: `lib/recent-auth.ts` の `RECENT_AUTH_CONFIRM_PATH` を **export** し、
  Svelte 側はその定数を使う（リテラル重複も作らない）。`EnrollmentField` は boolean のまま。

## 施策 4 [Suggestion] import 変更を変更箇所に明記
- 判断: 対応する
- 対応内容: 変更箇所に `import { withRecentAuth, isRecentAuthRequiredPayload,
  RECENT_AUTH_CONFIRM_PATH, type RecentAuthStatus } from "@/lib/recent-auth";` を明記。

## 施策 2 [Warning] 「ちょうど 1 本」と書くなら数える gate にせよ
- 判断: 対応する
- 根拠: 正しい。契約文と機械検査が一致していなかった。二重付与は
  `appendMiddlewareIfMissing()` が防ぐ設計だが、group 側とルート側の両方から
  付与されれば 2 本になりうる（`RouteThrottleBinder` が throttle で同じ理由から
  「2 本以上は fail」にしているのと同じ形）。
- 対応内容: `RecentAuthMiddleware::countAttached(): int` を追加し、
  gate は「非 exemption route は recent-auth entry がちょうど 1」を検査する。
  `isAttached()` は `countAttached() > 0` の薄いラッパとして残す
  （`RecentAuthRouteTest` の既存意味を変えない）。

## 施策 2 [Warning] non-exemptible 固定リストが狭い
- 判断: 対応する
- 根拠: T124 の中心リスクは秘密の**読み出し**と**差し替え**の両方であり、
  `enable` を免除可能なままにするのは設計意図と検査の不一致。
- 対応内容: 関数名を `twoFactorNonExemptibleRoutes()` に変え、6 本にする:
  秘密開示 3 本 (`qr-code` / `secret-key` / `recovery-codes`) +
  第二要素の除去・差し替え 3 本 (`enable` / `disable` / `regenerate-recovery-codes`)。
  組織管理側の 2 本 (`organizations.members.two-factor.reset` /
  `organizations.two-factor-requirement.update`) は**入れない**
  — 脅威系統が違い（管理者操作・Gate 認可が別途かかる）、
  `RecentAuthRouteTest` の allowlist が既に名指しで固定しているため二重管理になる。
  この線引きを gate のコメントに書く。

## 施策 2 [Warning] `vendor/bin/pest` 直叩きはグローバルテストロックと衝突
- 判断: 対応する
- 根拠: 正しい。AGENTS.md §テストレーンのグローバルロック (T099) は
  `composer test` 経由で worktree 横断の直列化を行う。
  `scripts/run-test.sh` は `php artisan test --parallel --processes=4 "$@"` で
  引数を透過するため、**`composer test -- <path>` で個別ファイル指定ができる**（実査済み）。
- 対応内容: Step A / Step C のコマンドを
  `cd /workspace && composer test -- tests/Architecture/TwoFactorStepUpInventoryTest.php` に統一。

## 施策 1 [Warning] `force=true` 回帰テストの前提 (confirmed_at) を明示固定せよ
- 判断: 対応する
- 根拠: `UserFactory::withTwoFactor()` が `two_factor_confirmed_at` を立てるのは
  実装依存であり、Factory が変われば「確立済み 2FA に対する差し替え」という
  テストの意味が沈黙して薄れる。
- 対応内容: stale / fresh の両ケースで
  (a) 事前に `two_factor_confirmed_at` が non-null であることを assert、
  (b) stale では secret / recovery codes / confirmed_at の **3 つとも不変**、
  (c) fresh では secret / recovery codes は**変化**し confirmed_at は**不変**
  （= Fortify が confirmed_at を触らない = ロックアウトが成立する仕組みそのもの）を固定する。

## 施策 1 [Suggestion] `AuthThrottleCoverageTest` を最初から変更対象に含めよ
- 判断: 対応する
- 対応内容: 「落ちたら直す」ではなく**計画された波及変更**として施策 1 の変更対象へ格上げし、
  `withSession(['recent_auth_at' => time()])` の付与を明記する（検査意図・閾値は不変）。

## 施策 3 [Suggestion] challenge 応答の shape まで見る
- 判断: 一部対応
- 根拠: vendor (Fortify passkeys) の応答 shape に強く依存するテストは、
  vendor update で意味の無い赤を生む。ただし「allowlist は通ったが実用上は壊れている」
  空振りの指摘は妥当。
- 対応内容: `assertOk()` までを固定し（実測で 200 以外が正なら実測に合わせる）、
  応答 body の細部には踏み込まない。「`settings.security` へ redirect されない」は必ず固定する。

## 施策 5 [Warning] AGENTS.md の「ちょうど 1 本」と gate の強さを合わせよ
- 判断: 対応する（gate 側を強くする = 施策 2 の count-based 化で解決）

## 施策 5 [Warning] 実装モード standalone と worktree 運用の関係が曖昧
- 判断: 対応する
- 対応内容: 実装モード表に「専用 worktree (`scripts/setup-worktree.sh <task-id>`) 上での
  standalone 実装。main 直実装は行わない」と明記し、Definition of Done にも入れる。
