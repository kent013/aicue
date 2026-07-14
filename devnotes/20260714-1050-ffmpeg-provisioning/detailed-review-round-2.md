## 施策別判定

### 施策1: APPROVE

残指摘なし。

### 施策2: APPROVE

`fontconfig` 明示導入と family 判定への変更で、Round 1 指摘は解消されています。

### 施策3: REQUEST_CHANGES

- [Warning] `/apt-get install -y[\s\S]*\bffmpeg\b/` はファイル末尾まで貪欲に探索するため、`apt-get install` と無関係なコメント・別命令中の `ffmpeg` でも合格します。フォント側も同様です。

  修正案: パッケージの独立行を検証する元の方式へ戻すか、各 `RUN apt-get install ...` 命令の範囲を抽出してからパッケージを検証してください。今回のDockerfile形式が固定なら、以下の独立行判定が最も単純です。

```php
expect(dockerfileContents())->toMatch('/^[ \t]*ffmpeg[ \t]*\\\\[ \t]*$/m');
expect(dockerfileContents())->toMatch('/^[ \t]*fonts-noto-cjk[ \t]*\\\\?[ \t]*$/m');
```

Dockerfile整形変更時にテスト更新が必要になることは、静的ガードとして許容可能です。

### 施策4: APPROVE

例外安全性、一意ディレクトリの限定削除、`mkdir()` 検証、フォント設定の明示により、Round 1 指摘は解消されています。

## 全体判定

**CHANGES_REQUESTED**

施策3の正規表現による誤検知可能性のみ残っています。これを修正すれば **APPROVED** です。