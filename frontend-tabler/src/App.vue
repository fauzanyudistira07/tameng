<script setup lang="ts">
import { onMounted } from 'vue'
import { useTheme } from './composables/useTheme'
import AppSidebar from './components/layout/AppSidebar.vue'
import AppHeader from './components/layout/AppHeader.vue'
import AppFooter from './components/layout/AppFooter.vue'
import ModalReport from './components/modals/ModalReport.vue'
import ModalProfile from './components/modals/ModalProfile.vue'

const { initTheme } = useTheme()

onMounted(() => {
  initTheme()
})
</script>

<template>
  <div class="page">
    <AppSidebar />
    <AppHeader />
    <div class="page-wrapper">
      <router-view />
      <AppFooter />
    </div>
  </div>
  <ModalReport />
  <ModalProfile />
</template>
