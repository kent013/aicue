全体判定: **CHANGES_REQUESTED**

実装本体は概ね詳細設計に一致し、施策1〜3は妥当です。一方、施策4の必須成果と回帰テストが設計上の成功条件を満たしておらず、施策5をスキップできる根拠がまだ固定されていません。Critical はありませんが、マージ前に修正すべき Warning があります。

### `app/DataTransferObjects/Manual/SourceDocumentSummaryData.php`

判定: 問題なし

- `final readonly`、shape 付き `toArray()`、`CarbonInterface` への正確な絞り込みはPHPStan level 10方針に合致。
- PIIを含み得る名前をDTO生成前にrelation経由で限定する設計とも整合。
- 表示整形をフロントへ分離しており責務も適切。

### `app/Http/Controllers/Capture/CaptureTakeController.php`

判定: 問題なし

- `AdoptCaptureTakeRequest`への差し替え後も、`FormRequest`は`Request`のサブタイプなので既存処理を壊さない。
- controller内の`Gate::authorize('adopt', ...)`も維持されている。
- 正常系がgreenとの報告もあり、退行は認められない。

### `app/Http/Requests/Capture/AdoptCaptureTakeRequest.php`

判定: 問題なし

- bodyを使わない操作に、保護キーの`missing`ルールだけを追加する最小構成。
- `authorize(): true`として、404境界→422検証→403認可という既存順序を維持している。
- 型のwidenやignoreもない。

### `app/Models/VideoManual.php`

判定: 問題なし

- `ofMany(['created_at' => 'max', 'id' => 'max'])`は、設計した最新決定規則と一致。
- manual起点のrelationなので、別manualや別組織の文書が混ざる実装経路を作っていない。
- genericsも適切。

### `app/Http/Controllers/Projects/VideoManualController.php`

判定: 問題なし

- `hasDocument`と`document`を同じ`$sourceDocument`から導出しており、同一スナップショット要件を満たす。
- DTO→Inertia propsの既存パターンを守り、`response()->json()`の直書きもない。
- relation解決が既存のmanual境界内に置かれている前提で、PII境界も妥当。

### `resources/js/types/manual.ts`

判定: 問題なし

- PHP DTOとのフィールド対応が一致。
- `document`を必須かつnullableにした契約も適切。

### `resources/js/pages/Manuals/Create.svelte`

判定: 問題なし

- 表示用stateだけを追加しており、送信データ経路を変更していない。
- 未選択・再選択・解除を正しく処理している。
- DESIGN tokenのみを使用し、hexや新規コンポーネントの過剰追加もない。

### `resources/js/pages/Manuals/Show.svelte`

判定: 問題なし

- Svelte通常補間なのでfilenameはHTMLとして解釈されない。
- サイズ・日時整形を既存helperへ委譲している。
- Lucideの`FileText`、既存DS token、pages層の責務に収まっている。
- `SourceDocumentUpload`のprops契約を変更していない点も設計どおり。

### `tests/Architecture/CurrentRenderArtifactInventoryTest.php`

判定: 問題なし

- 新しいone-of-many relationを、成果物選択relationとは別概念として名前までpinしている。
- `succeeded`条件の件数を維持しているため、成果物選択式の増加を見逃す変更にはなっていない。

### `tests/Feature/Capture/CaptureTakeManagementTest.php`

判定: 問題なし

- 全保護キー、正常なネスト、cross-cut、cross-org、同一組織内の認可前422、副作用なしを網羅。
- 404がFormRequestより先に成立する境界も固定できている。
- 既存のclean-body正常系がgreenであるため、FormRequest差し替えの退行も担保されている。

### `tests/Feature/Manual/SourceDocumentSummaryPropsTest.php`

[Warning] 別組織PIIの「現在表示中manualへの非混入」がテストされていません。

現在のテストは、別組織project配下に対象manual IDを差し込んだ場合の404だけです。詳細設計では、次の二つを別の境界として要求しています。

- 現在閲覧できるmanualのpropsへ、別組織SOPのsentinel filenameが混ざらないこと
- 別組織manualを直接showした場合に404になること

後者しか固定されていません。閲覧可能なmanualを別組織側にも用意し、他組織SOPのsentinelがそのレスポンスに出ないケースを追加してください。

[Suggestion] `uploadedAt`は存在確認だけでなく、既知の`created_at`に対するISO 8601値を比較するとDTO契約をより直接固定できます。

### `tests/js/pages/CaptureShow.test.ts`

[Warning] Phase Aの回帰テストが、詳細設計で要求された「共通before-event emitter」を実装していません。

現在は`reload`と`visit/get/post`を独立したmockとして数えています。そのため、

- 母集団非空なのは`reload`だけで、`programmaticCalls`は0件のままgreenになれる
- `<Link>`やform helperなど、別のvisit入口が共通観測点を通ることを保証していない
- 負のコントロールが判定用純関数を直接呼ぶだけで、実際のmock→event→判定配線を検証していない

という空振りの余地があります。設計どおり、`reload/visit/get/post`と必要な`<Link>`クリックを共通のbefore-event emitterへ通し、禁止destinationもそのemitterへ投入して検出してください。

これはPhase Aの結論を支える中心テストなので、単なるテスト改善ではなく施策4の未完了です。

### `tests/js/pages/ManualsCreate.test.ts`

判定: 問題なし

- 初期非表示、選択、再選択、解除を網羅している。
- 実装のstate遷移を十分に固定できている。

### `tests/js/pages/ManualsShow.test.ts`

[Suggestion] 「サイズ・日時が出る」というテスト名に対して、実際にはサイズしかassertしていません。

`formatDateTime()`の既知入力に対応する表示値もassertし、日時表示の削除や配線漏れを検出できるようにしてください。filenameのHTML非解釈テストは妥当です。

### Phase A実装記録（diffに存在しない必須成果）

[Warning] 提示されたdiffには、Phase Aで要求されたネットワーク観測記録がありません。

コメントの「devnotes実装メモに記録」だけでは、以下を確認できません。

- documentとXHR/fetchの分類
- 最終responseの`X-Inertia`と`X-Inertia-Location`実値
- 409 reload、`Inertia::location()`、ハーネス操作の分類
- 分岐(a)/(b)/(c)のどれに該当したか
- 非再現からハーネス原因を断定していないこと

この記録と、空振りしない回帰テストが揃って初めて「アプリ起因経路を再現できないため施策5をスキップ」が設計に合致します。現状ではスキップ判断を承認できません。

なお、コマンド実行禁止に従い、提示されたdiffと報告済みテスト結果のみをレビューしています。

**最終判定: CHANGES_REQUESTED**