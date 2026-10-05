<script setup lang="ts">
import { router, useHttp, usePage } from '@inertiajs/vue3';
import { onMounted, onUnmounted, ref, watch } from 'vue';
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
    message.value =
        'Delivery access could not be verified. Refresh to continue.';
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
            'Delivery information is unavailable or your access has changed.';
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
        <CardHeader>
            <CardTitle>Delivery information</CardTitle>
            <CardDescription
                >Delivery is separate from the recorded change. An accepted
                email does not show that the person received or read
                it.</CardDescription
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
                Checking delivery information…
            </p>
            <p
                v-else-if="result?.data.length === 0"
                class="text-muted-foreground text-sm"
            >
                No delivery records are available.
            </p>
            <ul
                v-else-if="result"
                class="divide-y"
                aria-label="Delivery records"
            >
                <li
                    v-for="item in result.data"
                    :key="item.reference"
                    class="space-y-1 py-3 text-sm"
                >
                    <div
                        class="flex flex-wrap items-center justify-between gap-2"
                    >
                        <span class="font-medium"
                            >{{ item.purpose.replaceAll('_', ' ') }} ·
                            {{ item.channel }}</span
                        ><span>{{ item.status }}</span>
                    </div>
                    <p class="text-muted-foreground break-all">
                        {{ item.reference }}
                    </p>
                    <p class="text-muted-foreground">
                        {{ date(item.effective_at)
                        }}<span v-if="item.attempt_count !== null">
                            · {{ item.attempt_count }} recorded attempts</span
                        >
                    </p>
                    <p
                        v-if="item.last_attempt_at"
                        class="text-muted-foreground"
                    >
                        Last attempt: {{ date(item.last_attempt_at) }}
                    </p>
                    <p v-if="item.category" class="text-muted-foreground">
                        {{ item.category.replaceAll('_', ' ') }}
                    </p>
                </li>
            </ul>
            <div class="flex flex-wrap items-center gap-3">
                <Button
                    type="button"
                    variant="outline"
                    :disabled="loading"
                    @click="load(result?.current_page ?? 1)"
                    >Refresh delivery information</Button
                >
                <template v-if="result && result.last_page > 1"
                    ><Button
                        type="button"
                        variant="outline"
                        :disabled="loading || result.current_page <= 1"
                        @click="load(result.current_page - 1)"
                        >Previous</Button
                    ><span class="text-sm"
                        >Page {{ result.current_page }} of
                        {{ result.last_page }}</span
                    ><Button
                        type="button"
                        variant="outline"
                        :disabled="
                            loading || result.current_page >= result.last_page
                        "
                        @click="load(result.current_page + 1)"
                        >Next</Button
                    ></template
                >
            </div>
        </CardContent>
    </Card>
</template>
