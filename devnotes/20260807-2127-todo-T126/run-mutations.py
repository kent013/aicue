#!/usr/bin/env python3
"""T126 の新設 gate が「本当に効いているか」を mutation で確認する一時スクリプト。

各 mutation について:
  1. 対象ファイルを退避 (.mutbak)
  2. mutation を適用
  3. 対象テストを実行し、**赤になること**を確認
  4. 退避から復元

★実行は worktree のルートから。恒久化しない (AGENTS.md: 一時スクリプトは devnotes へ)。
★テストは `vendor/bin/pest <file>` を直接叩く (グローバルテストロックは worktree 横断の
  直列化用で、ここは非 parallel かつ worktree 固有 base DB のみを使う)。
"""

from __future__ import annotations

import json
import shutil
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


def apply_replace(path: str, old: str, new: str) -> None:
    p = ROOT / path
    s = p.read_text()
    if old not in s:
        raise SystemExit(f"mutation target not found in {path}: {old[:80]!r}")
    p.write_text(s.replace(old, new, 1))


def run_tests(files: list[str]) -> tuple[bool, str]:
    proc = subprocess.run(
        ["vendor/bin/pest", *files],
        cwd=ROOT,
        capture_output=True,
        text=True,
        timeout=900,
    )
    out = (proc.stdout or "") + (proc.stderr or "")
    failed_names: list[str] = []
    for line in out.splitlines():
        line = line.strip()
        if not line.startswith("{"):
            continue
        try:
            payload = json.loads(line)
        except json.JSONDecodeError:
            continue
        if payload.get("tool") != "pest":
            continue
        for bucket in ("failures", "error_details"):
            for entry in payload.get(bucket, []) or []:
                name = entry.get("test", "")
                failed_names.append(name.split("__pest_evaluable_")[-1])
        return payload.get("result") != "passed", "; ".join(sorted(set(failed_names))[:6])
    return proc.returncode != 0, out[-300:]


MUTATIONS: list[dict] = [
    {
        "id": 1,
        "desc": "ExternalClientTimeouts::STRIPE_TIMEOUT_SECONDS を 80 (SDK 既定) にする",
        "expect": "pin 値は SDK 既定値と異なる",
        "edits": [("app/Support/ExternalClientTimeouts.php", "STRIPE_TIMEOUT_SECONDS = 20;", "STRIPE_TIMEOUT_SECONDS = 80;")],
        "tests": ["tests/Architecture/ExternalClientTimeoutInventoryTest.php"],
    },
    {
        "id": 2,
        "desc": "ExternalClientTimeouts::AWS_MAX_ATTEMPTS を 3 (SDK 既定) にする",
        "expect": "pin 値は SDK 既定値と異なる",
        "edits": [("app/Support/ExternalClientTimeouts.php", "AWS_MAX_ATTEMPTS = 2;", "AWS_MAX_ATTEMPTS = 3;")],
        "tests": ["tests/Architecture/ExternalClientTimeoutInventoryTest.php"],
    },
    {
        "id": 3,
        "desc": "config/filesystems.php の awsS3ClientOptions() 展開を削除",
        "expect": "AWS config: s3 / ses が http と retries を宣言する + behavioral",
        "edits": [("config/filesystems.php", "            ...ExternalClientTimeouts::awsS3ClientOptions(),\n", "")],
        "tests": ["tests/Architecture/ExternalClientTimeoutInventoryTest.php"],
    },
    {
        "id": 4,
        "desc": "config/services.php の awsControlClientOptions() 展開を削除",
        "expect": "AWS config: s3 / ses が http と retries を宣言する + behavioral (ses)",
        "edits": [("config/services.php", "        ...ExternalClientTimeouts::awsControlClientOptions(),\n", "")],
        "tests": ["tests/Architecture/ExternalClientTimeoutInventoryTest.php"],
    },
    {
        "id": 5,
        "desc": "ExternalClientTimeoutServiceProvider::boot() の中身を空にする",
        "expect": "Stripe HTTP client の timeout … が pin 値になる",
        "edits": [
            (
                "app/Providers/ExternalClientTimeoutServiceProvider.php",
                "        ApiRequestor::setHttpClient($client);\n        Stripe::setMaxNetworkRetries(ExternalClientTimeouts::STRIPE_MAX_NETWORK_RETRIES);\n",
                "",
            )
        ],
        "tests": ["tests/Feature/Providers/ExternalClientTimeoutServiceProviderTest.php"],
    },
    {
        "id": 6,
        "desc": "bootstrap/providers.php から provider 行を削除",
        "expect": "provider が bootstrap/providers.php に登録されている",
        "edits": [("bootstrap/providers.php", "    ExternalClientTimeoutServiceProvider::class,\n", "")],
        "tests": ["tests/Feature/Providers/ExternalClientTimeoutServiceProviderTest.php"],
    },
    {
        "id": 7,
        "desc": "TakeObjectStorage::headObject() の per-command 上書きを削除",
        "expect": "headObject は制御系の @http / @retries を per-command で積む + 負のコントロール",
        "edits": [
            (
                "app/Services/Capture/TakeObjectStorage.php",
                "                ...ExternalClientTimeouts::awsControlPlaneCommandOptions(),\n",
                "",
            )
        ],
        "tests": ["tests/Feature/Capture/TakeObjectStorageTest.php"],
    },
    {
        "id": 8,
        "desc": "Service に Storage::disk('s3')->exists() を 1 行足す (未登録クラスの到達)",
        "expect": "到達境界: AWS / Flysystem へ到達するクラスは目録と対称差ゼロ",
        "edits": [
            (
                "app/Services/Capture/StorageUsageService.php",
                "class StorageUsageService\n{\n",
                "class StorageUsageService\n{\n    public function mutationProbe(string $path): bool { return \\Illuminate\\Support\\Facades\\Storage::disk('s3')->exists($path); }\n",
            )
        ],
        "tests": ["tests/Architecture/ExternalClientTimeoutInventoryTest.php"],
    },
    {
        "id": 9,
        "desc": "TakeObjectStorage に未登録の public メソッドを足す",
        "expect": "面分類: adapter の public メソッドは目録と対称差ゼロ",
        "edits": [
            (
                "app/Services/Capture/TakeObjectStorage.php",
                "    /** stale 掃除用の存在確認 */",
                "    /** mutation probe */\n    public function listObjects(): array { return []; }\n\n    /** stale 掃除用の存在確認 */",
            )
        ],
        "tests": ["tests/Architecture/ExternalClientTimeoutInventoryTest.php"],
    },
    {
        "id": 10,
        "desc": "CashierAutoRechargeGateway に Stripe 呼び出しを 3 つ増やす",
        "expect": "既定接続の Stripe 呼び出しは予算を超えない (厳密回数)",
        "edits": [
            (
                "app/Services/Billing/CashierAutoRechargeGateway.php",
                "        $stripe = $organization->stripe();\n",
                "        $stripe = $organization->stripe();\n        $stripe->customers->retrieve($customerId);\n        $stripe->customers->retrieve($customerId);\n        $stripe->customers->retrieve($customerId);\n",
            )
        ],
        "tests": ["tests/Feature/Billing/AutoRechargeStripeCallBudgetTest.php"],
    },
    {
        "id": 11,
        "desc": "config/queue.php の retry_after を 280 にする (予算 290 未満)",
        "expect": "時間予算: 外部予算 + 局所予算 < worker --timeout < retry_after",
        "edits": [("config/queue.php", "'retry_after' => 360,", "'retry_after' => 280,")],
        "tests": ["tests/Architecture/ExternalClientTimeoutInventoryTest.php"],
    },
    {
        "id": 12,
        "desc": "mprocs.yaml の database worker --timeout を 360 にする",
        "expect": "時間予算: mprocs の database worker --timeout が定数と一致する / 既存 規則 1",
        "edits": [("mprocs.yaml", "queue:listen database --tries=1 --timeout=300", "queue:listen database --tries=1 --timeout=360")],
        "tests": [
            "tests/Architecture/ExternalClientTimeoutInventoryTest.php",
            "tests/Architecture/QueueWorkerLeaseInvariantTest.php",
        ],
    },
    {
        "id": 13,
        "desc": "TakeRegistrationService の成功パスに $this->storage->exists() を足す",
        "expect": "テイク登録エンドポイントは BoundedControl / NoObjectRequest 面しか呼ばない",
        "edits": [
            (
                "app/Services/Capture/TakeRegistrationService.php",
                "        $head = $this->storage->headObject($reservation->video_path);",
                "        $this->storage->exists($reservation->video_path);\n        $head = $this->storage->headObject($reservation->video_path);",
            )
        ],
        "tests": ["tests/Feature/Capture/TakeRegistrationS3SurfaceTest.php"],
    },
    {
        "id": 14,
        "desc": "AppServiceProvider に ApiRequestor::setHttpClient() を足す",
        "expect": "到達境界: Stripe の大域 setter はシンボルごとに許可箇所へ限定される",
        "edits": [
            (
                "app/Providers/AppServiceProvider.php",
                "        $this->app->bind(SnsSignatureVerifier::class, AwsSnsSignatureVerifier::class);",
                "        \\Stripe\\ApiRequestor::setHttpClient(new \\Stripe\\HttpClient\\CurlClient);\n        $this->app->bind(SnsSignatureVerifier::class, AwsSnsSignatureVerifier::class);",
            )
        ],
        "tests": ["tests/Architecture/ExternalClientTimeoutInventoryTest.php"],
    },
    {
        "id": 15,
        "desc": "PhpTokenScan::normalize() からコメント除去を外す",
        "expect": "既存 QueuedJobLeaseInventoryTest の回帰 (共通化が振る舞いを変えていないことの逆確認)",
        "edits": [
            (
                "tests/Support/PhpTokenScan.php",
                "                if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {",
                "                if (in_array($token[0], [T_WHITESPACE], true)) {",
            )
        ],
        "tests": ["tests/Architecture/QueuedJobLeaseInventoryTest.php"],
    },
    {
        "id": 16,
        "desc": "FakeTakeObjectStorage の目録 entry を surface => 'adapter' に変える",
        "expect": "到達境界: adapter 集合は面分類目録のクラスキーと一致する",
        "edits": [
            (
                "tests/Architecture/ExternalClientTimeoutInventoryTest.php",
                "    FakeTakeObjectStorage::class => [\n        'surface' => 'exempt',",
                "    FakeTakeObjectStorage::class => [\n        'surface' => 'adapter',",
            )
        ],
        "tests": ["tests/Architecture/ExternalClientTimeoutInventoryTest.php"],
    },
    {
        "id": 17,
        "desc": "provider の new CurlClient を CurlClient::instance() に変える",
        "expect": "到達境界: Stripe の大域 setter … (CurlClient::instance は app/ で 0 件)",
        "edits": [("app/Providers/ExternalClientTimeoutServiceProvider.php", "$client = new CurlClient;", "$client = CurlClient::instance();")],
        "tests": ["tests/Architecture/ExternalClientTimeoutInventoryTest.php"],
    },
    {
        "id": 18,
        "desc": "無関係なテストに ApiRequestor::setHttpClient() を足す",
        "expect": "到達境界: Stripe の大域 setter … (tests/ 側 exact-fit)",
        "edits": [
            (
                "tests/Unit/Architecture/ExternalClientBoundaryScannerTest.php",
                "test('use ... as ... の alias を解決する', function (): void {",
                "test('mutation probe', function (): void {\n    \\Stripe\\ApiRequestor::setHttpClient(new \\Stripe\\HttpClient\\CurlClient);\n    expect(true)->toBeTrue();\n});\n\ntest('use ... as ... の alias を解決する', function (): void {",
            )
        ],
        "tests": ["tests/Architecture/ExternalClientTimeoutInventoryTest.php"],
    },
    {
        "id": 19,
        "desc": "許可済みテストファイル内に setHttpClient を 1 件追加する (3 件にする)",
        "expect": "到達境界: Stripe の大域 setter … (site 件数一致)",
        "edits": [
            (
                "tests/Feature/Providers/ExternalClientTimeoutServiceProviderTest.php",
                "test('負のコントロール: pin されていない CurlClient は SDK 既定値を返す', function (): void {",
                "test('mutation probe', function (): void {\n    ApiRequestor::setHttpClient(new CurlClient);\n    expect(true)->toBeTrue();\n});\n\ntest('負のコントロール: pin されていない CurlClient は SDK 既定値を返す', function (): void {",
            )
        ],
        "tests": ["tests/Architecture/ExternalClientTimeoutInventoryTest.php"],
    },
    {
        "id": 20,
        "desc": "app/ の Service に匿名クラス経由の Storage::disk('s3') を足す",
        "expect": "到達境界: … (AnonymousClass 帰属は違反)",
        "edits": [
            (
                "app/Services/Capture/StorageUsageService.php",
                "class StorageUsageService\n{\n",
                "class StorageUsageService\n{\n    public function mutationProbe(): object { return new class { public function f(): bool { return \\Illuminate\\Support\\Facades\\Storage::disk('s3')->exists('x'); } }; }\n",
            )
        ],
        "tests": ["tests/Architecture/ExternalClientTimeoutInventoryTest.php"],
    },
    {
        "id": 21,
        "desc": "免除 DefaultDiskWithoutAwsClient のクラスに disk('s3') を足す (免除の適用条件を破る)",
        "expect": "到達境界: 免除理由の適用条件が走査結果と矛盾しない",
        "edits": [
            (
                "app/Services/Manual/SopTextExtractor.php",
                "        $contents = Storage::get($document->file_path);",
                "        $contents = Storage::disk('s3')->get($document->file_path);",
            )
        ],
        "tests": ["tests/Architecture/ExternalClientTimeoutInventoryTest.php"],
    },
    {
        "id": 22,
        "desc": "config/filesystems.php に pin 無しの driver=s3 disk を足す",
        "expect": "AWS config: driver=s3 の disk はすべて http / retries を宣言する",
        "edits": [
            (
                "config/filesystems.php",
                "        's3' => [\n",
                "        's3_unpinned_probe' => ['driver' => 's3', 'bucket' => 'probe'],\n\n        's3' => [\n",
            )
        ],
        "tests": ["tests/Architecture/ExternalClientTimeoutInventoryTest.php"],
    },
]


def main() -> int:
    only = {int(a) for a in sys.argv[1:]} if len(sys.argv) > 1 else None
    rows = []
    for mutation in MUTATIONS:
        if only is not None and mutation["id"] not in only:
            continue
        touched = sorted({path for path, _, _ in mutation["edits"]})
        backups = {}
        for path in touched:
            backups[path] = (ROOT / path).read_text()
        try:
            for path, old, new in mutation["edits"]:
                apply_replace(path, old, new)
            red, detail = run_tests(mutation["tests"])
        finally:
            for path, content in backups.items():
                (ROOT / path).write_text(content)

        rows.append((mutation, red, detail))
        status = "RED (期待どおり)" if red else "GREEN (gate が効いていない!)"
        print(f"[M{mutation['id']:02d}] {status} — {mutation['desc']}")
        print(f"        赤くなったテスト: {detail}")

    ok = all(red for _, red, _ in rows)
    print()
    print("ALL RED" if ok else "SOME MUTATIONS SURVIVED")
    return 0 if ok else 1


if __name__ == "__main__":
    raise SystemExit(main())
