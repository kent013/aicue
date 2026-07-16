# 対応マトリクス: design-review Round 1（REQUEST_CHANGES）

## S3 [Critical] arch テストの regex 誤検知/漏れ（svelte:component・条件レンダリング変形）
- 判断: 対応する
- 対応: 検査対象を「**通常の開始タグ利用**（`<Ident`）」に限定と明記。移植 primitive は全ページで通常タグ使用
  （`<svelte:component>` や動的コンポーネントは使わない）を前提とし、arch テストは resolved 識別子の通常開始タグ
  出現のみを検査する。動的レンダリング変形は対象外（利用側で使わないため発生しない）。

## S3 [Critical] padding={false} 禁止の import alias 時の識別子解決仕様
- 判断: 対応する
- 対応: PageContainer の **default import 識別子を capture**（alias 対応、PageContent 検出と同方式）。その識別子で
  `<Ident\b[^>]*\bpadding=\{false\}` を検査し fail。属性は開始タグ内（次の `>` まで）を対象。

## S3 [Warning] AdminMenuNav 不使用検査の責務混在
- 判断: 対応する（別テストに分離）
- 対応: layout 構造契約 = `tests/js/architecture/page-shell-structure.test.ts`（PageContainer/PageHeader(Section)/
  PageContent の利用 + padding={false} 禁止 + allowlist）。deprecated import = `tests/js/architecture/
  deprecated-imports.test.ts`（`AdminMenuNav` を pages が import しないことを検査。将来の廃止 import もここに追加）。

## 横断 [Warning] page-content-usage 名称/責務乖離
- 判断: 対応する
- 対応: T070 の `page-content-usage.test.ts` を `page-shell-structure.test.ts` に**リネーム**し責務を明確化
  （PageContainer/PageHeader/PageContent の shell 構造契約）。旧名は残さない。

## S4 [Critical] 「BE変更なし想定」の記述揺れ → PR 契約を固定
- 判断: 対応する（**FE のみ・BE 変更なし**に固定）
- 対応: 本 PR は **完全 FE のみ**。Inertia props の追加/削除・コントローラ変更は**行わない**。
  - Admin ページは AdminMenuNav を使わなくなるだけ。旧 `usersUrl`/`categoriesUrl` prop は BE から渡され続けても
    **害が無いためそのまま残す**（BE を触らない）。ページ側は該当 prop の消費をやめるのみ。
  - カテゴリ導線は Projects/Show の**既存 `canManage` prop**（= `can('update', $project)`）で出し分け。新規 prop
    追加なし。

## S4 [Warning] Projects/Show カテゴリリンク条件が閲覧権限と不一致の可能性
- 判断: 対応する（既存 canManage=update で出す。根拠明記）
- 根拠(サーバ確認済): categories ページ `/projects/{project}/categories` は `Gate::authorize('viewAny',
  [Category, project])`。旧 AdminMenuNav の categoriesUrl は `can('update', $project)` ゲートだった。
  `update ⊆ viewAny`（更新権限者は閲覧可）のため、**既存 `canManage`(=update) でリンクを出せば 403 は起きず、
  旧エントリポイントの条件を保存**できる（FE 完結）。viewAny だが update 不可のユーザーへの表示拡大は挙動変更
  （parity スコープ外）につき行わず、現行(update ゲート)を維持する。

## S4 [Suggestion] リンク位置固定
- 対応: Projects/Show の **primary actions セクション末尾**にカテゴリ管理リンクを固定配置。

## 横断 [Critical] テストファースト運用定義不足
- 判断: 対応する
- 対応: red→green チェックリストを明記:
  - S1: 各 primitive 単体テストを先に書き **red** 確認 → primitive 実装 → **green**。
  - S3: `page-shell-structure.test.ts` を先に置き **red**（24 ページ未移行で fail）→ 移行 → **green**。
    `deprecated-imports.test.ts` は AdminMenuNav 使用中は red → S4 撤去で green。

## 横断 [Suggestion] 24 ページ固定リスト添付
- 対応: 詳細設計に AppLayout 使用 24 ページの固定リストを添付（移行漏れ防止）。

## S1 [Warning] description truncate
- 判断: aigenba parity 優先で許容（aigenba と同一挙動）。長文説明の運用は parity 後に別途検討。

## S2 [Suggestion] app-main test contract 配置
- 対応: `<main>` に padding utility が付かないことは AppLayout.test で担保（AppLayout 側）と明記。
