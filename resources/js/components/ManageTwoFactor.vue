<script setup lang="ts">
import { Form, router, usePage } from '@inertiajs/vue3';
import { useClipboard } from '@vueuse/core';
import {
    AlertTriangle,
    Check,
    Copy,
    Download,
    KeyRound,
    Lock,
    RefreshCw,
    ShieldAlert,
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
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
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
    <div v-if="canManageTwoFactor" class="space-y-6">
        <Heading
            variant="small"
            title="Two-Factor Authentication"
            description="Manage your mandatory authenticator app and emergency recovery codes"
        />

        <!-- Authenticator Status Card -->
        <Card>
            <CardHeader class="flex flex-row items-center justify-between pb-2">
                <div class="space-y-1">
                    <CardTitle
                        class="flex items-center gap-2 text-base font-semibold"
                    >
                        <ShieldCheck
                            class="size-5 text-green-600 dark:text-green-400"
                        />
                        Authenticator App
                    </CardTitle>
                    <CardDescription>
                        Time-based One-Time Password (TOTP) is active for your
                        account.
                    </CardDescription>
                </div>
                <Badge
                    variant="outline"
                    class="border-green-600/30 bg-green-500/10 text-green-700 dark:text-green-400"
                >
                    Active
                </Badge>
            </CardHeader>
            <CardContent class="space-y-4 pt-2">
                <p class="text-muted-foreground text-xs leading-relaxed">
                    Two-factor authentication is mandatory for your role. You
                    are prompted for a 6-digit code during sign-in and sensitive
                    operations.
                </p>

                <div class="flex flex-wrap items-center gap-3">
                    <Button
                        variant="outline"
                        size="sm"
                        @click="showReplaceModal = true"
                    >
                        <RefreshCw class="mr-2 size-3.5" />
                        Replace Authenticator App
                    </Button>
                </div>
            </CardContent>
        </Card>

        <!-- Recovery Codes Card -->
        <Card>
            <CardHeader>
                <div class="flex flex-row items-center justify-between">
                    <div class="space-y-1">
                        <CardTitle
                            class="flex items-center gap-2 text-base font-semibold"
                        >
                            <KeyRound class="text-primary size-5" />
                            Emergency Recovery Codes
                        </CardTitle>
                        <CardDescription>
                            Use recovery codes if you lose access to your
                            authenticator app.
                        </CardDescription>
                    </div>
                    <span class="text-muted-foreground text-xs font-medium">
                        {{ remainingRecoveryCodes }} of 10 remaining
                    </span>
                </div>
            </CardHeader>
            <CardContent class="space-y-4">
                <!-- Warning when 2 or fewer remaining -->
                <Alert
                    v-if="isLowCodes"
                    variant="destructive"
                    class="border-amber-500/40 bg-amber-500/10 text-amber-950 dark:text-amber-200"
                >
                    <AlertTriangle
                        class="size-4 text-amber-600 dark:text-amber-400"
                    />
                    <AlertTitle class="text-sm font-semibold"
                        >Low recovery codes remaining</AlertTitle
                    >
                    <AlertDescription class="text-xs">
                        You have {{ remainingRecoveryCodes }} recovery code(s)
                        remaining. Regenerate a new set to ensure you do not
                        lose account access.
                    </AlertDescription>
                </Alert>

                <p class="text-muted-foreground text-xs leading-relaxed">
                    Recovery codes are stored exclusively as secure
                    cryptographic hashes and cannot be viewed again. Each code
                    can be used only once.
                </p>

                <div
                    class="flex flex-wrap items-center justify-between gap-3 pt-1"
                >
                    <Button
                        variant="secondary"
                        size="sm"
                        @click="showRegenerateModal = true"
                    >
                        <RefreshCw class="mr-2 size-3.5" />
                        Regenerate Recovery Codes
                    </Button>

                    <a
                        href="/assisted-recovery"
                        class="text-muted-foreground hover:text-foreground text-xs underline underline-offset-4"
                    >
                        Lost both authenticator and codes?
                    </a>
                </div>
            </CardContent>
        </Card>

        <!-- MODAL: Replace Authenticator -->
        <Dialog
            :open="showReplaceModal"
            @update:open="showReplaceModal = $event"
        >
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Replace Authenticator App</DialogTitle>
                    <DialogDescription>
                        {{
                            replaceStep === 'verify'
                                ? 'Verify your current credentials to configure a new authenticator.'
                                : replaceStep === 'scan'
                                  ? 'Scan the new code with your new authenticator app.'
                                  : 'Save your new emergency recovery codes.'
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
                                    >Current Password</Label
                                >
                                <PasswordInput
                                    id="replace_current_password"
                                    name="current_password"
                                    v-model="currentPassword"
                                    placeholder="Enter current password"
                                    required
                                />
                                <InputError
                                    :message="errors.current_password"
                                />
                            </div>

                            <div class="grid gap-2">
                                <Label for="replace_current_code"
                                    >Current Authenticator Code</Label
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
                                Manual Entry Key:
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
                                    >Enter 6-digit code from NEW
                                    authenticator</Label
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
                                    Cancel Replacement
                                </Button>
                                <Button
                                    type="submit"
                                    :disabled="processing || newCode.length < 6"
                                >
                                    Confirm Swap
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
                            {{ copiedNewCodes ? 'Copied' : 'Copy Codes' }}
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
                    <DialogTitle>Regenerate Recovery Codes</DialogTitle>
                    <DialogDescription>
                        This will immediately invalidate all existing recovery
                        codes and generate 10 new ones.
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
                                <Label for="regen_password"
                                    >Current Password</Label
                                >
                                <PasswordInput
                                    id="regen_password"
                                    name="password"
                                    v-model="regenPassword"
                                    placeholder="Enter your password"
                                    required
                                />
                                <InputError :message="errors.password" />
                            </div>

                            <div class="grid gap-2">
                                <Label for="regen_code"
                                    >Authenticator Code</Label
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
                                    Regenerate Codes
                                </Button>
                            </DialogFooter>
                        </div>
                    </Form>
                </div>

                <div v-else class="space-y-4 py-2">
                    <p class="text-muted-foreground text-xs">
                        Save these new recovery codes immediately. They will not
                        be displayed again.
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
                            {{ copiedNewCodes ? 'Copied' : 'Copy Codes' }}
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
