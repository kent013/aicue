レビュー仮説は「正典 v1 の6不変条件を実働テストで満たしつつ、boot probe 以外へ責務を広げず、既存保証を後退させない」です。提供テキストだけで検証した結果、現状はこの条件を満たしていません。

## S1: 共通 runner の取り込み

判定: REQUEST_CHANGES

- [Warning] fail-first の手順になっていません。3ファイルを同時に配置して最初から緑にする手順は、AGENTS.md の「先に赤を確認」に反します。

  修正案: バイト一致の `BootProbeRunnerTest.php` だけを先に配置し、クラス未定義で赤になることを確認してから、バイト一致の実装2ファイルを配置してください。ファイル内容を変更せずに実現できます。

- [Warning] `composer fix` は書き換えコマンドなので、「差分が出たら整形せず報告」と両立しません。

  修正案: まず `vendor/bin/pint --test` で非破壊確認し、通った場合だけ `composer fix` を実行してください。取り込み前後のSHA-256再確認も受入条件に含めるべきです。

- [Warning] 取り込むdocblockには、aicueでは誤った不変条件番号とパスが残ります。S6を削除すると、実装近傍から訂正情報も消えます。

  修正案: 共有3ファイルは編集せず、aicue固有の `ExternalFakeBootProbeTest` などに対応関係を残してください。それも許容できない場合は、取り込み自体を見直す必要があります。

## S2: FakeWiringProbeRunner の載せ替え

判定: REQUEST_CHANGES

- [Warning] クラス先頭の既存docblockが変更後の実装と矛盾します。変更後は `env -i` の3キーではなく、継承・基底・ケース別・予約鍵の4層です。また、`APP_KEY` は環境ファイルではなくケース別envへ移ります。

  修正案: クラスdocblockを新しい環境合成、鍵の配置、2種類の一時ディレクトリ、設定キャッシュ退避に合わせて更新してください。

- [Warning] `caseEnvValues()` の許可検査は「生成した配列に未知キーがない」ことしか確認しません。`CASE_ENV_KEYS` に不要なキーを足しても検出できません。

  修正案: P-7で実効集合を検査するだけでなく、`CASE_ENV_KEYS` 自体を期待する3キーのリテラルと完全一致させてください。

## S3: 子入口スクリプトの観測拡張

判定: REQUEST_CHANGES

- [Critical] 主要コード例は未定義定数を参照しています。

  ```php
  $markerPath = $app->storagePath(BOOT_PROBE_MARKER_RELATIVE);
  ```

  後段の説明では `FakeWiringProbeRunner::MARKER_RELATIVE_PATH` を直接使うとしており、設計内で不一致です。このまま実装するとErrorになり、P-13まで到達しません。

  修正案:

  ```php
  $markerPath = $app->storagePath(
      FakeWiringProbeRunner::MARKER_RELATIVE_PATH,
  );
  ```

  とし、必要な `use Tests\Support\ExternalFakes\FakeWiringProbeRunner;` も変更一覧に明記してください。

- [Warning] 現行コメントの「責務は4つだけ」は、マーカー書き込み、書き出し先8種、鍵digestの観測追加後には事実ではありません。

  修正案: probeの責務一覧と「観測しないもの」を更新してください。

## S4: 呼び出し側gateの更新

判定: REQUEST_CHANGES

- [Critical] 既存P-10が保証していた「timeoutでもFakeWiringProbeRunner自身の環境ディレクトリを削除する」が失われます。runnerのS7/S12/S14が検査するのは、runner内部の `temporaryRoot` の回収です。外側の `.env.probe` と `$directory` の `finally` は検査していません。P-15も `interpret()` を直接呼ぶだけなので、この後退を補いません。

  修正案: 実プロセスのsleep分岐を増やさず、runner結果または例外を決定的に注入できる狭いテスト境界を設け、`timedOut`/例外時にも外側ディレクトリが消えることを恒久テストにしてください。注入境界を増やさないなら、既存の実timeout検査を維持できる別案が必要です。

- [Warning] P-7は予約7キーを `BootProbeRunner::RESERVED_ENV_KEYS` から期待値へ流用しています。実装側の定数と実体を同時に変更すると、検査も一緒に追随します。「1本足しただけで赤」という説明は予約鍵について成立しません。

  修正案: 次の完全一致を独立してpinしてください。

  ```php
  expect(BootProbeRunner::RESERVED_ENV_KEYS)->toBe([
      'LARAVEL_STORAGE_PATH',
      'VIEW_COMPILED_PATH',
      'APP_CONFIG_CACHE',
      'APP_ROUTES_CACHE',
      'APP_SERVICES_CACHE',
      'APP_PACKAGES_CACHE',
      'APP_EVENTS_CACHE',
  ]);
  ```

- [Warning] `ExternalFakeBootProbeTest` の先頭docblockも、変更後なお `env -i`、専用環境ファイルだけ、鍵2つが環境ファイル内という旧説明のままです。

  修正案: P-7/P-8の新契約と一致する説明へ更新してください。

## S5: StrictTypesRuntimeProbe の載せ替え

判定: REQUEST_CHANGES

- [Critical] `StrictTypesRuntimeProbe` はアプリをbootするprobeではありません。`BootProbeRunner` に載せると、Laravel固有の基底env、予約7キー、一時ディレクトリ構築、リポジトリrootへのcwd固定を不要な検査へ持ち込みます。これは機能名と正典の境界に反し、PhpLintOracleを載せ替えない理由とも整合しません。

  さらに、現行Symfony Processは親環境を継承しますが、載せ替え後は許可envだけになります。現在の23検体が通ることは、この意味変更が安全である証明にはなりません。

  修正案: S5は本featureから外し、現行実装を維持してください。一般PHP subprocessのtimeout・回収を統一したいなら、boot固有の予約envを持たない別featureとして設計すべきです。正典がStrictTypes経路まで明示的に対象としている証拠がある場合は、その根拠と環境/cwdの互換性テストを追加してください。

- [Warning] S5を残す場合、`strictTypesInEffect()` の `@throws` はtimeoutを含む説明へ更新する必要があります。

## S6: 全数申告gate

判定: REQUEST_CHANGES

- [Critical] 正典の境界で「静的走査そのもの」を含まないと明記しながら、新しい静的走査gateをfeatureの必須施策として追加しています。「正典が求めていないことをやらない」という追従条件と衝突します。

  修正案: S6を削除してください。aicue固有の既存不変条件によって必要なら、正典追従とは分離したローカル上積みとして必要性を示し、台帳・逸脱判断も改めて行うべきです。

- [Critical] S6を維持する場合でも、AGENTS.md の走査器共通規約に適合しません。

  現案では以下を見逃します。

  - `use Symfony\Component\Process\Process as Worker; new Worker(...)`
  - `\proc_open(...)`
  - fully-qualified名や別名を使ったstatic call

  一方、末尾が `Process` の無関係なクラスを誤検出します。G-7も「BootProbeRunnerを参照する」だけでは、unusedな`use`を残して別ランチャーへ戻す退行を通せます。

  修正案: `use`、group use、aliasを解決した完全修飾名で照合し、解決不能を明示的な失敗にしてください。G-7は単なる参照ではなく、解決済みの `BootProbeRunner::run()` 呼び出しを要求すべきです。別名、FQN、同名別クラス、未解決参照の正負例も恒久テストへ追加してください。

- [Warning] `BootProbeRunnerTest.php` の申告理由「それ自身は子を起こさない」は不正確です。直接APIを呼ばないだけで、テストはrunner経由で複数の子を起こします。

  修正案: 「直接起動APIを持たず、BootProbeRunner経由でのみ起動する」へ修正してください。

## 横断的な受入条件

- [Warning] AGENTS.mdで必須の検証コマンドが不足しています。PHPのみの変更でも、列挙された全検証をgreenにしてコミットする規約です。

  修正案: `vendor/bin/pint --test` と、`pnpm lint`、`typecheck`、`test`、`build`、各packages系を受入条件へ追加してください。

- [Warning] 「実行時間の後退がない」は達成条件として成立しません。S7/S12/S14だけでも複数秒の固定実行時間が追加されるため、全 `composer test` の実時間は原理的に増えます。

  修正案: 実装前に比較対象、試行回数、集計方法、許容増分を固定してください。S5を外すなら、共通既存テストの中央値と新規自己検査の追加コストを分けて報告するのが適切です。

- [Warning] 作業開始時と終了時の未追跡集合の完全一致は、新規4ファイルを作る今回の変更とそのままでは両立しません。また `--exclude-standard` はignored生成物を検出しません。

  修正案: 意図した新規ファイルをindexへ登録した検証開始時点を基準にし、tracked差分とignoredな既知の書き出し場所も別に確認してください。S6の `TrackedPhpSourceFiles` も新規ファイルを走査するにはindex登録が必要です。

## 全体判定

CHANGES_REQUESTED

特に、S3の未定義定数、S4のtimeout時外側cleanup検査の後退、S5のbootではない経路への責務拡張、S6の正典スコープ外かつ名前解決不備は、実装前に解消が必要です。DTO/JsonResource、Inertia、DESIGN.md、Atomic Designは提示された変更範囲では該当しません。