<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { listAudit } from '@/api/operator'
import type { AuditEntry } from '@/api/types'
import { usePagedList } from '@/composables/usePagedList'
import AuditTable from '@/components/app/AuditTable.vue'
import Alert from '@/components/ui/Alert.vue'
import Button from '@/components/ui/Button.vue'
import Input from '@/components/ui/Input.vue'
import Skeleton from '@/components/ui/Skeleton.vue'

/** The audit log of the whole installation, sign-ins and operator actions included. */
const { t } = useI18n()
const workspace = ref('')
const action = ref('')
const list = usePagedList<AuditEntry>((cursor) => listAudit({ workspace: workspace.value.trim(), action: action.value.trim(), cursor }))

onMounted(() => list.reload())
</script>

<template>
  <div class="space-y-4">
    <h1 class="text-2xl font-semibold tracking-tight">{{ t('admin.audit.title') }}</h1>
    <form class="flex flex-wrap items-center gap-2" role="search" @submit.prevent="list.reload()">
      <Input v-model="workspace" class="w-56" :placeholder="t('admin.audit.workspace')" :aria-label="t('admin.audit.workspace')" />
      <Input v-model="action" class="w-56" :placeholder="t('admin.audit.action')" :aria-label="t('admin.audit.action')" />
      <Button type="submit" variant="outline">{{ t('admin.search') }}</Button>
    </form>
    <Alert v-if="list.error.value" variant="destructive" :title="list.error.value" />
    <Skeleton v-if="!list.loaded.value && list.loading.value" class="h-40 w-full" />
    <p v-else-if="list.loaded.value && list.items.value.length === 0" class="text-sm text-muted-foreground">{{ t('audit.empty') }}</p>
    <AuditTable v-else-if="list.items.value.length" :entries="list.items.value" operator />
    <Button v-if="list.hasMore()" variant="outline" size="sm" :disabled="list.loading.value" @click="list.loadMore()">{{ t('audit.more') }}</Button>
  </div>
</template>
