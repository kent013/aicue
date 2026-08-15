# 詳細設計: route-cache-middleware-attach

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(窓口 `PromptDefense` → 実行単位 `GuardedPrompt` の 1 本道のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest** テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 使用禁止）
- **テストデータは必ず Factory で生成**（`Model::create()` 手組み禁止）
- `declare(strict_types=1)` + 日本語コメント（git 追跡下の PHP 全数が対象）
- **アーリーリターン** 推奨
- **コードフォーマット**: `composer fix`（Pint）
- PHP 8.4 + Laravel 13 + Svelte 5 + Inertia.js + TypeScript

## 概念設計リファレンス

- `devnotes/20260815-2100-route-cache-middleware-attach/conceptual-design.md`（Round 4 で APPROVED）

## 決定事項（この設計が答えを出す 3 つの問い）

| 問い | 答え |
|---|---|
| 家系の正典 (a) 形（経路一覧が組み上がった後に走らせる専用の実行点クラス）へ移行するか | **移行しない**。判断を `docs/template-divergence.md` D19 として登録する |
| 移行しない場合、後付けの内容・順序・入口の目録を壊さないか | **`app/` を 1 行も変えない**。壊しようがない形にする |
| stale cache が無音で保護を外す実測を回帰として固定できるか | **できる**。ただし固定できるのは「middleware が欠けた compiled 経路一覧では保護が外れる」という局所の回帰までで、cached 起動そのものの再現ではない |

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | 逸脱 D19 の登録と参照の結線 | `docs/template-divergence.md`（追記）/ `AGENTS.md`（追記）/ `docs/app-integration-guide.md`（§7c 追記） | 高 |
| 2 | 免除の前提のトリップワイヤ | `tests/Architecture/RouteCacheExemptionPremiseTest.php`（新規） | 高 |
| 3 | 焼き込みと剥落の回帰 | `tests/Feature/Security/RouteCacheBakedProtectionTest.php`（新規） | 高 |

**実装順序**: 3 → 2 → 1（テストファースト。1 の文章は 2 / 3 が固定した内容を指すため最後に書く）。

---

## 施策 1: 逸脱 D19 の登録と参照の結線

### 変更箇所

- `docs/template-divergence.md`: 末尾（現在の最終エントリは D18）へ **D19** を追加
- `AGENTS.md`: 「運用要件 (route:cache)」ブロック（現在 L119〜L129）へ 3 行追記
- `docs/app-integration-guide.md`: §7c の「**現状**」段落（現在 L509 付近）へ 2 行追記

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: なし（ただし D19 の「再検討の条件」は施策 2 の検査条件と一致させる）

### D19 に書く内容（構成）

既存エントリと同じ書式（観点の表 → なぜ logic-driven か → 揃えている不変条件 → 関連）で書く。

**見出し**: `## D19 ✅ 経路キャッシュ起動での後付けは「走らせない」側の契約を維持する（専用の実行点クラスへは移行しない）`

**観点の表**:

| 観点 | 家系の正典 / テンプレート | 本アプリ |
|---|---|---|
| 実行点 | 専用クラス 1 つ（`AfterRoutesLoaded` 相当）へ集約 | 2 つの binder が各々 `Application::booted()` を使う |
| 経路キャッシュ起動での契約 | 正典 (a) = 容器の `routes` 束縛の張り替えを捕まえて、読み込まれた直後に後付けを走らせる | (b) = 走らせない。実効は cache 生成時の焼き込み |
| 入口の絞り込み | 素の起動完了フックの直呼びを走査で禁止 | `PostBootRouteMutationInventoryTest` が入口を 2 binder に絞る（deny-by-default） |
| 経路キャッシュ起動での実証 | 別プロセスで起動して後付けの残存を確認 | 同一プロセスで「焼き込みの入力」と「欠落時の剥落」を確認（別プロセス起動は導入しない） |

**なぜ logic-driven か**（要点。本文は概念設計の該当節を写す）:

1. 本リポジトリにデプロイ定義の実体が無く、`route:cache` を実行する記述も追跡下に 0 件である。
   言えるのは「**いま定めた走査条件で検出される発生経路が無い**」までである
   （「発生確率がゼロ」とも「管理下に発生経路が無い」とも書かない。
   走査条件が拾わない書き方は施策 2 の docblock が列挙する）。
2. 毎デプロイ再生成の**機械強制**は、`AGENTS.md` が「存在しない基盤のための preflight 機構を
   先回りして作らないこと」と明記しているため現時点で採れない。
3. 正典 (a) 形は Laravel 13 では 4 つの問題を同時に解く必要があり（容器の `routes` 束縛の
   張り替えの捕捉 / その束縛がまだ無いときに張り替えが発火しない穴の手当て / 経路一覧の実体ごとの
   冪等 / cached 起動で起動を止めると `route:list` も `route:clear` も落ちて復旧手段を失う問題への
   例外設計）、実証には別プロセスで起動する検査基盤も要る。
   **(a) を採る利益は「運用要件を 1 つ消せること」であり、その運用要件が効く相手（デプロイ基盤）が
   まだ無い**。基盤を作る PR で実物の手順と突き合わせて設計する方が確実である。
4. **これは保留ではなく明示の判断である**。期限で自然解消せず、**前提が崩れたときに解消する**。

**揃えている不変条件（これは保証し続ける）**:

> 「後付けした保護は、実効の経路に必ず載る」
> 「後付けの入口は 2 つの binder に限られる」
> 「経路名が消えたら起動を止める（無防備なまま公開しない）」

担い手の対応表を書く（Codex Round 4 の指摘。**どの不変条件をどのテストが担うか**を明示し、
施策 3 が既存目録と二重管理でないことを読み取れるようにする）:

| 不変条件 | 担い手 |
|---|---|
| どの route に何を付けるべきか（対象と件数） | `ThrottleCoverageInventoryTest` / `RecentAuthRouteTest` / `TwoFactorStepUpInventoryTest` / `PasskeyRouteProtectionTest` |
| 後付けの入口が 2 binder に限られること | `PostBootRouteMutationInventoryTest` |
| 後付けの契約（cached では resolver すら呼ばない / 経路が引けなければ起動を止める / 冪等 / 列の順） | `RouteMiddlewareBinderTest` / `RouteThrottleBinderTest` |
| 実際に付いた middleware 列が、直列化の準備を通しても焼き込みの入力へ欠落なく移ること | **施策 3 の検査 1（新規）** |
| 焼き込みが欠けた経路一覧では保護が実際に外れること | **施策 3 の検査 2（新規）** |
| この逸脱を許す前提（直接書かれた `route:cache` / 空白だけを挟む `artisan optimize` が無いこと。デプロイ定義の不在は早期の気づき。動的な組み立て・オプションを挟む書き方・リポジトリ外の手順は対象外） | **施策 2（新規）** |

**再検討の条件（解消条件）**:

- リポジトリにデプロイ定義の実体が入ったとき
- `route:cache`（または `artisan optimize`）を実行する記述が入ったとき
- 家系の機能台帳の裁定が変わったとき

前の 2 つは**施策 2 の検査条件と同じ言葉で書く**（文章と検査が別々に育つのを防ぐ）。

**関連**:

- 実装: `app/Support/Http/RouteMiddlewareBinder.php` / `app/Support/Http/RouteThrottleBinder.php`
- 設計: 本 devnotes ディレクトリ
- 契約の正本: `docs/app-integration-guide.md` §7c

### AGENTS.md への追記（運用要件 (route:cache) ブロックの末尾）

> 家系の正典はこの後付けを「経路の一覧が組み上がった後に走らせる専用の実行点」へ集約する形だが、
> **本リポジトリは「経路キャッシュ起動では走らせない」側を選んでいる**。この判断は
> `docs/template-divergence.md` **D19** に登録済みである。
> 判断の主前提に対するトリップワイヤとして、**追跡下に直接書かれた `route:cache`** と、
> **`artisan` と `optimize` の間が空白だけの実行記述**が無いことを
> `tests/Architecture/RouteCacheExemptionPremiseTest.php` が機械で固定する。
> **動的に組み立てた文字列・オプションを挟む書き方・リポジトリの外にある手順は対象外**である。
> 同テストは既知のデプロイ定義が増えたことも早期に知らせるが、そちらは網羅を主張しない
> （新しい CI 基盤やファイル名は拾えない）。**どちらかで赤くなったら D19 を読み直すこと。**

`docs/app-integration-guide.md` §7c の「現状」段落にも**同じ強度の言い方**で追記する
（主前提は `route:cache` の実行の不在。検出できるのは直接書かれたリテラルまでで、
動的な組み立て・オプションを挟む書き方・リポジトリ外の手順は対象外。
デプロイ定義の検出は早期の気づきで網羅を主張しない）。
D19 の担い手の対応表・施策 2 の docblock・この 2 つの文章は、**保証の強度を同じ言葉でそろえる**。

### リスク

- AGENTS.md / guide は他タスクも触る中心ドキュメントである → 追記は既存段落を書き換えず**末尾に足す**。
- **D19 の文章と施策 2 の検査条件が将来ずれる** → 「同じ言葉で書く」だけでは片方だけ直しても
  気づけない（Codex Round 1 の指摘）。施策 2 に**結線の検査**（テスト 2-6）を置いて機械で固定する:
  D19 の見出しが存在し、D19 が施策 2 のテストのファイル名を書いており、`AGENTS.md` と
  `docs/app-integration-guide.md` の両方が D19 を参照し、D19 に検査条件の要点
  （`route:cache` / `artisan optimize` / デプロイ定義）が書かれていること。
  **保証範囲を誇張しない**: これは「参照が切れていないこと」までで、文章の意味が
  検査と一致していることは機械では見られない。

---

## 施策 2: 免除の前提のトリップワイヤ

### 変更箇所

- 新規: `tests/Architecture/RouteCacheExemptionPremiseTest.php`

命名は既存の `tests/Feature/Security/ThrottleExemptionPremiseTest.php` /
`tests/Feature/Security/IdempotencyExemptionPremiseTest.php`（免除の前提を機械で固定する形）に
そろえる。置き場所は DB を使わない静的走査なので `tests/Architecture/` にする。

### 波及変更

- TypeScript 型定義: なし / API Resource・DTO: なし / 既存テストの更新: なし

### 何を検査するか

git 追跡下のファイルを走査し、次の 2 つを **0 件で pin** する。
**2 つは対等ではない** — D19 の前提を本当に決めるのは B（`route:cache` が実行されないこと）であり、
A（デプロイ定義が無いこと）は**早期の気づき**のための粗い網である。
デプロイ定義があっても `route:cache` を打たなければ前提は崩れず、逆に定義が無くても
`route:cache` を打てば崩れる。したがって **A は網羅を主張しない**（下の「保証しないもの」に書く）。

**検査 B（本体）: `route:cache` を実行する記述が無いこと**

追跡下の **`.md` 以外**のファイルの中身に、次の語が現れないこと。

- `route:cache`
- `artisan` に**空白だけ**を挟んで `optimize` が続き、**直後が `:` でない**もの
  （正規表現は `artisan\s+optimize(?!:)` 相当。素の `optimize` は `route:cache` を含む複合コマンドである）

`optimize:clear`（消す側。bug-hunt が使う）は一致しない。

**この語の検出範囲は狭いことを明言する**（Codex Round 2 の指摘）。
`php artisan --env=production optimize` のように**間にオプションが挟まる形は拾わない**。
シェルの文法を正規表現で解析し始めると際限がないため、
**「`artisan` と `optimize` の間が空白だけの書き方を検出する」までを保証**とし、
それ以上は docblock で「拾わない」と宣言する（誇張しない）。

- **`.php` を除外しない**（Codex Round 1 の指摘）。`Artisan::call('route:cache')` は実行経路になり得る。
  ただし素の文字列走査だと既存 binder や Provider の docblock（`route:cache` を大量に説明している）を
  誤検出するため、**`.php` は `token_get_all()` でコメントと docblock を落としてから**走査する。
  落とした後に残るのは実行するコードと文字列リテラルだけである。
- **自己言及の除外**: 本テスト自身は needle を文字列リテラルとして持つので、
  **自分 1 ファイルだけを名指しで除外**する（`PostBootRouteMutationInventoryTest` と同じ allowlist の作法）。
  除外はこの 1 件に限り、増やすときはレビューで必ず見える形にする。
- **行番号を失わない**: コメントを落とすときはトークンを単に連ねず、
  **落とす部分を同じ改行数を持つ空白へ置き換える**。こうしないと 2-2 の
  「ファイル名と行番号を列挙する」が実際の位置とずれる。

**検査 A（早期の気づき）: デプロイ定義の実体が無いこと**

追跡下のパスに、次のいずれにも一致するものが無いこと。

| 種類 | 一致条件（パスの前方一致 / ファイル名 / 拡張子） |
|---|---|
| 専用ディレクトリ | `deploy/` `ansible/` `.ebextensions/` `k8s/` `kubernetes/` `helm/` `charts/` |
| Terraform | `*.tf` `*.tfvars` |
| PaaS の宣言 | `Procfile` `fly.toml` `render.yaml` `app.yaml` `vercel.json` `railway.json` `captain-definition` |
| 他の CI 基盤の設定 | `.gitlab-ci.yml` `.circleci/` `.buildkite/` `.travis.yml` `azure-pipelines.yml` `Jenkinsfile` |
| 本番向けの合成定義 | ファイル名に `prod` を含む `docker-compose*.yml` |
| CI のデプロイ job | `.github/workflows/` のファイル名に `deploy` / `release` / `cd` を含むもの |

**`.github/workflows/*.yml` の中身は走査しない**。`environment:` や `ssh` / `rsync` / `kubectl` を
拾いに行くと、既存の `ci.yml` / `secret-scan.yml` の内容変更で偽陽性が出やすく、
その割に**取りこぼしを塞げない**（新しい CI 基盤はいくらでも増える）。
ここで取りこぼしても、**B が本体として効く**ので前提の崩れ自体は検知できる。この非対称を docblock に書く。

### 実装方針（要点）

```php
<?php

declare(strict_types=1);

/*
 * 逸脱 D19（経路キャッシュ起動での後付けは「走らせない」側を維持する）を
 * 許している**前提そのもの**を機械で固定する。
 *
 * ★これは「デプロイの正しさ」を事前検査する仕組みではない（AGENTS.md が禁じているのはそちら）。
 *   固定するのは、D19 を許している前提に対して**いま定義できるトリップワイヤ**である。
 *   同じ形は ThrottleExemptionPremiseTest / IdempotencyExemptionPremiseTest に前例がある。
 *
 * ★赤くなったときに求めるのは「デプロイを正しくすること」ではなく
 *   「D19 を読み直して、正典 (a) 形への移行か、毎デプロイ再生成の機械強制かを
 *   同じ PR で決めること」である。
 *
 * ★保証範囲を誇張しない:
 *   - PHP は**コメントと docblock を落とした後のコードと文字列リテラル**を走査する。
 *   - 見ないもの: Markdown の説明文 / 動的に連結した文字列 (`'route:'.'cache'`) /
 *     リポジトリの外から与えられる実行手順。
 *   - 検出するのは「`artisan` と `optimize` の間が空白だけ」の書き方までである。
 *     間にオプションが挟まる形 (`artisan --env=production optimize`) は**拾わない**。
 *   - 起動時の cache の鮮度も、デプロイ手順の正しさも検査しない。
 *   - デプロイ定義の検出 (検査 A) は**網羅を主張しない**。主前提を固定するのは検査 B である。
 */
```

- 追跡下ファイルの列挙は `git ls-files -z` を `Symfony\Component\Process\Process` で実行する
  （`tests/Support/TrackedPhpSourceFiles.php` と同じ作法。ただし対象が `*.php` に限らないので
  **共用クラスは作らず本テスト内の関数に閉じる** = 今必要なものだけ作る）。
  git が使えない環境では**空を返さず例外**にする（fail-open の防止）。
- **床値の pin**: 列挙件数が 0 件や極端に少ないと「対象が無いから緑」の空振りになる。
  追跡下ファイル数の下限（例: 500 件）と、必ず存在する代表パス
  （`composer.json` / `.github/workflows/ci.yml` / `scripts/bug-hunt-shard.sh`）が
  列挙に含まれることを表明する（`TrackedPhpSourceFiles` の docblock が求めている作法）。

### テスト計画

| # | テスト名 | 検証内容 |
|---|---|---|
| 2-1 | デプロイ定義の実体が追跡下に 1 件も無い | 検査 A。違反時は一致したパスを列挙し、D19 と二択を提示 |
| 2-2 | `route:cache` を実行する記述が追跡下に 1 件も無い | 検査 B。違反時はファイル:行を列挙 |
| 2-3 | 走査の母集団が空振りでない（床値と代表パスの pin） | 追跡下ファイルが下限件数以上で、代表 3 パスを含む |
| 2-4 | 負のコントロール: 判定関数の検出範囲の境界を固定する（dataset 1 本にまとめてよい） | 判定を純関数へ切り出し、文字列を直接渡して固定する。`artisan optimize:clear` は**非検出**（bug-hunt の既存記述が誤検出されない証拠）/ `artisan optimize` は検出 / `artisan   optimize`（空白複数）は検出 / `artisan --env=production optimize` は**非検出**。最後の 1 件は「安全だから許す」ではなく「**いまの検出器の境界を見えるようにする**」ためである。テスト名にもコメントにも「許可」ではなく「**検出しない**」と書く |
| 2-5 | 負のコントロール: PHP のコメント中の `route:cache` は違反にせず、文字列リテラル中の `route:cache` は違反にする | `token_get_all()` でコメントを落とす処理の両方向を固定（既存 binder の docblock が誤検出されないことの証拠）。落とした後も**行番号がずれない**ことも併せて表明する |
| 2-6 | D19 と本テストの結線が切れていない | `docs/template-divergence.md` に D19 の見出しがあり、そこに本テストのファイル名が書かれている。`AGENTS.md` と `docs/app-integration-guide.md` がどちらも D19 を参照している。D19 に検査条件の要点（`route:cache` / `artisan optimize` / デプロイ定義）が書かれている |

- 個別の `DatabaseTransactions` は使わない（DB 不使用）。
- 実行は `composer test`。

### PHPStan 適合チェック

- [ ] `git ls-files` の出力は `string` として扱い、`explode()` の結果は `list<string>` へ絞る
- [ ] 判定関数は `bool` / `list<string>` を返し、`mixed` を返さない
- [ ] `preg_match` の戻り値は `int|false` を明示的に判定する
- [ ] 型を緩める注釈は使わない（禁止事項 2）

### リスク

- **偽陽性**: 将来 `deploy` を名に含む無関係な workflow（例: `deploy-docs`）が入ると検査 A が赤くなる。
  これは意図した摩擦である（D19 の前提が本当に崩れていないかを人が見る契機）。
  失敗メッセージにその旨を書き、無関係なら検査条件と D19 を**同じ PR で**直すよう促す。
- **偽陰性（受け入れる）**: 検査 A は新しい CI 基盤やファイル名を網羅できない。
  受け入れる理由は、前提を本当に決めるのは検査 B であり、A は早期の気づきだからである。
  この非対称を docblock と D19 の両方に書く。
- **偽陰性（残る）**: 変数越しに組み立てた文字列（`Artisan::call('route:'.'cache')`）や
  外部から流し込む実行には沈黙する。「この検査があれば `route:cache` は 1 度も打たれない」とは読めない。

---

## 施策 3: 焼き込みと剥落の回帰

### 変更箇所

- 新規: `tests/Feature/Security/RouteCacheBakedProtectionTest.php`

### 波及変更

- TypeScript 型定義: なし / API Resource・DTO: なし / 既存テストの更新: なし
  （`tests/Feature/Auth/TwoFactorSecretReadStepUpTest.php` の設定手順を**踏襲**するが、変更はしない）

### 前提として確定させた機序（実読済み。laravel/framework 13.18.0）

1. `Illuminate\Foundation\Console\RouteCacheCommand::handle()` は
   `route:clear` → cache 無しのアプリを再 bootstrap → 各 route に `prepareForSerialization()` →
   `$routes->compile()` の戻り値を `var_export` してファイルへ書く。
2. `Route::prepareForSerialization()` が触るのは `action['uses']` / `action['missing']` の
   Closure 直列化、正規表現の事前構築、`router` / `container` 参照の切り離しだけで、
   **`action['middleware']` には触れない**。
3. `AbstractRouteCollection::compile()` は `['compiled' => …, 'attributes' => [route 名 => […, 'action' => …]]]`
   を返し、`Router::setCompiledRoutes()` は同じ形を受け取って
   `CompiledRouteCollection` を作り、容器の `routes` 束縛を張り替える。
4. `CompiledRouteCollection::getByName()` は生成した `Route` を `nameCache` へ覚え、
   `match()` はその `getByName()` を通る。よって差し替えた経路一覧の中身がそのまま dispatch に効く。

### 検査 1: 焼き込みの実証（複製した経路一覧に「直列化の準備 → compile」を通す）

**Round 1 からの変更（Codex の指摘への対応）**: 起動中の経路一覧をそのまま `compile()` して
比べる形だと、`compile()` が `getAction()` をそのまま `attributes` へ写すだけなので
**ほぼ同語反復**になる。そこで `RouteCacheCommand` と**同じ順序**を通す形へ変える。

手順（`RouteCacheCommand::handle()` の写し）:

1. 起動済みの経路一覧から、**名前があり担い手が文字列の** route を集める
   （担い手が Closure の route は `prepareForSerialization()` が直列化を試みるため対象外。
   これは「route:cache が落ちる側」の話であって本検査の主題ではない。除外理由を docblock に書く）。
2. それぞれの**複製**を新しい `RouteCollection` へ入れる（実アプリの経路一覧には触らない）。
3. 複製 1 本ずつに `prepareForSerialization()` を掛ける。
4. その経路一覧を `compile()` する。
5. `attributes[route 名]['action']['middleware']` が、**元の（複製していない）route の
   `action['middleware']`** と、順序と重複を含めて**厳密に一致する**ことを表明する。

これで「起動時に後付けした列が、直列化の準備を通しても変わらず、焼き込みの入力へ写る」までを
1 本で言える。Round 1 で別立てにしていた「直列化の準備が middleware を変えないこと」の検査は
**この検査に吸収される**（Codex が「1 route だけでは足りない」と指摘した網羅の問題も、
対象が後付け対象を含む全 route になることで同時に解ける）。

**この検査の性質を正確に書く**（誇張しない）: これは 2 つの性質の合成である —
(i) 直列化の準備が middleware 列を変えないこと、(ii) `compile()` が準備後の action を
`attributes` へ写すこと。(ii) だけなら vendor 実装の転記の確認にすぎない。
**「同語反復を消した」ではなく「転記の確認に、実際に変わり得る直列化の準備の段を足した」**と書く。

**前提の表明**: `attributes` は route 名をキーにするため、同名の route があると後勝ちで潰れる。
比較を始める前に、**対象の route 名が重複していないこと**を先に表明する
（重複があれば比較そのものが意味を失うため、静かに緑にならないようにする）。

- 比較は**生の `action['middleware']`** で行う。`gatherMiddleware()` /
  `Router::resolveMiddleware()` は使わない（alias の解決・group の展開・重複の畳み込みを挟むと
  「焼き込みの入力が同じ」を見たことにならない）。
- **集合化も sort もしない**。順序と重複をそのまま比較する
  （passkey 削除 route の `throttle:passkeys` → `recent-auth` → `ensure-login-method` の順序と、
  意図しない重複の両方を見逃さないため）。
- 個々の alias の一覧表は**作らない**（2 binder の呼び出し側の定数と二重管理になるため）。
- **隔離の証明**: 上の手順の後、**元の route の `action['middleware']` が 1 つも変わっていない**ことを
  併せて表明する（複製で隔離できていることの証拠。これが無いと実アプリの経路一覧を壊しながら
  緑になっている可能性が残る）。

**空振り防止（負のコントロール）**: 後付けの 5 系統が、それぞれ 1 本以上の route の
`attributes` に現れること。

| 系統 | 代表 |
|---|---|
| `recent-auth` | 2FA 秘密 GET 3 本 / passkey 系 |
| `recent-auth.on-email-change` | プロフィール更新 |
| `ensure-login-method` | `passkey.destroy` |
| `no-store` | `passkey.login-options` |
| `throttle:` で始まる後付け | `passkey.destroy` / 2FA 秘密 GET / `cashier.webhook` |

`throttle:` の系統は**アプリ全体の無関係な throttle で成立しない**よう、
**後付け対象の route 名を名指しして**その route の `attributes` に載っていることを見る
（Codex Round 4 の指摘）。名指しするのは代表 1 本ずつで、件数の網羅は既存目録の担当。

さらに `passkey.destroy` の 1 本で 3 つの alias の**相対順序**を固定する。

### 検査 2: 剥落の実証（負のコントロール）

**1 テスト 1 シナリオ**。差し替えはそのテストの**最後の操作**。

- 2-a（保護が載っている側）: `compile()` の結果を**そのまま** `setCompiledRoutes()` へ渡し、
  鮮度切れセッションで `GET /user/two-factor-secret-key`（`Accept: application/json`）が
  **409** で、本文に `secretKey` が**無い**こと。
- 2-b（保護が欠けている側）: 同じ結果から
  `attributes['two-factor.secret-key']['action']['middleware']` の `recent-auth` を **1 本だけ**抜いて
  `setCompiledRoutes()` へ渡し、同じ要求が **200** になること。
  本文の形には踏み込まない（Fortify の応答表現の変更に脆くしない）。
  抜く前と抜いた後で middleware 列の**件数が 1 減っていること**も表明する
  （抜けていないのに 200 になった、という取り違えを防ぐ）。

**自己証明（Codex Round 1 の指摘）**: 差し替えが本当に効いていることを、要求を出す前に表明する。

1. `setCompiledRoutes()` の直後に `app(Router::class)->getRoutes()` が
   `CompiledRouteCollection` の実体であること
2. 差し替えた経路一覧から引いた対象 route の middleware 列で、
   `recent-auth` の件数が**ちょうど 1 減っている**こと（2-b の場合）
3. HTTP 要求は `setCompiledRoutes()` の**後**に初めて実行すること
   （差し替え前に要求を出すと、どちらの経路一覧を測ったのか分からなくなる）

利用者の用意は `tests/Feature/Auth/TwoFactorSecretReadStepUpTest.php` と同じ
`User::factory()->withTwoFactor()->create()` を使う（Factory 必須）。

### 状態の隔離（Codex Round 2 / 3 の指摘への回答）

- `setCompiledRoutes()` は Router の持ち物だけでなく容器の `routes` 束縛を張り替え、
  それを見ている URL 生成器も付いてくる。したがって**元へ戻す形は採らない**。
- 代わりに次の 4 点で隔離する。
  1. **1 テスト 1 シナリオ**（2-a と 2-b を同じテスト関数で続けない）
  2. 差し替えは**そのテストの最後の操作**（差し替えた後に別の前提で検査しない）
  3. テスト間の隔離は Laravel が**各テストでアプリを作り直す**ことに依る
     （これは全テストが既に依っている既定であり、本テスト固有の仮定ではない）
  4. 差し替えた経路一覧を dataset / ループで持ち越さない。静的変数や共有 singleton へ保存しない
- **テストの途中でアプリを作り直さない**（`RefreshDatabase` が張っているトランザクションが
  接続ごと道連れになるため）。

### 保証範囲（誇張しない。docblock にそのまま書く）

- 保証する: 起動済みの経路一覧に後付けが載っており、**直列化の準備を通しても**それが
  `compile()` の `attributes` に欠落なく現れること / その並びから alias が欠けると
  保護が効かなくなること。
- 保証しない: `php artisan route:cache` コマンド全体が成功すること（Closure route の
  直列化可否を含む）/ 実際に cache ファイルを置いた**別プロセスでの起動順**の再現 /
  起動時の cache の鮮度。**「cached 起動を再現した」とは書かない**。
- `compile()` と書き出し内容の同一性は vendor の版に依存する推論であり、
  検査 1 が「直列化の準備 → compile」の順を実際に通すことでそれを支える。
  それでも `var_export` してファイルへ書き、別プロセスで読み戻す区間は通っていないので、
  framework 更新時には本テスト群の前提を人手で読み直すこと。

### テスト計画

| # | テスト名 | 検証内容 |
|---|---|---|
| 3-0 | 対象 route の名前が重複していない | 検査 1 の前提（`attributes` は名前キーなので重複があると後勝ちで潰れる） |
| 3-1 | 複製へ直列化の準備を掛けてから compile() しても、middleware 列が元と厳密一致する | 検査 1 の本体（`prepareForSerialization` → `compile` の順を通す） |
| 3-2 | 上の操作の後も元の route の middleware 列が 1 つも変わっていない | 検査 1 の隔離の証明 |
| 3-3 | 後付けの 5 系統が代表 route の attributes に現れる（空振り防止） | 検査 1 の負のコントロール。代表 route を名指し |
| 3-4 | passkey 削除 route で 3 つの alias の順序が保たれる | 付与順の契約が焼き込み入力でも崩れないこと |
| 3-5 | 保護が載った compiled 経路一覧では 2FA 秘密 GET が 409 で秘密を返さない | 検査 2-a（自己証明つき） |
| 3-6 | recent-auth を 1 本抜いた compiled 経路一覧では同じ要求が 200 になる | 検査 2-b（剥落の実測。自己証明つき） |

- `RefreshDatabase` はグローバル適用。個別の `DatabaseTransactions` は使わない。
- テストデータは Factory（`User::factory()->withTwoFactor()`）で作る。

### PHPStan 適合チェック

- [ ] `compile()` の戻り値の形を局所の PHPDoc で宣言する
      （`array{compiled: array<mixed>, attributes: array<string, array{action: array<string, mixed>, …}>}` 程度）。
      `mixed` のまま添字を掘らない
- [ ] `action['middleware']` は `list<string>` へ絞ってから比較する（`array_values` + 型表明）
- [ ] `getByName()` の戻りは `Route|null` なので `instanceof` で絞る
- [ ] 型を緩める注釈・baseline は使わない（禁止事項 2）

### リスク

- **3-1 の母集団**: 名前のない route は `attributes` のキーが空文字に潰れるため、比較の母集団は
  **名前を持ち、かつ担い手が文字列の route** に限る。この限定と理由を docblock に書く。
- **3-1 が複製の作りで壊れる可能性**: `clone` は浅い複製なので `action` 配列は値として複写されるが、
  `prepareForSerialization()` は正規表現の事前構築と `router` / `container` 参照の切り離しも行う。
  複製を入れた経路一覧を `compile()` する経路で不足が出たら、**検査を弱めずに**
  「複製へ `setRouter()` / `setContainer()` を張り直してから準備を掛ける」形で補う
  （`RouteCacheCommand` も cache 無しのアプリを再 bootstrap して同じ状態から準備している）。
- **3-5 が Fortify の実装変更で 200 以外になる可能性**: そのときは「保護が外れる」の実測が
  変わったということなので、テストを弱めずに実測を取り直す（弱めると設計の根拠が消える）。
- **並列実行**: 経路一覧の差し替えはプロセス内で完結し、プロセス間でアプリ状態を共有しないため
  `--parallel` と両立する。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | 変更は新規テスト 2 本と、`AGENTS.md` / `docs/template-divergence.md` / `docs/app-integration-guide.md` の追記だけで完結する。`app/` には一切触れない。一方で `AGENTS.md` と `docs/app-integration-guide.md` は他タスクも触る中心ドキュメントなので、単独の worktree で完結させてから main へ入れる方が競合を捌きやすい |
| 競合リスク | `AGENTS.md`（運用要件ブロック末尾への追記）と `docs/app-integration-guide.md` §7c、`docs/template-divergence.md`（末尾へ D19 追加）。いずれも**既存段落を書き換えず末尾に足す**形にすることで、行単位の衝突を最小化する |

### D 番号の採り方（実装時の手順。Codex Round 2 の指摘）

D 番号は他タスクと競合しうる。実装時は次の順で確定させる。

1. `docs/template-divergence.md` の**最終番号をその場で確認**してから採る（D19 が埋まっていれば D20）。
2. 採った番号は**テスト側の定数 1 つ**（例: `ROUTE_CACHE_DIVERGENCE_ID`）に持ち、
   テスト 2-6 の照合はその定数を通す。文字列を 2 か所に書かない。
3. 番号を変えるときは、その定数と `AGENTS.md` / `docs/app-integration-guide.md` /
   `docs/template-divergence.md` の参照を**同じコミットで**まとめて直す（テスト 2-6 が赤くなるので取りこぼさない）。
