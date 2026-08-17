---
name: app-codex-vscode
description: scripts/codex exec を使ったOpenAIモデル呼び出しの共通規約
user-invocable: false
---

# codex 呼び出し規約

OpenAI モデルへの問い合わせは `scripts/codex` 経由で実行する
(VSCode 拡張 `openai.chatgpt` のネイティブバイナリを動的検出して使用するラッパ)。

---

## 基本コマンド（One-shot）

```bash
scripts/codex exec --ephemeral --sandbox read-only -m {model} \
  -c 'model_reasoning_effort="{reasoning}"' \
  -o {出力ファイル} - < {プロンプトファイル}
```

**必須オプション**:
- `--ephemeral`: セッションファイルを永続化しない
- `--sandbox read-only`: コマンド実行・ファイル書き込みを禁止（ファイル読み込みは許可）
- `-m {model}`: モデルを指定
- `-c 'model_reasoning_effort="{reasoning}"'`: reasoning effortを指定（`~/.codex/config.toml` のグローバル設定を上書き）
- `-o {出力ファイル}`: 結果をファイルに保存
- `- < {プロンプトファイル}`: プロンプトをstdin経由で渡す

---

## 利用可能モデル

モデル指定の**正本は本節**である。呼び出し側のスキルは下の割当に従って綴りを書く。

| モデル | 用途 |
|--------|------|
| `gpt-5.6-sol` | コードの分析・レビュー・技術設計（**既定**） |
| `gpt-5.6-terra` | 議論・概念設計 |
| `gpt-5.6-luna` | 軽い判定 |

- **名前は接尾辞まで含めて 1 つ**である。接尾辞を落とした名前を書くと呼び出しが失敗する。
- 前の世代の名前・末尾に codex が付く名前は**新たに指定しない**。提供が終了しているものは
  呼び出しに失敗し、期限つきでまだ使えるものも移行の対象である。
  本書は**その綴りを持たない**（個別の区分は家系の機能台帳 `skill-codex-integration` の
  裁定 AG-186 が指す spirux 側の呼び出し規約スキルの表が正本である）。
- 用途と綴りの対応、および綴りが本書と呼び出し側スキルの外へ漏れていないことは
  `tests/js/architecture/codex-model-consistency.test.ts` が既定拒否で固定する
  （許可表に無い綴りは 1 件でも赤になる）。

---

## Reasoning Effort

`-c 'model_reasoning_effort="{reasoning}"'` で推論の深さを制御する。
`~/.codex/config.toml` のグローバル設定（`model_reasoning_effort`）はモデルとの互換性問題を起こす場合があるため、**常にコマンドラインで明示指定すること**。

| レベル | 用途 |
|--------|------|
| `low` | 高速・軽量な応答 |
| `medium` | 議論・分析・ブレスト用（**デフォルト推奨** — Claudeが評価・選別する場面） |
| `high` | コードレビュー・安全性判定用（Codex判断が直接品質に影響する場面） |
| `xhigh` | 最大の推論深度 |

---

## プロンプトの渡し方

1. **Write ツール**でプロンプトファイルを作成（`{design_dir}/codex-history/{label}-prompt-round-{N}.md`）
2. **stdin経由**で `scripts/codex exec` に渡す（`- < {ファイルパス}`）
3. 結果は `-o` で指定したファイルに出力される
4. **シェル引数でプロンプトを渡してはならない**（エスケープ・長さ制限の問題を回避）
5. **プロンプト・返答・判断記録はリポジトリ外（`/tmp` 等）に書き出さない**。議論履歴として `devnotes/` にコミットするため（セッションJSONLのみ例外。`app-codex-review` 参照）

詳細は `app-codex-review` スキルの「議論履歴の保存方針」を参照。

---

## セッション管理（文脈保持が必要な場合）

複数ラウンドの会話で文脈を維持する場合は `app-codex-review` スキルのセッションモードを参照。

---

## エラーハンドリング

- `scripts/codex exec` が非ゼロ終了コードを返した場合、30秒待って1回リトライ
- 2回連続失敗時は呼び出し元の規定に従う
