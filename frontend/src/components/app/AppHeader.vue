<script setup lang="ts">
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import Button from '@/components/ui/Button.vue'
import LocaleSwitcher from '@/components/app/LocaleSwitcher.vue'
import { LogOut } from '@lucide/vue'

const auth = useAuthStore()
const router = useRouter()
const { t } = useI18n()

async function signOut() {
  await auth.logout()
  await router.push({ name: 'login' })
}
</script>

<template>
  <header class="sticky top-0 z-40 border-b bg-background/95 backdrop-blur">
    <div class="mx-auto flex h-14 max-w-6xl items-center justify-between gap-4 px-4">
      <div class="flex min-w-0 items-center gap-3"><slot /></div>
      <div class="flex shrink-0 items-center gap-2">
        <span v-if="auth.user" class="hidden text-sm text-muted-foreground sm:inline">{{ auth.user.name }}</span>
        <LocaleSwitcher />
        <Button variant="ghost" size="sm" @click="signOut">
          <LogOut class="mr-2 h-4 w-4" />{{ t('common.signOut') }}
        </Button>
      </div>
    </div>
  </header>
</template>
