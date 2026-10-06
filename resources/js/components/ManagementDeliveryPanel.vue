<script setup lang="ts">
import { router, useHttp, usePage } from '@inertiajs/vue3';
import { onMounted, onUnmounted, ref, watch } from 'vue';
import { RefreshCw } from '@lucide/vue';
import MoreDetails from '@/components/MoreDetails.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { index as customerDelivery } from '@/routes/customers/delivery';
import { index as agentDelivery } from '@/routes/agents/delivery';

const props = defineProps<{
    subject: 'customer' | 'agent';
    reference: string;
}>();
type Delivery = {
    reference: string;
    channel: string;
    purpose: string;
    status: string;
    category: string | null;
    effective_at: string;
    attempt_count: number | null;
    last_attempt_at: string | null;
};
type Result = {
    data: Delivery[];
    current_page: number;
    last_page: number;
    total: number;
};
const result = ref<Result | null>(null);
const message = ref('');
const allowed = ref(false);
const loading = ref(true);
const request = useHttp({});
const page = usePage();
let sequence = 0;
let timer: ReturnType<typeof setInterval> | undefined;
function clear(): void {
    sequence++;
    result.value = null;
    message.value = 'We could not load messages. Refresh to try again.';
}
async function load(number = 1): Promise<void> {
    const current = ++sequence;
    loading.value = true;
    try {
        const url =
            props.subject === 'customer'
                ? customerDelivery.url(props.reference, {
                      query: { page: number },
                  })
                : agentDelivery.url(props.reference, {
                      query: { page: number },
                  });
        const response = (await request.get(url)) as Result;
        if (current !== sequence) return;
        result.value = response;
        allowed.value = true;
        message.value = '';
    } catch {
        if (current !== sequence) return;
        result.value = null;
        message.value =
            'Messages are not available right now, or your access has changed.';
        router.clearHistory();
    } finally {
        if (current === sequence) loading.value = false;
    }
}
watch(() => page.props.auth, clear, { deep: true });
watch(
    () => props.reference,
    () => {
        clear();
        void load();
    },
);
const remove = router.on('start', clear);
onMounted(() => {
    void load();
    timer = setInterval(() => {
        if (!document.hidden) void load(result.value?.current_page ?? 1);
    }, 5000);
});
onUnmounted(() => {
    sequence++;
    clearInterval(timer);
    remove();
});
function date(value: string): string {
    return new Date(value).toLocaleString('en-NG', {
        timeZone: 'Africa/Lagos',
    });
}
</script>

<template>
    <Card v-if="allowed" aria-label="Delivery diagnostics">
        <CardHeader
            class="flex flex-row flex-wrap items-start justify-between gap-3"
        >
            <div class="space-y-1.5">
                <CardTitle class="text-base">Messages sent</CardTitle>
                <CardDescription
                    >Emails and texts we tried to send. Sent does not always
                    mean read.</CardDescription
                >
            </div>
            <Button
                type="button"
                variant="ghost"
                size="sm"
                :disabled="loading"
                @click="load(result?.current_page ?? 1)"
                ><RefreshCw
                    class="size-4"
                    :class="loading ? 'animate-spin' : ''"
                />
                Refresh</Button
            >
        </CardHeader>
        <CardContent class="space-y-4">
            <p
                v-if="message"
                role="status"
                class="text-muted-foreground text-sm"
            >
                {{ message }}
            </p>
            <p
                v-else-if="!result && loading"
                role="status"
                class="text-muted-foreground text-sm"
            >
                Loading messages…
            </p>
            <p
                v-else-if="result?.data.length === 0"
                class="text-muted-foreground text-sm"
            >
                No messages sent yet.
            </p>
            <MoreDetails
                v-else-if="result"
                :label="`Show ${result.total} message${result.total === 1 ? '' : 's'}`"
            >
                <ul class="divide-y" aria-label="Delivery records">
                    <li
                        v-for="item in result.data"
                        :key="item.reference"
                        class="space-y-1 py-3 text-sm"
                    >
                        <div
                            class="flex flex-wrap items-center justify-between gap-2"
                        >
                            <span class="font-medium capitalize"
                                >{{ item.purpose.replaceAll('_', ' ') }} ·
                                {{ item.channel }}</span
                            ><Badge variant="outline" class="capitalize">{{
                                item.status.replaceAll('_', ' ')
                            }}</Badge>
                        </div>
                        <p class="text-muted-foreground">
                            {{ date(item.effective_at)
                            }}<span v-if="item.attempt_count !== null">
                                · {{ item.attempt_count }}
                                {{
                                    item.attempt_count === 1 ? 'try' : 'tries'
                                }}</span
                            ><span v-if="item.last_attempt_at">
                                · last try
                                {{ date(item.last_attempt_at) }}</span
                            >
                        </p>
                        <p
                            v-if="item.category"
                            class="text-muted-foreground capitalize"
                        >
                            {{ item.category.replaceAll('_', ' ') }}
                        </p>
                        <p class="text-muted-foreground text-xs break-all">
                            Ref: {{ item.reference }}
                        </p>
                    </li>
                </ul>
                <div
                    v-if="result.last_page > 1"
                    class="flex flex-wrap items-center gap-3 pt-2"
                >
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        :disabled="loading || result.current_page <= 1"
                        @click="load(result.current_page - 1)"
                        >Previous</Button
                    ><span class="text-muted-foreground text-sm"
                        >Page {{ result.current_page }} of
                        {{ result.last_page }}</span
                    ><Button
                        type="button"
                        variant="outline"
                        size="sm"
                        :disabled="
                            loading || result.current_page >= result.last_page
                        "
                        @click="load(result.current_page + 1)"
                        >Next</Button
                    >
                </div>
            </MoreDetails>
        </CardContent>
    </Card>
</template>
