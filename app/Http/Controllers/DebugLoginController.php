<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * local 専用デバッグログイン。LocalOnly middleware (+ route 登録ゲート) の背後でのみ動く。
 *
 * ユーザー一覧からワンクリックで任意ユーザーとしてログインし、手動テストの
 * アカウント切替を高速化する。認可・レート制限は不要 (local 限定経路)。
 */
class DebugLoginController extends Controller
{
    /**
     * ユーザー一覧を表示する (ワンクリックログイン導線)。
     */
    public function index(): Response
    {
        // CipherSweet で暗号化された列 (name / email) は全列揃えてロードしないと
        // decryptRow() が失敗し復号がスキップされる。User 本体は select() で列を
        // 絞らず丸ごとロードし、将来の暗号化列追加にも追従させる。
        $users = User::query()
            ->orderBy('id')
            ->get()
            ->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ])
            ->all();

        return Inertia::render('Debug/Login', [
            'users' => $users,
        ]);
    }

    /**
     * 指定ユーザーとして即時ログインする (local 限定の非対話ログイン)。
     */
    public function loginAs(Request $request, int $userId): RedirectResponse
    {
        $user = User::findOrFail($userId);

        // 既存セッションを完全に破棄してからログインする (session fixation 防止の作法に揃える)
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('app.entry');
    }
}
