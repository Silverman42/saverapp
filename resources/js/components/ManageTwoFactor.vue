<script setup lang="ts">
import { Form, router, usePage } from '@inertiajs/vue3';
import { useClipboard } from '@vueuse/core';
import {
    AlertTriangle,
    Check,
    Copy,
    Download,
    KeyRound,
    RefreshCw,
    ShieldCheck,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    InputOTP,
    InputOTPGroup,
    InputOTPSlot,
} from '@/components/ui/input-otp';
import { Label } from '@/components/ui/label';
import { useAppearance } from '@/composables/useAppearance';

export type Props = {
    canManageTwoFactor?: boolean;
    requiresConfirmation?: boolean;
    twoFactorEnabled?: boolean;
    authenticatorState?: string;
    remainingRecoveryCodes?: number;
    hasAcknowledgedRecoveryCodes?: boolean;
};

const props = withDefaults(defineProps<Props>(), {
    canManageTwoFactor: false,
    requiresConfirmation: false,
    twoFactorEnabled: false,
    authenticatorState: 'not_configured',
    remainingRecoveryCodes: 10,
    hasAcknowledgedRecoveryCodes: true,
});

const page = usePage();
const { resolvedAppearance } = useAppearance();
const { copy, copied } = useClipboard();

// Replacement Dialog State
const showReplaceModal = ref<boolean>(false);
const replaceStep = ref<'verify' | 'scan' | 'codes'>('verify');
const currentPassword = ref<string>('');
const currentCode = ref<string>('');
const newCode = ref<string>('');
const replaceSetupData = ref<{ secret: string; qr_code: string } | null>(null);

// Regeneration Dialog State
const showRegenerateModal = ref<boolean>(false);
const regenPassword = ref<string>('');
const regenCode = ref<string>('');

// Newly generated codes to display once
const newCodesList = ref<string[]>([]);
const copiedNewCodes = ref<boolean>(false);

// Watch for flash session data
watch(
    () => page.props.flash,
    (flash: any) => {
        if (flash?.replacementSetup) {
            replaceSetupData.value = flash.replacementSetup;
            replaceStep.value = 'scan';
        }
        if (flash?.recoveryCodes && flash.recoveryCodes.length > 0) {
            newCodesList.value = flash.recoveryCodes;
            if (showReplaceModal.value) {
                replaceStep.value = 'codes';
            }
        }
    },
    { deep: true, immediate: true },
);

const isLowCodes = computed(() => (props.remainingRecoveryCodes ?? 10) <= 2);

const copyCodes = async (codes: string[]): Promise<void> => {
    if (codes.length) {
        await copy(codes.join('\n'));
        copiedNewCodes.value = true;
        setTimeout(() => {
            copiedNewCodes.value = false;
        }, 3000);
    }
};

const downloadCodes = (codes: string[]): void => {
    if (!codes.length) return;
    const blob = new Blob([codes.join('\n')], {
        type: 'text/plain;charset=utf-8',
    });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'recovery-codes.txt';
    a.click();
    URL.revokeObjectURL(url);
};

const cancelReplacement = (): void => {
    router.delete('/user/two-factor-authentication/replace/cancel', {
        preserveScroll: true,
        onSuccess: () => {
            showReplaceModal.value = false;
            replaceStep.value = 'verify';
            replaceSetupData.value = null;
            currentPassword.value = '';
            currentCode.value = '';
            newCode.value = '';
        },
    });
};
</script>

<template>
    <div v-if="canManageTwoFactor" class="space-y-4">
        <Heading
            variant="small"
            title="Sign-in codes"
            description="Your account needs a code from your authenticator app to sign in."
        />

        <div class="divide-border divide-y rounded-xl border">
            <div class="flex flex-wrap items-center justify-between gap-3 p-4">
                <div class="flex min-w-0 items-start gap-3">
                    <ShieldCheck
                        class="mt-0.5 size-5 shrink-0 text-green-600 dark:text-green-400"
                    />
                    <div>
                        <p class="flex items-center gap-2 text-sm font-medium">
                            Authenticator app
                            <Badge
                                variant="outline"
                                class="border-green-600/30 bg-green-500/10 text-green-700 dark:text-green-400"
                            >
                                On
                            </Badge>
                        </p>
                        <p class="text-muted-foreground mt-0.5 text-xs">
                            You also need a code before some important actions.
                        </p>
                    </div>
                </div>
                <Button
                    variant="outline"
                    size="sm"
                    @click="showReplaceModal = true"
                >
                    <RefreshCw class="size-3.5" />
                    Change app
                </Button>
            </div>

            <div class="space-y-3 p-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="flex min-w-0 items-start gap-3">
                        <KeyRound class="text-primary mt-0.5 size-5 shrink-0" />
                        <div>
                            <p class="text-sm font-medium">Recovery codes</p>
                            <p class="text-muted-foreground mt-0.5 text-xs">
                                {{ remainingRecoveryCodes }} of 10 left. Use one
                                if you lose your phone.
                            </p>
                        </div>
                    </div>
                    <Button
                        variant="outline"
                        size="sm"
                        @click="showRegenerateModal = true"
                    >
                        <RefreshCw class="size-3.5" />
                        Get new codes
                    </Button>
                </div>

                <Alert
                    v-if="isLowCodes"
                    variant="destructive"
                    class="border-amber-500/40 bg-amber-500/10 text-amber-950 dark:text-amber-200"
                >
                    <AlertTriangle
                        class="size-4 text-amber-600 dark:text-amber-400"
                    />
                    <AlertTitle class="text-sm font-semibold"
                        >You are running low</AlertTitle
                    >
                    <AlertDescription class="text-xs">
                        Only {{ remainingRecoveryCodes }} left. Get new codes so
                        you do not get locked out.
                    </AlertDescription>
                </Alert>

                <a
                    href="/assisted-recovery"
                    class="text-muted-foreground hover:text-foreground inline-block text-xs underline underline-offset-4"
                >
                    Lost your phone and your codes?
                </a>
            </div>
        </div>

        <!-- MODAL: Replace Authenticator -->
        <Dialog
            :open="showReplaceModal"
            @update:open="showReplaceModal = $event"
        >
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Change authenticator app</DialogTitle>
                    <DialogDescription>
                        {{
                            replaceStep === 'verify'
                                ? 'First, confirm it is you.'
                                : replaceStep === 'scan'
                                  ? 'Scan this QR code with your new app.'
                                  : 'Save your new recovery codes somewhere safe.'
                        }}
                    </DialogDescription>
                </DialogHeader>

                <!-- STEP 1: Verify Current Password & TOTP -->
                <div v-if="replaceStep === 'verify'" class="space-y-4 py-2">
                    <Form
                        action="/user/two-factor-authentication/replace"
                        method="post"
                        #default="{ errors, processing }"
                    >
                        <div class="space-y-4">
                            <div class="grid gap-2">
                                <Label for="replace_current_password"
                                    >Password</Label
                                >
                                <PasswordInput
                                    id="replace_current_password"
                                    name="current_password"
                                    v-model="currentPassword"
                                    placeholder="Your password"
                                    required
                                />
                                <InputError
                                    :message="errors.current_password"
                                />
                            </div>

                            <div class="grid gap-2">
                                <Label for="replace_current_code"
                                    >Code from your current app</Label
                                >
                                <InputOTP
                                    id="replace_current_code"
                                    v-model="currentCode"
                                    :maxlength="6"
                                    :disabled="processing"
                                >
                                    <InputOTPGroup>
                                        <InputOTPSlot
                                            v-for="index in 6"
                                            :key="index"
                                            :index="index - 1"
                                        />
                                    </InputOTPGroup>
                                </InputOTP>
                                <input
                                    type="hidden"
                                    name="current_code"
                                    :value="currentCode"
                                />
                                <InputError :message="errors.current_code" />
                            </div>

                            <DialogFooter class="pt-4">
                                <Button
                                    type="button"
                                    variant="outline"
                                    @click="showReplaceModal = false"
                                >
                                    Cancel
                                </Button>
                                <Button
                                    type="submit"
                                    :disabled="
                                        processing ||
                                        !currentPassword ||
                                        currentCode.length < 6
                                    "
                                >
                                    Continue
                                </Button>
                            </DialogFooter>
                        </div>
                    </Form>
                </div>

                <!-- STEP 2: Scan New QR Code and Confirm -->
                <div v-else-if="replaceStep === 'scan'" class="space-y-4 py-2">
                    <div
                        v-if="replaceSetupData"
                        class="flex flex-col items-center justify-center space-y-3"
                    >
                        <div
                            class="border-border bg-card overflow-hidden rounded-xl border p-3"
                        >
                            <div
                                v-html="replaceSetupData.qr_code"
                                class="size-44"
                                :style="{
                                    filter:
                                        resolvedAppearance === 'dark'
                                            ? 'invert(1) brightness(1.5)'
                                            : undefined,
                                }"
                            />
                        </div>

                        <div class="w-full space-y-1 text-center">
                            <p class="text-muted-foreground text-xs">
                                Can't scan? Enter this key:
                            </p>
                            <span class="font-mono text-xs select-all">{{
                                replaceSetupData.secret
                            }}</span>
                        </div>
                    </div>

                    <Form
                        action="/user/two-factor-authentication/replace/confirm"
                        method="post"
                        #default="{ errors, processing }"
                    >
                        <div class="space-y-4 pt-2">
                            <div
                                class="flex flex-col items-center justify-center space-y-2"
                            >
                                <Label for="new_totp_code"
                                    >6-digit code from your new app</Label
                                >
                                <InputOTP
                                    id="new_totp_code"
                                    v-model="newCode"
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
                                <input
                                    type="hidden"
                                    name="code"
                                    :value="newCode"
                                />
                                <InputError :message="errors.code" />
                            </div>

                            <DialogFooter class="pt-4">
                                <Button
                                    type="button"
                                    variant="outline"
                                    @click="cancelReplacement"
                                >
                                    Cancel
                                </Button>
                                <Button
                                    type="submit"
                                    :disabled="processing || newCode.length < 6"
                                >
                                    Confirm
                                </Button>
                            </DialogFooter>
                        </div>
                    </Form>
                </div>

                <!-- STEP 3: Display New Recovery Codes -->
                <div v-else-if="replaceStep === 'codes'" class="space-y-4 py-2">
                    <div
                        class="bg-muted grid grid-cols-2 gap-2 rounded-lg border p-3 font-mono text-xs"
                    >
                        <div
                            v-for="(code, idx) in newCodesList"
                            :key="idx"
                            class="bg-background rounded px-2 py-1"
                        >
                            {{ code }}
                        </div>
                    </div>

                    <div class="flex gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            @click="copyCodes(newCodesList)"
                        >
                            <Check
                                v-if="copiedNewCodes"
                                class="mr-1.5 size-3.5 text-green-500"
                            />
                            <Copy v-else class="mr-1.5 size-3.5" />
                            {{ copiedNewCodes ? 'Copied' : 'Copy' }}
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            @click="downloadCodes(newCodesList)"
                        >
                            <Download class="mr-1.5 size-3.5" />
                            Download
                        </Button>
                    </div>

                    <DialogFooter class="pt-2">
                        <Button
                            class="w-full"
                            @click="showReplaceModal = false"
                        >
                            Done
                        </Button>
                    </DialogFooter>
                </div>
            </DialogContent>
        </Dialog>

        <!-- MODAL: Regenerate Recovery Codes -->
        <Dialog
            :open="showRegenerateModal"
            @update:open="showRegenerateModal = $event"
        >
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Get new recovery codes</DialogTitle>
                    <DialogDescription>
                        Your old codes will stop working. You will get 10 new
                        ones.
                    </DialogDescription>
                </DialogHeader>

                <div
                    v-if="!newCodesList.length || showReplaceModal"
                    class="space-y-4 py-2"
                >
                    <Form
                        action="/user/two-factor-recovery-codes"
                        method="post"
                        #default="{ errors, processing }"
                    >
                        <div class="space-y-4">
                            <div class="grid gap-2">
                                <Label for="regen_password">Password</Label>
                                <PasswordInput
                                    id="regen_password"
                                    name="password"
                                    v-model="regenPassword"
                                    placeholder="Your password"
                                    required
                                />
                                <InputError :message="errors.password" />
                            </div>

                            <div class="grid gap-2">
                                <Label for="regen_code"
                                    >Code from your app</Label
                                >
                                <InputOTP
                                    id="regen_code"
                                    v-model="regenCode"
                                    :maxlength="6"
                                    :disabled="processing"
                                >
                                    <InputOTPGroup>
                                        <InputOTPSlot
                                            v-for="index in 6"
                                            :key="index"
                                            :index="index - 1"
                                        />
                                    </InputOTPGroup>
                                </InputOTP>
                                <input
                                    type="hidden"
                                    name="code"
                                    :value="regenCode"
                                />
                                <InputError :message="errors.code" />
                            </div>

                            <DialogFooter class="pt-4">
                                <Button
                                    type="button"
                                    variant="outline"
                                    @click="showRegenerateModal = false"
                                >
                                    Cancel
                                </Button>
                                <Button
                                    type="submit"
                                    variant="destructive"
                                    :disabled="
                                        processing ||
                                        !regenPassword ||
                                        regenCode.length < 6
                                    "
                                >
                                    Get new codes
                                </Button>
                            </DialogFooter>
                        </div>
                    </Form>
                </div>

                <div v-else class="space-y-4 py-2">
                    <p class="text-muted-foreground text-xs">
                        Save these codes now. You will not see them again.
                    </p>

                    <div
                        class="bg-muted grid grid-cols-2 gap-2 rounded-lg border p-3 font-mono text-xs"
                    >
                        <div
                            v-for="(code, idx) in newCodesList"
                            :key="idx"
                            class="bg-background rounded px-2 py-1"
                        >
                            {{ code }}
                        </div>
                    </div>

                    <div class="flex gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            @click="copyCodes(newCodesList)"
                        >
                            <Check
                                v-if="copiedNewCodes"
                                class="mr-1.5 size-3.5 text-green-500"
                            />
                            <Copy v-else class="mr-1.5 size-3.5" />
                            {{ copiedNewCodes ? 'Copied' : 'Copy' }}
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            @click="downloadCodes(newCodesList)"
                        >
                            <Download class="mr-1.5 size-3.5" />
                            Download
                        </Button>
                    </div>

                    <DialogFooter class="pt-2">
                        <Button
                            class="w-full"
                            @click="
                                showRegenerateModal = false;
                                newCodesList = [];
                            "
                        >
                            Done
                        </Button>
                    </DialogFooter>
                </div>
            </DialogContent>
        </Dialog>
    </div>
</template>
