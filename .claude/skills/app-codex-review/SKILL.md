---
name: app-codex-review
description: scripts/codex exec を使ったCodexレビュー・分析の共通呼び出しスキル（使命・禁止事項の一元管理、セッション管理を含む）
user-invocable: false
---

# Codex CLI レビュー呼び出し規約

Codexへのレビュー・分析依頼は `scripts/codex` 経由で実行する。
VSCode拡張（`openai.chatgpt`）のネイティブバイナリを動的検出して使用。
基本コマンド・モデル・reasoning effort の規約は `app-codex-vscode` スキルを参照。

---

## 使命・禁止事項（全Codex呼び出しに自動適用）

全てのCodexプロンプトの**先頭**に以下を挿入すること。呼び出し元スキルのsystem部にはこの内容を重複記載しない。

### 1. アプリの使命（AGENTS.md から導出 — 二重管理禁止）

**`AGENTS.md` の「使命 (North Star)」セクションを読み、その内容をそのまま挿入する。**
このスキル内に使命のコピーを持たない（AGENTS.md が唯一の正本）。
使命が未記入（テンプレート初期状態）の場合は「使命は未定義。汎用的な品質・安全性の観点でレビューせよ」と挿入する。

### 2. 禁止事項（AGENTS.md から導出）

**`AGENTS.md` の「禁止事項」セクションを読み、その内容をそのまま挿入する。**

### 3. 思考原則・ツール使用制限（このスキルが正規定義）

```
【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。
```

---

## プロンプト構成ルール

1. **プロンプトはファイルに書き出し、stdin経由で渡す**（シェル引数では渡さない）
2. Write ツールでプロンプトファイルを作成 → stdin リダイレクトで `scripts/codex exec` に渡す
3. プロンプトの構成順序:

```
{使命・禁止事項・思考原則・ツール使用制限}  ← 本スキルの規定に従い挿入
{system部: 役割・タスク固有の指示}          ← 呼び出し元スキルが定義
---
{user部: データ・質問}                      ← 呼び出し元スキルが定義
```

4. プロンプトファイルの配置先: `{design_dir}/codex-history/{label}-prompt-round-{N}.md`
    - `design_dir` は呼び出し元スキルが作成する `devnotes/{YYYYMMDD-HHMM}-{topic}/`
    - `label` は呼び出し元が指定する識別子（例: `analysis`, `consensus`, `design-review`, `impl-review`）
    - Round 1 は `{label}-prompt-round-1.md`
    - **プロンプト・返答・対応マトリクスをリポジトリ外（`/tmp` など）に書き出すのは禁止**。議論履歴はdevnotesにまとめてコミットする

### 議論履歴の保存方針

Codexとの議論は以下を `{design_dir}/codex-history/` 配下に保存する:

| ファイル | 内容 |
|---------|------|
| `{label}-prompt-round-{N}.md` | Codexに送ったプロンプト全文 |
| `{label}-decisions-round-{N}.md` | Claude側の対応マトリクス（Critical/Warning/Suggestion ごとに「対応する / 反論する / 見送る」の判断と根拠） |

Codexの返答ファイル（`{label}-review-round-{N}.md` 等）は呼び出し元スキルが指定する位置（通常 `{design_dir}/` 直下）に保存する。

**セッションJSONL** (`--json` 出力) は機械出力なので **`${TMPDIR:-/tmp}/codex-review/` 配下に置く**（リポジトリ内に置かない）。これは `thread_id` 抽出のための中間ファイルであり、人間が読む議論履歴はプロンプト・返答・対応マトリクスで完結する。

---

## One-shotモード

セッションを保持しない単発呼び出し。独立分析、テーマ別議論など合議ループを伴わない用途で使用。

**コマンドテンプレート**:

```bash
mkdir -p {design_dir}/codex-history
scripts/codex exec --ephemeral --sandbox read-only -m {model} \
  -c 'model_reasoning_effort="{reasoning}"' \
  -o {出力ファイル} - < {design_dir}/codex-history/{label}-prompt-round-1.md
```

**必須オプション**:

- `--ephemeral`: セッションファイルを永続化しない
- `--sandbox read-only`: コマンド実行・ファイル書き込みを禁止（読み込みは許可）
- `-m {model}`: モデルを指定（本スキルが指定する既定値は `gpt-5.6-sol`。用途別の割当は `app-codex-vscode` が正本）
- `-c 'model_reasoning_effort="{reasoning}"'`: reasoning effortを指定（モデル互換性のため常に明示指定。詳細は `app-codex-vscode` 参照）
- `-o {出力ファイル}`: 結果をファイルに保存

---

## セッションモード（合議ループ用）

複数ラウンドの合議でCodexが前回の指摘を記憶した状態で再レビューできるモード。

### Round 1: セッション作成

```bash
mkdir -p {design_dir}/codex-history "${TMPDIR:-/tmp}/codex-review"
SESSION_LOG=$(mktemp "${TMPDIR:-/tmp}/codex-review/session-{label}.XXXXXX.jsonl")

scripts/codex exec --sandbox read-only -m {model} \
  -c 'model_reasoning_effort="{reasoning}"' --json \
  -o {出力ファイル}-round-1.md \
  - < {design_dir}/codex-history/{label}-prompt-round-1.md \
  > "$SESSION_LOG"

SESSION_ID=$(head -1 "$SESSION_LOG" \
  | python3 -c "import sys,json; print(json.loads(sys.stdin.read())['thread_id'])")
```

- `--ephemeral` を付けない（セッションを永続化）
- `--json` でJSONLイベントをstdoutに出力 → `${TMPDIR:-/tmp}/codex-review/` の一時ファイルに保存（リポジトリ外）
- 1行目の `thread.started` イベントから `thread_id` を抽出

### Round N (N≥2): セッション再開

Round Nのプロンプトは Round 1 と同じ `{design_dir}/codex-history/` に保存する。対応マトリクスも別ファイルとして保存する。

```bash
# 対応マトリクスを記録（Claudeが Round N-1 の指摘をどう捌いたか）
# → {design_dir}/codex-history/{label}-decisions-round-{N-1}.md を Write で作成

scripts/codex exec resume "$SESSION_ID" --json \
  -o {出力ファイル}-round-{N}.md \
  - < {design_dir}/codex-history/{label}-prompt-round-{N}.md \
  >> "$SESSION_LOG"
```

- `exec resume {SESSION_ID}` で前回セッションを再開（文脈維持）
- Round Nのプロンプトには対応マトリクス・修正内容のみ記載（使命・禁止事項の再挿入は不要）
- JSONLは同じ `$SESSION_LOG` に追記（`>>`）

### 対応マトリクスの書式

`{label}-decisions-round-{N}.md` は以下の形式で書く（Round N のCodex返答を受けた判断を記録する）:

```markdown
# 対応マトリクス: {label} Round {N}

## [Critical] {指摘タイトル}
- 判断: 対応する / 反論する / 見送る
- 根拠: {なぜそう判断したか}
- 対応内容: {具体的に何を変えたか / 次ラウンドで何を伝えるか}

## [Warning] ...
## [Suggestion] ...
```

### session_label の命名規則

並列実行時の衝突を避けるため、呼び出し元が一意な `label` を指定する:

- `conceptual-review` — 概念設計レビュー（app-design Phase 1）
- `design-review` — 詳細設計レビュー（app-design Phase 2）
- `impl-review` — 実装レビュー（app-implement Phase A）
- `analysis` / `consensus` — 単発分析・改善策合議など

---

## エラーハンドリング

### 共通（全モード）

- `scripts/codex exec` / `scripts/codex exec resume` が非ゼロ終了コードを返した場合、30秒待って1回リトライ
- 2回連続失敗時の挙動は呼び出し元スキルの規定に従う

### セッションモード固有

- **Round 1 で失敗**: リトライ時も新規セッションを作成（SESSION_IDは更新される）
- **Round N (N≥2) で失敗**: 同じ SESSION_ID でリトライする
- **SESSION_ID 取得失敗**（JSONLパースエラー等）: one-shotモードにフォールバック（`--ephemeral` で再実行）
