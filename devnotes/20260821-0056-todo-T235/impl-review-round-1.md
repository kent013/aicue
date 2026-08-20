全体として施策 1〜4 は設計どおりです。一方、施策 5 には fail-closed を崩す経路が残っているため、現状では承認できません。診断免除を導入する判断自体は妥当ですが、実測で必要になった 1 例より免除範囲が広すぎます。

## ファイル別判定

### `AGENTS.md`

[Warning] 未解決形を「名指しの免除目録」と説明していますが、実装上の鍵は `file + reason + count` です。同じファイル内で既存箇所を削除し、別の場所へ同じ理由の診断を追加すると件数が変わらず通過します。「名指し」と呼べる精度ではありません。

また、実測で必要だったのは `Input.svelte` の `spread-attribute` だけです。免除可能な診断を必要最小限に限定する義務も記載すべきです。

### `app/Http/Controllers/Projects/VideoManualController.php`

判定: 問題なし。

3 props はすべて `AcceptedSourceDocumentTypes` から供給され、既存のテナント境界 404 → 認可 403 の順序も維持されています。独立したスカラーなので DTO を新設しない判断も妥当です。

### `app/Http/Requests/Projects/StoreSourceDocumentRequest.php`

判定: 問題なし。

中央の `formatsLabel()` へ正しく結線されています。レスポンス方式や受理判定への変更もありません。

### `app/Http/Requests/Projects/StoreVideoManualRequest.php`

判定: 問題なし。

後付け経路と同じ中央ラベルを参照しており、設計どおりです。

### `app/Support/Manual/AcceptedSourceDocumentTypes.php`

判定: 問題なし。

法務確認済み文面を機械導出しない判断と、拡張子集合をテストで pin する分担は適切です。戻り値も PHPStan level 10 上明確です。

### `resources/js/components/features/manual/SourceDocumentUpload.svelte`

判定: 問題なし。

同一 feature domain 内の import で Atomic Design に適合し、fragment によって既存の flex 子構造も維持されています。

### `resources/js/components/features/manual/SourceDocumentUploadNotice.svelte`

判定: 問題なし。

状態を持たない domain 固有コンポーネントとして妥当です。新しい hex、SVG、design token 外参照もありません。

### `resources/js/pages/Manuals/Create.svelte`

判定: 問題なし。

accept、画像対応フラグ、形式ラベルのすべてを props から利用しています。accept 文字列からフラグを推測する実装もなく、案内は入力前に表示されています。ボタンの disabled 条件も変更されていません。

### `tests/Feature/Projects/SourceDocumentUploadOcrTest.php`

判定: 問題なし。

両フラグ、両画面、両アップロード経路を検証しており、テストが保証しない「メソッド呼び出しそのもの」も正確に説明されています。認可・テナント境界への変更はありません。

### `tests/Unit/Support/Manual/AcceptedSourceDocumentTypesTest.php`

判定: 問題なし。

両ラベルと拡張子の順序込み完全一致があり、施策 1 の前提 pin として十分です。

### `tests/js/architecture/file-input-accept-source-inventory.test.ts`

判定: 呼び出し側は問題なし。

`(scan, policy)` への変更は引数取り違えを防げるため、設計上の 5 positional 引数より安全です。ただし、渡している policy 自体の免除範囲に下記の問題があります。

### `tests/js/support/file-input-accept-inventory.ts`

[Critical] `UnresolvedFormExemption.reason` が `ScanDiagnosticReason` 全体を受け入れるため、次の診断まで免除できます。

- `parse-failed`
- `missing-accept`
- `unresolved-accept`
- `unresolved-native-element`
- `unresolved-type`

特に `parse-failed` を免除すると、そのファイルを一切解析できていない状態で gate を緑にできます。`missing-accept` も未解決ではなく、確定した file input に accept が無い明白な違反です。これは「未解決を落とす」という fail-closed の中核を破ります。

今回の実測が正当化するのは、少なくとも現時点では `components/atoms/Input.svelte` の `spread-attribute` だけです。免除可能理由を別の狭い union にし、`parse-failed`、`missing-accept` などは無条件違反として判定関数の先頭で処理すべきです。

[Critical] 診断免除の鍵が `file + reason` と総件数だけなので、免除対象の入れ替わりを検出できません。

例えば `Input.svelte` の現在の spread を削除し、同じファイルの別要素へ新しい spread を追加すると、`file + reason + count=1` は変わらず gate が通ります。未レビューの未解決形が既存免除へ無言で乗り換えられます。

診断にも同一 `file + reason` 内の occurrence を付け、`file + reason + occurrence` で突き合わせるなど、個々の実測を特定できる鍵が必要です。

設計逸脱については、「汎用 Input atom が正当に未解決になるため、完全禁止を名指し免除へ変える」という判断までは妥当です。しかし、現在の実装はその実測より広い例外機構を作っているため、逸脱全体は正当化できません。

### `tests/js/support/file-input-scan.ts`

[Critical] 属性名の照合が大小文字を区別しています。

```ts
attr.name === name
```

native HTML の属性名は ASCII 大文字小文字を区別しないため、例えば次はブラウザ上 file input になりますが、走査器は `type` 属性なしとして母集団から外す可能性があります。

```svelte
<input TYPE="file" ACCEPT="image/*" />
```

要素名と `type` の値は小文字化している一方、属性名だけ小文字化していません。`attributesNamed()` は native element に対して `attr.name.toLowerCase() === name` で比較し、この形の負例を追加する必要があります。

[Warning] `RawHtmlRecord` の docblock に「診断は免除の概念を持たず」とありますが、現在の実装は診断免除を持っています。保証範囲の正本とされているファイル内の自己矛盾なので訂正が必要です。

### `tests/js/architecture/file-input-scan.test.ts`

[Critical] 上記の fail-open を捕捉する負例がありません。

最低限、次のテストが必要です。

- `TYPE="file"` が file input として検出される
- `parse-failed` を免除目録へ登録しても違反のまま
- `missing-accept` を免除目録へ登録しても違反のまま
- 同一ファイルで免除済み診断を別箇所の同理由診断に置き換えると違反
- `type="fi{x}le"` が `unresolved-type`
- 重複した `type` / `accept` 属性の追加分岐が診断になる

現在の「同じ診断を 2 件へ増やす」テストは件数増加しか検証しておらず、件数を維持した置換を検出できません。

### `tests/js/components/features/manual/SourceDocumentUpload.test.ts`

判定: 問題なし。

共有化後の表示順と form 直下の親子構造を固定できています。

### `tests/js/components/features/manual/SourceDocumentUploadNotice.test.ts`

判定: 問題なし。

両フラグと法務文面の全文一致を検証しており、正例・非表示例の両方向があります。

### `tests/js/pages/ManualsCreate.test.ts`

判定: 問題なし。

props 由来の accept、案内の出し分け、help 全文、配置を検証しています。既存の submit 非 disabled 条件も維持されています。

### `tests/js/support/normalizeText.ts`

判定: 問題なし。

正規化範囲と保証しない点が明記され、テスト用 helper として適切です。

## 総評

施策 1〜4、PHPStan/TypeScript の型、Inertia パターン、セキュリティ境界、DESIGN.md、Atomic Design には問題を認めません。

施策 5 の方向性も妥当ですが、現在は以下のため「未解決形を無言で候補から外さない」という保証を満たしません。

- 任意の診断理由を免除できる
- parse 失敗まで免除できる
- 免除対象が同一ファイル内で別箇所へ入れ替わっても検出できない
- 大文字の native HTML 属性名で file input を母集団から落とせる

テストファーストの記録は提示内容と差分に整合していますが、実行順そのものは diff から独立には検証できません。

CHANGES_REQUESTED