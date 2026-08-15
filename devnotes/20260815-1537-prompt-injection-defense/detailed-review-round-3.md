## 全体判定: CHANGES_REQUESTED

Round 2 の指摘はすべて適切に反映されています。窓口経路、5根走査、fail-closed、帰属、再試行・課金制御の設計は承認可能な水準です。

残る問題は、パイプラインテストで拒否例外をどう発生させるかが現行の前段検査と矛盾している点です。

### A. 防御設定の集約: APPROVE

上界と断定しない記述への修正は妥当です。

[Suggestion] `llm_call_logs`のtoken数だけでは入力のバイト数が上限から離れていることを直接確認できません。追認時は窓口で入力バイト数を内容なしで観測するか、pipeline-smokeのfixtureについて実バイト数も別途測定すると明確です。

### B. 入力の無害化: APPROVE

分類、UTF-8拒否、除去数、例外理由の設計に問題はありません。

[Suggestion] 提示コードの`use Webmozart\Assert\Assert;`は未使用になったため削除対象です。

### C. 応答カナリア: APPROVE

ASCII hexをバイト列として検査し、正規化失敗を安全側に倒す修正でfail-openは解消されています。検知限界の記述も適切です。

### D. 窓口と実行単位: APPROVE

通常経路と名指し免除経路の分離、metadata必須化、vendor型の閉じ込めはいずれも整合しています。

### E. factory・YAMLの窓口化: APPROVE

旧経路を残さず、4 factoryを同時移行する設計で問題ありません。

### F. パイプラインの失敗写像: REQUEST_CHANGES

[Warning] `TooLarge`と`InvalidEncoding`のパイプラインテストについて、例外を実際に発生させる方法が示されていません。

通常のSOP経路では次の順になります。

- `analysis_max_text_bytes = 150,000`が先に検査される
- 窓口上限は`200,000`
- `SopTextExtractor`は有効なUTF-8を保証する

したがって通常fixtureでは、窓口の`TooLarge`をLLM呼び出し0回で発生させる前に既存の入力上限で落ち、`InvalidEncoding`も窓口へ到達しません。設計書自身の「到達しない最後の砦」と、Featureテストの期待が衝突しています。

修正案: テスト上の発生方法を明記してください。例えば以下です。

- `TooLarge`: テスト内だけ`llm-defense.max_untrusted_bytes`をmanual上限より小さくoverrideし、manual検査を通る入力を窓口で拒否させる
- `InvalidEncoding`: extractorのtest doubleから不正バイトを含む`ExtractedText`を返せることを確認し、無理ならパイプラインを直接試さず、失敗写像を専用クラスへ抽出してUnitテストする
- チケットreleaseまで確認する場合は、`AnalysisPipeline`へ専用例外を注入できる既存のfake境界を使う。新しいproduction用脱出口は作らない

テストのために`ExtractedText`の不変条件を緩めることは避けてください。

### G. 窓口通過の構造検査 gate: APPROVE

5根と`app/`の責務別分離、`PromptCanary`の正当な参照先、template/YAML/nameの三者一致まで含めて、Round 2 の問題は解消されています。

### H. 集約設定の gate: APPROVE

設定集合、読み手、型、env禁止、manual上限との関係が過不足なく固定されています。

### I. 既存 gate 4本の更新: APPROVE

既存scannerの維持、YAML構文契約、reflection依存の集約はいずれも妥当です。

### J. 実行時テストと攻撃コーパス: REQUEST_CHANGES

[Warning] 項目12のうち`TooLarge`と`InvalidEncoding`は、Fと同じ理由で発生fixtureが未定義です。

修正案: 項目12に、各拒否をどの境界から注入するかを明記してください。また、設定overrideを使う場合はテスト終了後にLaravelのconfig状態が他テストへ漏れない既存のテスト分離に乗ることを確認してください。

それ以外の攻撃コーパス、カナリア検査、5根の合成負例は網羅的です。

### K. 規約文書の更新: REQUEST_CHANGES

[Warning] `docs/template-divergence.md`の不変条件4にある「未知の予約変数名を拒否する」は、提示された実装より強い保証です。実装が明示的に拒否するのは`llm_canary`だけです。

修正案はどちらかです。

- `llm_`など予約namespaceを定義し、窓口で未知の予約名を拒否する
- 文書を「窓口は合言葉変数`llm_canary`の上書きを拒否する」に限定する

現時点で予約namespaceが不要なら、後者が思考原則2に合います。

F/Jの拒否fixtureを具体化し、Kの保証範囲を実装に合わせれば、設計全体として承認可能です。