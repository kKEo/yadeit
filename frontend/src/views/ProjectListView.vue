<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import * as api from '@/api/client'
import type { Project } from '@/api/types'
import { useApiForm } from '@/composables/useApiForm'
import { useWorkspaceStore } from '@/stores/workspace'
import Alert from '@/components/ui/Alert.vue'
import Card from '@/components/ui/Card.vue'
import CardHeader from '@/components/ui/CardHeader.vue'
import CardTitle from '@/components/ui/CardTitle.vue'
import CardDescription from '@/components/ui/CardDescription.vue'
import Skeleton from '@/components/ui/Skeleton.vue'
import { FolderKanban } from '@lucide/vue'

const store = useWorkspaceStore()
const { t } = useI18n()
const { error, submit } = useApiForm()

const projects = ref<Project[]>([])
const loading = ref(true)

onMounted(async () => {
  await submit(async () => {
    projects.value = await api.listProjects(store.requireSlug())
  })
  loading.value = false
})
</script>

<template>
  <div class="space-y-4">
    <div>
      <h1 class="text-2xl font-semibold tracking-tight">{{ t('projects.title') }}</h1>
      <p class="text-sm text-muted-foreground">{{ t('projects.subtitle', { workspace: store.current?.name ?? '' }) }}</p>
    </div>

    <Alert v-if="error" variant="destructive" :title="error" />

    <div v-if="loading" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3" aria-busy="true">
      <Skeleton v-for="i in 3" :key="i" class="h-28 w-full" />
    </div>

    <div v-else-if="projects.length === 0 && !error" class="rounded-lg border border-dashed px-6 py-12 text-center">
      <FolderKanban class="mx-auto h-8 w-8 text-muted-foreground" />
      <h2 class="mt-3 font-medium">{{ t('projects.emptyTitle') }}</h2>
      <p class="mx-auto mt-1 max-w-md text-sm text-muted-foreground">{{ t('projects.emptyDescription') }}</p>
    </div>

    <ul v-else class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
      <li v-for="project in projects" :key="project.id">
        <Card class="h-full">
          <CardHeader>
            <span class="font-mono text-xs font-semibold text-muted-foreground">{{ project.key }}</span>
            <CardTitle class="text-lg">{{ project.name }}</CardTitle>
            <CardDescription>{{ project.description || t('projects.noDescription') }}</CardDescription>
          </CardHeader>
        </Card>
      </li>
    </ul>
  </div>
</template>
