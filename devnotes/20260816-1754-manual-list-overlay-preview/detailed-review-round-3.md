## 施策 1: APPROVE

Canonicalへの責務移管、`relationLoaded()`の事前条件、共通`receivable()`、DTOに残すpublished・ability判定の境界は整合しています。T154違反とN+1の再発経路はいずれも設計上閉じられています。

[Suggestion] Unitテストでは未ロード時の例外型を明記してください。`Webmozart\Assert\Assert::true()`なら通常は`InvalidArgumentException`を期待する形に固定すると、単に「何か落ちた」テストになりません。

また、実装時には`CurrentRenderArtifact.php`への`use Webmozart\Assert\Assert;`追加が必要です。

## 施策 2: REQUEST_CHANGES

[Warning] `preload="none"`を「開いただけではplayback routeを要求しない」という保証として扱っている点は正確ではありません。

HTMLの`preload`はブラウザへのヒントであり、`none`でもブラウザが完全にネットワーク要求を行わないことまでは保証されません。Vitestで属性を検査しても、実際のHTTP要求がゼロであることは証明できません。

修正案:

- 契約を「ブラウザに事前取得しないよう指示する」「意図しない先読みを抑制する」に弱める。
- Vitestの説明も「`preload="none"`指定の固定」とし、「playback要求が発生しないことの固定」とは書かない。
- HTTP要求ゼロをセキュリティ不変条件として必要とするなら、`src`自体を再生操作まで設定しない別設計が必要。ただし今回はその強い保証は不要と考えます。

`preload="none"`を採用する実装判断自体は妥当です。修正対象は保証範囲の表現です。

## 施策 3: APPROVE

プレビューとDLが同一props・同一分岐にあり、UI側で権限や状態を再判定していません。disabled禁止、Lucide、DS token、Atomic Designにも適合しています。

## 施策 4: APPROVE

モーダルの単一配置、選択行の差し替え、endpointを最終認可境界とする構成は妥当です。閉状態でvideoがDOMから除去されるテストも維持されています。

## 施策 5: REQUEST_CHANGES

[Warning] 新設するUnitテストが施策一覧と変更箇所に含まれていません。

本文では`tests/Unit/Manual/CurrentRenderArtifactLoadedCandidateTest.php`を新設しますが、施策一覧の施策5と「変更箇所」には記載がありません。波及変更の正本として不完全です。

修正案:

- 施策一覧の施策5へ同ファイルを追加する。
- 施策5の「変更箇所」にも追加する。
- 個別検証へ次を追加する。

```text
composer test -- --filter=CurrentRenderArtifactLoadedCandidate
```

[Suggestion] 「追加クエリ0本」は観測区間を明確にしてください。fixture生成と`load('latestSucceededRender')`を終えた後にカウンタをリセットし、`fromLoadedRenderCandidate()`呼び出しだけを測定する必要があります。既存のquery-count helperがあればそれを優先してください。

Architectureテストの既存`tokensOf()`利用とテスト名の修正は適切です。

## 施策 6: APPROVE

props置換、null ID防御、callback、disabled禁止、ページ統合まで必要な契約をカバーしています。施策2の修正に合わせ、`preload`テストの説明だけ「HTTP要求ゼロの保証」から「属性指定の固定」へ変更してください。

## 文書全体

[Warning] 実装モードの「新規2本」は、Unitテスト追加後の実数と一致しません。

少なくとも次の3ファイルが新規です。

- `ManualPreviewModal.svelte`
- `ManualPreviewModal.test.ts`
- `CurrentRenderArtifactLoadedCandidateTest.php`

修正案: 「新規3本（component 1本、Vitest 1本、Unitテスト1本）」へ更新してください。

## 全体判定: CHANGES_REQUESTED

実装方針とドメイン境界について新たな重大問題はありません。残件は、`preload="none"`をHTTP要求ゼロの保証として扱わないことと、新設Unitテストを変更一覧・検証一覧・ファイル数へ正しく反映することです。