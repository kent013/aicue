<script lang="ts">
    let { appName }: { appName: string } = $props();
</script>

<main class="flex min-h-screen items-center justify-center bg-neutral text-text">
    <div class="text-center">
        <h1 class="text-h2">{appName}</h1>
        <p class="mt-2 text-caption text-text-secondary">Laravel + Svelte template is running.</p>
    </div>
</main>
