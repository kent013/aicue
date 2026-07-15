# S7: 認可境界 (IDOR) — AI-CUE ドメイン横断

> S3 実行後の状態を意図的に使う。組織 A/B・プロジェクト・ロール(編集者/撮影者)を跨いだ read/write が認可より前に 404/403 で弾かれるか(セキュリティ不変条件: 子は親に属する・cross-org 不可・tenant キー不信)。

- 前提状態: 組織 A/B の 2 ユーザー。A に S3 で作った manual/cut/take/category/render がある。B からは何も見えてはならない。
- 目的: nested route の子解決が親 relation 経由で、越境は 403 でなく 404(存在を漏らさない)であること。存在オラクル(422/404 差分)が無いこと。

## 手順
1. B のログインで、A の URL を直叩き: `projects.show`/`projects.manuals.show`/`projects.manuals.edit`/`projects.manuals.jobs.show`/`projects.manuals.render-jobs.show`/`capture.manuals.show` → いずれも 404(403 でも Blade エラーでもなく)。
2. B から A の manual への書き込み: `projects.manuals.update`/`destroy`/`scenario.update`/`analyze`/`render`/`preview`/`source-documents.store` → 404。
3. B から A の category: `projects.categories.update`/`destroy`/`reorder` → 404。同名 category を A/B 別々に作れる(project スコープ unique)が、B の reorder に A の category id を混ぜると 422/404 の差分オラクルにならない。
4. 撮影面(PWA): B から A の `capture.takes.*`(store/adopt/update/destroy/upload-url/downloaded/playback) → 404。
5. cross-cut 採用: A 内で cut X のテイクを cut Y の adopt に渡す → 404(cut->takes() 経由解決)。
6. 撮影者(project_member)ロールで編集者専用操作(manuals.store/update/destroy, categories.*, analyze, render, manage.users)→ 403。編集者は撮影者専用でない全操作可。
7. tenant/protected キーを payload に混入(project_id/created_by/category_id/parent_cut_id/adopted_take_id/ticket_reservation_id/video_manual_id/cut_id)→ 422(ProhibitsProtectedKeys)。`category` 別名は許容、`category_id` 直送は 422。

## このストーリーで消化する screens / operations
- screens: (S3/S4 の全 nested screen を B 視点で 404 確認。新規消化はしないが再走査)
- operations: projects.manuals.update, projects.manuals.destroy, projects.manuals.duplicate, projects.manuals.scenario.update, projects.categories.update, projects.categories.destroy, projects.categories.reorder, capture.takes.adopt, capture.takes.destroy(いずれも越境で 404)

## 逸脱アイデア (--deviate 時)
- 隣接 ID 総当り(manual/cut/take/category/render-job の id を ±1)→ 他組織・他プロジェクトのリソースに到達できないか。
- 署名 URL(download/playback)の manual/lang を差し替え → 他 manual の完成動画が取れないか。
- upload 署名チケットの cut_id を別 cut に差し替え → HeadObject 照合で拒否されるか。
- 存在オラクル: 実在する他組織 project id と実在しない id で、エラー種別・レスポンス時間に差が出ないか。
