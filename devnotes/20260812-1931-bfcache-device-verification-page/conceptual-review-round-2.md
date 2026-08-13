全体判定: **CHANGES_REQUESTED**

Round 1 の主要指摘には概ね適切に対応できています。専用 env フラグを追加しない判断も、既存の route 登録ゲート、`LocalOnly`、資格情報未設定時の 404、`ProductionEnvGuard` を前提とする限り妥当です。

残る問題は、真理値表が「bfcache 復元の成立」と「guard の受入結果」を一つの判定にまとめていること、およびログアウト後の証跡回収経路がまだ閉じていないことです。

## 1. 使命との整合性

[Suggestion] 使命への貢献の限定は適切です。

これは動画作成能力を直接増やす機能ではなく、撮影 PWA 利用後の PII 非露出を検証する設備です。「撮影導線の安全性・信頼性への間接貢献」とした修正は妥当です。

## 2. 禁止事項違反

[Warning] 新しい logout 導線を作らない方針は正しいですが、相方ページ B から A に戻る履歴操作を詳細設計で固定する必要があります。

B で既存の `router.post` logout を実行した後、ログイン画面等への Inertia 遷移が履歴にどう積まれるかによっては、1 回の「戻る」で A ではなく B に戻ります。また、B が復元されて guard によってさらにリダイレクトされる可能性もあります。

修正提案:

- 「戻るで A」ではなく「履歴上で A を選択して復帰」と記述する。
- iOS Safari と standalone それぞれについて、実際に必要な戻る操作を実機手順で固定する。
- A と B を試行 ID で関連付け、A 以外へ戻った場合は無効試行とする。
- B も local/debug、`auth`、`no-store` の範囲に置く。

[Suggestion] 新規 JSON endpoint を作らず Inertia props に限定する方針は、禁止事項 4 に適合します。

## 3. 実現可能性

[Critical] 真理値表の `pagehide` / `pageshow` の説明が正確ではありません。

`pagehide` は bfcache に格納された場合だけでなく、通常の離脱でも発火します。`pageshow` も bfcache 復元だけでなく初回表示や通常の再取得で発火します。したがって、これらの「発火あり」は Document が「凍結・復帰した」証拠ではなく、full-document lifecycle を通ったことの証拠です。

また、初回ロード時の `pageshow(persisted=false)` と、離脱後の再取得による `pageshow(persisted=false)` を区別しなければなりません。

修正提案:

- 観測 1 の説明を「full-document navigation の lifecycle を通ったか」に変更する。
- `pagehide.persisted` も保存する。
- 判定対象を、同一試行 ID に属する次の組として定義する。

| 観測 | 真の bfcache 復元 | 通常の再取得 |
|---|---:|---:|
| 離脱時 `pagehide` | あり、原則 `persisted=true` | あり、通常 `persisted=false` |
| 復帰時 `pageshow` | `persisted=true` | `persisted=false` |
| token | 離脱前と同一 | 離脱前と異なる |

- 初回 `pageshow` は判定対象外として明示する。
- `pagehide.persisted` と `pageshow.persisted` の不一致も「観測矛盾」にする。

[Critical] 「有効試行」と「guard の受入結果」を別軸にしてください。

現行の最終判定は bfcache が成立したかしか判定していません。しかし T085 の目的は、真の bfcache 復元時に guard が PII を秘匿し、セッション再検証を行い、適切な終端へ到達することの確認です。

真の復元が起きても、MutationObserver の記録が空、`pending` のまま停止、秘匿解除が早すぎる、といった場合は受入失敗です。

修正提案:

1. 試行成立判定  
   `valid-bfcache` / `invalid-not-bfcache` / `invalid-wrong-route` / `inconsistent`

2. guard 結果判定  
   `authenticated-unhidden` / `unauthenticated-redirected` / `retry-hidden` / `failed-transition` / `not-observed`

3. 総合判定  
   試行成立かつ、期待した guard 遷移・秘匿結果を満たした場合のみ `PASS`

「有効試行」は `PASS` と同義にしないでください。

[Warning] `/login` へのリダイレクト後に「debug login で入り直して読む」だけでは、元試行の証跡を安定して回収できません。

再ログイン後に A を再表示すると、新しい context token、初回 `pageshow`、新しい試行 ID が発生し、保存済み試行と混ざる可能性があります。

修正提案:

- sessionStorage の記録を試行 ID ごとの immutable record とする。
- 新しい試行開始と既存試行の閲覧を分離する。
- A の再表示時は、未完了の既存試行を上書きせず「前回試行の結果」として表示する。
- 可能なら同じページに「新規試行開始」と「保存済み試行表示」の明確なモードを持たせる。新しい endpoint は不要です。

## 4. 期待効果の妥当性

[Warning] 「スクリーンショット 1 枚が証跡」は、`unauthenticated` と `retry` の場合にはまだ保証されていません。

秘匿中の A は撮影できず、ログイン後の再表示では別 Document になります。その画面が保存済み試行を明確に表示できる設計まで含めて、初めて 1 枚で足ります。

修正提案:

- スクリーンショットに「live observation」か「stored report」かを表示する。
- stored report では元試行 ID、元の復帰時刻、保存完了時刻を区別する。
- 証跡回収のための hard reload を、新しい試行として数えない。

[Suggestion] 観測矛盾を「要調査」とする判断は妥当です。ブラウザ差異を無効試行として捨てない設計は、本施策の目的に合っています。

## 5. リスク

[Warning] context token の「短縮ハッシュ」は、同一性判定に必要な衝突耐性を定義してください。

これは秘密情報の秘匿目的ではなく、前後の同一性確認です。短くしすぎると偶然一致を「Document 生存」と誤認します。

修正提案:

- `crypto.randomUUID()` 等で十分なエントロピーを持つ token を生成する。
- 保存値は最低でも 128 bit 相当を維持するか、実質的に衝突を無視できる長さを明記する。
- 「短縮ハッシュ」の表示用短縮と、内部比較値を分ける。

[Suggestion] 専用 env フラグを追加しない反論は受け入れ可能です。

本ページが既存 `/debug/login` と同一ゲートに属し、追加データや権限を提供せず、さらに `auth` を要求するなら、専用フラグは防御の本質的強化になりません。むしろ debug 経路の有効条件を二系統化します。

ただし、この判断は次の条件付きです。

- 本ページと B の全 route が既存 debug route block と `LocalOnly` の両方に構造的に包含される。
- その包含を route/Architecture テストで固定する。
- debug ページから実ユーザー情報を props に渡さない。
- トンネル運用規律を実機確認手順の正本に残す。

## 6. スコープの適切さ

[Suggestion] passkey、経路 C、自動化、新規 logout 導線を除外したスコープは適切です。

plain anchor による full document navigation も方向として正しいです。ただし「plain `<a>` だから必ず full navigation」と仮定せず、次を受入条件に含めるべきです。

- `pagehide` が A で観測されたこと。
- B で `performance.getEntriesByType('navigation')` 等を使う場合でも、判定の主証拠にはしないこと。
- `target="_blank"`、download、外部ブラウザへの切替を使わないこと。
- standalone から Safari UI へ脱出していないことを `display-mode` で検出すること。

## 7. 型安全性

[Warning] discriminated union に `trial-start` と `pagehide.persisted` が不足しています。

現状の `pagehide` / `pageshow` / `guard-state` / `verdict` だけでは、初回表示と復帰、保存済み試行と新規試行の境界を安全に表現しにくくなります。

修正提案:

```ts
type ProbeEvent =
    | TrialStartedEvent
    | PageHideEvent
    | PageShowEvent
    | GuardStateChangedEvent
    | TrialVerdictEvent
    | GuardVerdictEvent;
```

各イベントには共通して `schemaVersion`、`trialId`、`sequence`、`timestamp` を持たせ、イベント種別ごとに許可フィールドを固定してください。sessionStorage からの復元時は型 assertion だけで済ませず、allowlist と schema version を検証し、不正なら破棄する設計が必要です。

PHP 側は試行 ID をクライアントで生成できるため、必須でなければ DTO 自体を増やさず、props なしの Inertia ページでも成立します。サーバ生成が必要なら、型付き PageData DTO に限定すれば PHPStan level 10 上の問題はありません。

結論として、専用 env フラグへの反論と full document navigation の基本方針は承認できます。一方、真理値表を lifecycle の正確な意味へ修正し、「bfcache 成立」と「guard 合格」を二段階判定に分離すること、ログイン後も元試行を上書きせず証跡表示できることが承認条件です。