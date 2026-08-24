---
id: S7
title: 認可境界 (IDOR) — AI-CUE ドメイン横断
surface: authz_boundary
lane: parallel_browser
priority: P1
applicability: applicable
depends_on: [S3]
reseed_before: false
accounts: [owner, member]
setup: [組織 A と組織 B の 2 アカウントを別 cookie セッションで用意する, S3 実行後の状態 (A 側の manual/cut/take/category/render) を残したまま始める]
covers_screens: [capture.manuals.show, capture.takes.playback, projects.categories.index, projects.edit, projects.manuals.download, projects.manuals.edit, projects.manuals.jobs.show, projects.manuals.render-jobs.playback, projects.manuals.render-jobs.show, projects.manuals.show, projects.show]
covers_operations: [capture.takes.adopt, capture.takes.destroy, projects.categories.destroy, projects.categories.reorder, projects.categories.update, projects.manuals.destroy, projects.manuals.duplicate, projects.manuals.scenario.update, projects.manuals.update]
covers_capabilities: [CAP-01, CAP-03, CAP-04, PROJ-03, REN-03, REN-04, SCEN-02, SCEN-03, SCEN-05, SOP-01, SOP-04, SOP-05]
---

# S7: 認可境界 (IDOR) — AI-CUE ドメイン横断

## 目的
組織 A/B・プロジェクト・ロール(編集者/撮影者)を跨いだ read/write が認可より前に 404/403 で弾かれるか(セキュリティ不変条件: 子は親に属する・cross-org 不可・tenant キー不信)。nested route の子解決が親 relation 経由で、越境は 403 でなく 404(存在を漏らさない)であること。存在オラクル(422/404 差分)が無いこと。A に S3 で作った manual/cut/take/category/render があり、B からは何も見えてはならない。

**画面側は「新規消化」ではなく B 視点での再走査**である。上の `covers_screens` 11 件は
いずれも S3 / S4 が既に消化しているものを、越境で 404 になることの確認として踏み直す
(1 route を複数カードが挙げてよい。目録のセルは `S3 S7` のように並ぶ)。

## 手順
1. B のログインで、A の URL を直叩き: `projects.show`/`projects.manuals.show`/`projects.manuals.edit`/`projects.manuals.jobs.show`/`projects.manuals.render-jobs.show`/`capture.manuals.show` → いずれも 404(403 でも Blade エラーでもなく)。
2. B から A の manual への書き込み: `projects.manuals.update`/`destroy`/`scenario.update`/`analyze`/`render`/`preview`/`source-documents.store` → 404。
3. B から A の category: `projects.categories.update`/`destroy`/`reorder` → 404。同名 category を A/B 別々に作れる(project スコープ unique)が、B の reorder に A の category id を混ぜると 422/404 の差分オラクルにならない。
4. 撮影面(PWA): B から A の `capture.takes.*`(store/adopt/update/destroy/upload-url/downloaded/playback) → 404。
5. cross-cut 採用: A 内で cut X のテイクを cut Y の adopt に渡す → 404(cut->takes() 経由解決)。
6. 撮影者(project_member)ロールで編集者専用操作(manuals.store/update/destroy, categories.*, analyze, render, manage.users)→ 403。編集者は撮影者専用でない全操作可。
7. tenant/protected キーを payload に混入(project_id/created_by/category_id/parent_cut_id/adopted_take_id/ticket_reservation_id/video_manual_id/cut_id)→ 422(ProhibitsProtectedKeys)。`category` 別名は許容、`category_id` 直送は 422。

## 逸脱アイデア (--deviate 時)
- 隣接 ID 総当り(manual/cut/take/category/render-job の id を ±1)→ 他組織・他プロジェクトのリソースに到達できないか。
- 署名 URL(download/playback)の manual/lang を差し替え → 他 manual の完成動画が取れないか。
- upload 署名チケットの cut_id を別 cut に差し替え → HeadObject 照合で拒否されるか。
- 存在オラクル: 実在する他組織 project id と実在しない id で、エラー種別・レスポンス時間に差が出ないか。
