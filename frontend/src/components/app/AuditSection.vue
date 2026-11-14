<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { listWorkspaceAudit } from '@/api/operator'
import type { AuditEntry } from '@/api/types'
import { usePagedList } from '@/composables/usePagedList'
import { useSlug } from '@/queries/keys'
import AuditTable from '@/components/app/AuditTable.vue'
import Alert from '@/components/ui/Alert.vue'
import Button from '@/components/ui/Button.vue'
import Card from '@/components/ui/Card.vue'
import CardContent from '@/components/ui/CardContent.vue'
import CardDescription from '@/components/ui/CardDescription.vue'
import CardHeader from '@/components/ui/CardHeader.vue'
import CardTitle from '@/components/ui/CardTitle.vue'
import Select from '@/components/ui/Select.vue'
import Skeleton from '@/components/ui/Skeleton.vue'

/** The workspace's audit log in its settings: access, sharing, settings and deletions. */
const { t } = useI18n()
const slug = useSlug()
const area = ref('all')
const list = usePagedList<AuditEntry>((cursor) =>
  listWorkspaceAudit(slug.value, { action: area.value === 'all' ? undefined : `${area.value}.`, cursor }))

const areas = ['all', 'member', 'invitation', 'agent', 'token', 'webhook', 'project', 'workflow', 'page', 'item', 'ai', 'workspace']

function pick(value: string) {
  area.value = value
  void list.reload()
}

onMounted(() => list.reload())
</script>

<template>
  <Card>
    <CardHeader class="flex-row items-start justify-between gap-4 space-y-0">
      <div class="space-y-1.5">
        <CardTitle class="text-lg">{{ t('audit.title') }}</CardTitle>
        <CardDescription>{{ t('audit.description') }}</CardDescription>
      </div>
      <Select
        :model-value="area" class="w-44 shrink-0" :aria-label="t('audit.filter')"
        :options="areas.map((value) => ({ value, label: t(`audit.area.${value}`) }))"
        @update:model-value="pick"
      />
    </CardHeader>
    <CardContent class="space-y-4">
      <Alert v-if="list.error.value" variant="destructive" :title="list.error.value" />
      <Skeleton v-if="!list.loaded.value && list.loading.value" class="h-24 w-full" />
      <p v-else-if="list.loaded.value && list.items.value.length === 0" class="text-sm text-muted-foreground">{{ t('audit.empty') }}</p>
      <AuditTable v-else-if="list.items.value.length" :entries="list.items.value" />
      <Button v-if="list.hasMore()" variant="outline" size="sm" :disabled="list.loading.value" @click="list.loadMore()">
        {{ t('audit.more') }}
      </Button>
    </CardContent>
  </Card>
</template>
