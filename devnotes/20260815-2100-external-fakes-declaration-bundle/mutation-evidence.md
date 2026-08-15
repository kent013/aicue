# 変異の 2 段確認 (T177)

新設・強化した gate が「実際に何を落とすか」を、変異を当てて実走で確かめた記録。

## 段階 1: 実装前に素通りすること

本 TODO で新設した検査 (`3-13`〜`3-16` / `LaneExternalFakeBindingTest` /
`BughuntSeedWiringInvariantTest` の `S-1`〜`S-11` / `ExternalFakeBootProbeTest` の
`P-1`〜`P-12` / `ProductionEnvGuard` の実環境変数の判定) は、実装前の HEAD に**存在しない**。
したがって段階 1 の「素通りする」は、対応する検査が母集団ごと無いことによって成立している
(実装前の main で `composer test` は 5000 件超が緑であり、下記の変異はどれも検出されない)。

例外は 1 つある。**施策 1c は素通りどころか実在の違反を見つけた** —
`tests/Feature/Billing/` の 5 ファイル・8 箇所が偽の実装クラスを container へ直接結んでいた。
これは「実装前は素通りする」の実測そのものであり、詳細と対応は
`detailed-design.md` §実装時に判明した設計の訂正 (4)。

## 段階 2: 実装後にすべて赤くなること

変異を 1 つ当てて対象テストを走らせ、直後に原状復帰する手順で実走した
(結果は `composer test -- --filter=…` の `result` フィールド)。

| # | 変異 | 対象 | 結果 |
|---|---|---|---|
| M-a | 宣言 (`swaps()`) から外部ログインの entry を 1 件消す | `ExternalFakeWiring` / `ExternalFakeBootProbe` | failed (= 検出) |
| M-b | provider に `::class` の bind を手書きする | `ExternalFakeWiring` | failed |
| M-c | provider が宣言を読まず素通しする (bind しない) | `ExternalFakeWiring` / `ExternalFakeBootProbe` | failed |
| M-d | `config/testing.php` に宣言外の偽物フラグを 1 本足す | `ExternalFakeWiring` | failed |
| M-e | レーン側 (`tests/`) で偽の実装クラスを直接結ぶ | `LaneExternalFakeBinding` | failed |
| M-f | `cmd_reseed` から `BughuntOAuthSeeder` を落とす | `BughuntSeedWiring` | failed |
| M-g | `BughuntBillingSeeder` を `DatabaseSeeder` に足す | `BughuntSeedWiring` | failed |
| M-h | bug-hunt 専用 seeder のガードの前に 1 文入れる | `BughuntSeedWiring` | failed |
| M-k | 偽の外部ログインの転送先を実 IdP に戻す | `ExternalFakeBootProbe` | failed |
| M-l | 一時環境ファイルへ `STRIPE_SECRET` を足す | `ExternalFakeBootProbe` | failed |
| M-m | 一時環境ファイルの鍵を親の設定値の複写に戻す | `ExternalFakeBootProbe` | failed |
| M-n | 子プロセスの起動コマンドから `env -i` を外す | `ExternalFakeBootProbe` | failed |

変異をすべて戻した後、上記 filter を通しで再実行して 167 passed を確認した
(復帰漏れが無いことの確認)。

## 変異を当てなかったもの

- **施策 3 (本番混入防止の実環境変数の判定)**: 変異ではなく**新規ケースの追加**で固定した
  (`$_SERVER` / `$_ENV` / `getenv()` の 3 経路それぞれ / 未設定 / 無効と読める値 /
  解釈できない値 / 非文字列)。加えて別プロセス観測の `P-4` が、実際の production 起動で
  設定値と 3 経路の両方から違反が出ることを実測している。
- **`P-9` の権限違反で子を起こさない分岐**: 一時ディレクトリの権限を外から壊す変異は
  再現が環境依存になるため、判定を `assertSafePermissions()` へ切り出して
  緩い権限 (0755 / 0644) で例外になることを直接固定した。

## 保証範囲を誇張しない

- 走査系の gate はいずれも**字句**を見る。宣言と provider の関係は
  「許可した呼び出し形の外に出たら赤」という閉じた文法で守っており、
  reflection / `eval` のような敵対的回避に対する完全性は主張しない。
- 投入データの検査は条件の**論理** (かつ / または) を読めない。
  そこはガードの振る舞いテストを目録から紐づけて守っている (`S-9`)。
- 別プロセス観測が見るのは**設定キャッシュ無しの起動だけ**である。
