#!/usr/bin/env python3
"""fake 配線 gate の mutation テスト用ヘルパ (一時スクリプト。gate の受入検証にのみ使う)。

使い方:
    python3 devnotes/20260806-1355-external-fakes-wiring-gate/mutations.py apply M1
    python3 devnotes/20260806-1355-external-fakes-wiring-gate/mutations.py revert

mutation は「gate が本当に赤くなるか」を確認するためだけに当てる。必ず revert する
(revert は git checkout -- で対象ファイルを元に戻す)。
"""

from __future__ import annotations

import subprocess
import sys
from pathlib import Path

PROVIDER = Path("app/Providers/FakeExternalsServiceProvider.php")
PROVIDERS_LIST = Path("bootstrap/providers.php")
QUOTA_SERVICE = Path("app/Services/Billing/QuotaService.php")

TOUCHED = [PROVIDER, PROVIDERS_LIST, QUOTA_SERVICE]


def read(path: Path) -> str:
    return path.read_text(encoding="utf-8")


def write(path: Path, body: str) -> None:
    path.write_text(body, encoding="utf-8")


def drop_line(path: Path, needle: str) -> None:
    lines = read(path).splitlines(keepends=True)
    kept = [line for line in lines if needle not in line]
    if len(kept) == len(lines):
        raise SystemExit(f"mutation 失敗: {needle!r} が {path} に見つからない")
    write(path, "".join(kept))


def apply(mutation: str) -> None:
    if mutation == "M1":
        # AutoRecharge の bind を落とす (実カードへの請求経路が real に戻る)
        drop_line(PROVIDER, "bind(AutoRechargeGatewayInterface::class, FakeAutoRechargeGateway::class)")
    elif mutation == "M2":
        # TakeObjectStorage の bind を落とす (abstract が具象なので Laravel が本物を自動組み立てする)
        drop_line(PROVIDER, "bind(TakeObjectStorage::class, FakeTakeObjectStorage::class)")
    elif mutation == "M3":
        # provider 登録点そのものを落とす
        drop_line(PROVIDERS_LIST, "    FakeExternalsServiceProvider::class,")
    elif mutation == "M4":
        # 登録順を反転させる (AppServiceProvider の後勝ちが崩れる)
        body = read(PROVIDERS_LIST)
        body = body.replace("    FakeExternalsServiceProvider::class,\n", "")
        body = body.replace(
            "    AppServiceProvider::class,\n",
            "    FakeExternalsServiceProvider::class,\n    AppServiceProvider::class,\n",
        )
        write(PROVIDERS_LIST, body)
    elif mutation == "M5":
        # inventory 未登録の bind 組を足す (既存 fake クラスを使うので参照集合は変わらない)
        body = read(PROVIDER)
        body = body.replace(
            "        $this->app->bind(RenderObjectStorage::class, FakeRenderObjectStorage::class);\n",
            "        $this->app->bind(RenderObjectStorage::class, FakeRenderObjectStorage::class);\n"
            "        $this->app->bind(\\App\\Services\\Render\\VideoComposer::class, "
            "\\App\\Services\\Render\\Fakes\\FakeRenderObjectStorage::class);\n",
        )
        write(PROVIDER, body)
    elif mutation == "M5b":
        # inventory 未登録の fake クラスを provider が新規参照する変種 (3-10 用)
        body = read(PROVIDER)
        body = body.replace(
            "use App\\Services\\Billing\\Fakes\\FakeStripeGateway;\n",
            "use App\\Services\\Billing\\Fakes\\FakeExternalUrl;\nuse App\\Services\\Billing\\Fakes\\FakeStripeGateway;\n",
        )
        body = body.replace(
            "        $this->app->bind(StripeGatewayInterface::class, FakeStripeGateway::class);\n",
            "        $this->app->bind(StripeGatewayInterface::class, FakeStripeGateway::class);\n"
            "        Log::debug(FakeExternalUrl::class);\n",
        )
        write(PROVIDER, body)
    elif mutation == "M6":
        # bind を singleton へ (許可された呼び出し形から外れる)
        body = read(PROVIDER)
        body = body.replace(
            "$this->app->bind(TicketCheckoutGateway::class",
            "$this->app->singleton(TicketCheckoutGateway::class",
            1,
        )
        write(PROVIDER, body)
    elif mutation == "M6b":
        # bind(A::class, B::class, true) = singleton 相当による M6 回避
        body = read(PROVIDER)
        body = body.replace(
            "$this->app->bind(TicketCheckoutGateway::class, FakeTicketCheckoutGateway::class);",
            "$this->app->bind(TicketCheckoutGateway::class, FakeTicketCheckoutGateway::class, true);",
            1,
        )
        write(PROVIDER, body)
    elif mutation == "M8":
        # use alias 経由で Container::getInstance() へ逃げる (Codex 実装レビュー Round 1 の Critical)
        body = read(PROVIDER)
        body = body.replace(
            "use Illuminate\\Support\\Facades\\Log;\n",
            "use Illuminate\\Container\\Container as C;\nuse Illuminate\\Support\\Facades\\Log;\n",
        )
        body = body.replace(
            "        $this->app->bind(RenderObjectStorage::class, FakeRenderObjectStorage::class);\n",
            "        $this->app->bind(RenderObjectStorage::class, FakeRenderObjectStorage::class);\n"
            "        C::getInstance()->bind(\\App\\Services\\Render\\VideoComposer::class, "
            "FakeRenderObjectStorage::class);\n",
        )
        write(PROVIDER, body)
    elif mutation == "M8b":
        # use function alias 経由で app() helper へ逃げる
        body = read(PROVIDER)
        body = body.replace(
            "use Illuminate\\Support\\Facades\\Log;\n",
            "use Illuminate\\Support\\Facades\\Log;\nuse function app as container;\n",
        )
        body = body.replace(
            "        $this->app->bind(RenderObjectStorage::class, FakeRenderObjectStorage::class);\n",
            "        $this->app->bind(RenderObjectStorage::class, FakeRenderObjectStorage::class);\n"
            "        container()->bind(\\App\\Services\\Render\\VideoComposer::class, "
            "FakeRenderObjectStorage::class);\n",
        )
        write(PROVIDER, body)
    elif mutation == "M9":
        # 動的プロパティアクセスで $this->app のトークン列を回避する
        # (Codex 実装レビュー Round 2 の Critical)
        body = read(PROVIDER)
        body = body.replace(
            "        $this->app->bind(RenderObjectStorage::class, FakeRenderObjectStorage::class);\n",
            "        $this->app->bind(RenderObjectStorage::class, FakeRenderObjectStorage::class);\n"
            "        $this->{'app'}->bind(\\App\\Services\\Render\\VideoComposer::class, "
            "FakeRenderObjectStorage::class);\n",
        )
        write(PROVIDER, body)
    elif mutation == "M10":
        # $this を式へ渡して container を取り出す (Codex 実装レビュー Round 3 の Critical)
        body = read(PROVIDER)
        body = body.replace(
            "        $this->app->bind(RenderObjectStorage::class, FakeRenderObjectStorage::class);\n",
            "        $this->app->bind(RenderObjectStorage::class, FakeRenderObjectStorage::class);\n"
            "        get_object_vars($this)['app']->bind(\\App\\Services\\Render\\VideoComposer::class, "
            "FakeRenderObjectStorage::class);\n",
        )
        write(PROVIDER, body)
    elif mutation == "M7":
        # 本番コードが fake クラスを参照する
        body = read(QUOTA_SERVICE)
        body = body.replace(
            "use App\\Models\\Organization;\n",
            "use App\\Models\\Organization;\nuse App\\Services\\Billing\\Fakes\\FakeStripeGateway;\n",
        )
        write(QUOTA_SERVICE, body)
    else:
        raise SystemExit(f"未知の mutation: {mutation}")


def revert() -> None:
    subprocess.run(["git", "checkout", "--", *[str(p) for p in TOUCHED]], check=True)


def main() -> None:
    if len(sys.argv) < 2:
        raise SystemExit(__doc__)
    command = sys.argv[1]
    if command == "revert":
        revert()
        return
    if command == "apply":
        revert()
        apply(sys.argv[2])
        return
    raise SystemExit(__doc__)


if __name__ == "__main__":
    main()
