<script setup lang="ts">
import { computed } from 'vue'
import { renderMarkdown } from '@/lib/markdown'

/** Shows Markdown as HTML. `renderMarkdown` sanitises with DOMPurify, which is what makes `v-html` safe here. */
const props = defineProps<{ source: string | null | undefined }>()
const html = computed(() => renderMarkdown(props.source))
</script>

<template>
  <!-- eslint-disable-next-line vue/no-v-html -- sanitised by renderMarkdown -->
  <div class="markdown" v-html="html" />
</template>
