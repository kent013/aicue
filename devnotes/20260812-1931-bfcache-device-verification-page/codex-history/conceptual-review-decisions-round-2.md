# 対応マトリクス: conceptual-review Round 2

## [Critical] 真理値表の `pagehide`/`pageshow` の説明が不正確 (§3)
- 判断: **対応する**
- 根拠: 指摘が正しい。`pagehide` は bfcache 格納時だけでなく通常の離脱でも発火し、
  `pageshow` は初回表示でも発火する。「発火あり = 凍結・復帰した証拠」は誤りで、
  正しくは「**full-document lifecycle を通った証拠**」でしかない。
  当初の表はこの 2 つを混同していた。
- 対応内容:
  - 観測 1 の意味を「full-document navigation の lifecycle を通ったか」に修正
  - **`pagehide.persisted` も記録**する（離脱時のブラウザ申告）
  - **初回 `pageshow` は判定対象外**として明示
  - `pagehide.persisted` と `pageshow.persisted` の不一致を「観測矛盾」に加えた
  - 判定は「同一試行 ID に属する離脱と復帰の組」に対して行うと定義し直した

## [Critical] 「有効試行」と「guard の受入結果」を別軸にせよ (§3)
- 判断: **対応する（本レビューで最も重要な指摘）**
- 根拠: 当初の最終判定は「bfcache が成立したか」しか見ておらず、
  T085 の目的である「**真の復元時に guard が正しく振る舞ったか**」を判定していなかった。
  真の復元が起きても guard が `pending` で停止する・秘匿解除が早すぎる・
  MutationObserver の記録が空、といった受入失敗を PASS と読んでしまう。
  設計として明確な欠陥だったので全面的に受け入れる。
- 対応内容: 判定を三段構えに再設計した。
  1. **試行成立判定**: `valid-bfcache` / `invalid-not-bfcache` / `invalid-wrong-route` / `inconsistent`
  2. **guard 結果判定**: `authenticated-unhidden` / `unauthenticated-redirected` /
     `retry-hidden` / `failed-transition` / `not-observed`
  3. **総合判定**: 試行成立 **かつ** そのシナリオで期待した guard 結果に一致した場合のみ `PASS`
  - 「有効試行」を `PASS` と同義にしないことを明記した
  - 期待される guard 結果はシナリオ依存（ログアウト後の復元なら
    `unauthenticated-redirected`、ログイン維持のままの復元なら `authenticated-unhidden`）
    のため、**試行開始時にシナリオを宣言する**選択を画面に追加した。
    ページ側は利用者の意図を推測できないので、宣言させるのが正しい。

## [Warning] `/login` リダイレクト後の証跡回収が閉じていない (§3) / スクリーンショット 1 枚の保証 (§4)
- 判断: **対応する**
- 根拠: 指摘のとおり。再ログイン後に A を開き直すと新しい context token・初回 `pageshow`・
  新しい試行 ID が発生し、保存済み試行と混ざる。
  当初案の「debug login で入り直して読む」は回収経路として閉じていなかった。
- 対応内容:
  - sessionStorage の記録を**試行 ID ごとの immutable record** にする
  - **ページ読み込み時に自動で新規試行を開始しない**（自動開始が上書きの原因）。
    既定は「保存済み試行の表示」、新規試行は明示操作で開始する
  - 画面に **live observation / stored report のどちらかを表示**する
  - stored report では元試行 ID・元の復帰時刻・保存完了時刻を区別して出す
  - 証跡回収のための hard reload は新しい試行として数えない

## [Warning] B から A へ戻る履歴操作を固定せよ (§2)
- 判断: **対応する**
- 根拠: B で `router.post` logout を実行すると Inertia が履歴を積むため、
  1 回の「戻る」では A ではなく B に戻る。B 自身が復元されて guard に
  リダイレクトされる可能性もある。「戻る」を素朴に書くと手順が壊れる。
- 対応内容:
  - 「戻るで A」ではなく「**履歴上で A を選択して復帰**」と記述を改めた
  - iOS Safari と standalone それぞれで必要な操作を実機手順として固定する（詳細設計で確定）
  - **A と B を同一試行 ID で関連付け**、A 以外へ復帰した場合は無効試行とする
  - **B も local/debug + `auth` + `no-store` の範囲**に置く

## [Warning] context token のエントロピー / 「短縮ハッシュ」が不正確 (§5)
- 判断: **対応する**
- 根拠: 指摘のとおり。用途は秘匿ではなく前後の同一性確認であり、
  短くすると偶然一致を「Document 生存」と誤認する。「短縮ハッシュ」は用語として誤り。
- 対応内容:
  - token は `crypto.randomUUID()` で生成し、**比較には全値を使う**
  - **表示用の短縮とは明確に分ける**（短縮値で比較しない）
  - 付随して: `crypto.randomUUID()` は secure context 必須なので、
    利用できない環境では**沈黙で劣化させず、検証不能として明示的に失敗させる**。
    HTTPS 必須という既存の制約と整合し、平文 http で気づかず確認してしまう事故も同時に防ぐ

## [Warning] discriminated union に `trial-start` と `pagehide.persisted` が不足 (§7)
- 判断: **対応する**
- 対応内容:
  - union を `TrialStarted` / `PageHide` / `PageShow` / `GuardStateChanged` /
    `TrialVerdict` / `GuardVerdict` に拡張
  - 共通フィールド `schemaVersion` / `trialId` / `sequence` / `timestamp` を持たせる
  - **sessionStorage からの復元時は型 assertion で済ませず、allowlist と schemaVersion を
    検証し、不正なら破棄する**。これは `bfcache-guard.ts` の
    `readAuthenticatedFlag()` が採っている「shape を厳密判定し、崩れていたら
    判定不能に倒す」idiom と同じで、リポジトリ内に前例がある
  - 試行 ID はクライアント生成にできるため **Inertia props を持たない**構成にする
    （DTO を増やさない。指摘 §7 の後段を採用）

## [Suggestion] 専用 env フラグ不要の判断は受け入れ可（ただし条件付き） (§5)
- 判断: **条件をすべて受け入れる**
- 対応内容:
  - 本ページと B の全 route が既存 debug route block と `LocalOnly` の
    **両方に構造的に包含されることを architecture テストで固定**する（施策に追加）
  - **debug ページから実ユーザー情報を props に渡さない**（props 自体を持たない構成にしたので自動的に満たす）
  - トンネル運用規律を `docs/supported-browsers.md`（実機確認手順の正本）に残す

## [Suggestion] plain anchor = full navigation と仮定するな (§6)
- 判断: **対応する**
- 対応内容: 受入条件に以下を追加した。
  - **A で `pagehide` が観測されたこと**を必須条件にする（仮定ではなく観測で確かめる）
  - `performance.getEntriesByType('navigation')` は補助情報に留め、**主証拠にしない**
  - `target="_blank"` / download / 外部ブラウザ切替を使わない
  - **standalone から Safari UI へ脱出していないことを `display-mode` で検出**する

## [Suggestion] 使命への貢献の限定は適切 (§1) / スコープは適切 (§6) / 観測矛盾を要調査とするのは妥当 (§4)
- 判断: 追加対応なし（現状維持）
