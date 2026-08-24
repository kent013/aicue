結論として、Round 4 の最重要問題のうち S9 関連の2件は解消しています。一方、`idempotency-claim-probe.php` の裏取り不足は、主張を狭め切れていないため未解消です。

## `tests/Unit/Support/Process/BootProbeRunnerTest.php`

判定: 概ね妥当。ただし後片付けに残件あり。

- 未正規化パスを `isInside()` に渡していた問題は解消しています。`$result->temporaryRoot.'/.env'` との完全一致は、この経路で最も強い検査です。
- 実 `.env` の解析と資格情報 digest を廃止し、真偽値だけを報告する変更も妥当です。秘密や推測用 verifier は出力されず、`.env` の有無にも依存しません。
- 退避なし／ありの負の裏取りも、4項目すべてが反転しており十分です。

- [Warning] S11 は `mkdir($base, ..., true)` で作られた祖先をまだ戻していません。`storage/framework` 自体が存在しない環境では、最後に削除するのは `storage/framework/testing` だけで、作成した親が残ります。P-10d と同じ `$createdAncestors` 方式が必要です。Round 4 のこの指摘は完全には解消していません。

- [Suggestion] `$report` の PHPDoc が `array<string, string>` のままですが、追加された3項目は bool です。PHPStan対象外でも、実態に合わせて `array<string, mixed>` またはshapeへ直す方が安全です。

## `tests/Architecture/PhpBootProbeReferenceInventoryTest.php`

判定: 修正が必要です。

- [Critical] `idempotency-claim-probe.php` について、実挙動の裏取りがないまま、G-8 は「リポジトリ `.env` を読む子は0件」という不変条件を主張しています。

  スコープ境界として `ConcurrentProbeObservation` を変更しない判断自体は妥当です。しかしその場合、同経路を「実挙動で裏取り済み」と同じ集合に入れることはできません。現在は次の3点が互いに矛盾しています。

  - `behaviour_proof` の定義は「実挙動で裏取りしている検査」
  - 当該entryは「環境ファイル切り替えを直接測る検査は無い」と明記
  - G-8は全child entryについて0件を不変条件として表明

  対応はどちらかです。

  - 実効 `environmentFilePath()` の完全一致を子→DTO→親まで実測する
  - `behaviorally_verified` と `structurally_pinned` を分け、後者について「実際に読まない」とは主張しない

- [Critical] `phpBootProbeMentionsEnvironmentPathRelocation()` はG-9の証拠として偽グリーンになります。

  名前トークン側では、受け手を問わないため次でも通ります。

  ```php
  $unrelated->useEnvironmentPath($dir);
  ```

  文字列側は `str_contains()` なので、次も通ります。

  ```php
  '$app->notUseEnvironmentPath($dir);'
  'useEnvironmentPath is required'
  '$app->useEnvironmentPathX($dir);'
  ```

  存在を肯定する証拠として使う走査では、拾いすぎは安全側ではありません。G-6が未解決の受け手を証拠に数えないのと同じ理由です。

  また、文字列トークン側の素の部分文字列一致は共通規約(e)を満たしません。接頭辞・打ち消し・接尾辞の負例は名前トークンにしか置かれておらず、文字列分岐を裏取りできていません。

  したがってG-9は現状、共通規約のうち以下が不十分です。

  - (b): 意味を解決できない呼び出しを退避の証拠として採用
  - (c): 文字列分岐の負例が不足
  - (e): 文字列内をトークン完全一致ではなく部分文字列で判定

  (d)の「収集結果を判定に使う」と母集団非空は満たしています。

- [Warning] `fake-wiring-probe.php` の `behaviour_proof` に指定したP-8も、専用ファイル由来のCiphersweet鍵が効いたことは示しますが、`environmentFilePath()` 自体や「他の `.env` 値を一切読んでいないこと」は直接測りません。G-8の広い主張に使うなら、S9同様のパス完全一致が適切です。

G-8のテスト名を「申告上」へ直した点と、名指し先の実在確認は改善されています。ただし実在確認は実挙動の証明ではありません。

## `tests/Support/ExternalFakes/FakeWiringProbeRunner.php`

判定: 実装本体は妥当です。

- 4段の環境合成、使い捨て鍵、外側／内側一時ディレクトリ、timeoutのfail-closedは設計どおりです。

- [Warning] 汎用runnerの必須契約をこの特定呼び出し側だけに記述しても、`BootProbeRunner` の公開契約そのものは訂正されません。また「各経路の実挙動の検査が守る」とありますが、idempotency経路にはその検査がありません。バイト一致を維持する判断は理解できますが、少なくとも記述は「一部経路は構造pinのみ」と合わせる必要があります。

## `tests/Support/Process/BootProbeRunner.php`

判定: 実装上の新規問題なし。

- [Warning] 「ここが開発者ローカルenvを締め出す唯一の統制点」は、環境ファイルを含む一般的な説明としては依然不正確です。別ファイルの訂正表ではなく、このクラスから直接たどれる契約にするのが望まれます。

## `tests/Support/Process/BootProbeResult.php`

判定: 実装上の新規問題なし。

- [Warning] 既出のとおり、`timedOut === true && exitCode === 0` が可能なのに「強制終了なら124」とするPHPDocは実装と一致していません。今回の呼び出し側は誤記に依存していません。

## `tests/Architecture/ExternalFakeBootProbeTest.php`

判定: P-7、P-10c、P-11、P-13〜P-16は妥当です。

- [Warning] P-10dの祖先削除は、並列workerが途中で同じ場所へ書いた場合にも無条件で`rmdir()`します。S11では「空なら削除」という配慮があるのに、こちらは非空時にwarningまたは失敗になり得ます。作成した祖先についても、空であることを確認してから深い順に戻す方が並列契約に合います。

## `tests/Support/ExternalFakes/fake-wiring-probe.php`

判定: 指摘なし。

専用環境ファイルの設定位置、marker、書き出し先、使い捨て鍵の観測は妥当です。

## `tests/Support/StrictTypesRuntimeProbe.php`

判定: 指摘なし。

アプリ起動を測らない経路を共通runnerへ統合しない判断は妥当です。

## 詳細設計・検証証跡

- [Warning] S1の「実装2本のみSHA一致、自己検査は意図的差分」への更新は十分です。
- [Warning] 一方、S6の詳細設計は依然としてG-1〜G-7、旧走査仕様を記述しており、実装済みのG-8/G-9、FQCN解決、`behaviour_proof`契約と一致していません。
- EmailPromotionTestの失敗は、提示された同一コードでのpass/fail、単独green、変更領域の非連結性から、T249の回帰とは見なしません。別件の順序依存flakeとして扱ってよいです。ただし「全体スイートが安定してgreen」とは報告できません。B/Cの2回連続greenは、受入条件の字面自体は満たしています。

## 全体判定

**CHANGES_REQUESTED**

S9のパス検査と資格情報処理は解消済みです。しかし、Round 4の3件目は「別featureを変更しない」という線引きと、「全child entryで実挙動上 `.env` 読み込み0件」という主張が両立していません。さらにG-9の文字列分岐は部分文字列一致による偽グリーンを持つため、セキュリティ不変条件の裏打ちとしては承認できません。