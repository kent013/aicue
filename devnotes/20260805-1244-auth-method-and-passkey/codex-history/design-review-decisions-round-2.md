# 対応マトリクス: design-review Round 2 (全体判定 APPROVED)

Round 2 で全 6 施策が APPROVE、全体判定 APPROVED。Critical なし。
残った Warning 4 件 / Suggestion 1 件の処理は以下。

## [Warning][実装時] `RequireRecentAuth` の 409 が Inertia で安定処理できるか

- 判断: **申し送り (実装フェーズで実測)**
- 根拠: 妥当な懸念。Inertia の 409 は外部 location response にも使われるため、
  `onError` で安定して拾えるとは限らない。ただし本設計は precheck
  (`guardWithRecentAuth`) を主経路にしており、409 はレース時の最終ゲートにすぎない。
  また `RequireRecentAuth` は**既存の共有 middleware** であり、
  本 TODO の範囲で挙動を変えると 2FA / API キー / アカウント削除の全経路に波及する。
  設計で決め切るより、実測して必要なら別途扱うのが正しい。
- 対応内容: 詳細設計に「実装フェーズで確定させる項目」節を新設し、
  実測手順と、成立しない場合のフォールバック
  (Inertia 向けは `back()->withErrors()` に統一 + `RecentAuthTest` 回帰更新) を明記した。

## [Warning][実装時] middleware 順序テストを解決後クラス順でも検査

- 判断: **申し送り (実装フェーズ)**
- 根拠: 妥当。`$middlewarePriority` による並べ替えまで含めれば実行順を保証できる。
  ただし `Router::gatherRouteMiddleware()` は protected/内部 API の可能性があり、
  実装時にアクセス可否を確認する必要がある。
- 対応内容: 「実装フェーズで確定させる項目」に記載。alias 文字列比較を最低ラインとし、
  解決後クラス順の検査を可能なら追加する。

## [Warning] `LoginMethodRetentionTest` の期待値表が旧契約 (422 一律) のままだった

- 判断: **即時対応した (設計書内の自己矛盾)**
- 根拠: 正しい。Round 1 で `reject()` を
  「Inertia = 302 + errors / 純 XHR = 422 JSON」に変えたのに、
  テスト計画の表を更新し忘れていた。設計書の自己矛盾は実装を誤らせる。
- 対応内容: 期待値表に「リクエスト種別」列を追加し、
  Inertia / 純 XHR / 通常フォーム POST の 3 系統に分けて期待値を書き直した。

## [Warning][実装時] fetch ラッパの HTTP ヘッダ契約

- 判断: **申し送り (ただし重要点は設計に明記した)**
- 根拠: 特に「`passkey.login` は `Accept: application/json` が無いと
  `expectsJson()` が false になり JSON Resource 分岐に入らない」は
  設計の正しさに直結するため、申し送りに留めず本文へ書くべき。
- 対応内容: 「実装フェーズで確定させる項目」に
  `Accept` / `Content-Type` / `X-XSRF-TOKEN`（既存 `RecentAuthModal` と同作法）を明記し、
  JSON 分岐の前提条件であることを強調した。
  ヘッダ固定は `tests/js/lib/passkeys.test.ts` の責務とした。

## [Suggestion] 未使用の `LoginMethodKind` は追加しない (YAGNI)

- 判断: **対応する**
- 根拠: 正しい。`LoginMethodSet` は「空かどうか / 何個か」しか使わず、
  要素の種別で分岐する箇所が無い。AGENTS.md 思考原則 2 (今必要なものだけ作る)。
- 対応内容: `app/Enums/Auth/LoginMethodKind.php` を削除し、
  「UI に手段の内訳を出す要件が生まれたときに導入する」と明記。
  変更箇所リストも `LoginMethodRemovalKind` に修正した。

## [確認] `PasskeyConfirmationResponse` での `auth.password_confirmed_at` 除去

- 判断: **変更なし (妥当との評価)**
- 根拠: 「`toResponse()` は通常 session 永続化より前に評価されるため、
  リクエスト完了後にキーが存在しない Feature テストで契約を固定すれば十分」との評価。
  既にテスト計画に含まれている。
