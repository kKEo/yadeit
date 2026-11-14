<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useWorkItemsStore } from '@/stores/workItems'
import {
  WORK_ITEM_PRIORITIES, WORK_ITEM_STATES,
  type WorkItemPriority, type WorkItemState,
} from '@/api/types'
import AppHeader from '@/components/app/AppHeader.vue'
import Alert from '@/components/ui/Alert.vue'
import Button from '@/components/ui/Button.vue'
import Card from '@/components/ui/Card.vue'
import CardHeader from '@/components/ui/CardHeader.vue'
import CardTitle from '@/components/ui/CardTitle.vue'
import CardDescription from '@/components/ui/CardDescription.vue'
import CardContent from '@/components/ui/CardContent.vue'
import CardFooter from '@/components/ui/CardFooter.vue'
import Input from '@/components/ui/Input.vue'
import Label from '@/components/ui/Label.vue'
import Select from '@/components/ui/Select.vue'
import Textarea from '@/components/ui/Textarea.vue'
import { ArrowLeft } from '@lucide/vue'

const store = useWorkItemsStore()
const router = useRouter()
const { t } = useI18n()

const title = ref('')
const description = ref('')
const state = ref<WorkItemState>('backlog')
const priority = ref<WorkItemPriority>('none')
const submitting = ref(false)

const stateOptions = computed(() => WORK_ITEM_STATES.map((value) => ({ value, label: t(`state.${value}`) })))
const priorityOptions = computed(() =>
  WORK_ITEM_PRIORITIES.map((value) => ({ value, label: t(`priority.${value}`) })),
)
const valid = computed(() => title.value.trim().length > 0)

onMounted(() => store.clearError())

async function submit() {
  if (!valid.value || submitting.value) return
  submitting.value = true
  const item = await store.create({
    title: title.value.trim(),
    description: description.value,
    state: state.value,
    priority: priority.value,
  })
  submitting.value = false
  if (item) await router.push({ name: 'item-detail', params: { key: item.key } })
}
</script>

<template>
  <div class="min-h-screen bg-background">
    <AppHeader>
      <Button variant="ghost" size="sm" @click="router.push({ name: 'items' })">
        <ArrowLeft class="mr-2 h-4 w-4" />{{ t('common.back') }}
      </Button>
      <span class="truncate font-semibold tracking-tight">{{ t('items.create.title') }}</span>
    </AppHeader>

    <main class="mx-auto max-w-2xl px-4 py-6">
      <Card>
        <form novalidate @submit.prevent="submit">
          <CardHeader>
            <CardTitle>{{ t('items.create.title') }}</CardTitle>
            <CardDescription>{{ t('items.create.description') }}</CardDescription>
          </CardHeader>
          <CardContent class="space-y-4">
            <Alert v-if="store.error" variant="destructive" :title="store.error" />
            <div class="space-y-1.5">
              <Label for="title">{{ t('items.fields.title') }} *</Label>
              <Input id="title" v-model="title" :placeholder="t('items.create.titlePlaceholder')" required />
              <p v-if="store.fieldErrors.title" class="text-sm text-destructive">{{ store.fieldErrors.title[0] }}</p>
            </div>
            <div class="space-y-1.5">
              <Label for="description">{{ t('items.fields.description') }}</Label>
              <Textarea
                id="description" v-model="description" :rows="8"
                :placeholder="t('items.create.descriptionPlaceholder')" class="font-mono"
              />
              <p v-if="store.fieldErrors.description" class="text-sm text-destructive">
                {{ store.fieldErrors.description[0] }}
              </p>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
              <div class="space-y-1.5">
                <Label>{{ t('items.fields.state') }}</Label>
                <Select
                  :model-value="state" :options="stateOptions"
                  @update:model-value="state = $event as WorkItemState"
                />
                <p v-if="store.fieldErrors.state" class="text-sm text-destructive">{{ store.fieldErrors.state[0] }}</p>
              </div>
              <div class="space-y-1.5">
                <Label>{{ t('items.fields.priority') }}</Label>
                <Select
                  :model-value="priority" :options="priorityOptions"
                  @update:model-value="priority = $event as WorkItemPriority"
                />
                <p v-if="store.fieldErrors.priority" class="text-sm text-destructive">
                  {{ store.fieldErrors.priority[0] }}
                </p>
              </div>
            </div>
          </CardContent>
          <CardFooter class="justify-end gap-2">
            <Button variant="outline" :disabled="submitting" @click="router.push({ name: 'items' })">
              {{ t('common.cancel') }}
            </Button>
            <Button type="submit" :disabled="!valid || submitting">
              {{ submitting ? t('items.create.submitting') : t('items.create.submit') }}
            </Button>
          </CardFooter>
        </form>
      </Card>
    </main>
  </div>
</template>
