<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import BarcodeScannerModal from '@/Components/BarcodeScannerModal.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, reactive, computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { ArrowLeft, ScanLine, Save, Lock, Unlock, EyeOff, Search, CheckCircle2 } from 'lucide-vue-next';

const { t } = useI18n();

const props = defineProps({
    audit: Object,
    rounds: Array,
    activeRound: Object,
    items: Array,
    blind: Boolean,
    isAdmin: Boolean,
});

const search = ref('');
const scanning = ref(false);
const processing = ref(false);
const counts = reactive({});
const savingIds = reactive({});

// Seed local inputs from the server (only MY counts — the server never sends
// another counter's numbers when the count is blind).
const seed = () => {
    Object.keys(counts).forEach((k) => delete counts[k]);
    props.items.forEach((i) => {
        counts[i.id] = i.my_count;
    });
};
watch(() => props.items, seed, { immediate: true, deep: true });

const filteredItems = computed(() => {
    const term = search.value.trim().toLowerCase();
    if (!term) return props.items;
    return props.items.filter((i) =>
        `${i.name || ''} ${i.sku || ''} ${i.barcode || ''}`.toLowerCase().includes(term)
    );
});

const countedCount = computed(() => props.items.filter((i) => counts[i.id] !== null && counts[i.id] !== '' && counts[i.id] !== undefined).length);
const progress = computed(() => (props.items.length ? Math.round((countedCount.value / props.items.length) * 100) : 0));
const activeIsOpen = computed(() => props.activeRound?.status === 'open');
const activeIsMine = computed(() => (props.rounds || []).find((r) => r.round_number === props.activeRound?.round_number)?.is_mine);

const switchRound = (n) => {
    router.get(route('stock-audits.capture', { stockAudit: props.audit.id, round: n }));
};

const saveCount = async (item) => {
    const value = counts[item.id];
    if (value === null || value === '' || value === undefined) return;
    savingIds[item.id] = true;
    try {
        const res = await fetch(
            route('stock-audits.rounds.count', { stockAudit: props.audit.id, round: props.activeRound.round_number }),
            {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                },
                body: JSON.stringify({ item_id: item.id, counted_quantity: parseInt(value) }),
            }
        );
        const data = await res.json();
        if (!res.ok) alert(data.message || 'Failed to save count');
    } catch (e) {
        alert('An error occurred while saving the count');
    } finally {
        savingIds[item.id] = false;
    }
};

const onProductFound = (product) => {
    scanning.value = false;
    if (!product) return;
    search.value = product.sku || product.name || product.barcode || '';
};

const closeRound = () => {
    if (!confirm(`Close round ${props.activeRound.label}? Counts can still be reopened by an admin.`)) return;
    processing.value = true;
    router.post(
        route('stock-audits.rounds.close', { stockAudit: props.audit.id, round: props.activeRound.round_number }),
        {},
        { onFinish: () => { processing.value = false; }, onSuccess: () => router.reload() }
    );
};

const reopenRound = () => {
    processing.value = true;
    router.post(
        route('stock-audits.rounds.reopen', { stockAudit: props.audit.id, round: props.activeRound.round_number }),
        {},
        { onFinish: () => { processing.value = false; }, onSuccess: () => router.reload() }
    );
};
</script>

<template>
    <Head :title="`Count ${audit.audit_number}`" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <Link :href="route('stock-audits.show', audit.id)" class="text-text-tertiary hover:text-text-primary">Audit</Link>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">Count</span>
            </div>
        </template>

        <PageHeader :title="`Round ${activeRound.label}`" :description="`${audit.audit_number} — ${audit.name}`">
            <template #actions>
                <Badge v-if="blind" variant="info" size="sm">
                    <EyeOff :size="12" class="mr-1" /> Blind
                </Badge>
                <Badge :variant="activeIsOpen ? 'success' : 'neutral'" size="sm" dot>
                    {{ activeIsOpen ? 'Open' : 'Closed' }}
                </Badge>
                <Button variant="secondary" size="sm" as="Link" :href="route('stock-audits.show', audit.id)">
                    <ArrowLeft :size="14" /> Back
                </Button>
            </template>
        </PageHeader>

        <!-- Round switcher -->
        <div class="mt-6 flex flex-wrap gap-2">
            <button
                v-for="r in rounds"
                :key="r.round_number"
                @click="switchRound(r.round_number)"
                class="rounded-lg border px-3 py-1.5 text-sm transition-colors ds-focus-ring"
                :class="r.round_number === activeRound.round_number
                    ? 'border-brand bg-brand-soft font-medium text-brand'
                    : 'border-border-subtle bg-surface-raised text-text-secondary hover:border-border-strong'"
            >
                {{ r.label }}
                <span v-if="r.is_tiebreak" class="ml-1 text-xs">(tiebreak)</span>
            </button>
        </div>

        <!-- Progress -->
        <div class="mt-4 rounded-lg border border-border-subtle bg-surface-raised p-4">
            <div class="flex items-center justify-between text-sm">
                <span class="text-text-secondary">Counted by me</span>
                <span class="font-semibold tabular-nums text-text-primary">{{ countedCount }} / {{ items.length }}</span>
            </div>
            <div class="mt-2 h-2 w-full rounded-full bg-surface-sunken">
                <div class="h-2 rounded-full bg-brand transition-all duration-300" :style="{ width: progress + '%' }"></div>
            </div>
        </div>

        <!-- Toolbar -->
        <div class="mt-4 flex flex-wrap items-center gap-2">
            <div class="relative flex-1 min-w-[12rem]">
                <Search :size="16" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-text-tertiary" />
                <input
                    v-model="search"
                    type="search"
                    placeholder="Search by name, SKU or barcode…"
                    class="h-10 w-full rounded-lg border border-border-subtle bg-surface-canvas pl-9 pr-3 text-sm text-text-primary ds-focus-ring"
                />
            </div>
            <Button variant="secondary" size="sm" @click="scanning = true">
                <ScanLine :size="16" /> Scan
            </Button>
            <Button v-if="activeIsOpen" variant="secondary" size="sm" :loading="processing" :disabled="processing" @click="closeRound">
                <Lock :size="14" /> Close round
            </Button>
            <Button v-else-if="isAdmin" variant="secondary" size="sm" :loading="processing" :disabled="processing" @click="reopenRound">
                <Unlock :size="14" /> Reopen
            </Button>
        </div>

        <!-- Items -->
        <Card :padded="false" class="mt-4">
            <div class="divide-y divide-border-subtle">
                <div
                    v-for="item in filteredItems"
                    :key="item.id"
                    class="flex items-center gap-3 px-4 py-3 sm:px-5"
                >
                    <div class="min-w-0 flex-1">
                        <div class="truncate text-sm font-medium text-text-primary">{{ item.name || `#${item.product_id}` }}</div>
                        <div class="truncate text-xs text-text-tertiary">
                            SKU: {{ item.sku || '—' }}
                            <span v-if="item.location"> · {{ item.location }}</span>
                        </div>
                    </div>
                    <input
                        v-model.number="counts[item.id]"
                        type="number"
                        min="0"
                        step="1"
                        :disabled="!activeIsOpen"
                        class="h-10 w-24 rounded-md border border-border-subtle bg-surface-canvas px-3 text-right text-sm text-text-primary ds-focus-ring disabled:opacity-50"
                        @keyup.enter="saveCount(item)"
                        @blur="saveCount(item)"
                    />
                    <Button
                        size="sm"
                        :disabled="!activeIsOpen || savingIds[item.id]"
                        :loading="savingIds[item.id]"
                        @click="saveCount(item)"
                    >
                        <Save :size="14" />
                    </Button>
                </div>
                <div v-if="filteredItems.length === 0" class="px-5 py-12 text-center">
                    <CheckCircle2 :size="24" class="mx-auto text-text-tertiary" />
                    <p class="mt-2 text-sm text-text-tertiary">No items match.</p>
                </div>
            </div>
        </Card>

        <p v-if="!activeIsOpen" class="mt-3 text-xs text-text-tertiary">
            This round is closed. An admin can reopen it if a correction is needed.
        </p>

        <BarcodeScannerModal :show="scanning" @close="scanning = false" @product-found="onProductFound" />
    </AppLayout>
</template>