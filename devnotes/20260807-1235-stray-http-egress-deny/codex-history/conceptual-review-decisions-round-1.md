# 対応マトリクス: conceptual-review Round 1

Round 1 の全体判定は **APPROVED**。Critical はゼロ。Warning / Suggestion の処理を記録する。
APPROVED のため概念設計フェーズはここで終了し、指摘のうち詳細設計で解くべきものは
Phase 2 (detailed-design.md) へ持ち越す。

## [Warning] テストファーストの導入順序を明記すべき
- 判断: **対応する**
- 根拠: AGENTS.md 思考原則 5「テストファースト。fail を確認してから実装に入る」に直結する。
  概念設計に順序が書かれていないと、実装者が guard を先に書いて自己検査が空振りする。
- 対応内容: `conceptual-design.md` の実装方針に「導入順序 (テストファースト)」段落を追加
  (S2 → S1 → S3 → S4 → S5、gate は mutation で赤化確認)。詳細設計の各施策のテスト計画でも
  「先に赤を見る手順」を明記する。

## [Warning] globalMiddleware の重複登録リスク (install を beforeEach で毎回積む)
- 判断: **対応する** (ただし指摘の前提は一部訂正して伝える)
- 根拠: 実コードを確認した結果、`Illuminate\Http\Client\Factory` は container の singleton であり、
  Laravel の TestCase は各テストの `setUp()` で `refreshApplication()` を実行して**新しい container を
  作る**ため、Factory も毎テスト新品になる = 通常経路では重複登録は起きない。
  ただし「同一テスト内で install を 2 回呼ぶ」「将来 refreshApplication を経ない lane が増える」
  ケースで指摘は成立し、accumulator の二重記録は guard の信頼性を直接損なう。
  **安全側に倒す価値が明確にある**ので冪等化する。
- 対応内容: 詳細設計 S1 で `install()` を冪等にする
  (`Factory::getGlobalMiddleware()` を走査し、guard の marker closure が既に居れば再 push しない)。
  S2 に自己検査 case を追加: 「同一プロセスで install を 2 回呼んでも stray 1 件に対し
  accumulator は 1 件」。

## [Warning] Architecture lane の bootstrapping 前提確認
- 判断: **対応する** (指摘の前提は実コードで確認済み = 成立している)
- 根拠: `tests/Pest.php:65-69` の Architecture lane は `pest()->extend(TestCase::class)` で
  `Tests\TestCase` (= `Illuminate\Foundation\Testing\TestCase`) を使っており、`withoutVite()` を
  呼べている時点で Laravel app は立っている (`RefreshDatabase` を使っていないだけ)。
  よって `Http` facade / `Application` 注入に依存する install は動く。
- 対応内容: 詳細設計 S3 で「Architecture lane も Laravel app 上で動くため install 可能」を
  根拠付きで明記し、S4 の gate に「Architecture lane も install/flush を持つこと」を含める。
  併せて S2 に「Architecture lane から guard を触る自己検査」は置かない
  (Architecture lane は DB を使わない走査専用で、HTTP を出す自己検査を置くと lane の
  役割分担が壊れる)。代わりに gate がソース走査で配線の実在を固定する。

## [Warning] 期待効果「秘密の漏出面の縮小」の保証範囲が広く読める
- 判断: **対応する**
- 根拠: 過大な保証を書くのは裁定 AG-105 が明示的に禁じている点そのもの
  (「別プロセスの探索的検査には無言で効かない。両者を対称に書くのは禁止」)。
- 対応内容: `conceptual-design.md` の該当箇条書きを
  「秘密の漏出面の縮小 (Laravel HTTP client 経由に限る)」へ書き換え、
  Socialite / Stripe SDK / AWS SDK / ブラウザ fetch が対象外であることをその場に併記した。

## [Warning] 局所 preventStrayRequests 5 箇所と allowlist 上書きの相互作用
- 判断: **対応する**
- 根拠: 実コードを確認すると `PendingRequest::allowStrayRequests(array $only)` は
  `array_values($only)` で**置換**する (merge しない) ため、指摘は原理的に正しい。
  ただし既存 5 箇所はいずれも `Http::preventStrayRequests()` を引数なしで呼ぶだけで
  `allowStrayRequests` を呼んでいない (リポジトリ全体で 0 件) ため、現時点で上書きは起きない。
  問題は「将来誰かが局所 allowlist を書いたときに既定が静かに壊れる」ことなので、
  **gate で禁じる**のが正しい打ち手。
- 対応内容: 詳細設計 S4 の gate に「テスト本体での `Http::allowStrayRequests(...)` 直呼びは
  exemption inventory 登録必須」を deny-by-default で入れる (置換の危険を機械的に止める)。
  併せて S5 で既存 5 箇所へ「レーン既定と同値の重複宣言であり allowlist を触っていない」
  位置づけコメントを付ける。

## [Suggestion] allowlist を behavioral test でも固定する (127.0.0.1.evil.example の負テスト)
- 判断: **対応する**
- 根拠: 末尾ワイルドカード 1 本にしない判断が本設計の肝で、gate の定数検査だけでは
  「定数が正しい形をしている」しか言えない。実際に `Str::is()` が弾くことは behavioral でしか
  固定できない。
- 対応内容: S2 に case を追加 (`http://127.0.0.1.evil.example/` が stray として記録される /
  `http://127.0.0.1:8010/x` は通る)。

## [Suggestion] 「新規 3 本」と表の 4 ファイルの不一致
- 判断: **対応する**
- 根拠: 単純な記述ミス。実装者の混乱を招く。
- 対応内容: `conceptual-design.md` の見出しを「新規 4 本」へ修正した。

## [Warning] exemption entry を value object / shape annotation で型付けする
- 判断: **対応する (ただし value object ではなく shape annotation を採る)**
- 根拠: 既存の同種 gate (`ThrottleCoverageInventoryTest` /
  `tests/Support/Security/DirectFetchInventory.php`) は
  `array<string, array{Enum, string}>` の phpdoc shape + 理由の最小長 30 文字検査という形で
  統一されている。ここだけ readonly value object にすると、同じ役割のものが 2 形式になり
  「別物の概念を似ているからで統合しない」の逆 = 同じ概念を無理由に分岐させる。
  PHPStan level 10 は phpdoc shape で十分縛れる (既存 gate が実際に通っている)。
- 対応内容: 詳細設計 S4 で inventory を
  `@return array<string, array{StrayHttpEgressExemption, non-empty-string}>` と型付けし、
  理由の最小長 30 文字 + exemption 件数 cap (exact fit) + case 別 cap を既存 gate と同形で置く。

## [Suggestion] 使命との整合 / スコープ切り分け / 型安全性 は妥当との評価
- 判断: **見送る** (対応不要)
- 根拠: 指摘ではなく肯定的評価。
