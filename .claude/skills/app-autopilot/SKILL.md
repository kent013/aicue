---
name: app-autopilot
description: アプリ改善の自走ループ（現在地確認→設計→TODO登録→worktree実装→TODOクローズ→マージ→多角監査→繰り返し）
user-invocable: true
argument-hint: "[topic_or_todo_id] [--repeat] [--from phase] [--max-cycles N]  例: /app-autopilot --repeat, /app-autopilot T001"
---

# 自走ループ（Autopilot）

## 引数

| 引数 | 必須 | 説明 |
|------|------|------|
| topic_or_todo_id | No | 開始地点の指定。TODO ID（例: T001）なら実装から開始。トピック名なら設計から開始。省略時はdocs/TODO.mdを見て自動判断 |
| --repeat | No | サイクルをループする。省略時は1回のみ実行して終了 |
| --from | No | 開始フェーズの強制指定: survey / design / todo-add / implement / next-cycle（省略時は自動判断） |
| --max-cycles | No | 最大サイクル数（--repeat時のみ有効。省略時: 5）。安全のため上限あり |
| --skip-consensus | No | Codex合議スキップモード。実装フェーズでテスト通過をもって完了とみなす |
| --audit-interval | No | 何サイクルごとに多角監査を実施するか（省略時: 2）。1なら毎サイクル、0なら監査スキップ |
| --resume | No | 前回の中断から再開する。 `${TMPDIR:-/tmp}/app-autopilot-state/session-*.json` を読み込み、 最後に完了したPhaseの次から続行。 複数 active セッションがあれば `--session` で対象を指定 |
| --session | No | `--resume` で再開対象のセッションIDを明示指定（例: `--session 20260522-1922`）。 複数 active セッション存在時は必須 |
| --no-state | No | 状態ファイルへの書き込みを行わない（テスト用途等） |

**設計→TODO登録→worktree実装→TODOクローズ→マージ→多角監査→位置確認→次の設計…** を自律的に繰り返す統合オーケストレーションスキル。

---

## 使命（North Star）— 絶対遵守

**`AGENTS.md` の「使命 (North Star)」セクションを読み、全サイクルの判断基準とする**（使命はAGENTS.mdが唯一の正本。本スキルにコピーを持たない）。使命が未記入（テンプレート初期状態）の場合は、自走を始める前にユーザーへ使命の記入を促す。

## 思考原則 — 全サイクルに適用

**まず仮説を立てろ。** 何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。

**ユーザー視点で考えろ。** 技術的に正しいかだけでなく、エンドユーザーの体験にどう影響するかを常に問え。

**データに真摯に向き合え。** 思い込みで機能を追加するな。フィードバック・利用状況・メトリクスが判断材料。

**先人の知恵を探せ。** Laravel/Svelteのエコシステムに既存の解決策があるなら使え。

**機能の名前に立ち返れ。** 名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

**仕組みが機能していない段階で値を弄るな。** 設計の方向性が正しいと確認できてから微調整を行え。

## 禁止事項（全フェーズで厳守）

`AGENTS.md` の「禁止事項」セクションが正本。核は:

| # | 禁止事項 |
|---|---------|
| 1 | PHPStanのエラーを `@phpstan-ignore-line` や baseline で無視する |
| 2 | テストなしの実装完了（各施策にテスト必須） |
| 3 | 既存テストの削除・上書き |
| 4 | `response()->json()` の直接使用（DTO + JsonResource必須。仕様固定 endpoint のみ例外） |
| 5 | `DatabaseTransactions` の個別使用（`RefreshDatabase` がグローバル適用済） |
| 6 | 不必要な複雑化 |
| 7 | JavaScriptの使用（TypeScript必須） |

---

## 動作モード

### Single Shot（デフォルト）

`--repeat` なし。1サイクル（Phase 0→1→2→3→4）を実行して終了。
「まず1つだけ片付けたい」「特定のTODOだけ実装したい」場合に使用。

```
/app-autopilot              ← 自動判断で1サイクル
/app-autopilot T001         ← T001を実装して終了
/app-autopilot auth-refactor ← auth-refactorを設計→実装して終了
```

### Repeat（自走モード）

`--repeat` あり。サイクルを繰り返し、`max-cycles`（デフォルト5）に達するか、やることがなくなるまで自走する。
定期的に多角監査（Phase 4A）を挟み、品質・方向性のドリフトを防ぐ。

```
/app-autopilot --repeat                    ← 最大5サイクル自走
/app-autopilot --repeat --max-cycles 10    ← 最大10サイクル自走
```

---

## 全体フロー概要

```
┌──────────────────────────────────────────────────────────┐
│                     Autopilot Loop                       │
│                                                          │
│  ┌──────────┐                                            │
│  │ Phase 0  │ 現在地確認（Survey）                       │
│  │  Survey  │ TODO.md・設計ファイル・概念ドキュメント     │
│  │          │ を読み、次に何をすべきか判断                │
│  └────┬─────┘                                            │
│       │                                                  │
│       ▼                                                  │
│  ┌──────────┐                                            │
│  │ Phase 1  │ /app-design を呼び出し                     │
│  │  Design  │ 概念設計→Codexレビュー→詳細設計            │
│  └────┬─────┘                                            │
│       │                                                  │
│       ▼                                                  │
│  ┌──────────┐                                            │
│  │ Phase 2  │ /app-todo-add を呼び出し                   │
│  │ TODO Add │ 詳細設計からTODO登録                       │
│  └────┬─────┘                                            │
│       │                                                  │
│       ▼                                                  │
│  ┌──────────┐                                            │
│  │ Phase 3  │ /app-implement を呼び出し                  │
│  │Implement │ worktreeで実装→テスト→レビュー             │
│  │          │ →コミット→TODOクローズ→mainマージ           │
│  └────┬─────┘                                            │
│       │                                                  │
│       ▼                                                  │
│  ┌──────────┐                                            │
│  │ Phase 4  │ サイクル完了報告                            │
│  │Checkpoint│ 進捗確認                                   │
│  └────┬─────┘                                            │
│       │                                                  │
│       ├── --repeat なし → 最終報告して終了                │
│       │                                                  │
│       ▼ --repeat あり                                    │
│  ┌──────────┐                                            │
│  │Phase 4A  │ 多角監査（audit-interval サイクルごと）     │
│  │Evaluation│ 使命整合・技術的負債・UI一貫性・            │
│  │          │ セキュリティ・ドキュメント鮮度を点検        │
│  └────┬─────┘                                            │
│       │                                                  │
│       └──────── Phase 0 に戻る（次サイクル）─────────────┘
└──────────────────────────────────────────────────────────┘
```

---

## 状態保存（State Persistence）

**目的**: サイクル横断で進捗・結果を永続化し、 中断・再開・事後追跡・**複数 autopilot セッションの並行実行**を可能にする。

### 保存場所

**Live（実行中）と archive（履歴）で保存場所を分離する**。 live は OS の tmp に置いて git tracking から外し、 完了したものだけ devnotes に正規アーカイブする。

```
${TMPDIR:-/tmp}/app-autopilot-state/
└── session-{started_at}.json   ← 実行中（live）のセッション。 複数同時に存在しうる。 ephemeral

devnotes/autopilot-state/
└── {started_at}.json           ← 完了・中断・失敗で確定したセッション（履歴。 git 管理）
```

**単一の `current.json` 方式は採らない**。 複数 autopilot を同時に走らせると衝突するため、 セッション ID（`started_at`）でファイル名を分離する。

**live を tmp に置く理由**:
- `git status` を汚さない（実行中の状態ファイルが untracked として浮かない）
- OS 再起動で自然に消えるので orphan が長期残留しない
- ホスト共有 FS の lock 不可問題（flock 不可な FS）を踏まないため、 多セッションの読み書き競合がある live は tmp 側に隔離する

**トレードオフ**: ホスト再起動・tmp クリーンアップで live ファイルが失われると `--resume` 不可。 これは acceptable とする（クラッシュ復旧より「完了したものだけが履歴に残る」シンプルさを優先）。

**ディレクトリ作成**: 起動時に `mkdir -p "${TMPDIR:-/tmp}/app-autopilot-state"` を実行。 `devnotes/autopilot-state/` も同様に `mkdir -p` でアーカイブ前に確保する。

**セッション ID の決定**:
- 基本は `TZ=Asia/Tokyo date '+%Y%m%d-%H%M'` の値を使用
- 同分に複数セッションが起動した場合は `-2` / `-3` … をサフィックス（例: `session-20260522-1922-2.json`）
- セッション ID は state ファイルの `session_id` フィールドにも記録（ファイル名と同じ）

### ファイル構造（`session-{started_at}.json`）

```json
{
  "session_id": "20260413-2230",
  "started_at": "20260413-2230",
  "last_updated_at": "20260413-2305",
  "ended_at": null,
  "mode": "repeat",
  "max_cycles": 5,
  "audit_interval": 2,
  "skip_consensus": false,
  "args": { "topic_or_todo_id": null, "from": null },
  "notes": [
    {
      "added_at": "20260413-2230",
      "content": "決済画面のテストが通るまで実装したら一旦終了すること"
    }
  ],
  "current_cycle": 2,
  "current_phase": "phase-3",
  "claiming_todo_id": "T007",
  "status": "running",
  "cycles": [
    {
      "cycle": 1,
      "phase_completed": "phase-4a",
      "designed_topic": "billing-foundation",
      "design_tmp_dir": "devnotes/20260413-2230-billing-foundation",
      "added_todo_ids": ["T006"],
      "implemented_todo_ids": ["T005"],
      "merge_commit": "c44b17a",
      "audit_report_path": "devnotes/20260413-2300-audit-cycle-1/audit-report.md",
      "error": null,
      "started_at": "20260413-2230",
      "ended_at": "20260413-2301"
    }
  ]
}
```

- `session_id`: ファイル名と同じセッションID（人間の可読性のためフィールドにも入れる）
- `claiming_todo_id`: 現在 Phase 3 で実装中の TODO ID。 他セッションの Phase 0 がこれを見て重複取得を回避する。 Phase 3 完了時に `null` に戻す
- `status` は `running` / `completed` / `interrupted` / `failed` のいずれか

### メモ（`notes[]`）

ユーザーからの指示・注意事項・終了条件などを自由に蓄積できる領域。Autopilotは各サイクル開始時にこれを読み、意思決定に反映する。

**用途の例**:
- 終了条件の指定（例: 「T015までマージしたら止まる」）
- 優先順位の指示（例: 「セキュリティ監査で出た項目を最優先」）
- 避けるべき変更（例: 「今週は `app/Models/User.php` を触らない」）
- 進行中の文脈共有（例: 「リリース明けまで破壊的変更禁止」）

**読み書きの方法**:
- ユーザーが「メモに〜と書いておいて」「autopilotに〜を伝えて」等と指示した場合、`notes[]` に追記する
- 各サイクル Phase 0 の冒頭で `notes[]` を読み、該当する指示があれば Phase 0-5 の現在地レポートに「### 反映中のメモ」として明示する
- **終了条件系のメモ**は Phase 4 の継続判断に組み込み、条件を満たしたら `--repeat` でも停止する
- 古くなった・達成済みのメモはユーザー確認のうえで削除するか、`resolved_at` フィールドを追加して無効化する

**メモ項目のフォーマット**:
```json
{
  "added_at": "YYYYMMDD-HHMM",
  "content": "指示内容をそのまま記録",
  "resolved_at": "YYYYMMDD-HHMM"   // 解消した場合のみ
}
```

### 書き込みタイミング

| タイミング | 操作 |
|-----------|------|
| Autopilot起動時 | `session-{started_at}.json` を新規作成（`--resume` 時は対象セッションを読み込んで継続） |
| 各サイクル Phase 0 開始時 | `cycles[]` に新規エントリ追加、 `current_cycle` 更新、 `claiming_todo_id` クリア |
| Phase 3 開始時 | **`claiming_todo_id` を実装対象 TODO ID にセット**して即書き出し（他セッションが Phase 0 で見て重複回避するため） |
| 各 Phase 完了時 | 当該サイクルの結果フィールドを更新、 `phase_completed` と `current_phase` を進める。 `last_updated_at` を更新 |
| Phase 3 完了時 | `implemented_todo_ids` に追加し `claiming_todo_id` を `null` に戻す |
| Phase 4A 完了時 | `audit_report_path` を記録 |
| サイクル内エラー発生時 | `error` フィールドに内容、 `status: "failed"` で中断、 `claiming_todo_id` は残置（事後追跡のため） |
| 最終報告時 | `status: "completed"` にして `${TMPDIR:-/tmp}/app-autopilot-state/session-{started_at}.json` → `devnotes/autopilot-state/{started_at}.json` に `mv`（アーカイブ） |
| ユーザー中断時 | 次回起動までそのまま残す（`--resume --session {id}` で続行可能） |

**`--no-state` 指定時は全ての書き込みをスキップ**する。

**`last_updated_at` の更新は全 Phase で必須**。 他セッションが stale 判定に使うため、 Phase 完了時だけでなく長時間の処理（Codex 合議・worktree 実装）中も適宜更新することが望ましい。

### 複数セッションの並行実行

複数の Claude セッションで `/app-autopilot` を同時に走らせることをサポートする。 各セッションは独立した `session-{id}.json` を持ち、 互いの状態を読んで TODO の重複取得を回避する。

**Active セッションの定義**:
- `${TMPDIR:-/tmp}/app-autopilot-state/session-*.json` のうち `status: "running"` のもの
- かつ `last_updated_at` が現在時刻から **3 時間以内**（180 分以内）のもの
- 上記を満たさないものは **stale** として扱う（claim 無効、 active 一覧から除外）

**Stale セッションの扱い**:
- 起動時に stale なセッションファイルを検出したら、 ユーザーに以下を報告する:

  ```
  ⚠ Stale autopilot session detected:
    - ${TMPDIR:-/tmp}/app-autopilot-state/session-20260520-1430.json (last updated: 20260520-1545、 約 X 時間前)
  → このセッションは claim 無効として扱います。 不要であれば手動でアーカイブ (devnotes/autopilot-state/{id}.json に mv) または削除してください。
  ```
- 自動アーカイブは行わない（実行中の長期処理を誤判定するリスクを避けるため）

**TODO claim の仕組み**:
- 各セッションは Phase 3 開始時に `claiming_todo_id` を即書き出す
- 他セッションの Phase 0 で「他 active セッションの `claiming_todo_id` および `cycles[].implemented_todo_ids` の和集合」を「claim 済み TODO ID」として算出
- Open TODO 候補から claim 済み TODO を除外して次対象を選ぶ
- 全 Open TODO が他セッションに claim されている場合、 当該サイクルは「次にやる TODO がない」として完了報告へ進む

**衝突時の挙動**:
- 2 セッションがほぼ同時に Phase 0 を実行し、 同じ TODO を選んでしまった場合は worktree 作成（branch 名 `todo/T{NNN}` 衝突）で後発が失敗する。 これは acceptable な失敗モードとし、 失敗セッションはエラー報告してサイクル中断
- ハードロック（flock 等）は導入しない（共有 FS 制約、 およびオーバーエンジニアリング回避）

### 再開（`--resume`）の流れ

1. `${TMPDIR:-/tmp}/app-autopilot-state/session-*.json` を一覧
2. **`--session {id}` 指定あり**: `${TMPDIR:-/tmp}/app-autopilot-state/session-{id}.json` を読み込む。 無ければエラー
3. **`--session` なし**:
   - active セッションが 0 件: エラー停止（通常起動を案内）
   - active セッションが 1 件: それを自動採用
   - active セッションが 2 件以上: 全 active セッションのリストを表示して `--session {id}` で明示するよう要求し停止
4. 既存フィールド（mode, max_cycles, audit_interval 等）を復元。 コマンドライン引数で上書きされた場合はそちらを優先
5. 最後の `cycles[]` エントリを見て:
   - `phase_completed` が `phase-4` 以降 → 次サイクルの Phase 0 から開始
   - それ以外 → 同じサイクルの次Phaseから開始
6. 再開時にユーザーへ以下を報告:

```
───────────────────────────────────
Autopilot 再開 (session: {session_id})
前回: サイクル {N}、 最終完了Phase: {phase_completed}
次の開始Phase: {next_phase}
累計成果: 設計 {X}件 / TODO登録 {Y}件 / 実装 {Z}件
他 active セッション: {N}件 ({IDリスト})
───────────────────────────────────
```

### 実装ヒント

- 状態ファイル更新は Bash `jq` もしくは Read→編集→Write で行う。 原子性が必要なら一時ファイル書き込み後 `mv` でアトミックに置換
- `started_at` は `TZ=Asia/Tokyo date '+%Y%m%d-%H%M'` の形式
- 起動時に `mkdir -p "${TMPDIR:-/tmp}/app-autopilot-state" devnotes/autopilot-state` を実行
- セッションID 衝突回避: 起動時に `${TMPDIR:-/tmp}/app-autopilot-state/session-{started_at}.json` の存在を確認し、 既存なら `-2` / `-3` … サフィックスを付与
- アーカイブ時: `mv "${TMPDIR:-/tmp}/app-autopilot-state/session-{started_at}.json" devnotes/autopilot-state/{started_at}.json`
- 他 active セッションの列挙: `ls "${TMPDIR:-/tmp}/app-autopilot-state/"session-*.json` し、 各 JSON の `last_updated_at` と `status` を見て active/stale 判定
- Stale 閾値 (180 分) の判定: `date +%s` と `last_updated_at` (`YYYYMMDD-HHMM` をパースして JST → epoch) の差分で計算

---

## Phase 0: 現在地確認（Survey）

**目的**: プロジェクトの現状を把握し、次に取るべきアクションを決定する。

### 0-0. 状態ファイルの初期化／復元

- `--resume` 指定時: 「複数セッションの並行実行」セクションの再開フローに従い、 対象 `session-{id}.json` を読み込んで開始Phaseを決定
- `--resume` なし & 同分の `session-{started_at}.json` が既に存在: セッションID 衝突として `-2` / `-3` … サフィックスを付与して別ファイルとして起動（**他セッションを上書きしない**）
- 新規起動時: `mkdir -p "${TMPDIR:-/tmp}/app-autopilot-state"` 後、 `session-{started_at}.json` を作成し、 `session_id` / `started_at` / `status: "running"` / `claiming_todo_id: null` 等の初期メタ情報を書き込む
- 起動時に他の active セッションを一覧し、 stale なものがあればユーザーに報告（自動アーカイブはしない）

サイクル開始時は `cycles[]` に空エントリを追加し、 `current_cycle` と `current_phase: "phase-0"` を更新し、 `claiming_todo_id` を `null` にクリア。

### 0-1. TODO の読み込み

```
Read: docs/TODO.md（Open / Conditional）
Read: docs/TODO-closed.md（直近の Closed / Obsoleted を把握）
```

以下を確認:
- **Open**: 未着手のTODOがあるか
- **Closed**: 直近で完了したものは何か
- **Obsoleted**: 廃止されたものはあるか

### 0-2. 概念ドキュメントの確認

`AGENTS.md` の使命セクションと、`docs/` 配下に全体像・ロードマップを示すドキュメントがあれば読み、プロジェクトの方向性を把握する。

### 0-3. 設計ファイルの確認

```
Glob: devnotes/*/conceptual-design.md
Glob: devnotes/*/detailed-design.md
```

設計済みだがTODO未登録のもの、設計途中のものがないか確認する。

### 0-4. 他 active セッションの claim 収集

**多セッション並行対応**のキモ。 自セッション以外の `session-*.json` を読み、 次の集合を「claim 済み TODO ID」として収集する:

1. `${TMPDIR:-/tmp}/app-autopilot-state/session-*.json` の一覧（自セッションは除く）
2. 各ファイルについて active 判定（`status: "running"` かつ `last_updated_at` が 180 分以内）
3. active セッションから以下を取り出して和集合を取る:
   - `claiming_todo_id`（Phase 3 で実装中の TODO）
   - `cycles[].implemented_todo_ids` の全要素（既に完了したもの。 TODO.md にまだ反映前のケースを含む）

これを後段の TODO 選択時に Open TODO 候補から除外する。

### 0-5. 現在地の判定と次アクションの決定

以下の優先順位で次のフェーズを決定する:

| 優先度 | 状態 | 次のアクション |
|--------|------|---------------|
| 1 | Open TODO（claim 済みを除いたもの）がある | → Phase 3（Implement）へ。 優先度の高い未 claim TODO から実装 |
| 2 | 詳細設計があるがTODO未登録 | → Phase 2（TODO Add）へ |
| 3 | 概念設計があるが詳細設計未完 | → Phase 1（Design）へ。 既存概念設計を引き継ぐ |
| 4 | 未設計の改善トピックがある（ユーザー指示・監査起因の候補等） | → Phase 1（Design）へ。 新規設計を開始 |
| 5 | 全 Open TODO が他セッションに claim 済 & 設計待ちもなし | → 「他セッションに任せる対象しか残っていない」として完了報告して終了 |
| 6 | 全て完了 | → 完了報告して終了 |

### 0-6. 現在地レポート

```
## Autopilot サイクル {N}/{max-cycles}: 現在地確認 (session: {session_id})

### プロジェクト状況
- Open TODO: {N}件（{ID一覧}）
- Closed TODO: {N}件
- 未登録の設計: {N}件（{ディレクトリ一覧}）
- 未設計のトピック: {N}件（{一覧}）

### 他 active セッション
- {N}件: {session_id 一覧と各セッションの claiming_todo_id}
- claim 済み TODO: {ID一覧}（自セッションでは選ばない）

### 次のアクション
→ Phase {X}（{フェーズ名}）: {具体的に何をするか}
  対象: {TODO ID or トピック名}
  理由: {なぜこれを次にやるか}
```

---

## Phase 1: 設計（Design）

### 1-1. トピックの決定

Phase 0の判定結果に基づき、設計対象のトピックを決定する。

**トピック決定の優先順位**:
1. `topic_or_todo_id` 引数で指定されたトピック
2. 概念設計途中のもの（概念設計はあるが詳細設計がない）
3. 未設計の改善トピック（ユーザー指示・監査起因の候補・ロードマップ文書）

### 1-2. /app-design のバックグラウンド呼び出し

**Agentツール（`run_in_background: true`）で設計スキルを起動する。**

Agentに渡すプロンプト:
```
/app-design {topic} [{conceptual_design_path}]

設計が完了したら、以下を報告せよ:
- 概念設計ファイルパス
- 詳細設計ファイルパス
- 設計のAPPROVEDステータス（Round数含む）
- 施策一覧（タイトル・テーマ・優先度・モード）
```

**バックグラウンド完了の通知を受けたら**、結果を確認して次フェーズに進む。
待機中はユーザーに状況を報告する:
```
設計をバックグラウンドで実行中です（/app-design {topic}）
完了通知を受け次第、Phase 2（TODO登録）に進みます。
```

### 1-3. フェーズ完了確認

バックグラウンドAgentの結果から:
- 設計が APPROVED になったことを確認
- `design_dir` と施策情報を次フェーズに引き継ぐ
- 失敗した場合はエラー内容を報告してサイクルを中断

### 1-4. 状態ファイル更新

自セッションの `session-{started_at}.json` の当該サイクルエントリに `designed_topic` / `design_tmp_dir` を記録し、 `phase_completed: "phase-1"`、 `current_phase: "phase-2"` に更新。

---

## Phase 2: TODO登録（TODO Add）

### 2-1. 設計情報の抽出

詳細設計書から以下を抽出する:
- **title**: 施策タイトル
- **theme**: テーマ分類（frontend / backend / infrastructure / test / docs / general）
- **summary**: 30文字以内の概要
- **priority**: 優先度（詳細設計の記載 or Critical）
- **mode**: 実装モード（詳細設計の推奨モード）

### 2-2. /app-todo-add のバックグラウンド呼び出し

**Agentツール（`run_in_background: true`）でTODO登録スキルを起動する。**

Agentに渡すプロンプト:
```
/app-todo-add "{title}" {theme} "{summary}" {design_dir_name} {priority} {mode}

完了したら、登録されたTODO IDを報告せよ。
```

待機中はユーザーに状況を報告する:
```
TODO登録をバックグラウンドで実行中です（{title}）
完了通知を受け次第、Phase 3（実装）に進みます。
```

### 2-3. TODO IDの記録

バックグラウンドAgentの結果から登録されたTODO ID（例: `T002`）を取得し、次フェーズに引き継ぐ。

### 2-4. 状態ファイル更新

自セッションの `session-{started_at}.json` の当該サイクルの `added_todo_ids` に追加、 `phase_completed: "phase-2"`、 `current_phase: "phase-3"` に更新。

---

## Phase 3: 実装（Implement）

### 3-1. 実装対象の決定

**TODO IDが引き継がれている場合**: そのIDを使用。 ただし Phase 0 で他セッションが claim している TODO だと判明している場合はサイクル中断（重複実装の事故防止）。

**Phase 0から直接来た場合**: Open TODO から「他セッションが claim していない」もので優先度の高いものを選択。
- Critical > High > Medium > Low
- 同一優先度なら追加日が古いものを優先

### 3-1.5. claim の書き込み（多セッション必須ステップ）

実装に取りかかる前に、 自セッションの state ファイルへ `claiming_todo_id` を即書き出す:

```
session-{started_at}.json:
  claiming_todo_id: "{todo_id}"
  last_updated_at: "{現在時刻}"
```

これにより他セッションが Phase 0 で当該 TODO を選ばないようになる。 **Agent 起動より前に書き込むこと**（書き込み前に他セッションが Phase 0 を走らせると重複取得が起こるため）。

### 3-2. /app-implement のバックグラウンド呼び出し

**Agentツール（`run_in_background: true`, `isolation: "worktree"`）で実装スキルを起動する。**

Agentに渡すプロンプト:
```
/app-implement {todo_id} [--skip-consensus]

実装が完了したら、以下を報告せよ:
- マージコミットハッシュ
- 変更ファイル数
- テスト結果（passed / failed）
- Codexレビュー結果（APPROVED / SKIPPED）
- TODOクローズ結果
- コンフリクトの有無と解消結果
```

このスキルが以下を全て実行する:
- worktree作成（`.claude/worktrees/tasks/{todo_id}`）
- 実装 & テスト
- Codexレビュー合議
- コミット
- TODOクローズ（/app-todo-close）
- mainマージ
- worktreeクリーンアップ

待機中はユーザーに状況を報告する:
```
実装をバックグラウンドで実行中です（{todo_id}）
worktree分離環境で実装・テスト・レビュー・マージを自動実行しています。
完了通知を受け次第、Phase 4（チェックポイント）に進みます。
```

### 3-3. 実装結果の記録

バックグラウンドAgentの結果からマージコミットハッシュ、テスト結果、レビュー結果を取得・記録する。
失敗した場合はエラー内容を報告してサイクルを中断。

### 3-4. 状態ファイル更新

自セッションの `session-{started_at}.json` の当該サイクルに `implemented_todo_ids` 追加、 `merge_commit` を記録、 `phase_completed: "phase-3"`、 `current_phase: "phase-4"` に更新。 **`claiming_todo_id` は `null` に戻す**（実装完了したため claim を解除）。 失敗時は `error` に内容を書いて `status: "failed"` にする（`claiming_todo_id` は事後追跡のため残置）。

---

## Phase 4: チェックポイント（Checkpoint）

### 4-1. サイクル完了報告

```
## Autopilot サイクル {N}/{max-cycles} 完了

### 今サイクルの成果
- 設計: {トピック名}（APPROVED）  ← Phase 1を実行した場合
- TODO: {ID} 登録済み             ← Phase 2を実行した場合
- 実装: {ID} → main マージ済み    ← Phase 3を実行した場合
  - 変更ファイル: {N} files
  - テスト: {結果}
  - コミット: {hash}

### 残タスク
- Open TODO: {N}件（{ID一覧}）
- 未登録の設計: {N}件
- 未設計のトピック: {N}件
```

### 4-2. 継続判断

サイクル完了時に自セッションの `session-{started_at}.json` の当該サイクルエントリの `phase_completed: "phase-4"` と `ended_at` を書き込む。

**`--repeat` なしの場合**: 状態ファイルを `status: "completed"` にしてアーカイブ（`${TMPDIR:-/tmp}/app-autopilot-state/session-{started_at}.json` → `devnotes/autopilot-state/{started_at}.json` に `mv`）後、 最終報告を出力して**即座に終了**する。

**`--repeat` ありの場合**: 以下の条件を**全て**満たせば次のサイクルに進む:

1. `max-cycles`（デフォルト5）に達していない
2. 次にやるべきことがある（Open TODO、未登録設計、未設計トピックのいずれかが残っている）
3. 前サイクルでエラーが発生していない

**いずれかを満たさない場合**: 最終報告を出力して終了。

### 4-3. 次サイクルへの遷移（--repeat 時のみ）

監査タイミングかどうかを判定し、Phase 4A または Phase 0 に進む:

```
サイクル番号 % audit-interval == 0 → Phase 4A（多角監査）
それ以外 → Phase 0（現在地確認）に直行
```

---

## Phase 4A: 多角監査（Multi-Perspective Evaluation）

**目的**: 個々のTODOを片付ける「木を見る」作業の合間に、「森を見る」視点で全体の健全性を点検する。問題を早期発見し、次サイクルの設計・優先順位に反映する。

**実行タイミング**: `audit-interval`（デフォルト2）サイクルごと。`audit-interval=0` でスキップ。

### 4A-1. 監査の準備

```bash
TZ=Asia/Tokyo date '+%Y%m%d-%H%M'
```

監査結果の保存先: `devnotes/{YYYYMMDD-HHMM}-audit-cycle-{N}/`

### 4A-2. 五つの監査観点（バックグラウンド並列実行）

**5つの監査観点それぞれをバックグラウンドAgentとして同時起動する。** 各Agentは独立して点検を行い、全Agentの完了通知を受けてから統合レポートを作成する。

ユーザーへの報告:
```
多角監査を5つのバックグラウンドAgentで並列実行中です:
1. 使命整合性監査
2. 技術的負債監査
3. UI一貫性監査
4. セキュリティ監査
5. ドキュメント鮮度監査

全Agentの完了後、統合レポートを作成します。
```

#### 観点1: 使命整合性監査（Mission Alignment）

直近のサイクルで実装した変更が、使命（AGENTS.md の North Star）から逸脱していないか確認する。

**手順**:
1. `AGENTS.md` の「使命 (North Star)」セクションを読む
2. `git log --oneline -10` で直近のコミットを確認
3. 各コミットの変更内容を `git diff {hash}~1 {hash} --stat` で把握
4. 以下を判定:
   - この変更は使命の達成に寄与しているか？
   - 使命と無関係な機能追加・過剰な技術的こだわりはないか？

**出力**:
```
### 使命整合性: {OK / DRIFT_DETECTED}
- {各コミットの判定}
- 逸脱があれば: 修正提案 or 次サイクルでの設計課題として記録
```

#### 観点2: 技術的負債監査（Tech Debt）

コードベースに蓄積しつつある技術的負債を検出する。

**手順**:
1. `composer phpstan 2>&1 | tail -20` — PHPStanの状態確認
2. `composer test 2>&1 | tail -10` — テストの健全性
3. `pnpm lint 2>&1 | tail -10` / `pnpm typecheck 2>&1 | tail -10` — フロントエンドの健全性
4. 直近の変更でTODOコメント・FIXME・HACKが増えていないか:
   ```
   Grep: TODO|FIXME|HACK|XXX（app/ と resources/js/ を対象）
   ```
5. 依存パッケージの状態:
   ```bash
   composer outdated --direct 2>&1 | head -20
   pnpm outdated 2>&1 | head -20
   ```

**出力**:
```
### 技術的負債: {CLEAN / DEBT_FOUND}
- PHPStan: {OK / N errors}
- テスト: {N passed, M failed}
- lint/typecheck: {OK / NG}
- TODO/FIXME: {N箇所}（増減: +X / -X）
- 依存パッケージ: {outdated があれば列挙}
- 対応が必要な場合: 次サイクルのTODO候補として記録
```

#### 観点3: UI一貫性監査（UI Consistency）

フロントエンドの一貫性・ユーザー体験の統一性を確認する。

**手順**:
1. `resources/js/pages/` 配下のSvelteファイルを走査
2. 以下を確認:
   - 同じ機能に対して異なるUIパターンが使われていないか
   - エラーハンドリングの表示方法が統一されているか
   - ナビゲーション・ブレッドクラム等の共通要素が一貫しているか
   - レスポンシブ対応の抜け漏れはないか
   - `DESIGN.md` の token 体系から逸脱した hex 直書き・独自スタイルが増えていないか
3. `resources/js/components/` の共通コンポーネント（atoms/molecules/organisms/templates）が適切に活用されているか（似た実装が各ページに散在していないか）

**出力**:
```
### UI一貫性: {CONSISTENT / INCONSISTENCY_FOUND}
- {発見事項}
- 改善が必要な場合: 次サイクルのTODO候補として記録
```

#### 観点4: セキュリティ監査（Security）

OWASP Top 10 と `AGENTS.md` の「セキュリティ不変条件」を中心としたセキュリティリスクの点検。

**手順**:
1. 直近コミットの変更ファイルを対象に:
   - 認可チェック（Policy/Gate）の漏れはないか
   - 入力バリデーションの不足はないか
   - SQLインジェクション・XSSのリスクはないか
   - CSRFトークンの確認
   - 機密情報（APIキー、パスワード等）の漏洩リスクはないか
   - AGENTS.md のセキュリティ不変条件（tenant キー不信・nested route 404 guard・cross-org 不可等）に違反していないか
2. `routes/web.php` と `routes/api.php` でミドルウェア設定を確認
3. `.env.example` と実際の設定に不整合がないか

**出力**:
```
### セキュリティ: {SECURE / RISK_FOUND}
- {発見事項}
- Critical な場合: 即座にTODO登録を推奨
```

#### 観点5: ドキュメント鮮度監査（Documentation Freshness）

実装とドキュメントの乖離を検出する。

**手順**:
1. `/app-update-docs` スキルの Step 2（陳腐化チェック）の手法を簡易実行
2. 直近コミットで変更されたファイルに対応するドキュメントが更新されているか
3. `AGENTS.md` / `CLAUDE.md` が最新の状態か

**出力**:
```
### ドキュメント鮮度: {FRESH / STALE_FOUND}
- {乖離があれば列挙}
- 更新が必要な場合:
  - 軽微: 次サイクルで `/app-update-docs` を実行
  - 重要: 即座に修正
```

### 4A-3. 監査結果の統合レポート

```
## 多角監査レポート（サイクル {N} 完了時点）

| 観点 | 判定 | 要対応 |
|------|------|--------|
| 使命整合性 | {OK/DRIFT} | {なし / 内容} |
| 技術的負債 | {CLEAN/DEBT} | {なし / 内容} |
| UI一貫性 | {OK/INCONSISTENCY} | {なし / 内容} |
| セキュリティ | {SECURE/RISK} | {なし / 内容} |
| ドキュメント鮮度 | {FRESH/STALE} | {なし / 内容} |

### 監査起因の新規TODO候補
{監査で発見された問題を、次サイクルで設計・実装すべきTODO候補として列挙}
- [{priority}] {タイトル}: {概要}
```

監査結果を保存:
```
devnotes/{YYYYMMDD-HHMM}-audit-cycle-{N}/audit-report.md
```

自セッションの `session-{started_at}.json` の当該サイクルエントリに `audit_report_path` を記録し、 `phase_completed: "phase-4a"` に更新。

### 4A-4. 監査結果の反映

1. **Critical な発見**（セキュリティリスク等）: 次サイクルの Phase 0 で最優先TODO候補として扱う
2. **ドキュメント陳腐化**: 次サイクルの Phase 0 で `/app-update-docs` の実行を判断
3. **使命ドリフト**: 次サイクルの設計時に方向修正を意識
4. **技術的負債**: 蓄積が深刻であれば専用のリファクタリングTODOを起票

→ Phase 0 に戻って次のサイクルを開始。

---

## 最終報告

全サイクル完了後（または中断時・Single Shot完了時）に出力する。

**先に状態ファイルを確定**する:
- 正常完了: `session-{started_at}.json` を `status: "completed"` にしてから `devnotes/autopilot-state/{started_at}.json` へ `mv`（アーカイブ）
- 中断: `status: "interrupted"`、 `session-{started_at}.json` はそのまま残す（次回 `--resume --session {id}` で続行可能）
- エラー停止: `status: "failed"`、 `session-{started_at}.json` はそのまま残す

その後レポートを出力する:

```
## Autopilot 完了

### 実行サマリー
- モード: {Single Shot / Repeat}
- 実行サイクル数: {N}/{max-cycles}
- 完了TODO: {ID一覧}
- 新規設計: {トピック一覧}
- 新規TODO: {ID一覧}
- 多角監査: {実施回数}回

### 成果物
| サイクル | フェーズ | 対象 | 結果 |
|---------|---------|------|------|
| 1 | Design | {topic} | APPROVED |
| 1 | TODO Add | {ID} | 登録済み |
| 1 | Implement | {ID} | main マージ済み |
| 2 | Evaluation | - | {判定サマリー} |
| 2 | ... | ... | ... |

### 残タスク
- Open TODO: {N}件（{ID一覧}）
- 未登録の設計: {N}件
- 未設計のトピック: {N}件

### 監査サマリー（--repeat時のみ）
- 使命整合性: {全サイクル通しての傾向}
- 技術的負債: {増減トレンド}
- 注意事項: {次回起動時に意識すべきこと}

### 次のアクション提案
- {何をすべきか}
```

---

## 引数による開始地点の制御

### topic_or_todo_id の解釈

| 値の形式 | 解釈 | 開始フェーズ |
|---------|------|------------|
| `T{NNN}` | TODO ID → 実装から開始 | Phase 3 |
| その他の文字列 | トピック名 → 設計から開始 | Phase 1 |
| 省略 | 自動判断 | Phase 0 |

### --from による強制指定

| 値 | 開始フェーズ |
|-----|------------|
| `survey` | Phase 0（デフォルト） |
| `design` | Phase 1 |
| `todo-add` | Phase 2 |
| `implement` | Phase 3 |
| `next-cycle` | Phase 4 → Phase 0 |

---

## エラーハンドリング

### スキル呼び出し失敗

各フェーズでスキル呼び出しが失敗した場合:

1. エラー内容をユーザーに報告
2. **自動リカバリーを試みない**（サブスキル内でリトライ済みのため）
3. 現在のサイクルを中断し、最終報告を出力

### 設計ファイル不整合

- 概念設計はあるが詳細設計がない → Phase 1（Design）で概念設計を引き継いで続行
- TODO.mdの設計リンクが壊れている → ユーザーに報告して手動修正を依頼

### max-cycles の安全弁

- Single Shot: 常に1サイクル
- Repeat: デフォルト5サイクル、最大10サイクル（10を超える指定は10に切り詰める）
- 各サイクル開始時に残サイクル数を表示

---

## 使用例

### 例1: 1回だけ実行（デフォルト）
```
/app-autopilot
→ 現在地確認→最も優先度の高いタスクを1つ設計→実装→マージして終了
```

### 例2: 特定TODOを1つ実装
```
/app-autopilot T001
→ T001をworktreeで実装→マージして終了
```

### 例3: 自走モード
```
/app-autopilot --repeat
→ 最大5サイクル、2サイクルごとに多角監査を挟みながら自走
```

### 例4: フル自走（監査頻度高め）
```
/app-autopilot --repeat --max-cycles 10 --audit-interval 1
→ 最大10サイクル、毎サイクル監査付きで自走
```

### 例5: 設計から開始して自走
```
/app-autopilot auth-refactor --repeat
→ auth-refactorの設計から始めて、完了後は次のタスクへ自走
```

---

## バックグラウンド実行方針

### 原則

**重い処理はバックグラウンドAgentに委譲し、Autopilot本体は判断・進行管理に専念する。**

| フェーズ | 実行方式 | 理由 |
|---------|---------|------|
| Phase 0（Survey） | **フォアグラウンド** | 軽量な読み取りのみ。判断に直結するため即座に結果が必要 |
| Phase 1（Design） | **バックグラウンドAgent** | Codex合議を含む重い処理。完了通知を待つ |
| Phase 2（TODO Add） | **バックグラウンドAgent** | 設計ファイル確認・TODO.md編集。完了通知を待つ |
| Phase 3（Implement） | **バックグラウンドAgent + worktree分離** | 最も重い処理。worktreeで分離実行 |
| Phase 4（Checkpoint） | **フォアグラウンド** | 軽量な集計・判断のみ |
| Phase 4A（Evaluation） | **バックグラウンドAgent** | 5観点の点検は独立して実行可能 |

### バックグラウンド起動時の共通ルール

1. **Agentツールの `run_in_background: true` を使用**する
2. Phase 3（Implement）では追加で **`isolation: "worktree"`** を指定する
3. バックグラウンドAgent起動後、**ユーザーに状況を報告**する（何を実行中か、次に何が起きるか）
4. **完了通知を受けたら結果を確認**し、次フェーズに進む。ポーリング・スリープはしない
5. バックグラウンドAgentが失敗した場合は**エラー内容をユーザーに報告**してサイクルを中断

### 並列実行が可能なケース

Phase 4A（多角監査）の5観点は互いに独立しているため、**5つのバックグラウンドAgentを同時起動**して並列実行できる:

```
Agent 1: 使命整合性監査
Agent 2: 技術的負債監査
Agent 3: UI一貫性監査
Agent 4: セキュリティ監査
Agent 5: ドキュメント鮮度監査
```

全Agentの完了通知を受けてから統合レポートを作成する。

---

## 注意事項

- **このスキルは他のスキルのオーケストレーターである。** 設計・実装・TODO管理のロジックは各サブスキルに委譲し、自身は判断・呼び出し・進捗管理・監査に徹する
- **Phase 0（Survey）は毎サイクル実行する。** 前サイクルの結果だけでなく、外部変更（ユーザーによるTODO追加・設計変更等）も拾うため
- **多角監査は「森を見る」ための仕組みである。** 個々のTODOの品質はサブスキル（Codexレビュー等）が保証する。監査はプロジェクト全体の方向性・健全性を俯瞰する
- **サブスキルの出力を信頼する。** サブスキルが APPROVED / 完了を報告したら、再検証しない
- **ユーザーへの報告は各Phase完了時に行う。** サイレントに進行せず、進捗を可視化する
- **バックグラウンドAgentの完了を待つ間、ポーリングやスリープをしない。** 通知が来るまでユーザーと対話可能な状態を維持する

---

## ⚠ CRITICAL: ループ継続の絶対ルール（コンテキスト圧縮後も必ず従うこと）

**このセクションはスキルファイルの末尾に配置されている。コンテキストが圧縮・要約されても、このルールは失われてはならない。**

### `--repeat` が指定されている場合、1サイクルで終了してはならない

`--repeat` モードでは、以下の**停止条件のいずれかを満たすまで**、必ず Phase 0 に戻って次のサイクルを開始すること:

1. `max-cycles` に達した
2. やることが何もない（Open TODO = 0、未登録設計 = 0、未設計トピック = 0）
3. エラーが発生してリカバリー不能

**上記3つ以外の理由で停止してはならない。**

### サイクル終了時の自己チェック

各サイクルの Phase 4（Checkpoint）完了時に、以下を必ず確認すること:

```
□ --repeat が指定されているか？
  → YES: 停止条件を満たしているか？
    → 停止条件を満たしていない → Phase 0 に戻る（次サイクル開始）
    → 停止条件を満たしている → 最終報告を出力して終了
  → NO (Single Shot): 最終報告を出力して終了
```

### コンテキストが長くなった場合の対処

会話が長くなりコンテキストが圧縮された場合でも:

1. **このスキルファイルを `Read` ツールで再読み込み**して、ループ継続の判断を行うこと
2. 「前のサイクルで何をしたか覚えていない」は停止理由にならない。Phase 0 で現在地を再確認すれば十分
3. 迷ったら **Phase 0（Survey）に戻る**。現在地確認は常に安全な選択肢

### ループ状態の明示

各サイクル開始時に、以下を必ず出力して自身のループ状態を明示すること:

```
───────────────────────────────────
Autopilot サイクル {N}/{max-cycles} 開始
モード: Repeat
残サイクル: {max-cycles - N + 1}
───────────────────────────────────
```

これにより、コンテキスト圧縮後も自身がループ中であることを認識できる。
