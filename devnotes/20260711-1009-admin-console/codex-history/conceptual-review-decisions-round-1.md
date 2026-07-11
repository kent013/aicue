# 対応マトリクス: conceptual-review Round 1

## [Critical] D2 の fail-soft（project 不在なら org 参加のみ）は UI 表示と実効権限が乖離する
- 判断: 対応する
- 根拠: 指摘どおり。org プロビジョニングは project を自動生成しない（`OrganizationProvisioningService` を確認）ため「編集者を選んだのに権限が付かない」状態が実際に発生しうる。
- 対応内容:
  - 編集者/撮影者の招待・ロール変更は **Default Project 存在を必須条件**にし、不在なら 422（押下時エラー方針と整合）。管理者は project 不要。
  - 受諾時 race（招待後に project 削除）は「**未割当**」という明示の表示状態（第一級の状態）に落とす。silent degrade を廃止し、管理画面で未割当が見える + 再割当できる。

## [Critical] 既存データ（org role / pivot / 旧 pending invitation）の canonical mapping が未定義
- 判断: 対応する
- 根拠: 表示と実効権限の一貫性は本設計の中核。
- 対応内容: 概念設計 D2 に「表示状態の導出テーブル（owner / admin / editor / shooter / **unassigned** の 5 状態。毎リクエスト導出 = backfill 不要）」と「各コマンドの最終状態表（stale pivot 掃除を含む）」を明記。旧 pending invitation（project_role なし）は従来どおり org role で受諾され、Member は「未割当」として入る（未割当が UI にあるため整合）。org からのメンバー削除時に org 配下 project pivot を detach する掃除規則も追加（現状 `removeMember` は pivot を残す）。

## [Critical] AdminConsoleRole を保存概念でなく「正規状態へのコマンド」として遷移を厳密定義せよ
- 判断: 対応する
- 根拠: 権限ドリフト（表示は撮影者なのに pivot 未付与、管理者降格時の stale pivot）の構造的防止に必要。
- 対応内容: D2 を「AdminConsoleRole = 遷移コマンド enum（admin/editor/shooter）」+「表示状態 = 導出（5 状態）」の 2 層に再定義し、コマンドごとの最終状態（org role / pivot）を表で固定。遷移テスト（editor⇄shooter⇄admin、admin 降格時の pivot 掃除、未割当→割当）を要件に追加。

## [Warning] `orderBy('projects.id')->first()` は Default Project 規約として弱い
- 判断: 対応する
- 根拠: 複数 project は既存 UI（projects.create）で今日でも作れる。解決規約の散在は将来の変更点を増やす。
- 対応内容: `DefaultProjectResolver`（専用 Service）へ一本化し、`CaptureManualController::home` も同 resolver に寄せる。`default_project_id` カラムは v1 では追加しない（単一 Default Project 前提のうちはオーバーエンジニアリング。resolver 一本化により複数 project 化時の変更点が 1 箇所になる）。resolver の挙動テストを追加。

## [Warning] 旧 UI 撤去を同一 PR の完了条件として明示せよ
- 判断: 対応する
- 対応内容: 実装方針に「Projects/Show のカテゴリ CRUD UI・Settings のメンバー管理 UI の削除を同一 PR の完了条件」+「重複導線を残さない回帰テスト（Vitest）」を明記。

## [Warning] `organizations.members.update` の契約変更は既存 caller・テスト資産へ波及する
- 判断: 対応する
- 対応内容: 詳細設計の波及変更チェックで caller 棚卸し（Organizations/Settings.svelte のロール select は Admin/Users へ移設して消滅、既存 Feature テストの role 値更新）を必須項目化。

## [Warning] スコープが広い。フェーズを切れ
- 判断: 対応する（設計は 1 本、実装スライスを分離）
- 対応内容: 実装方針を 3 スライスに分割（A: ロール正規化 + 招待/受諾 + resolver のバックエンド、B: ユーザー管理画面 + Settings スリム化、C: カテゴリ管理画面 + Show 移設）。C は A/B と独立して実装可能なことを明記。

## [Warning] typed array だけでは PHP 側の契約が固定されない。DTO/FormRequest+Enum を明示せよ
- 判断: 対応する
- 対応内容: 新画面 props は PHP 側 DTO（`App\DataTransferObjects\Admin\*`。Capture ドメインの既存 DTO パターンに合わせる）、書き込みは FormRequest + `Rule::enum(AdminConsoleRole)`、`organization_invitations.project_role` は `ProjectRole` enum cast まで設計に含める。

## [Suggestion] AdminConsoleRole の assignable / displayed 分離
- 判断: 対応する（上記 Critical 対応に内包）

## [Suggestion] D1 は「危険な旧モック方式の排除」であることを期待効果に明記
- 判断: 対応する

## [Suggestion] カテゴリ側は低リスクなので優先順位を分ける
- 判断: 対応する（スライス C として独立化）
