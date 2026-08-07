# 概念設計: cache-payload-plain-data (キャッシュ素データ規約の明文化と gate)

## 背景・課題

lctl 台帳の feature `cache-payload-plain-data` は 2026-08-06 の裁定で標準形 v1 を確定している:

- キャッシュに入れてよいのは**素のデータ** (配列 / 文字列 / 数値 / 真偽値) だけ。オブジェクトをそのまま入れない
- 読み出したらアプリのコードが**明示的に組み立て直し**、その際に整合性を検査する
- `config/cache.php` の `serializable_classes` は **false のまま維持し例外を作らない**。
  クラスを名指しで許す**許可一覧は使わない**
- 配列への変換と復元の**往復が壊れないことを単体テストで固定**する (キャッシュ経路を通す必要はない)
- 「オブジェクトをキャッシュに入れていないこと」の**機械検査を標準形に必須**として含める
  (静的検査か実行時検出かは各リポジトリに委ねられている)

aicue の実コードを実査した結果 (`recon-brief.md`)、**アプリの実装は既に標準形を満たしている**:

- `config/cache.php:128` = `'serializable_classes' => false,` (許可一覧なし)
- キャッシュ書き込みはアプリ全体で `app/Services/FxRateService.php:49` の
  `Cache::put($cacheKey, $fresh->toArray(), …)` **1 か所だけ**で、既に配列化済み
- 読み戻し (同 33-37 行) は `Cache::get` → `is_array()` 検査 → `FxSnapshotDto::fromArray()`、
  38-44 行の catch で警告ログ + `Cache::forget()` = 標準形そのもの
- その他のキャッシュ API 利用は `Cache::lock()` 計 9 か所のみ (payload を持たない)

したがって**アプリの振る舞いは 1 行も変わらない**。残っているギャップは次の 4 点である。

1. **実害のある誤情報**: `docs/app-integration-guide.md` 213-214 行 (§7 不変条件 6) が
   「object cache が必要になったときだけ**最小 allowlist**」と書いており、canonical v1 の
   「許可一覧は使わない・例外を作らない」と**正面から矛盾**している。
   この記述を信じた実装者は `serializable_classes` に class を足す方向へ誘導される。
   これは「未整備」ではなく「誤った指示が既に書かれている」状態で、放置コストが最も高い。
2. **AGENTS.md に規約が無い**: セキュリティ不変条件の本文に逆シリアライズ / キャッシュ payload の
   項目が無く、72 行の採番注意書きに「guide 6 = 逆シリアライズ」と参照があるだけ。
   LLM エージェントが最初に読むのは AGENTS.md なので、ここに無い規約は実質的に存在しない。
3. **機械検査が 0 本**: `tests/Architecture/` 全 70 ファイルにキャッシュ payload の検査は無く、
   `serializable_classes` の値を pin する検査も無い。違反は**本番で読み出しが失敗するまで気付けない**
   (しかも array driver は `serialize => false` なのでローカル / テストでは**成功してしまう** —
   database driver で serialize される本番でのみ壊れる、発見が最も遅れる型の欠陥)。
4. **往復の単体テストが無い**: `FxSnapshotDto` / `FxRateService` を参照するテストは tests/ 全体で 0 件。
   `toArray()` / `fromArray()` の往復も不正値の拒否も固定されていない。

**仮説**: 現時点で違反は 0 件なので、いま入れる検査は「バグを見つける」ためではなく
**「規約が破られた瞬間に落ちる」予防装置**として価値がある。成功判定は
(a) 誤情報が消えること、(b) 新しいキャッシュ書き込み経路を無申告で追加できなくなること、
(c) その検査が空振りしていないことが機械的に示されること、の 3 点。

## 改善アイデア

**「明文化 2 点 + 機械検査 2 点」**に絞り、アプリコードには一切触れない。

### A. 明文化 (誤情報の訂正が主目的)

- `docs/app-integration-guide.md` §7 不変条件 **6 の本文**を canonical v1 に合わせて書き換える。
  「最小 allowlist」を削り、素データ規約・読み戻し時の再構築と検査・gate のファイル名を明記する。
  **§7 の番号は動かさない** (AGENTS.md 71-75 行が renumber を禁じている。既存参照が壊れるため)
- `AGENTS.md` のセキュリティ不変条件に**末尾 11 として追記**する (既存 1-10 は renumber しない)

### B. 機械検査 (deny-by-default)

- `tests/Architecture/CachePayloadPlainDataGateTest.php` を新設し、**3 層**で塞ぐ:
  - **L1 (語彙)**: キャッシュ受け手に対して呼ばれたメソッドを全件、
    `WRITE` / `NON_WRITE` / `CHAIN` のいずれかに分類する。**どこにも属さない API は fail** させる
    (将来 Laravel が新しい書き込み API を足しても、deny 側リストの更新漏れですり抜けない)
  - **L2 (書き込み経路)**: `WRITE` に分類された呼び出し箇所を gate 内 inventory と **exact-fit** で
    突き合わせる。未登録も、登録されているのに実在しない entry も、宣言件数のズレも fail
  - **L3 (面)**: そもそも**キャッシュ記号に触れているファイル**の集合を exact-fit で固定する。
    L1/L2 の静的解析には原理的な穴 (変数による動的ディスパッチ、`app('cache')` 経由など) があるため、
    「新しいファイルがキャッシュに触れ始めたこと」自体を検知する粗い網を重ねる
- 同ファイルに `config('cache.serializable_classes') === false` の **pin** (SsrfPinBoundaryTest 流) と、
  **空振り検知** (走査ファイル数 > 0 / 解決できたキャッシュ式 > 0 / 走査メソッド呼び出し数 > 0)、
  および **正負のコントロール fixture** (`CarbonOverflowArithmeticGateTest` の作法) を置く
- `tests/Unit/DataTransferObjects/FxSnapshotDtoTest.php` を新設し、
  `toArray()` → `fromArray()` の往復一致と不正値の拒否を固定する
  (台帳が標準形に含めた「往復を単体テストで固定する」の充足。キャッシュ経路は通さない)

### 母集団定義 — 最初に決めるべき論点への結論

実査ブリーフが名指しした論点。**受け手 (receiver) を解決してから**メソッド名を見る、が結論。

| 書き方 | 判定 | 理由 |
|--------|------|------|
| `Cache::put(...)` (facade) | 対象 | 素直な形 |
| `Cache::store('x')->put(...)` / `Cache::tags([...])->put(...)` | 対象 | `store` / `tags` は CHAIN として辿る |
| `cache()->put(...)` / `cache(['k' => $v], $ttl)` | 対象 | ヘルパも受け手として解決する |
| `$this->cache->put(...)` (Repository を DI) | 対象 | ファイル内の型宣言から受け手名を収集して解決する |
| `Cache::lock(...)` とその後続 (`->block()` / `->get()` / `->release()`) | **対象外** | payload を持たない。`lock` は terminal 扱いで以降の chain を辿らない (9 か所) |
| `session()->put(...)` / `$session->put(...)` (16 か所中 15 か所) | **対象外** | 受け手がキャッシュでない |
| `$this->disk()->put(...)` (FakeObjectStore) | **対象外** | 同上 |

「`->put(` を受け手を見ずに拾う」方式は採らない (session/disk を巻き込む)。
「Cache facade だけを見る」方式も採らない (`cache()` ヘルパと DI が素通りして**空振り green** になる)。
この両方向を**負のコントロール fixture** で恒久的に固定する。

## 期待効果

- **使命への貢献**: 直接の機能価値は無い。効くのは「AI が撮るべきカットを設計する」中核処理を
  支える基盤の壊れにくさ。特に本件は **array driver では再現せず本番でのみ壊れる**型の欠陥を
  対象にしており、CI で落とせる形にしておく価値が高い
- **誤情報の除去**: 実装者を `serializable_classes` へ class を足す方向へ誘導する記述が消える。
  gadget chain 攻撃面 (APP_KEY 漏洩時) を開けさせない
- **予防**: キャッシュ書き込み経路の追加が**申告なしには不可能**になる。
  レビューの目視に依存していた不変条件が機械化される
- **家系の整合**: 5 リポジトリ共通の標準形 v1 に aicue が準拠したことを機械検査で示せる

## 実装方針（概要）

| # | 施策 | 対象 | 種別 |
|---|------|------|------|
| S1 | キャッシュ payload gate の新設 | `tests/Architecture/CachePayloadPlainDataGateTest.php` | 新規 |
| S2 | FxSnapshotDto 往復の単体テスト | `tests/Unit/DataTransferObjects/FxSnapshotDtoTest.php` | 新規 |
| S3 | guide §7 不変条件 6 の誤情報訂正 | `docs/app-integration-guide.md` | 変更 |
| S4 | AGENTS.md セキュリティ不変条件 11 の追記 | `AGENTS.md` | 変更 |

- **アプリコード (`app/` / `config/` / `routes/`) の変更はゼロ**。既に標準形を満たしているため
- 検査は `PhpToken::tokenize` による静的走査 (DB 不使用)。regex は使わない
  (本 gate 自身の説明コメントに `Cache::put` と書いた瞬間に偽赤になるため)
- inventory は**現状 1 経路しか無い**ため、`app/Enums/Security/` への新規 enum +
  `tests/Support/Security/` への inventory クラスは作らず、gate ファイル内の const で足りる
  (思考原則 2「今必要なものだけ作る」)。経路が増えて const が読みにくくなった時点で昇格を再検討する
- 実装順は**テストファースト** (思考原則 5): gate を先に書き、mutation を注入して赤を確認してから
  文書を直す。「素の main では緑」の予防 gate なので、赤を一度も見ずに完了報告しない

## 制約・前提

- **§7 の採番を動かさない**。AGENTS.md 71-75 行が「本節の番号と guide §7 の番号は 1:1 対応しない」
  「どちらの側も renumber しない」と明記している。guide 6 の**本文書き換えは可**、番号移動は不可。
  AGENTS.md 側は既存 1-10 の後ろに 11 を足す
- Architecture lane は `tests/Pest.php` で `TestCase` のみ (DB 不使用)。本 gate も DB に触れない
- `RefreshDatabase` はグローバル適用済み。個別 `DatabaseTransactions` は使わない。
  S2 の単体テストは DB を使わない純粋な値オブジェクトの検査
- テストデータは Factory 前提だが、S1/S2 はモデルを一切使わない (fixture は heredoc のソース文字列)
- PHPStan level 10 対象に `tests/` が含まれる。走査ヘルパは戻り値型・配列 shape を明示する
- 実行時間: `app/` + `tests/` + `routes/` + `database/` の PHP を 1 パス token 走査。
  既存の同型 gate (`CarbonOverflowArithmeticGateTest` / `PcreUnicodeModifierGateTest`) と同程度

## スコープ外

- **アプリコードの書き換え**。`FxRateService` / `FxSnapshotDto` / `config/cache.php` は現状維持
- **実行時検出** (`KeyWritten` イベント購読 / cache store の decorator によるテスト時 assert)。
  テストで実行された経路しか見えず、`FxRateService` にテストが無い現状では**空振りする**。
  静的検査の方が母集団が広く、標準形も「実装形は各リポジトリに委ねる」としている。
  静的検査の穴を埋めるために L3 (面の exact-fit) を置く方が費用対効果が高い
- **`serializable_classes` の allowlist 運用手順の設計**。canonical v1 が「例外を作らない」と
  裁定しているので、運用手順そのものを作らない (作れば誤情報を再生産する)
- **`Cache::lock` 側の不変条件**。ジョブの重複実行と結果の一回性は
  `JobExecutionDedupInventoryTest` / `JobExclusionOrderingInvariantTest` の担当で母集団が交わらない
- **フロントエンド**。差分ゼロ (`pnpm` 系は無変更確認のみ)
- **`cache.php` の store 追加や driver 変更**。本件は payload の型の話であって store の話ではない
