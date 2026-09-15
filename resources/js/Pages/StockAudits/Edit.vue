<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Card from '@/Components/ui/Card.vue';
import Button from '@/Components/ui/Button.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { ArrowLeft, Eye, Users, EyeOff } from 'lucide-vue-next';

const { t } = useI18n();

const props = defineProps({
    audit: Object,
    locations: Array,
    users: Array,
    assignments: Object,
    auditTypes: Object,
});

const form = useForm({
    name: props.audit.name || '',
    description: props.audit.description || '',
    audit_type: props.audit.audit_type || 'cycle',
    warehouse_location_id: props.audit.warehouse_location_id || '',
    notes: props.audit.notes || '',
    rounds_total: props.audit.rounds_total || 1,
    blind_count: !!props.audit.blind_count,
    assignments: { ...(props.assignments || {}) },
});

// Round numbers 1..rounds_total, used to render one counter selector per round.
const roundNumbers = computed(() =>
    Array.from({ length: Math.max(1, Number(form.rounds_total) || 1) }, (_, i) => i + 1)
);

// Keep `assignments` in sync with the chosen number of rounds.
watch(
    () => form.rounds_total,
    (n) => {
        const total = Math.max(1, Number(n) || 1);
        const next = {};
        for (let i = 1; i <= total; i++) next[i] = form.assignments[i] ?? '';
        form.assignments = next;
    },
    { immediate: true }
);

const submit = () => {
    form.put(route('stock-audits.update', props.audit.id));
};

const fieldLabel = 'mb-1 block text-sm font-medium text-text-secondary';
const fieldInput = 'h-9 w-full rounded-md border border-border-subtle bg-surface-canvas px-3 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring';
const fieldArea = 'w-full rounded-md border border-border-subtle bg-surface-canvas px-3 py-2 text-sm text-text-primary placeholder:text-text-tertiary ds-focus-ring';
const fieldError = 'mt-1 text-xs text-status-danger';
const fieldHint = 'mt-1 text-xs text-text-tertiary';
const fieldCheckbox = 'h-4 w-4 rounded border-border-subtle text-brand ds-focus-ring';
</script>

<template>
    <Head title="Edit Stock Audit" />

    <AppLayout>
        <template #header>
            <div class="flex items-center gap-2 text-xs">
                <Link :href="route('stock-audits.index')" class="text-text-tertiary hover:text-text-primary">Workspace</Link>
                <span class="text-text-tertiary">/</span>
                <Link :href="route('stock-audits.index')" class="text-text-tertiary hover:text-text-primary">Stock Audits</Link>
                <span class="text-text-tertiary">/</span>
                <span class="font-medium text-text-primary">Edit ({{ audit.audit_number }})</span>
            </div>
        </template>

        <PageHeader title="Edit Stock Audit" :description="`${audit.audit_number} - ${audit.name}`">
            <template #actions>
                <Button variant="secondary" size="sm" as="Link" :href="route('stock-audits.show', audit.id)">
                    <ArrowLeft :size="14" />
                    Back to Audit
                </Button>
                <Button variant="secondary" size="sm" as="Link" :href="route('stock-audits.show', audit.id)">
                    <Eye :size="14" />
                    View
                </Button>
            </template>
        </PageHeader>

        <form @submit.prevent="submit" class="mt-6 max-w-3xl">
            <Card :padded="false">
                <div class="px-5 pt-5"><h3 class="text-sm font-semibold text-text-primary">Audit Details</h3></div>
                <div class="space-y-4 p-5">
                    <!-- Name -->
                    <div>
                        <label for="name" :class="fieldLabel">Audit Name <span class="text-status-danger">*</span></label>
                        <input
                            id="name"
                            v-model="form.name"
                            type="text"
                            required
                            maxlength="255"
                            :class="fieldInput"
                        />
                        <p v-if="form.errors.name" :class="fieldError">{{ form.errors.name }}</p>
                    </div>

                    <!-- Description -->
                    <div>
                        <label for="description" :class="fieldLabel">Description</label>
                        <textarea
                            id="description"
                            v-model="form.description"
                            rows="3"
                            maxlength="1000"
                            :class="fieldArea"
                            placeholder="Describe the purpose of this audit..."
                        ></textarea>
                        <p v-if="form.errors.description" :class="fieldError">{{ form.errors.description }}</p>
                    </div>

                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <!-- Audit Type -->
                        <div>
                            <label for="audit_type" :class="fieldLabel">Audit Type <span class="text-status-danger">*</span></label>
                            <select
                                id="audit_type"
                                v-model="form.audit_type"
                                required
                                :class="fieldInput"
                            >
                                <option v-for="(label, value) in auditTypes" :key="value" :value="value">{{ label }}</option>
                            </select>
                            <p v-if="form.errors.audit_type" :class="fieldError">{{ form.errors.audit_type }}</p>
                        </div>

                        <!-- Location -->
                        <div>
                            <label for="warehouse_location_id" :class="fieldLabel">Warehouse Location</label>
                            <select
                                id="warehouse_location_id"
                                v-model="form.warehouse_location_id"
                                :class="fieldInput"
                            >
                                <option value="">All Locations</option>
                                <option v-for="location in locations" :key="location.id" :value="location.id">
                                    {{ location.name }}
                                    <span v-if="location.code">({{ location.code }})</span>
                                </option>
                            </select>
                            <p v-if="form.errors.warehouse_location_id" :class="fieldError">{{ form.errors.warehouse_location_id }}</p>
                        </div>
                    </div>

                    <!-- Notes -->
                    <div>
                        <label for="notes" :class="fieldLabel">Notes</label>
                        <textarea
                            id="notes"
                            v-model="form.notes"
                            rows="3"
                            maxlength="2000"
                            :class="fieldArea"
                            placeholder="Additional notes or instructions..."
                        ></textarea>
                        <p v-if="form.errors.notes" :class="fieldError">{{ form.errors.notes }}</p>
                    </div>
                </div>

                <!-- Counting Rounds (multi-count / blind count) -->
                <div class="space-y-4 border-t border-border-subtle p-5">
                    <h3 class="flex items-center gap-2 text-sm font-semibold text-text-primary">
                        <Users :size="16" />
                        Counting Rounds
                    </h3>
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <div>
                            <label for="rounds_total" :class="fieldLabel">Number of counting rounds</label>
                            <input
                                id="rounds_total"
                                v-model.number="form.rounds_total"
                                type="number"
                                min="1"
                                max="10"
                                step="1"
                                :class="fieldInput"
                            />
                            <p :class="fieldHint">1 = single count (default). 2 = blind count + tiebreak. Up to 10.</p>
                            <p v-if="form.errors.rounds_total" :class="fieldError">{{ form.errors.rounds_total }}</p>
                        </div>
                        <div>
                            <label :class="fieldLabel">Counting mode</label>
                            <label class="mt-2 flex items-center gap-2">
                                <input type="checkbox" v-model="form.blind_count" :class="fieldCheckbox" />
                                <span class="flex items-center gap-1 text-sm text-text-secondary">
                                    <component :is="form.blind_count ? EyeOff : Eye" :size="14" />
                                    Blind count
                                </span>
                            </label>
                            <p v-if="form.errors.blind_count" :class="fieldError">{{ form.errors.blind_count }}</p>
                        </div>
                    </div>

                    <div v-if="form.rounds_total > 1">
                        <p :class="fieldLabel">Assign a counter to each round (optional)</p>
                        <div class="space-y-2">
                            <div v-for="n in roundNumbers" :key="n" class="flex items-center gap-3">
                                <span class="w-10 text-sm font-medium text-text-secondary">C{{ n }}</span>
                                <select v-model="form.assignments[n]" :class="fieldInput">
                                    <option value="">Any counter</option>
                                    <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
                                </select>
                            </div>
                        </div>
                        <p v-if="form.errors.assignments" :class="fieldError">{{ form.errors.assignments }}</p>
                    </div>
                </div>

                <!-- Actions -->
                <div class="flex justify-end gap-3 border-t border-border-subtle p-5">
                    <Button variant="secondary" as="Link" :href="route('stock-audits.show', audit.id)">Cancel</Button>
                    <Button type="submit" variant="default" :loading="form.processing" :disabled="form.processing || !form.name">
                        {{ form.processing ? 'Saving...' : 'Update Audit' }}
                    </Button>
                </div>
            </Card>
        </form>
    </AppLayout>
</template>
