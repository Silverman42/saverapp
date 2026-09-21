---
paths:
    - 'resources/js/pages/**'
---

# Pages

## Dashboard Page Layout, Breadcrumbs, and Header Standards

1. Base padding (px-4 py-6 sm:px-6 lg:px-8 lg:py-8) is managed centrally on the slot wrapper in AppSidebarLayout.vue. Individual dashboard/subpages and child layouts must not declare redundant outer horizontal or vertical padding.
2. Subpages must always define breadcrumbs in defineOptions layout starting with Dashboard: [{ title: 'Dashboard', href: dashboard() }, ...].
3. Dashboard page headers must follow the Lockouts standard: <h1 class="text-3xl font-semibold tracking-tight"> followed by <p class="text-muted-foreground mt-1.5 text-sm"> with an mt-1.5 top margin for consistent spacing.
