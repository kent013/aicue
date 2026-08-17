<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Controllers\Testing\GetFakeStorageObjectController;
use App\Http\Controllers\Testing\PutFakeStorageObjectController;
use App\Services\AI\Testing\CannedPromptFakeRegistrar;
use App\Support\ExternalFakes\ExternalFakeDeclaration;
use App\Support\FakeStorageGate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * 偽の外部サービスの配線 (差し替え先の決定は本ファイルに 1 つも無い)。
 *
 * 「何をどの偽物へ差し替えるか」の正本は App\Support\ExternalFakes\ExternalFakeDeclaration で、
 * 本 provider はその宣言を 1 本の経路で適用するだけである。
 *
 * bootstrap/providers.php で AppServiceProvider より後に登録する (後勝ち rebind)。
 * fail-secure 二軸:
 * 1. capability flag === true (既定 false = 完全 no-op)
 * 2. 環境 allowlist。denylist (非 production) ではなく allowlist で倒す = staging 等の
 *    未知環境で flag が誤設定されても偽物を立てない (warning ログで検出可能にする)。
 *    production は加えて ProductionEnvGuard が flag=true を起動時 fail-fast で拒否する。
 *
 * capability は 3 つで許可環境も判定も異なる (すべて宣言側が正本):
 * - 外部サービス (決済 gateway + 人間性確認 + 外部ログインの解決点): EXTERNALS_FLAG。
 *   container 差し替えのため register() で配線する。**外部ログインだけ許可環境が狭い**。
 * - 保存先 (S3): STORAGE_FLAG。有効化条件は FakeStorageGate に一元化する
 *   (経路登録と実行時判定を完全一致させるため。経路キャッシュ残存で素通りしないようにする)。
 * - LLM (Prism): LLM_FLAG。Prompt::$fake はプロセス大域の static のため container 差し替えではなく
 *   boot() で install する (宣言の swaps() には現れない)。
 */
class BughuntFakesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->installDeclaredSwaps();
    }

    public function boot(): void
    {
        $this->bootLlmFake();       // LLM: LLM_FLAG 依存 (container 差し替えではない)
        $this->bootStorageRoutes(); // 偽の保存先の署名付き経路 — 独立
    }

    /**
     * 宣言集合を 1 本の経路で差し替える (bind 対象の決定は宣言側にしか無い)。
     */
    private function installDeclaredSwaps(): void
    {
        $environment = $this->app->environment();
        $this->warnIfExternalsFlagIsUnusable($environment);

        // 保存先だけは「登録条件と実行時条件を完全一致させる」ため判定を gate に一元化している。
        // ここでは 1 度だけ解決する。
        $storageEnabled = $this->app->make(FakeStorageGate::class)->enabled();

        foreach (ExternalFakeDeclaration::swaps() as $swap) {
            $enabled = $swap->flag === ExternalFakeDeclaration::STORAGE_FLAG
                ? $storageEnabled
                : config($swap->flag) === true
                    && in_array($environment, $swap->allowedEnvironments, true);

            if (! $enabled) {
                continue;
            }

            $this->app->bind($swap->abstract, $swap->fake);
        }
    }

    /**
     * 外部サービスのフラグが立っているのに、その capability の許可環境の外にいるときだけ
     * **1 度だけ**警告する (未知の環境で誤って有効化されたことを検出可能にするため)。
     *
     * ★外部ログインだけ許可環境が狭いことについては**警告しない**。あれは誤設定ではなく
     *   設計上の除外である (保存先 / LLM のフラグでも警告しないのと同じ)。
     */
    private function warnIfExternalsFlagIsUnusable(string $environment): void
    {
        if (config(ExternalFakeDeclaration::EXTERNALS_FLAG) !== true) {
            return;
        }

        if (in_array($environment, ExternalFakeDeclaration::EXTERNAL_ENVIRONMENTS, true)) {
            return;
        }

        Log::warning('TESTING_FAKE_EXTERNALS=true ですが allowlist 外の環境のため fake を bind しません。', [
            'environment' => $environment,
        ]);
    }

    /** LLM (Prism) の偽物 (LLM_FLAG + LLM_ENVIRONMENTS。挙動不変) */
    private function bootLlmFake(): void
    {
        // bughunt 既定は実 LLM で、--fake-llm 指定時のみ TESTING_FAKE_LLM=true が注入される。
        if (config(ExternalFakeDeclaration::LLM_FLAG) !== true) {
            return;
        }

        // Prompt::$fake (プロセス大域の static) を書き換えるため、per-test で static を占有する
        // testing と、実 API 検証を潰す local は許可環境から外す。
        // (決済と違い warning は出さない: testing/local の除外は誤設定ではなく設計上の除外)
        if (! in_array($this->app->environment(), ExternalFakeDeclaration::LLM_ENVIRONMENTS, true)) {
            return;
        }

        // Browser lane (tests/Pest.php) と同一の install API を使う (Prompt::installFake の封じ込め)。
        $this->app->make(CannedPromptFakeRegistrar::class)->install();
    }

    /** 偽の保存先の署名付き経路 (gate 成立時のみ。web CSRF group 外 = signed のみ) */
    private function bootStorageRoutes(): void
    {
        if (! $this->app->make(FakeStorageGate::class)->enabled()) {
            return;
        }

        // 冪等化: boot() が複数回走っても (route:cache 併用・テストの provider 再実走等)
        // 同名 route を二重登録しない。通常の bootstrap では未登録 = そのまま登録される。
        if (Route::has('bughunt.storage.put')) {
            return;
        }

        Route::middleware('signed')->group(function (): void {
            Route::put('/_fake-storage/object', PutFakeStorageObjectController::class)
                ->name('bughunt.storage.put');
            Route::get('/_fake-storage/object', GetFakeStorageObjectController::class)
                ->name('bughunt.storage.get');
        });
    }
}
