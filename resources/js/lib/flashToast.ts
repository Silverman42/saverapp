import { router } from "@inertiajs/vue3";
import { markRaw } from "vue";
import { toast } from "vue-sonner";
import AppToast from "@/components/AppToast.vue";
import type { FlashToast } from "@/types/ui";

const appToast = markRaw(AppToast);

export function showToast(data: FlashToast): void {
    const toastId = crypto.randomUUID();

    toast.custom(appToast, {
        id: toastId,
        componentProps: { ...data, toastId },
        duration: data.type === "error" ? 8000 : 5000,
    });
}

export function initializeFlashToast(): void {
    router.on("flash", (event) => {
        const flash = (event as CustomEvent).detail?.flash;
        const data = flash?.toast as FlashToast | undefined;

        if (!data) {
            return;
        }

        showToast(data);
    });
}
