# 対応マトリクス: design-review Round 1

全体判定 **CHANGES_REQUESTED**。[Critical] はゼロ。[Warning] 4 件・[Suggestion] 2 件。
**Warning は 4 件すべて対応した** (反論・見送りゼロ)。

## [Warning] S2 の self-test が tests/Pest.php のレーン既定に依存しており、実装順序 S2 → S1 → S3 と噛み合わない (S3 前に実通信するリスク)
- 判断: **対応する**
- 根拠: 指摘のとおり致命的。実装順序を「テストファースト」で S2 → S1 → S3 と定めた以上、
  S3 適用前に本ファイルを走らせる局面が必ず存在する。そのとき guard 未 install なら
  case A の `Http::get('https://api.frankfurter.dev/...')` は**実際に外へ出る**。
  「HTTP 出口を塞ぐ作業のために外部へ出る」は本末転倒であり、設計の欠陥。
  さらに自己検査は「guard が install されていれば何が起きるか」の契約テストなので、
  前提を自分で用意する方がテストとして自己完結する。
- 対応内容: `detailed-design.md` S2 の骨格に
  `beforeEach(function (): void { StrayHttpRequestGuard::install($this->app); });` を追加し、
  「レーン既定に依存しない」理由 (2 点) と「S3 後は二重 install になるが install() は冪等
  (case G が固定)」をコメントとして明記した。骨格のテスト一覧にも `beforeEach(...)` を追記。

## [Warning] case D の `127.0.0.1:9` は環境依存で flaky になりうる
- 判断: **対応する**
- 根拠: 「port 9 が閉じている」は OS / コンテナ / inetd 設定に依存する強い前提で、
  `--parallel` 実行の CI で偽赤を生む。加えて、そもそも case D の主眼は
  「**stray 判定を通過したか**」であって「接続がどう失敗したか」ではない。
  `ConnectionException` を固定していたのは主眼の取り違えだった。
- 対応内容: (1) `stream_socket_server('tcp://127.0.0.1:0')` で OS に一時ポートを割り当てさせ、
  `stream_socket_get_name()` でポート番号を取って close する形に変更。
  (2) assert を `expect($caught)->not->toBeInstanceOf(StrayRequestException::class)` に変更し、
  例外型を固定しない (接続成功でも成立する = close 後の再割当 TOCTOU にも耐える)。
  (3) テスト名を「(stray 判定を通過して送信段まで進む)」に改め、主眼を明示。
  (4) リスク表を書き換え (`ConnectionException` 前提の行を削除し、TOCTOU 耐性の行を追加)。
  (5) 不要になった `use Illuminate\Http\Client\ConnectionException;` を削除。

## [Warning] S4 の opt-out 検出が `preventStrayRequests(false)` の literal に寄りすぎ (逃げ道が残る)
- 判断: **対応する**
- 根拠: 指摘どおり。`preventStrayRequests($flag)` / `((bool) 0)` / 名前付き引数
  `prevent: false` はすべて既定拒否を外せる。deny-by-default gate が
  「特定の書き方だけ」を見るのは自己矛盾で、gate の存在意義を損なう。
  Codex が提示した「無引数だけを許可し、それ以外は inventory 必須」が最も単純かつ安全。
- 対応内容: `strayHttpEgressOptOutSites()` の契約を
  「`preventStrayRequests` の直後の `(` から対応する `)` までが**空白のみ**なら許可、
  引数が 1 文字でもあれば opt-out として inventory 必須」へ変更。
  `allowStrayRequests(` は引数を問わず全件対象 (null は prevent OFF、配列は許可集合の**置換**で
  merge ではないため、区別しない)。
  1 ファイル分の判定を純関数 `strayHttpEgressIsOptOutSource(string $source): bool` に切り出し、
  fixture でテストできる形にした。負のコントロール
  「preventStrayRequests の非 literal opt-out を書き方によらず検出する」を追加
  (literal / variable / cast / named / allow-null / allow-array の 6 形 + 無引数とコメントの
  非検出 2 形)。mutation 手順に M9 を追加。

## [Warning] gate が「install / flush / reset が本当に beforeEach / afterEach closure 内にあるか」を強く保証しない
- 判断: **対応する** (負のコントロール追加に留めず、判定ロジック自体を強化する)
- 根拠: Codex の修正案は「負のコントロールを足せば最低限固定できる」だったが、
  負のコントロールは**純関数が壊れた入力を検出できること**しか示さない。
  判定ロジックがオフセット前後関係のままなら、実ファイルで
  「beforeEach と afterEach の間だが closure の外」に install を書いた瞬間に偽緑になる。
  gate の目的 (レーン既定であることの保証) に対して弱すぎるので、ロジックを直す。
- 対応内容: 純関数 `strayHttpEgressClosureBody(string $code, int $openOffset): string` を新設し、
  `->beforeEach(` / `->afterEach(` 以降で最初に現れる `{` から**波括弧の対応を数えて**
  closure 本体を切り出す。`strayHttpEgressLaneViolations()` の契約を
  「install は beforeEach の**closure 本体内**」「flush / reset は afterEach の
  **closure 本体内**」へ変更した。負のコントロール
  「install が beforeEach closure の外にある配線を検出する」(欠落形と closure 外形の 2 fixture)
  を追加し、mutation 手順に M8 を追加。

## [Suggestion] S1: `localhost` 許可を残す理由を定数コメントに明示する
- 判断: **対応する**
- 根拠: 「127.0.0.1 で足りるなら `localhost` は名前解決依存の余計な許可では」という問いは
  次の担当も必ず持つ。答えを定数の隣に置かないと、いずれ削られて偽赤を生む。
- 対応内容: `ALLOWED_URL_PATTERNS` の docblock に理由を追記
  (表記揺れによる偽赤コストの方が大きい / 解決先が loopback でなければそもそも到達しない /
   `aicue.test` のような任意カスタムドメインは**入れない** = 許可集合を環境依存にしない)。

## [Suggestion] S3: 2 guard の flush で片方の詳細が落ちる旨をコメントに明記する
- 判断: **対応する**
- 根拠: 将来の調査効率に直結する 1 行。コストゼロ。
- 対応内容: `tests/Pest.php` の afterEach コメントを
  「**同時発生時は先に throw した guard の詳細だけが表示される**」と明示する形に書き換えた
  (Feature/Unit lane と Browser lane の両方)。
