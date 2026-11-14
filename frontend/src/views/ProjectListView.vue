<script setup lang="ts">
import { computed, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { FolderKanban, Plus } from '@lucide/vue'
import { useCanEdit } from '@/composables/useCanEdit'
import { useProjects } from '@/queries/workspace'
import { useWorkspaceStore } from '@/stores/workspace'
import EmptyState from '@/components/app/EmptyState.vue'
import ProjectCreateDialog from '@/components/app/ProjectCreateDialog.vue'
import Alert from '@/components/ui/Alert.vue'
import Button from '@/components/ui/Button.vue'
import Card from '@/components/ui/Card.vue'
import CardDescription from '@/components/ui/CardDescription.vue'
import CardHeader from '@/components/ui/CardHeader.vue'
import CardTitle from '@/components/ui/CardTitle.vue'
import Skeleton from '@/components/ui/Skeleton.vue'

const store = useWorkspaceStore()
const { t } = useI18n()
const canEdit = useCanEdit()
const projects = useProjects()
const createOpen = ref(false)

const list = computed(() => projects.data.value ?? [])
</script>

<template>
  <div class="mx-auto max-w-6xl space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="text-2xl font-semibold tracking-tight">{{ t('projects.title') }}</h1>
        <p class="text-sm text-muted-foreground">{{ t('projects.subtitle', { workspace: store.current?.name ?? '' }) }}</p>
      </div>
      <Button v-if="canEdit" size="sm" class="gap-2" @click="createOpen = true"><Plus class="h-4 w-4" />{{ t('projects.create.open') }}</Button>
    </div>

    <Alert v-if="projects.error.value" variant="destructive" :title="projects.error.value.message" />

    <div v-if="projects.status.value === 'pending'" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3" aria-busy="true">
      <Skeleton v-for="i in 3" :key="i" class="h-28 w-full" />
    </div>

    <EmptyState
      v-else-if="list.length === 0 && !projects.error.value" :icon="FolderKanban"
      :title="t('projects.emptyTitle')" :description="t('projects.emptyDescription')"
      :action-label="canEdit ? t('projects.create.open') : undefined" @action="createOpen = true"
    />

    <ul v-else class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
      <li v-for="project in list" :key="project.id">
        <RouterLink
          :to="{ name: 'items', params: { slug: store.currentSlug ?? '' }, query: { project: project.key } }"
          class="block h-full rounded-lg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
        >
          <Card class="h-full transition-colors hover:border-foreground/30">
            <CardHeader>
              <span class="font-mono text-xs font-semibold text-muted-foreground">{{ project.key }}</span>
              <CardTitle class="text-lg">{{ project.name }}</CardTitle>
              <CardDescription>{{ project.description || t('projects.noDescription') }}</CardDescription>
            </CardHeader>
          </Card>
        </RouterLink>
      </li>
    </ul>

    <ProjectCreateDialog v-model:open="createOpen" />
  </div>
</template>
