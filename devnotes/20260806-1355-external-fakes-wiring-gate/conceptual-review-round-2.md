# 全体判定: CHANGES_REQUESTED

Round 1 の Critical 2 件は方向として解消されています。特に同一 container で binding を巻き戻さない判断は正しいです。

ただし、網羅性 gate に核心的な抜け道が残っています。また、`afterEach` と連続実行に関する成功判定には技術的に成立しない記述があります。

## 1. 使命との整合性

[Suggestion] 十分に整合しています。

撮影データと課金の外部到達を fake 配線で隔離する保証は、North Star への間接的ながら重要な貢献です。テスト基盤としての優先度 High も妥当です。

## 2. 禁止事項違反

[Warning] §5 に禁止事項番号の誤参照が残っています。

対応マトリクスと §6.4 は修正されていますが、§5 の最終行にはまだ次の記述があります。

> 既存テストは削除も改変もしない (禁止事項 3)

AGENTS.md の禁止事項 3 は dev DB の破壊操作です。

修正提案: §5 から番号参照を削除し、§6.4 と同じ「既存 Feature テストを振る舞い回帰として残すため」に統一してください。

## 3. 実現可能性

[Warning] `refreshApplication()` は利用できますが、「復元機構」として積極的に検査する必要性は薄いです。

Laravel の `refreshApplication()` は新しい Application を生成しますが、旧 Application の binding を巻き戻す操作ではありません。Facades、static、外部参照まで一括で初期化する一般的な sandbox APIでもありません。

今回の container 検査では次の構成が堅実です。

- real 対照と fake 実証を独立 test case にする
-各 test case は Laravel の通常の `setUp` / `tearDown` に任せる
- env/config を一つの test 内で切り替える必要がある場合だけ `refreshApplication()` を使用する
- `Prompt` は `finally` とファイル限定 `afterEach` の両方で `stopFaking()` する

「fake 実証後に refresh して real」を必須の不変条件にすると、fake provider ではなくLaravel TestCase 自体を検査するテストになります。削除しても本来の事故検出力は落ちません。

## 4. 検査の有効性

[Critical] 網羅性走査は依然として deny-by-default になっていません。

現在の条件 1 は、列挙された API だけを検出します。例えば以下は検査対象から抜けられます。

- `$this->app->rebinding(...)`
- `$this->app->getContainer()->singleton(...)`
- `app()->bind(...)`
- `Container::getInstance()->bind(...)`
- closure 内で fake を生成する `extend()` 相当の新しい書き方
- 将来追加される Laravel Container API

つまり、「既知の bind 以外を禁止」ではなく「既知の代替 API の一部を禁止」になっています。

修正提案: provider 内の container mutation を構文上ひとつの形に制限してください。

- 許可する登録構文は直接の `$this->app->bind(A::class, B::class)` のみ
- `$this->app` からの間接的な container 取得を禁止
- `app()`、Container facade、`Container::getInstance()` による登録を禁止
- provider が参照する fake クラスについて、Prompt など理由付き例外を除き、すべて inventory の `fake` として現れることを集合一致で確認

これで「未知の API 名を列挙できなかった」という抜け道を、fake クラス参照側からも閉じられます。

[Warning] fake クラス母集団のディレクトリ規約が未固定です。

`**/Fakes/*.php` と `**/Testing/*.php` 以外に `FakeFoo.php` が追加されると、施策2の母集団に入りません。

修正提案: 「本番コードに載る fake は必ず `Fakes` または `Testing` 配下」という命名・配置規約をArchitecture テストで固定するか、クラス名の `Fake*` も候補として走査してください。

## 5. 状態リークと並列実行

[Warning] `afterEach` を経た状態を、その test case 自身では assertion できません。

> afterEach を経て必ず false に戻る

`afterEach` 完了後にはテスト本体が終わっているため、その表現の検査は成立しません。後続テストで確認すると順序依存になります。

修正提案: LLM fake のテスト本体を `try/finally` にし、`finally` で `stopFaking()` 後に `Prompt::isFaking() === false` をassertしてください。`afterEach` は assertion ではなくフェイルセーフとして残します。

[Warning] Architecture suite の2回連続実行は「同一 worker 内リーク」の検出にはなりません。

別々のコマンド実行なら PHP プロセスも static 状態も新しくなります。

修正提案:

- 2回実行は「再実行安定性」の確認と記載する
- 同一プロセス内リークはランダム順実行と、各 test の `finally` / `afterEach` で保証する
- random seed を失敗時に記録して再現可能にする

## 6. スコープの適切さ

[Suggestion] `refreshApplication()` 後の real 再解決と route 二重 boot 検査はやや過大です。

登録漏れ、provider 脱落、順序反転、inventory 漏れという主目的には直接必要ありません。特に route 冪等性は signed route の Feature テスト側の責務に近いため、既存テストで未保証の場合だけ残すのが妥当です。

柱2と実環境変数の二重判定を今回外す判断、および再検討条件は妥当です。

## 7. 三層構成の妥当性

[Warning] inventory の `risk` と `mutation` は説明力を上げますが、機械的な形骸化防止にはなりません。

どちらも自由記述なら、空でない文字列を入れるだけで満たせます。

修正提案: `mutation` は文章ではなく安定した mutation ID とし、§1・詳細設計・inventory のID集合一致を検査してください。`risk` はレビュー用説明として維持すれば十分です。

結論として、Round 1 の container 復元問題は解決方向です。承認に必要なのは、網羅性走査を「既知 API の列挙」から「許可された唯一の登録形＋fake参照集合一致」に変更し、`afterEach` と連続実行の誤った検査表現を修正することです。