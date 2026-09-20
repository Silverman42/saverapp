<script setup lang="ts">
import { Form, Head, setLayoutProps } from "@inertiajs/vue3";
import { useClipboard } from "@vueuse/core";
import { useVuelidate } from "@vuelidate/core";
import { helpers, required } from "@vuelidate/validators";
import {
    Check,
    Copy,
    Download,
    Lock,
    Printer,
    ShieldAlert,
    ShieldCheck,
} from "@lucide/vue";
import { computed, reactive, ref, unref, watchEffect } from "vue";
import InputError from "@/components/InputError.vue";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import {
    InputOTP,
    InputOTPGroup,
    InputOTPSlot,
} from "@/components/ui/input-otp";
import { Label } from "@/components/ui/label";
import { acknowledge, confirm } from "@/routes/two-factor/enrolment";

type Props = {
    qrCodeSvg: string;
    manualSetupKey: string;
    expiresAt: string | null;
    isReplacement: boolean;
    recoveryCodes: string[];
    hasConfirmed: boolean;
};

const props = defineProps<Props>();

const { copy, copied } = useClipboard();

const formState = reactive({
    code: "",
});

const rules = {
    code: {
        required: helpers.withMessage(
            "Authentication code is required",
            required,
        ),
        validFormat: helpers.withMessage(
            "Code must be 6 digits",
            (val: string) => /^\d{6}$/.test(val),
        ),
    },
};

const v$ = useVuelidate(rules, formState);

const hasAcknowledged = ref<boolean | 'indeterminate'>(false);
const copiedCodes = ref<boolean>(false);

const copyAllCodes = async (): Promise<void> => {
    if (props.recoveryCodes.length) {
        await copy(props.recoveryCodes.join("\n"));
        copiedCodes.value = true;
        setTimeout(() => {
            copiedCodes.value = false;
        }, 3000);
    }
};

const downloadCodes = (): void => {
    if (!props.recoveryCodes.length) return;
    const blob = new Blob([props.recoveryCodes.join("\n")], {
        type: "text/plain;charset=utf-8",
    });
    const url = URL.createObjectURL(blob);
    const link = document.createElement("a");
    link.href = url;
    link.download = "recovery-codes.txt";
    link.click();
    URL.revokeObjectURL(url);
};

const printCodes = (): void => {
    window.print();
};

const pageTitle = computed(() =>
    props.recoveryCodes.length > 0
        ? "Save Your Recovery Codes"
        : props.isReplacement
          ? "Replace Authenticator App"
          : "Mandatory Two-Factor Setup",
);

const pageDescription = computed(() =>
    props.recoveryCodes.length > 0
        ? "Store these emergency recovery codes in a secure password manager. They will not be displayed again."
        : "Scan the QR code with your authenticator app (e.g. Google Authenticator, 1Password) to protect your account.",
);

watchEffect(() => {
    setLayoutProps({
        title: pageTitle.value,
        description: pageDescription.value,
    });
});
</script>

<template>
    <div class="space-y-6">
        <Head :title="pageTitle" />

    <!-- STEP 2: Display 10 Recovery Codes Once -->
    <div
        v-if="props.recoveryCodes && props.recoveryCodes.length > 0"
        class="space-y-6"
    >
        <div
            class="bg-amber-500/10 border-amber-500/20 text-amber-950 dark:text-amber-200 flex items-start gap-3 rounded-lg border p-4 text-sm"
        >
            <ShieldAlert
                class="mt-0.5 size-5 shrink-0 text-amber-600 dark:text-amber-400"
            />
            <div class="space-y-1">
                <p class="font-medium">Important: Save these emergency codes</p>
                <p class="text-muted-foreground text-xs">
                    If you lose access to your authenticator app, these codes
                    are your only way to recover account access. Each code can
                    only be used once.
                </p>
            </div>
        </div>

        <!-- Recovery Codes List -->
        <div
            class="bg-muted grid grid-cols-2 gap-2 rounded-lg border p-4 font-mono text-sm"
        >
            <div
                v-for="(code, index) in props.recoveryCodes"
                :key="index"
                class="bg-background/80 flex items-center justify-between rounded px-3 py-1.5 shadow-xs"
            >
                <span>{{ code }}</span>
            </div>
        </div>

        <!-- Actions: Copy / Download / Print -->
        <div class="flex flex-wrap items-center gap-2">
            <Button
                variant="outline"
                size="sm"
                @click="copyAllCodes"
                class="gap-1.5"
            >
                <Check v-if="copiedCodes" class="size-4 text-green-500" />
                <Copy v-else class="size-4" />
                {{ copiedCodes ? "Copied" : "Copy All Codes" }}
            </Button>
            <Button
                variant="outline"
                size="sm"
                @click="downloadCodes"
                class="gap-1.5"
            >
                <Download class="size-4" /> Download (.txt)
            </Button>
            <Button
                variant="outline"
                size="sm"
                @click="printCodes"
                class="gap-1.5"
            >
                <Printer class="size-4" /> Print
            </Button>
        </div>

        <!-- Acknowledge and Continue -->
        <Form
            action="/two-factor-enrolment/acknowledge"
            method="post"
            class="space-y-4 pt-2"
            #default="{ processing }"
        >
            <div class="flex items-start gap-3">
                <Checkbox
                    id="acknowledge"
                    v-model="hasAcknowledged"
                    class="mt-1"
                />
                <Label
                    for="acknowledge"
                    class="text-sm leading-relaxed font-normal cursor-pointer select-none"
                >
                    I have safely recorded these emergency recovery codes in a
                    secure location.
                </Label>
            </div>

            <Button
                type="submit"
                class="w-full"
                :disabled="hasAcknowledged !== true || processing"
            >
                <ShieldCheck class="mr-2 size-4" />
                Complete Setup and Continue
            </Button>
        </Form>
    </div>

    <!-- STEP 1: Scan QR Code and Verify TOTP -->
    <div v-else class="space-y-6">
        <!-- QR Code -->
        <div class="flex flex-col items-center justify-center space-y-4">
            <div
                class="border-border bg-card overflow-hidden rounded-xl border p-4 shadow-xs"
            >
                <div
                    v-html="props.qrCodeSvg"
                    class="size-52 dark:invert dark:brightness-150"
                />
            </div>

            <!-- Manual Entry Key -->
            <div class="w-full space-y-1.5 text-center">
                <p class="text-muted-foreground text-xs">
                    Or enter this key manually in your app:
                </p>
                <div
                    class="border-border bg-muted flex items-center justify-between rounded-lg border px-3 py-1.5"
                >
                    <span class="font-mono text-xs tracking-wider select-all">{{
                        props.manualSetupKey
                    }}</span>
                    <Button
                        variant="ghost"
                        size="icon"
                        class="size-7 shrink-0"
                        @click="copy(props.manualSetupKey)"
                    >
                        <Check v-if="copied" class="size-3.5 text-green-500" />
                        <Copy v-else class="size-3.5" />
                    </Button>
                </div>
            </div>
        </div>

        <!-- Confirmation Code Form -->
        <Form
            action="/two-factor-enrolment/confirm"
            method="post"
            reset-on-error
            @submit="v$.$touch()"
            #default="{ errors, processing }"
        >
            <input type="hidden" name="code" :value="formState.code" />

            <div class="space-y-4">
                <div
                    class="flex flex-col items-center justify-center space-y-2"
                >
                    <Label for="otp" class="text-sm font-medium"
                        >Enter 6-digit confirmation code</Label
                    >
                    <InputOTP
                        id="otp"
                        v-model="formState.code"
                        :maxlength="6"
                        :disabled="processing"
                        autofocus
                    >
                        <InputOTPGroup>
                            <InputOTPSlot
                                v-for="index in 6"
                                :key="index"
                                :index="index - 1"
                            />
                        </InputOTPGroup>
                    </InputOTP>
                    <InputError
                        :message="
                            errors?.code ||
                            (v$.code.$error
                                ? String(unref(v$.code.$errors[0]?.$message))
                                : undefined)
                        "
                    />
                </div>

                <Button
                    type="submit"
                    class="w-full"
                    :disabled="
                        processing || formState.code.length < 6 || v$.$invalid
                    "
                >
                    <Lock class="mr-2 size-4" />
                    Confirm and Generate Recovery Codes
                </Button>
            </div>
        </Form>
    </div>
    </div>
</template>
