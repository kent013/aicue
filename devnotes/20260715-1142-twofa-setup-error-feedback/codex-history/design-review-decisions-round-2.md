# 対応マトリクス: design-review Round 2

全体判定: CHANGES_REQUESTED (施策2 に Warning 1 件)。施策1 は APPROVE 維持。

## [Warning] (b) の onError 駆動は実装と矛盾 (confirmTwoFactor は post に onError を渡さない)
- 判断: 対応する
- 根拠: 正しい指摘。現行 confirmTwoFactor() は post options に onError を渡していないため、
  テストで options.onError を発火しても errors.code は更新されない。実物 Inertia は内部で errors を
  更新してから利用者 callback を呼ぶ。フェイクに errors 反映口を持たせるのが正しい。
- 対応内容: reactiveUseForm に `respondWithErrors(next)` (= Object.assign(errors, next)) を公開し、
  テスト (b) は submit 後に respondWithErrors({ code }) を呼んで表示を検証する方式へ変更。
  「options.onError 直接発火」記述を削除。責務分離 (a)=visit option / (b)=errors→UI 表示 /
  (c)=成功後遷移 を明文化。reset/processing getter 化/成功パス駆動は Codex から問題なしと確認済み。

次ラウンド: 上記反映で施策2 の APPROVE と全体 APPROVED を確認する。
</content>
