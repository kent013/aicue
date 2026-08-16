# 対応マトリクス: conceptual-review Round 2

## [Warning] `ShootingGuideOverlay` の入力契約が本文と矛盾 (「`visible` だけを受ける」)

- 判断: **対応する** (指摘のとおり矛盾していた。Codex の推す「後者」を採る)
- 根拠: `GridOverlay` は表示する内容を持たない純粋な装飾なので `visible` だけで足りるが、
  撮影ガイドは**カットごとに変わる文字列**を表示する。同じ形に揃える、と書いたのは誤り。
  空文字列と非表示の 2 状態を子に持ち込むと、`SubtitleOverlay` が既に抱えている
  「`visible` かつ非空のときだけ描く」という内部判定を 1 つ増やすことになる。
  親 (`CameraRecorder`) は `layout === "fullscreen"` の判定を既に持っているので、
  表示可否の判定を親に集約するほうが状態の置き場所が減る。
- 対応内容: `ShootingGuideOverlay` の props を **`{ text: string }` の 1 つだけ**に確定し、
  「非空の `shooting_point` があり、かつ全画面のときだけ親が描画する」と明記した。
  実装方針の表から「`GridOverlay` と同じ `visible` だけを受ける薄い表示 component に揃える」
  という記述を削除した。あわせて `LayoutMode` union と props の型を概念設計に書いた。

## [Warning] 型安全性: props 未確定のため表示データの型契約を評価できない

- 判断: **対応する** (上と同一の修正で解消)
- 根拠: 同上。
- 対応内容: 概念設計に TypeScript の型契約を明示した
  (`LayoutMode` / `ShootingGuideOverlayProps` / `CutSwipeBar` の props)。
  サーバ側 DTO / JsonResource / PHPStan level 10 への影響が無いことは Codex も同意。

## [Suggestion] 面積の測定では「録画開始・停止操作が同時に viewport 内へ収まること」も受入値に含める

- 判断: **対応する**
- 根拠: 面積だけ増えても操作が折り返しの下に隠れていたら現行の問題は解けていない。
  「使命に沿った評価」という指摘は正しい。
- 対応内容: 期待効果の測定条件に、映像面積に加えて
  **「映像・カット名バー・録画開始/停止ボタンが同時に viewport 内へ収まる」**を追加した。

## [Suggestion] デスクトップの負のコントロールに「`pointer: coarse` 相当かつ高さ 540px 超」も加える

- 判断: **対応する**
- 根拠: 通常のデスクトップ context だけでは `pointer` 条件を実際に検証したことにならない
  (`max-height` 条件だけで落ちている可能性を排除できない = 空振りするテスト)。
  Playwright の context option (`hasTouch` / `isMobile`) で `pointer: coarse` 相当の
  context は作れるため、実現可能。
- 対応内容: テスト方針の Browser の項に、負のコントロールを 2 本
  (通常デスクトップ / タッチ対応かつ高さ 540px 超) にすると明記した。
  ハーネス側で `pointer: coarse` 相当の context を作れなかった場合の扱い
  (テストを緑にせず、実機受入確認の項目へ明示的に降ろす) も書いた。

## [Suggestion] 使命整合 / 禁止事項 / 実現可能性 / リスク / スコープの肯定的評価

- 判断: **見送る** (対応不要)
- 根拠: 指摘ではなく評価。
