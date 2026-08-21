# 全体判定: CHANGES_REQUESTED

Round 2の主要な修正方針は妥当です。ただし、施策1に旧説明の取り残し、施策6の具体的なテストコードに実行エラーが残っています。

## 施策1: configからフラグ削除

判定: REQUEST_CHANGES

[Warning] リスク節は訂正されていますが、直前の「テスト計画」には旧説明が残っています。

> `config()->boolean()`/`array()` 呼び出しが残っていれば PHPStan/実行時に未定義キー参照が起きる

これはRound 2で誤りと認めた説明と矛盾します。

修正案:

```text
config()->boolean() の呼び出しが残ってもPHPStanや実行時例外では検出されず、
黙ってfalse相当へ倒れる。死んだconfig設定・参照を残さないため、
施策10の残存確認で全呼び出しを検出して削除する。
```

`config()->array()` はこの旧フラグの参照経路ではないため、ここから除いてください。

## 施策2: 受理形式の単一情報源を固定値化

判定: APPROVE

4メソッドの完全一致、sniff MIME境界、`imagesEnabled()` 撤去確認まで揃っています。

## 施策3: AnalysisPipelineのroute決定を無条件化

判定: APPROVE

画像・PDFフォールバック・例外時ログのroute契約は正しく維持されています。

## 施策4: docblock更新

判定: APPROVE

実装責務と説明が一致しています。

## 施策5: Inertia / Svelte props撤去

判定: APPROVE

不要なpropと条件分岐を全階層から撤去し、常時表示へ一本化する設計は妥当です。

## 施策6: バックエンドテストの畳み込み

判定: REQUEST_CHANGES

fixtureを `$httpManual` / `$serviceManual` に分離する説明により、前回の状態干渉の指摘は解消されています。HEICの不要なcasesループ撤去も適切です。

ただし、提示された具体的なテストコードに問題があります。

[Critical] 標準的なLaravel 12の `Storage::fake()` はdisk名を必須引数として受けるため、次の呼び出しは実行時エラーになります。

```php
Storage::fake();
```

修正案:

既存テストおよびアプリがSourceDocumentに使用するdisk名と同じ値を明示してください。

```php
Storage::fake('該当する既存disk名');
```

設定値を使う既存パターンなら、その型が確実にstringになる既存の取得方法に合わせてください。

[Warning] 文字列連結にPint違反となる空白不足があります。

現状:

```php
$expected = '対応していないファイル形式です。'
    .AcceptedSourceDocumentTypes::formatsLabel()
    .'でアップロードし直してください。';
```

修正案:

```php
$expected = '対応していないファイル形式です。'
    . AcceptedSourceDocumentTypes::formatsLabel()
    . 'でアップロードし直してください。';
```

## 施策7: フロントテストの畳み込み

判定: APPROVE

旧false状態のみを削除し、常時有効側の表示・accept・DOM配置を維持しています。

## 施策8: rollout文書更新

判定: APPROVE

rollbackと修正patchが運用環境に応じた選択肢として正しく分離されました。過去の計画と実施済み記録、現行障害対応、外部Secretの管理範囲も区別されています。

## 施策9: architecture更新

判定: APPROVE

現在の常時有効状態と旧rollout gateの履歴が明確です。

## 施策10: 残存確認

判定: APPROVE

未追跡を含む `.env*` のglob検索、終了コードの区別、対象ファイル一覧の証跡、追加識別子検索、全検証コマンドが揃いました。

`rg` の終了コードは表示を見るだけでなく、PR証跡に値を記録する運用を守ってください。恒久的な専用Architecture gateを追加しない判断も妥当です。

## 承認に必要な残修正

- 施策1のテスト計画に残った「PHPStan／実行時に未定義キーを検出する」という旧説明を訂正する
- HEICテストの `Storage::fake()` に既存の正しいdisk名を渡す
- 文字列連結をPint準拠の空白へ直す

以上を反映すれば、設計全体をAPPROVEDにできます。