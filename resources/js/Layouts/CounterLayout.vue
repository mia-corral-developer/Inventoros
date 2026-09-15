<script setup>
import { Link, router, usePage } from '@inertiajs/vue3';
import { LogOut } from 'lucide-vue-next';

const props = defineProps({
    title: { type: String, default: 'Mis conteos' },
});

const page = usePage();
const user = page.props.auth?.user;

const logout = () => router.post(route('logout'));
</script>

<template>
    <!--
        Minimal, mobile-first shell for warehouse counters. Deliberately has NO
        sidebar, global search, notifications or workspace switcher — an
        operator only needs to see their pending counts and the form.
    -->
    <div class="min-h-screen bg-surface-base text-text-primary">
        <header class="sticky top-0 z-30 border-b border-border-subtle bg-surface-base/95 backdrop-blur">
            <div class="mx-auto flex h-14 max-w-2xl items-center gap-3 px-4">
                <Link :href="route('my-counts')" class="flex min-w-0 items-center gap-2">
                    <img
                        src="/images/brand/inventoros_icon_transparent_512.png"
                        alt="Inventoros"
                        class="h-6 w-6 shrink-0"
                    />
                    <span class="truncate text-sm font-semibold tracking-tight">{{ title }}</span>
                </Link>

                <div class="ml-auto flex items-center gap-2">
                    <span class="hidden max-w-[10rem] truncate text-xs text-text-tertiary sm:inline">
                        {{ user?.name }}
                    </span>
                    <button
                        @click="logout"
                        class="rounded-md p-2 text-text-secondary transition-colors hover:bg-surface-overlay ds-focus-ring"
                        aria-label="Salir"
                        title="Salir"
                    >
                        <LogOut :size="18" />
                    </button>
                </div>
            </div>
        </header>

        <main class="mx-auto max-w-2xl px-4 py-4 pb-24">
            <slot />
        </main>
    </div>
</template>