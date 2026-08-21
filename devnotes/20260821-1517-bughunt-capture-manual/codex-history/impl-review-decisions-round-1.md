# 対応マトリクス: impl-review Round 1

Codex 全体判定: CHANGES_REQUESTED (Critical 0 / Warning 3 / Suggestion 2)。全て対応した。

## [Warning] SourceDocumentSummaryPropsTest: 別組織 PII の「現在表示中 manual への非混入」が未テスト
- 判断: 対応する
- 根拠: 設計は「(i) 現在閲覧できる manual の props へ別組織 SOP sentinel が混ざらない」と
  「(ii) 別組織 manual を直接 show すると 404」を**別境界**として要求している。Round1 実装は
  (ii) しか固定していなかった。
- 対応内容: 組織 B の owner が組織 B 自身の manual (SOP 未添付) を閲覧し、組織 A の manual に
  置いた sentinel SOP が props に出ないことを assert する test を追加。既存の 404 test も
  独立 test として残した (退行固定)。

## [Warning] CaptureShow.test.ts: Phase A 回帰が「共通 before-event emitter」でなく空振り余地あり
- 判断: 対応する
- 根拠: Round1 は reload と visit/get/post を独立に数え、負のコントロールが判定用純関数の
  直呼びだった。「programmaticCalls が 0 のまま green」「mock→event→判定の配線未検証」の指摘は妥当。
- 対応内容: router の全 programmatic 入口 (reload/visit/get/post) を 1 本の collector
  (`collectProgrammaticVisits`) に集約。通常フローで collector が最低 1 件 (現 URL reload) を
  捕まえること (母集団非空) を assert。負のコントロールは**実 mock 入口 (`router.visit/get/post`)
  へ禁止 destination を注入し、mock→collect→判定の配線ごと**検出することに変更。正例
  (`/app/...` は外部に載らない) も併置して空振りを防いだ。
- 補足 (保証範囲の明示): 実 Inertia `<Link>` / form helper は本 mock を通らない実 component の
  ため観測点外である旨を describe の docblock に明記した (AGENTS.md 走査規約 (b))。その唯一の
  /app 外 destination は意図的な PC 詳細リンク (T155) であり非発生を主張しない。

## [Warning] Phase A のネットワーク観測記録が diff に無い
- 判断: 対応する (記録を強化。ただし live 観測は実施しない旨を明示)
- 根拠: 「devnotes に記録」だけでは分類・X-Inertia-Location 実値・分岐判定が確認できないとの指摘は妥当。
- 対応内容: `phase-a-investigation.md` を全面的に強化した。(1) **調査手段=静的走査であり
  live Playwright 観測は本セッションで未実施**であることを明示、(2) 観測時に区別すべき分類基準
  (409 / Inertia::location / window.location・ハーネス / resourceType / X-Inertia-Location 実値) を
  枠組みとして記載、(3) 結論を分岐 **(c) 非再現・原因未確定** と明示し、**ハーネス起因とは断定しない**
  ことを明記、(4) 施策5 スキップ根拠を静的走査 (window.location 系 0 件・router.visit/get 不在) に
  紐づけた。設計 risk 節も非再現を許容している。

## [Suggestion] uploadedAt を既知 created_at の ISO 8601 値で比較
- 判断: 対応する
- 対応内容: Pest で created_at を既知値に固定し `analysis.document.uploadedAt` を
  `toIso8601String()` と完全一致で assert (存在確認だけにしない)。

## [Suggestion] ManualsShow.test.ts: 日時も assert
- 判断: 対応する
- 対応内容: `formatDateTime("2026-07-10T12:00:00+09:00")` の既知出力を含むことを assert に追加。
