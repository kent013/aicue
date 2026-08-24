# 変異表 (v1 用の再取得。T259 実装時の実測)

T144 が残した `devnotes/20260810-1143-todo-T144/mutation-evidence.md` の MU8
(`carried_forward_through` の単調性) は**概念ごと消える**ため、正典 v1 (二段判定・収束繰越形) の
要求に合わせて変異表を作り直した。

## 測り方

worktree `.claude/worktrees/tasks/T259` で、実装へ 1 つずつ変異を入れて次の 3 ファイルを走らせ、
**どのテストが赤になるか**を実測した (実測後は毎回元へ戻している)。

```
vendor/bin/pest tests/Feature/Billing/TicketLedgerCarryForwardTest.php \
                tests/Unit/Billing/CarryForwardGroupTest.php \
                tests/Architecture/TicketLedgerMutationSiteGateTest.php
```

## 結果: 基本の 7 変異 (すべて検出された)

| # | 変異 | 実際に入れた変更 | 赤になったテスト |
|---|---|---|---|
| MU1 | **第 2 段の寄与判定を落とす** (v0 の単段へ戻す) | `expiredScope()` を `whereRaw('1 = 0')` にし、`contributingGroups()` / `groupScope()` から寄与述語 (`expires_at IS NULL OR > now`) を外す | N1 / N4 / N18 / 検証 1〜4・7 |
| MU2 | **繰越行の `created_at` を実行時刻に戻す** | `appendCarryForward()` の `$group->maxCreatedAt` → `CarbonImmutable::now()` | N2 / N5 / N18 / 「繰越行は残高の粒度 3 つだけを引き継ぐ」 |
| MU3 | **収束の短絡を外す** | `rowCount === 1 && carryForwardRows === 1` の `continue` を削除 | N3b |
| MU4 | **int4 の範囲検査を外す** | `CarryForwardGroup::int4()` の上下限を PHP 整数の範囲へ緩める | DTO テスト 3 (`delta_sum` が int4 の境界 +1 なら例外) |
| MU5 | **件数照合を外す** | `$deleted !== $group->rowCount` の例外を削除 | N10 |
| MU6 | **`withTrashed()` を外す** | `Organization::withTrashed()` → `Organization::query()` (2 箇所) | N12/N13 / N14 / TLM-4 / TLM-7 |
| MU7 | **決着対象から失効した繰越行を外す** | `settlementPredicate()` を `kind != carry_forward` だけにする | N18 |

## 追加: 境界演算子の 2 変異 (Codex 実装レビュー Round 1・2 の指摘を受けて追加)

| 変異 | 赤になったテスト |
|---|---|
| 削除枝の `expires_at <= now` を `<` にする | N1b (静止した境界) |
| 寄与枝の `expires_at > now` を `>=` にする | **N1c** (削除 → 集約の窓に境界行が割り込む) |

★**訂正の記録**: Round 1 の時点では後者を「等価変異」と書いていたが、これは**誤り**だった。
静止した fixture では削除枝が先に `expires_at = now` の行を消すので観測できないだけで、
**組織行ロックを取らない追記経路** (`grantMonthly` / `grantPurchased`) は
削除と集約の間に commit しうる (サービスの docblock がこの窓を明記している)。
その窓に境界行を差し込むと `>` と `>=` は**振る舞いが分かれる** —

- `>` (正) … 割り込んだ行は寄与側に入らないので**そのまま残り**、次回の実行で決着する
- `>=` (誤) … 集約に取り込まれ、**既に失効している繰越行**へ置き換わってしまう

N1c は `DB::listen` で失効 DELETE を観測した直後に境界行を差し込む形で、
別 connection も barrier も使わずにこの分岐を固定する (実測で `>=` を赤にすることを確認した)。

## 読み取り (誇張しない)

- **MU4 で N8 / N9 は赤にならない**。範囲検査を外しても `delta` が int4 の列なので
  INSERT の段で driver が生の SQL 例外を投げ、結局 `unexpectedFailures = 1` になるためである。
  範囲検査が守っているのは「**組織の処理を巻き戻す判断を、生 SQL 例外ではなく
  型の境界で fail-closed に行う**」ことであり、それを固定しているのは
  DTO の単体テスト側である (Feature 側ではない)。
  → **「範囲検査の検出力は Feature テストにある」とは書けない**。正本は `CarryForwardGroupTest`。
- **MU6 は挙動テストと静的 gate の両方で赤になる**。母集団の是正 (退会組織) は
  N12〜N14 が実挙動で、`withTrashed(` の件数 pin は TLM-4 が構造で受ける。
  TLM-7 (空振り検知) も同時に赤になるのは、`withTrashed(` の検出が 0 件になるからである。
- **MU7 は N18 だけが赤になる**。決着対象から失効した繰越行を外すと
  「失効済みの繰越行しか持たない組織」が列挙されず永久に処理されないが、
  取引明細を 1 行でも持つ組織では観測できない。**N4 の初期明細を消すだけでは
  この経路を検出できない**ため、N18 を独立したテストとして置いている
  (詳細設計の N18 の注記どおりであることを実測で確認した)。
- **`candidates = processed + expiredRemaining` の恒等式は静止した集合についての性質**である。
  組織行ロックを取らない追記経路が実行中に commit すれば、述語が正しくても崩れる。
  「崩れたら述語ずれ」と断定しないこと (DTO の docblock と runbook にも同じ注意を書いた)。
- 本書が示すのは**基本 7 変異 + 境界 2 変異 = 全 9 形**に対する検出力であり、
  実装の正しさ一般ではない。
