---
name: app-design
description: アプリ改善の設計フロー（概念設計→Codexレビュー→詳細設計→Codexレビュー）。設計ファイル生成のみ、TODO登録は行わない
user-invocable: true
argument-hint: "<topic> [conceptual_design_path]  例: /app-design permission-refactor"
---

# 設計フロー（概念設計 → Codex合議 → 詳細設計 → Codex合議）

## 引数

| 引数 | 必須 | 説明 |
|------|------|------|
| topic | Yes | 設計トピック（ディレクトリ名に使用。例: permission-refactor, billing-api） |
| conceptual_design_path | No | 既存の概念設計ファイルのパス（リポジトリルートからの相対パス）。省略時は会話内容から概念設計を作成する |

改善アイデアの議論が終わった後、**概念設計 → Codex合議 → 詳細設計 → Codex合議** の順で設計を完成させ、TODOへの登録を案内する。

---

## 使命（North Star）— 絶対遵守

**`AGENTS.md` の「使命 (North Star)」セクションを読み、全設計判断の基準とする**（使命はAGENTS.mdが唯一の正本。本スキルにコピーを持たない）。

## 思考原則 — 全議論に適用

**まず仮説を立てろ。** 何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

**ユーザー視点で考えろ。** 技術的に正しいかだけでなく、その変更がエンドユーザーの体験にどう影響するかを常に問え。

**データに真摯に向き合え。** ユーザーフィードバック、利用状況、パフォーマンスメトリクス — 全てが判断材料になる。思い込みで機能を追加するな。

**先人の知恵を探せ。** Laravel/Svelteのエコシステムに既存の解決策があるなら使え。

**機能の名前に立ち返れ。** 名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

**仕組みが機能していない段階で値を弄るな。** UI微調整やパラメータチューニングは、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、設計そのものを見直せ。

## 禁止事項（全フェーズで厳守）

`AGENTS.md` の「禁止事項」セクションが正本。設計判断に直結する核は:

| # | 禁止事項 |
|---|---------|
| 1 | PHPStanのエラーを `@phpstan-ignore-line` や baseline で無視する |
| 2 | テストなしの実装完了（各施策にテスト必須） |
| 3 | 既存テストの削除・上書き |
| 4 | `response()->json()` の直接使用（DTO + JsonResource必須。仕様固定 endpoint のみ例外） |
| 5 | `DatabaseTransactions` の個別使用（`RefreshDatabase` は `tests/Pest.php` でグローバル適用済、テストは `--parallel` 実行） |
| 6 | やたらに複雑な案を提案する |

---

## 重要原則

- **全ての成果物は `devnotes/{YYYYMMDD-HHMM}-{topic}/` に保存**する
- **Codexとの合議は「全CriticalとWarningが解消されるまで」繰り返す**（最大5ラウンド）
- 概念設計レビューは **`gpt-5.4`**、詳細設計レビューは **`gpt-5.3-codex`** を使用

---

## Phase 1: 概念設計

### 1-1. 作業ディレクトリの作成

今日の日時と `topic` からディレクトリ名を決定する:
```bash
TZ=Asia/Tokyo date +%Y%m%d-%H%M
```

以降の全ファイルを以下に保存:
```
devnotes/{YYYYMMDD-HHMM}-{topic}/
```

### 1-2. 概念設計ファイルの準備

**`conceptual_design_path` が指定された場合**:
- `Read` ツールでそのファイルを読み込む
- 内容を `devnotes/{dir}/conceptual-design.md` にコピーして保存する

**`conceptual_design_path` が省略された場合**:
- 会話内容（ユーザーが説明した改善アイデア）をもとに概念設計を作成する
- 以下のフォーマットで `devnotes/{dir}/conceptual-design.md` を作成する:

```markdown
# 概念設計: {topic}

## 背景・課題
[なぜこの改善が必要か]

## 改善アイデア
[何をどう変えるか、改善の方向性]

## 期待効果
- [使命への貢献]
- [具体的な改善見込み]

## 実装方針（概要）
[どのコンポーネントを、どう変えるか。コード変更の概要]

## 制約・前提
[既存アーキテクチャとの整合性、依存関係、制約]

## スコープ外
[今回扱わないこと]
```

### 1-3. Codexによる概念設計レビュー

`app-codex-review` スキルの**セッションモード**に従い、プロンプトファイルを作成してCodexに概念設計のレビューを依頼する。

**model**: `gpt-5.4`
**reasoning**: `medium`
**label**: `conceptual-review`

**system**:
```
あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

（アプリの使命・禁止事項は app-codex-review スキルにより AGENTS.md から自動挿入済み）

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
```

**user**: `## 概念設計\n{conceptual-design.mdの内容}`

レビュー結果を保存:
```
devnotes/{dir}/conceptual-review-round-1.md
```

### 1-4. 概念設計レビュー合議ループ

Codexのレビューを精査し:

**セッション再開**: `app-codex-review` スキルのセッションモード（Round N）に従い、同じ SESSION_ID で `scripts/codex exec resume` を実行する。

1. **[Critical]** の指摘は**必ず対応**（概念設計を修正 or 根拠を添えて反論）
2. **[Warning]** の指摘は**対応を検討**
3. **[Suggestion]** は任意
4. **全体判定が APPROVED になるまで**繰り返す（最大5ラウンド）

各ラウンドの成果物は `devnotes/{dir}/` 配下に必ず残す（議論履歴をコミット対象に含める）:

| ファイル | 内容 |
|---------|------|
| `devnotes/{dir}/conceptual-review-round-{N}.md` | Codexの返答 |
| `devnotes/{dir}/codex-history/conceptual-review-prompt-round-{N}.md` | Codexに送ったプロンプト |
| `devnotes/{dir}/codex-history/conceptual-review-decisions-round-{N}.md` | Claude側の対応マトリクス（次ラウンド開始前に記録） |

### 1-5. ユーザー報告

```
## Phase 1 完了: 概念設計 APPROVED (Round {N})

### 概念設計サマリー
- 改善内容: [1-2行で]
- 期待効果: [箇条書き]
- スコープ: [変更コンポーネント]

→ Phase 2に進みます（詳細設計 & Codexレビュー）
```

---

## Phase 2: 詳細設計

### 2-1. 関連コードの読み込み

概念設計で示した変更対象ファイルを `Read` ツールで読み込み、現行コードを把握する。

### 2-2. 詳細設計書の作成

概念設計と現行コードをもとに、詳細設計書を作成する。

> **⚠ 波及変更チェック（必須）**: インターフェース変更（API、ルート、DTO、コンポーネントProps等）が発生する場合、その影響が及ぶファイルも**変更対象として施策に明示**すること。
> - TypeScript型定義
> - Inertia Propsインターフェース
> - API Resource / DTO
> - テストファイル

**詳細設計書には必ず以下のセクションを含める**:

```markdown
# 詳細設計: {topic}

## 使命・制約（絶対遵守）

### アプリの使命（North Star）
[AGENTS.md の「使命 (North Star)」セクションの内容をここに転記]

### 禁止事項
[AGENTS.md の「禁止事項」セクションの内容をここに転記]

### コーディングルール
- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest**テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 使用禁止）
- **テストデータは必ずFactoryで生成**（`Model::create()` 手組み禁止）
- 新モデルを追加する設計では **対応するFactoryの作成も施策に含める** こと
- **DTO + JsonResource** パターン（AGENTS.md参照）
- **アーリーリターン** 推奨
- **コードフォーマット**: `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript

## 概念設計リファレンス

[devnotes/{dir}/conceptual-design.md へのリンク]

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|

## {施策名}

### 変更箇所
- ファイル: `app/Services/xxx.php` (L100-120)

### 波及変更
<!-- インターフェース変更がある場合、影響するファイルを全て列挙 -->
- TypeScript型定義: [変更が必要な箇所、または「なし」]
- API Resource/DTO: [変更が必要な箇所、または「なし」]
- テストファイル: [変更が必要な箇所、または「なし」]

### 現行コード
```php
// 現在の実装
```

### 変更後コード
```php
// 変更後の実装
```

### PHPStan適合チェック
- [ ] 戻り値の型が明示されている
- [ ] null安全（`Webmozart\Assert\Assert` 使用）
- [ ] DTOを返している（配列返却なし）
- [ ] Genericsの型パラメータが正しい

### テスト計画
- [ ] バグ修正の場合: 再現テストを先に書く（Pest）
- [ ] 既存テスト `tests/Feature/xxx.php` の更新
- [ ] 新規テスト: {テスト名} — {検証内容}
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク
- {副作用・後退の可能性}

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | incremental / standalone |
| 判断根拠 | [なぜそのモードか] |
| 競合リスク | [他施策との干渉可能性] |
```

保存先:
```
devnotes/{dir}/detailed-design.md
```

### 2-3. Codexによる詳細設計レビュー

`app-codex-review` スキルの**セッションモード**に従い、プロンプトファイルを作成してCodexにレビューを依頼する。

**model**: `gpt-5.3-codex`
**reasoning**: `high`
**label**: `design-review`

**system**:
```
あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

（アプリの使命・禁止事項は app-codex-review スキルにより AGENTS.md から自動挿入済み）

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10
- Pestテストフレームワーク
- DTO + JsonResource パターン
- Laratrust RBAC（Organization → Team → Project階層）

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null安全性）
2. 既存コードとの整合性（命名規約、パターン、API）
3. PHPStan level 10 適合性（型安全性、generics、Assert使用）
4. テスト計画の網羅性（各施策にPestテスト、RefreshDatabaseグローバル適用に従う）
5. DTO/JsonResource パターンの遵守
6. Inertia Props vs API Responseの使い分け
7. 副作用・後退リスク
8. 波及変更の網羅性（TypeScript型定義、API Resource、テストが変更対象に含まれているか）
9. セキュリティ（認可チェック、入力バリデーション、OWASP Top 10、AGENTS.md のセキュリティ不変条件）
10. DESIGN.md準拠（UI/frontend 変更を含む場合）: `/DESIGN.md` が design token の canonical source。color / radius / typography を token 経由で参照する設計か、hex 直書きを増やさないか。token 変更時は `resources/css/tokens.css` との同期を設計に織り込んでいるか（運用契約は `docs/design-system.md`）
11. Atomic Design準拠（UI/frontend 変更を含む場合）: `resources/js/components/` の `atoms/molecules/organisms/templates` の責務分離に沿った配置か。atom は単機能・無状態、molecule は atom の組合せという階層を逆流していないか。アイコンは Lucide 前提で、SVG 直書きを新設していないか

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力
```

**user**: `## 詳細設計書\n{detailed-design.mdの内容}\n\n## 関連する現行コード\n{変更対象ファイルの抜粋}`

レビュー結果を保存:
```
devnotes/{dir}/detailed-review-round-1.md
```

### 2-4. 詳細設計レビュー合議ループ

Codexのレビューを精査し:

1. **[Critical]** は**必ず対応**（設計修正 or 根拠ある反論）
2. **[Warning]** は**対応を検討**
3. **全体判定が APPROVED になるまで**繰り返す（最大5ラウンド）

各ラウンドの成果物（Phase 1-4 と同様、全て `devnotes/{dir}/` 配下に保存）:

| ファイル | 内容 |
|---------|------|
| `devnotes/{dir}/detailed-review-round-{N}.md` | Codexの返答 |
| `devnotes/{dir}/codex-history/design-review-prompt-round-{N}.md` | Codexに送ったプロンプト |
| `devnotes/{dir}/codex-history/design-review-decisions-round-{N}.md` | Claude側の対応マトリクス |

### 2-5. 最終確認

詳細設計が APPROVED になったら、**使命・禁止事項チェック**を実施:
- 全施策が使命（AGENTS.md）に寄与するか
- 禁止事項に違反していないか
- コーディングルール（PHPStan、テスト必須、DTO）が設計に反映されているか

---

## Phase 3: 完了報告 & TODO登録案内

### 3-1. 最終報告

```
## 設計フロー完了

### 成果物
- 概念設計: devnotes/{dir}/conceptual-design.md
- 詳細設計: devnotes/{dir}/detailed-design.md （APPROVED）

### サマリー
- 改善内容: [1-2行]
- 変更ファイル: [一覧]
- 推奨実装モード: {incremental / standalone}（理由: [判断根拠]）
```

### 3-2. TODO登録の案内

詳細設計書の内容から以下を推定し、TODO登録コマンドを案内する:

```
### TODO登録

以下のコマンドでTODOリストに登録してください:

/app-todo-add "{topic}" {theme} "{summary}" {dir} {priority} {mode}
```

**注意**: このスキルはTODO.mdを直接変更しない。TODO登録は `/app-todo-add` スキルの責務。

---

## エラーハンドリング

### Codex APIエラー
- 30秒待って1回リトライ
- 2回連続失敗の場合、ユーザーに報告してCodexなしで続行するか確認

### 既存コードの読み込みエラー
- 対象ファイルが見つからない場合、ユーザーに正しいパスを確認する
