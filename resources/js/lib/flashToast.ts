import { router } from "@inertiajs/vue3";
import type { Visit } from "@inertiajs/core";
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

/**
 * Describes a failed HTTP response in words a user can act on.
 */
function describeHttpException(status: number): FlashToast {
    if (status === 403) {
        return {
            type: "error",
            title: "Action not allowed",
            description: "You do not have permission to do that.",
        };
    }

    if (status === 404) {
        return {
            type: "error",
            title: "Not found",
            description: "The record may have been moved or removed.",
        };
    }

    if (status === 419) {
        return {
            type: "error",
            title: "Page expired",
            description: "Refresh the page and try again.",
        };
    }

    if (status === 429) {
        return {
            type: "error",
            title: "Too many attempts",
            description: "Wait a moment and try again.",
        };
    }

    return {
        type: "error",
        title: "Something went wrong",
        description: "The request could not be completed. Try again.",
    };
}

/**
 * Shows server-flashed toasts and turns failed user-initiated visits
 * (validation errors, HTTP exceptions, network failures) into error toasts.
 * Background visits such as polls and prefetches stay silent.
 */
export function initializeFlashToast(): void {
    let userVisit: Visit | null = null;
    let pendingError: FlashToast | null = null;
    let flashedError = false;

    router.on("start", (event) => {
        const visit = event.detail.visit;

        if (visit.async || visit.prefetch) {
            return;
        }

        userVisit = visit;
        pendingError = null;
        flashedError = false;
    });

    router.on("flash", (event) => {
        const flash = (event as CustomEvent).detail?.flash;
        const data = flash?.toast as FlashToast | undefined;

        if (!data) {
            return;
        }

        if (data.type === "error") {
            flashedError = true;
        }

        showToast(data);
    });

    router.on("error", (event) => {
        if (!userVisit) {
            return;
        }

        const messages = Object.values(event.detail.errors).filter(Boolean);

        pendingError = {
            type: "error",
            title: "Please check the form",
            description:
                messages.length > 1
                    ? `${messages[0]} (+${messages.length - 1} more)`
                    : messages[0],
        };
    });

    router.on("httpException", (event) => {
        if (!userVisit) {
            return;
        }

        pendingError = describeHttpException(event.detail.response.status);
    });

    router.on("networkError", () => {
        if (!userVisit) {
            return;
        }

        pendingError = {
            type: "error",
            title: "Connection problem",
            description: "Check your internet connection and try again.",
        };
    });

    router.on("finish", (event) => {
        if (event.detail.visit !== userVisit) {
            return;
        }

        if (pendingError && !flashedError) {
            showToast(pendingError);
        }

        userVisit = null;
        pendingError = null;
        flashedError = false;
    });
}
