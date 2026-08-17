# 全体判定: CHANGES_REQUESTED

主要設計は承認可能な水準です。Round 2 の Critical は解消されており、新たな Critical はありません。ただし、設計内に [Warning] 4 件の不整合が残っています。

## 施策 0: 腐り検知テスト

判定: REQUEST_CHANGES

- [Warning] 「36 契約」という表記と実際の表が一致しません。`5b`、`5c`、`12b`、`23b`、`32b` を独立契約として数えると、表は少なくとも41行あります。さらに `24`、`25` などは複数ケースを含みます。

  修正案: 補助番号を廃止して通し番号を振り直すか、「契約数は実装時に確定」として現段階では数を主張しないでください。

- [Warning] 原子的書き込みは「書き込みエラー・chmod エラー・置換エラー」を保証対象にしていますが、障害注入は `os.replace` だけです。

  修正案: temp 作成後、replace 前に失敗する経路として、少なくとも `os.chmod` または `fh.write` の例外を注入し、sentinel 不変・temp 削除を確認してください。これで入力検証前、temp 作成後、replace 時の3段階をカバーできます。

なお、`REPO_ROOT = SKILL_DIR.parents[2]` への修正は正しいです。対応マトリクスの「誤った root でも全件緑」は実際には根拠パス不在で赤になる可能性が高いため、「正しい対象を検査できない」に直すと正確です。

## 施策 1: 生成器

判定: APPROVE

出力される全文字列のマーカー偽装防止、supersede 関係の検証、whitespace-only の拒否が追加され、生成物の構造と matcher の有効性判定が整合しました。

## 施策 2: 移行台帳

判定: REQUEST_CHANGES

- [Warning] hash 正規化方式の記述が内部で食い違っています。

  共通契約では次の完全な正規化を定義しています。

  ```python
  json.dumps(
      projection,
      sort_keys=True,
      ensure_ascii=False,
      separators=(",", ":"),
  )
  ```

  一方、`check_migration()` の説明では `separators=(",", ":")` が抜けています。実装者が後者を写すと期待 hash と一致しません。

  修正案: `canonical_machine_projection()` のような単一関数を正本として定義し、生成器とテストの両方がそれを使う設計にしてください。少なくとも全記述を同じ引数へ統一する必要があります。

三点一致による pin 自体は妥当で、Round 2 の Critical は解消されています。

## 施策 3: A-001 の移行

判定: REQUEST_CHANGES

- [Warning] 後続タスクの内容に、現在の移行契約と矛盾があります。

  後続タスクは「A-001 を supersede する新登録を追加する」としながら、「移行台帳の鍵 A-001 と `machine_projection_sha256` も更新する」としています。しかし append-only なら既存 A-001〜A-003 の機械射影は変わらず、新しい登録は「pin にない新規 ID」として検査対象外です。したがって既存 hash や移行キーを更新する理由がありません。

  移行キーまで後継へ動かすと、「旧 `spec-ledger.md` のブロックが A-001 へ移った」という provenance も書き換わってしまいます。

  修正案: 後続タスクを次の形にしてください。

  - A-001 は機械項目・context・移行キー・hash を変更しない。
  - 新登録を append し、A-001 を supersede する。
  - 新登録へ修正済み `watch_globs` と必要な context を持たせる。
  - 新登録は移行時点に存在しなかったため、migration provenance の hash pin 対象へ加えない。

## 施策 4: A-003 の context

判定: APPROVE

一次資料と移行時確認の区別、再オープン条件、watch_globs が自動検出しない範囲が明確です。

## 施策 5: 生成物への置換

判定: APPROVE

再生成済み出力に限定した完全性保証と、CI 非実行による stale 状態の可能性が正確に記述されています。

## 施策 6: README 更新

判定: APPROVE

「正常に再生成すれば」という限定が入り、二層の fail-closed 境界も正確です。

## 施策 7: AGENTS.md 更新

判定: APPROVE

保証範囲、JSON 構文エラー、active/superseded の区別が簡潔かつ正確です。

## 後続タスクの扱い

判定: REQUEST_CHANGES

- [Warning] TODO 登録を本タスクの完了条件に追加したため、実際の変更対象に `docs/TODO.md` が増えていますが、施策一覧と波及変更には載っていません。また末尾にはまだ「後続タスクの候補として申し送る」とあり、「必ず起票する」と矛盾します。

  修正案:

  - 施策一覧へ「施策 8: app-todo-add による後続 TODO 登録」を追加する。
  - 変更ファイルへ `docs/TODO.md` を追加する。
  - 「候補として申し送る」を「上記の確定内容で必ず登録する」へ統一する。
  - TODO 登録によって追加される ID を実装完了報告へ記載する。

以上を修正すれば、設計の中核に残る問題はありません。次ラウンドでは APPROVED と判断できる見込みです。