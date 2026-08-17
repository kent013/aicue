# 詳細設計レビュー Round 3

Round 2 で残った唯一の指摘 (施策 5) を修正しました。他の施策は無変更です。

---

# 対応マトリクス: design-review Round 2

Codex 判定: **CHANGES_REQUESTED** (残るのは施策 5 のみ。施策 1/2/3/4/6/7 は APPROVE)。

Round 1 で反論した 2 件は **Codex が反論に同意し、指摘を撤回**した:

- [Critical] LIKE の `ESCAPE` 句 → **撤回**。「PostgreSQL 固定・バインド変数利用・既存の実接続テスト
  という条件下では `addcslashes()` と既定のバックスラッシュ escape で契約は成立する。
  `whereRaw()` 化はむしろ保守性を落とす」
- [Warning] `Assert::string($search)` → **撤回**。「`when()` 連鎖という既存様式を維持し、
  PHPStan のクロージャ境界で型を確定する用途として妥当」

## [Warning] 施策5: 事前確認による「重複索引の回避」が無条件 migration と矛盾している
- 判断: **対応する**
- 根拠: 指摘が正確である。Round 1 で「保険として事前確認する」と書いたが、
  提示している migration は確認結果にかかわらず索引を作るので、**保険として機能していない**
  (確認して重複が見つかっても、migration は同じものを作る)。
  併記した 2 文が論理的に噛み合っていなかった。
  また「migration を環境の状態で条件分岐させない」も正しい —
  分岐させると環境ごとにスキーマが分かれ、migration の意味 (すべての環境で同じスキーマへ収束する)
  が壊れる。
- 対応内容 (3 点):
  1. `Schema::getIndexes('cuts')` の事前実行を **「前提の実測記録」** と明記し直した。
     「保険」「重複回避」という記述を削除した。目的は
     「索引が無いという断定が実測と合っていたかを後から検証できるようにすること」だけである。
  2. **「migration を環境の状態で条件分岐させない」**を明記した。
     管理外の手動索引が見つかった場合は migration を変えず、
     **環境固有のスキーマドリフト**として別に是正する (手動索引を落として migration に収束させる)。
  3. `CutsIndexTest` を **索引名 `cuts_video_manual_id_index` まで固定**する形へ変更した。
     「先頭列に `video_manual_id` を持つ索引が 1 本以上」だけだと、
     **環境固有の手動索引が 1 本あるだけでテストが緑になる**ため
     (= migration が作った索引が実在しなくても気付けない)。
     `columns` が `['video_manual_id']` であることも併せて固定する。

## 施策別判定の受け止め

| 施策 | Round 2 判定 | 本ラウンドでの変更 |
|---|---|---|
| 1 | APPROVE | なし |
| 2 | APPROVE | なし |
| 3 | APPROVE | なし |
| 4 | APPROVE | なし |
| 5 | REQUEST_CHANGES | 上記 3 点を修正 |
| 6 | APPROVE | なし |
| 7 | APPROVE | なし |


---

## 修正後の施策 5 (全文)

## 施策 5: `cuts.video_manual_id` へ索引を足す

### 変更箇所

- ファイル: `database/migrations/2026_08_17_000000_add_video_manual_id_index_to_cuts_table.php` (**新規**)

### 前提: 索引が存在しないことは**コード読解で確定済み** (Codex Round 1 [Critical] 対応)

Round 1 の設計は「実装時に確認して、あれば施策を落とす」と書いていたが、それは実装者依存で弱い。
**確認を待たずに確定できる**ので、証拠の連鎖を示して確定施策に格上げする:

1. `database/migrations/2026_07_10_000300_create_cuts_table.php` は
   `$table->foreignId('video_manual_id')->constrained()->cascadeOnDelete();` だけで、
   `index()` を 1 つも宣言していない (全文を実読)。
2. `cuts` に索引を足す migration は**他に 1 本も無い**
   (`grep -n "index(" database/migrations/*.php` の全出力に `cuts` 由来の行が無い)。
   後付け migration `2026_07_10_000500_add_foreign_keys_to_cuts_table.php` も
   `foreign()` を 2 本張るだけで索引を作らない。
3. Laravel の `Grammar::compileForeign()` は
   `alter table … add constraint … foreign key …` しか出さず、**索引は作らない** (vendor 実読)。
4. **PostgreSQL は FK 列に索引を自動生成しない** (MySQL/InnoDB とは異なる)。

→ `cuts.video_manual_id` に索引は**存在しない**。migration は**無条件に**作る。

**`Schema::getIndexes('cuts')` の事前実行は「前提の実測記録」であり、重複回避機構ではない**
(Codex Round 2 [Warning] 対応)。Round 1 の設計はこれを「保険」と書いていたが、
提示している migration は確認結果にかかわらず索引を作るので、**保険として機能しない**。
記録の目的は「索引が無かったという前提が実測と合っていたか」を後から検証できるようにすることだけである。

**migration を環境の状態で条件分岐させない**。migration は**すべての環境で同じスキーマへ収束する**
必要があり、特定の dev DB の状態を見て分岐させると環境ごとにスキーマが分かれる。

**管理外の手動索引が見つかった場合**: migration は変更しない。
それは**環境固有のスキーマドリフト**であり、migration ではなく環境側の是正として別に扱う
(手動索引を落として migration に収束させる)。

### 波及変更

- TypeScript型定義: なし / API Resource/DTO: なし
- テストファイル: 新規 `tests/Feature/Database/CutsIndexTest.php`

### 変更後コード

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * cuts.video_manual_id へ索引を足す。
     *
     * **PostgreSQL は FK 列に索引を自動生成しない** (MySQL/InnoDB とは異なる)。
     * 元の create migration は foreignId()->constrained() だけで索引を宣言していないため、
     * cuts を video_manual_id で引く経路がすべて逐次走査になっていた。
     *
     * 効く経路は本改善のカット本文検索 (相関 EXISTS) だけではない:
     * 撮影 PWA 一覧の withCount(['cuts', ...]) は**行ごとに** cuts への相関副問い合わせを
     * 出しており、索引が無いと cuts 全走査 × 表示行数になる。
     * シナリオ編集・レンダリングの cuts 取得、manual 削除時の cascade も同様。
     *
     * `%語%` の LIKE 自体には B-tree 索引は効かない (前方一致でないため)。
     * 本索引が支えるのは**相関 nested-loop 計画のときの cuts 取得**である。
     * pg_trgm + GIN は導入しない (拡張の導入は運用権限と運用負担を増やす。
     * 引き金は devnotes の概念設計に Conditional として記録した)。
     */
    public function up(): void
    {
        Schema::table('cuts', function (Blueprint $table): void {
            $table->index('video_manual_id');
        });
    }

    public function down(): void
    {
        Schema::table('cuts', function (Blueprint $table): void {
            $table->dropIndex(['video_manual_id']);
        });
    }
};
```

索引名は Laravel 既定の `cuts_video_manual_id_index`。

### PHPStan適合チェック

- [x] Blueprint クロージャに `: void` を付ける (既存 migration の流儀)
- [x] 匿名クラス migration の書式は既存踏襲

### テスト計画

- [ ] 新規 `tests/Feature/Database/CutsIndexTest.php` —
      `Schema::getIndexes('cuts')` に **`cuts_video_manual_id_index` という名前の索引**が存在し、
      その `columns` が `['video_manual_id']` であること。
      (既存 `tests/Feature/Database/IdempotencyStateMigrationTest.php` と同じ流儀)
      **名前まで固定する理由** (Codex Round 2): 「先頭列に `video_manual_id` を持つ索引が 1 本以上」
      だけだと、**環境固有の手動索引が 1 本あるだけでテストが緑になる**。
      migration が作った索引が実在することを見たいので、Laravel 既定名で固定する。
      索引が黙って消えたら赤くなる。

### 完了条件 (施策 5) — 実測を採る (Codex Round 1 [Warning] 対応)

「索引を足したから速い」で終えない。**索引は `%語%` の LIKE 自体には効かない**ので、
何が効いて何が効いていないかを実測で分けて記録する。

- [ ] `Schema::getIndexes('cuts')` の migration **前**の出力を `devnotes/{dir}/index-precheck.md` へ貼る
      (**前提の実測記録**であって重複回避機構ではない。「索引が無い」という断定が実測と
      合っていたかを後から検証できるようにするためだけのもの)
- [ ] PC 一覧の検索クエリ / 撮影 PWA 一覧の検索クエリの 2 本について、
      **dev DB で `EXPLAIN (ANALYZE, BUFFERS)` を読み取りのみ実行**し
      (SELECT の実行計画取得であり dev DB への破壊操作ではない = 禁止事項 3 に触れない)、
      結果を `devnotes/{dir}/explain-notes.md` へ貼る。記録するのは 3 つ:
  - 選ばれた計画 (相関 nested-loop / hash semi-join / seq scan のどれか)
  - `cuts` へのアクセス方法 (`Index Scan` / `Bitmap Heap Scan` / `Seq Scan`)
  - 実測時間
- [ ] `Seq Scan` が選ばれた場合は**それを異常としない**。想定規模 (project あたり cuts 10^3〜10^4)
      では正しい計画でありうるため、「行数 N のとき実測 M ms で許容」という**理由を併記**する。
      許容できない実測が出たときだけ概念設計の Conditional (pg_trgm) を起こす

### リスク

- 書き込み (cuts の INSERT/UPDATE/DELETE) が索引更新の分だけ僅かに遅くなる。cuts の書き込みは
  シナリオ保存時のバッチであり、読み取り側の利得が上回る。
- **索引が本改善の検索を直接速くするとは限らない**。`%語%` の LIKE には B-tree 索引が効かないため、
  hash semi-join が選ばれた場合の `cuts` へのアクセスは逐次走査になる。
  本索引が確実に効くのは (a) 相関 nested-loop が選ばれたときの cuts 取得、
  (b) 撮影 PWA 一覧の `withCount(['cuts', ...])` の相関副問い合わせ、
  (c) manual 削除時の cascade、の 3 つである。**(a) を保証としては書かない**。

---



---

判定をお願いします (施策 5 の APPROVE / REQUEST_CHANGES と、全体判定 APPROVED / CHANGES_REQUESTED)。
