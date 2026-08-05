# 対応マトリクス: conceptual-review Round 2

## [Critical] `fetchRecentAuthStatus()` の既定値補完が通信境界で同じ回帰を隠す (観点 4 / 7)
- 判断: 対応する
- 根拠: 妥当かつ本質的。call-site gate は「渡し忘れ」を止めるが、`?? false` による補完は
  **サーバ Resource 側の欠落**を正常値に見せる。今回の回帰 (passkeyAvailable が false 扱い) が
  通信境界で再演しうる。
- 対応内容: 概念設計に「施策 1-b: 通信境界でも既定値による黙った補完をやめる」を追加。
  strict parse (欠損・型不一致は契約不成立 → `null`) にし、`withRecentAuth` の既存 delegated 経路
  (= モーダルを開かずサーバの最終ゲートへ委譲 → 全画面 confirm でサーバが完全な分岐を描く) に倒す。
  contract テストを 2 枚 (Feature: JSON キー集合と型の固定 / JS: field 欠落時に null を返す) 追加する。

## [Critical] nullable `status` の時間的状態がモーダル表示時の踏破可能性を保証しない (観点 5)
- 判断: 一部対応する (nullable 維持 + null 分岐を明示・テスト化)
- 根拠: 「null のまま開いたときに何が出るか」を決めよという指摘は妥当なので対応する。
  ただし「prop を非 nullable にして呼び出し側が `{#if}` で loading/failure を処理する」案は採らない。
  `bind:open` は component が mount されていないと `open=false` に戻せないため、
  「open=true なのに何も描画されない」= **本批で潰そうとしているのと同じ species の
  無言の行き止まり**を 6 画面ぶん新規に作る。component を入力に対し全域にする方が安全。
- 対応内容: null 時は明示的な取得失敗メッセージ + 回復導線 (再読み込み案内) + キャンセルを出す
  (空表示にも「非対応」文言にもしない)。施策 1-b により通常経路で null は入らない旨と、
  JS テスト 3 ケース (初期 null / 取得失敗 / 取得後に手段が出る) を概念設計に明記した。
  「取得中の連打」は 2 回目の `withRecentAuth` が同じ status で同じモーダルを開くだけで
  pending action も上書きのみ (多重実行にならない) ため、新たな機構は入れない。

## [Critical] call-site 固定はサーバ・クライアント間の shape 一致を保証しない (観点 7)
- 判断: 対応する (Critical 1 と同一根)
- 根拠: 同上。
- 対応内容: 施策 1-b の contract テスト 2 枚で「PHP Resource の shape ↔ TS `RecentAuthStatus`」を噛み合わせる。
  なお `RecentAuthStatusDto` / `RecentAuthProviderDto` は既に全プロパティ非 nullable の
  `final readonly` DTO で、Resource の array shape も PHPDoc で固定され PHPStan level 10 を通っている
  (本批で型定義の変更は不要)。`AvailableReauthProvider` の discriminated union 不要は同意を得た。

## [Warning] `apply()` と transaction の関係が曖昧 (観点 3)
- 判断: 対応する
- 根拠: 妥当。
- 対応内容: 公開 API 2 本 (`setInitial` / `change`) を transaction 境界とし、`apply()` は
  「transaction 内でのみ呼ばれる private 処理」と明記。`setInitial()` は lock 取得後の
  User インスタンスをそのまま `apply()` に渡す。DB session 行削除は現在セッション ID を除外する
  (既存 `deleteOtherSessionRecords()` の移設) ことも明記した。

## [Warning] recent-auth 未成立時の「XHR は 409 相当」が曖昧 (観点 5)
- 判断: 対応する
- 根拠: 妥当 (筆者の記述が不正確だった)。
- 対応内容: 受入条件表を実装の事実に合わせて修正 —
  `RequireRecentAuth` は **Inertia の非 GET / `expectsJson()` に 409 + `RecentAuthRequiredResource`
  (`{code, message, redirect}` + `Cache-Control: no-store`)**、通常遷移には 302 + `url.intended` 保持。
  本 route は Inertia POST なので前者。Feature テストで固定する (詳細設計のテスト計画へ)。

## [Suggestion] F-4 / F-7 の切り離し可能性 (観点 6)
- 判断: 対応済み (Round 1 で反映)
- 対応内容: 追加変更なし。
