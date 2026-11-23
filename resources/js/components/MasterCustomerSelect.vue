<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import { Loader2, Search, X } from '@lucide/vue';
import { ref, watch } from 'vue';
import {
    Combobox,
    ComboboxAnchor,
    ComboboxEmpty,
    ComboboxInput,
    ComboboxItem,
    ComboboxList,
} from '@/components/ui/combobox';
import { masterCandidates } from '@/routes/customers';

export type MasterCustomerOption = {
    id: string;
    name: string;
    phone: string;
};

const props = withDefaults(
    defineProps<{
        id?: string;
        initial?: MasterCustomerOption | null;
        exclude?: string | null;
        disabled?: boolean;
        invalid?: boolean;
    }>(),
    {
        id: 'master-customer',
        initial: null,
        exclude: null,
        disabled: false,
        invalid: false,
    },
);

const model = defineModel<string | null>({ default: null });

const selected = ref<MasterCustomerOption | null>(props.initial);
const options = ref<MasterCustomerOption[]>(
    props.initial ? [props.initial] : [],
);
const searchTerm = ref('');
const loaded = ref(false);
const failed = ref(false);
const lookup = useHttp({});

watch(selected, (option) => {
    model.value = option?.id ?? null;
});

async function loadCandidates(search: string): Promise<void> {
    failed.value = false;

    try {
        const response = (await lookup.get(
            masterCandidates.url({
                query: {
                    search: search || undefined,
                    exclude: props.exclude || undefined,
                },
            }),
        )) as { data: MasterCustomerOption[] };
        options.value = response.data;
        loaded.value = true;
    } catch {
        failed.value = true;
    }
}

const debouncedLoad = useDebounceFn(loadCandidates, 300);

function onOpenChange(open: boolean): void {
    if (open && !loaded.value) {
        void loadCandidates(searchTerm.value);
    }
}

function onSearch(value: string): void {
    searchTerm.value = value;
    void debouncedLoad(value.trim());
}

function clear(): void {
    selected.value = null;
    searchTerm.value = '';
}

const displayValue = (option: MasterCustomerOption | null): string =>
    option ? `${option.name} · ${option.id}` : '';
</script>

<template>
    <Combobox
        v-model="selected"
        by="id"
        ignore-filter
        open-on-focus
        open-on-click
        :disabled="disabled"
        @update:open="onOpenChange"
    >
        <ComboboxAnchor :aria-invalid="invalid || undefined">
            <Search />
            <ComboboxInput
                :id="id"
                :display-value="displayValue"
                placeholder="Search by name, phone or customer ID"
                autocomplete="off"
                @update:model-value="onSearch"
            />
            <Loader2
                v-if="lookup.processing"
                class="animate-spin"
                aria-hidden="true"
            />
            <button
                v-else-if="selected && !disabled"
                type="button"
                class="text-muted-foreground hover:text-foreground"
                aria-label="Clear master account"
                @click="clear"
            >
                <X class="size-4" />
            </button>
        </ComboboxAnchor>

        <ComboboxList>
            <ComboboxEmpty>
                {{
                    failed
                        ? 'We could not load your customers. Try again.'
                        : lookup.processing
                          ? 'Searching…'
                          : 'No master accounts match your search.'
                }}
            </ComboboxEmpty>
            <ComboboxItem
                v-for="option in options"
                :key="option.id"
                :value="option"
            >
                <div class="min-w-0">
                    <p class="truncate font-medium">{{ option.name }}</p>
                    <p class="text-muted-foreground truncate text-xs">
                        {{ option.id }} · {{ option.phone }}
                    </p>
                </div>
            </ComboboxItem>
        </ComboboxList>
    </Combobox>
</template>
