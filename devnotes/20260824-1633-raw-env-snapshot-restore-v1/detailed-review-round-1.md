# 全体判定: CHANGES_REQUESTED

仮説は「3 面の状態を損失なく復元でき、例外時にも漏れず、走査 gate が宣言した範囲を fail-closed で守れること」です。成功条件は、i1–i12 の各主張に実装可能な検査が対応し、PHPStan level 10 と文書上の保証範囲に矛盾がないことです。

方向性は妥当ですが、i10 の検査不足、走査器の未解決呼び出し、構造検査の仕様不足など、実装前に解消すべき点があります。

## 施策 1: REQUEST_CHANGES

- [Critical] i10 の契約テストになっていません。  
  g-1 は repository のインスタンス変更、g-2 は通常の `env()` 読み出ししか検査していません。設計が問題としている「一度目の boot で `.env.testing` を書いた immutable writer が、`refreshApplication()` 時に注入値を上書きする」経路を通っていないため、`forgetLaravelEnvRepository()` を削除しても g-2 が緑になり得ます。

  修正案: `.env.testing` と衝突する安全な非 DB キーを使い、次を実際に通す契約テストを追加してください。

  1. 元状態を退避
  2. 3 面へ別値を注入
  3. `forgetLaravelEnvRepository()`
  4. `refreshApplication()`
  5. 注入値が維持されることを検査
  6. 復元後に再度 application を作り直す

- [Critical] h-1 / h-2 の「構造検査」が実装可能な粒度まで設計されていません。  
  どのトークン構造をどう認識し、別メソッドの `try`、ネストした `finally`、コメント内の綴りをどう排除するかが未定です。これは新しい走査ロジックなので、AGENTS.md の走査器共通規約も発火します。

  修正案: 構造検査用の純関数を明示し、少なくとも以下を設計に追加してください。

  - 対象クラス・メソッド・ブロック境界の特定方法
  - `foreach` が対象 `try` の直下または子孫であることの判定
  - `restore()` が対応する `finally` / `catch` 内にあることの判定
  - メソッドが見つからない、括弧が不整合、呼び出し先を解決できない場合の fail-closed
  - 正しい合成入力と、`foreach` を `try` の外へ移した負例
  - `restore()` を `finally` の外へ移した負例

- [Warning] 不正キーの契約テストと PHPDoc 型が衝突します。  
  `with()` は `array<non-empty-string, RawEnvChannels>`、`captureAndClear()` は `list<non-empty-string>` なので、空文字や整数を直接渡す d-1 は PHPStan level 10 でテストコード自体が落ちる可能性があります。

  修正案: 公開 API の型を維持するなら、不正入力の実行時防御テストだけは `ReflectionMethod::invoke()` など静的契約を意図的に迂回する方法を明記してください。虚偽の `@var` や PHPStan ignore は使わないでください。

- [Warning] `restore()` は最初の `putenv()` 失敗で停止するため、後続キーが復元されません。  
  また、body の例外を復元例外が上書きします。「成否によらず元へ戻す」という契約より弱い実装です。

  修正案: 全キーの `$_SERVER` / `$_ENV` 復元と全 `putenv()` を最後まで試み、失敗キーを収集して最後に例外化してください。body 例外と復元失敗が重なった場合の優先順位・連結方法も契約に定めてください。

- [Warning] process 値の NUL を第1段で検証していません。  
  `withProcess()` は任意の string を受けるため、予測可能な `ValueError` が適用段階で発生します。

  修正案: 全キー検証と同じ第1段で、`processSpecified` な値の NUL を拒否してください。例外仕様にも `ValueError` を残さない形が望まれます。

- [Warning] 契約テストの前後掃除が、実行環境に元から存在した `RAW_ENV_PROBE_*` を破壊します。

  修正案: suite 開始時に部品を使わず3面を退避し、suite 終了時に元の存在状態まで復元してください。単に unset して終える構成にはしないでください。

## 施策 2: REQUEST_CHANGES

- [Critical] 可変関数呼び出しの仕様が矛盾しています。  
  保証外では「文字列から関数を呼ぶ形」を検出しないとする一方、自己検査では次を `unresolved` としています。

  ```php
  $fn = 'putenv';
  $fn('K=V');
  ```

  記載された走査手順には、この代入を呼び出しまで追跡する処理がありません。一方、すべての `$fn(...)` を未解決にすると、raw env と無関係な可変呼び出しまで全件違反になります。

  修正案: 次のいずれを採るか確定してください。

  - リテラル代入だけを追跡する限定的なデータフロー解析を仕様化する
  - すべての可変関数呼び出しを未解決にする場合は、現行母集団で成立することを実測し、検査対象を明記する
  - 保証外とする場合は「未解決 1」を削除し、i12 と D50 の保証表現もその範囲まで狭める

- [Critical] 分割代入を保証外にしながら、「部品の外に直接の書き込みが無い」と絶対表現しています。

  ```php
  [$_SERVER['K']] = $values;
  list($_ENV['K']) = $values;
  ```

  これは間接書き込みではなく、字句として明示された直接書き込みです。D50、G1、クラス docblock の主張と一致しません。

  修正案: `[]` / `list()` の左辺を検出対象へ加えるのが安全です。正典が明示的に対象外としている場合だけ、すべての文書を「列挙した構文に限る」と統一してください。

- [Warning] `unset()` 内に現れただけで書き込み扱いすると誤検出します。

  ```php
  unset($other[$_SERVER['K']]);
  ```

  この `$_SERVER` は読み出しです。

  修正案: `unset` の直接の writable target が `$_SERVER` / `$_ENV` を根に持つ場合だけ検出してください。この形を負例へ追加してください。

- [Warning] 名前解決の仕様に `T_NAME_RELATIVE`、複数 namespace、bracketed namespace がありません。

  修正案: namespace ごとに import map を持つか、複数 namespace・`namespace\putenv()` を未解決として落とす分岐を明記し、正例・負例を追加してください。

- [Warning] 検出を宣言する代入演算子に対して自己検査が不足しています。  
  `+=`、`**=`、`<<=`、前置 `++`、多段添字、面全体の複合代入などが検査されていません。

  修正案: `RawEnvWriteKind` が宣言する全トークン群を data provider で最低1回ずつ通してください。

- [Warning] 目録の型付き分類が実質的に検証されていません。G10 は3件であることしか保証せず、パスと enum の組み合わせを入れ替えても緑になり得ます。

  修正案:

  - 3つの許可パス集合を完全一致で pin
  - 各パスと `RawEnvDirectWriteAllowance` の対応を完全一致で検査
  - `counts` のキーが既知の `RawEnvWriteKind` だけであること
  - 件数が正の整数であること
  - `unresolved` が0件であること

  を追加してください。

## 施策 3: REQUEST_CHANGES

- [Warning] `productionEnvGuardRawSnapshot()` は `null` を setter として扱えないため、snapshot を消去できません。  
  `beforeEach` が `captureAndClear()` 中に失敗すると、`afterEach` が前ケースの snapshot を再利用します。「同じ値なので害がない」は、ケース間で外部状態が変わらないという未検証の前提です。

  修正案: `store()` と `take()` を分け、`take()` が必ず保存スロットを空にする構造にしてください。`afterEach` は取得したローカル snapshot だけを復元する形にします。

- [Warning] `productionEnvGuardFakeFlagVariables()` の宣言は `list<string>` のままです。  
  `captureAndClear()` の `list<non-empty-string>` へ渡す箇所で PHPStan が型不一致を報告し得ます。

  修正案: 定数の型根拠があるなら戻り値を `list<non-empty-string>` に更新してください。根拠が静的に得られないなら、空文字を実行時に検証し、検証済みの新しいリストを返してください。

- [Suggestion] 移送後に旧関数名が0件であることを `rg` 相当の確認だけで終えず、可能なら gate または対象テストの明示的な inventory で固定すると並走防止が明確になります。

## 施策 4: REQUEST_CHANGES

純関数への分離と、親プロセスへ dev DB 値を立てない方針は正しいです。

- [Warning] 「実際の親環境と接続値を結線する」新ケースは、その結線を検査していません。  
  提示された assertion はすべて compose の固定値です。`pgsqlTestParentEnv()` が空配列を返したり、`pgsqlTestConnValues()` の結果を捨てても、そのケースは緑になり得ます。

  修正案:

  - 3面の配列を引数に取る純関数 `pgsqlTestMergeParentEnv()` を設け、`SERVER > ENV > getenv` の優先順位を決定的に検査する
  - `pgsqlTestArtisanEnv()` の結果について、`PATH/HOME/TMPDIR` が `pgsqlTestParentEnv()` 由来であることを検査する
  - `DB_HOST/PORT/USERNAME/PASSWORD` が `pgsqlTestConnValues()` の結果と一致することを検査する

  これにより、親プロセスを書き換えずに結線を固定できます。

## 施策 5: REQUEST_CHANGES

- [Warning] D50 の保証表現が施策2の保証外一覧と矛盾しています。  
  「部品の外に3面への直接の書き込みが無い」は、分割代入などを検出しない現設計では成立しません。

  修正案: 施策2で検出範囲を完成させるか、D50・integration guide・gate docblock の全箇所を同じ限定表現へ修正してください。

- [Warning] integration guide の発火条件にある「`getenv()` を直接書き換える」は技術的に不正確です。`getenv()` は読み出しで、書き込みは `putenv()` です。

  修正案: 「`putenv()` / `$_ENV` / `$_SERVER` を直接書き換えるとき」へ修正してください。

- [Suggestion] D番号と件数を実装時に再確定する方針は妥当です。件数だけでなく、D50 の対象パス数も9件から変わった場合に同時更新するチェックリストを加えると安全です。

## 横断的な不足

- [Warning] 最終検証計画が AGENTS.md の必須コマンドを網羅していません。

  修正案: 対象テストの fail-first / green 確認後、最終段へ以下をすべて明記してください。

  - `composer test`
  - `composer phpstan`
  - `vendor/bin/pint --test`
  - `pnpm lint`
  - `pnpm typecheck`
  - `pnpm test`
  - `pnpm build`
  - `pnpm typecheck:packages`
  - `pnpm build:packages`
  - `pnpm test:packages`

UI、Atomic Design、DTO/JsonResource、Inertia Props/API Response は本変更では非該当です。dev DB への直接書き込みを純関数入力へ置き換える施策4の方向性は、セキュリティ不変条件に適合しています。