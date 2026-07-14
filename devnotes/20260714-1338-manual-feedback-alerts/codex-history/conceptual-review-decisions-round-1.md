# 対応マトリクス: conceptual-review Round 1

## [Warning] F-1-2 単一 startError は render/preview の起動失敗を同時保持できない (観点3)
- 判断: 対応する
- 根拠: 妥当。後発の失敗が先発を上書きし、帰属問題を別形で残す。render と preview は独立操作なので
  状態も独立させるべき。
- 対応内容: `startError` を per-source の 2 state (`renderStartError` / `previewStartError`) へ分離。
  各々 `{ message, showPurchaseLink } | null`。概念設計を更新。

## [Warning] title「完成動画」「プレビュー」だけでは同一小節の 起動失敗 vs ジョブ失敗 を識別できない (観点4)
- 判断: 対応する
- 根拠: 妥当。同 source で start error と job error が併存し得るため、source だけでなく phase も見出しに
  含める必要がある。
- 対応内容: phase-aware な title に変更:
  - 完成動画: 起動失敗=「完成動画の生成を開始できませんでした」/ ジョブ失敗=「完成動画の生成に失敗しました」
  - プレビュー: 起動失敗=「プレビューの生成を開始できませんでした」/ ジョブ失敗=「プレビューの生成に失敗しました」

## [Warning] 型安全性: Record<StartSource, StartError|null> か明示 2 プロパティに (観点7)
- 判断: 対応する (上記分離で充足)
- 根拠: 状態空間を正しく表現する。strict TS 下で「表現できる型」と「正しい状態モデル」を分ける指摘は正当。
- 対応内容: 明示 2 プロパティ (`renderStartError` / `previewStartError`) を採用 (Record より参照が素直で
  既存コードの局所差分が小さい)。`StartError` 型を component 内に定義。

## [Suggestion] showPurchaseLink を source 別 state に載せる (観点5)
- 判断: 対応する
- 根拠: 上記分離に自然に畳み込める。共有のままだと preview 起動失敗の購入導線が render 側に出る回帰源。
- 対応内容: `showPurchaseLink` を各 start error object のフィールドに移す。

## [Suggestion] justSaved のクリア条件を明文化 (409 競合・別更新時) (観点5)
- 判断: 対応する
- 根拠: 古い成功表示が残ると安心表示が誤認を生む。ライフサイクルを明示すべき。
- 対応内容: `justSaved` のクリア条件を設計に明記:
  (1) dirty へ転じた瞬間 (編集), (2) 次の保存試行開始 (`save()` 冒頭), (3) 保存失敗 (`saveFailure` set),
  (4) 409 からの reseed / 明示リロード reseed。実質「reseed で true・それ以外の状態遷移で false」。

## [Suggestion] 「1 小節あたり赤 alert 最大 1」の保証が弱い (観点6)
- 判断: 対応する (主張を正す)
- 根拠: props 由来の job 失敗と local の start 失敗は併存し得るので「最大 1」は保証できない。
- 対応内容: 主張を「各 alert が source+phase の見出しを持ち帰属が一義」に修正。「最大 1」は削除。

## [Suggestion] 使命整合・禁止事項・F-1-1 再定義・契約維持は APPROVE 済み観点
- 判断: 見送る (変更不要)
- 根拠: Codex が妥当と認めた。維持。
