# 対応マトリクス: conceptual-review Round 4

## [Warning] 施策 1-c の方式が二択のまま (観点 3)
- 判断: 対応する
- 根拠: 妥当。汎用 axios interceptor は Inertia 内部通信への配線が保証されない。
- 対応内容: **`router.on("invalid", handler)` に確定**。受入条件を満たしたときだけ
  `event.preventDefault()` して `router.visit(...)` し、対象外は preventDefault せず
  Inertia 既定処理へ渡す。登録はアプリ初期化 1 箇所で 1 回だけ (1 回であることもテストする)。

## [Warning] `code` 一致だけで `redirect` へ遷移するのはナビゲーション境界として検証不足 (観点 5)
- 判断: 対応する
- 根拠: 妥当。単一ハンドラはアプリ全体の 409 を受けるため、サーバ由来 URL を無検証で
  グローバル遷移に使うのは fail-closed でない。
- 対応内容: 受入条件を 4 項目に明文化 (409 / `code` 厳格一致 / `redirect` が string /
  same-origin かつ pathname が recent-auth confirm の既知 path と一致)。
  外部 URL・別 route・`redirect` 欠損・他の 409 code・409 以外 の各ケースで
  遷移せず既定処理へ渡すことをテストで固定する。
