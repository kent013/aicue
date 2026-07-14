【アプリの使命 (North Star) — AGENTS.md より】

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。
- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(tebiki)と異なり、標準作業を起点に AI が教材設計し撮影を指示する。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置(SECI)。

【禁止事項 — AGENTS.md より】
1. テストなしの実装完了報告
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する)

【思考原則 — 全議論に適用】
まず仮説を立てろ。データに真摯に向き合え。先人の知恵(Laravel/Svelte)を探せ。機能の名前に立ち返れ。仕組みが機能していない段階で値を弄るな。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. 型安全性: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

【補足コンテキスト (既存コードの事実)】
- `AccountController::destroy` は現状 `Auth::logout()` 後に `DB::transaction(fn) => $user->delete())` するのみ。ownership 検査なし。
- `OrganizationMembershipService` が Owner 保護不変条件の唯一の窓口。`changeRole` は「最後のオーナーは降格できません」、`removeMember` は「オーナーは削除できません」を `ValidationException` で拒否。private `hasAnotherOwner(org, target)` を持つ。
- Owner 昇格の唯一の正規経路は `transferOwnership`（既存 Owner→別メンバー、recent-auth 必須）。Owner 0 人になると昇格経路が断たれる。
- 全ユーザーは登録時に個人組織 (`is_personal=true`) の唯一 Owner になる（`provisionPersonalOrganization`）。
- `GET /settings` は現状 props 無しの route closure で `Inertia::render('Settings/Index')`。
- `Settings/Index.svelte` の DangerZone がアカウント削除ボタン + ConfirmDialog を持つ。削除は `router.delete('/settings/account')`（recent-auth precheck 付き）。
- Inertia props はプレーン配列で渡すのが既存慣習（JsonResource は REST API 用）。

---

## 概念設計

# 概念設計: sole-owner-delete-guard

## 背景・課題

bug-hunt finding F-H5 (High, broken_flow)。組織の唯一の Owner がアカウント削除 (`DELETE /settings/account`) を実行すると、現状 `AccountController::destroy` は無条件に `$user->delete()` するだけで、「その組織が Owner 不在になる」ことを検出・警告・ブロックしない。削除後は FK cascade で組織が Owner 0 人のまま残存メンバーごと取り残される。Owner 昇格の正規経路は `transferOwnership` のみだが、Owner が 0 人だと誰も新 Owner を作れず、恒久的に管理不能になる。メンバー削除・ロール降格には既に Owner 保護ガードがあるのに、アカウント削除経路だけが素通りしている非対称バグ。

## 改善アイデア

方針は (a) サーバー側ブロック + (b) 事前警告・移譲導線の併用:
1. サーバー側ブロック: `AccountController::destroy` が「削除対象ユーザーが唯一 Owner かつ他に残存メンバーがいる組織」を 1 つでも持つ場合、`ValidationException` で削除を拒否。
2. 事前警告・移譲導線: `/settings` に該当組織一覧(name+slug)を props で渡し、DangerZone に警告 + 各組織 settings への移譲リンクを表示。

### 判定述語（最重要）
全ユーザーは個人組織の唯一 Owner のため「唯一 Owner の組織を持つならブロック」は全削除を不能にする致命的誤り。正しい述語 = ユーザーが Owner かつ 他 Owner 無し かつ 他に 1 人以上メンバーが残る組織。個人組織のように唯一メンバーなら削除で誰も孤児化しない→許可（is_personal を特別扱いせずメンバー数で一様判定）。残存が Admin だけでも Owner 昇格経路が断たれるため役割問わずブロック。

## 期待効果
- 使命への貢献: 組織が Owner 不在で管理不能になるとメンバー招待・課金・権限管理が停止し現場のマニュアル運用が破綻する。組織運用の可用性を守る。
- 唯一 Owner 誤削除による組織ロックを 0 件に。既存 Owner 保護不変条件と挙動を対称化。削除前に「オーナー移譲すべき」を提示し詰みを回避。

## 実装方針（概要）
- Service: `OrganizationMembershipService::organizationsBlockingDeletion(User): Collection<Organization>` を追加。private `hasAnotherOwner` 再利用。
- Controller: `AccountController::destroy` にサービス注入、削除前に非空なら `ValidationException`。
- Route/Props: `GET /settings` closure にサービス注入、`soleOwnedOrganizations`(name+slug) を渡す。
- UI: DangerZone に警告 + 移譲導線。削除ボタンは disabled にしない（禁止事項8。押下時にサーバーがエラー表示）。

## 制約・前提
- `response()->json()` なし。PHPStan L10。テスト必須。Inertia props はプレーン配列。Owner 判定は laratrust_team_id 明示の既存 `organizationRole` 経由。`transferOwnership`(recent-auth 必須) をそのまま利用。旧無条件削除は消す。

## スコープ外
- 移譲 UI 改修 / 自動移譲・自動解散 / 組織削除フロー / 既存破損組織のデータ修復 / 招待のみ組織の厳密化。
