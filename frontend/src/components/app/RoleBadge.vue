<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { WorkspaceRole } from '@/api/types'
import Badge from '@/components/ui/Badge.vue'

const props = defineProps<{ role: WorkspaceRole }>()
const { t } = useI18n()

const variant = computed(() => {
  switch (props.role) {
    case 'owner': return 'default'
    case 'admin': return 'info'
    case 'member': return 'secondary'
    case 'guest': return 'outline'
  }
})
</script>

<template>
  <Badge :variant="variant">{{ t(`role.${role}`) }}</Badge>
</template>
