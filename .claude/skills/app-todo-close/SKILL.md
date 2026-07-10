---
name: app-todo-close
description: TODOリストのタスクを完了マークまたは廃止マーク（TODO.md Open → TODO-closed.md Closed/Obsoleted 移動）
user-invocable: true
argument-hint: "<todo_id> [--action obsolete --reason \"理由\"]  例: /app-todo-close T012"
---

# TODO クローズ / 廃止

## 引数

| 引数 | 必須 | 説明 |
|------|------|------|
| todo_id | Yes | 対象TODOのID（例: T012） |
| --action | No | close（デフォルト）= Open→Closed、obsolete = Open/Conditional→Obsoleted |
| --reason | No | 廃止理由（action=obsolete時は必須。例: 設計陳腐化、要件変更により不要） |

指定した TODO を `docs/TODO.md` の Open / Conditional テーブルから削除し、`docs/TODO-closed.md` の **Closed** または **Obsoleted** テーブルへ移動する。

- `action` 省略 or `close`: Open → **Closed**（実装完了）
- `action` = `obsolete`: Open or Conditional → **Obsoleted**（設計陳腐化等）

---

## 手順

### Step 1: 引数バリデーション

`action` = `obsolete` の場合、`reason` が指定されていなければエラー:
```
ERROR

action=obsolete には reason が必須です。
例: /app-todo-close T012 --action obsolete --reason "要件変更により不要"
```
**ここで処理を終了する。**

### Step 2: 今日の日時を取得

```bash
TZ=Asia/Tokyo date '+%Y-%m-%d %H:%M'
```

### Step 3: TODO.md から対象行を検索し、TODO-closed.md へ移動

1. `docs/TODO.md` を `Read` ツールで読み込む
2. Open テーブル（`close` 時）または Open + Conditional テーブル（`obsolete` 時）から `{todo_id}` を含む行を検索
3. **行が見つからない場合**:
   ```
   ERROR

   {todo_id} は Open / Conditional リストに存在しません。

   - すでに Closed/Obsoleted になっている可能性があります（docs/TODO-closed.md を確認）
   - ID の指定が間違っている可能性があります
   ```
   **ここで処理を終了する。**

4. **行が見つかった場合**:
   - `Edit` ツールで `docs/TODO.md` の元テーブルから該当行を削除
   - `docs/TODO-closed.md` を `Read` し、Closed テーブル（`close` 時）または Obsoleted テーブル（`obsolete` 時）の末尾に `Edit` ツールで追加
   - `close` の場合の行フォーマット: `| {ID} | {タイトル（実装サマリーを追記してよい）} | {テーマ} | {today} |`
   - `obsolete` の場合の行フォーマット: `| {ID} | {タイトル} | {テーマ} | {today} | {reason} |`

### Step 4: 完了報告

#### close の場合:
```
TODO クローズ完了

ID: {todo_id}
タイトル: {タイトル}
テーマ: {テーマ}
完了日時: {today}

docs/TODO.md → docs/TODO-closed.md に移動しました（Open → Closed）。
```

#### obsolete の場合:
```
TODO 廃止完了

ID: {todo_id}
タイトル: {タイトル}
テーマ: {テーマ}
廃止日時: {today}
理由: {reason}

docs/TODO.md → docs/TODO-closed.md に移動しました（Open/Conditional → Obsoleted）。
```

---

## 注意事項

- Open / Conditional テーブルが空になった場合、テーブルのヘッダー行は残す
- `close` は Open テーブルのみが対象（Conditional 項目は直接クローズ不可。先に Open への昇格が必要）
- `obsolete` は Open と Conditional の両テーブルを検索する
- `docs/TODO-closed.md` が存在しない場合は、Closed / Obsoleted のテーブルヘッダーを含めて新規作成する
