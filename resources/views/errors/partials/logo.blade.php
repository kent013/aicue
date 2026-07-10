{{-- 自己完結 error page 用ロゴ。Lucide 等のランタイム依存を避けるため inline SVG で置く。
     ブランド未定のニュートラルなプレースホルダ (スレート色のひし形マーク)。
     アプリ初期化時に実ブランドロゴの主要 path を移植し、色を DESIGN.md の primary に揃える。 --}}
<svg class="logo" viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="{{ config('app.name') }}">
    <rect width="64" height="64" rx="14" fill="#1f2937" />
    <path fill="#ffffff" d="M32 16 L48 32 L32 48 L16 32 Z" />
</svg>
