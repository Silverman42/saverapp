<script setup lang="ts">
import { Head, usePage } from "@inertiajs/vue3";
import { ChevronDown, Link2, Search } from "@lucide/vue";
import { computed, nextTick, onMounted, ref } from "vue";
import EmptyState from "@/components/EmptyState.vue";
import PageHeader from "@/components/PageHeader.vue";
import { Badge } from "@/components/ui/badge";
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from "@/components/ui/collapsible";
import { Input } from "@/components/ui/input";
import { Tabs, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { helpSections, permissionLabels } from "@/lib/help/content";
import type { HelpAudience, HelpSection } from "@/lib/help/content";
import { dashboard, help } from "@/routes";

defineOptions({
    layout: {
        breadcrumbs: [
            { title: "Dashboard", href: dashboard() },
            { title: "Help", href: help() },
        ],
    },
});

const page = usePage();
const isAdmin = computed(() => page.props.auth?.user?.user_type === "admin");

const guide = ref<HelpAudience>(isAdmin.value ? "admin" : "agent");
const search = ref("");
const openArticles = ref<Set<string>>(new Set());

const guideTabs: { value: HelpAudience; label: string }[] = [
    { value: "admin", label: "Admin guide" },
    { value: "agent", label: "Agent guide" },
];

const searchTerm = computed(() => search.value.trim().toLowerCase());

const visibleSections = computed<HelpSection[]>(() => {
    const sections = helpSections.filter(
        (section) => section.audience === guide.value,
    );

    if (searchTerm.value === "") {
        return sections;
    }

    return sections
        .map((section) => ({
            ...section,
            articles: section.articles.filter((article) =>
                [article.question, ...article.answer]
                    .join(" ")
                    .toLowerCase()
                    .includes(searchTerm.value),
            ),
        }))
        .filter((section) => section.articles.length > 0);
});

function isArticleOpen(id: string): boolean {
    return searchTerm.value !== "" || openArticles.value.has(id);
}

function setArticleOpen(id: string, open: boolean): void {
    const next = new Set(openArticles.value);

    if (open) {
        next.add(id);
    } else {
        next.delete(id);
    }

    openArticles.value = next;
}

function articleUrl(id: string): string {
    return `${window.location.pathname}#${id}`;
}

function openFromHash(): void {
    const id = window.location.hash.slice(1);

    if (id === "") {
        return;
    }

    const section = helpSections.find(
        (candidate) =>
            candidate.id === id ||
            candidate.articles.some((article) => article.id === id),
    );

    if (!section || (section.audience === "admin" && !isAdmin.value)) {
        return;
    }

    guide.value = section.audience;

    if (section.id !== id) {
        setArticleOpen(id, true);
    }

    nextTick(() => {
        document.getElementById(id)?.scrollIntoView({ block: "start" });
    });
}

onMounted(() => {
    openFromHash();
    window.addEventListener("hashchange", openFromHash);
});
</script>

<template>
    <Head title="Help" />

    <div class="flex flex-col gap-6">
        <PageHeader
            title="Help & FAQ"
            :description="
                isAdmin
                    ? 'How every feature works, for Admins and the Agents you support.'
                    : 'How every feature works, in plain steps.'
            "
        />

        <div class="flex flex-row flex-wrap gap-4">
            <div class="relative w-fit">
                <Search
                    class="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2"
                />
                <Input
                    v-model="search"
                    type="search"
                    placeholder="Search questions"
                    aria-label="Search help"
                    class="w-72 max-w-full pl-9"
                />
            </div>
            <Tabs
                v-if="isAdmin"
                :model-value="guide"
                class="w-fit"
                @update:model-value="guide = $event as HelpAudience"
            >
                <TabsList aria-label="Guide" class="h-11">
                    <TabsTrigger
                        v-for="tab in guideTabs"
                        :key="tab.value"
                        :value="tab.value"
                        class="px-3"
                    >
                        {{ tab.label }}
                    </TabsTrigger>
                </TabsList>
            </Tabs>
        </div>

        <EmptyState
            v-if="visibleSections.length === 0"
            :icon="Search"
            title="No answers found"
            description="Try a different word, or switch guides."
        />

        <div
            v-else
            class="grid gap-8 lg:grid-cols-[220px_minmax(0,1fr)] lg:items-start"
        >
            <nav
                aria-label="Help sections"
                class="hidden lg:sticky lg:top-6 lg:flex lg:flex-col lg:gap-1"
            >
                <a
                    v-for="section in visibleSections"
                    :key="section.id"
                    :href="`#${section.id}`"
                    class="text-muted-foreground hover:text-foreground hover:bg-accent rounded-lg px-3 py-1.5 text-sm transition-colors"
                >
                    {{ section.title }}
                </a>
            </nav>

            <div class="flex min-w-0 flex-col gap-8">
                <section
                    v-for="section in visibleSections"
                    :id="section.id"
                    :key="section.id"
                    class="scroll-mt-6"
                >
                    <h2 class="text-lg font-medium tracking-tight">
                        {{ section.title }}
                    </h2>
                    <p class="text-muted-foreground mt-1 text-sm">
                        {{ section.summary }}
                    </p>

                    <div class="bg-card mt-4 divide-y rounded-2xl border">
                        <Collapsible
                            v-for="article in section.articles"
                            :id="article.id"
                            :key="article.id"
                            :open="isArticleOpen(article.id)"
                            class="scroll-mt-6"
                            @update:open="setArticleOpen(article.id, $event)"
                        >
                            <CollapsibleTrigger
                                class="hover:bg-accent/50 flex w-full items-center justify-between gap-4 px-4 py-3.5 text-left text-sm font-medium transition-colors first:rounded-t-2xl"
                            >
                                <span>{{ article.question }}</span>
                                <ChevronDown
                                    class="text-muted-foreground size-4 shrink-0 transition-transform"
                                    :class="
                                        isArticleOpen(article.id)
                                            ? 'rotate-180'
                                            : ''
                                    "
                                />
                            </CollapsibleTrigger>
                            <CollapsibleContent class="px-4 pb-4">
                                <ul
                                    class="text-muted-foreground list-disc space-y-1.5 pl-5 text-sm"
                                >
                                    <li
                                        v-for="(line, index) in article.answer"
                                        :key="index"
                                    >
                                        {{ line }}
                                    </li>
                                </ul>
                                <div
                                    class="mt-3 flex flex-wrap items-center gap-3"
                                >
                                    <Badge
                                        v-if="article.permission"
                                        variant="secondary"
                                    >
                                        Needs:
                                        {{
                                            permissionLabels[article.permission]
                                        }}
                                    </Badge>
                                    <a
                                        :href="articleUrl(article.id)"
                                        class="text-muted-foreground hover:text-foreground inline-flex items-center gap-1 text-xs"
                                    >
                                        <Link2 class="size-3.5" />
                                        Link to this answer
                                    </a>
                                </div>
                            </CollapsibleContent>
                        </Collapsible>
                    </div>
                </section>
            </div>
        </div>
    </div>
</template>
