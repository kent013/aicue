全体判定: **CHANGES_REQUESTED**

Round 1 の Critical は解消されています。ただし、並行実行制御と成果物保持に実装上の競合が残っています。

### 1. 使命との整合性

[Suggestion] 完成動画へのアクセス境界、字幕焼き込み、欠落テイクの fail-fast、プレビュー導線は North Star と整合しています。「編集ゼロ」の最終工程として妥当です。

### 2. 禁止事項違反

[Warning] `NestedRouteIdorDefenseTest` の対象数が「4 route」のままですが、現在は route が5本です。playback追加時の更新漏れに見え、不変条件の登録漏れにつながります。

修正提案: inventory 対象を route 名単位で明記し、少なくとも polling、playbackを含む全 nested route を登録してください。単なる件数表記ではなく route 名一覧を設計に残す方が安全です。

### 3. 実現可能性

[Critical] org単位preview上限の「件数を数えて409」だけでは、異なるmanualへの同時リクエストを直列化できません。両方が上限未満を観測し、同時にjobを作成できます。VideoManual行ロックはmanual間の競合を防ぎません。

修正提案: preview triggerでOrganization行を`lockForUpdate()`してからin-flight数を検査・作成してください。併せてロック順を設計へ明記し、異なるmanualへの並行triggerでも上限3を超えないFeatureテストを追加してください。

### 4. 期待効果の妥当性

[Suggestion] 無料previewの負荷制御、queued短期回復、権限別成果物routeにより、Round 1時点より効果の根拠が明確になっています。

### 5. リスク

[Warning] 「最新succeeded 1世代のみ保持」とplayback routeが整合していません。旧preview jobは`succeeded + output_pathあり`のままですが、実体は削除されます。そのjobのplaybackは302を返した後、S3で失敗します。

修正提案: playbackは「同manual・previewの最新succeeded job」であることも条件にしてください。旧jobの`output_path`をNULL化する場合は、削除jobの失敗や再実行との整合も状態機械として定義してください。

[Warning] `DeleteRenderOutputsJob`へ任意のS3キー配列を渡す設計は削除範囲が広すぎます。

修正提案: job payloadはrender job IDなどの内部IDに限定し、実行時にrelation経由でキーを再解決したうえで、期待するmanual配下のprefixを検証してください。

### 6. スコープの適切さ

[Suggestion] published動画のインライン再生とキャンセルを後続へ送った判断は適切です。v1の成果物アクセスがdownloadに一本化され、権限モデルも単純になっています。

### 7. 型安全性

[Warning] previewのversion不一致をフロントで「この種別」と判定するとしていますが、永続化されるのは自由文の`error`だけです。文字列比較は型安全でなく、文言変更でCTAが壊れます。

修正提案: nullableなbacked enum `error_code`を持たせるか、失敗DTOに型付きコードを追加してください。例: `scenario_version_changed`。表示文言はコードからフロント側で決定します。

また、テスト欄の「stale回復（queued/running 30分）」は設計本文と不一致です。`queued=10分 / running=30分`へ修正してください。