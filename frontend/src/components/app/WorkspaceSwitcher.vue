<script setup lang="ts">
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useWorkspaceStore } from '@/stores/workspace'
import Button from '@/components/ui/Button.vue'
import DropdownMenu from '@/components/ui/DropdownMenu.vue'
import DropdownMenuItem from '@/components/ui/DropdownMenuItem.vue'
import DropdownMenuLabel from '@/components/ui/DropdownMenuLabel.vue'
import DropdownMenuSeparator from '@/components/ui/DropdownMenuSeparator.vue'
import { Check, ChevronsUpDown, Plus } from '@lucide/vue'

const store = useWorkspaceStore()
const router = useRouter()
const { t } = useI18n()
</script>

<template>
  <DropdownMenu>
    <template #trigger>
      <Button variant="outline" size="sm" class="max-w-[14rem] gap-2" :aria-label="t('workspace.switcher.label')">
        <span class="truncate">{{ store.current?.name ?? t('workspace.switcher.placeholder') }}</span>
        <ChevronsUpDown class="h-4 w-4 shrink-0 opacity-50" />
      </Button>
    </template>
    <DropdownMenuLabel>{{ t('workspace.switcher.heading') }}</DropdownMenuLabel>
    <DropdownMenuItem
      v-for="workspace in store.list" :key="workspace.id"
      @select="router.push({ name: 'workspace', params: { slug: workspace.slug } })"
    >
      <Check class="h-4 w-4" :class="workspace.slug === store.currentSlug ? 'opacity-100' : 'opacity-0'" />
      <span class="truncate">{{ workspace.name }}</span>
    </DropdownMenuItem>
    <DropdownMenuSeparator />
    <DropdownMenuItem @select="router.push({ name: 'workspace-new' })">
      <Plus class="h-4 w-4" />{{ t('workspace.switcher.create') }}
    </DropdownMenuItem>
  </DropdownMenu>
</template>
