<script setup>
import CounterLayout from '@/Layouts/CounterLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { ClipboardList, ChevronRight, CheckCircle2 } from 'lucide-vue-next';

defineProps({
    counts: { type: Array, default: () => [] },
});
</script>

<template>
    <Head title="Mis conteos" />

    <CounterLayout title="Mis conteos">
        <div v-if="counts.length === 0" class="mt-20 text-center">
            <CheckCircle2 :size="40" class="mx-auto text-status-success" />
            <p class="mt-3 text-sm font-medium text-text-primary">Nada pendiente</p>
            <p class="mt-1 text-xs text-text-tertiary">No tienes conteos asignados en este momento.</p>
        </div>

        <div v-else class="space-y-3">
            <p class="text-xs uppercase tracking-wider text-text-tertiary">
                {{ counts.length }} conteo{{ counts.length === 1 ? '' : 's' }} pendiente{{ counts.length === 1 ? '' : 's' }}
            </p>

            <Link
                v-for="c in counts"
                :key="c.url"
                :href="c.url"
                class="block rounded-xl border border-border-subtle bg-surface-raised p-4 transition-colors hover:border-border-strong ds-focus-ring"
            >
                <div class="flex items-center gap-3">
                    <div class="min-w-0 flex-1">
                        <div class="text-[10px] font-medium uppercase tracking-wider text-text-tertiary">
                            {{ c.audit_number }}
                        </div>
                        <div class="truncate text-sm font-semibold text-text-primary">{{ c.audit_name }}</div>
                        <div class="mt-1 text-xs text-text-secondary">
                            Ronda <b>{{ c.round_label }}</b>
                            <span v-if="c.is_tiebreak"> · desempate</span>
                            · {{ c.counted }} de {{ c.total }} contados
                        </div>
                    </div>
                    <ChevronRight :size="20" class="shrink-0 text-text-tertiary" />
                </div>

                <div class="mt-3 h-1.5 w-full rounded-full bg-surface-sunken">
                    <div class="h-1.5 rounded-full bg-brand transition-all" :style="{ width: c.progress + '%' }"></div>
                </div>
            </Link>
        </div>
    </CounterLayout>
</template>