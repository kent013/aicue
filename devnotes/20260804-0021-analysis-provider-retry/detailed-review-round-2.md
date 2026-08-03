## 各施策判定

- 施策1: **APPROVE**
- 施策2: **APPROVE**
- 施策3: **REQUEST_CHANGES**
- 施策4: **APPROVE**
- 施策5: **REQUEST_CHANGES**
- 施策6: **REQUEST_CHANGES**
- 施策7: **REQUEST_CHANGES**

## 指摘

- [Critical] `AnalysisBudget` が timeout の実値をYAMLから導出するため、**360秒を固定するテストが消えています**。3 YAMLを300秒、deadlineを900秒へ同時変更しても全テストが通ります。  
  修正案: `AnalysisBudget::CLIENT_TIMEOUT_SECONDS = 360` を仕様値として定義し、3 YAMLすべてがこの値と一致することを検証してください。Architectureテストでの期待値複製は、driftを検出するための意図的な重複です。

- [Critical] `ThrowingPromptFake` の `RuntimeException` がimportされていません。`Tests\Support\RuntimeException` と解決され、script枯渇時にクラス未検出になります。  
  修正案: `use RuntimeException;` を追加するか、`\RuntimeException` としてください。

- [Warning] deadlineの定義と実装位置が一致していません。設計ではT0を「`run()`入口」としていますが、実装は`findOrFail()`後にdeadlineを生成しています。さらにPの説明では`findOrFail()`を「alarm→run入口」に含めていますが、実際には`run()`内部です。  
  修正案: deadline生成を`run()`の先頭へ移すか、T0を「`findOrFail()`完了直後」と再定義し、施策2・3・7の記述を統一してください。

- [Warning] `Assert::isArray($yaml['client_options'])`による配列offset式のnarrowingはPHPStanで保持されない可能性があります。  
  修正案: `$clientOptions = $yaml['client_options']; Assert::isArray($clientOptions);` とローカル変数へ移してからアクセスしてください。

- [Warning] `ThrowingPromptFake`の時計操作が「`travel()`が使えなければ別方式」と未確定です。詳細設計として実装方式を確定する必要があります。  
  修正案: fakeへ`Closure $onAttempt`を注入し、テスト側の`$this->travel()`を呼ばせる方式が最も型安全で、Carbonのグローバル状態管理もSupportクラスへ漏れません。

- [Suggestion] `userMessageFor()`ではHTTP statusを先に一度だけ取得すると、`extractHttpStatus()`の二重呼び出しを避けられます。
- [Suggestion] `providerBusy()`のコメントを「429 / 529 / 500・502・503・504」へ更新してください。

## 観点別評価

- `Throwable` catch、retry回数、deadline先頭guard、deny-by-defaultは妥当です。
- `extractHttpStatus()`共有により、retry判定と文言分岐の整合性は改善されています。
- reserve→commit/releaseのテスト計画は十分で、二重課金・課金済みfailed・無課金succeededを固定できます。
- DTO/JsonResource、Inertia、DESIGN.md、Atomic Designへの追加波及はありません。

## 全体判定

**CHANGES_REQUESTED**

上記Critical 2件とT0定義を修正すれば、承認可能な設計です。