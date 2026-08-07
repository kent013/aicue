# 対応マトリクス: conceptual-review Round 1

## [Critical] AWS の worst-case が `timeout × attempts + backoff` であり、web 経路の見積もりが甘い

- 判断: **対応する**
- 根拠: vendor を実査して指摘が正しいことを確認した。
  `Aws\Retry\ConfigurationProvider::DEFAULT_MAX_ATTEMPTS = 3` / `DEFAULT_MODE = 'legacy'` で、
  `ClientResolver::_apply_retries()` は legacy のとき `maxAttempts - 1` を retry 数に使う。
  つまり既定は **3 attempts**。`retries` を pin しない限り実効上限は `timeout × 3 + backoff` になる。
  設計の表が「15s」と書いていたのは誤り。
- 対応内容:
  1. AWS クライアント 3 面すべてに `retries` を **明示 pin** する
     (`['mode' => 'legacy', 'max_attempts' => 2]`)。値の単一出典クラスに定数として置く。
  2. **web 同期経路の S3 metadata 操作 (`headObject`) は per-command `@retries => 0`** にする。
     `Aws\RetryMiddleware` / `RetryMiddlewareV2` が `$command['@retries']` を読むことを vendor で確認済み
     (V1 は retry 数、V2 は `+1` して max attempts)。指摘どおり「同期 API は SDK 内で粘らせず
     アプリ側で失敗を返す」方針に寄せる。
  3. 期待効果の表を **attempts 込みの実効上限**へ書き直す。

## [Critical] S3 disk 全体 900s + `headObject` だけ per-command 上書きだと、将来 web 経路に足された metadata 操作が 900s を継承する

- 判断: **対応する** (ただし「操作名の目録」ではなく「S3Client を握れる箇所の目録」で解く)
- 根拠: 指摘の危険は本物である。ただし「web 同期経路から呼ばれる S3 操作」を静的に判定するのは
  呼び出しグラフの解析が要り、deny-by-default の母集団として脆い (偽陰性が静かに増える)。
  一方、**生の `S3Client` を取得できる口は構造的に有限**である —
  `Storage::disk(...)->getClient()` と `new \Aws\…Client(...)` の 2 パターンしかない
  (`TakeObjectStorage::client()` が唯一の `getClient()` 呼び出し点であることを実査で確認)。
  ここを exact-fit の目録にすれば、**新しい metadata 操作を足すときに必ず目録を通る**。
- 対応内容: gate の母集団を「AWS SDK クライアントの構築点」から
  「**AWS SDK クライアントの構築点 + 取得点 (`->getClient()`)**」へ拡張し、各 entry に
  「per-command 制御系 option を必ず渡す (pinned)」か「型付き enum 免除 + 30 文字以上の根拠」を要求する。
  さらに `TakeObjectStorage::headObject()` が実際に `@http` / `@retries` を積むことを
  behavioral に固定する (既存 `TakeObjectStorageTest` が実 SDK オブジェクトを使う形と同じ土俵)。

## [Warning] Stripe のプロセス大域 pin のテスト間状態漏れが設計に落ちていない

- 判断: **対応する** (ただし「退避・復元 helper」は作らない)
- 根拠: 状態漏れが起きるのは「テストが pin を書き換える」場合である。本設計では
  **テストは setter を一切呼ばず getter だけを読む** ため、書き換える主体が存在しない。
  退避・復元 helper を先回りで作るのは AGENTS.md 思考原則 2 (今必要なものだけ作る) に反する。
- 対応内容: 制約・前提に「gate は `ApiRequestor::httpClient()` の **getter しか触らない**
  (大域状態を汚さない)」ことと、「テナント別 timeout は設計として持たない」ことを明記する。
  pin が実際に効いていることは mutation (pin 行の削除で赤化) で確認する。

## [Warning] 本番 supervisor はリポジトリ外なので、コード変更だけでは不変条件が成立しない

- 判断: **対応する**
- 根拠: 既に `docs/architecture.md` が「本番/ステージングの supervisor 定義にもこの `--timeout` を
  必ず設定する (リポジトリ外にあるため CI は検知しない。上表が正本)」と書いている。
  帯を動かす以上、この一文だけでは不十分で「**いつ・何を・どの順で**変えるか」が要る。
- 対応内容: 施策に「`docs/architecture.md` の値表更新 + **デプロイ順序の明記**
  (`--timeout=300` を supervisor へ反映してから `retry_after=360` をデプロイする、
  逆順は規則 1 違反の窓を開く)」を含め、**運用上の破壊的変更**として扱うことを明記する。

## [Warning] 期待効果を attempts 込みで書き直すべき

- 判断: **対応する** (Critical 1 の対応に含める)

## [Warning] 主目的 (web 経路の有限化) と従目的 (帯の短縮) の順序を明文化すべき

- 判断: **対応する**
- 対応内容: 概念設計の「課題」「期待効果」「実装方針」を主従の順に並べ直し、
  T127 との境界を明示する。

## [Warning] 同一 PR でよいが実装順を分けるべき

- 判断: **対応する**
- 対応内容: 詳細設計の施策順を Codex の提案どおり
  (1) SDK pin + gate → (2) worst-case 表 → (3) 帯の変更 → (4) 既存 lease invariant 更新
  に固定する。

## [Suggestion] `maxNetworkRetries = 0` の理由を docs に残す

- 判断: **対応する**。`docs/architecture.md` に「課金は外部冪等キーとリコンサイルで担保するため
  SDK 自動 retry に寄せない」を書く。

## [Suggestion] AWS config array の shape が緩い → 小さな factory/helper へ寄せる

- 判断: **対応する**
- 根拠: PHPStan level 10 で `array{...}` shape を宣言した static メソッドにすれば、
  config 3 箇所の綴りずれが型で落ちる。定数を 3 箇所へ手で撒くより堅い。
- 対応内容: `ExternalClientTimeouts::awsClientOptions()` /
  `::awsControlPlaneCommandOptions()` を shape 付きで用意し、config から呼ぶ。

## [Suggestion] 見落とし候補 (Socialite / vendor SDK 直呼び)

- 判断: **一部反論・一部対応**
- 根拠: Socialite は Guzzle 直で AWS/Stripe SDK ではない。AGENTS.md が
  「テストレーンの egress guard は Socialite / Stripe SDK / AWS SDK に効かない」と
  既に非対称を明記しており、Socialite の timeout は**別テーマ**である
  (T126 の題名は「外部 SDK の client timeout」)。今回混ぜない = 思考原則 2。
- 対応内容: 「スコープ外」に Socialite を明記し、**なぜ外すか**の根拠を書く
  (対象が SDK ではなく Guzzle 直呼びで、pin の層がまったく別)。
  一方「S3 disk を使うが本文転送ではない操作」は Critical 2 の対応に含めた。
