# mutation 2 段確認の記録 (T119 受入条件)

新 gate は素の main では赤にならない (穴を塞ぐ類のため)。テストファースト (AGENTS.md 思考原則 5) は
**mutation の 2 段確認**で満たす。手順は `mutations.py` / `run-mutations.sh` (本ディレクトリ) が機械化している。

- 段階 1 (実装前): mutation を当てて **緑のまま = 穴が実在する**ことを記録する
- 段階 3 (実装後): 同じ mutation を当てて **対応する gate が赤くなる**ことを確認する
- mutation は当てたら必ず `git checkout --` で戻す (コミットしない)

## 段階 1: 穴の実在確認 (実装前)

コマンド: `composer test -- --testsuite=Architecture --testsuite=Feature` (2321 tests)

| ID | mutation | 結果 | 備考 |
|---|---|---|---|
| M1 | `AutoRechargeGatewayInterface` の bind を削除 | **緑** (2319 passed / 0 failed) | 穴の実在 |
| M2 | `TakeObjectStorage` の bind を削除 | **赤** (2305 passed / **4 failed**) | 既存 Feature が部分被覆 (下記) |
| M3 | `bootstrap/providers.php` から provider を削除 | **緑** | 穴の実在 |
| M4 | provider を `AppServiceProvider` の直前へ移動 | **緑** | 穴の実在 |
| M5 | inventory 未登録の bind 組を追加 (既存 fake クラス) | **緑** | 穴の実在 |
| M5b | inventory 未登録の fake クラスを provider が新規参照 | **緑** | 穴の実在 (3-10 用の変種) |
| M6 | `bind(` を `singleton(` へ | **緑** | 穴の実在 |
| M6b | `bind(A::class, B::class, true)` (= singleton 相当) | **緑** | 穴の実在 (M6 回避路) |
| M7 | Service に fake クラスの `use` を追加 | **緑** | 穴の実在 |

### M2 の扱い (設計の想定どおり)

M2 は既存 Feature テスト 4 本が落ちた。落ち方は
`InvalidArgumentException: Missing required client configuration options: region` = **実 S3 クライアントの組み立て**で、
「bind が消えると Laravel が本物を自動組み立てして実 S3 を叩く」という本 feature の核心そのものが露出した形である。

- 落ちたのは `Tests\Feature\Capture\TakePlaybackTest` ほか計 4 test (すべて S3 region 未設定の 500)
- ただし **Architecture lane (不変条件の正本) は 1 本も赤くならなかった** = 「登録漏れを不変条件として見ている層は無い」という穴は実在する
- 段階 3 で `--testsuite=Architecture` 単独でも赤くなることを確認済み (下記)

## 段階 3: gate が赤くなることの確認 (実装後)

コマンド: `composer test -- --testsuite=Architecture` (381 tests。**Feature を含めない = Architecture 側が正本**であることの確認)

| ID | 結果 | 赤くなったテスト |
|---|---|---|
| M1 | **赤** (4 failed) | 3-2 (AutoRecharge × allowlist 3 環境) / 3-8 |
| M2 | **赤** (3 failed) | 3-2 (TakeObjectStorage × allowlist 2 環境) / 3-8 |
| M3 | **赤** (3 failed) | 3-5 / 3-6 / 3-7 |
| M4 | **赤** (1 failed) | 3-6 |
| M5 | **赤** (1 failed) | **3-8 のみ** (設計どおり。既存 fake クラスを使うため 3-10 は変化しない) |
| M5b | **赤** (1 failed) | **3-10** (未登録 fake クラスの新規参照) |
| M6 | **赤** (2 failed) | 3-9 / 3-8 (`singleton` は bind 組から外れるため) |
| M6b | **赤** (1 failed) | 3-9 (`bind(…, true)` = singleton 相当を引数個数で禁止) |
| M7 | **赤** (1 failed) | 4-3 |
| M8 | **赤** | 3-9 (`use Illuminate\Container\Container as C;` + `C::getInstance()->bind(…)`) |
| M8b | **赤** | 3-9 (`use function app as container;` + `container()->bind(…)`) |

| M9 | **赤** | 3-9 (`$this->{'app'}->bind(…)` = 動的メンバアクセスによる token 列回避) |
| M10 | **赤** | 3-9 (`get_object_vars($this)['app']->bind(…)` = 未分類の式経由) |

> M8 / M8b は Codex 実装レビュー Round 1 の Critical (use alias で末尾セグメント照合をすり抜ける)、
> M9 は Round 2 の Critical (動的プロパティアクセスで `$this->app` の token 列を回避する)、
> M10 は Round 3 の Critical (`$this` を式へ渡して container を取り出す) に対する回帰。
> いずれも修正前は緑のまま素通りした = 抜け道が実在した。
>
> ⚠️ **敵対的回避に対する完全性は主張しない**。PHP は reflection / `eval` / `Closure::bind` 等で
> 任意に container へ到達できるため、字句解析で「絶対に抜けられない」ことは示せない。
> 本 gate が守るのは**通常の実装作業で起きるドリフト**であり、そのために
> 「許可形の列挙 = 閉じた文法」を要求して未分類の書き方をすべて赤にする方針を採っている。
> 新しい抜け道は Unit テスト (5-x) にケースを足して文法を狭める (allowlist を広げない)。

設計の被覆表 (M1/M2 → 3-2、M3 → 3-5/3-7、M4 → 3-6、M5 → 3-8、M6 → 3-9、M7 → 4-3) をすべて満たし、
M1/M2 が 3-8 も、M3 が 3-6 も、M6 が 3-8 も追加で捕まえている (被覆は設計より広い)。

## 段階 4: 全体検証 (実装後・mutation なし)

| コマンド | 結果 |
|---|---|
| `composer test -- --testsuite=Architecture` | 381 passed / 0 failed |
| `composer test -- --testsuite=Architecture` (2 回目) | 381 passed / 0 failed |
| `composer test -- --testsuite=Architecture --order-by=random --random-order-seed=20260806` | 381 passed / 0 failed |
| `composer test` (全体) | 3307 tests / 3305 passed / 0 failed / 2 skipped |
| `composer phpstan` | No errors (791 files) |
| `vendor/bin/pint --test` | passed |
| `pnpm lint` / `pnpm typecheck` | passed (フロント差分なし) |
| `git status` | `app/` / `config/` / `bootstrap/` / `routes/` に差分なし |

実装前の Architecture は 340 tests、実装後は 381 tests (+41 = 3-1〜3-12 の 37 + 4-1〜4-4 の 4)。
走査器の Unit テストは 19 tests (5-1〜5-19)。
