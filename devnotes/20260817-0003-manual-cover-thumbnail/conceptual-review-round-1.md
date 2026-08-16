全体判定: **CHANGES_REQUESTED**

致命的な方向違いはありません。North Star との整合性は高く、PWA の「読まずに選べる」入口改善として妥当です。ただし、概念設計としては **代表選択ロジックの置き場所** と **props と thumbnail endpoint の認可契約** がまだ弱く、このまま実装に入ると不変条件テストか将来変更で割れやすいです。

## 1. 使命との整合性

[Suggestion]  
撮影 PWA のシナリオ選択でサムネイルを出す改善は、現場作業者が小さい画面・手袋・屋外などの条件で目的のマニュアルを素早く選ぶ助けになるため、North Star に本質的に貢献します。

[Suggestion]  
PC 一覧には広げない判断も妥当です。要件にない表示面へ広げず、撮影導線に絞っている点はスコープ管理として良いです。

## 2. 禁止事項違反

[Warning]  
`props に代表が入っている ⇔ その URL を叩けば 302` という契約を置くなら、`Gate::allows('capture', $project)` だけで閉じる設計は少し脆いです。現時点では `TakePolicy::preview` が `ProjectPolicy::capture` に一致している前提でも、将来 `preview` 側に条件が増えると props は cover を出すのに endpoint は 403 になります。

修正提案:  
代表 cover の公開可否は endpoint と同じ認可意味に寄せるべきです。最低限、`TakePolicy::preview` と props 側の条件が同値であることを Feature/Architecture テストで pin してください。可能なら「thumbnail を props に出してよいか」を小さな policy/helper に寄せ、endpoint と DTO が同じ判断を参照する形が安全です。

## 3. 実現可能性

[Warning]  
`VideoManual` に `HasOne` + `ofMany` で代表カットを足す方針は実現可能に見えますが、「表示順で最初の、採用テイクに thumbnail があるカット」を SQL relation として正しく表す難度があります。特に `sort_order ASC, id ASC` のタイブレーク、`adoptedTake.thumbnail_path is not null`、既存の `AdoptedReadyTakeCoverage` 委譲を同時に満たす必要があります。

修正提案:  
設計段階で relation の責務を明確に分けてください。例えば:

- relation は「thumbnail_path を持つ adoptedTake がある候補 cut を表示順で 1 件取る」だけにする
- ready 判定の正本は DTO/Service 側で `AdoptedReadyTakeCoverage::readyTakeId()` に必ず委譲する
- relation が `adoptedTake` と `TakeStatus::Ready` を同居させないことを明文化する
- `sort_order` 同値時の `id` 昇順をテストで固定する

## 4. 期待効果の妥当性

[Suggestion]  
「読まずに目的のマニュアルを選びやすくなる」という期待効果は合理的です。特に撮影 PWA は作業現場で使うため、文字情報だけより代表画像がある方が識別性は上がります。

[Warning]  
「撮影が進むと代表が付く = 進捗が視覚的にも分かる」は効果としては妥当ですが、過去分・生成失敗・未生成ではプレースホルダのままなので、進捗表現として過度に期待させない方がよいです。

修正提案:  
期待効果の主張は「マニュアル識別性の向上」を主にし、「進捗の補助」は副次効果として扱うのが適切です。

## 5. リスク

[Warning]  
一覧に `<img>` を追加すると、権限不一致時だけでなく、サムネイル生成遅延・署名 URL 期限・S3 側エラーでも壊れた画像表示が起き得ます。プレースホルダ方針は良いですが、画像ロード失敗時の UI 挙動が設計にありません。

修正提案:  
新 component は `on:error` 相当でプレースホルダへ戻す仕様を入れてください。`cover !== null` だけで初期表示を決めつつ、ネットワーク/署名/S3 失敗時は同寸法のプレースホルダにフォールバックするのが実運用向きです。

[Warning]  
一覧がページネーションなしで、lazy loading だけを上限装置にする設計は現状追随としてはよいですが、カード数が多い組織では初期 DOM と IntersectionObserver 周辺の負荷が増えます。

修正提案:  
今回ページネーションをスコープ外にする判断は維持してよいです。ただし Feature/Vitest では「画像 URL を props で大量に配らない」「cover は id だけ」という点を固定し、転送面の増加を抑える契約にしてください。

## 6. スコープの適切さ

[Suggestion]  
新規 route、手動表紙選択、PC 一覧、バックフィル、自動再生を外している点は適切です。今回の目的は doc/05 §5.2 の欠落要素を埋めることであり、代表画像管理機能まで広げる必要はありません。

[Warning]  
テスト計画の「cross-org 404」は重要ですが、今回の主変更が props 生成中心であるため、何を 404 として固定するのかが少し曖昧です。

修正提案:  
少なくとも以下を分けてください。

- Capture index 自体の nested/project 境界
- 代表 cover に含めた `cut_id/take_id` の endpoint が別 org から 404/403 で漏れないこと
- props に他 org の take id が混入しないこと

## 7. 型安全性

[Warning]  
`CaptureManualSummaryData` に `cover` を足す方針は DTO パターンに沿っていますが、`{cut_id, take_id} or null` の shape を PHPStan level 10 で安定させるには専用 DTO にした方が安全です。配列 shape のままだと TS 型、Svelte props、PHP 側 nullable shape の同期が崩れやすいです。

修正提案:  
`CaptureManualCoverData` のような小 DTO を切り、`CaptureManualSummaryData::$cover` を `?CaptureManualCoverData` にする案を推奨します。Inertia props へ出す時点で shape 化すれば、PHPStan と TS の双方で契約が読みやすくなります。

結論として、改善の方向性は承認可能に近いですが、現案は **認可の同値性** と **代表選択 relation の責務分離** を明確にする修正が必要です。