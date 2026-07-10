# LLM 駆動開発プロセス資産の調査(視点⑨)

> 対象: AGENTS.md / TODO 運用 / devnotes 規約 / .claude/skills 内部 / codex review 連携 /
> .claude/settings.json。テンプレートの中核価値(M12)の設計材料。

## 1. AGENTS.md(aigenba 1189 行 / spirux 668 行)

構成は同型で、**共通部が 70〜80%**:
- 使命(North Star)/ 思考原則(6 項目)/ 禁止事項(8 項目)/ 基本原則
- devnotes 規約(`devnotes/YYYYMMDD-HHMM-{topic}/`)/ scripts(本番) vs devnotes(一時)の配置規則
- worktree 運用(CWD 毎回明示・vendor worktree-local・install 層別 L1/L2/L3)
- dev DB 保護(dusk DB 分離、migrate:fresh 禁止)
- LLM 呼び出し規則(Prompt サブクラス経由・UserInput wrap・Prism Facade 直呼び禁止)
- テスト規則(Pest・Factory 必須・Dusk fake provider・Browser テストで role/plan を書き換えない)
- JSON 返却規則(DTO/JsonResource、spec 固定 endpoint の例外)
- フロントエンド規則(ESLint/Tailwind class-order)

差分(アプリ固有 20〜30%): 認可モデルの詳説(aigenba=SharedResource 多層 / spirux=scopeBindings+SSRF)、
課金詳説(aigenba は本文 / spirux は skill 委譲)、CLI E2E(aigenba のみ)、supply-chain 監査(aigenba のみ)。

**テンプレ設計**:
- `AGENTS.md` 雛形 = 共通部をそのまま+「ドメイン固有セクションの挿入位置」をマーカーで明示。
  巨大 frontmatter+条件 include のような生成機構は **作らない**(over-engineering。
  雛形 1 本+「アプリ固有節を追記する場所」の指示で足りる)
- 禁止事項・基本原則は両者の和集合から強い方を採る
- 07(アプリ組み込みガイド)と相互参照させる

## 2. TODO 運用

- フォーマット共通: ID(TXXX 採番)/ タイトル / テーマ / 概要 / 優先度 / モード / 設計リンク / 追加日 の表形式
- クローズ管理: **spirux 方式(TODO.md=Open のみ、TODO-closed.md に Closed/Obsoleted を分離)をドナー**
  (aigenba はインライン完了表記で Open の可読性が落ちる)
- テンプレには空の TODO.md / TODO-closed.md + 採番・移動規約(app-todo-add / app-todo-close スキルが操作)

## 3. devnotes 規約

- ディレクトリ: `devnotes/YYYYMMDD-HHMM-{topic}/`(両者同一)
- 中身の典型: conceptual-design.md → conceptual-review-round-N.md → detailed-design.md →
  detailed-review-round-N.md
- 差分: aigenba は `codex-history/` サブディレクトリにプロンプト・判断履歴を分離 / spirux は flat。
  **テンプレは aigenba 方式(codex-history/ 分離)**: 設計本文とレビュー機械出力が混ざらない

## 4. スキル群の内部構造

5 フェーズ自走ループ(autopilot)・design(概念→Codex→詳細→Codex)・implement(worktree→実装→
テスト→Codex→マージ→TODO close)・todo-add/close・codex-review・update-docs は**両者ほぼ同一**
(design 399/393 行、implement 333/336 行)。完全に再利用可能。

差分と採否:
| 論点 | aigenba | spirux | テンプレ |
|---|---|---|---|
| autopilot 状態ファイル | /tmp/{app}-autopilot-state/session-{id}.json(複数セッション、claiming_todo_id で TODO 重複取得防止、stale 180 分) | devnotes/autopilot-state/current.json(単一) | **aigenba 方式**(複数セッション対応。単一でも動く) |
| codex プロンプト保存先 | devnotes/{dir}/codex-history/ | devnotes/{dir}/ flat | aigenba 方式 |
| codex セッション JSONL | devnotes 内 | /tmp/codex-review/ | **spirux 方式**(/tmp 外出し。リポジトリを機械出力で汚さない) |
| Codex モデル指定 | 概念=chat 系 / 詳細=codex 系 + reasoning effort 明示 | 同一 | 共通化 |

**アプリ固有値の抽出ポイント**(スキル frontmatter or config/template.php へ):
スキル名 prefix / 状態ファイルパス / DB 名(dev/dusk/e2e) / ポート / TODO.md パス /
devnotes パス / North Star 文 / codex ラッパパス(scripts/codex)。
codex-review スキルの「使命・禁止事項の一元管理」(レビュー prompt に毎回自動挿入する文)は
AGENTS.md の使命節から導出する形にし、二重管理を避ける。

## 5. .claude/settings.json・hooks

- permissions: ほぼ同型(Bash / WebFetch ドメイン / MCP context7・github)。テンプレは共通集合+
  プロジェクト追記方式(fewer-permission-prompts の運用に委ねる)
- hooks: 両者 PostToolUse で code-review-graph 更新(flock 付き)。code-review-graph 自体は
  外部ツール依存なので **オプション**(hooks 雛形はコメントアウトで同梱)
- enabledMcpjsonServers: context7 / github を既定、playwright・code-review-graph はオプション

## 6. 両アプリ間整列運用 → テンプレ運用への転写

aigenba↔spirux で実証された運用(divergence registry 正本+ミラー、reverse-backport 再評価、
handoff devnotes)は、テンプレ化後は次の形に置き換える:
- 各アプリは `docs/template-divergence.md` に「テンプレからの logic-driven 逸脱」を記録(07 §8)
- テンプレ更新時の取り込み・アプリからの逆輸入は app-update-docs 系スキルの兄弟として
  `app-template-sync` スキル(新規)を Phase 9 で設計する候補に追加

## 7. M12/Phase 9 への反映事項(09 の更新点)

1. autopilot は複数セッション型(aigenba)をベースに汎用化
2. devnotes は codex-history/ 分離型
3. TODO は Open/Closed 分離型(spirux)
4. codex セッション JSONL は /tmp 系へ
5. AGENTS.md は生成機構なしの雛形 1 本+挿入マーカー方式
6. `app-template-sync` スキルを Phase 9 の設計候補に追加(テンプレ⇔アプリの差分還流)
