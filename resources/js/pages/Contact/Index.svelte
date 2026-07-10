<script lang="ts">
    import { useForm } from "@inertiajs/svelte";
    import { onMount } from "svelte";
    import Button from "@/components/atoms/Button.svelte";
    import Card from "@/components/atoms/Card.svelte";
    import Checkbox from "@/components/atoms/Checkbox.svelte";
    import FormError from "@/components/atoms/FormError.svelte";
    import Input from "@/components/atoms/Input.svelte";
    import Select from "@/components/atoms/Select.svelte";
    import Textarea from "@/components/atoms/Textarea.svelte";
    import TextLink from "@/components/atoms/TextLink.svelte";
    import FormField from "@/components/molecules/FormField.svelte";
    import GuestLayout from "@/components/templates/GuestLayout.svelte";
    import {
        executeInvisible,
        loadRecaptcha,
        renderInvisible,
        resetInvisible,
    } from "@/lib/recaptcha";

    interface Props {
        appName: string;
        types: { value: string; label: string }[];
        source: string | null;
        recaptchaSiteKey: string;
        termsUrl: string;
        privacyUrl: string;
    }

    let { appName, types, source, recaptchaSiteKey, termsUrl, privacyUrl }: Props = $props();

    // 初期値としての一度きりの参照で意図どおり (フォーム初期化後に props は追従しない)
    // svelte-ignore state_referenced_locally
    const form = useForm({
        type: types[0]?.value ?? "",
        name: "",
        email: "",
        company_name: "",
        message: "",
        source: source ?? "",
        terms_accepted: false,
        website: "", // honeypot (人間には不可視。bot 充填で silent drop)
        "g-recaptcha-response": "",
    });

    let recaptchaContainer: HTMLDivElement | undefined = $state();
    let widgetId: number | null = null;

    onMount(() => {
        // site key 未設定 (local 等) は widget を描画せず captcha 無しの縮退で動く
        if (!recaptchaSiteKey || !recaptchaContainer) return;
        void loadRecaptcha()
            .then(() => {
                if (!recaptchaContainer) return;
                widgetId = renderInvisible(recaptchaContainer, recaptchaSiteKey, (token) => {
                    form["g-recaptcha-response"] = token;
                    postForm();
                });
            })
            .catch(() => {
                // grecaptcha ロード失敗時はサーバ側 RecaptchaVerifier の fail-open に委ねる
            });
    });

    function postForm(): void {
        form.post("/contact", {
            onFinish: () => {
                // token は使い切りのため送信後に reset する
                if (widgetId !== null) resetInvisible(widgetId);
                form["g-recaptcha-response"] = "";
            },
        });
    }

    function submit(event: SubmitEvent): void {
        event.preventDefault();
        if (widgetId !== null) {
            // invisible challenge を起動。callback (postForm) が token 取得後に送信する
            executeInvisible(widgetId);
            return;
        }
        // site key 未設定で widget が無い場合は、サーバ側 'g-recaptcha-response' required を
        // 満たすためのプレースホルダ token を載せて送信する (サーバ側 RecaptchaVerifier が
        // 非 production の secret 未設定 fail-open で検証を通す)
        if (!form["g-recaptcha-response"]) {
            form["g-recaptcha-response"] = "no-recaptcha";
        }
        postForm();
    }
</script>

<GuestLayout {appName}>
    <div class="mx-auto w-full max-w-2xl">
        <h1 class="text-h1">お問い合わせ</h1>
        <p class="mt-2 text-caption text-text-secondary">
            ご質問・ご相談をお気軽にお寄せください。
        </p>

        <Card padding="lg" class="mt-6">
            <form onsubmit={submit} class="flex flex-col gap-4" data-testid="contact-form">
                <FormField label="お問い合わせ種別" id="type" required error={form.errors.type}>
                    {#snippet children({ id, describedBy, invalid })}
                        <Select
                            {id}
                            bind:value={form.type}
                            error={invalid}
                            aria-describedby={describedBy}
                        >
                            {#each types as t (t.value)}
                                <option value={t.value}>{t.label}</option>
                            {/each}
                        </Select>
                    {/snippet}
                </FormField>

                <FormField label="お名前" id="name" required error={form.errors.name}>
                    {#snippet children({ id, describedBy, invalid })}
                        <Input
                            {id}
                            type="text"
                            bind:value={form.name}
                            error={invalid}
                            aria-describedby={describedBy}
                            autocomplete="name"
                        />
                    {/snippet}
                </FormField>

                <FormField label="メールアドレス" id="email" required error={form.errors.email}>
                    {#snippet children({ id, describedBy, invalid })}
                        <Input
                            {id}
                            type="email"
                            bind:value={form.email}
                            error={invalid}
                            aria-describedby={describedBy}
                            autocomplete="email"
                        />
                    {/snippet}
                </FormField>

                <FormField label="会社・組織名" id="company_name" error={form.errors.company_name}>
                    {#snippet children({ id, describedBy, invalid })}
                        <Input
                            {id}
                            type="text"
                            bind:value={form.company_name}
                            error={invalid}
                            aria-describedby={describedBy}
                            autocomplete="organization"
                        />
                    {/snippet}
                </FormField>

                <FormField label="お問い合わせ内容" id="message" required error={form.errors.message}>
                    {#snippet children({ id, describedBy, invalid })}
                        <Textarea
                            {id}
                            bind:value={form.message}
                            error={invalid}
                            aria-describedby={describedBy}
                            rows={6}
                        />
                    {/snippet}
                </FormField>

                <!-- honeypot: 人間には不可視 (sr-only)。bot が充填するとサーバ側で silent drop -->
                <div class="sr-only" aria-hidden="true">
                    <label for="website">Website</label>
                    <input
                        id="website"
                        type="text"
                        tabindex="-1"
                        autocomplete="off"
                        bind:value={form.website}
                        data-testid="contact-honeypot"
                    />
                </div>

                <Checkbox
                    id="terms_accepted"
                    bind:checked={form.terms_accepted}
                    error={form.errors.terms_accepted}
                    testId="contact-consent"
                >
                    {#snippet label()}
                        <TextLink href={termsUrl}>利用規約</TextLink>と<TextLink href={privacyUrl}
                            >プライバシーポリシー</TextLink
                        >に同意します
                    {/snippet}
                </Checkbox>

                <FormError
                    id="g-recaptcha-response-error"
                    message={form.errors["g-recaptcha-response"]}
                />

                <div bind:this={recaptchaContainer}></div>

                <Button type="submit" loading={form.processing} fullWidth>送信する</Button>
            </form>
        </Card>
    </div>
</GuestLayout>
