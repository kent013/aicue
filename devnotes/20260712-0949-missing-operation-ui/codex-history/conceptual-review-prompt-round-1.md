# 概念設計レビュー依頼 (Round 1): missing-operation-ui

【アプリの使命 (North Star)】
**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項 (AGENTS.md)】
1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

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
7. **型安全性**: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

【補足コンテキスト】
- 本設計は bug-hunt (LLM 探索的バグハント) の finding F-10 / F-12 (いずれも Medium、
  「operations.md に定義があるのに UI が無い操作」) への対応。
- 参照可能な関連ファイル (read-only):
  - devnotes/20260712-075854-bug-hunt/shard-0/shard-report.md (F-10 / F-12 の節)
  - resources/js/pages/Settings/Security.svelte (F-10 対象)
  - resources/js/pages/Organizations/Settings.svelte (F-12 対象。オーナー移譲 UI が既に存在)
  - app/Http/Controllers/Organizations/OrganizationOwnershipController.php
  - routes/web.php (organizations.transfer-ownership は recent-auth middleware 付き)
  - tests/js/pages/OrganizationsSettings.test.ts

---

## 概念設計

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

- 機微操作のため既存の「2FA 無効化」と同じパターンで **ConfirmDialog を必ず経由**する
  (「古いリカバリコードはすべて無効になります」と明示)。
- 確認後に Inertia `router.post("/user/two-factor-recovery-codes")` を実行
  (既存 Fortify route。新規バックエンドロジック不要)。
- 成功時: `GET /user/two-factor-recovery-codes` (既存 `loadRecoveryCodes()`) で
  **新しいコードを即座に画面表示** + 成功トースト (`addToast("success", ...)`)。
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

- 移譲候補が 0 人のとき: フォーム上部に案内文を表示
  (「移譲先にできるメンバーがいません。先に管理メニューのユーザー管理からメンバーを
  招待してください。」+ `usersUrl` があればリンク)。
- ボタンは disabled にしない。候補 0 人 or 未選択のまま押下した場合は
  既存の `setError` 経路でエラーメッセージを表示する
  (候補 0 人時は「移譲先にできるメンバーがいません…」、未選択時は既存文言)。
- 確認ダイアログ (`ConfirmDialog`) と recent-auth precheck (`withRecentAuth` +
  `RecentAuthModal`) は既存実装をそのまま使う (変更しない)。
- 非オーナーには従来どおり表示しない (`isOwner` 条件は維持。
  Policy `transferOwnership` = owner のみ、と整合)。

### 付随: bug-hunt インベントリへのフィードバック (ドキュメントのみ)

shard-report の「インベントリ修正提案」(operations.md に未実装フラグを付ける案) は
**不要** (UI は実在する) であることを詳細設計に明記し、実装フェーズの成果物に
finding への回答として記録する。operations.md 自体の変更は行わない。

## 期待効果

- **使命への貢献**: 現場チームの運用継続性を守る。リカバリコード紛失による 2FA
  ロックアウト (サポート依存) と、退職/異動時にオーナー交代できない詰みを解消し、
  「専門知識ゼロの現場」でもアカウント/組織のライフサイクルを自己完結できる。
- F-10: リカバリコードの再発行がユーザー自身で完結し、新コードが即時表示される。
- F-12: オーナー移譲機能の発見可能性が回復する (メンバー 0 人でも「何をすれば
  移譲できるか」が画面から分かる)。
- bug-hunt Medium finding 2 件のクローズ。

## 実装方針（概要）

| 対象 | 変更 |
|------|------|
| `resources/js/pages/Settings/Security.svelte` | 再生成ボタン + ConfirmDialog + 成功時の再読込/トースト追加 |
| `resources/js/pages/Organizations/Settings.svelte` | DangerZone の表示条件変更 + 候補 0 人時の案内文/押下時エラー |
| `tests/js/pages/SettingsSecurity.test.ts` (新規) | 再生成導線の描画/ダイアログ/POST 発火/2FA 無効時非表示を Vitest で固定 |
| `tests/js/pages/OrganizationsSettings.test.ts` (更新) | 候補 0 人でもセクション表示 + 案内文、非オーナー非表示の回帰テスト追加 |

- バックエンド変更なし → Pest/PHPStan への影響なし (既存
  `tests/Feature/Organization/OwnershipTransferTest.php` が認可 403 を担保済み)。
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
