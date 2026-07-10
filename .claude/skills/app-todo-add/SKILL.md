---
name: app-todo-add
description: TODOリストにタスクを追加するスキル（概念設計・詳細設計の存在確認あり、なければReject）
user-invocable: true
argument-hint: '"title" theme "summary" design_dir [priority] [mode]  例: /app-todo-add "認可リファクタ" backend "Policy層をLaratrust統合に変更" 20260401-1200-auth'
---

# TODO 追加

## 引数

| 引数 | 必須 | 説明 |
|------|------|------|
| title | Yes | TODOのタイトル（簡潔な改善名） |
| theme | Yes | テーマ分類: frontend / backend / infrastructure / test / docs / general |
| summary | Yes | 30文字以内の実装概要（何をするか一言で） |
| design_dir | Yes | devnotesディレクトリ名（例: 20260401-1200-auth）。conceptual-design.md と detailed-design.md が配置されていることを確認 |
| priority | No | 優先度: Critical / High / Medium / Low（省略時: Medium） |
| mode | No | 実装モード: incremental / standalone（省略時: incremental） |
| target | No | 追加先: open（デフォルト）/ conditional（条件付き待機。trigger_condition必須） |
| trigger_condition | No | トリガー条件（target=conditional時のみ必須） |

TODOリスト（`docs/TODO.md`）に改善タスクを追加する。
**概念設計・詳細設計の両ファイルが存在することを確認してから追加する。存在しない場合は Reject する。**

---

## 実装モードの説明

| モード | 説明 | 向いているケース |
|--------|------|----------------|
| **incremental** | 小規模で他施策と並行可能 | 単一コンポーネントの変更、競合リスクが低い |
| **standalone** | 個別に実装セッションを開始 | 複数コンポーネントの協調変更、大規模変更 |

## テーマ分類の説明

| テーマ | 向いているケース |
|--------|----------------|
| `frontend` | Svelteコンポーネント・UI・Inertia Props・CSS・デザインシステム |
| `backend` | Laravel Service・Controller・Model・認可・ルーティング・LLM連携 |
| `infrastructure` | DB設計・CI/CD・Docker・パフォーマンス最適化 |
| `test` | テスト基盤・カバレッジ改善・Architectureテスト |
| `docs` | ドキュメント整備・更新 |
| `general` | 上記に分類されない汎用改善 |

（アプリ固有のテーマ分類が必要になったら、この表と `docs/TODO.md` のヘッダコメントに追記して拡張する）

---

## 手順

### Step 1: 設計ファイルの存在確認

`design_dir` からファイルパスを構成し、それぞれ `Read` ツールで読み込みを試みる:

```
conceptual_design_path = devnotes/{design_dir}/conceptual-design.md
detailed_design_path   = devnotes/{design_dir}/detailed-design.md
```

- **どちらかでもファイルが存在しない場合**:

  ```
  REJECT

  以下のファイルが存在しません:
  - {存在しないファイルのパス}

  TODO への追加には概念設計・詳細設計の両ファイルが必要です。
  先に /app-design スキルで設計を完成させてから再度 /app-todo-add を実行してください。
  ```

  **ここで処理を終了する（TODO.md は変更しない）**。

### Step 2: 引数のバリデーション

- `theme` が `frontend` / `backend` / `infrastructure` / `test` / `docs` / `general` のいずれかでない場合はエラー
- `summary` が30文字を超える場合は警告を表示し、30文字以内に短縮するよう案内する
- `priority` が省略された場合は `Medium`
- `mode` が省略された場合は `incremental`
- `target` が省略された場合は `open`
- `target` が `conditional` の場合、`trigger_condition` が指定されていなければエラー

### Step 3: ID 採番と日時取得

`docs/TODO.md`（および必要に応じて `docs/TODO-closed.md`）を読み込み、既存の最大IDから次のIDを決定する:

```bash
TZ=Asia/Tokyo date '+%Y-%m-%d %H:%M'
```

既存IDの最大値（Open / Conditional / Closed / Obsoleted 全体を通した最大値）を取得し、+1したものを `T{NNN}` 形式で採番する（初回は `T001`）。

### Step 4: テーブルへの行追記

`docs/TODO.md` の Open テーブル（または Conditional テーブル）の末尾に `Edit` ツールで行を追加する。

#### Open テーブルの行フォーマット:

```
| {ID} | {title} | {theme} | {summary} | {priority} | {mode} | [設計](devnotes/{design_dir}/) | {today} |
```

#### Conditional テーブルの行フォーマット:

```
| {ID} | {title} | {theme} | {summary} | {trigger_condition} | {priority} | {mode} | [設計](devnotes/{design_dir}/) | {today} |
```

### Step 5: 完了報告

```
TODO 追加完了

ID: {ID}
タイトル: {title}
テーマ: {theme}
概要: {summary}
優先度: {priority}
実装モード: {mode}
設計: devnotes/{design_dir}/
追加日時: {today}
```

---

## 注意事項

- 設計列は `[設計](devnotes/{design_dir}/)` 形式でディレクトリリンクとして記録する
- `summary` はテーブルが読みやすいよう30文字以内に収めること
- `docs/TODO.md` には **Open / Conditional のみ**を置く。Closed / Obsoleted は `docs/TODO-closed.md` に分離されている（移動は `/app-todo-close` の責務）
- `docs/TODO.md` が存在しない場合は、テーブルヘッダーを含めて新規作成する
