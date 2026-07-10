---
name: app-implement
description: TODO実装スキル（worktreeで実装・テスト・Codexレビュー・コミット・TODOクローズ→mainマージ）
user-invocable: true
argument-hint: "<todo_id> [--skip-consensus] [--skip-todo]  例: /app-implement T012"
---

# TODO 実装（worktree分離）

## 引数

| 引数 | 必須 | 説明 |
|------|------|------|
| todo_id | Yes | TODO ID（例: T012）。docs/TODO.mdから設計リンクを取得し、詳細設計を読み込んで実装 |
| design_dir | No | 中間成果物の保存先ディレクトリ。省略時は自動生成 |
| --skip-consensus | No | Codex合議スキップモード。テスト通過をもって実装完了とみなす。ユーザーから明示的に指定された場合のみ使用可 |
| --skip-todo | No | TODO遷移スキップモード。Phase C（TODO close）をスキップ |

詳細設計に基づき、**git worktreeで分離した環境**でコード実装→テスト→Codexレビュー→コミット→TODOクローズ→mainマージを行う。

**入力**:
- TODO ID → 設計リンクから `detailed-design.md` を自動取得
- または `{design_dir}/detailed-design.md`

**出力**:
- mainブランチにマージ済みのコミット
- クローズ済みTODO

---

## 使命（North Star）— 絶対遵守

**`AGENTS.md` の「使命 (North Star)」セクションを読み、全実装判断の基準とする**（使命はAGENTS.mdが唯一の正本）。

## 思考原則 — 全議論に適用

**まず仮説を立てろ。** 何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。

**ユーザー視点で考えろ。** 技術的に正しいかだけでなく、その変更がエンドユーザーの体験にどう影響するかを常に問え。

**先人の知恵を探せ。** Laravel/Svelteのエコシステムに既存の解決策があるなら使え。

**機能の名前に立ち返れ。** 名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

## 禁止事項（自分・Codex双方に適用）

`AGENTS.md` の「禁止事項」セクションが正本。実装に直結する核は:

| # | 禁止事項 | 理由 |
|---|---------|------|
| 1 | **PHPStanエラーを無視**（`@phpstan-ignore-line` / baseline / 型のwiden） | 型安全性の崩壊 |
| 2 | **テストなしの実装完了** | 品質保証不能 |
| 3 | **既存テストの削除** | リグレッション検知不能 |
| 4 | **response()->json() の直接使用**（仕様固定 endpoint のみ例外） | DTO/JsonResourceパターン違反 |
| 5 | **`DatabaseTransactions` の個別使用** | `RefreshDatabase` がグローバル適用済（並列実行前提） |
| 6 | **不必要な複雑化** | コスト不適合 |

---

## Phase W: Worktree準備

### W-1. TODO情報の取得

1. `docs/TODO.md` から対象TODOの行を検索:
   ```bash
   grep "{todo_id}" docs/TODO.md
   ```
2. 見つからない場合はエラー終了
3. 設計列のリンク先から詳細設計ファイルパスを取得
4. 詳細設計ファイルを `Read` する

### W-2. design_dir の準備

`design_dir` が未指定の場合:
```bash
TZ=Asia/Tokyo date '+%Y%m%d-%H%M'
```
で `devnotes/{YYYYMMDD-HHMM}-todo-{todo_id}/` を作成。

### W-3. Worktree作成

worktree のセットアップは `scripts/setup-worktree.sh` で機械的に行う
（AGENTS.md §worktree 運用ルール。手動 `git worktree add` は使わない）:

```bash
cd {repo_root} && scripts/setup-worktree.sh {todo_id}
```

スクリプトが `.claude/worktrees/tasks/{todo_id}` に worktree を作成し
`todo/{todo_id}` ブランチを切り（main 起点・ブランチ名固定）、実行時ファイルのコピー・
worktree 内 `composer install --no-scripts`・`pnpm install --frozen-lockfile`・
post-setup health check まで自動で行う。失敗時は作成途中の worktree とブランチを
自動削除するので、エラー原因を解消して再実行すればよい。

以降の全作業は **worktree内** で行う。

> **⚠️ CWD を毎回明示する (絶対遵守)**
>
> 以降の Bash コマンドは **1 コール毎の先頭で `cd {repo_root}/.claude/worktrees/tasks/{todo_id} && ...` を必ず明示** する
> （`{repo_root}` はメインリポジトリの絶対パス）。
> Bash tool の CWD は建前上持続するが、 サブシェル `( ... )` ・ 並列 background task ・ 別ツール経由で
> 知らない間に main 側に戻るケースが繰り返し発生する。 これを油断すると、
> worktree のつもりで `composer test` を打ったら **main 側で test が走り、
> 計測が無効になる / 最悪 main 側の DB を触る** 事故になる。
>
> ```bash
> # ✅ 正しい
> cd {repo_root}/.claude/worktrees/tasks/{todo_id} && composer test
> cd {repo_root}/.claude/worktrees/tasks/{todo_id} && git diff HEAD
>
> # ❌ 危険 (直前で cd 済を前提にしない)
> composer test
> git diff HEAD
> ```
>
> 計測系コマンドは特に注意: 「成功した」 だけ見ると違う branch の数字を採用する事故になる。
> background task 完了後にログを読む時は最初に `pwd` を確認するか、 ログ path を絶対パスにする。

### W-4. Worktree内の依存関係確認

依存は W-3 の `setup-worktree.sh` がインストール済み（worktree-local の `vendor/` +
global virtual store 共有の `node_modules`）。追加作業は不要。

lockfile を変更した後など手動で再インストールする場合のみ（AGENTS.md §worktree 運用ルール）:

```bash
cd {repo_root}/.claude/worktrees/tasks/{todo_id} && composer install
cd {repo_root}/.claude/worktrees/tasks/{todo_id} && pnpm install --config.ci=false --config.enableGlobalVirtualStore=true --config.nodeLinker=isolated
```

---

## Phase A: 実装 & Codex実装レビュー合議

### A-1. 実装

詳細設計書に従い、コードを実装する。

**重要**: 全ファイルパスはworktreeのルート（`.claude/worktrees/tasks/{todo_id}/`）基準で操作する。

**実装ルール**:
1. 施策ごとに順番に実装（依存関係がある場合は先行施策から）
2. **各施策には必ずテストを書く**（テストのない施策は実装完了とみなさない）
   - ロジック変更 → 単体テストで Before/After を検証
   - API変更 → Feature テストで期待レスポンスを検証
   - 新機能追加 → 正常系・異常系テスト
   - **バグ修正はテストファースト**: 再現テストを先に書きFAILを確認してから修正
   - **Pest**フレームワーク使用、**RefreshDatabase**グローバル適用済（個別 `DatabaseTransactions` 使用禁止、`composer test` は `--parallel` 実行）
   - **テストデータは必ずFactoryで生成**（`Model::create()` での手組み禁止）
   - 新モデルを追加した場合は **対応するFactoryも必ず作成** すること
3. 各施策の実装後:
   ```bash
   cd {repo_root}/.claude/worktrees/tasks/{todo_id} && composer test
   ```
4. 全施策の実装完了後に品質チェック（全コマンド green になるまで修正する）:
   ```bash
   cd {repo_root}/.claude/worktrees/tasks/{todo_id} && composer phpstan && composer fix && pnpm lint:fix
   cd {repo_root}/.claude/worktrees/tasks/{todo_id} && vendor/bin/pint --test && pnpm lint && pnpm typecheck && pnpm test && pnpm build
   ```
5. **テストが失敗した場合はテスト駆動で修正**
6. **E2E テスト基盤（Dusk 等）が導入済みなら**、UI変更を含む施策では E2E テストも追加・実行する（未導入のテンプレート初期状態ではスキップ）

### A-2. Codexによる実装レビュー

**`--skip-consensus` 時は A-2/A-3 をスキップ**。テスト全通過 + PHPStan通過をもって実装完了とみなし、Phase B へ進む。

全施策の実装完了・テスト通過後、git diffでコード差分を取得し、Codexにレビューを依頼する。

**差分の取得**（worktree内で実行）:
```bash
cd {repo_root}/.claude/worktrees/tasks/{todo_id} && \
  git add -N app/ resources/ tests/ routes/ && \
  git diff HEAD --no-color -- app/ resources/ tests/ routes/
```

`app-codex-review` スキルの**セッションモード**に従い、プロンプトファイルを作成してCodexに依頼する。

**model**: `gpt-5.3-codex`
**reasoning**: `high`
**label**: `impl-review`

- **system**: コードレビュアーとしてLaravel + Svelteの改善実装をレビュー。レビュー観点（設計との一致性、正確性、PHPStan適合性、DTO/JsonResourceパターン、テスト網羅性、セキュリティ、**DESIGN.md準拠**、**Atomic Design準拠**）、出力形式（ファイルごとに判定、Critical/Warning/Suggestion分類、全体判定APPROVED/CHANGES_REQUESTED）
  - **DESIGN.md準拠**: `/DESIGN.md` が design token の canonical source。color / radius / typography は token 経由で参照し hex 直書き（`#RRGGBB`）を増やさない。token 値を変更する diff は `resources/css/tokens.css` と同一 diff 内で同期しているか（運用契約は `docs/design-system.md`）
  - **Atomic Design準拠**: `resources/js/components/` は `atoms/molecules/organisms/templates` の責務分離に従う。atom は単機能・状態を持たない、molecule は atom の組合せという階層を逆流していないか。アイコンは Lucide を使い、SVG 直書きを増やさない
- **user**: `## 詳細設計書\n{detailed-design.md}\n\n## 実装差分（git diff）\n{diff出力}\n\n## テスト結果\n{composer test出力サマリー}`
  - diff が `resources/js/` / `resources/css/` を含む場合は `## design system 参照\n{DESIGN.md の関連 token 抜粋 + 触れた atomic ディレクトリ構造}` も user 部に添付し、DESIGN.md / Atomic Design 観点を Codex が判定できるようにする

### A-3. 実装レビュー合議ループ

1. **[Critical]** は必ず修正
2. **[Warning]** は検討して対応
3. 修正後に再度テスト実行 + PHPStan
4. 修正差分を再度Codexに送信

**合議終了条件**: Codexの全体判定が **APPROVED** になるまで。最大3ラウンド。

各ラウンドの成果物を `{design_dir}/` 配下に保存する（議論履歴として必ずコミット対象に含める）:

| ファイル | 内容 |
|---------|------|
| `{design_dir}/impl-review-round-{N}.md` | Codexの返答 |
| `{design_dir}/codex-history/impl-review-prompt-round-{N}.md` | Codexに送ったプロンプト |
| `{design_dir}/codex-history/impl-review-decisions-round-{N}.md` | Claude側の対応マトリクス（次ラウンド開始前に記録） |

### A-4. ユーザー報告

```
## Phase A 完了: 実装 & レビュー

### 実装完了
- ブランチ: todo/{todo_id}
- 変更ファイル: N files
- テスト: XXX passed, 0 failed
- PHPStan: OK

### Codex実装レビュー: APPROVED (Round {N})

→ Phase Bに進みます（コミット & mainマージ）
```

---

## Phase B: コミット（worktree内）

### B-1. コミット（worktree内）

Phase Aの実装・テスト変更をまとめてコミットする。

```bash
cd {repo_root}/.claude/worktrees/tasks/{todo_id}
git add app/ resources/ tests/ routes/ database/ config/
git commit -m "$(cat <<'EOF'
feat: {todo_id} {施策タイトル}

施策:
- S1: {施策名}
- S2: {施策名}

Co-Authored-By: Claude <noreply@anthropic.com>
EOF
)"
```

---

## Phase C: TODOクローズ & mainマージ

### C-1. TODOクローズ

**`--skip-todo` 時はスキップ**。

**mainブランチ（リポジトリルート）に戻って**TODOクローズを実行する:

```bash
cd {repo_root}
```

**`/app-todo-close` スキルを呼び出す**:
```
/app-todo-close {todo_id}
```

### C-2. mainへマージ

```bash
cd {repo_root}
git merge todo/{todo_id} --no-ff -m "$(cat <<'EOF'
Merge branch 'todo/{todo_id}'

{todo_id}: {施策タイトル}

Co-Authored-By: Claude <noreply@anthropic.com>
EOF
)"
```

### C-3. コンフリクト解消

マージでコンフリクトが発生した場合:

1. `git diff --name-only --diff-filter=U` でコンフリクトファイルを特定
2. 各ファイルのコンフリクトマーカーを確認
3. **解消方針**:
   - **同一関数内の変更**: 両方の変更を論理的に統合する
   - **import文・use文**: 両方を保持（重複除去）
   - **設定値**: main側を優先し、worktree側の新規追加分を追加
   - **ドキュメント**: main側を優先
4. コンフリクト解消後にテスト + PHPStanを実行:
   ```bash
   composer test && composer phpstan
   ```
5. テスト通過を確認してからコミット
6. 3回修正しても通らない場合はユーザーに報告

### C-4. Worktreeクリーンアップ

teardown は `scripts/teardown-worktree.sh` で機械的に行う（dirty チェック +
テストDB回収 + worktree削除。ブランチ削除はスクリプトの責務外なので続けて実行する）:

```bash
cd {repo_root}
scripts/teardown-worktree.sh {todo_id}
git branch -d todo/{todo_id}
```

dirty チェックで fail した場合は worktree 内の未コミット変更（依存変更の lockfile 含む）を
コミットしてから再実行する。

### C-5. 最終報告

```
## 実装完了: {todo_id} {施策タイトル}

### サマリー
- ブランチ: todo/{todo_id} → main にマージ済み
- 変更ファイル: N files
- テスト: XXX passed, 0 failed
- PHPStan: OK
- Codexレビュー: APPROVED / SKIPPED
- TODOクローズ: 完了 / スキップ
- コンフリクト: なし / 解消済み

### コミット
- 実装: {commit_hash}
- マージ: {merge_commit_hash}
```

---

## エラーハンドリング

### Codex CLIエラー
- 30秒待って1回リトライ
- 2回連続失敗でユーザーに報告しCodexなしで続行するか確認

### テスト失敗
- **テスト駆動で修正**する
- 3回修正しても通らない場合、ユーザーに報告

### PHPStanエラー
- エラーを修正する（`@phpstan-ignore-line` 禁止）
- `Webmozart\Assert\Assert` を活用

### Worktreeエラー
- worktree / ブランチが既に存在する場合: `scripts/teardown-worktree.sh {todo_id}` +
  `git branch -D todo/{todo_id}` してから再作成
- setup-worktree.sh 失敗: 失敗時は作成途中の worktree とブランチが自動削除される。
  エラーメッセージ（composer/pnpm の install 失敗、health check 失敗等）を報告

### マージコンフリクト
- Phase C-3 のコンフリクト解消手順に従う
- 解消不能な場合は `git merge --abort` してユーザーに報告
