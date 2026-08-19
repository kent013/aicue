## レビュー前提・検証仮説

仮説は「OCR 分岐の方向性は妥当だが、媒体付き Prompt の実行状態・媒体検証・OCR 成功判定に、既存の安全境界を失わせる箇所がある」です。

成功条件を以下に置いて確認しました。

- OCR 経路でも system prompt、canary、provider/model/options、課金帰属 metadata が欠落しない
- 保存済みファイルを信用せず、provider 送信直前の境界で上限・形式を再検証する
- 読み取れなかった媒体を「日本語 OCR 成功」と誤認しない
- 静的 gate が列挙した特定メソッドだけでなく、実際の迂回経路も既定拒否する
- PR を分割しても、アップロードだけ受理して解析できない中間状態を production に出さない

## 判定一覧

| # | 施策 | 判定 |
|---:|---|---|
| 1 | 画像 MIME の受理 | REQUEST_CHANGES |
| 2 | 抽出失敗理由の enum 化 | APPROVE |
| 3 | 媒体 DTO とバリデータ | REQUEST_CHANGES |
| 4 | `PromptDefense::loadWithMedia()` | REQUEST_CHANGES |
| 5 | OCR prompt factory + YAML | REQUEST_CHANGES |
| 6 | パイプライン分岐 | REQUEST_CHANGES |
| 7 | OCR 経路の成功条件 | REQUEST_CHANGES |
| 8 | 静的 gate 拡張 | REQUEST_CHANGES |
| 9 | token budget 不変条件 | REQUEST_CHANGES |
| 10 | UI 文言・案内 | REQUEST_CHANGES |
| 11 | 観測・課金・rollout | REQUEST_CHANGES |

---

## 施策 1: 画像 MIME の受理

**判定: REQUEST_CHANGES**

[Warning] 画像専用上限が FormRequest にしか実質存在せず、解析境界では再検証されません。既存レコード、ストレージ差し替え、別の保存経路から上限超過媒体が到達できます。

修正案:

- FormRequest の上限は早期拒否用と位置付ける。
- `AnalysisMediaValidator` でも実バイト数を独立して検証する。
- Laravel の `max` が KiB 単位である場合、bytes 設定値からの変換規則を共通メソッドに閉じ、端数境界をテストする。
- JPEG/PNG/PDF について「上限ちょうど」「1 byte 超過」を固定する。

[Warning] 「1 手順書につき画像 1 枚」が UI にしかありません。複数回 POST できるなら容易に迂回できます。

修正案:

- 本当にドメイン不変条件なら、親 relation 経由で既存画像数を確認し、Service 内で拒否する。
- 並行アップロードを許さない必要があるなら、親行ロックまたは DB 制約を含めて競合テストを追加する。
- UI はサーバー側拒否理由を表示するだけにする。

[Warning] 施策 1 だけを先行デプロイすると、画像を受理するのに解析は失敗する中間状態になります。

修正案:

- MIME 受理の有効化は、施策 3〜9 と同じ vertical slice に含める。
- または機能を既定無効にし、パイプライン完成後に有効化する。

---

## 施策 2: 抽出失敗理由の enum 化

**判定: APPROVE**

文字列比較から型付き reason に移す方向は妥当です。既存 `userMessageFor()` の公開契約も変わりません。

[Suggestion] `isOcrEligibleForPdf()` は「失敗理由そのもの」よりパイプラインの遷移方針に近いため、将来 OCR 対象媒体が増えるなら resolver 側の明示的な `match` へ移す余地があります。現時点の規模では enum に置いても問題ありません。

追加で、全 case と全 named constructor の対応をデータセットで完全一致検査してください。新しい case の追加時にテスト対象から漏れない構造が望まれます。

---

## 施策 3: 媒体 DTO とバリデータ

**判定: REQUEST_CHANGES**

[Critical] `validateImage()` は画像の実バイト数を上限検査していません。`source_document_image_max_bytes` を通さずに `fromValidated()` へ到達できます。PDF にも OCR/provider 送信用の独立したバイト上限がありません。

修正案:

1. `Storage::get()` 直後、parserやvendor変換より前に `strlen($bytes)` を検査する。
2. JPEG/PNG と PDF の provider 送信上限を明示する。
3. raw size だけでなく、base64・JSON 化後の request size 制限との関係も一次情報から導出する。
4. FormRequest と validator の両方を境界値テストする。

[Critical] provider/model 判定が `prism-prompt.default_*` を見ていますが、媒体 YAML は provider/model を明示しています。実行される設定と検証対象が一致しません。

このままでは以下が起きます。

- default が変わっただけで、Anthropic に固定された媒体 prompt を誤って拒否する
- YAML の model が変わっても、default が旧値なら validator は通す

修正案:

- 実際にロードされる `sop-extract-media` の provider/model を正本として検証する。
- YAML の値を Architecture テストで pin する。
- validator は媒体の妥当性に集中させ、prompt 実行設定の整合性は prompt/gate 側に置くのが自然です。

[Warning] `validateImage()` と `validatePdfForOcr()` が引数の MIME を検証しません。public メソッドへ異なる媒体を渡しても、DTO の `mime` にそのまま保存されます。

修正案:

- `validateImage()` は `image/jpeg|image/png` 以外を拒否する。
- `validatePdfForOcr()` は `application/pdf` 以外を拒否する。
- DTO 生成直前にも許可集合を満たしていることを保証する。

[Warning] 破損 PDF を `MediaTooLarge` にするのは理由と実態が一致しません。利用者文言と観測値も誤ります。

修正案:

- `MediaUnsupportedFormat`、または `MediaUnreadable` のような理由へ正規化する。
- 上限超過と parser 失敗は別テストにする。

[Warning] PDF フォールバックでは `SopTextExtractor` と validator がそれぞれ `Storage::get()` し、PDF も再解析します。「単一読み込み」のテストを validator 単体だけに置くと、パイプライン全体の二重読み込みを見逃します。

修正案:

- パイプライン全体でストレージ読み込み回数を検証する。
- 単一読み込みが不変条件なら、bytes を先に読み、抽出器と媒体 validator に渡せる構造へ変更する。

---

## 施策 4: `PromptDefense::loadWithMedia()`

**判定: REQUEST_CHANGES**

[Critical] 提案された無名 `TextPrompt` は、実行に必要な状態を `$inner` から引き継ぎません。

`withMetadata()` を適用しているのは `$inner` ですが、`GuardedPrompt` が実行するのは外側の `$mediaPrompt` です。外側は親コンストラクタを呼ばず、provider/model、system prompt、metadata、client options、max tokens 等を初期化していません。

その結果、少なくとも以下のリスクがあります。

- `LlmCallContextData` の帰属 metadata が失われる
- system prompt と defensive instructions が送られない
- canary が送信されず、応答検査が形骸化する
- YAML の provider/model/timeout/max_tokens が失われる
- vendor 内部の未初期化プロパティで実行時エラーになる

「他の public メソッドは継承する」は、この構造では成立しません。

修正案:

- pin 済み vendor が提供する正式な message/media 拡張 APIを使用する。
- subclass が必要なら、親の初期化契約を満たす形で構築し、設定・metadataを外側の実行インスタンスに保持する。
- 単に `$inner->render()` を呼ぶ adapter にはしない。
- vendor 側に安全な拡張点がない場合は、先に小さな upstream/vendor adapter を設計し直す。

必須の契約テストは、最終的な外部呼び出し直前の request を捕捉し、以下を確認する必要があります。

- provider/model/max_tokens/timeout
- organization/subject/actor metadata
- system message に防御指示と canary が存在
- user message にテキストと媒体が期待順で存在
- canary を返す fake 応答が `GuardedPrompt` で拒否される

[Warning] PHPStan level 10 では、anonymous subclass と `TextPrompt` の generic、override の戻り値、親コンストラクタ契約が問題になり得ます。「実装時に確認」だけでは詳細設計として不足しています。

修正案:

- pin 済み vendor の実シグネチャを確認した結果を詳細設計へ反映してから着手する。
- 契約が確認できない疑似コードを実装仕様として確定させない。

---

## 施策 5: OCR prompt factory + YAML

**判定: REQUEST_CHANGES**

[Warning] `PromptUntrustedInputContractTest` の「untrusted キー」と「帰属 exempt」が混同されています。

この factory は `LlmCallContextData` を必須で受けるため、帰属 exempt にはしてはいけません。AGENTS.md 上、帰属を持たない例外は `ExampleSummaryPrompt` だけです。

修正案:

- 帰属 inventory には organization/subject/actor の期待キーを通常登録する。
- 文字列 `untrusted` が空であることだけを、媒体入力専用の別分類として明示する。
- `loadWithMedia` の unattributed 版が存在しないことを gate で固定する。

[Warning] 防御指示と schema の一致を文字列の存在だけで検査すると、意味を変えた文面や一部欠落を見逃します。

修正案:

- text/media YAML の出力 schema 部分を正規化して完全一致検査する。
- 媒体 prompt について、system/user prompt の双方が存在し、system 側に canary と媒体命令無視の防御指示があることを固定する。
- 「`untrusted` は合言葉のみ」という説明は修正する。canary は内部変数であり、`untrusted` 配列の要素ではありません。

---

## 施策 6: パイプライン分岐

**判定: REQUEST_CHANGES**

[Warning] `match (true)` の `default` 節で、PHPStan が `$input` を媒体2型へ絞り込める保証がありません。`SopExtractFromMediaPrompt::make()` は `ExtractedText` を受けないため、level 10 で union 不一致になる可能性があります。

修正案:

- 3型を明示的に列挙する。
- または媒体2型だけを受ける private helper に、型が確定した分岐から渡す。
- `default` で「将来 union が増えても媒体扱いする」構造にしない。

[Warning] OCR route の選択・失敗理由が構造化して観測されません。後述の評価期間で「PDF OCR フォールバック率」「画像 OCR 成功率」「上限拒否率」を計測できません。

修正案:

- 本文を含めない固定 event として、source kind、route、failure reason、size/page/pixels を記録する。
- 例外 message の集計に依存しない。

分岐方針自体、特に `TooLarge` を OCR に回さない点と、OCR の品質失敗を retry しない点は妥当です。

---

## 施策 7: OCR 経路の成功条件

**判定: REQUEST_CHANGES**

[Critical] prompt が判読不能箇所に `"(判読不能)"` を出すよう指示しているため、完全に読み取れなかった結果でも日本語比率を満たします。

`判読不能` 自体が日本語文字なので、提案された acceptance gate は最も拒否したい結果を成功扱いします。

修正案:

- 判読不能 marker を比率計算から除外する。
- marker 除去後に、最低文字数と日本語比率の両方を要求する。
- より堅牢にするなら、schema に `readability` や `unreadable_fragments` のような明示フィールドを持たせ、全手順が判読不能なら拒否する。
- 「判読不能だけ」「一部だけ判読可能」「正常」「日本語らしい捏造」の評価 fixture を用意する。

[Warning] テスト計画の「全て空文字の work_process」は構築できません。`ExtractedSopData::validateStep()` が非空文字列を要求するため、`fromLlmText()` で先に拒否されます。

修正案:

- 検証順序テストは、空文字では schema violation になることを固定する。
- acceptance gate の負例には `"(判読不能)"` や英数字のみの非空文字列を使う。

[Warning] `SopTextExtractor::japaneseRatio()` を単純に static 化すると、現在の `$this->countBy()` を呼べません。また「テキスト抽出器」が OCR 結果の一般的な品質判定まで提供するのは名前と責務がずれます。

修正案:

- `JapaneseTextRatio` などの副作用なし utility/value service にパターンと計算を移す。
- `SopTextExtractor` と `AnalysisAcceptanceGate` の双方がそれを利用する。
- 既存の SJIS 修復用比率と文書受理用比率を混同しないテストを残す。

---

## 施策 8: 静的 gate の拡張

**判定: REQUEST_CHANGES**

[Critical] `Image::fromRawContent` と `Document::fromRawContent` だけを検出しても既定拒否になりません。別 factory、コンストラクタ、message への媒体添付 APIを使えば窓口を迂回できます。

修正案:

- pin 済み vendor に存在する媒体生成・添付 API の母集団を棚卸しする。
- 少なくとも以下を gate 対象として評価する。
  - `Image` / `Document` の全生成経路
  - `UserMessage` 等への媒体添付
  - custom message 構築 API
  - vendor prompt の subclass / direct construction
- 保証外にする構文があれば、gate 名と docblock の検出力主張もその範囲に狭める。
- 未解決 FQN は違反候補または解析失敗として落とす。

[Warning] 件数記述が矛盾しています。コード上は `Image::fromRawContent` と `Document::fromRawContent` の2呼び出しですが、「PromptDefense.php の1件」とされています。

修正案:

- 「許可ファイル1件」と「検出呼び出し2件」を分けて inventory 化する。
- シンボルごとの exact count を固定する。

[Warning] `MediaPromptExtendsDeclaration` は `extends Prompt` と `extends TextPrompt` の短名検索では不十分です。

修正案:

- use alias、group use、FQN、namespace 相対参照を解決して vendor の完全修飾名で判定する。
- 無名・記名・alias・FQN・別名同名クラスの正負例を揃える。

---

## 施策 9: token budget 不変条件

**判定: REQUEST_CHANGES**

[Critical] 「estimated tokens」を用いた算術は、hard invariant にはなりません。特に PDF はページごとの画像解像度や抽出テキスト量で変動するため、平均的なページ見積りでは安全上限を保証できません。

修正案:

- 公式仕様から安全側の上界を導ける場合だけ不変条件とする。
- 上界を導けない値は「容量計画の前提テスト」と明記し、provider の request bytes/pages/dimensions の hard limit を別に固定する。
- PDF のページ数だけでなく、PDF raw/request size も検査する。

[Critical] provider/model pin が default config を見ており、実際の媒体 YAML を見ていません。施策3と同じ不整合です。

修正案:

- `sop-extract-media.yaml` の provider/model/max_tokens/timeout を直接読み、見積り前提と比較する。
- text extract と media extract が排他的であり、deadline が4段分にならないことも固定する。
- `AnalysisBudget::PROMPT_NAMES` を変更しない判断は妥当ですが、媒体 prompt の代替関係をテスト名とコメントだけでなく機械的に示してください。

---

## 施策 10: UI 文言・アップロード案内

**判定: REQUEST_CHANGES**

[Warning] 変更対象コンポーネントが未特定で、Atomic Design、既存 FormField、エラー表示方式をレビューできません。

修正案:

- 実装前に具体的なページ・feature・organism・atom と既存テスト名を設計へ記載する。
- `accept` は案内にすぎないため、サーバー拒否を必ず正常に表示する。
- 必須条件未充足でも送信ボタンを disabled にせず、押下後に既存 FormField のエラー表示へ載せる。

[Warning] 画像2枚目の拒否を UI 状態だけで行わないでください。施策1のサーバー不変条件を正本とし、コンポーネントテストはそのエラー表示を確認する形にします。

Inertia Props と JsonResource の変更が不要という判断は妥当です。

---

## 施策 11: 観測・課金・rollout dependency

**判定: REQUEST_CHANGES**

[Critical] checklist は production 有効化を実際には止めません。施策1を含むコードをデプロイした時点で画像が受理されるため、「有効化前に法務・手動評価承認」という順序を保証できません。

修正案は次のいずれかです。

- 承認完了まで MIME 受理を含む PR をデプロイしない release 手順を明記する。
- 独立デプロイが必要なら OCR を既定無効にし、承認後の明示設定で有効化する。
- PR 分割は「内部型・テスト」「媒体実行経路と gate」「承認後の入口有効化」の順にする。

[Warning] 評価指標を文書化しても、現設計では structured reason が保存・記録されないため集計できません。

修正案:

- OCR route、`AnalysisFailureReason`、provider/model、媒体種別、pages/pixels/bytes、ticket release/commit を固定キーで記録する。
- SOP本文、画像内容、LLM応答本文はログへ出さない。
- 評価期間終了時に何をもって継続・撤回・上限変更するかを数値と責任者で明記する。

---

## PR 分割の修正案

現在の「1〜3を先に出す」は、施策1によって未完成機能が露出するため、そのままでは承認できません。以下の分け方が安全です。

1. `AnalysisFailureReason`、媒体DTO、validator、共有比率計算など、入口を広げない内部変更
2. `loadWithMedia`、prompt factory、vendor契約テスト、静的gate、パイプライン、budgetを同一の非公開経路として追加
3. フルパイプライン成功・失敗テスト、観測を追加
4. 法務・prompt injection評価の承認後に、MIME受理とUI案内を有効化

静的 scanner を変更する施策8は、負例・正例・fail-closed・母集団非空・docblockを同じPRに含める必要があります。

## 全体判定

**CHANGES_REQUESTED**

最大の阻害要因は `loadWithMedia()` の anonymous `TextPrompt` が実行状態と帰属 metadata を引き継がない点です。ここは課金帰属、system防御、canary、provider設定の全てに関わるため、実装前に vendor の正式な拡張契約へ合わせて再設計する必要があります。

次いで、`"(判読不能)"` が日本語比率を通過する成功条件、default config と媒体YAMLの provider/model 不一致、validator境界での実バイト上限欠落、静的gateの迂回可能性を修正すれば、OCR対応の基本方針自体は North Star と既存パイプラインに整合します。