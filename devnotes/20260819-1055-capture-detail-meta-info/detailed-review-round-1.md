仮説どおり、値の定義と UI の方向性は妥当ですが、既存のテイク取得方式と整合しないため、現状の詳細設計ではテストが green になりません。特にクエリ数テストは必ず失敗します。

## 施策 1: カット単位の確定尺

判定: REQUEST_CHANGES

- [Warning] `DeterminedCutDuration` を「式の唯一の所在」とする不変条件が、Unit/Feature テストだけでは固定されていません。将来 `RenderJobService` に式が再実装されても、同じ結果ならテストは通ります。

  修正案: `RenderJobService` など既知の利用箇所が `EffectiveMaterialType`、`StillDisplayDuration`、`duration_ms` を組み合わせて尺を再計算していないことを Architecture テストまたは exact-fit inventory で固定してください。新しい走査を作る場合は、AGENTS.md の走査器共通規約に従い、負例・正例、未解決時の fail-closed、母集団非空、保証範囲の docblock を同じ変更へ含めます。

- [Suggestion] レンダ側の結合テストにも `cut=video / take=still` を1ケース入れると、`DeterminedCutDuration` を呼び忘れて旧式へ戻る回帰を検出しやすくなります。

尺の3分岐自体と、未確定値をレンダ側だけ既定値で埋める責務分離は妥当です。

## 施策 2: シナリオ全体の確定尺

判定: REQUEST_CHANGES

- [Warning] 「int 要素だけなので `array_sum()` は必ず int」という前提はPHPの実行時契約として成立しません。整数加算が `PHP_INT_MAX` を超えると float になり得るため、`int` のコンストラクタ引数と衝突します。PHPStanも環境や推論結果によって `int|float` を残す可能性があります。

  修正案: 1パスの明示的なループにして、`null` 件数と合計を同時に集計してください。加算前に負値と `PHP_INT_MAX - $total` を検査し、異常値はクランプせず例外にします。負値とオーバーフローのテストも追加してください。実データ上到達不能なら、その根拠となるDB制約・カット数上限を設計に明記する方法でも構いません。

`null`、`0ms`、部分和を区別するモデルは適切です。

## 施策 3: 撮影詳細 DTO

判定: REQUEST_CHANGES

- [Critical] クエリ数一定という設計は、現行 `CaptureCutData::fromCut()` と両立しません。同メソッドはカットごとに次を実行します。

  ```php
  $cut->takes()->orderBy(...)->get()
  ```

  したがって、カット1本と10本のGETクエリ数は必ず異なります。`adoptedTake` の eager load だけでは解消されません。

  修正案:

  1. `CaptureManualDetailData` のカット取得で `adoptedTake` と `takes` を一括 eager loadする。
  2. `CaptureCutData::fromCut()` は relation のコレクションを利用し、必要ならメモリ上で `sort_order`、`id` 順に並べる。
  3. 単一カット応答など他の呼び出し元では `loadMissing('takes')` を許容するか、全呼び出し元に eager loadを明示する。
  4. 変更ファイル一覧と波及調査に `CaptureCutData.php` および全 `fromCut()` 呼び出し元を追加する。
  5. テイク順序が変わらない回帰テストを追加する。

- [Warning] 「`readyTakeId()` の評価は1カットにつき1回」という記述は、提案コードでも成立しません。`appendCut()` が1回呼び、その後 `CaptureCutData::fromCut()` が再度呼ぶため、合計2回です。

  修正案: 推奨は「判定実装は1か所」という本質的な不変条件に表現を戻し、評価回数1回という要件を削除することです。どうしても1回にするなら、解決済みの採用テイクを表す型付き結果を導入し、`CaptureCutData` を含む全呼び出し元へ渡す設計が必要です。単純な nullable ID の追加だけでは、渡し忘れを防ぐという現在の `CaptureCutData` の設計理由を壊します。

- [Warning] 「採用済みだが ready でないテイク」が尺でも未確定になることを、Feature テストで固定していません。

  修正案: processing または failed の採用テイクについて、`playback_url` / ACK が null、`total_duration_ms` から除外、`undetermined_cut_count` が増える、の3点を同じテストで確認してください。

DTOでスカラー化してからInertia propsへ渡すこと、PIIを認可後に表示目的だけで読むこと、`toArray()` 内でrelationへ触れない方針は適切です。

## 施策 4: TypeScript型

判定: APPROVE

- [Suggestion] PHP側の `array_keys()` pinはPHP出力の契約しか検証せず、TS型との1:1同期自体は保証しません。ページテストのfixtureを `satisfies CaptureManualDetail`、または型付きfixture helper経由にすると、5キーの欠落を確実に型エラーにできます。

既存の完成動画尺と今回の確定部分和を別概念として維持している点は妥当です。

## 施策 5: 表示コンポーネント

判定: REQUEST_CHANGES

- [Warning] 実装案とテスト期待値が矛盾しています。現在の `$derived` では全件未確定時も次の表示になります。

  ```text
  合計時間 —（確定分・未確定 5 カット）
  ```

  テスト計画は次を期待しています。

  ```text
  合計時間 —（未確定 5 カット）
  ```

  修正案:

  ```ts
  const durationNote = $derived(
      undeterminedCutCount === 0
          ? null
          : totalDurationMs === null
            ? `未確定 ${undeterminedCutCount} カット`
            : `確定分・未確定 ${undeterminedCutCount} カット`,
  );
  ```

- [Suggestion] 日時を `<time datetime={updatedAt}>`、項目群を`dl`相当で表現すると、支援技術にもメタ情報の構造を伝えやすくなります。ただし現行の2行表示でも重大なアクセシビリティ欠陥ではありません。

DS token、Lucide、Atomic Designの依存方向、全画面時の`inert`配下への配置はいずれも適切です。

## 施策 6: テスト契約

判定: REQUEST_CHANGES

- [Critical] 提案されたクエリ数テストは、施策3で指摘したカットごとの `takes()` クエリにより必ず失敗します。先に取得方式を変更するか、テストの主張を縮小する必要があります。設計上「比例しない」と明言しているため、取得方式を修正する方を推奨します。

- [Warning] テスト名・説明は「カット数・テイク数に比例しない」としていますが、骨子はカット数しか変えていません。

  修正案: 少なくとも次の2軸を独立に比較してください。

  - カット1本と10本、各カットのテイク数は同じ
  - カット数は同じで、テイク1本と複数本

  どちらもGET 1回分のSQL数が同じであることを固定します。

- [Warning] URL/ACKの回帰と尺集計を別々に確認するだけでは、同じready判定に従っていることを十分に固定できません。

  修正案: 非ready採用テイクの1 fixtureに対して、URL、ACK、合計尺、未確定数をまとめて検証してください。

## セキュリティ・アーキテクチャ総評

新しい入力、変更系route、LLM呼び出し、外部URL取得はなく、DTO → Inertia propsの選択も適切です。作成者名は既存の認可後に読み、Svelteの通常展開でエスケープされるため、新たなPII検索やXSS経路も見当たりません。

ただし、既存のnested routeが「別projectのmanual」をbinding直後に404へ落とす契約は、今回も既存の `NestedRouteIdorDefenseTest` inventoryで保護されていることを確認対象に残してください。新規routeではないため、通常はinventory追加不要です。

## 全体判定

CHANGES_REQUESTED

必須修正は、`CaptureCutData::fromCut()` のカット単位クエリ解消、全件未確定時の表示分岐修正、`array_sum()` の型・オーバーフロー契約の明確化です。これらを直せば、North Star、DTO/Inertia、DESIGN.md、Atomic Design、認可境界に対する全体方針は承認可能です。