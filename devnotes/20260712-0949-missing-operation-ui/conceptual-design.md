# 概念設計: missing-operation-ui (F-10 リカバリコード再生成 / F-12 オーナー移譲)

## 背景・課題

bug-hunt 初回走行 (devnotes/20260712-075854-bug-hunt/shard-0/shard-report.md) で
「operations.md に定義があるのに UI から実行できない操作」が 2 件 (いずれも Medium) 報告された。

### F-10: `/settings/security` にリカバリコード再生成 UI が無い

- バックエンドは正常動作 (`POST /user/two-factor-recovery-codes` =
  Fortify の `two-factor.regenerate-recovery-codes`。bug-hunt が直 fetch で 200 を確認済み)。
- `resources/js/pages/Settings/Security.svelte` には「リカバリコードを表示」(GET) と
  「2要素認証を無効化」はあるが、**再生成ボタンが存在しない**。
- リカバリコードを紛失/使い切ったユーザーが自力で再発行できず、2FA ロックアウトの
  リスクがある (サポート対応が必要になる)。

### F-12: 組織設定画面にオーナー移譲 UI が「無い」→ 実際は「条件付きで丸ごと隠れる」

コード調査の結果、**F-12 は部分的な誤検知**と判明した:

- `resources/js/pages/Organizations/Settings.svelte` L230-268 に オーナー移譲の
  DangerZone (移譲先 select + 確認ダイアログ + recent-auth precheck) が**既に実装済み**
  (T006 コミット `06a692d` で導入。bug-hunt 走行コミットにも含まれる。
  Vitest `tests/js/pages/OrganizationsSettings.test.ts` にも描画テストあり)。
- ただし表示条件が `{#if isOwner && transferCandidates.length > 0}` であり、
  **組織に自分以外のメンバーがいないと、セクションごと何の説明もなく消える**。
  bug-hunt の走行時は自分以外のメンバーがいない組織の設定画面を見たため
  「UI が一切存在しない」と観測された (shard-report 自身も「誤検知の可能性もあるため
  要確認性が残る」と注記している)。
- これは「必須条件未充足を理由に操作 UI を無言で隠す」パターンで、
  AGENTS.md 禁止事項 8 (disabled 禁止 = 未充足は押下時にエラー/説明を出す) の精神に反する。
  移譲したいオーナーは「この製品にはオーナー移譲機能が無い」と誤認する。

## 改善アイデア

**スコープは UI のみ。バックエンド (route / controller / Policy / Service) は一切変更しない。**

### 施策 1 (F-10): Settings/Security.svelte にリカバリコード再生成導線を追加

2FA 有効時のリカバリコード表示ブロックに「リカバリコードを再生成」ボタンを追加する。

- 機微操作のため既存の「2FA 無効化」と同じパターンで **ConfirmDialog を必ず経由**する。
  ダイアログ本文に「**既存のリカバリコードは直ちにすべて失効します**」を明記し、
  自己ロックアウト誘発 (旧コードをメモしたまま気付かない) を防ぐ。
- 確認後に Inertia `router.post("/user/two-factor-recovery-codes")` を実行
  (既存 Fortify route。新規バックエンドロジック不要)。
- **POST 成功 / GET 失敗の分岐を明示的に設計する** (誤案内防止):
  1. POST 確定時点で表示中の旧コードを即クリアし「再取得中」状態
     (既存 `loadingRecoveryCodes`) へ遷移させる (旧コード = 失効済みを画面に残さない)。
  2. `GET /user/two-factor-recovery-codes` (既存 `loadRecoveryCodes()`) が**成功したときのみ**
     成功トースト (`addToast("success", ...)`) を出し、新コード一覧へフォーカスを移して
     再保存を促す (一覧ブロックに `tabindex="-1"` + `focus()`)。
  3. GET 失敗時は「新しいコードの取得に失敗しました。旧コードは既に無効です。
     『リカバリコードを表示』で再取得してください。」の error トーストを出し、
     既存の「リカバリコードを表示」ボタン (= 再試行導線) が表示された状態に戻す。
  → bug-hunt H7 (操作の結果フィードバック欠如) の再発を作らない。
- ボタンは disabled にしない。処理中は既存パターンどおり `loading` を使う。
- recent-auth について: Fortify の 2FA 管理エンドポイントには現状 step-up が配線されて
  いない (config/fortify.php の `confirmPassword => false` + TODO(template) 参照)。
  バックエンド変更はスコープ外のため、本施策は既存の「2FA 無効化」と同じ
  「ConfirmDialog のみ」の水準に揃える (無効化より機微度が高い操作ではない)。
  step-up の後付け配線は既存 TODO(template) の課題として残す (本設計では触らない)。

### 施策 2 (F-12): Organizations/Settings.svelte のオーナー移譲セクションを常時表示に変更

表示条件から `transferCandidates.length > 0` を外し、**オーナーには常に
オーナー移譲 DangerZone を表示**する。

- 移譲候補が 0 人のとき: フォーム上部に案内文を表示。文言は製品内の実際の IA 名称に
  一致させる (「移譲先にできるメンバーがいません。先に**管理メニュー > ユーザー管理**から
  メンバーを招待してください。」+ `usersUrl` があれば同画面へのリンク。`usersUrl` が
  null になるのは owner では起きない — manageMembers は owner を含む — が、フォールバック
  文言も「メンバーを招待できる管理者に依頼してください」と具体化しておく)。
- ボタンは disabled にしない。ただし**成立し得ない destructive 操作を ConfirmDialog まで
  進めない**: submit ハンドラで候補 0 人 or 未選択なら ConfirmDialog を開かずに即
  `setError("user_id", ...)` でエラー表示し、エラー/案内文へ視線を戻す
  (候補 0 人時は「移譲先にできるメンバーがいません。先にメンバーを招待してください。」、
  未選択時は既存文言「移譲先のメンバーを選択してください。」)。ConfirmDialog が開くのは
  有効な移譲先が選択されているときのみ。
- 確認ダイアログ (`ConfirmDialog`) と recent-auth precheck (`withRecentAuth` +
  `RecentAuthModal`) は既存実装をそのまま使う (変更しない)。
- 非オーナーには従来どおり表示しない (`isOwner` 条件は維持。
  Policy `transferOwnership` = owner のみ、と整合)。

### 付随: bug-hunt インベントリへのフィードバック (ドキュメントのみ)

shard-report の「インベントリ修正提案」(operations.md に未実装フラグを付ける案) は
**不要** (UI は実在する) であることを詳細設計に明記し、実装フェーズの成果物に
finding への回答として記録する。operations.md 自体の変更は行わない。

## 期待効果

本件はコアの撮影フロー改善ではなく、**アカウント/組織運用の自己完結性向上**である。
現場チームがサポート介在なしに運用を継続できることが North Star (専門知識ゼロの現場が
自走する) への貢献点。

### 受け入れ条件 (成功の判定基準)

1. **F-10**: 2FA 有効ユーザーが `/settings/security` から確認ダイアログ経由で
   リカバリコードを再生成でき、成功時に新コード一覧と成功フィードバックが表示される
   (GET 失敗時も誤案内にならない)。
2. **F-12**: オーナーは移譲候補 0 人の組織でも「オーナー移譲機能が存在すること」と
   「次に何をすべきか (メンバー招待)」を組織設定画面から理解できる。
   有効な移譲先を選択したときのみ確認ダイアログ → (recent-auth) → 移譲が成立する。
3. 非権限者 (2FA 無効ユーザー / 非オーナー) には各 UI が表示されない
   (サーバ側 403/バリデーションは既存のまま)。

## 実装方針（概要）

| 対象 | 変更 |
|------|------|
| `resources/js/pages/Settings/Security.svelte` | 再生成ボタン + ConfirmDialog + 成功時の再読込/トースト追加 |
| `resources/js/pages/Organizations/Settings.svelte` | DangerZone の表示条件変更 + 候補 0 人時の案内文/押下時エラー |
| `tests/js/pages/SettingsSecurity.test.ts` (新規) | 再生成導線の描画/ダイアログ/POST 発火/2FA 無効時非表示を Vitest で固定 |
| `tests/js/pages/OrganizationsSettings.test.ts` (更新) | 候補 0 人でもセクション表示 + 案内文、非オーナー非表示の回帰テスト追加 |

- バックエンド変更なし → Pest/PHPStan への影響なし (既存
  `tests/Feature/Organization/OwnershipTransferTest.php` が認可 403 を担保済み)。
- **フロント側の型は詳細設計で明示する**: `loadRecoveryCodes()` の応答型 (`string[]`)、
  `transferCandidates` の要素型 (`Member { id: number; name: string }` — 既存 Props 再利用)、
  選択中 ID の型 (select binding 由来の `string`)、`setError` に渡すキー
  (`"user_id"` — `useForm` の data キーに閉じる)。既存 `SharedProps` /
  Props interface を再利用し、新規型定義は増やさない。
- DS token / atoms (Button, Card, TextLink) / molecules (FormField, DangerZone) /
  organisms (ConfirmDialog, RecentAuthModal) の既存部品のみ使用。新規コンポーネントなし。
  アイコン追加なし (Lucide 制約に抵触しない)。

## 制約・前提

- **バックエンドは既存のまま**: `two-factor.regenerate-recovery-codes` (Fortify) /
  `organizations.transfer-ownership` (recent-auth middleware 付き) を再利用。
- AGENTS.md 禁止事項 8: disabled ボタン禁止 → 押下時エラー/案内で対応。
- DESIGN.md / ds-purity: token/ramp のみ。既存クラス構成を踏襲。
- atomic-import-graph: pages → templates/organisms/molecules/atoms の既存 import 方向のみ。
- Svelte 5 runes ($state/$derived) + Inertia v3 (useForm は plain object)。
- 検証: `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` green。

## スコープ外

- バックエンド変更全般 (route / controller / Policy / operations.md)。
- Fortify 2FA 管理エンドポイントへの recent-auth 後付け配線 (config/fortify.php の
  既存 TODO(template)。別課題)。
- F-11 (`/user/confirm-password` 直アクセス 500) の修正 (別 finding)。
- 2FA セットアップキー手動入力表示等の a11y 改善 (shard-report H14 注記。別課題)。
- TODO 登録・実装 (本 Phase は設計のみ)。
